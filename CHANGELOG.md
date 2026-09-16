# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [15.3.4] - 2026-09-16 — the cron events leave with the plugin


- **Fixed:** the plugin's cron events now leave with the plugin. Deactivation never unscheduled the 38 hooks the modules arm (24 recurring, 14 single), so a deactivated plugin left WP-Cron firing them into callbacks that no longer existed, the way the Plugin Handbook's cron page says not to. New `inc/cron-lifecycle.php` lists every hook and `register_deactivation_hook( __FILE__, 'sn_cron_deactivate' )` runs `wp_unschedule_hook()` on each; every module re-arms on `init`, so re-activation needs nothing. `tests/cron-lifecycle.php` derives the set of scheduled hooks from source and pins the list both ways; its first run caught two hooks the hand-written list had missed.


