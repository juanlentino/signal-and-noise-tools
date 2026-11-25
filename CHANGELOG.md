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
- **The AI screen states which ranking the related notes actually use.** Each rebuild of the related-notes lists now records whether it ranked by meaning (embeddings) or by shared words, as a `source` key in `snt_ml_corpus_meta`. The embeddings sentence compares that record with the Related notes setting and says what readers see: ranked by meaning; set to meaning but the last rebuild fell back to shared words (it ran before embeddings were set up, or a note failed to embed); ranked by shared words; or set to shared words while the current lists still rank by meaning until the next rebuild. Before the first rebuild that records it, the sentence stays conditional. Tests: `tests/ml-artifacts.php`, `tests/os-leaf-ai-models-budget.php`.

## [2026.11.62] - 2026-11-25 — Controls for the read and write door switches, the write-door email and the related-notes ranking

### Added
- **Controls for four stored choices that had none.** Each could only be changed in the database. Each now has a control on both surfaces, in the classic screens and the native window, placed with the settings it belongs to. Defaults are unchanged, so nothing behaves differently until a control is saved.
  - **Settings › AI, "Door switches":** "Read door enabled" (`sn_mcp_read_enabled`) and "Write door enabled" (`sn_mcp_rw_enabled`), one form (action `mcp_doors_save`). The box sits next to the doors' credentials and the remote door's switch. Both default to on, as the guards read an untouched option. They are stored as 1 or 0, not as a boolean: WordPress's `update_option()` stores nothing when a `false` equals the `false` a missing row reads as, so a first "off" would not have saved. A door that `SN_MCP_READ_DISABLED` or `SN_MCP_RW_DISABLED` locks in wp-config.php paints disabled and names the constant, and the handler leaves its option alone.
  - **Settings › AI, "Related notes":** "Rank related notes by", with the choices Meaning (embeddings) and Shared words (`ml.related_source`, action `related_source_save`). The value is allowlisted, and a missing or off-list post keeps what is stored. A change queues the related-notes rebuild, which runs within a minute.
  - **Settings › Notifications and reports, "Write-door alerts":** "Email the admin address each time the write door is used" (`sn_mcp_rw_notify`, action `mcp_rw_notify_save`), beside the other email switches. It defaults to off. Calls within a minute of an email are counted in the next one.
  - The write door's off reason on its status card now names the pane, not the option.
  - New: `inc/admin-post-actions/mcp-doors.php`, `inc/admin-forms/mcp-door-switches.php`, `apps/sn-dashboard/parts/leaves/settings-switches.php`.
  - Tests: `tests/settings-switches-save.php` (new). It uses an option store that keeps core's early return, and it goes red when the switches store a boolean or when the lock is skipped. `tests/os-leaf-settings-ai.php` and `tests/os-leaf-settings-notifications.php` pin both twins: fields, values, the locked state and the whole-form rule.

### Fixed
- **The AI screen no longer says embeddings run in "shadow mode".** That sentence was written before embeddings began ranking the related notes and has been false since. With embeddings configured, it now follows the related-notes ranking: by meaning, it says embeddings rank the related notes from the next rebuild, and that a note that fails to embed puts that rebuild back on shared words; by shared words, it says nothing readers see uses them. Same sentence on both twins. Test: `tests/os-leaf-ai-models-budget.php`.

