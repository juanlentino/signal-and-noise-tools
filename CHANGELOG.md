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
- **SN Provenance: each link sits in the section it opens.** "Open Provenance" and "Open Machine Readers" sat together on the action row under both sections. Now "Open Provenance" closes the Provenance section and "Open Machine Readers" closes the Machine readers section, as SN Systems' links do. "Sweep now" stays on the action row, and a link whose section did not paint falls back to that row.
- **SN Systems: "Open Health" sits in the Health section, once.** It sat on the bottom row beside "Clear DB overrides", and a Health finding added a second "Open Health" inside the section. It now always closes the Health section, beside "Open Anthropic billing" when a check is paused.

## [22.6.2] - 2026-10-05 — Systems lines link to their fix; releases declare Front-End Change

### Changed
- **A release says whether it changes the public site; an update that does not leaves the caches warm.** Owner, 2026-10-05: 50 edge purges in a week, 42 by plugin updates. `tools/cut-release.sh` takes `--front-end=yes|no` (default `yes`) and writes a `Front-End Change:` header into the plugin. The theme's update purge (theme PR) and this plugin's version-change rollover skip the purge on an explicit `no` for a plugin-only update; a theme change, any other package, and a missing header purge as before.
  The `no` holds only for a forward update from a version at or after the release's `Front-End Baseline:` (the last release that changed the public site, carried forward by every `no` cut and reset by every `yes`). An update that jumps past a public release, a rollback, or an unknown prior version purges (Codex P1: the updater installs the latest tag directly).

### Fixed
- **SN Systems: every amber line now links to where it gets fixed, and a line nothing here can fix is no longer amber.** The owner opened the card to three amber reasons and no way to act on any of them. A section that adds anything to the headline now ends with its fix link: Edge and Cache open Cloudflare (5xx by path, purge and probes), Cron opens Cron, Health opens Health, and Uptime opens Better Stack. A health check the AI provider refused for an empty credit balance reads "paused: AI credit out", counts as "1 paused", and links to Anthropic billing, not to "could not run". A purge verifying within 15 minutes of when it was sent reads "1 verifying". Neither turns the dot amber: the dot is gray, not green, when they are all that is left. A purge still "verifying" after 15 minutes is unmeasured and amber, as before. Edge 5xx stays amber, because those errors are real.

