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
- Forbid redirects on the four credentialed outbound calls that had drifted from the v8.7.1 outbound-hardening convention: the Workers AI embedding request in `inc/ml-embeddings.php` (a Bearer to the same `api.cloudflare.com` host `inc/cloudflare-purge.php` already guards) and all three in `inc/search-console-client.php` — the OAuth token exchange, which POSTs a private-key-signed JWT assertion, plus the shared `snt_gsc_api_get()` / `snt_gsc_api_post()` helpers, so every Search Console call in the plugin inherited the gap. WordPress defaults to `redirection => 5`, so omitting the key opted in to re-sending the credential to a 3xx target. No call flow, host, or response handling changes.

### Added
- `tests/outbound-credential-redirect-guard.php`: a census guard deriving every credentialed `wp_remote_*` call under `inc/` from the source and requiring `redirection => 0`. The convention was previously pinned by eleven per-feature suites, none of which could see a call site nobody remembered to add — which is how the four above stayed uncovered. Balanced-region parse (the args array spans many lines), with floor assertions on call sites found and credentials recognised so a rotted scan fails instead of reporting a clean sweep over nothing.

## [13.107.5] - 2026-09-08 — Quiet status widget refresh

### Fixed
- Keep Deploy Status and Uptime background polls silent: retain the rendered content and colors while pending, preserve Last deploy and last-successful-refresh recency, and advance refresh timestamps only on success. Failed refreshes retain last-known data with a compact keyboard-accessible warning and tooltip details instead of a warning paragraph; retries, backoff and teardown cancellation remain unchanged.

