# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **sn-apply can label provenance versions minted before the change class existed.** A new publish-only `change.type: "change_class_backfill"` (target `{scope: "provenance_labels"}`) takes `window_dates` (UTC days, matched against each chain entry's `committed_at`) and `labels: [{post_id, change_class}]`, plans the single version each listed post minted in the window, and refuses the whole call (422) when a post has none or several, naming every candidate. The dry run reports each row with its current and proposed label, a day-level audit match (the rw audit log does not record which version a write minted), and the would-be `change_classes` totals; the apply requires the plan fingerprint (409 when stale) and writes through `sn_prov_set_change_class()`, all or nothing, leaving every payload and hash untouched; `sn_prov_set_change_class()` now hands core a slashed chain, because `update_post_meta()` unslashes and a relabel would otherwise strip every backslash from the signed payloads it rewrites. This ships as code only: no version is labeled until the owner runs the backfill.

## [2026.10.34] - 2026-10-17 — update purges refresh the cached feeds; a refused REST route is never cached

### Fixed
- **An update refreshes the cached feeds too, and a refused REST route is never cached.** A code update marks the Cloudflare zone stale, and Cloudflare serves a stale feed for up to an hour while it refetches it: after 2026.10.33, `/feed/` and `/notes/feed/` kept their pre-update headers (no `X-Robots-Tag`) until a manual purge. The update purge now also deletes the edge-cached feeds (`/feed/` and `/notes/feed/` in every format, `/llms.txt`, `/llms-full.txt`), after the zone call's outcome is recorded, so the purge ledger still reads the update as a mark-stale; pages keep the stale-safe refresh. Separately, Cloudflare stored the `rest_no_route` 404 that `/owner/revoked` and `/analytics/refresh` give a caller without the token (2026.10.26) and served it to the next request whatever its token, so the analytics Worker's authorized fetch of the revocation list could read a cached 404. Every `rest_no_route` answer, core's own included, now carries `Cache-Control: no-store`.

