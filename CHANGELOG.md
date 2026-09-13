# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.3.1] - 2026-09-13 — the Provenance column ships its field

### Fixed
- **The Provenance column was empty on every row.** 14.3.0 registered the column, and the badge never painted: OpenStation's Posts window trims every `/wp/v2/posts` fetch with a `_fields` allowlist (`id,title,status,…,openstation_lock,_links,_embedded`), so a registered REST field does not ride the list unless it is named there — the Explorer's request never trimmed, which is why the same field worked there. Found on the live shell after install: the column key was in the table's column set, the row objects had no `sn_provenance`. The shell's own `openstation_posts_window_query_args` filter exists for exactly this ("extend `_fields` to ship more columns"); `snt_os_posts_window_query_args()` appends `sn_provenance` to the allowlist, never replaces it, idempotent, and leaves every other arg alone. Guards 108 → 113; both the "never registered" and the "replace instead of append" mutations go red by name.

