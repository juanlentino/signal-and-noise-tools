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
- **The rows read's events source no longer reports time and scroll per view where a view's beacons land in another row.** Read live on 2026-11-01, after the 2026.11.2 install:
  - by city, one Los Angeles view read 475% scroll per view, because its reader's beacons geolocated elsewhere than its page view;
  - by hour, hours 18 and 19 read 125%, because a view's beacons fell in the next hour.

  - under a referrer filter, `(direct)` took every beacon, because a beacon carries no referrer. For `/` over 30 days it held all 157 scroll events of the page's 71 views, and google.com's rows read 0.

  The events source now keeps `time_avg_per_view` and `scroll_avg_per_view` only by path, link_code and day with no referrer filter, as the stored rollups always have. On referrer, country, device, region, city and hour rows, and under a referrer filter, both are null. `sort: time` is refused wherever time would be null; before, it fell back to alphabetical order. A row of beacons only, with no page view, is dropped: it carried 0 views and no figure. Views and visitor-days are unchanged, so four dimensions still add up to one (test 7b). The sessions source files each beacon under its own view and keeps both on every row. The fields read and the rows read's description now say all of this.

  Review fix (independent review of #2338): the time sort refusal, the referrer filter, and pins on every allowlist edge.

  Tests: `tests/analytics-rows-wide.php` (68). Each of these mutants was seen red: engagement kept on every row, the zero-view row kept, the referrer clause in the fetch, and the dimension and referrer clauses of the time sort.

## [2026.11.2] - 2026-11-01 — Full analytics access on both MCP doors: the fields read, a wider query, sessions one by one, and Live readers

### Added
- **Live names each reader: place, device and entry source (part 4 of 4).** Owner brief "MCP full analytics access" (2026-10-31). The admin Right now block gains "Readers now", and `signal-noise/live-now` (and `sn-status{live}`) gains `readers`: one line per presumed-human reader of the last 5 minutes, at most 20, newest first. Each line has:
  - city, region and country, and device;
  - the dashboard source of the visit's first page view;
  - the page they are on now;
  - pages read and minutes into the visit.

  One more query per realtime refresh: human page views, scrolls and timing beacons of the last two hours, split at the Sessions tab's idle gap. The readers are held only in the realtime cache, never in the stored 24-hour slots. The public live answer is unchanged. No visitor code, IP address or user agent.

  Review fixes (independent review of #2336):
  - A visit whose first page view is an internal click shows no source, never `(internal)`.
  - Any source that is not a dashboard name or a clean hostname reads `(invalid)`, on the reader lines and on Arrived from. A referrer is visitor-written and lands in a model's context, so this follows the rows read's rule. The shared helper is `sn_analytics_live_source_label()`.
  - A read that reaches its 5,000-row cap is not shown, never a partial list.
  - Each page resolves once per refresh.
  - A coded link reads as the pages list names it, with the résumé's own title and URL.
  - The idle gap follows the sessions config.
  - The over-cap list rides once in the pages, sources and readers queries. The second copy could push a full list past the statement budget.
  - The wiring is pinned end to end: a refresh stores the readers, the plugin loads the file, and live-now declares the field. The script keeps updating the rest of the block when a cache has no readers.

  Tests: `tests/analytics-live-readers.php` (31, including the Live script under node). Each of these mutants was seen red: the active window to the second, the entry view, the internal key, the cap of 20, the row cap, the referrer relabel, the admin payload, the country check, an invented source, `(internal)` kept, invalid text passed, the memo, the transient key, the require, the schema, and the script's null guard.
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

  The Sessions tab gains "Sessions, one by one", which calls the same callback for its window, so the tab and both doors list the same rows (owner test 7a, pinned). It shows ten rows and "View all N", as the paths and dimension tables do: `snt_an_kv_table()` gains a `clamp` option (default off, byte-identical for its other adopters).

  The rows read gains `entry`, `exit` and `returning` from `source: "sessions"`, with a `sessions` metric on every row (null from the other sources). The fields read lists them, with the sessions source and the sessions read.

  Privacy: no visitor code, IP address or user agent; paths pass the same allowlist; owner only. The remote twin is gated like every twin (owner test 7c).

  Review fixes (independent review of #2335):
  - **Sessions are read whole.** The read took the window's exact instants, so a session crossing the window's edge was cut: its entry and page count came out wrong, and `returning` missed the visitor's earlier session. A visitor-day is a UTC day, so it now reads the whole UTC days the window touches and lists the sessions that start in the window.
  - **The local door reaches every argument.** `sn-metrics` declares `sessions` (`from`, `to`, `hours`, `page`, `per_page`). A new pin checks that every key the dispatch reads is declared.
  - **The panel and the values:**
    - The panel says when a window is older than the 90 days Analytics Engine keeps.
    - It notes that its days are site days, while the panels above count UTC days.
    - It shows Engaged and Goals.
    - Place and device read `(unknown)` as in the rows read; goal names are clamped to short printable text.
    - A beacon in the same second as its page view still belongs to it.
    - `page` is capped at 100,000.
  - The session query joins the AE dialect guard.

  Tests: `tests/analytics-sessions-read.php` (62). Its stub now honors the query's window, so edge cases fail as they would live. Each of these mutants was seen red: returning, the timestamp strip, the time sum, a beacon filed under the wrong view, the hours filter, the 90-day floor, the remote gate, the tab dropping a row, the tab's window, path cleaning, the session count, visitor-days per group, the sessions route and its filter refusal.
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

### Fixed
- **A cut keeps the paragraphs inside a bullet.** `tools/lib/consolidate-changelog-sections.awk` dropped every blank line in a section, so a bullet's second paragraph ran into the sub-bullet above it once rendered. A blank line before an indented line now survives; the one between bullets is still dropped. Pinned in `tests/changelog-section-consolidation.php`, red both ways.
- **Live "Arrived from" no longer counts an internal click as Direct.** `sn_analytics_live_sources_sql()` read `blob3` raw, which is `''` for no referrer and for an internal click alike. It now selects `sn_analytics_referrer_value_sql('blob3')`, so the fold drops internal clicks as its docblock always said. The stored 5-minute slots and the publish window's sources had the same raw read and take the same relabel.

