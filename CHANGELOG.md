# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.72] - 2026-10-26 — keyring-status never serves part of a secret

### Security
- **`keyring-status` no longer serves any part of a secret.** The keyring's change log stored, and the read door's `keyring-status` returned, the last four characters of every value written to a keyring option (the Cloudflare token, the Anthropic admin key, the site secret, the owner key), against the tool's own "never a value". Each line now carries `fp` instead: an 8-hex HMAC-SHA256 of the value keyed with the site's own salt, so two values can still be told apart while no part of either is shown and a guess cannot be checked offline. Older lines are stripped on read and scrubbed from storage on the next write. Pinned in `tests/keyring-changes.php`. (2026-10-26 MCP doors audit, N3.)

