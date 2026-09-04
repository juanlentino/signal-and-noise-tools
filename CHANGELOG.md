# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- Admin form controls no longer fall below 16px on a phone. Five rules were
  specific enough to beat core's `max-width: 782px` bump, so iOS zoomed into a
  focused field and never zoomed back out. Desktop sizes are unchanged; the
  bump is restated at core's own breakpoint. (#1000)

## [13.96.1] - 2026-09-04 — one declaration, enforced the same way everywhere

### Fixed
- The MCP read door now validates arguments against the ability's declared
  input schema, as the write door and the REST run-route already did. One
  declaration was being enforced three different ways depending on the door,
  and on the read door not at all — while every read ability's docblock said
  "Validated against input_schema above". An undeclared argument is now a
  -32602 naming the key, not a silent drop. (#986)

### Changed
- `family-drift` no longer returns its whole report twice. The same record is
  written to both stored options on a successful run, so `last` and `last_ok`
  were byte-identical — including a ~100-entry `vendor_gap` map — on every call
  and every `sn-status{family_drift}`. `last_ok` now collapses to
  `{same_as_last, status, computed_at}` in that case only. It is still the full
  stale report when the last attempt failed, and still `null` when no run has
  ever succeeded. (#991)

