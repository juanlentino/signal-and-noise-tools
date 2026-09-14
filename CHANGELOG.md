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
- **The Provenance window's empty Commits table read "Loading anchor status…" forever.** A window has no poller (the classic page hydrates its table from `/status`; the window paints rows server-side), so its empty state is a state, nothing pending, never a wait. The port (#1083, 09-06) borrowed the classic pre-hydration sentence as the empty copy, and it never showed because something was always pending; the first time the queue drained (2026-09-14: 0 pending, 79 confirmed) it read as a stall. The empty copy now says "No pending proofs. Every commit is anchored; press Refresh after minting to see new ones here." Pins in `tests/os-leaf-tools-provenance.php` flipped: the empty table says nothing is pending and never carries "Loading".

## [14.7.1] - 2026-09-14 — the twin carries the prose signing sees

### Fixed
- **Every signed Page read as twin drift the moment it was minted (#1289).** The integrity sweep's leg (b) and `/verify`'s live-match both compared the twin's `content_text`, which the theme builds from RENDERED `the_content`, against the payload's `content`, which signs the RAW body through `sn_prov_normalize_v2()`. Notes agree on both sides; the three signed pages carry `[sn_reading_time]` and a `core/post-date` block, so the twin said "5 min read · May 7, 2026" where the payload said "[sn_reading_time]" and nothing, and the Attention queue showed three Integrity items about pages nobody had edited. Neither side was wrong; they measured different texts. `sn_prov_twin_content_signed()` now hooks the theme's `sn_content_json_document` filter (theme 13.2.1) and adds `content_signed`, the raw-normalized prose, to a provenance subject's twin; `sn_prov_integrity_check_note()` and `deriveLiveMatchVerdict()` read it first and fall back to `content_text` for a twin that predates it. The twin is still built live from the stored body, so an edit since signing still reads as drift; a rendered shortcode no longer does. No re-mint needed: the three clear on the next sweep once both halves ship. Tests: `tests/provenance-integrity.php` 123 → 129 (rendered-only twin still drifts; the same twin with `content_signed` passes; a differing `content_signed` still drifts; the hook adds the field only for a subject and leaves `content_text` alone), `tests/js/prov-verify-core.test.mjs` +3 (same three at the verdict). Mutations red: each comparer ignoring the field.

