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
- **Every Jev request goes through the connector.** `sn_jev_ask()` is now a wrapper over `JevConnector\ask()`: the connector owns the transport (three attempts, `Retry-After` honoured, a one-hour cache keyed on the payload so a draft saved five times in ten minutes costs one request, per-status messages that never carry the key); this plugin owns the questions and the pinned parser (`sn_jev_parse` over `Response::to_array()`). The plugin's own `wp_remote_post` transport and endpoint constant are gone; without the connector `sn_jev_ask` answers `no-connector` and nothing is sent. A `WP_Error` maps to `{ok:false, code, error}` with the HTTP status from the connector's error data, so a refused key still stops a pass after one request. `tests/stubs/jev-connector.php` stands in for the connector in the suites.

### Docs
- **AI.md: what every model does in the ecosystem.** A root document (linked from the README's first lines and from its AI section) with the rule (a model reads, relates or judges, and suggests; a human clicks), the three kinds of model and their one job each, Jev's four readings with their rubrics, costs and pins, the site as something models read (the rights surfaces, the ledger, the doors), where each key lives, what is deliberately not built, and how the thresholds moved. README counts refreshed: 109 abilities, read door 41, write door 12, 30 health checks, 26 check modules.

## [16.5.2] - 2026-09-18 — the key is the connector's

### Fixed
- **The TypeSafe key is the connector's; the keyring row is gone.** Connector for TypeSafe Jev (a separate plugin, `juanlentino/jev-connector`) registers `typesafe` with Core's Connectors API and holds the key under Settings › Connectors, resolving env → `TYPESAFE_API_KEY` → Core's `connectors_typesafe_api_key`. `sn_jev_key()` now reads `JevConnector\Connector::get_api_key()` and nothing else; the `typesafe_api_key` keyring row and its probe leave (the connector's screen owns "is the key good"), keyring 21 → 20 rows. A one-shot migration on `plugins_loaded` moves a key this plugin stored before 16.5.2 into Core's option when the connector is active and holds none, and deletes the old option either way once the connector is present; until the connector is installed the old value is kept and Jev is simply off. Every not-ready message (check 30's skip, the fit band, `jev-notes`) says "Install Connector for TypeSafe Jev and add the key under Settings › Connectors". The transport stays this plugin's (`sn_jev_ask`, its pinned parser); the connector's own client is 0.x. Pinned.

