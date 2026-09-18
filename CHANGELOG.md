# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.5.3] - 2026-09-18 — the connector carries the question

### Fixed
- **Every Jev request goes through the connector.** `sn_jev_ask()` is now a wrapper over `JevConnector\ask()`: the connector owns the transport (three attempts, `Retry-After` honoured, a one-hour cache keyed on the payload so a draft saved five times in ten minutes costs one request, per-status messages that never carry the key); this plugin owns the questions and the pinned parser (`sn_jev_parse` over `Response::to_array()`). The plugin's own `wp_remote_post` transport and endpoint constant are gone; without the connector `sn_jev_ask` answers `no-connector` and nothing is sent. A `WP_Error` maps to `{ok:false, code, error}` with the HTTP status from the connector's error data, so a refused key still stops a pass after one request. `tests/stubs/jev-connector.php` stands in for the connector in the suites.

### Docs
- **AI.md: what every model does in the ecosystem.** A root document (linked from the README's first lines and from its AI section) with the rule (a model reads, relates or judges, and suggests; a human clicks), the three kinds of model and their one job each, Jev's four readings with their rubrics, costs and pins, the site as something models read (the rights surfaces, the ledger, the doors), where each key lives, what is deliberately not built, and how the thresholds moved. README counts refreshed: 109 abilities, read door 41, write door 12, 30 health checks, 26 check modules.

