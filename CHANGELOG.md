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
- **A refused multi-edit in the drift family keeps its real status.** For `drift_replace` and `emdash_replace` with `payload.edits`, gate 1 dropped the planner's code and status, so an overlap, an ambiguous phrase or too many edits came back as a 409 `snt_sn_apply_fingerprint_stale` and sent the caller to re-scan for nothing. Gate 1 now carries the planner's own code and status, as `sentence_replace` has since v13.95.1. The planner's 409s (a moved phrase, a stale per-edit fingerprint) stay the fingerprint's failure. Every other refusal is a payload conflict: a 422, reported as a validation finding with the fingerprint gate marked `plan_refused`. The description's exception for these two types is gone. Pinned in `tests/sn-apply-batch-edits.php`.

### Changed
- **sn-apply's TARGETS rule says "a list of them", not "an array (batch)",** which read like the `batch` change type. The batch-scope check also gains tests for non-string scopes and a nested list (`tests/abilities-sn-apply-batch-and-rollback.php`).
- **sn-apply's description says to make one call per post from clients that refuse a list.** A fresh Claude client reads `target` as an object only and refuses a list target before sending it ("expected object, received array"), so the description no longer leaves "an array (batch)" as the only guidance. Pinned in `tests/sn-apply-description.php`.

## [2026.10.74] - 2026-10-26 — sn-apply target keeps the order clients read

### Fixed
- **sn-apply's `target` keeps the type order clients read.** 2026.10.73 listed `array` before `object` so WordPress's first-fit validator would check a list's items. But an MCP client reads this type list as its first type: it already collapsed `["object","array"]` to an object. Once a client reloaded its schema, array-first would have refused every single-target call, the write door's main use. The order is `["object","array"]` again. A list stays closed to the three unadvertised types: `snt_sn_apply_batch_scope_error()` holds every item to the single target's scope enum at runtime, before anything resolves, and refuses with a 422 naming the item. The schema still describes each item with the same properties. Pinned in `tests/abilities-sn-apply-batch-and-rollback.php` (red with the check disabled) and `tests/mcp-projection-schema-types.php`.

