# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.34] - 2026-10-17 — update purges refresh the cached feeds; a refused REST route is never cached

### Fixed
- **An update refreshes the cached feeds too, and a refused REST route is never cached.** A code update marks the Cloudflare zone stale, and Cloudflare serves a stale feed for up to an hour while it refetches it: after 2026.10.33, `/feed/` and `/notes/feed/` kept their pre-update headers (no `X-Robots-Tag`) until a manual purge. The update purge now also deletes the edge-cached feeds (`/feed/` and `/notes/feed/` in every format, `/llms.txt`, `/llms-full.txt`), after the zone call's outcome is recorded, so the purge ledger still reads the update as a mark-stale; pages keep the stale-safe refresh. Separately, Cloudflare stored the `rest_no_route` 404 that `/owner/revoked` and `/analytics/refresh` give a caller without the token (2026.10.26) and served it to the next request whatever its token, so the analytics Worker's authorized fetch of the revocation list could read a cached 404. Every `rest_no_route` answer, core's own included, now carries `Cache-Control: no-store`.

