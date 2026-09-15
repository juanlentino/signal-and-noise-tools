# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.2.2] - 2026-09-15 — the keyring refuses a token that is not a secret

### Fixed
- **The keyring refuses a shared secret that is really an issued token.** Twice on 2026-09-15 the Cloudflare API token was pasted into the site secret, the sensor's read token and the analytics override; the leaf accepted all three and the sensor said only "differ". `keyring_save` now refuses, by name, an issued token pasted into the site secret or a worker row (`keyring_issued_as_shared`: "a site secret or worker secret must be its own value; openssl rand -hex 32"), and refuses any value another row already holds (`keyring_duplicate`: one leak would open both). `site` and `clear` pass untouched. Pinned six ways; mutation red.
- **The analytics override has its own probe.** When set, S&N Analytics reads with it, and after today's token roll it held a dead token while the ledger said `no probe`. `sn_cf_monitor_verify()` and `sn_cf_api_get()` take an explicit token; the override row verifies with its own bytes and reads `refused` when they are dead.
- **The firewall window is a minute under a day, both bounds explicit.** The raw dataset's first live read answered "cannot request a time range wider than 1d, but your query time range spans 1d1s620ms": the query sent only `datetime_geq: now − 24h`, Cloudflare closed the window at its own clock a second past mine, and the Free plan's cap for `firewallEventsAdaptive` is exactly one day. `sn_cf_firewall_window()` sends `datetime_geq` and `datetime_leq` around my now, 23h59m apart; the monitor's grouped and raw queries and the event log all use it. Pinned in both suites.
- **The Cloudflare API token names its other half.** The sensor's `SN_MR_SQL_TOKEN` is a copy of it, and rolling the token left the worker on the dead one (502 "upstream") with nothing on the leaf saying where to update. The row now prints `wrangler secret put SN_MR_SQL_TOKEN` under Worker secrets like the four shared ones; five commands.

