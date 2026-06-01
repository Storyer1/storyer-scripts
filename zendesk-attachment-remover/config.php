<?php
// ─────────────────────────────────────────────
//  Zendesk Attachment Remover — Configuration
// ─────────────────────────────────────────────

// Zendesk credentials
define('ZAR_SUBDOMAIN',  'your-subdomain');       // yourcompany.zendesk.com → 'yourcompany'
define('ZAR_EMAIL',      'you@yourcompany.com');  // Zendesk admin email
define('ZAR_API_KEY',    'your_api_token_here');  // Zendesk API token

// Date window: target tickets closed X–Y days ago
define('ZAR_DAYS_START', 40);  // older boundary
define('ZAR_DAYS_END',   30);  // newer boundary

// Log file path (relative to this script)
define('ZAR_LOG_FILE', __DIR__ . '/logs/zar_log.txt');
