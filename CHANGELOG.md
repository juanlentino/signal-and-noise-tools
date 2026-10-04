# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [21.6.0] - 2026-10-04 — the local doors count the protocol versions clients announce

### Added
- **The local MCP doors count which protocol version their clients announce.** Both doors serve two protocol generations, and nothing recorded which one a client uses. Each request is now counted per door and announced version for the day, with a kept stamp of when each version was last seen; the version is read from the header, else the request's `_meta`, else the handshake. `sn-site-facts` returns the reading under `tool_telemetry.protocols`. The remote worker counts the same on its status page since 1.13.0. This is the evidence for dropping the older generation in both places, planned for after 2026-10-18. Nothing changes for a client.

