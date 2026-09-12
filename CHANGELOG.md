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
- **The Cloudflare WAF finding no longer claims the rule is missing.** The `waf: Block Basic-auth on abilities API` probe shipped 2026-09-11 and reported the abilities route open on its first run. The rule is in fact present: dashboard-verified 2026-09-12, custom rule 4 of 5, action Block, status Active, expression matched on `http.request.uri`. The probe's reading is the thing at fault, and the reason is not yet established — it calls `home_url()` **from the origin server**, and `cf-ray` on the response proves the response traversed Cloudflare, not that the WAF custom-rule phase judged the request. An IP Access Rule or WAF exception covering the origin's own address would produce exactly this reading. The finding now says what is actually known — the route was not refused *from this server*, which is "unverified from here", not "the rule is missing" — and the probe carries a `KNOWN UNSOUND` note until a request from a non-origin host settles it. Audit row E3 and `.github/security-scan-instructions.md` are corrected the same way. `sn_mcp_rw_guard_run_route` holds the route either way. Copy and docs only — no behaviour change.
- **The WAF probe's coverage limit is written down.** It probes the `/wp-json/` spelling only; a rule written on `http.request.uri.path` would satisfy it while leaving `/?rest_route=/wp-abilities/v1/...` unmatched. The live rule correctly uses `http.request.uri`.

## [14.0.3] - 2026-09-12 — check for updates without a nonce

### Fixed
- **"Check for updates" no longer expires in the phone PWA.** An iOS standalone PWA keeps the last-rendered admin page in memory for days without reloading, so the `wp_nonce_url()` "Check now" link in it died after 24h (or a session-token rotation) with "The link you followed has expired." The three links now point at core's own nonce-free `update-core.php?force-check=1` (gated by `update_core`), and the plugin's cache clear runs there on `load-update-core.php`. The old admin-post door stays for bookmarks. `tests/force-check-no-nonce.php`.

