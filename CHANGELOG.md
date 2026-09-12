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
- **Edge-workers health: a null denylist count reads as UNKNOWN, not EMPTY** (#1237). The login-guard worker answers `denylistCount: null` when its list meta is unreadable while a warm isolate may still be enforcing; the finding now says so and names `enforcedCount`. Only a measured 0 is EMPTY.
- **Bridge route: a rejected argument value answers 400** (#1238). Core's `ability_invalid_input` carries no status, so the REST layer defaulted to 500 and the remote MCP worker reported an origin outage for a bad `days`; the route stamps 400 on that code. The worker side (1.5.0) classifies both.
- **Analytics abilities refuse an unknown `range`** (#1239) — `range: 60` used to resolve silently to 7 days; `get-analytics-summary` and `get-analytics-events` now answer `ability_invalid_input` (400). The admin URL parameter keeps its 7-day fallback.

## [14.0.1] - 2026-09-12 — the bug sweep

### Fixed
- **Bug sweep: 56 confirmed defects across the plugin, each with a pin (#1177–#1230).** Ten read-only reviewers covered all 122k lines; every finding was verified against a concrete input before it was filed, and every fix went in red → green. The ones a reader or the owner would have noticed:
  - **Content:** seven writers handed `wp_update_post()` unslashed content, stripping every backslash in block attributes on Apply (#1177 — the census in `tests/write-door-slash-contract.php` now covers all of `inc/`); "unused" tags were decided on the publish-only count, so a tag held only by scheduled notes could be pruned (#1178); batch schedule's bad-date guard was dead and drafts lost their chosen date (#1179); unpublishing or trashing a live note never purged the edge (#1180) and told IndexNow about `?p=ID` instead of the indexed URL (#1181).
  - **Health checks:** the orphaned-media scan self-matched and could never find an orphan (#1182); the machine-reader liveness check went quiet exactly when an outage was oldest (#1183); false positives in color drift, contrast usage, the plugin-registry probe, alt quality and link probes (#1184–#1188); a degraded worker body was cached for 6h (#1189); the morning brief counted advisories on the wrong surface, always zero (#1190).
  - **Abilities and watches:** the provenance-integrity watch could never ripen — an integer read with `strtotime()` (#1191); ability latency was never measured on 7.1 because core passes a sentinel, not `null` (#1192); `sn-metrics` ignored `range` for three sections (#1193); `merge-tags` reported success after dropping an unknown slug (#1194); `run-cron-event`, `modified_since`, the em-dash adapter and the roadmap merge each had one wrong comparison (#1195–#1198).
  - **Analytics:** path trajectories queried the unslashed spelling against verbatim rows (#1199); publish day, entry pages, the digest window and the events rollup bucketed by UTC beside site-local totals (#1200, #1201); the Movers tile showed live pages at 0 (#1202); the edge rollup's 24h window overlapped and double-counted (#1203); login-defense cached a failed read as zeros (#1204); `σ`/`×` printed as escapes (#1205); a stale cache key on settings save (#1206); byte truncation into utf8mb4 columns dropped whole write chunks silently (#1207); per-day decimals, the engagement delta and three "of N" totals (#1208); rollup freshness without an object cache (#1209).
  - **MCP doors:** route matching now follows core's case-insensitive routing and the run route applies the door's purge-argument rule; the rw rate limiter counted per 60s-of-silence instead of per minute (#1210); duplicate audit rows on 7.1 (#1211); a schema-refused call was not audited (#1212); a failed bridge execute counted as a use (#1213); registry lookups, ceiling-vs-switch order, notify stamp (#1214); the three search twins registered a REST run route the others do not (#1215).
  - **Admin and front end:** the OpenStation Date column sorted its label as text and was a no-op on Safari for non-ISO stamps (#1216); two S&N Home tiles linked to the wrong door (#1217); scheduled lists labeled every post "Page" (#1218); `/verify` paste mode dropped the subject kind and two concurrent runs shared the DOM (#1219); WebFinger rejected percent-encoded resources (#1220); OG cards carried a spurious ellipsis (#1221); overdue cron read "in N mins" (#1222); the GitHub Actions ETag path was unreachable (#1223); crawlers got 304 after SEO meta edits (#1224); the audit-log unique-IP set never reset (#1225); a citations insert race (#1226); byte-based string functions on prose across ten surfaces (#1227); twenty small admin inaccuracies (#1228); a front-end fatal when the login-slug module is bypassed (#1229); the agent output budget raised `max_tokens` with thinking unbounded (#1230).
  - Left for a follow-up with Analytics Engine access: the scroll-depth 0–25% band (#1230, second item).
- **Two guard suites walked zero files from inside a linked worktree.** `tests/admin-class-orphans.php` and `tests/direct-access-guard-window.php` skip other sessions' worktrees by refusing any path containing `/.claude/` (#1055) — tested on the absolute path, so a run from `.claude/worktrees/<name>/` excluded its own root and scanned nothing. The orphan suite then reported all 91 baseline classes "now styled" and eight drift regressions; the guard-window suite held 0 files to the window. Both now test the path relative to the scan root, which keeps nested worktrees skipped and makes the root's own location irrelevant. The vacuity assertions caught it, as designed.

