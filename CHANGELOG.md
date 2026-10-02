# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [20.4.1] - 2026-10-02 — junk pageviews leave the dashboard


- **Sources, countries, devices and five other readings stop counting junk pageviews.** The country, device and source tables totalled 291 human views over 30 days where the daily table said 235 (2026-10-02). The daily rollup drops pageviews whose path cannot be a page (admin and login URLs, /wp-content/, /wp-includes/, /wp-json/, anything with a file extension; the 17.5.1 rule after a replayed beacon planted /wp-content/uploads/sn-css/ as a pageview), but it does that in PHP, and the rollups that group by something other than the path never saw the path. Sep 3 showed it: 6 visitors in both tables, 5 views in one and 55 in the other. `sn_analytics_excluded_path_sql()` now states the same rule in the Analytics Engine query (ILIKE inside NOT (...), shapes live AE already accepts; a dot anywhere in the path stands in for the file-extension rule, since no page here has one) and the dims, UTM, hour and scroll/time band, entry and exit page, and views-today queries all carry it. The dims rollup also keys days on the site's zone, like the daily one. **After installing, press Recompute (full) in Analytics:** source, country and device numbers go DOWN to match the daily total for the ~90 days Analytics Engine still holds; older rows stay as they were. Pinned in `tests/analytics-excluded-path-sql.php` (the SQL and PHP rules agree on real and junk paths) and `tests/analytics-human-rule.php` (every pageview builder without a PHP filter carries the clause; red with it removed).


