# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.9.1] - 2026-09-15 — the Cloudflare API row is figure-sized again; the firewall refusal names its grant

### Fixed
- **The Cloudflare API row erased its own label (#1313).** 14.9.0 put a whole sentence in the row's value; the kit list's value never shrinks and the label "Cloudflare API" was ellipsised to nothing on the live Dashboard. `sn_cf_monitor_api_row()` now returns a figure-sized value ("token active", "expires 2026-09-20" in amber inside 14 days, "token expired" in red, "not run yet", "not configured") and carries the sentence (headers, last call, expiry) on the row's `title`; `snt_kit_list()` accepts that title, and `assets/os-app.css` gives the label a 6em floor and lets the value shrink with an ellipsis at 60%, so no reading can do this again. Pins in `tests/cloudflare-monitor.php` (every value under 20 characters; the CSS floor and cap; the title seam).
- **The firewall reading named the wrong grant and read its refusal as an error.** With Zone › Analytics › Read added, the zone read came back (160,291 requests, 8.3% from cache, 4,139 edge 5xx over seven days) and the firewall answered "zone … does not have access to the path": that dataset sits behind **Zone › Firewall Services › Read**, and the sentence is a refusal, not a fault. `sn_cf_graphql_needs_permission()` reads it as a gap; `sn_cf_monitor_permission_hint( $dataset )` names the grant per dataset. Pinned on the live refusal body.
- **The zone reading keeps the 5xx codes, not just the class.** The first reading showed 4,139 edge 5xx in seven days against 5 origin 503s a day (Cloudways' own log, 2026-09-15), which a class count cannot explain: Cloudflare's 520/522/524 (it could not reach or wait for the origin) and the origin's 503 (Varnish backend fetch) are different problems. `totals.status_5xx_codes` carries the map, and the Monitor lists each code with Cloudflare's meaning beside it.

