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
- **The Cloudflare API row erased its own label (#1313).** 14.9.0 put a whole sentence in the row's value; the kit list's value never shrinks and the label "Cloudflare API" was ellipsised to nothing on the live Dashboard. `sn_cf_monitor_api_row()` now returns a figure-sized value ("token active", "expires 2026-09-20" in amber inside 14 days, "token expired" in red, "not run yet", "not configured") and carries the sentence (headers, last call, expiry) on the row's `title`; `snt_kit_list()` accepts that title, and `assets/os-app.css` gives the label a 6em floor and lets the value shrink with an ellipsis at 60%, so no reading can do this again. Pins in `tests/cloudflare-monitor.php` (every value under 20 characters; the CSS floor and cap; the title seam).
- **The firewall reading named the wrong grant and read its refusal as an error.** With Zone › Analytics › Read added, the zone read came back (160,291 requests, 8.3% from cache, 4,139 edge 5xx over seven days) and the firewall answered "zone … does not have access to the path": that dataset sits behind **Zone › Firewall Services › Read**, and the sentence is a refusal, not a fault. `sn_cf_graphql_needs_permission()` reads it as a gap; `sn_cf_monitor_permission_hint( $dataset )` names the grant per dataset. Pinned on the live refusal body.

## [14.9.0] - 2026-09-15 — a Cloudflare monitor over token, zone and firewall

### Added
- **A Cloudflare monitor: what the API will tell us.** The API-limits row said "Cloudflare API: not seen yet" for months while purges fired daily. The rate-limit monitor reads `x-ratelimit-*` headers and Cloudflare sends none (measured 2026-09-15: a v4 response carries `cf-ray` and nothing else; the 1,200-per-five-minutes limit answers 429 when crossed), so the row could never fill and "not seen" read as "never used". `inc/cloudflare-monitor.php` reads the three things the API does offer, daily (`sn_cf_monitor_daily`) and on demand (`cf_monitor_refresh` on both Cloudflare leaves), into one option: the token (`GET /user/tokens/verify`: status, expiry; needs no new permission), the zone's last seven days (GraphQL `httpRequests1dGroups`: requests, cached share, bytes, threats, 4xx/5xx) and the firewall's last 24 hours (`firewallEventsAdaptiveGroups`: events by action, top rules), the last two needing Zone › Analytics › Read on the token. A reading the token cannot make says `needs_permission` and names the permission; it is never a zero. Surfaces: the API-limits row (token status, expiry with a 14-day warning, the last call, and the sentence about headers), a Monitor section in Connections › Cloudflare (native and classic), and `signal-noise/cloudflare-status` as the `cloudflare` section of `sn-status` (local only: it describes the perimeter). Read-only against Cloudflare; every request refuses redirects. Tests: `tests/cloudflare-monitor.php` (28: the three parsers over fixtures including the refusal and the unreachable case, the row's five states, a refresh making exactly three requests and no purge, the daily schedule), censuses re-pinned (status map 24, read door 35, actions 63, both Cloudflare leaves offer the Refresh). Mutation red: a permission gap read as data.

