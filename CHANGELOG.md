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
- **The TypeSafe key is the connector's; the keyring row is gone.** Connector for TypeSafe Jev (a separate plugin, `juanlentino/jev-connector`) registers `typesafe` with Core's Connectors API and holds the key under Settings › Connectors, resolving env → `TYPESAFE_API_KEY` → Core's `connectors_typesafe_api_key`. `sn_jev_key()` now reads `JevConnector\Connector::get_api_key()` and nothing else; the `typesafe_api_key` keyring row and its probe leave (the connector's screen owns "is the key good"), keyring 21 → 20 rows. A one-shot migration on `plugins_loaded` moves a key this plugin stored before 16.5.2 into Core's option when the connector is active and holds none, and deletes the old option either way once the connector is present; until the connector is installed the old value is kept and Jev is simply off. Every not-ready message (check 30's skip, the fit band, `jev-notes`) says "Install Connector for TypeSafe Jev and add the key under Settings › Connectors". The transport stays this plugin's (`sn_jev_ask`, its pinned parser); the connector's own client is 0.x. Pinned.

## [16.5.1] - 2026-09-18 — the floor is the site's scale

### Fixed
- **The fit floor is 5 impressions, not 20, and `jev-query-fit` shows what was judged.** The first live fit pass (16.5.0) judged one note on two queries: Search Console's 28-day window held 469 impressions across 47 pages, with page impressions running 229, 40, 38, 22, 15…, so a 20-impression floor on a page × query pair left one pair standing. The floor is 5, which is the site's scale rather than the rubric's; the pass is still one request per note. The read ability now returns `judged_notes` (every note judged with its rows: query, counts, score, confidence) beside `gaps` and `stray`, so the reading can be checked against what was asked. Pinned.

