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
- **sn-apply's description states each rule once.** The write door's one content-mutating tool carried a 19,998-character description (about 5k tokens, half the write door's `tools/list`) in which the fingerprint rule appeared five times and all-or-nothing, the 50-change limit and the scheduled-post guarantee twice each. It now opens with RULES FOR EVERY TYPE (gates, dry run, modes and the publish-only and revision-only lists, fingerprint, targets, idempotency, change class, prose delta, scheduled posts, multi-change) and gives each type only what differs: 16,023 characters, every gate, refusal and "never" kept. It also names `dismiss` (publish-only), which it accepted but never described. `merge_tags`, `clear_template_overrides` and `schedule_cron_event` stay unadvertised: `target.scope`'s enum admits none of their scopes, so the door's input validation refuses them (an owner decision whether to open them). Pinned in `tests/sn-apply-description.php` (every safety rule present, the shared ones exactly once, only reachable types advertised).

## [2026.10.72] - 2026-10-26 — keyring-status never serves part of a secret

### Security
- **`keyring-status` no longer serves any part of a secret.** The keyring's change log stored, and the read door's `keyring-status` returned, the last four characters of every value written to a keyring option (the Cloudflare token, the Anthropic admin key, the site secret, the owner key), against the tool's own "never a value". Each line now carries `fp` instead: an 8-hex HMAC-SHA256 of the value keyed with the site's own salt, so two values can still be told apart while no part of either is shown and a guess cannot be checked offline. Older lines are stripped on read and scrubbed from storage on the next write. Pinned in `tests/keyring-changes.php`. (2026-10-26 MCP doors audit, N3.)

