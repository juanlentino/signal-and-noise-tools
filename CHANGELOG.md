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

- The 50 page view cap now actually applies. Cloudflare refused the query that lists over-cap visitor-days because it grouped by `toDate(timestamp)`, a function, and Analytics Engine only accepts column names or aliases there. The day is now selected as an alias and grouped and filtered by alias, the shape verified live. The cached failed read is dropped on upgrade (the cache key changed), so the cap and the history recompute work right away. A test now fails any analytics query that puts a function in GROUP BY, HAVING or ORDER BY.
- The Recompute analytics history button works inside the Analytics window. The window used to refuse every form with "handles no action named analytics_recompute". It now replays that one action through the shared admin-post dispatcher, with the same nonce and manage options checks as the classic page, and toasts whether the run started or one was already running. Every other action is still refused.

## [19.4.0] - 2026-09-27 — one rule for who counts


- **One rule decides who counts as human.** A visitor-day with more than 50 page views now reads as automated wherever the analytics count people: overview totals, rollups, sessions, realtime, drill-downs, percentiles, events, entry pages and the north star all route through the same condition in `inc/analytics-human-rule.php`. One human-classed visitor-day had made 258 of 497 human page views in 28 days; the next highest made 20. The Overview says what human means, how many visitor-days the cap removed, and says so plainly when the cap list could not be read. It also shows engaged visitor-days (the north star's own read floor, 50% scroll or 30 s on a page), counted nightly into the session rollup from tonight on.
- The site owner's own devices now stay out of analytics even when logged out. Logging in as an excluded role marks that browser with a `sn_owner=1` cookie for 400 days, and logging out does not clear it. Existing sessions get it on their next page load. The Exclude my own visits card shows whether this device is excluded and offers a Count this device again link (nonce protected). The theme's beacon reads the cookie.
- **Recompute analytics history.** A button under the human-rule note on the Analytics Overview re-rolls the last 90 days of every stored rollup and the session rollup (engaged visitor-days included) under the current rule, so days before the rule, like 2026-09-22, stop carrying the old counts. It runs in the background a week at a time, oldest first, through the same code the nightly job uses, and shows how far it got. If a read fails, the over-cap list cannot be read, or a result comes back cut off at Analytics Engine's row limit, it stops, writes nothing for that batch, and says why; clicking again starts over, and every write replaces a day rather than adding to it, so re-running is safe. Days older than Analytics Engine's roughly 92-day retention cannot be recomputed.
- **Machine readers carry Cloudflare's verified-bot category.** Rights signals 1.27.0 records the `x-sn-verified-bot` header (set by a zone Transform Rule, so a client cannot forge it) and its aggregate read returns `verified_bot`; the row normaliser now passes that label through. Empty means not verified, or a row from before 1.27.0.

### Added
- **Analytics ingest check in Site Health.** If no human pageview has been recorded for 24 hours, the new check flags it and says whether other traffic kept arriving (the collector runs but records no people) or nothing did (the collector stopped writing). A dead collector no longer reads as a quiet day. A failed or unconfigured query reports "could not check", never zero.

### Fixed
- **The edge-workers check now reads the login guard's enforcement fields.** It flags the killswitch left off (`enforcement: "off"`), any `degradedList`, `degradedList6` or `degradedMeta` flag, the worker's own `stale6` verdict when the age math cannot see it, and a lost Durable Object binding (`config.rate_limit_global` or `rate_limit_escalates` false). Each is one plain sentence, and a worker that omits a field raises nothing.
- **The north star docblock names the right rotation clock.** The visitor hash rotates at midnight America/New_York (the worker's `SN_ROTATE_TZ`), not UTC. The counts were already right: the north star buckets rolling 7-day weeks from now, and each hash is one visitor-day whatever the query window's zone, so only the comment changed.
- **The remote contract comment counts its twins.** It said 8 remote twin abilities; there are 15.

