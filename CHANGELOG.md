# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [23.9.0] - 2026-10-11 — the watch is read where it can be read, the cutover check names its last exact day, a gap can be marked planned

### Fixed
- **The General-save guard watch is read only where it can be read.** It asks whether `admin_email` still lands in Core's General settings group; Core puts it there itself in `register_initial_settings()`, hooked on `rest_api_init` only, so a REST read (sn-status{watches}) always saw it and could never ripen, while a wp-admin request shows whether anything else still adds it. On 23.8.0 the desktop said "1 watch due" and the REST read said not: the desktop was right (WordPress/ai#1048 was fixed by WordPress/ai#1080 on 2026-10-05). Only a wp-admin request (admin_init, no rest_api_init) now judges it; any other request answers "cannot tell". Pinned by `tests/watches.php`.
- **The Cutover check says what it measured.** "2 days clean · since Oct 7" read as if every day since were checked, but a sampled day (Oct 9) is neither counted nor a reset. It now reads "clean since Oct 7 · last exact day Oct 8". Pinned by `tests/desktop-status-extra.php` and `tests/desktop-status-resilience.cjs`.

### Added
- **SN Queue: a gap can be marked planned.** The holidays are on purpose, so the gap line has a **Planned** button: it records that gap (`POST /signal-noise/v1/desktop/queue-gap-planned`, `manage_options`, option `sn_content_queue_planned_gaps`, last 20 kept) and the line goes; a different gap still shows. Pinned by `tests/abilities-content-queue.php` and `tests/desktop-status-resilience.cjs`.

