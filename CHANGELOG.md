# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **The firewall reading follows the plan, not a grant.** Three fixes (14.9.1, 14.9.2, 15.0.0) chased a token permission for `firewallEventsAdaptiveGroups`; on 2026-09-15 the owner gave a token every zone read grant Cloudflare offers, 44 of them, and the zone path still answered "zone … does not have access to the path". The subject of that sentence is the zone: the grouped dataset is not on Free zones, and Cloudflare documents the raw `firewallEventsAdaptive` as open to every plan. The monitor now asks the grouped dataset, and on that refusal reads one page of the raw one (10,000 rows, last 24 hours) and groups it here to the same shape, each row weighing its `sampleInterval` because the raw dataset is adaptively sampled and a row stands for that many events under load (`dataset: raw`, `truncated` when the page was full, `groups_refused` keeping the API's sentence). The account-path probe and the account-id GET are gone; the firewall hint names the plan and no grant; the grant list is three rows (Cache Purge, Analytics Read, Account Analytics Read), the two "candidate" firewall grants measured useless. `sn_cf_monitor_firewall_from()` reads both shapes. Pinned: raw rows grouped and weighed by sampleInterval, a full page marked truncated, the fallback made only on refusal, no zone-record GET; mutation red on the raw count and on the fallback.

## [15.0.0] - 2026-09-15 — one Cloudflare credential set

### Added
- **One Cloudflare credential set.** Until now the plugin held two Cloudflare tokens in two tabs, each with its own hint about which grant it needed, and on 2026-09-15 the owner extended one while the other silently lost its grant: the monitor gained zone reads and the Analytics tab answered "HTTP 403 Authentication error" the same hour. `inc/cloudflare-credentials.php` resolves token, zone ID and account ID for every Cloudflare caller (purge, monitor, Analytics Engine SQL reads, the Edge view's GraphQL): wp-config constants first (`SN_CLOUDFLARE_API_TOKEN`, `SN_CLOUDFLARE_ZONE_ID`, `SN_CF_ACCOUNT_ID`), then the options under Connections › Cloudflare, which now carries the account ID too and paints the full grant list for the one token (Zone: Cache Purge, Analytics Read; Account: Account Analytics Read; the two firewall candidates), each marked documented, measured or candidate. The analytics token (`SN_CF_ANALYTICS_TOKEN`, then `sn_cf_analytics_token`) keeps working as an override so nothing breaks on upgrade; the Analytics tab says when it is in force and offers "Use the central token" (`analytics_use_central_token`) to drop it, and its field is now "Separate analytics token (optional)" with "Empty: the Connections › Cloudflare token is used". On the first init after upgrade an empty central token takes the analytics one, once, never over a constant. Tests: `tests/cloudflare-credentials.php` (20: resolution order, the override and its source, the grant list, the migration in all four states, the callers on source), leaf suites re-pinned (account id on both Cloudflare leaves, Save gated on all three constants), actions 64, i18n. Mutation red: migration overwriting a set token.
- **The monitor verifies either token kind.** A User token answers `GET /user/tokens/verify`; an Account token has no `/user` and answers `GET /accounts/{account}/tokens/verify`. The site ran one of each in two tabs, and the monitor only asked the user route, so an Account token in Connections would have read as invalid. `sn_cf_monitor_verify()` asks the user route, then the account route when the account id is known, and records `kind: user | account`; the Monitor shows it beside the status. Pinned both ways, plus no-account-id and both-refused.

