# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [20.3.1] - 2026-10-01 — Core comes back after an install


- **Core reads ✓ again right after an install.** On this site WordPress's `update_core` check lives in the persistent object cache, and the theme's full purge (which runs after every update) calls `wp_cache_flush()`, emptying it just after WordPress refilled it; the Core row then read "update check not cached" until the next twice-daily check. The plugin now hooks `sn_after_full_cache_flush` and, when the object cache was flushed, schedules one `wp_version_check()` a minute later through cron, so the purge request never waits on wordpress.org and `snt_core_status()` stays read-only. Pinned in `tests/core-fingerprint.php` (hooked, skipped when the object cache was not flushed, scheduled once, runs the check).


