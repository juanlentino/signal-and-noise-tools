# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.4.0] - 2026-09-13 — two more Signal & Noise views on the shell's Posts window — Edge column, Attention pill

### Added
- **An Edge column in OpenStation's native Posts window.** Beside Provenance: the post's last edge-cache probe verdict — `fresh` or `stale`, with the probe time and whether a zone purge was forced in the title. A new `sn_edge` REST field on posts (`snt_explorer_edge_field()`) wraps `sn_note_dossier_last_probe()`, the reader the note dossier's Edge block already uses, so the column and the dossier cannot disagree about the same row. Not public: a reader without `manage_options` gets null. A post with no row in the site-wide twenty-row probe log paints nothing — a gap, never fresh. The column script is renamed `assets/os-posts-provenance.js` → `assets/os-posts.js` (handle `snt-os-posts`), one script for every column we add; the fields it needs ride the window's `_fields` allowlist from one list, `SNT_OS_POSTS_FIELDS`, and a guard checks every field PHP ships has a column the script renders. Phase 1 of `docs/plans/2026-09-12-posts-window-moves.md`.
- **An Attention pill in OpenStation's native Posts window.** One `<os-button>` in the toolbar (`openstation.postsWindow.toolbarTrailing`): "Attention · N". The decision the plan deferred is made: N is what the app LAST composed. A new `GET signal-noise/v1/openstation/attention` route (`manage_options`, cookie+nonce) reads the `snt_os_attention` transient only — `{ count, read_at, stamp, stale }` — and answers `count: null, stale: true` when the app has not composed; it never calls the composer, and a guard that strips comments then scans the route file for a call keeps it that way. The pill fetches once on `opened`, refreshes on `dataLoaded` no more than once per 60 s (the transient's own TTL), and removes itself on a refused fetch rather than painting a 0. A click opens the Signal & Noise app on its Attention section: `wp.os.openWindow( 'signal-noise', { params: { section: 'attention' } } )`, honoured by a new `mount` handler and `reopen` action in the app — both route through `go_to_section()`, the function `go` now uses, so a deep link and a folder tap cannot drift. REST census 21 → 22. Phase 2 of the plan.

