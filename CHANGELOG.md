# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The colophon reads for someone who is not a developer.** /colophon keeps its facts and regroups them into ten rows under three H2 headings (Made with, On the page, Kept honest). Each row is the fact plus one clause on what it means, with a colon after the label and no hyphen separators. A first-person opening says the site runs on code I wrote, maintain alone, and publish on GitHub. Two rows are new. **Records** states only what the provenance code does: a SHA-256 fingerprint of each note's text, a signature from the site's Ed25519 key, a timestamp in Bitcoin through OpenTimestamps, a new signed version on each edit, and a link to Verify a Note. **Interop** now says what OpenStation is: a WordPress plugin that turns the admin into a desktop, which readers never see. "Build" is now **Code**: "vanilla ES5" was not true of every script, so it reads "plain JavaScript". "Tooling" is now **Companion plugin**, listing what the plugin actually does, and "Trust" is now **Systems**. Every existing link and its destination is kept, and the version line is unchanged. The group headings use the site's section-heading scale (`clamp(2rem, 5vw, 3.5rem)`, as on /resume), because the theme's default H2 is larger than the page title. The rows are also the single source for the theme's humans.txt, through `sn_colophon_plain_facts()`.

## [22.6.4] - 2026-10-06 — say whose 5xx; count every purge

### Fixed
- **SN Systems says whose 5xx they were.** Beside the day's count, a row reads "Your dashboard · everything else": the session ping, the abilities and desktop polls, wp-admin and the OpenStation shell against the rest. On Oct 4 about 25 of the week's 30 were the dashboard's own. The count stays whole and stays amber; the split is counted from the day's top failing paths, so it never overstates the dashboard.
- **A save's purge is counted.** Since #1850 every save purges the theme's cache tag, which empties every tagged page, and the purge ledger recorded none of them, so "purges in the last 7 days" read low. A confirmed or queued tag purge is now a ledger row (`trigger: save`, `scope: tag`); a refused one records nothing.
- **The post-save probe says it no longer runs.** "Post-purge probes: 20 retained, 4 stale" described the old per-URL purge path; since #1850 a save purges the cache tag and no probe follows. The figures stay, labeled: both Cloudflare screens open with "Old per-URL purge path, last run Oct 3", and `cache-freshness` and `purge-verification-log` carry `retired` and `last_run`.

### Documentation
- `AI.md`: corrected why Connector for TypeSafe Jev is not an `ai_provider`. Core clears an `ai_provider` key on save when no AI Client provider class is registered under that id, not because Jev is non-generative; the connector switches once the AI Client supports decision models (php-ai-client#296).

