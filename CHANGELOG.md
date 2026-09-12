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
- **The OpenStation Preferences tab names its sidebar glyph.** The `Signal & Noise` tab rendered with an empty icon column because OpenStation's settings-tab registry had no icon field — no plugin could supply one ([WordPress/openstation#808](https://github.com/WordPress/openstation/issues/808)). The registration now passes `icon: 'bell'` (an OS icon-set name); OpenStation ≤ 1.1.8 ignores the key, 1.1.9+ draws it. Confirmed against 1.1.8: none of the 52 OpenStation symbols the plugin consumes changed between 1.1.7 and 1.1.8 — the five that appear in that diff are tests and docblocks only. Suite 99 → 100; the new guard mutation-checked red.

## [14.1.0] - 2026-09-12 — the WAF probe gets a witness

### Fixed
- **The WAF probe measures the rule from a vantage that can judge it.** The 2026-09-12 investigation settled the open question: from a non-origin host an `Authorization`-bearing GET is answered **403 at the edge** on both `/wp-json/wp-abilities/…` and `/?rest_route=/wp-abilities/…` — the rule is in force and `http.request.uri` covers both spellings — while the same request **from the origin server** is not refused, so no request the plugin sends can ever judge it (the "0 events" counter was simply that nobody had sent one from outside). `sn_health_cf_waf_abilities_probe()` no longer probes; it reads a **Better Stack witness monitor** — an HTTP monitor on the abilities URL that carries an `Authorization` header from Better Stack's network and expects 403. The monitor's *configuration* is verified, not just its name (a plain status monitor without the header reads `up` whether or not the rule exists), a down witness while the site itself is down is uninformative rather than "open", and one witness per URL spelling closes the `uri.path` coverage gap. No witness, a mis-set one, a pending one, no token, or Better Stack unreachable → the check reports itself **SKIPPED** with the reason (and how to create the witness), never as a pass; that reading is never cached. Same request budget: one Better Stack GET per 6h replaces the GET to the abilities route. Replaces the `KNOWN UNSOUND` note. Suite 45 → 66 assertions; both new guards mutation-checked red.
- **"Re-run scan" now re-measures the edge.** The manual button drops the two 6h edge transients (`sn_health_cf_headers_probe`, `sn_health_cf_waf_abilities_probe`) before scanning; a stale verdict used to survive every press until the transient expired. The daily cron keeps the cache.
- **The Cloudflare WAF finding no longer claims the rule is missing.** The `waf: Block Basic-auth on abilities API` probe shipped 2026-09-11 and reported the abilities route open on its first run. The rule is in fact present: dashboard-verified 2026-09-12, custom rule 4 of 5, action Block, status Active, expression matched on `http.request.uri`. The probe's reading is the thing at fault, and the reason is not yet established — it calls `home_url()` **from the origin server**, and `cf-ray` on the response proves the response traversed Cloudflare, not that the WAF custom-rule phase judged the request. An IP Access Rule or WAF exception covering the origin's own address would produce exactly this reading. The finding now says what is actually known — the route was not refused *from this server*, which is "unverified from here", not "the rule is missing" — and the probe carried a `KNOWN UNSOUND` note until a request from a non-origin host settled it the same day (previous bullet). Audit row E3 and `.github/security-scan-instructions.md` are corrected the same way. `sn_mcp_rw_guard_run_route` holds the route either way. Copy and docs only — no behaviour change.
- **The WAF probe's coverage limit is written down.** It probes the `/wp-json/` spelling only; a rule written on `http.request.uri.path` would satisfy it while leaving `/?rest_route=/wp-abilities/v1/...` unmatched. The live rule correctly uses `http.request.uri`; the witness-per-spelling above is what now measures it.

