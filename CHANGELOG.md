# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.22] - 2026-11-10 — Core-first follow-ups: scheduled boundaries, safe URLs, sitemap dates

### Fixed
- **Core-first audit follow-ups (five small fixes).** (1) A scheduled fragment's start and end cron events now carry their own args, `array( row_id, 'start' )` and `array( row_id, 'end' )`, so core's `wp_schedule_single_event()` no longer rejects the end as a duplicate of the start when the two are within 10 minutes; events armed before this release (`array( row_id )`) still fire, are cleared on every re-save or delete, and count as armed in the reconcile. (2) The webhook dispatch, the family-drift fetch and the worker-version probe now also pass `reject_unsafe_urls`, so core validates the URL at send time beside the plugin's SSRF guard, which stays. (3) A sitemap entry whose post has no real date now emits no `lastmod` instead of keeping core's. (4) The generic `content` ability category is registered at priority 20 as a fallback, so the official AI plugin's or core's wording wins. (5) The inert The SEO Framework OG pool filter is removed; nothing read it. Tests: `tests/schedule-sync.php`, `tests/schedule-fire.php`, `tests/webhooks.php`, `tests/family-drift.php`, `tests/worker-version.php`, `tests/sitemap.php`, `tests/abilities-categories.php`.

