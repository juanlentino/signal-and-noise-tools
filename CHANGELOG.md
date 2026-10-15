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
- **Spanish versions of the storefront pages: `/inicio/`, `/sobre-mi/`, `/musica/`, `/servicios/`.** Built into the plugin, with no translation plugin. A Spanish page is an ordinary Page whose one new meta row, `_sn_en_counterpart`, names its English page. The block editor sets it with a **Spanish version of** picker in the Signal & Noise panel. From that row the page gets:
  - `<html lang="es-AR">` (the copy is Argentine Spanish, voseo);
  - reciprocal hreflang links (`en`, `es`, and `x-default` pointing to English) on both pages of the pair;
  - `og:locale` `es_AR` and WebPage `inLanguage` `es-AR`;
  - its English page's template (`page_template_hierarchy`);
  - the English page's slug gates through `sn_lang_is_page()` (the music schema, the services schema, the ProfilePage type);
  - the English body, marked `lang="en"`, while its own body is empty;
  - the English meta description while its own is empty.

  English URLs did not change, and there is no `/es/` prefix. The four pages are seeded once as **drafts** with empty bodies (`sn_spanish_pages_seeded_v1`), skipping any slug already taken by a page or a redirect. A draft shows no toggle and no hreflang, so nothing is public until the owner publishes each page with its Spanish copy. Published Spanish pages are in the core sitemap and purge with every Page save. The cookieless analytics record `/musica` as its own path. How it works, and how to add a page: `docs/BILINGUAL.md`. The theme's EN / ES toggle ships in signal-and-noise alongside this. Pinned in `tests/bilingual.php`.
- **Release notes on /music (Connections › Discography › Release notes).** This is a short line under a release's roles, in English and Spanish, for what Muso.AI cannot say. The Richter releases get "Stage manager (listed on Muso.AI as Manager)." once, after the store has synced; the Spanish line is left for the owner. The notes are kept in their own option, `sn_discography_notes`, keyed by release id, because the daily sync rewrites the store. They are merged at the `sn_discography_entries` filter. Saving purges the page cache. The classic leaf and the dashboard twin both have the box, and each box saves only its own fields. Pinned in `tests/discography-notes.php`.
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

