# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.1.1] - 2026-09-15 — the Machine Readers tile names its failure

### Fixed
- **The Machine Readers tile names its failure.** It said "Sensor unreachable" for every code but `not_configured`, and on 2026-09-15 that hid an `http_502`: the sensor was up (401 from outside) and had lost its Analytics Engine read because the Cloudflare token behind its `SN_MR_SQL_TOKEN` secret lost Account › Account Analytics › Read in a token edit. `snt_mr_error_hint()` is the one map (502 → "Sensor cannot read Analytics Engine", naming the grant and the secret; 401/403 → read token refused; other 5xx; network; blocked; bad_schema; unavailable); the summary payload carries it as `hint`, the desktop tile paints it with the code, and the Machine Readers tab's pill reads the same sentence. The grant list on Connections › Cloudflare marks Account Analytics Read as measured and names the sensor and the analytics worker among what the same token feeds. Pinned: nine codes, six distinct titles; mutation red on the 502 branch.

