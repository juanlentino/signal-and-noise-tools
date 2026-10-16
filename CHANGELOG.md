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
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.
- **AI › Models & budget and Connections › Zenodo: the status sits beside its Setup** (live desktop audit, 2026-10-16). Each leaf opened on a thin full-width status strip and kept its Setup card at the very bottom, far from the state it sets. The status card and the Setup card now share the first row; the working content follows (on Models & budget the spend readouts, Jev and the embeddings comparison; on Zenodo the ledger at full width). `docs/kit-leaf-anatomy.md` states the rule. Layout only: every figure, field, action and open warning still paints.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

