# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Added
- **The Person node carries the full name and the GitHub profile.** Every page's Person JSON-LD (`#/schema/Person`, `@id` unchanged) gains `alternateName` "Juan Martín Lentino" and "Juan Martin Lentino" (US records drop the accent), and `sameAs` gains `https://github.com/juanlentino`, the profile /resume links. `name`, `jobTitle` and every visible page still say what they said. alumniOf and memberOf were already there. No SSRN author page: the site links papers by DOI only, never an author page. Test: `tests/seo-schema.php` (+3).
- **Feed opens where you look.** `live-now` gains `feed_opens`: today (since 00:00 UTC, the day the store keys on), the last 7 days and which notes, labeled a floor. The SN Traffic widget and the Analytics footer pulse show "Feed: at least N opens today (UTC), M this week" beside Today so far. Same pixel count and same 7-day window as rss_stats and north-star, no new counter. Tests: `tests/feed-opens.php` (+3), `tests/analytics-live-slots.php` (the live answer plus `feed_opens`).

## [2026.11.50] - 2026-11-17 — The #65551 watch reads Core's code; a side-by-side WordPress boot for release candidates

### Added
- **A side-by-side WordPress boot for judging a core release candidate.** `tools/wp-boot/` installs the version live runs and the candidate on SQLite, with the theme from GitHub main and this plugin checkout, outbound HTTP blocked, and compares them: status per path, proof that the real theme and plugin rendered, the lines that differ once ports and version strings are folded, and the PHP log lines only one side has. A local-only sign-in (`boot-login.php`, under the rig's own constant) opens the editor. First run, 7.1.4 vs 7.2 RC1 (2026-11-17): same statuses on 12 paths, differences only in core's CSS and emoji loader, identical PHP logs, editor and the Signal & Noise inspector panel the same on both. Development tooling only; nothing here ships in the plugin's runtime. See `tools/wp-boot/README.md`.

### Changed
- **The connector key-wipe watch reads Core's code, not its version (Trac #65551).** It was set to ripen at WordPress 7.2 and say "verify the fix landed", but 7.2 RC1 still blanks a stored key when validation is not strictly true (`connectors.php`, `true !==` then `update_option( $setting_name, '' )`), so on 7.2 it would have announced a fix that is not there. It now reads the source of `_wp_connectors_rest_settings_dispatch()` and ripens only once that line is gone; an unreadable Core never ripens it. The Secrets API watch now says the API is not in 7.2 RC1. Code: `snt_watch_ripe_65551()` in `inc/watches.php`. Test: `tests/watches.php` (red with the check inverted; against a 7.2 RC1 `connectors.php` when one is on the machine).

