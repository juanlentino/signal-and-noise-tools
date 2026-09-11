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
- README: an **OpenStation** bullet naming what the plugin ships for the shell — the three native windows, ten widgets, 22 palette commands, the dock / badge / Station Home / PWA / Copilot seams — every count re-derived from the tree.

## [13.109.21] - 2026-09-10 — a dead provider is reported, not mistaken for silence

### Fixed
- **`get-narration` could not tell a dead provider from an empty cache.** Both returned `null`. The module already carried a complete failure-recording trio — `snt_narration_store_last_error()`, `snt_narration_last_error()`, `snt_narration_clear_last_error()` — defined, unit-tested in isolation, and **never called by any production path**: `snt_narration_cron_run()` discarded `snt_narration_run()`'s `WP_Error` on the floor. Found under a genuinely depleted API account on 2026-09-10, the one condition that cannot be reproduced on purpose. The cron handler now stores the failure and a successful run clears it; `get-narration` returns the digest stamped `state:ready`, or `state:unavailable` with `reason` / `message` / `failed_at` and an empty body when the last run failed. A cold cache with no recorded failure still returns `null` — the case every existing reader handles, and not a fault.
- The stored failure lived 15 minutes, which was right for the admin-notice flash it was written for and wrong for a state an agent reads a day later. It now persists as long as the digest it stands in for would have been cached, and is cleared only by the next success.

### Changed
- **Remote MCP contract `4` → `5`.** `remote-get-narration` mirrors the admin `output_schema` byte-identically (the parity pin enforces it), so the twin's payload shape moved and `tests/remote-contract-shapes.php` went RED with the new hash — the intended workflow. Additive: every prior key keeps its type. **The worker half is owed:** `sn-remote-mcp-worker` `CONTRACT_VERSION` must move to `5` too; until it does the deploy probe reports `contract_match: false`, which is observed, never refused, by design.

### Added
- Pins in `tests/abilities-narration.php` for all three states, including that a cached digest outranks a lingering failure row and that the cold case did not become a fault. Pins in `tests/insights-narration.php` drive the **real** `snt_narration_run()` through the stubbed generator, so the wiring itself is load-bearing — a helper tested in isolation and never called is a guard that cannot go red. All four verified failable, one of them twice: the first mutation removed an `if` and orphaned its `elseif`, and a parse error reds every test without proving anything.

