# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **Measurement › Search Console: Unanswered searches.** The queries Google showed the site for at least 5 times in the synced 28-day window that earned no click, most shown first, each with its impressions, average position and the page Google showed; a query shown on the home page or an index is marked "No note answers this". Capped at 25 with "+N more; the list is capped, not complete." Arithmetic only, no model reads it. The daily sync now also reads the query x page rows (500, stored compact beside the window; a failure there keeps the window), and a new read-only ability, `signal-noise/search-unanswered`, serves the list on the read door (a sibling, not a key on search-performance, whose remote twin's contract stays as it is). Painted on the native leaf and its classic twin, with honest empty states for no synced data and an empty window.
- **AI › Agent tools: the refusals, counted.** Every write a door declined, set against the rule that declined it: one table of door, rule, 7 days and 30 days, busiest first, on both twins and as `refusals` inside sn-site-facts' `tool_telemetry`. A rule that never fired is not listed. Most counts come from the call log (refused, rejected, invalid and conflicting calls to write tools, sn-apply's gates among them). The two kinds decided before the log are now counted on their own: the write door's credential, kill switch and capability checks, and write abilities denied outside MCP (WordPress 7.1+). They live in one non-autoloaded option, a door, a rule and a count per day for 90 days, never a payload; a signed-out request is not counted. Observe only: no allow or deny decision changed.
- **Analytics › Search: index coverage history is kept, not overwritten.** Each completed weekly URL Inspection run now also appends one summary reading (inspected, indexed, not indexed, crawled but not indexed, unknown, errors, and the median days since Google last crawled) to `snt_gsc_coverage_history`, a non-autoloaded option capped at 104 readings (two years). The Index coverage panel and its native twin state what moved since the previous run and list the last eight readings; under two readings they say "History starts with the next weekly run." The `signal-noise/search-coverage` ability gains a `history` key; every existing key is unchanged.

### Changed
- **Six large modules split under the house line cap** (internal only; nothing changes on screen or in behavior). `inc/seo-schema.php`, `inc/deploy-workers.php`, `inc/analytics-admin.php`, `inc/mcp/mcp-tools.php`, `inc/rss-feed-tracker.php` and `inc/analytics-signals.php` each become a shell plus three `*-parts.php` files, a pure move: the shell's original lines plus each part minus its header rebuild the old file byte for byte, hooks keep their order, and every function is still declared once. Source-reading tests read through new by-name helpers in `tests/lib/inc-population.php`, and the two phpcs exclusions that named `inc/rss-feed-tracker.php` now name the two parts that need them.
- **Measurement › Health: the scan's other readings share one row** (owner, live 2026-10-16). Passing checks and "Also scanned, shown elsewhere" stood one full-width box each; they are both what else the scan covered, of a height, so they sit side by side, with "Could not run" leading the row when a check could not. Layout only: every check, chip and link still paints.
- **AI › Models & budget and Connections › Zenodo: the status sits beside its Setup** (live desktop audit, 2026-10-16). Each leaf opened on a thin full-width status strip and kept its Setup card at the very bottom, far from the state it sets. The status card and the Setup card now share the first row; the working content follows (on Models & budget the spend readouts, Jev and the embeddings comparison; on Zenodo the ledger at full width). `docs/kit-leaf-anatomy.md` states the rule. Layout only: every figure, field, action and open warning still paints.

### Fixed
- **Site › Performance: the outbound-call ledger names admin.php and update.php again, and the status sits beside its Setup** (live, 2026-10-16). The Screen column read "adminphp?page=mailpoet-settings" and "updatephp": the HTTP diagnosis module ran `$pagenow` through `sanitize_key()`, which drops the dot. The pagenow now keeps it (a-z, 0-9, dot, `_` and `-` only, so no slash or markup can reach the log). Rows already stored read back repaired, on the leaf and on the Site Health Info panel alike: a file part ending in "php" with no dot can only be a `*.php` basename, so the dot goes back before that "php" and nothing else is touched. The leaf now opens on one row of two, the Performance status card beside the Setup card, with the ledger and its open failed-calls warning at full width under it. Nothing removed.

## [2026.10.21] - 2026-10-16 — Last seen on Your devices moves on the owner's day

### Fixed
- **Your devices: Last seen moves on the owner's day.** It moved only when the marker was re-signed, once per UTC day, so a browser enrolled on an evening (already tomorrow in UTC) still read yesterday the next morning (live, 2026-10-16). Last seen now updates on the first wp-admin visit of each site-local day, at most one write a day; the marker's signing day is unchanged, so the Workers verify it as before.

