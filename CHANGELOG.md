# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.57] - 2026-10-22 — Refresh in the mobile Plugins app finds new releases


- **Fixed: a refresh from the mobile app's Plugins screen finds a new release.** That Refresh clears core's update record and asks again, but it is not core's Check Again, so the plugin rebuilt the record from a GitHub tag cached for up to an hour and core then kept that answer for 12 hours. Now, whenever core deletes its `update_plugins` or `update_themes` record, the plugin's cached tag for the plugin or the theme is deleted with it, so the next check reads GitHub. The routine twice-daily check only sets those records, so it still uses the cache (`inc/wp-update-integration-offer-parts.php`, `tests/wp-update-cache-watchdog.php`).


