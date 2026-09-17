# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.7.1] - 2026-09-17 — the sweep button asks with an empty hand

### Fixed
- **A POST with no input now carries `{}`, so `Sweep now` on the SN Anchors widget runs again.** The shared runner (`assets/snt-ability-run.js`) dropped an empty input on POST and sent no body at all; the abilities controller validates a missing input as `null`, and any write ability typed plain `object` refused it ("input is not of type object"). One line in the runner closes the class for every caller. `anchor-sweep`, `block-migrations-scan` and `corpus-integrity-scan` also take the `[object,null]` union, the house rule the read abilities already follow, so a bodyless curl or MCP call is accepted too.
- Tests: `ability-run-client.php` D.9/D.10 pin the runner's POST body (red against the old runner); Group E's comment in `abilities-categories.php` no longer claims POST always carried a body.

