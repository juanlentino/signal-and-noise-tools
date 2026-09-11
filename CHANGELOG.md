# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Security
- **The abilities run route now meets the write door.** `POST /wp-abilities/v1/abilities/<slug>/run` is registered by core for every `show_in_rest` ability — all eight rw-door slugs and the seven pre-consolidation apply abilities — and was reachable with **any** `manage_options` application password while the rw door's four controls (kill switch, bound credential, 30/min rate limit, audit row) lived on `/signal-noise/v1/mcp-rw` alone. Found by the 2026-09-11 enforcement audit ([docs/audits/enforcement-audit-2026-09-11.md](docs/audits/enforcement-audit-2026-09-11.md)); for the life of the gap the only external control was a Cloudflare WAF rule in no repository. `sn_mcp_rw_guard_run_route()` (`inc/mcp/mcp-rw-guard.php`) on `rest_pre_dispatch` applies the door's controls, in the door's order and with the door's error codes, to any request that authenticated by application password against an ability not annotated `readonly`. It keys on the **credential, not the route**, because wp-admin's own buttons call the same route with cookie + nonce and must pass untouched — the negative assertion in `tests/mcp-rw-guard-run-route.php` (32 pins; verified failable by neutering the credential test: 11 red). Allowed calls are logged as `ok`/`error` on `rest_request_after_callbacks`, so the audit log shows this route the way it shows the door.
- **The WAF rule has a witness.** `Cloudflare security headers` now also sends one `Authorization: Basic` request to the abilities catalogue and expects the edge to answer 403; a 401/200 *through* the edge (cf-ray present) is a finding naming the rule, and a response with no edge marker is `unknown` — never cached, never a pass (`tests/health-checks-cf-headers.php`, 5 new cases).

### Fixed
- `inc/sn-apply/gates.php` said an unbound or mismatched credential was "denied at the door, before sn_apply's execute_callback ever runs". True of the door, false of the run route; gate 3 held on its own because it reads the UUIDs itself. The comment now says what was live.

### Changed
- `.github/security-scan-instructions.md` §2 names the run route as a write surface, the guard as its control, and five change shapes that widen it.
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

