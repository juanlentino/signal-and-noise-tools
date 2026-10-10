# The edge 5xx break alert

What fires a "BREAK" mail, what it counts, and what it says. Code: `inc/alerts.php` (pure: the rule and the wording), `inc/alerts-cron.php` (the hourly read), `inc/edge-rollup.php` (the stored rows). Tests: `tests/alerts.php`, `tests/edge-5xx-read-back.php`, `tests/edge-analytics-sees-5xx.php`.

## How it runs

1. **The daily edge rollup** (`sn_edge_run_rollup`, a wp-cron `daily` event) reads the previous complete UTC day from Cloudflare's GraphQL and stores the 5xx under that day, in `wp_sn_edge_dims`:
   - `err_path`: path, count
   - `err_source`: who asked (request source) and who answered (edge status, origin status, cache), no path
   - `err_path_status`: edge status, cache, path; no asker
   - `err_path_asker` (new): who asked, edge status, origin status, cache, path, as `"<asker> <edge> <origin> <cache> <path>"`, the asker being `visitor`, `worker`, `other` or `unrecorded`.
2. **The hourly alert run** (`snt_alerts_run`) reads the last three UTC days of those rows. A path breaks when it resolves to a real page (`snt_alerts_is_real_page`: home, published content, a known archive route; scanner paths never qualify) and its count that day is at least `SNT_ALERT_BREAK_MIN` (3). Each break mails once, keyed on the path and the day the errors happened.

## What it counts (since this release)

- **Visitor errors only.** On a day with `err_path_asker` rows, the count is the errors asked by a visitor (`requestSource = eyeball`). Errors a Worker's subrequest got, and any other source, are listed in the mail as context and do not count toward the line. The line stays at 3.
- **Older days** (stored before `err_path_asker` existed) have no per-path asker. They keep the old rule, every error counts, and the mail says so ("Who asked was not stored per path for this day, so every error counted"), with the status breakdown `err_path_status` does have.

## What the mail says

```
BREAK: / answered a server error to visitors 3 times on 2026-10-08 (UTC), in the stored edge 5xx rollup. All 4 on / that day: 3 x 522 (Cloudflare, origin never answered, visitor), 1 x 503 (origin, via Worker). The alert line is 3 visitor errors; Worker and other errors are context and do not count.
```

Counts and status words only: no IPs, user agents, tokens, firewall or WAF detail.

## The 2026-10-08 finding

The mail of 2026-10-09 (~20:50 UTC) said "/" answered a server error 4 times on 2026-10-08. What could be established:

- **Site-wide that day:** 10 stored 5xx, 7 asked by visitors and 3 through a Worker.
- **The week on "/":** 7 × `522 dynamic`, 4 × `503 bypass` and 1 × `522 none`. Every 522 that week was a visitor request Cloudflare could not connect to the origin for; the 503s through a Worker were the origin answering 503, the same pattern as the week's scanner paths (`/terraform.tfstate`, `/public/admin.json`, `/api/console/api_server`).
- **The four individually** (timestamps, clients) could not be read. The live probe covers only the last 24 hours, and per-request detail for Oct 8 needs Cloudflare's dashboard or API access the agent did not have. The owner chose not to pursue it.
- **The origin was not stalling:** Cloudways CPU was 78 to 91% idle every hour of Oct 8, uptime 100% with no incidents. A few isolated 522s on an idle server point at brief connection drops between Cloudflare and the origin, not at PHP-FPM saturation.

The alert could not say any of this because `err_path_status` had no asker and `err_source` no path. `err_path_asker` closes that from the next rollup on.

## When it runs (proposal, not changed)

The rollup's `daily` event is anchored to when it was first scheduled; on 2026-10-09 it ran at 20:43 UTC, so a day's break mails about 21 hours after the day closes. Rescheduling the rollup to about 01:15 UTC (after Cloudflare's previous-day groups settle) would bring the mail to within about two hours of the day closing. Not changed without the owner's go-ahead.
