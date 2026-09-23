# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- #1691's `@since` and code markers read 18.0.0, the number the cut gives it (Y rolls X at 10), not 17.10.0.

### Added
- **The 5xx rollup reaches the phone.** New `signal-noise/edge-errors-summary` (read door 48 → 49, sn-status section `edge_errors`) carries the last seven days of 5xx on its own: total, failing paths, who answered, and the query's last outcome. It reuses the same reader as `cloudflare-status`'s `errors_5xx`, so the two can't disagree. It's split off so it can get a remote twin, `remote-edge-errors-summary`, while `cloudflare-status` stays local because it describes the perimeter. Remote contract '5' → '6' (15 twins). The worker's `sn_remote_edge_errors` row ships with sn-remote-mcp-worker's matching contract bump, and until that deploy lands the deploy probe reads `contract_match: false`, as designed.

## [17.9.3] - 2026-09-23 — Early Hints lookups are not errors

### Fixed
- **Cloudflare's own Early Hints lookups no longer count as errors.** `edge-sampling-probe` showed that 7,593 of the 7,743 sampled 5xx in a day had `requestSource: earlyHintsCache`. That's Cloudflare checking whether it holds a cached `103 Early Hints` for a URL, and a miss is logged as a 504 that never reaches the origin and that no visitor sees. It's why every stored 5xx read `edge=504 origin=-`, why the top paths were just the most-requested URLs, and why the exact daily totals never counted them. The 5xx query now excludes them. It also keeps the request source, so a row says who asked ("asked by a visitor", "asked by a Worker"), and records its own outcome as `errors_5xx.query`, so a refused query reads as "not read" rather than "no errors". Rows stored earlier keep their old wording.

### Added
- **`edge-sampling-probe` read C: what a sampled `count` means.** Yesterday's visitor requests from the sampled groups, taken as `count` and as `count × sampleInterval`, are compared against the exact daily total. The verdict (`count_is_the_estimate`, `multiply_by_interval`, or `unsettled` when neither or both land within 10%) decides whether 17.9.1's change stays.

