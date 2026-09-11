# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.110.0] - 2026-09-11 — the abilities run route meets the write door

### Security
- **The abilities run route now meets the write door.** `POST /wp-abilities/v1/abilities/<slug>/run` is registered by core for every `show_in_rest` ability — all eight rw-door slugs and the seven pre-consolidation apply abilities — and was reachable with **any** `manage_options` application password while the rw door's four controls (kill switch, bound credential, 30/min rate limit, audit row) lived on `/signal-noise/v1/mcp-rw` alone. Found by the 2026-09-11 enforcement audit ([docs/audits/enforcement-audit-2026-09-11.md](docs/audits/enforcement-audit-2026-09-11.md)); for the life of the gap the only external control was a Cloudflare WAF rule in no repository. `sn_mcp_rw_guard_run_route()` (`inc/mcp/mcp-rw-guard.php`) on `rest_pre_dispatch` applies the door's controls, in the door's order and with the door's error codes, to any request that authenticated by application password against an ability not annotated `readonly`. It keys on the **credential, not the route**, because wp-admin's own buttons call the same route with cookie + nonce and must pass untouched — the negative assertion in `tests/mcp-rw-guard-run-route.php` (32 pins; verified failable by neutering the credential test: 11 red). Allowed calls are logged as `ok`/`error` on `rest_request_after_callbacks`, so the audit log shows this route the way it shows the door.
- **The WAF rule has a witness.** `Cloudflare security headers` now also sends one `Authorization: Basic` request to the abilities catalogue and expects the edge to answer 403; a 401/200 *through* the edge (cf-ray present) is a finding naming the rule, and a response with no edge marker is `unknown` — never cached, never a pass (`tests/health-checks-cf-headers.php`, 5 new cases).

### Fixed
- `inc/sn-apply/gates.php` said an unbound or mismatched credential was "denied at the door, before sn_apply's execute_callback ever runs". True of the door, false of the run route; gate 3 held on its own because it reads the UUIDs itself. The comment now says what was live.

### Changed
- `.github/security-scan-instructions.md` §2 names the run route as a write surface, the guard as its control, and five change shapes that widen it.
- README: an **OpenStation** bullet naming what the plugin ships for the shell — the three native windows, ten widgets, 22 palette commands, the dock / badge / Station Home / PWA / Copilot seams — every count re-derived from the tree.

