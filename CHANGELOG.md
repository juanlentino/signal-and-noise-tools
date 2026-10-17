# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Cleanup: provenance requests drop the v1 signature header, and smaller tidy-ups.** Requests to the provenance Worker no longer send the body-only `X-SN-Signature`; Worker 1.26.1 refuses v1 (`accept_v1: false` on `/_sn/version`), so the v2 `X-SN-Sig` envelope is the only one, and a test now fails if any provenance file sends the v1 header (the owner's own outbound webhooks keep it). The rest changes no behavior: the MCP bridge returns the shared `sn_rest_no_route_error()` instead of its own copy of core's literal; `inc/maturity-legacy-redirects.php` is now `inc/legacy-page-redirects.php` with `sn_legacy_page_redirect_*` functions and a new `sn_legacy_page_redirects` filter (the old `sn_maturity_legacy_redirects` still applies, as a deprecated alias); one script, `tools/fetch-wp-test-core.sh`, fetches the WordPress core files the standalone sweep needs, and both CI sweep jobs call it; stale comments and two `@since Unreleased` tags are corrected; the README states the webmention receiver's limits (10 per IP per hour, 20 per source host per day, 2,000 unverified, each a named 429) and `docs/REST-HARDENING.md` describes the token-gated routes' `rest_no_route` answer and its `no-store` header.

## [2026.10.34] - 2026-10-17 — update purges refresh the cached feeds; a refused REST route is never cached

### Fixed
- **An update refreshes the cached feeds too, and a refused REST route is never cached.** A code update marks the Cloudflare zone stale, and Cloudflare serves a stale feed for up to an hour while it refetches it: after 2026.10.33, `/feed/` and `/notes/feed/` kept their pre-update headers (no `X-Robots-Tag`) until a manual purge. The update purge now also deletes the edge-cached feeds (`/feed/` and `/notes/feed/` in every format, `/llms.txt`, `/llms-full.txt`), after the zone call's outcome is recorded, so the purge ledger still reads the update as a mark-stale; pages keep the stale-safe refresh. Separately, Cloudflare stored the `rest_no_route` 404 that `/owner/revoked` and `/analytics/refresh` give a caller without the token (2026.10.26) and served it to the next request whatever its token, so the analytics Worker's authorized fetch of the revocation list could read a cached 404. Every `rest_no_route` answer, core's own included, now carries `Cache-Control: no-store`.

