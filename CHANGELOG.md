# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.2.1] - 2026-09-15 — the Credentials leaf is a ledger

### Changed
- **The Credentials leaf is a ledger, not a form.** 15.2.0 painted sixteen stacked inputs in one column with the other half of the window empty; the owner called it a wall, and the one row that mattered (the refused sensor token) sat between fourteen nobody edits. Now: left, one table per group (Credential · Source · Value · Verified), so all sixteen rows read in one screen and the verdict word stands in its cell; a refused or errored verdict is also a notice at the top with its sentence and the side to fix. Right, the rail: one "Set a credential" form (a select of the rows a value can be set on, labelled by group; one value field; `clear` removes, `site` derives; rows locked in wp-config.php are not offered), Verify all with its time, and the four worker commands in one place. Fields are `key_id` + `key_value`; `keyring_save` saves one row per press and answers `keyring_locked` or `keyring_unknown_row` by name. Same on the classic leaf. Re-pinned: 24 (parity, the tables' rows, the select's options, the notice above the ledger, never a raw value).

### Removed
- **The four credential handlers the keyring replaced** (`sn_handle_cf_save`, `sn_handle_analytics_save`, `sn_handle_analytics_use_central_token`, `sn_handle_monitoring_save`), the two field helpers only their forms called (`sn_uptime_status_token_field_html`, `sn_spend_watch_settings_fields_html`), the spend-watch save helper only the monitoring handler called, and their six flash codes. Deprecated in 15.2.0, unreachable since; 221 lines gone. What they did on a change (drop the uptime snapshot, the spend usage caches, the Spotify token cache, the Machine Readers cache) the keyring handler now does from the registry: rows carry `flush` (transient keys) and `flush_fn`, and `sn_keyring_flush()` runs on every save or switch, so a rotated key never serves a stale reading.

