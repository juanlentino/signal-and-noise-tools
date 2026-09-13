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
- **The Updates page said "Compatibility with WordPress 7.1: Not tested" — on a 7.1 site, for a plugin whose header says `Tested up to: 7.1`.** Core paints the value the *updater* reports, and `inc/wp-update-integration.php` hard-coded `tested = '7.0'` in both places (the update transient and the View details modal) while the header had moved on. `sn_gh_plugin_compat()` now reads `Requires at least` / `Tested up to` / `Requires PHP` from the plugin file header once, and both surfaces use it — one source, so the two cannot drift again. `tests/manifest-floor.php` refuses any compatibility literal in the updater and pins the reported `tested` to the header's 7.1; both mutations (a literal restored, the helper's own fallback) go red.

## [14.5.0] - 2026-09-13 — Attention rows solve where they are; a pending anchor heals from the ledger

### Fixed
- **A `pending` anchor that the ledger had already confirmed stayed pending forever.** "Nobody can sign an absence" v1 sat `pending` in WordPress for thirteen days while the public ledger had it confirmed at Bitcoin block 964812, same hash: the Worker's confirm callback was lost, the Worker then dropped its pending row (its rule since 1.8.2), and nothing on either side could heal it — the app's Retry anchor and the hourly sweep only re-dispatched `unanchored`. `sn_prov_reconcile_post()` now also handles `pending`: it reads the commit's ledger record (the same record the integrity sweep already trusts) and, when it says confirmed with a matching hash, applies it through the callback's own gate (`sn_prov_apply_confirmation()`: hash must match, status allowlisted). A 404, an outage, a still-pending record or a foreign hash change nothing. Retry anchor in the app now covers pending commits and says so ("The ledger was asked about v1"). Guards: the exact ledger URL, four ledger answers, no Worker POST on a heal, no re-read once confirmed; mutations red: bypassing the hash gate, confirming from a still-pending record.

### Changed
- **Two watches retired, both ripe and read.** `integrity_resweep_after_silent_write`: fleet 43, every subject re-checked since the 2026-09-03 write, no `hash_mismatch` — the whole fleet cleared it; the ripen callable and `SNT_WATCH_SILENT_WRITE_AT` go with the row. `origin_503_recheck`: **5** origin-side 503s per 24 h (12/09 15:20 → 13/09 15:20) against the pre-v13.97.2 baseline of 10 — halved, not gone; the rest is the FPM pool, and at 0.06 % of ~8,500 requests/day it does not justify a resize. Watches 7 → 5.

### Added
- **Attention rows can be solved where they are.** Until now a row offered two doors — the Dashboard leaf and the note — and the fix was two clicks away. An `edge` row now offers **Purge edge** and an `anchors` row **Retry anchor**, dispatching to the app's existing `purge` / `anchor` handlers (same guards, same toasts). From the Attention section, which has no post type, `section_post_type()` resolves the post's *own* section — offered to this reader and listing this post — and takes its type, so a signed page's Retry anchor works from Attention exactly as under Pages; an unlisted post gets no button, as it gets no jump. Every row offers **Acknowledge** (new `ack` server action, `manage_options`): the row's key and stamp go to `snt_os_attention_acks`, `attention_visible_rows()` hides the row while the stamp is unchanged, and a new stamp brings it back — an acknowledgement cannot bury a recurring fault. The store is pruned to the queue's own keys on every write. Guards: buttons per kind and gate, hidden-at-stamp / back-on-newer-stamp / prune / corrupt-store, the page path from Attention with a negative control under Notes, the cap gate; mutations red: stamp ignored, fallback outside Attention, resolve ungated.

