# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.0.1] - 2026-09-15 — the firewall reading follows the plan

### Fixed
- **The firewall reading follows the plan, not a grant.** Three fixes (14.9.1, 14.9.2, 15.0.0) chased a token permission for `firewallEventsAdaptiveGroups`; on 2026-09-15 the owner gave a token every zone read grant Cloudflare offers, 44 of them, and the zone path still answered "zone … does not have access to the path". The subject of that sentence is the zone: the grouped dataset is not on Free zones, and Cloudflare documents the raw `firewallEventsAdaptive` as open to every plan. The monitor now asks the grouped dataset, and on that refusal reads one page of the raw one (10,000 rows, last 24 hours) and groups it here to the same shape, each row weighing its `sampleInterval` because the raw dataset is adaptively sampled and a row stands for that many events under load (`dataset: raw`, `truncated` when the page was full, `groups_refused` keeping the API's sentence). The account-path probe and the account-id GET are gone; the firewall hint names the plan and no grant; the grant list is three rows (Cache Purge, Analytics Read, Account Analytics Read), the two "candidate" firewall grants measured useless. `sn_cf_monitor_firewall_from()` reads both shapes. Pinned: raw rows grouped and weighed by sampleInterval, a full page marked truncated, the fallback made only on refusal, no zone-record GET; mutation red on the raw count and on the fallback.

