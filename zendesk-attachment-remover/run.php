<?php
/**
 * Zendesk Attachment Remover — Standalone
 * 
 * Redacts attachments from Zendesk tickets closed between
 * ZAR_DAYS_START and ZAR_DAYS_END days ago.
 *
 * Usage:
 *   php run.php          → full run
 *   php run.php --test   → verify credentials + search queries only (no redaction)
 *   php run.php --dry    → shows what would be redacted, does nothing
 */

require_once __DIR__ . '/config.php';

// ─── Bootstrap ───────────────────────────────

$log_dir = dirname(ZAR_LOG_FILE);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

$mode = 'run';
if (in_array('--test', $argv ?? [])) $mode = 'test';
if (in_array('--dry',  $argv ?? [])) $mode = 'dry';

// ─── Logging ─────────────────────────────────

function zar_log(string $message): void {
    $timestamp  = date('Y-m-d H:i:s');
    $log_line   = "[$timestamp] $message" . PHP_EOL;

    echo $log_line;
    file_put_contents(ZAR_LOG_FILE, $log_line, FILE_APPEND);
}

// ─── API ─────────────────────────────────────

function zar_api(string $endpoint, string $method = 'GET', ?array $data = null): array {
    $url = 'https://' . ZAR_SUBDOMAIN . '.zendesk.com/api/v2/' . $endpoint;
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => ZAR_EMAIL . '/token:' . ZAR_API_KEY,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        zar_log('cURL error: ' . curl_error($ch) . ' (' . curl_errno($ch) . ')');
    }

    curl_close($ch);
    usleep(200000); // 200ms — stay within Zendesk rate limits

    return [
        'code' => $http_code,
        'body' => json_decode($response ?: '{}', true),
    ];
}

// ─── Environment check ───────────────────────

function zar_check_environment(): bool {
    zar_log('PHP version: '  . PHP_VERSION);
    zar_log('cURL version: ' . curl_version()['version']);
    zar_log('SSL version: '  . curl_version()['ssl_version']);

    if (!function_exists('curl_init')) {
        zar_log('ERROR: cURL is not available.');
        return false;
    }

    return true;
}

// ─── Credentials check ───────────────────────

function zar_verify_credentials(): bool {
    $r = zar_api('users/me.json');
    if ($r['code'] === 200) {
        $name = $r['body']['user']['name'] ?? 'unknown';
        zar_log("Credentials OK — logged in as: $name");
        return true;
    }
    zar_log('Credential check failed. HTTP ' . $r['code']);
    return false;
}

// ─── Test search queries ──────────────────────

function zar_test_search(): void {
    $tests = [
        'Tickets with attachments'           => 'has_attachment:true',
        'Closed tickets with attachments'    => 'has_attachment:true status:closed',
        'Target date range'                  => 'has_attachment:true status:closed updated>'
                                                . date('Y-m-d', strtotime('-' . ZAR_DAYS_START . ' days'))
                                                . ' updated<'
                                                . date('Y-m-d', strtotime('-' . ZAR_DAYS_END . ' days')),
    ];

    foreach ($tests as $label => $query) {
        $r     = zar_api('search.json?query=' . urlencode($query) . '&per_page=1');
        $count  = $r['body']['count'] ?? 'n/a';
        $status = $r['code'] === 200 ? 'OK' : 'FAIL (HTTP ' . $r['code'] . ')';
        zar_log("[$status] $label → $count results");
    }
}

// ─── Fetch tickets ───────────────────────────

function zar_get_tickets(int $days_start, int $days_end): array {
    $all_tickets = [];
    $chunk_size  = 2;
    $cursor      = $days_start;

    zar_log('Fetching tickets closed between ' . $days_end . ' and ' . $days_start . ' days ago...');

    while ($cursor > $days_end) {
        $chunk_end = max($cursor - $chunk_size, $days_end);
        $date_from = date('Y-m-d', strtotime("-{$cursor} days"));
        $date_to   = date('Y-m-d', strtotime("-{$chunk_end} days"));

        $query = "has_attachment:true status:closed updated>{$date_from} updated<{$date_to}";
        $url   = 'search.json?query=' . urlencode($query);

        $probe = zar_api($url . '&per_page=1');
        $total = $probe['body']['count'] ?? 0;

        if ($probe['code'] !== 200) {
            zar_log("Probe failed for {$date_from}→{$date_to}: HTTP " . $probe['code']);
            $cursor = $chunk_end;
            continue;
        }

        zar_log("{$date_from} → {$date_to}: {$total} tickets");

        if ($total > 0) {
            $page     = 0;
            $next_url = $url;

            do {
                $r = zar_api($next_url);
                $page++;

                if ($r['code'] === 200 && isset($r['body']['results'])) {
                    $batch       = $r['body']['results'];
                    $all_tickets = array_merge($all_tickets, $batch);
                    zar_log("  Batch {$page}: " . count($batch) . ' tickets');

                    $next_page = $r['body']['next_page'] ?? null;
                    $next_url  = $next_page
                        ? str_replace('https://' . ZAR_SUBDOMAIN . '.zendesk.com/api/v2/', '', $next_page)
                        : null;

                } elseif ($r['code'] === 422 && $chunk_size > 1) {
                    zar_log('  Response limit hit — retrying with 1-day chunks');
                    $chunk_size = 1;
                    $chunk_end  = $cursor - 1;
                    $date_to    = date('Y-m-d', strtotime("-{$chunk_end} days"));
                    $query      = "has_attachment:true status:closed updated>{$date_from} updated<{$date_to}";
                    $next_url   = 'search.json?query=' . urlencode($query);
                } else {
                    zar_log('  Fetch failed: HTTP ' . $r['code']);
                    $next_url = null;
                }
            } while ($next_url);
        }

        $cursor = $chunk_end;
    }

    $filtered = array_filter($all_tickets, function($ticket) use ($days_start, $days_end) {
        if (!isset($ticket['updated_at'])) return false;
        $days = (new DateTime())->diff(new DateTime($ticket['updated_at']))->days;
        return $days >= $days_end && $days <= $days_start;
    });

    zar_log('Total fetched: ' . count($all_tickets) . ' → after date filter: ' . count($filtered));

    return $filtered;
}

// ─── Redact attachments ──────────────────────

function zar_redact(int $ticket_id, int $comment_id, array $attachments, bool $dry): bool {
    $urls  = array_map(fn($a) => $a['content_url'], $attachments);
    $count = count($urls);

    if ($dry) {
        zar_log("  [DRY] Would redact {$count} attachment(s) from comment #{$comment_id}");
        return true;
    }

    $r = zar_api("comment_redactions/{$comment_id}", 'PUT', [
        'ticket_id'                => $ticket_id,
        'external_attachment_urls' => $urls,
    ]);

    if ($r['code'] === 200) {
        zar_log("  Redacted {$count} attachment(s) from comment #{$comment_id} ✓");
        return true;
    }

    zar_log("  Failed to redact comment #{$comment_id}: HTTP " . $r['code']);
    return false;
}

// ─── Main ────────────────────────────────────

function zar_run(string $mode): void {
    zar_log('');
    zar_log('═══════════════════════════════════════');
    zar_log('  Zendesk Attachment Remover — ' . strtoupper($mode) . ' mode');
    zar_log('═══════════════════════════════════════');

    if (!zar_check_environment())  return;
    if (!zar_verify_credentials()) return;

    if ($mode === 'test') {
        zar_test_search();
        zar_log('Test complete.');
        return;
    }

    $tickets = zar_get_tickets(ZAR_DAYS_START, ZAR_DAYS_END);
    $total   = count($tickets);

    if ($total === 0) {
        zar_log('No tickets found in the target date range. Done.');
        return;
    }

    zar_log("Processing {$total} ticket(s)...");

    $redacted_total = 0;
    $i = 0;

    foreach ($tickets as $ticket) {
        $i++;
        $id         = $ticket['id'];
        $updated_at = $ticket['updated_at'] ?? 'unknown';
        zar_log("[{$i}/{$total}] Ticket #{$id} (updated: {$updated_at})");

        $r = zar_api("tickets/{$id}/comments.json");

        if ($r['code'] !== 200 || !isset($r['body']['comments'])) {
            zar_log("  Could not fetch comments: HTTP " . $r['code']);
            continue;
        }

        foreach ($r['body']['comments'] as $comment) {
            $attachments = $comment['attachments'] ?? [];
            if (empty($attachments)) continue;

            if (zar_redact($id, $comment['id'], $attachments, $mode === 'dry')) {
                $redacted_total += count($attachments);
            }
        }
    }

    $label = $mode === 'dry' ? 'Would redact' : 'Redacted';
    zar_log('');
    zar_log("{$label}: {$redacted_total} attachment(s) across {$total} ticket(s).");
    zar_log('Done.');
}

zar_run($mode);
