# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.6.2] - 2026-09-28 — an unset token is a refusal


- Verify all no longer reads green over an unset worker row: a probed row whose other half lives on a worker (Analytics server token, Machine Readers read token, Cloudflare API token) is now refused, "Not set here (plugin side)", so the banner says a credential was refused and the row names the side. Seen 2026-09-28: the Analytics server token read "unset" under "Every credential with a probe was accepted."
- The keyring keeps a change log: every write or delete of a keyring option, by any code path (the form, cron, REST, CLI, anything else), records the row, set or clear, the value's last 4 characters and the source, capped at 50 lines. Switching a row to or from the site secret logs `switch_site` or `switch_saved`. Every option write in WordPress passes the hook, so it returns on one isset() against a row map built once per request, and the log's own write never logs itself. Read through `keyring-status` (`changes`), which stays local-only on the remote door; never a value.


