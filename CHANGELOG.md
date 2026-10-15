# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.15] - 2026-10-15 — Breeze retired with a guard, 15+ years in the seeds, four more files split

### Added
- **A Health check goes red when a second page cache comes back.** The Cloudflare edge is the only page cache (owner, 2026-10-13), and Breeze is deactivated, but Cloudways can switch it back on. A Breeze page cache serves pages without Cache-Tag, so the tag purges cannot clear them. The new `breeze_active` check (Health surface, Other checks family) reports Breeze in `active_plugins`, or `WP_CACHE` true with a `wp-content/advanced-cache.php` drop-in, and its fix hint says to deactivate Breeze. One option read and one file stat; no network. The scan now runs one more check.

### Changed
- **Internal only: the sn_scan adapters' source is split into smaller files** (house line cap). `inc/sn-scan-adapters.php` (769 lines) keeps its header, the confidence constants, the adapter registry and the scope test, then loads three parts files in the order the code always ran: `sn-scan-adapters-structure-parts.php` (block_migrations, pattern_adoption, duplicate_body, near_duplicate), `-links-media-parts.php` (link_candidates, orphan_media) and `-emdash-tags-parts.php` (emdash, tag_hygiene). Code moved unchanged, apart from one comment in the shell that said the emdash fix was "below" and now names its part; the module registers no hooks. The one suite that reads the adapters' text (`sn-scan-apply-hint-reachable`) now reads every part through `snt_test_sn_scan_adapters_src()`. Nothing changes on screen and no figure is removed.
- **Internal only: the Cloudflare purge module's source is split into smaller files** (house line cap). `inc/cloudflare-purge.php` (779 lines) keeps its header, its five constants and its four sibling requires, then loads three parts files in the order the code always ran, so both hooks keep their place: `cloudflare-purge-api-parts.php` (the token and zone readers, the URL and zone purges, the blocking post, the verified zone purge), `-post-parts.php` (the URL list a post save purges and the save hook) and `-tab-parts.php` (the probe schedule, the Cache sentence and the Cloudflare tab, one 216-line callback moved whole). Code moved unchanged. Eight suites that read the module's text now read all four files through a new `snt_test_cloudflare_purge_src()`, and the escaping lint exclusion that named the file is dropped: the two lines it covered already carry their own ignore, so neither the shell nor any part needs it. Nothing changes on screen and no figure is removed.
- **Internal only: the self-updater's source is split into smaller files** (house line cap). `inc/wp-update-integration.php` (782 lines) keeps its header and every constant, then loads three parts files in the order the code always ran, so every hook keeps its place: `wp-update-integration-fetch-parts.php` (the install-folder check and its notice, the header compat trio, the fetch failure reason, the secret redaction, the failure record and its lifetimes), `-offer-parts.php` (the latest tag, the Check Again test, the update offer filter) and `-install-parts.php` (the stale-offer guard, the folder rename, the version watchdog, the View Details filter). Code moved unchanged. Three suites that read the module's text now read all four files through a new `snt_test_wp_update_integration_src()`. Nothing changes on screen and no figure is removed.
- **Internal only: the Health contrast usage scan's source is split into smaller files** (house line cap). `inc/health-contrast-usage.php` (790 lines) keeps its header and its row cap, then loads four parts files in the order the code always ran: `health-contrast-usage-sources-parts.php` (the admin-only sheet list, the plugin sheets, the sources), `-rules-parts.php` (the at-rule spans, the print-only test, the rule parser, the color reader), `-pairings-parts.php` (the surfaces, the pairings, the color resolver, the palettes, the document background) and `-report-parts.php` (the report, one 123-line function moved whole). Code moved unchanged; the module registers no hooks, and no suite reads its text. Nothing changes on screen and no figure is removed.

### Removed
- **The Breeze excludes.** `breeze_exclude_js` and `breeze_exclude_css` only told Breeze's minifier what to skip; Breeze is deactivated, so nothing read them.
- **The Cloudways app purge.** It ran only on `breeze_clear_varnish`, which the theme stopped firing (signal-and-noise#532) and Breeze no longer fires, and Varnish is off for this app. The hook, the API call, its token cache and timeout filter, the `SN_CLOUDWAYS_APP_PURGE` switch and the ledger's gate for it (`snt_purge_wants_app_purge()`) are gone. Kept: the four credential readers and `sn_cloudways_last_purge`, which the Connections leaves still show as history. New ledger rows say "retired: Varnish is off for this app"; older rows keep the words they were written with.

### Fixed
- **Seed copy says 15+ years.** The services, split-hero and résumé seeds said "20+ years", the About seed "Twenty years in", and the résumé migration's excerpt "20+ years"; the owner's figure is 15+, which the live pages already state. A reseed would have put the wrong number back. `tests/seed-years-of-experience.php` fails on any other years figure in a seed file.
- **purge-all-caches says what it clears.** Its description listed "page files"; that leg only ever cleared Breeze's files, and Breeze is off. It now reads "transients and the Cloudflare edge".
- **"SN: Purge all caches" says what it purges:** transients and the Cloudflare edge, with Redis left alone. It said "Object cache + Breeze + Varnish + Cloudflare".
- **Copy and comments no longer describe Breeze or the Cloudways purge as running:** the Cloudways leaf's hint and retired sentence (no switch to turn it back on), the Last purge caption, the keyring's Cloudways key row, the purge-all-caches and cache-freshness ability descriptions, and comments in the purge ledger, Core status, deploy history, the résumé PDF sync and three Redis notes.

