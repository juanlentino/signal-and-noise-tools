# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.75] - 2026-10-26 — the doors hold their credentials

### Security
- **The write door's Application Password reaches the write door only.** It is a full administrator credential, and outside `/signal-noise/v1/mcp-rw` nothing held it: it could run the destructive abilities neither door offers (`clear-template-overrides`, `run-cron-event`, `merge-tags`) on the abilities run route, and every core route (`/wp/v2/posts`, `/wp/v2/settings`, ...). `inc/mcp/mcp-rw-credential-scope.php` now refuses it, 403 `sn_mcp_rw_credential_out_of_scope`, everywhere except the door and the run routes of the door's own tools, and writes each refusal to the rw audit log. Other Application Passwords and the owner's cookie session are untouched. If one password served both doors, the read door now answers 403: give it its own (the connect page no longer says the two doors share one). Pinned in `tests/mcp-rw-credential-scope.php`. (2026-10-26 MCP doors audit, N5 and N0. The read door's password is not bound to the door, so it cannot be told from any other administrator password yet.)
- **The read kill switch also closes the plugin's other reads to machine callers.** With the switch on, an Application Password caller on the run route could still use about two dozen read-only abilities that are not on the read door (`get-post-content`, `list-posts`, `note-dossier`, the audit-log reads). The switch now refuses those too, for this plugin's and the theme's abilities, with the door's own code. Writes, the remote door's abilities (its own switch governs them), other plugins and the owner's cookie session are untouched. Pinned in `tests/mcp-read-guard-run-route.php`. (N1.)

### Fixed
- **A refused multi-edit in the drift family keeps its real status.** For `drift_replace` and `emdash_replace` with `payload.edits`, gate 1 dropped the planner's code and status, so an overlap, an ambiguous phrase or too many edits came back as a 409 `snt_sn_apply_fingerprint_stale` and sent the caller to re-scan for nothing. Gate 1 now carries the planner's own code and status, as `sentence_replace` has since v13.95.1. The planner's 409s stay the fingerprint's failure, now under their own codes (`snt_sn_apply_batch_phrase_not_found`, `snt_sn_apply_batch_fingerprint_stale`) rather than the generic `snt_sn_apply_fingerprint_stale`. Every other refusal is a payload conflict: a 422, reported as a validation finding. An overlap comes only after every per-edit fingerprint matched, so the fingerprint gate reports passed; every other refusal marks it `plan_refused`. `link_insert`, which takes no `payload.edits`, now gets the planner's 422 unsupported type instead of a 409. The description's exception for these two types is gone. Pinned in `tests/sn-apply-batch-edits.php`.

### Changed
- **sn-apply's TARGETS rule says "a list of them", not "an array (batch)",** which read like the `batch` change type. The batch-scope check also gains tests for non-string scopes and a nested list (`tests/abilities-sn-apply-batch-and-rollback.php`).
- **sn-apply's description says to make one call per post from clients that refuse a list.** A fresh Claude client reads `target` as an object only and refuses a list target before sending it ("expected object, received array"), so the description no longer leaves "an array (batch)" as the only guidance. Pinned in `tests/sn-apply-description.php`.

