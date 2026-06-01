---
title: Zendesk Attachment Remover
description: Automatically redacts attachments from closed Zendesk tickets to save storage and stay GDPR-compliant. Available as a WordPress plugin or standalone PHP script.
---

import { Tabs, TabItem } from '@astrojs/starlight/components';

Automatically redacts all attachments from Zendesk tickets that were closed between **30 and 40 days ago**. Runs on a daily schedule — set it up once, forget about it.

:::note[Download]
👉 [GitHub — storyer-scripts/zendesk-attachment-remover](https://github.com/Storyer1/storyer-scripts/tree/main/zendesk-attachment-remover)
:::

---

## What it does

- Searches Zendesk for closed tickets with attachments in the target date window
- Loops through every comment in each ticket
- Calls the Zendesk Redact API on every attachment found
- Logs all actions to a text file
- Zendesk automatically tags processed tickets with `redacted_content`

:::caution[Irreversible]
Attachment redaction in Zendesk **cannot be undone**. Always run with `--dry` first to preview what will be affected.
:::

---

## How it works

```
Daily schedule (cron or WP-Cron)
    → Fetch closed tickets from Zendesk (30–40 days ago, in 2-day chunks)
    → For each ticket → fetch all comments
    → For each comment with attachments → call Zendesk Redact API
    → Log results
```

Searches in **2-day chunks** to avoid Zendesk API response limits — automatically retries at 1-day intervals if a `422` error is returned.

---

## Requirements

- PHP 7.4+ with cURL enabled
- Zendesk account with **API token access enabled**
- Zendesk user with **Admin role** (required for attachment redaction)

---

## Setup

<Tabs>
<TabItem label="Standalone (recommended)">

Best for running on any server or locally — no WordPress needed.

### 1. Download the files

```bash
git clone https://github.com/Storyer1/storyer-scripts.git
cd storyer-scripts/zendesk-attachment-remover
```

Or just download `run.php` and `config.php` directly from GitHub.

### 2. Get your Zendesk API token

1. Go to **Zendesk Admin → Apps & Integrations → APIs → Zendesk API**
2. Enable **Token Access**
3. Click **Add API Token** and copy it

### 3. Edit config.php

```php
define('ZAR_SUBDOMAIN',  'yourcompany');          // from yourcompany.zendesk.com
define('ZAR_EMAIL',      'you@yourcompany.com');
define('ZAR_API_KEY',    'your_api_token_here');

define('ZAR_DAYS_START', 40);  // older boundary
define('ZAR_DAYS_END',   30);  // newer boundary
```

### 4. Test before running

```bash
# Check credentials + search queries (no changes made)
php run.php --test

# Preview what would be redacted (no changes made)
php run.php --dry

# Real run
php run.php
```

### 5. Schedule with cron

```bash
crontab -e
```

```bash
# Run daily at 2am
0 2 * * * cd /path/to/zendesk-attachment-remover && php run.php >> /dev/null 2>&1
```

**On Google Cloud (Cloud Scheduler):**

1. Go to **Cloud Scheduler → Create Job**
2. Frequency: `0 2 * * *`
3. Target: HTTP → point to your Cloud Run instance or VM URL

</TabItem>
<TabItem label="WordPress Plugin">

Best if you already have a WordPress site and want a UI for manual runs.

### 1. Download and install

Copy the plugin folder to WordPress:

```bash
wp-content/plugins/zendesk-attach-remover/
    └── zendesk-attachment-remover.php
```

Activate it in **WordPress Admin → Plugins**.

### 2. Get your Zendesk API token

1. Go to **Zendesk Admin → Apps & Integrations → APIs → Zendesk API**
2. Enable **Token Access**
3. Click **Add API Token** and copy it

### 3. Add the API key to wp-config.php

```php
define('ZENDESK_API_KEY', 'your_api_token_here');
```

:::caution
Never put the API key in the plugin Settings UI — it would be stored as plain text in the database. Use `wp-config.php` instead.
:::

### 4. Configure subdomain and email

Go to **WordPress Admin → Settings → Zendesk Attachment Remover**:

- **Zendesk Subdomain** — e.g. `yourcompany`
- **Zendesk Email** — the admin account email

### 5. Run it

The plugin runs automatically every day via WP-Cron.

For a manual run: **WordPress Admin → Tools → Zendesk Attachments → Run Now**

</TabItem>
</Tabs>

---

## Logs

<Tabs>
<TabItem label="Standalone">

Log file is written to `logs/zar_log.txt` relative to the script:

```
[2026-06-01 02:00:00] ═══════════════════════════════════════
[2026-06-01 02:00:00]   Zendesk Attachment Remover — RUN mode
[2026-06-01 02:00:00] ═══════════════════════════════════════
[2026-06-01 02:00:01] Credentials OK — logged in as: John Smith
[2026-06-01 02:00:02] Fetching tickets closed between 30 and 40 days ago...
[2026-06-01 02:00:03] 2026-04-22 → 2026-04-24: 8 tickets
[2026-06-01 02:00:05]   Batch 1: 8 tickets
[2026-06-01 02:00:06] [1/8] Ticket #48291 (updated: 2026-04-23T14:22:01Z)
[2026-06-01 02:00:07]   Redacted 2 attachment(s) from comment #9912841 ✓
...
[2026-06-01 02:00:45] Redacted: 27 attachment(s) across 8 ticket(s).
```

</TabItem>
<TabItem label="WordPress Plugin">

Log file is written to:
```
wp-content/plugins/zendesk-attach-remover/zendesk-attachment-remover/zar_log.txt
```

You can also view it from the admin panel: **Tools → Zendesk Attachments → View Log**

</TabItem>
</Tabs>

---

## Customizing the date window

Edit the config to change which tickets are targeted:

<Tabs>
<TabItem label="Standalone">

In `config.php`:

```php
define('ZAR_DAYS_START', 90);  // older boundary
define('ZAR_DAYS_END',   60);  // newer boundary
```

</TabItem>
<TabItem label="WordPress Plugin">

In the plugin file, find `zar_remove_attachments()`:

```php
$days_ago_start = 90; // older boundary
$days_ago_end   = 60; // newer boundary
```

</TabItem>
</Tabs>

---

## Known limitations

- Attachment redaction is **irreversible** — always `--dry` run first (standalone)
- Requires WP-Cron to be active (WordPress version) — or set up a real cron as fallback
- Zendesk API rate limit: 200ms delay between requests is built in
- If a 2-day chunk exceeds 1000 results, the script automatically splits into 1-day intervals
