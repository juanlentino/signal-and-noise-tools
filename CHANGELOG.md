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
- **The Machine Readers tile names its failure.** It said "Sensor unreachable" for every code but `not_configured`, and on 2026-09-15 that hid an `http_502`: the sensor was up (401 from outside) and had lost its Analytics Engine read because the Cloudflare token behind its `SN_MR_SQL_TOKEN` secret lost Account › Account Analytics › Read in a token edit. `snt_mr_error_hint()` is the one map (502 → "Sensor cannot read Analytics Engine", naming the grant and the secret; 401/403 → read token refused; other 5xx; network; blocked; bad_schema; unavailable); the summary payload carries it as `hint`, the desktop tile paints it with the code, and the Machine Readers tab's pill reads the same sentence. The grant list on Connections › Cloudflare marks Account Analytics Read as measured and names the sensor and the analytics worker among what the same token feeds. Pinned: nine codes, six distinct titles; mutation red on the 502 branch.

## [15.1.0] - 2026-09-15 — the firewall event log

### Added
- **The Cloudflare firewall event log, read daily.** The raw `firewallEventsAdaptive` dataset is open to every plan and carries what the origin can never see: what Cloudflare stopped before WordPress ran, per event (`action`, `source`, `ruleId`, `description`, `clientIP`, ASN, country, path, query, user agent, ray). `inc/cloudflare-firewall-events.php` reads one page (10,000 rows) of the last 24 hours on the monitor's daily hook and on its Refresh button, stores it in one never-autoloaded option, and weighs every row by its `sampleInterval` (the dataset is adaptively sampled; a row stands for that many events under load). Two readers: the health check's WAF witness (below) and the Cloudflare leaf's top paths and countries acted on. Pinned: the query shape, weights never below 1, a full page marked truncated, the abilities matcher with five negative controls, the refresh through stubbed HTTP; mutation red on the source filter and the freshness window.
- **The abilities WAF witness reads Cloudflare's own log first.** Since 14.0.4 the "Block Basic-auth on abilities API" check could only pass through a Better Stack monitor sending the header from outside (#1245), because a probe from the origin cannot test the perimeter. A custom-rule block (`source: firewallCustom`, the rule's name in `description`) on an abilities path in the last day IS the rule firing, measured from Cloudflare's side, so on such a day the check passes from the log and Better Stack is not asked. An empty, stale, managed-rule or other-path log proves nothing and the outside witness decides as before. Pinned both ways in `tests/health-checks-cf-headers.php` (74).

### Changed
- **The Cloudflare leaf, rearranged by the question a reader brings.** Left: Credentials (with the grant list) and, new, the token's health as its own facts section (status · kind, expiry, when verified) next to the token it describes. Right: Cache (one facts list for auto-purge, last purge, the Cloudways leg and the probe tally; the purge card; the probes fold, which sat orphaned in the other column; the `docs/CACHING.md` note as this section's hint instead of the leaf's opener), Edge, 7 days (the five stats, the 5xx split) and Firewall, 24 hours (by action, top rules, top paths, top countries), each its own section. One Refresh footer under Firewall says when the readings were taken and what Refresh reads. Three stacked notices and a Monitor nested inside Cache status are gone. Same fields, same three actions; the leaf suite re-pinned (51).
- **One purge mechanism behind every door.** The Cloudflare leaf's "Purge Everything Now" purged Cloudflare alone, and the edge refilled from the stale copy Varnish still held; the other four doors (Dashboard › Maintenance, the Quick Actions widget, the command palette, the MCP ability) ran the full chain. `cf_purge_now` now runs that same chain (object cache, Breeze, Varnish, then Cloudflare, verified) and the card, on both leaves, says so. Flash: "All caches purged."

