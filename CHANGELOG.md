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
- **The Provenance column was empty on every row.** 14.3.0 registered the column, and the badge never painted: OpenStation's Posts window trims every `/wp/v2/posts` fetch with a `_fields` allowlist (`id,title,status,…,openstation_lock,_links,_embedded`), so a registered REST field does not ride the list unless it is named there — the Explorer's request never trimmed, which is why the same field worked there. Found on the live shell after install: the column key was in the table's column set, the row objects had no `sn_provenance`. The shell's own `openstation_posts_window_query_args` filter exists for exactly this ("extend `_fields` to ship more columns"); `snt_os_posts_window_query_args()` appends `sn_provenance` to the allowlist, never replaces it, idempotent, and leaves every other arg alone. Guards 108 → 113; both the "never registered" and the "replace instead of append" mutations go red by name.

## [14.3.0] - 2026-09-12 — a Provenance column in the native Posts window

### Added
- **A Provenance column in OpenStation's native Posts window.** `assets/os-posts-provenance.js` registers on the shell's `openstation.postsWindow.columns` filter (1.1.8, #779) and paints the Explorer's anchor-status badge — dot + `v{n}`, title with the status label — in the table, the writing-desk cards and the inspector, one node per render as the workspace requires. It reads the `sn_provenance` REST field the plugin already exposes on posts, so the value rides the `/wp/v2/posts` request the window already makes: no extra fetch, no PHP. An unsigned Note paints an empty node, never a gray badge (absent is not zero). Its own handle on every shell request with `wp-hooks` as the sole dependency — not the lazily-loaded Explorer bundle, which would leave the column absent until the Explorer had been opened. Idempotent against the filter running on every paint. The first Signal & Noise view moved onto the shell's surface; `statusSegments` was ruled out for "Needs attention" (its value is sent verbatim as `?status=`, and our queue is not a post status). Guarded in `tests/openstation-preferences.php` (100 → 108; the idempotence pin mutation-checked red).

