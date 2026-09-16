# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- **Fixed:** the plugin's cron events now leave with the plugin. Deactivation never unscheduled the 38 hooks the modules arm (24 recurring, 14 single), so a deactivated plugin left WP-Cron firing them into callbacks that no longer existed, the way the Plugin Handbook's cron page says not to. New `inc/cron-lifecycle.php` lists every hook and `register_deactivation_hook( __FILE__, 'sn_cron_deactivate' )` runs `wp_unschedule_hook()` on each; every module re-arms on `init`, so re-activation needs nothing. `tests/cron-lifecycle.php` derives the set of scheduled hooks from source and pins the list both ways; its first run caught two hooks the hand-written list had missed.

## [15.3.3] - 2026-09-15 — the Home details are flat columns

### Fixed
- **S&N Home: the detail panels are flat columns under an eyebrow.** Zoomed out, the owner saw what 15.3.2 had painted: three audience panels as shaded cards inside a section inside the pulse (a box in a box, next to flat tiles), in a two-column grid that left Top queries alone with an empty cell, under a section heading where its siblings use small-caps eyebrows; Operations detail with the same nested weight. Both details are now an eyebrow ("Audience detail, 7 days" / "Operations detail") over flat columns, one column per panel from the painter's count so three never orphan, a rule between columns and no card; under 900px the columns stack. Pinned (7).

