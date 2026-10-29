# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **`sn-posts` reads the search surfaces.** Rows carry `seo_title`, `meta_description` and `og_card_title`, the three search fields `sn-apply`'s `surfaces` type writes. Until now the only way to see one was a dry-run write, read back from the diff's `before`. They are opt-in through `fields` like any row key, sit in the full row when `fields` is omitted, and work under every scope with no size cap, because they are short postmeta and not bodies. An unset field is `""`, never omitted and never null, so "not written yet" cannot read as "not supported". `include_surface_checks: true` adds `surface_checks` per row: `meta_description` and `seo_title` each as `{length, status}`, with status `ok`, `short`, `long` or `empty`. The grading reuses existing limits rather than restating them: sn-validate's description range and the search-title health check's ceiling. `seo_title` has no floor, so it is never `short`. The checks are informational and block nothing. Pinned in `tests/abilities-sn-posts.php`, including a source scan that fails on a restated threshold.

### Changed
- **One map from surfaces field to meta key, for the reads.** `inc/surface-fields.php` holds it. `sn-apply`'s dry-run diff, its revision-mode staging and `sn-posts` all read it, where the diff and the staging each used to spell the four keys out. The publish-mode write (`update-post-surfaces`), `create_draft` and the front-end readers still spell them, so a test pins the map to the publish write's four keys. Moving the map changed nothing in the diff or the staging.

## [2026.10.76] - 2026-10-26 — the read door can hold its own credential

### Security
- **The read door's Application Password can be limited to the read door.** Tools > MCP and AI > MCP Clients gain an optional read-door binding beside the write door's. Binding a password limits THAT password to `/signal-noise/v1/mcp` and the read door's tools: everywhere else (the write door, `/wp/v2/*`, other abilities) it gets 403 `sn_mcp_read_credential_out_of_scope`, recorded in the rw audit log as `read-route:` (at most twenty a day: over the log’s 30-day window (31 UTC days at most) that fills at most 620 of its 1,000 rows, so a flood from the lower-trust password cannot roll the write door's rows out), the same scope the write door's password has had since 2026.10.75. The binding does not make the read door exclusive, and unbound the read door works exactly as before, so nothing changes until a password is bound. One password can never be bound to both doors (each door's scope refuses the other door's routes): both setters and both handlers refuse it by name, and the read list leaves the write door's password out. (2026-10-26 MCP doors audit, N0's read half.) Pinned in `tests/mcp-read-binding.php` and `tests/mcp-rw-credential-scope.php`.

