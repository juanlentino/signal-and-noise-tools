# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **The Jev meter: the site's own priced ledger of Jev use, the way the Claude itemization is.** Every request through `sn_jev_ask()` now names its feature (`notes`, `collision`, `lane_map`, `fit`) and lands in a bucket per feature per **credit cycle** (TypeSafe's credit renews on the 17th, so the bucket is the cycle, not the calendar month; the credit amount and the cycle day are two settings beside the AI budget, defaults $5 and 17), priced from the input tokens each answer reports at the pinned $0.042 per million; output is free. A hit on the connector's one-hour cache is counted as `cached` and costs nothing, so a draft saved five times shows one paid request and four cached; a failure is counted with no tokens. Twelve cycles kept. Painted as **Jev, this cycle** on AI › Models & Budget under the Claude spend: credit, spent, remaining, days left, a bar, one row per feature. The `jev-meter` read ability (door 42) and `sn-status{jev_spend}` hand the same out (local only on the remote door until the shape settles). One-shot seed at install from the passes stored before the meter existed (notes, lane map, fit), marked seeded. Nothing projects: a figure is read or absent, and the TypeSafe console stays the bill. `tests/jev-meter.php` (20).

## [16.5.3] - 2026-09-18 — the connector carries the question

### Fixed
- **Every Jev request goes through the connector.** `sn_jev_ask()` is now a wrapper over `JevConnector\ask()`: the connector owns the transport (three attempts, `Retry-After` honoured, a one-hour cache keyed on the payload so a draft saved five times in ten minutes costs one request, per-status messages that never carry the key); this plugin owns the questions and the pinned parser (`sn_jev_parse` over `Response::to_array()`). The plugin's own `wp_remote_post` transport and endpoint constant are gone; without the connector `sn_jev_ask` answers `no-connector` and nothing is sent. A `WP_Error` maps to `{ok:false, code, error}` with the HTTP status from the connector's error data, so a refused key still stops a pass after one request. `tests/stubs/jev-connector.php` stands in for the connector in the suites.

### Docs
- **AI.md: what every model does in the ecosystem.** A root document (linked from the README's first lines and from its AI section) with the rule (a model reads, relates or judges, and suggests; a human clicks), the three kinds of model and their one job each, Jev's four readings with their rubrics, costs and pins, the site as something models read (the rights surfaces, the ledger, the doors), where each key lives, what is deliberately not built, and how the thresholds moved. README counts refreshed: 109 abilities, read door 41, write door 12, 30 health checks, 26 check modules.

