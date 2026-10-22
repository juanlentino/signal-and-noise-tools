# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]


- **Fixed: the morning brief reads as labeled lines, says when a trust check has a finding, and every plugin email goes out as HTML with a plain-text part.** The brief's opening paragraph is now one short line per subject (Health, Trust checks, Cron, Uptime, Versions, Search with its window, Last deploy, and Drift when present): the same sentences and numbers, none removed, as a table with row headers in the HTML part. A new Trust checks line reads the four Integrity checks (Provenance integrity, Ledger CI, Rights signals, Rights anchoring) from the stored health scan through `snt_trust_reading()`, the reading both Trust checks screens use: "all clear", or each check that is not clear by name ("Ledger CI: 1 finding"), "not measured" for a skip and "not read" when there is no scan, never "clear". It links to Integrity › Trust checks, and a trust finding now counts in the subject, so it no longer arrives under "no fault-tier issues" (the health total counts the `health` surface, and these four live on `integrity`). A watch's "Where to read it" is a link to its admin screen where one exists (index_gap: Integrity › Reports, as "Integrity › Reports: <URL>" in text); tool slugs and option names move to a small "For agents" line. The security digest, alerts, the rate-limit warning and the MCP write-door notice now send through one helper, `sn_mail_send_html()` (`inc/mail-send.php`): HTML plus the unchanged text as the alternative, the `phpmailer_init` hook removed in a `finally`, with HTML read from the same text (labeled rows, headings, lists, links named by their label). The alert's Hacker News thread and dashboard URLs sit on their own labeled lines. Recipients, triggers and frequency are unchanged (`inc/morning-brief*.php`, `inc/mail-send.php`, `inc/security-digest.php`, `inc/alerts.php`, `inc/alerts-cron.php`, `inc/api-rate-monitor.php`, `inc/mcp/mcp-rw-audit.php`, `tests/mail-send.php`, `tests/morning-brief-trust.php`, `tests/api-rate-monitor-mail.php`, `tests/lib/mail-capture-stub.php`).
## [2026.10.58] - 2026-10-22 — the morning brief reads one line per page, and every plugin email reads plainly


- **Fixed: the morning brief's indexing watch reads as one line per page, and the plugin's emails read cleanly.** The "Watch due" section pasted the index_gap watch note verbatim: one paragraph of raw Search Console URLs that ended "click Request indexing.. Read it". The brief now goes out as HTML with a plain-text alternative (core's `wp_mail()`, the text part set through `phpmailer_init` for that one send and removed after). A due index_gap watch shows the ranked indexing request list from Integrity › Reports: one line per page with its path, Google's state, days since publish and Bing's answer, and a short "Inspect" link in the HTML part or the URL on its own line in the text part. Any other watch note or pointer with a URL gets the same treatment (a footnote line in text, a link labeled by its host in HTML). Sentences now join through one helper that never doubles a stop (`inc/mail-text.php`, `inc/morning-brief-render.php`, `inc/morning-brief.php`). The index_gap watch now points agents at `signal-noise/indexing-requests{request_list}`. The other emails: the MCP write-door subject decodes the site name ("Signal &amp; Noise" no longer arrives literally) and its footer stops sending the reader to a "Tools -> MCP" toggle that does not exist; the security digest loses an em dash and spells out its units (7 days, 48 hours, hours ago); the rate-limit warning spells out 24 hours and 60 or 5,000 requests an hour; alert and digest footers name screens as "Tab › Leaf" (`inc/mcp/mcp-rw-audit.php`, `inc/security-digest.php`, `inc/api-rate-monitor.php`, `inc/alerts.php`, `tests/mail-text.php`, `tests/morning-brief.php`, `tests/security-digest.php`).


