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
- **The AI plugin experiments that can change a signed note are locked off in code.** Editorial Updates, Editorial Notes, WebMCP and Text to Speech can edit a note outside sn-apply, so its gates and change_class never see it and provenance mints an unlabeled version. A Settings checkbox was all that kept them off. They now use the same `wpai_feature_{id}_enabled` filter as the two duplicates, ids verified at AI plugin 1.5.0. The plugin's other features and its connector access are unchanged. `inc/ai-ai-dedupe.php`. Test: `tests/ai-ai-dedupe.php`.
- **The MCP adapter watch says what it waits on.** MCP Adapter 0.7.1 is on WordPress.org, so the port waits on the owner turning MCP Access on, not on a release. The note says so, and the watch stays quiet rather than ripe until that call is made. `inc/watches.php`.
- **The test sweep is ready for WordPress 7.2's block parser.** From 7.2, `WP_Block_Parser` needs `WP_Block_Processor`, and `tests/resume-lane-marks.php` did not load it, so the suite crashed on Beta 4 with `Class "WP_Block_Processor" not found`. The suite now loads it, and `tools/fetch-wp-test-core.sh` fetches it. The file also exists in 7.1, so nothing changes for the current sweep. Checked on 7.1.4 and 7.2 Beta 4: 40 passed each. Test setup only: the site does not change.

## [2026.11.13] - 2026-11-06 — Each stored 5xx says when it happened

### Added
- **Each stored 5xx now says when it happened and from which network.** The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s on the home page had no time to hold up against the origin's firewall. A separate query (a refusal costs the times, never the counts) stores each error group's minute in UTC, Cloudflare colo, asker, status, network number and name, and path, as `err_when` rows. No IP address is stored. It reads back as `errors_5xx.when` on the local `cloudflare-status`, newest first up to 100 with a flag when older rows exist past that, along with the query's last outcome so an empty list is not read as no errors, and whether the day reached the limit of 100 groups. Local door only: the remote contract does not move. It starts with the next daily rollup; earlier days have no times. `inc/edge-error-when.php` (new). Test: `tests/edge-errors-when.php` (new).

