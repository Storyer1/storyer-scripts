<?php
/**
 * Zendesk Ticket Closer — Standalone
 *
 * Closes a Zendesk ticket when a customer clicks a signed link.
 *
 * URL format:
 *   https://yoursite.com/close-ticket.php?pid=TICKET_ID&fid=USER_ID&ts=TIMESTAMP&token=HMAC
 *
 * Generate links with generate-link.php or build them in a Zendesk trigger/webhook.
 */

require_once __DIR__ . '/config.php';

// ─── Bootstrap ───────────────────────────────

$log_dir = dirname(ZTC_LOG_FILE);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// ─── Logging ─────────────────────────────────

function ztc_log(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    echo $line;
    file_put_contents(ZTC_LOG_FILE, $line, FILE_APPEND);
}

// ─── HMAC Token ──────────────────────────────

/**
 * Generate a signed token for a ticket close link.
 * Includes ticket_id + user_id + timestamp so each link is unique
 * and can't be reused for a different ticket.
 */
function ztc_generate_token(int $ticket_id, int $user_id, int $timestamp): string {
    $payload = $ticket_id . ':' . $user_id . ':' . $timestamp;
    return hash_hmac('sha256', $payload, ZTC_SECRET_KEY);
}

/**
 * Verify the token from the URL.
 * Uses hash_equals() to prevent timing attacks.
 */
function ztc_verify_token(int $ticket_id, int $user_id, int $timestamp, string $token): bool {
    // Check expiry
    if (ZTC_LINK_TTL > 0 && (time() - $timestamp) > ZTC_LINK_TTL) {
        ztc_log("Token expired for ticket #$ticket_id (issued " . date('Y-m-d H:i:s', $timestamp) . ")");
        return false;
    }

    $expected = ztc_generate_token($ticket_id, $user_id, $timestamp);
    return hash_equals($expected, $token);
}

// ─── Zendesk API ─────────────────────────────

function ztc_close_ticket(int $ticket_id): bool {
    $url = 'https://' . ZTC_SUBDOMAIN . '.zendesk.com/api/v2/tickets/' . $ticket_id . '.json';

    $payload = json_encode([
        'ticket' => [
            'status'  => 'closed',
            'comment' => [
                'body'   => 'Ticket closed via customer request.',
                'public' => false,
            ],
        ],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ZTC_BEARER_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        ztc_log('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    if ($http_code === 200) {
        ztc_log("Ticket #$ticket_id closed successfully.");
        return true;
    }

    ztc_log("Failed to close ticket #$ticket_id. HTTP $http_code: $response");
    return false;
}

// ─── Handle request ──────────────────────────

function ztc_handle(): void {
    // Validate required params
    $ticket_id = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
    $user_id   = isset($_GET['fid']) ? (int) $_GET['fid'] : 0;
    $timestamp = isset($_GET['ts'])  ? (int) $_GET['ts']  : 0;
    $token     = $_GET['token'] ?? '';

    if (!$ticket_id || !$user_id || !$timestamp || !$token) {
        http_response_code(400);
        ztc_log('Invalid request — missing parameters.');
        die('Invalid request.');
    }

    ztc_log("Close request received for ticket #$ticket_id (user #$user_id)");

    // Verify HMAC token
    if (!ztc_verify_token($ticket_id, $user_id, $timestamp, $token)) {
        http_response_code(403);
        ztc_log("Invalid or expired token for ticket #$ticket_id");
        die('This link is invalid or has expired.');
    }

    // Close the ticket
    if (ztc_close_ticket($ticket_id)) {
        if (ZTC_REDIRECT_URL) {
            header('Location: ' . ZTC_REDIRECT_URL);
            exit;
        }
        die('Your ticket has been closed. Thank you!');
    }

    http_response_code(500);
    die('Something went wrong. Please contact support.');
}

ztc_handle();
