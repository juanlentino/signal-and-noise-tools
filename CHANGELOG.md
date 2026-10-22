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
- **A scheduled job that dies says so.** A run that hit a fatal error or the time limit never reached the history callback, so it left no row at all: the weekly insights scan went seven weeks without a trace before showing up only as overdue. The start of every run now arms a shutdown check; a run that started and never finished is recorded as a failure, "Died mid-run", with PHP's last error when there is one.

### Changed
- **The degree credential sits on the profile pages only.** The Person node rides every page's structured data, so the Parchment-verified MBA (`hasCredential`) appeared on every note. It now appears on /about, /resume, /services and /uses (and their Spanish twins), the same pages typed ProfilePage; one predicate, `sn_schema_is_profile_post()`, decides both.

## [2026.10.59] - 2026-10-22 — every plugin email goes out as HTML with a plain-text part, and the brief names the trust checks



- **Fixed: the morning brief reads as labeled lines, says when a trust check has a finding, and every plugin email goes out as HTML with a plain-text part.** The brief's opening paragraph is now one short line per subject (Health, Trust checks, Cron, Uptime, Versions, Search with its window, Last deploy, and Drift when present): the same sentences and numbers, none removed, as a table with row headers in the HTML part. A new Trust checks line reads the four Integrity checks (Provenance integrity, Ledger CI, Rights signals, Rights anchoring) from the stored health scan through `snt_trust_reading()`, the reading both Trust checks screens use: "all clear", or each check that is not clear by name ("Ledger CI: 1 finding"), "not measured" for a skip and "not read" when there is no scan, never "clear". It links to Integrity › Trust checks, and a trust finding now counts in the subject, so it no longer arrives under "no fault-tier issues" (the health total counts the `health` surface, and these four live on `integrity`). A watch's "Where to read it" is a link to its admin screen where one exists (index_gap: Integrity › Reports, as "Integrity › Reports: <URL>" in text); tool slugs and option names move to a small "For agents" line. The security digest, alerts, the rate-limit warning and the MCP write-door notice now send through one helper, `sn_mail_send_html()` (`inc/mail-send.php`): HTML plus the unchanged text as the alternative, the `phpmailer_init` hook removed in a `finally`, with HTML read from the same text (labeled rows, headings, lists, links named by their label). The alert's Hacker News thread and dashboard URLs sit on their own labeled lines. Recipients, triggers and frequency are unchanged (`inc/morning-brief*.php`, `inc/mail-send.php`, `inc/security-digest.php`, `inc/alerts.php`, `inc/alerts-cron.php`, `inc/api-rate-monitor.php`, `inc/mcp/mcp-rw-audit.php`, `tests/mail-send.php`, `tests/morning-brief-trust.php`, `tests/api-rate-monitor-mail.php`, `tests/lib/mail-capture-stub.php`).


