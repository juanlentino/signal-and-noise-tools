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
- **The integrity sweep re-reads a failing subject before its turn in the rotation (#1295).** The sweep checks 10 of 46 subjects a day, never-read then oldest-checked, so a subject that just failed was the freshest-checked and went to the back: the three pages flagged `twin_drift` at 08:00 UTC on 2026-09-14, and fixed by 14.7.1 hours later, would have stayed in the Attention queue until about 09-19. "Run scan now" checks the next ten oldest and the dossier's live check never writes back, so nothing could confirm the fix sooner. `sn_prov_integrity_select_batch()` now takes the failing ids: a subject whose last verdict is a reconfirmable mismatch (`sn_prov_integrity_is_reconfirmable()`: drift, hash, ledger, key) is re-read ahead of the rotation, at most half the cap so the rotation always moves, and never ahead of a never-read subject; an outage is a gap and an absence (`no_signed_commit`, unresolved kind) is an owner-side state, and neither earns the early slot. Pins in `tests/provenance-integrity.php` (selector order under each guard; a run-level pin that the freshest-checked drifting note is re-read next run while an outage-only one and eleven chainless ones wait). Mutation red: sweep not passing the failing list.
- **The Provenance window's empty Commits table overclaimed.** 14.7.2 replaced "Loading…" with "Every commit is anchored", which sat beside an Attention queue listing three integrity failures. Anchored is not intact, and this table measures neither. The copy now says only that no proofs are pending and, when the integrity sweep's last reading has failures, quotes the count and the reading's time and points at Trust checks (`provenance_commits_empty_copy()`; pinned both ways).
- **Retired the `zero-impression notes` watch.** Date-only, ripened 2026-09-14 with nothing measured and no `ripe` callable to un-ripen it; its question is answered (6 not indexed, all queued for a GSC request) and the Search Attention reader carries it daily since 14.7.0. `tests/watches.php` moves the date-only contract pins to `wave4_telemetry` and pins the retirement.

## [14.7.2] - 2026-09-14 — the Provenance window's empty Commits table

### Fixed
- **The Provenance window's empty Commits table read "Loading anchor status…" forever.** A window has no poller (the classic page hydrates its table from `/status`; the window paints rows server-side), so its empty state is a state, nothing pending, never a wait. The port (#1083, 09-06) borrowed the classic pre-hydration sentence as the empty copy, and it never showed because something was always pending; the first time the queue drained (2026-09-14: 0 pending, 79 confirmed) it read as a stall. The empty copy now says "No pending proofs. Every commit is anchored; press Refresh after minting to see new ones here." Pins in `tests/os-leaf-tools-provenance.php` flipped: the empty table says nothing is pending and never carries "Loading".

