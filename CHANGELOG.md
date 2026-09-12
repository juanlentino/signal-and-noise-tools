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
- **The Cloudflare WAF finding no longer describes a rule that never existed.** The `waf: Block Basic-auth on abilities API` probe shipped on 2026-09-11 as a *drift* check, on the assumption — carried by a memory note and by `docs/audits/enforcement-audit-2026-09-11.md` E3 — that the rule had been in force since 2026-08-26. Its first run measured the abilities route open through the edge, and the owner confirmed the rule was never created. The check's copy said the edge rule "was dropped or misconfigured" and that "the edge layer is gone", sending a reader to hunt the Cloudflare dashboard for something that was never there; it now reads "not in force — dropped, disabled, or never created". Audit row E3 is marked REFUTED, its gap-list entry withdrawn, and `.github/security-scan-instructions.md` no longer tells the security reviewer that an edge control exists. `sn_mcp_rw_guard_run_route` is and always was the sole control on that route. Copy and docs only — no behaviour change.
- **The WAF probe's coverage limit is now written down.** It probes the `/wp-json/` spelling only; a rule written on `http.request.uri.path` would satisfy it while leaving `/?rest_route=/wp-abilities/v1/...` open. The rule must match on `http.request.uri`.

## [14.0.3] - 2026-09-12 — check for updates without a nonce

### Fixed
- **"Check for updates" no longer expires in the phone PWA.** An iOS standalone PWA keeps the last-rendered admin page in memory for days without reloading, so the `wp_nonce_url()` "Check now" link in it died after 24h (or a session-token rotation) with "The link you followed has expired." The three links now point at core's own nonce-free `update-core.php?force-check=1` (gated by `update_core`), and the plugin's cache clear runs there on `load-update-core.php`. The old admin-post door stays for bookmarks. `tests/force-check-no-nonce.php`.

