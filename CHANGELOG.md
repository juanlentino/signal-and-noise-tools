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
- **Security › Firewall.** What Cloudflare stopped before WordPress ran, on the tab that asks what is being kept out: events by action, top rules, the log's top paths and countries, the raw-dataset note, its own Refresh. The first live read showed the WAF rule blocking the abilities route 487 times in a day, which is the witness the health check now reads; that belongs next to Login defense, not under a credentials tab. Native leaf `security-firewall.php` through the monitor's shared part; classic leaf in `inc/cloudflare-readings-admin.php`. Parity suite: 11.

### Changed
- **Edge, 7 days moved to Measurement › Analytics**, a section above the hub's two columns, next to the analytics it belongs with (the Edge view already reads the same zone): the five figures, the 5xx split, the monitor's Refresh. Painted only once the monitor has a configured record, so a site without Cloudflare keeps the hub as it was. Classic card the same, after the pipeline strip. Pinned on both leaves.
- **Connections › Cloudflare is wiring and cache.** Credentials (read-only, from the keyring), Token with the monitor's Refresh, Cache. Edge and Firewall left for the tabs above; one stored record feeds all three leaves, the daily cron and the ability unchanged.

## [15.2.2] - 2026-09-15 — the keyring refuses a token that is not a secret

### Fixed
- **The keyring refuses a shared secret that is really an issued token.** Twice on 2026-09-15 the Cloudflare API token was pasted into the site secret, the sensor's read token and the analytics override; the leaf accepted all three and the sensor said only "differ". `keyring_save` now refuses, by name, an issued token pasted into the site secret or a worker row (`keyring_issued_as_shared`: "a site secret or worker secret must be its own value; openssl rand -hex 32"), and refuses any value another row already holds (`keyring_duplicate`: one leak would open both). `site` and `clear` pass untouched. Pinned six ways; mutation red.
- **The analytics override has its own probe.** When set, S&N Analytics reads with it, and after today's token roll it held a dead token while the ledger said `no probe`. `sn_cf_monitor_verify()` and `sn_cf_api_get()` take an explicit token; the override row verifies with its own bytes and reads `refused` when they are dead.
- **The firewall window is a minute under a day, both bounds explicit.** The raw dataset's first live read answered "cannot request a time range wider than 1d, but your query time range spans 1d1s620ms": the query sent only `datetime_geq: now − 24h`, Cloudflare closed the window at its own clock a second past mine, and the Free plan's cap for `firewallEventsAdaptive` is exactly one day. `sn_cf_firewall_window()` sends `datetime_geq` and `datetime_leq` around my now, 23h59m apart; the monitor's grouped and raw queries and the event log all use it. Pinned in both suites.
- **The Cloudflare API token names its other half.** The sensor's `SN_MR_SQL_TOKEN` is a copy of it, and rolling the token left the worker on the dead one (502 "upstream") with nothing on the leaf saying where to update. The row now prints `wrangler secret put SN_MR_SQL_TOKEN` under Worker secrets like the four shared ones; five commands.

