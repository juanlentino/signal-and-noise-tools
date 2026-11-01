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
- **The analytics fields read, on both MCP doors (remote contract 26).** Owner brief "MCP full analytics access" (2026-10-31), part 1 of 4. `signal-noise/analytics-fields` (local, as `sn-metrics{analytics_fields}`) and its remote twin `signal-noise/remote-analytics-fields` answer "what can I ask for?" before a query. They return every dimension and metric the rows read (`analytics_rows`, the remote analytics query) accepts, each with its unit, a one-line meaning and the store that answers it, plus the traffic classes, the windows, the reads that take them, and what is not measured at all: no cross-day identity, no visitor identity. They return no figures. One registry (`inc/analytics-fields.php`) feeds the read, and `tests/analytics-fields.php` pins it to what `snt_arows_validate()` and the rows schema enforce, so the two cannot drift; the classes and ranges are pinned to the real constants. The local `analytics_query` section keeps its own vocabulary. The rows read now names a stored blank country or device `(unknown)` instead of `(invalid)`. The remote twin is gated like every twin (switch, capability, its own slug) and the permission matrix covers it. The remote contract moves from 25 to 26 (RED-then-pin; fixture regenerated for the Worker, whose release follows the last part).
- **The analytics query widens, on both MCP doors (remote contract 27).** Part 2 of 4. `signal-noise/analytics-rows` and its remote twin (`sn_remote_analytics_query`) accept:
  - one to four dimensions from path, referrer, country, **region**, **city**, device, link_code, day and **hour** (day and hour in the site's time zone);
  - a custom window, **from** and **to** in site time, in place of a range;
  - **source** auto, stored or events.

  Routing (`inc/analytics-rows-events.php`):
  - The stored rollups answer what they hold as one row per day: path, link_code or day; or one of referrer, country, device, region or city, with or without day. They go back to the first stored day.
  - Anything else is read from Analytics Engine, which keeps about 90 days: two or more of those dimensions, the hour, or a filter the rollups do not carry. It uses two stitched queries in the daily rollup's live-proven shapes, weighted sums plus a page-view-only count of visitor-days. A window older than 90 days is refused, naming the first day it holds.
  - Every answer names its `source`, `from` and `to`.
  - Over the same rows and weighted sums, a four-dimension query's views add up to the single-dimension total, pinned (owner test 7b).

  Validation and safety:
  - Origin validation is the same allowlist, widened. A path with characters outside a safe set is refused before it reaches Analytics Engine, and the SQL builder refuses it again.
  - The local door's `sn-metrics{analytics_rows}` takes rows.from and rows.to in place of the top-level range.
  - The fields read lists region, city, hour, the events source and the custom window.

  Review fixes (independent review of #2334):
  - The Analytics Engine read takes the referrer the dims rollup's way (`sn_analytics_referrer_value_sql()`), so an internal click is `(internal)` there too. The `(direct)` and `(internal)` filters are predicates on the referrer kind, not `blob3` literals that never match.
  - Each half orders by its own figure before the 5,000-row cap, so a capped read keeps the top rows by views, and `pageview_visits` is a floor.
  - The hard limit in the catalog and the door verdict now read "nothing finer than an hour", as the owner brief widened it.
  - `analytics-rows-events.php` joins the AE dialect guard.

  Tests: `tests/analytics-rows-wide.php` (56), and seven older refusals of now-answered combinations became acceptance checks. Each of these mutants was seen red: the stitched halves merge, the visitor-day join, the cap flag, the referrer relabel, and the builder's referrer and time-zone guards.
- **Sessions one by one, on both MCP doors and the Sessions tab (remote contract 28).** Part 3 of 4. `signal-noise/analytics-sessions` (local, as `sn-metrics{analytics_sessions}`) and its remote twin list sessions, newest first and paged. A session is the Sessions tab's unit, a within-day visit split at the idle gap with at least one page view. Each carries:
  - start and end in site time, duration, page views, entry and exit;
  - the pages in order, each view with its own visible time and deepest scroll;
  - the source label of its first page view's referrer, with an internal click as `(internal)`;
  - city, region, country and device;
  - class, returning (a second or later session of the same visitor-day), link code, engaged and goals.

  Windows: site days, or the last N hours (1 to 72). The read keeps 90 days and refuses anything older, naming the first day it holds.

  The Sessions tab gains "Sessions, one by one", which calls the same callback for its window, so the tab and both doors list the same rows (owner test 7a, pinned).

  The rows read gains `entry`, `exit` and `returning` from `source: "sessions"`, with a `sessions` metric on every row (null from the other sources). The fields read lists them, with the sessions source and the sessions read.

  Privacy: no visitor code, IP address or user agent; paths pass the same allowlist; owner only. The remote twin is gated like every twin (owner test 7c).

  Tests: `tests/analytics-sessions-read.php` (49). Each of these mutants was seen red: returning, the timestamp strip, the time sum, a beacon filed under the wrong view, the hours filter, the 90-day floor, the remote gate, the tab dropping a row, the tab's window, path cleaning, the session count, visitor-days per group, the sessions route and its filter refusal.
- **The inventory and the gap list** (`docs/audits/2026-11-01-analytics-mcp-inventory.md`): every store and field, every number the wp-admin analytics screens show, and what each MCP door returns today. Gaps found:
  - City and region: on no MCP door, though stored in the dims table and Analytics Engine.
  - Entry and exit pages: local door only.
  - Hour in site time: on no door; only the UTC hour is stored.
  - Returning versus new: impossible across days by design; a same-day repeat is derivable.
  - Queries: no more than two dimensions; no custom from/to; nothing finer than a day.
  - Individual sessions: nowhere, and the Sessions tab shows aggregates only.
  - Live: no per-reader detail.
  - Also missing from MCP: session quality, UTM campaigns, browser, OS, protocol, TLS, time zone, network, colo, the hour heatmap, distributions and percentiles, event properties, the insights signals, drill-downs, edge dimensions and the login-defense counts.
  - Every field the brief names exists in Analytics Engine for about 90 days; the ceiling was the tooling.

## [2026.11.1] - 2026-11-01 — Coded résumé links, the Links view, and link_code on the remote door

### Added
- **Coded résumé links: `/r/CODE` serves the résumé page, and the code is read back out of the stored path.** Each person the owner contacts gets their own link, for example `/r/hera`. A code is 2 to 40 lowercase letters, digits or hyphens; there is no registry, and anything else under `/r/` is the normal 404. The link serves the résumé page itself with a 200 and no redirect (`redirect_canonical` is turned off for it), so the code survives email apps and link wrappers that drop the referrer. The page's own canonical (`/resume/`) stands; the coded copy is noindex in the robots meta and in an `X-Robots-Tag` header, which the edge's Markdown twin copies. It is postless, so it never reaches the sitemap or the llms files. Nothing new is stored and no cookie is set: the beacon already records the path, and `sn_link_code_from_path()` (new `inc/link-codes.php`) reads the code back out of it. A coded path is served content in the path rows (not `(unmatched)`) and is left out of the unmatched-paths report. The admin Live list labels a coded reader ("Resume via hera") from the same query as "Being read now"; the public list never names a code, and neither does /stats: a coded path counts in its series but never ranks in Most read and has no public label (owner, 2026-10-31: /stats is information for visitors, the backend views are the owner's). Path rows and both unmatched answers share one served-content test (`sn_path_is_served()`), so the breakdown still sums to the `(unmatched)` row. `tests/link-codes.php` (48) plus asserts in the rows, unmatched-paths, unmatched-breakdown, public-stats and live suites; thirteen mutants seen red.
- **The Links view: which coded résumé link was opened.** A new S&N Analytics tab beside Campaigns (`inc/analytics-view-links.php`, native painter `view/links`). **Links** lists each code over the chosen range from the daily rollup, which is kept forever: first seen, last seen, views, and visitor-days as Human, Suspect and Bot. **Opened, last 90 days** lists each visitor-day that opened a code, from Analytics Engine (about 90 days kept): day, city and country, device, class, views, and the other pages the same visitor-day read, joined on the visitor-day hash the analytics already keep (`inc/link-codes-read.php`, cached five minutes). Both reads are stitched across the two dataset generations, as every 90-day reader is, so opens keep showing after the legacy dataset stops. A human-class visitor-day whose observe-only bot signals, taken over all its event rows as the site-wide reading takes them, reach the threshold reads **likely automated**, so an email scanner that opens a link before its person does is not counted as that person; the site-wide figures are unchanged. Class is a column, so the class filter does not narrow the view. `tests/link-codes-read.php` (32) and `tests/analytics-view-links.php` (25); twenty mutants seen red.
- **`link_code`, a dimension of the stored analytics rows, on the local door and the remote twin (remote contract 25).** `signal-noise/analytics-rows` and `remote-analytics-rows` accept `link_code` alone or with `day`. It reads coded paths from the daily rollup (a prepared `LIKE '/r/%'`), and each value is the code itself (`/r/hera` returns `hera`, both spellings merged; a malformed one is `(invalid)`). Time sort is allowed, since a coded path is a page. Each row's output schema gains `link_code`, so the remote contract moves from 24 to 25 (RED-then-pin hash; `tests/fixtures/remote-output-schemas.json` regenerated for the Worker, whose CONTRACT_VERSION 25 ships with it). Origin validation is unchanged: the allowlist and the pair rule name `link_code`. `tests/analytics-rows-link-code.php` (14), the first test of the stored read's SQL; four mutants seen red.

