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

- The 90-day analytics history recompute no longer takes about 14 hours. The site's cron now fires only every 5 minutes, and each firing ran a single unit of the 168. A firing now runs units back to back for up to about 40 seconds (it only starts a unit that fits, judged by how long the previous one took), then schedules one next firing. A run already in progress continues from where it stopped. Progress, the death record, strict-mode stops and the stall check work as before.
- Feed opens and feed subscribers can no longer be flooded into a huge stored option. Anyone can call the open pixel with a made-up user agent, and each distinct one was kept up to 500 times per note per day, so spraying every note could grow the option to tens of megabytes that every pixel request then reads and rewrites. Opens now keep at most 1,000 per day across all notes, and subscriber keys at most 200 per bucket per day, so 90 days stay a few megabytes.
- Analytics date ranges now end on the site's own day, the same day the daily table is stored by. They used the UTC day, which runs ahead from 8 pm to midnight New York time, so an evening "last 7 days" silently lost its oldest day. A test pins the same seven days at 11:30 pm and at noon. (#1961)
- Feed subscribers now count one aggregator once across its versions. A name like "BazQux/2.4" inside a browser-style user agent kept its version, so two releases of the same reader on one day were added together instead of taking the higher count. (#1961)
- The OpenStation analytics window test now checks that the Resume recompute button's resume choice reaches the handler, so a replay that dropped it (and restarted from day one) would fail. (#1961)
- The owner exclusion notes no longer say cached pages leak the owner's visits. The sn_owner cookie checked in the browser covers cached pages. (#1961)

## [19.6.0] - 2026-09-27 — the class is decided when read


- Feed reach now counts subscribers honestly. Feed aggregators such as Feedly, NewsBlur, Feedbin, Inoreader and The Old Reader put their subscriber count in the request they send for the feed, so one Feedly fetch can stand for many readers but used to count as one. The RSS tracker now keeps, per day, the highest count each aggregator reported for each feed, plus one for every direct reader, and reads the busiest day of the last 7 as the figure, labelled a ceiling. Only the parsed name, feed id and count are stored, never the full user agent. A claim above 1,000,000 is capped and flagged. Monitoring > RSS shows feed reach as three separate numbers that are never added together: Subscribers (reported by readers, a ceiling), Opens (at least; many readers block images), and Clicks (visits from feed links). The north star gains a `feed_subscribers` input and its value is unchanged; `rss_readers` stays and means distinct fetchers. The RSS stats tool gains a `subscribers` block, which moves the remote contract to version 12; the remote worker's contract version must move to 12 in its next deploy.
- Analytics now decides whether a visit is human or suspect when it reads the data, not when the visit was recorded. The worker's network lists (data centres, hosting and proxy networks, and the iCloud Private Relay exception for Safari) are mirrored in the plugin and turned into the query, so a classifier change now applies to all retained history (about 92 days), not just to visits after it. A stored "bot" is still final, since it comes only from the worker's user-agent list. Measured live: 245 human page views over 28 days and 1,152 over 90 days after the page-view cap, matching the worker's own classifier on every stored row. A test pins the mirrored lists to the worker's (sha256) and proves the term lists agree with the worker's patterns on every network seen in 90 days. The over-cap visitor-day list is now capped at 400 so every statement stays under Analytics Engine's 10,000-character limit, and a statement over that limit is refused before it is sent, logged with the reason, and shown as unavailable instead of a partial number. After this ships, press "Recompute analytics history" once so the stored rollups are rebuilt under the new rule.
- The cron health reading now says plainly what its old `cron_disabled_constant` field meant. Two new fields sit beside it: `disable_wp_cron` (is the constant set, nothing more) and `cron_stalled_no_runner` (the alarm: the constant is set, nothing has fired recently, and no system cron is declared). The old field keeps its value for existing readers but is deprecated; it read false on a site with the constant set and a system cron running, which looked like "the constant is not set". The summary line now names all three conditions. The new fields are in the payload only, not yet in the declared schema, so the remote contract (version 11) is unchanged; declaring them is a contract bump for a later worker deploy.


