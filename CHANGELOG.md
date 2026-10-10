# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.7.0] - 2026-10-10 — the local door reads machine readers by day

### Added
- **The local door takes the machine-readers series too: `sn-metrics{machine_readers}` with `series: "day"`.** The daily split shipped on the remote twin only (23.5.0); the local `sn` door's `machine_readers` section had no way to ask for it. `series` is now an `sn-metrics` argument (enum `day`, machine_readers only) passed beside `days`; omitted, the section receives exactly what it did before. Any other value fails the call, as a bad query does, rather than reading as an outage. Pinned by `tests/abilities-sn-metrics.php`.

