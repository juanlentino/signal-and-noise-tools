# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Security
- **The write door's Application Password reaches the write door only.** It is a full administrator credential, and outside `/signal-noise/v1/mcp-rw` nothing held it: it could run the destructive abilities neither door offers (`clear-template-overrides`, `run-cron-event`, `merge-tags`) on the abilities run route, and every core route (`/wp/v2/posts`, `/wp/v2/settings`, ...). `inc/mcp/mcp-rw-credential-scope.php` now refuses it, 403 `sn_mcp_rw_credential_out_of_scope`, everywhere except the door and the run routes of the door's own tools, and writes each refusal to the rw audit log. Other Application Passwords and the owner's cookie session are untouched. Pinned in `tests/mcp-rw-credential-scope.php`. (2026-10-26 MCP doors audit, N5 and N0. The read door's password is not bound to the door, so it cannot be told from any other administrator password yet.)
- **The read kill switch also closes the plugin's other reads to machine callers.** With the switch on, an Application Password caller on the run route could still use about two dozen read-only abilities that are not on the read door (`get-post-content`, `list-posts`, `note-dossier`, the audit-log reads). The switch now refuses those too, for this plugin's and the theme's abilities, with the door's own code. Writes, the remote door's abilities (its own switch governs them), other plugins and the owner's cookie session are untouched. Pinned in `tests/mcp-read-guard-run-route.php`. (N1.)

### Changed
- **sn-apply's description says to make one call per post from clients that refuse a list.** A fresh Claude client reads `target` as an object only and refuses a list target before sending it ("expected object, received array"), so the description no longer leaves "an array (batch)" as the only guidance. Pinned in `tests/sn-apply-description.php`.

## [2026.10.74] - 2026-10-26 — sn-apply target keeps the order clients read

### Fixed
- **sn-apply's `target` keeps the type order clients read.** 2026.10.73 listed `array` before `object` so WordPress's first-fit validator would check a list's items. But an MCP client reads this type list as its first type: it already collapsed `["object","array"]` to an object. Once a client reloaded its schema, array-first would have refused every single-target call, the write door's main use. The order is `["object","array"]` again. A list stays closed to the three unadvertised types: `snt_sn_apply_batch_scope_error()` holds every item to the single target's scope enum at runtime, before anything resolves, and refuses with a 422 naming the item. The schema still describes each item with the same properties. Pinned in `tests/abilities-sn-apply-batch-and-rollback.php` (red with the check disabled) and `tests/mcp-projection-schema-types.php`.

