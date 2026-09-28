# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- Verify all no longer reads green over an unset worker row: a probed row whose other half lives on a worker (Analytics server token, Machine Readers read token, Cloudflare API token) is now refused, "Not set here (plugin side)", so the banner says a credential was refused and the row names the side. Seen 2026-09-28: the Analytics server token read "unset" under "Every credential with a probe was accepted."
- The keyring keeps a change log: every write or delete of a keyring option, by any code path (the form, cron, REST, CLI, anything else), records the row, set or clear, the value's last 4 characters and the source, capped at 50 lines. Switching a row to or from the site secret logs `switch_site` or `switch_saved`. Every option write in WordPress passes the hook, so it returns on one isset() against a row map built once per request, and the log's own write never logs itself. Read through `keyring-status` (`changes`), which stays local-only on the remote door; never a value.

## [19.6.1] - 2026-09-28 — the cap catches the midnight split


- A correction to 19.6.0's notes: the read-time rule matches the worker's classifier on every stored row except server-side feed events (the RSS tracker's server-authenticated hits from the WordPress host). Those read as suspect on purpose: they are feed fetches the RSS stats already count, not people on the site.

### Fixed
- The SN Site Views desktop card now shows the same engaged-readers number as the north star. The card cached its whole payload for 15 minutes, north star included, so it could keep a reading older than the one the north-star ability returned (4 on the card, 3 in the ability after the one-human rule landed). The north star is now added to every response from its own hourly reading, outside the card's cache.
- The SN Site Views card no longer says the direction twice. The arrow carries it and the number is plain: "4 ▲ 3", "37% ▼ 10 pts", "▼ 16", "▼ 41.3%". Green or red now appears only for a change worth a look (at least 5 and at least 20 percent, or at least 5 points); smaller moves use the muted text colour.
- The 90-day analytics history recompute no longer takes about 14 hours. The site's cron now fires only every 5 minutes, and each firing ran a single unit of the 168. A firing now runs units back to back for up to about 40 seconds (it only starts a unit that fits, judged by how long the previous one took), then schedules one next firing. A run already in progress continues from where it stopped. Progress, the death record, strict-mode stops and the stall check work as before.
- Feed opens and feed subscribers can no longer be flooded into a huge stored option. Anyone can call the open pixel with a made-up user agent, and each distinct one was kept up to 500 times per note per day, so spraying every note could grow the option to tens of megabytes that every pixel request then reads and rewrites. Opens now keep at most 1,000 per day across all notes, and subscriber keys at most 200 per bucket per day, so 90 days stay a few megabytes.
- Analytics date ranges and the named periods (this week, this month, this quarter, year to date, last month, last quarter, previous year) now end on the site's own day, the same day the daily table is stored by. They used the UTC day, which runs ahead from 8 pm to midnight New York time, so an evening "last 7 days" silently lost its oldest day. A test pins the same seven days at 11:30 pm and at noon. (#1961)
- Feed subscribers now count one aggregator once across its versions. A name like "BazQux/2.4" inside a browser-style user agent kept its version, so two releases of the same reader on one day were added together instead of taking the higher count. (#1961)
- The OpenStation analytics window test now checks that the Resume recompute button's resume choice reaches the handler, so a replay that dropped it (and restarted from day one) would fail. (#1961)
- The owner exclusion notes no longer say cached pages leak the owner's visits. The sn_owner cookie checked in the browser covers cached pages. (#1961)
- The page-view cap now catches a visitor-day that crosses midnight UTC. The visitor hash rotates at New York midnight, but the over-cap list grouped views by hash and UTC date, so one visitor-day could be split in two and slip under the cap. Measured live: a 65-view visitor-day read as 18 plus 47 and was not excluded. The list now groups by the hash alone, and its cache key moves to v3 so the old list is dropped on upgrade.
- Analytics mirrors the worker's 1.21.7 classifier. The iCloud Private Relay exception now also requires the visit's stored OS to be iOS or macOS, since Private Relay exists only there, so a Safari-shaped visit from Linux or with no OS via Fastly or Akamai reads suspect. Blazing SEO, LLC (a proxy seller) and Web2Objects LLC (hosting) join the hosting list and read suspect. Because the class is decided at read time, this applies to all retained history. Measured live: human page views move from 249 to 247 over 28 days and from 1,156 to 1,152 over 90 days, all of it from the two new networks; no stored row hits the OS gap. The worst-case statement with a full 400-hash list grows by 103 characters to 9,572, still under the 10,000 limit.
- The Analytics server token row in Connections › Credentials now has a Verify probe. It reads the analytics worker's public version page, which records the result of the worker's last 15-minute refresh call to this site, and names the side to fix: not set here (plugin side), the worker holds a different value (with the command to set it there), accepted, or unknown when the reading is missing or older than 30 minutes. The secret is never sent anywhere new. From 2026-09-15 the two sides held different values for 12 days and nothing said so, because this row had no probe.
- When the server token is not set, the refresh route's 503 and the Analytics settings pill now say "The analytics server token is not set: set it in Connections › Credentials (Analytics server token)" instead of pointing at a wp-config constant. The pill resolves the token the same way the route does, so a value saved in the keyring is never reported missing. The RSS tracker already resolved through the keyring; a test now proves it.
- The over-cap visitor-day list is now capped at 300 hashes, down from 400. At 400 the longest statement measured 9,572 characters against Analytics Engine's 10,000 limit; at 300 it is 7,569, and a test keeps at least 1,000 characters of headroom.

