# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Added:** the edge posture (`inc/cloudflare-posture.php`): three documented Free-plan REST reads, daily on the Cloudflare monitor's hook and on Refresh now, of what the edge is SET TO, which the headers probe cannot see: zone settings (SSL mode, minimum TLS, Always Use HTTPS, Development Mode, plus readings), DNSSEC status, and the custom WAF rules by name, action and state. Security › Firewall paints it as "Edge posture" under Acted on (judged rows with a dot, the readings as one quiet line, the rules by name; a refused read is a notice naming the scope), and the Top rules list carries rule names instead of ids. New Health check 27, `cf_edge_posture` (edge family, Health surface): Flexible or Full without strict, TLS below 1.2, Always Use HTTPS off, Development Mode on and DNSSEC not active are findings; a refused read is a finding naming the scope, never a pass; no record, a stale one or a transport failure is skipped with the reason. The abilities WAF witness reads the ruleset first: a rule that exists, is enabled and blocks is the fact on a quiet day; disabled or absent is open whatever the log or the monitor say; a refused or stale read falls through to the log, then Better Stack, as before. `signal-noise/cloudflare-status` carries `posture`. The token needs three more read scopes, named as the API reference names them: Zone Settings Read, Zone WAF Read, DNS Read (Connections › Credentials lists them; `documented` until the first live read).

## [15.3.4] - 2026-09-16 — the cron events leave with the plugin


- **Fixed:** the plugin's cron events now leave with the plugin. Deactivation never unscheduled the 38 hooks the modules arm (24 recurring, 14 single), so a deactivated plugin left WP-Cron firing them into callbacks that no longer existed, the way the Plugin Handbook's cron page says not to. New `inc/cron-lifecycle.php` lists every hook and `register_deactivation_hook( __FILE__, 'sn_cron_deactivate' )` runs `wp_unschedule_hook()` on each; every module re-arms on `init`, so re-activation needs nothing. `tests/cron-lifecycle.php` derives the set of scheduled hooks from source and pins the list both ways; its first run caught two hooks the hand-written list had missed.


