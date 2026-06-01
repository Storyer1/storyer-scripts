<?php
/**
 * Link Generator — Zendesk Ticket Closer
 *
 * Run this from the command line to generate a signed close link for testing:
 *   php generate-link.php TICKET_ID USER_ID
 *
 * In production, generate links inside a Zendesk webhook or trigger
 * using the same HMAC formula.
 */

require_once __DIR__ . '/config.php';

$ticket_id = isset($argv[1]) ? (int) $argv[1] : 0;
$user_id   = isset($argv[2]) ? (int) $argv[2] : 0;

if (!$ticket_id || !$user_id) {
    die("Usage: php generate-link.php TICKET_ID USER_ID\n");
}

$timestamp = time();
$payload   = $ticket_id . ':' . $user_id . ':' . $timestamp;
$token     = hash_hmac('sha256', $payload, ZTC_SECRET_KEY);

$base_url = 'https://yoursite.com/close-ticket.php'; // ← change this

$link = $base_url . '?' . http_build_query([
    'pid'   => $ticket_id,
    'fid'   => $user_id,
    'ts'    => $timestamp,
    'token' => $token,
]);

echo "Close link for ticket #$ticket_id:\n";
echo $link . "\n";
echo "\nExpires: " . (ZTC_LINK_TTL > 0 ? date('Y-m-d H:i:s', $timestamp + ZTC_LINK_TTL) : 'never') . "\n";
