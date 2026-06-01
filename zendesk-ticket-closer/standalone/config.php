<?php
// ─────────────────────────────────────────────
//  Zendesk Ticket Closer — Standalone Config
// ─────────────────────────────────────────────

// Zendesk credentials
define('ZTC_SUBDOMAIN',    'your-subdomain');      // yourcompany.zendesk.com → 'yourcompany'
define('ZTC_BEARER_TOKEN', 'your_bearer_token');   // Zendesk OAuth bearer token

// Security: secret key used to sign and verify close links
// Generate one: php -r "echo bin2hex(random_bytes(32));"
define('ZTC_SECRET_KEY',   'replace_with_random_64_char_hex_string');

// How long a close link stays valid (seconds). 0 = never expires.
define('ZTC_LINK_TTL', 604800); // 7 days

// Log file
define('ZTC_LOG_FILE', __DIR__ . '/logs/ztc_log.txt');

// Optional: redirect URL after successful close (leave empty to show plain message)
define('ZTC_REDIRECT_URL', '');
