# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.7] - 2026-09-07 — Responsive two-column layouts in S&N Home and Analytics

### Added
- S&N Home Composite Settings: introduced responsive two-column `.snt-2up` layout for `Measurement > Analytics`, `Monitoring > Machine Readers`, and `Connections > Cloudflare`, separating writable configurations from read-only operational references.
- S&N Analytics Multi-Column Reports: placed `Traffic quality` and `Bot confidence` tables in `Quality` view, and `Catalog` and `Evergreen vs spike` tables in `Posts` view, into balanced side-by-side `.snt-report-columns`.
- Wide Leaf & Container Queries: uncapped `.snt-leaf` max-width to 100% for all two-column and multi-column settings leaves while preserving responsive `@container (max-width: 860px)` and mobile PWA 1-column collapse.

