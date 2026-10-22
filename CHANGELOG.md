# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Fixed: the morning brief's indexing watch reads as one line per page, and the plugin's emails read cleanly.** The "Watch due" section pasted the index_gap watch note verbatim: one paragraph of raw Search Console URLs that ended "click Request indexing.. Read it". The brief now goes out as HTML with a plain-text alternative (core's `wp_mail()`, the text part set through `phpmailer_init` for that one send and removed after). A due index_gap watch shows the ranked indexing request list from Integrity › Reports: one line per page with its path, Google's state, days since publish and Bing's answer, and a short "Inspect" link in the HTML part or the URL on its own line in the text part. Any other watch note or pointer with a URL gets the same treatment (a footnote line in text, a link labeled by its host in HTML). Sentences now join through one helper that never doubles a stop (`inc/mail-text.php`, `inc/morning-brief-render.php`, `inc/morning-brief.php`). The index_gap watch now points agents at `signal-noise/indexing-requests{request_list}`. The other emails: the MCP write-door subject decodes the site name ("Signal &amp; Noise" no longer arrives literally) and its footer stops sending the reader to a "Tools -> MCP" toggle that does not exist; the security digest loses an em dash and spells out its units (7 days, 48 hours, hours ago); the rate-limit warning spells out 24 hours and 60 or 5,000 requests an hour; alert and digest footers name screens as "Tab › Leaf" (`inc/mcp/mcp-rw-audit.php`, `inc/security-digest.php`, `inc/api-rate-monitor.php`, `inc/alerts.php`, `tests/mail-text.php`, `tests/morning-brief.php`, `tests/security-digest.php`).

## [2026.10.57] - 2026-10-22 — Refresh in the mobile Plugins app finds new releases


- **Fixed: a refresh from the mobile app's Plugins screen finds a new release.** That Refresh clears core's update record and asks again, but it is not core's Check Again, so the plugin rebuilt the record from a GitHub tag cached for up to an hour and core then kept that answer for 12 hours. Now, whenever core deletes its `update_plugins` or `update_themes` record, the plugin's cached tag for the plugin or the theme is deleted with it, so the next check reads GitHub. The routine twice-daily check only sets those records, so it still uses the cache (`inc/wp-update-integration-offer-parts.php`, `tests/wp-update-cache-watchdog.php`).


