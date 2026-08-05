<img src="src/icon.svg" width="100">

# Fort for Craft CMS

Application-level security for Craft CMS 5 — HTTP rate limiting, login failure monitoring, automatic IP blocking, email digests, webhooks, and a full Control Panel dashboard.

## Requirements

- Craft CMS **5.0.0** or later
- PHP **8.2** or later

## Installation

```bash
composer require allomambo/craft-fort
php craft plugin/install fort
```

Then navigate to **Settings > Fort** (or **Fort > Settings** in the CP sidebar) to configure the plugin.

## Features

### HTTP Rate Limiting

Per-IP request throttling using calendar-minute buckets. When an IP exceeds the configured limit, Fort returns a `429 Too Many Requests` response with a `Retry-After` header.

- Configurable max requests per IP per minute (default: 400)
- Option to exclude Control Panel requests and CP resource requests
- Sliding-window alert counting: after N alerts within a configurable window, the IP is automatically blocked

### Login Failure Monitoring

Tracks failed login attempts per IP address with a sliding time window. When the threshold is reached, the IP is immediately blocked.

- Configurable failure threshold per IP (default: 10)
- Configurable time window (default: 15 minutes)

### IP Blocking

Automatic and manual IP blocking with escalating permanent block thresholds.

- Temporary blocks with configurable default duration (default: 60 minutes)
- Permanent block escalation: after a configurable number of automatic blocks (default: 10), the block becomes permanent until manually cleared; the threshold then increases linearly (10, 20, 30, ...)
- Manual blocking from the dashboard with optional notes and permanent flag
- Automatic sweep of expired temporary blocks (cache-throttled, once per day)
- Blocked IPs receive a generic `403 Access Denied` response with a reference ID (no mechanism disclosure) that admins can trace in the logs

### Excluded IPs / CIDRs

Whitelist IPs or CIDR ranges that should never be blocked. Excluded IPs still generate audit events for full visibility, but Fort will never return `403`/`429` responses, trigger alerts, or apply blocks for them.

A quick "Add my IP" button in the settings makes it easy to whitelist your own address.

### Notifications

#### Significant Event Emails

Immediate email alerts to selected maintainer users when a threshold is crossed or an IP is blocked. Throttled to a maximum of 20 emails per hour to prevent notification floods during distributed attacks.

#### Digest Emails

- **Daily digest** — summary of events, sent at a configurable hour
- **Weekly digest** — summary of events, sent on a configurable day of the week

Digests can be sent via server cron (recommended) or through a built-in pseudo-cron that triggers after ordinary web requests.

#### Webhooks

HTTPS-only webhook for significant events. Fort validates the URL against SSRF vectors (private IPs, credentials in URL, non-standard ports).

Payload format:

```json
{
  "event": "http_rate_limited",
  "time": "2026-04-16 17:46:30",
  "payload": { "ip": "203.0.113.10", "count": 450, "limit": 400 }
}
```

### Control Panel Dashboard

A dedicated CP section with:

- **Sparklines** — 30-day visual trends for login failures, rate-limit hits, and IP blocks
- **Recent events** — filterable table of security events
- **Alerts** — history of all triggered alerts
- **Blocked IPs** — active and past blocks with inline unblock, notes, copy IP, and external IP trace (AbuseIPDB)
- **Runtime overrides** — adjust thresholds on the fly without redeploying; overrides take precedence over settings and config file values

### Settings Tabs

Fort settings are organized into six tabs:

| Tab | What it covers |
|---|---|
| Authentication & logging | Login attempt tracking, failure thresholds |
| Rate limiting | HTTP request limits, CP exclusions |
| IP blocking | Block duration, permanent escalation, excluded IPs |
| Notifications | Email recipients, digests schedule, webhook |
| Data retention | Event and alert retention period, bulk purge, personal-data anonymization |
| Config file | View active `config/fort.php` overrides |

## Configuration

### Plugin Settings (CP)

All settings can be managed from the Control Panel under **Settings > Fort**.

When `allowAdminChanges` is `false` (staging/production), the settings page displays a read-only banner and prevents edits.

### Config File Overrides

Create a `config/fort.php` file for environment-specific overrides:

```php
<?php

return [
    '*' => [
        'httpRateLimitEnabled' => true,
        'maxRequestsPerIpPerMinute' => 200,
        'eventRetentionDays' => 90,
    ],
    'production' => [
        'maxRequestsPerIpPerMinute' => 300,
    ],
];
```

All settings from the Settings model can be overridden. Fort detects whether overrides apply to all environments (`*`) or a specific one, and displays this in the dashboard.

### Personal Data Anonymization

Fort records the client IP and, on failed logins, the attempted username or email. Set `anonymizePii` to `true` (Settings > Fort > Data retention, or `config/fort.php`) to hash the attempted login and mask the client IP (IPv4 last octet, IPv6 to /48) before anything is stored in an event or alert, emailed, or sent to a webhook.

The setting is off by default and applies to new data only — rows written before enabling it keep their original values.

### Runtime Overrides

From the dashboard, admins can set temporary runtime overrides for key tunables (thresholds, windows, block duration). These take precedence over both plugin settings and config file values, and can be cleared at any time.

### Dev IP Spoofing

For local development, Fort supports an IP spoofing feature so you can test blocking behavior without locking yourself out of the CP:

```php
// config/fort.php — local environment only
'local' => [
    'devIpSpoof' => [
        'enabled' => true,
        'from' => '192.168.97.1',   // your real LAN IP
        'to' => '192.168.100.1',     // the IP Fort will use for enforcement
        'excludeCp' => true,         // keep real IP for CP access
    ],
],
```

This feature is gated behind `devMode` and is completely ignored when `devMode` is `false`.

## Console Commands

Schedule these commands with cron for reliable digest delivery and database hygiene:

```bash
# Prune events and alerts older than the configured retention period
php craft fort/events/prune

# Send the daily digest (if enabled and maintainers configured)
php craft fort/events/send-daily-digest

# Send the weekly digest (if enabled and maintainers configured)
php craft fort/events/send-weekly-digest
```

Example crontab:

```cron
# Fort daily digest at 8:00 AM
0 8 * * * cd /path/to/craft && php craft fort/events/send-daily-digest

# Fort weekly digest on Mondays at 8:00 AM
0 8 * * 1 cd /path/to/craft && php craft fort/events/send-weekly-digest

# Prune old events nightly at 3:00 AM
0 3 * * * cd /path/to/craft && php craft fort/events/prune
```

If you use cron for digests, disable the "Send digests on web activity" setting to avoid duplicate sends.

## Permissions

Fort uses the built-in `accessPlugin-fort` permission. Grant it to users or groups that should see the Fort dashboard and manage settings.

Destructive actions (clearing all events, clearing runtime overrides) require admin access.

## Scope and Limitations

Fort protects your site by **source IP address**. It does not implement account-level lockout (e.g., "lock user X after N failures from any IP"). A distributed credential-stuffing attack using one attempt per IP will not trip Fort's per-IP thresholds.

For account-level protection, combine Fort with:

- Craft's built-in `maxInvalidLogins`, `invalidLoginWindowDuration`, and `cooldownDuration` settings in `config/general.php`
- Craft's native elevated-session and 2FA support for admin accounts
- A WAF or edge service (Cloudflare, AWS WAF, fail2ban) for volumetric and behavioral protections

Fort is complementary to — not a replacement for — network-level and account-level controls.

## Defense in Depth

- **Edge / WAF** (e.g., Cloudflare) — absorbs volumetric abuse before PHP runs. You may disable Fort's HTTP rate limiting when edge rules cover that layer; Fort still adds value for login monitoring, automatic IP blocks, CP alerts, and webhooks.
- **Reverse proxy** — configure Craft's trusted hosts and proxy headers (`config/general.php`) so the client IP is correct. Wrong IP configuration makes blocks and logs target the wrong address.
- **Origin server** — nginx/Apache rate limits and tools like fail2ban complement application-level rules.

## Support

Please [open a GitHub issue](https://github.com/allomambo/craft-fort/issues) for bug reports, questions, or feature requests.
