# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.76] - 2026-10-26 — the read door can hold its own credential

### Security
- **The read door's Application Password can be limited to the read door.** Tools > MCP and AI > MCP Clients gain an optional read-door binding beside the write door's. Binding a password limits THAT password to `/signal-noise/v1/mcp` and the read door's tools: everywhere else (the write door, `/wp/v2/*`, other abilities) it gets 403 `sn_mcp_read_credential_out_of_scope`, recorded in the rw audit log as `read-route:` (at most twenty a day: over the log’s 30-day window (31 UTC days at most) that fills at most 620 of its 1,000 rows, so a flood from the lower-trust password cannot roll the write door's rows out), the same scope the write door's password has had since 2026.10.75. The binding does not make the read door exclusive, and unbound the read door works exactly as before, so nothing changes until a password is bound. One password can never be bound to both doors (each door's scope refuses the other door's routes): both setters and both handlers refuse it by name, and the read list leaves the write door's password out. (2026-10-26 MCP doors audit, N0's read half.) Pinned in `tests/mcp-read-binding.php` and `tests/mcp-rw-credential-scope.php`.

