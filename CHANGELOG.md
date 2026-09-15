# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **One Cloudflare credential set.** Until now the plugin held two Cloudflare tokens in two tabs, each with its own hint about which grant it needed, and on 2026-09-15 the owner extended one while the other silently lost its grant: the monitor gained zone reads and the Analytics tab answered "HTTP 403 Authentication error" the same hour. `inc/cloudflare-credentials.php` resolves token, zone ID and account ID for every Cloudflare caller (purge, monitor, Analytics Engine SQL reads, the Edge view's GraphQL): wp-config constants first (`SN_CLOUDFLARE_API_TOKEN`, `SN_CLOUDFLARE_ZONE_ID`, `SN_CF_ACCOUNT_ID`), then the options under Connections › Cloudflare, which now carries the account ID too and paints the full grant list for the one token (Zone: Cache Purge, Analytics Read; Account: Account Analytics Read; the two firewall candidates), each marked documented, measured or candidate. The analytics token (`SN_CF_ANALYTICS_TOKEN`, then `sn_cf_analytics_token`) keeps working as an override so nothing breaks on upgrade; the Analytics tab says when it is in force and offers "Use the central token" (`analytics_use_central_token`) to drop it, and its field is now "Separate analytics token (optional)" with "Empty: the Connections › Cloudflare token is used". On the first init after upgrade an empty central token takes the analytics one, once, never over a constant. Tests: `tests/cloudflare-credentials.php` (20: resolution order, the override and its source, the grant list, the migration in all four states, the callers on source), leaf suites re-pinned (account id on both Cloudflare leaves, Save gated on all three constants), actions 64, i18n. Mutation red: migration overwriting a set token.

## [14.9.2] - 2026-09-15 — the firewall refusal keeps the API's sentence and probes the account path

### Fixed
- **The firewall reading's hint named grants the docs never state (#1316).** `firewallEventsAdaptiveGroups` refused the zone path under Zone › Analytics › Read, then still with Firewall Services › Read and Logs › Read added (2026-09-15), and Cloudflare's docs name no grant for the dataset. The banner now keeps the API's own sentence, says the grant is undocumented and which three were tried, and on refusal the refresh probes the account viewer path (one GET for the zone's account id, one GraphQL with the zone as a dataset filter) and records which path answered; when the account path answers, the reading is taken from it and marked `path: account`. The one grant left in Cloudflare's "Read analytics and logs" template is Account › Account Analytics › Read, named as a candidate, not a claim. Pins: the refused reading keeps the sentence; a refusal costs one extra GET and, with an account id, one extra query; an answering account path fills the reading.

