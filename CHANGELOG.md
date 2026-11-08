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
- **The exact daily edge totals are stored again.** The daily rollup asked `httpRequests1dGroups` for 395 days at once, and this zone refuses a window wider than 52 weeks, 1 day and 1 hour (the dataset's `maxDuration`, read live 2026-11-08). The whole request failed every day, unlogged, so Traffic & edge read Edge requests 0, Bandwidth 0 B, every status code 0 and no countries. `SN_EDGE_BACKFILL_DAYS` is 364, and the first run after install back-fills the year. Test: `tests/edge-rollup.php` (the daily window fits the limit; it failed at 395).

## [2026.11.16] - 2026-11-08 — Edge readings count the site only; Threats and attack surface read again

### Fixed
- **The edge readings count the site only, not other services on the same zone.** The 5xx counts, their times, edge locations and the attack surface now read `juanlentino.com` and `www.juanlentino.com` only (`sn_edge_site_hosts()`, passed as `$hosts` to each sampled query). The zone also carries a Railway app on `app.juanlentino.com`, the analytics and MCP workers, and scanner hits on ports 2096 and 8443, and the stored path drops the host. So the "522s on the home page" of Nov 3 to 6 were scanners on `app.juanlentino.com/`: read live, the site itself had none. Owner rule 2026-11-08: anything that is not the site itself is left out. The exact daily totals (`httpRequests1dGroups`: requests, bandwidth, countries, the zone's daily 5xx line) have no host field and stay whole-zone. Days already stored keep their old rows until they leave the seven-day window. Tests: `tests/edge-analytics.php`, `tests/edge-rollup.php`, `tests/edge-errors-when.php`.
- **The attack-surface and 5xx-timing readings read again.** Both asked Cloudflare for the client's network (`clientAsn`, `clientASNDescription`), which this zone's plan refuses on `httpRequestsAdaptiveGroups`, and one refused field fails the whole document. So Login doors, Door status codes, Door methods, Attacker countries and Top probed paths were always empty, and no 5xx time was ever stored (the refusal sat in `sn_edge_errors_when_last`). Both queries drop the network, and each was run live against the zone before this change. The Attacker networks card and the login-defense glance's "top network" are removed, since they could only read empty. A stored 5xx time now reads `<minute> <asker> <status> <colo> <path>`.
- **The Threats card fills.** The rollup read threats from `firewallEventsAdaptiveGroups`, which this zone's plan does not carry, so the read was refused every day and the card in S&N Analytics › Traffic & edge always said "No threats recorded". It now reads the raw `firewallEventsAdaptive` (open to every plan, as the security monitor has since 15.0.1), the site's own hosts only, and groups the events by action, each row counting its `sampleInterval`. A skip, allow or log is not a threat and is left out: the static-asset skip rule alone was 1,869 of the site's 3,051 firewall events on 2026-11-07. Read live before shipping: 1,446 blocks that day. One page of 10,000 rows; a busier day reads a floor. Tests: `tests/edge-analytics.php`, `tests/edge-rollup.php`.
- **A direct hit on a theme's PHP file counts as a scanner probe, not a site failure.** WordPress loads theme PHP itself and never routes a request to it, so any `.php` under `/wp-content/themes/` joins the core library files in `SN_EDGE_PROBE_PATH_RE`: still shown under probes, out of the total. Four such 500s in the fortnight to 2026-11-08, all on the inactive Twenty Twenty-Five's `functions.php`. Plugin files stay counted, since some plugins serve a PHP file directly. Test: `tests/edge-error-probes.php`.

### Changed
- **Remote contract 30: the edge-errors twin says what it counts.** Wording only, no key moves. Its description says the 5xx are the site's own hosts (juanlentino.com and www) and that a `.php` under `/wp-content/themes/` is a scanner probe, like the core library files. `SN_REMOTE_CONTRACT_VERSION` is `30`, its hash is pinned, and `tests/fixtures/remote-output-schemas.json` is regenerated for the Worker, whose own contract 30 ships separately, after this release is installed.

