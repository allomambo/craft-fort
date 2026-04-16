# Release Notes for Fort

## 1.0.0 - 2026-04-16

### Added
- HTTP rate limiting with configurable per-IP thresholds (calendar-minute buckets).
- Login failure monitoring with sliding-window threshold detection.
- Automatic and manual IP blocking with permanent escalation (linear threshold increment).
- Runtime setting overrides for all tunable values without redeploy.
- Daily and weekly email digest notifications with pseudo-cron and console command support.
- Significant event email notifications with global hourly throttle.
- Webhook notifications for significant events (HTTPS-only, SSRF-protected).
- Excluded IPs/CIDRs whitelist — whitelisted IPs still generate audit events but are never blocked.
- Control panel dashboard with sparklines, recent events, alerts, and blocked IP management.
- Reusable IP badge component with copy-to-clipboard and external trace lookup.
- Console commands for digest sending (`fort/digest/send`) and event pruning (`fort/events/prune`).
- Config file overrides via `config/fort.php` with per-environment support.
- `allowAdminChanges` integration — settings page shows read-only banner when locked.
- Admin-only guard on destructive actions (clear events, clear runtime overrides).
