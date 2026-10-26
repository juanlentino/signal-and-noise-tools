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
- **sn-apply's description says to make one call per post from clients that refuse a list.** A fresh Claude client reads `target` as an object only and refuses a list target before sending it ("expected object, received array"), so the description no longer leaves "an array (batch)" as the only guidance. Pinned in `tests/sn-apply-description.php`.

## [2026.10.74] - 2026-10-26 — sn-apply target keeps the order clients read

### Fixed
- **sn-apply's `target` keeps the type order clients read.** 2026.10.73 listed `array` before `object` so WordPress's first-fit validator would check a list's items. But an MCP client reads this type list as its first type: it already collapsed `["object","array"]` to an object. Once a client reloaded its schema, array-first would have refused every single-target call, the write door's main use. The order is `["object","array"]` again. A list stays closed to the three unadvertised types: `snt_sn_apply_batch_scope_error()` holds every item to the single target's scope enum at runtime, before anything resolves, and refuses with a 422 naming the item. The schema still describes each item with the same properties. Pinned in `tests/abilities-sn-apply-batch-and-rollback.php` (red with the check disabled) and `tests/mcp-projection-schema-types.php`.

