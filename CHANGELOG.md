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
- **Core-first audit, part 4: three rebuilds on core and the official plugins.**
  - **Plugin updates** are offered through core's `update_plugins_github.com` hook, the one core provides for this plugin's Update URI, instead of editing the update record directly. One difference: when WordPress.org itself cannot be reached during a check, no offer appears until the next successful check.
  - **The Insights scan** passes its model options (adaptive thinking, effort, token ceiling) through the AI Client instead of rewriting the request on the wire. The request Anthropic receives is unchanged; the budget cap is untouched.
  - **`/resume?output_format=markdown`** goes through the AI plugin's Markdown handler with the résumé as its only section. `/resume.md` and `/cv.md` are unchanged.

### Changed
- **Core-first audit, part 3: HTML and block markup are read with core's parsers, not regular expressions.** Readers move to `WP_HTML_Tag_Processor`, `WP_HTML_Processor`, `parse_blocks()` and `WP_Block_Processor`: health checks, the citation verifier, link extraction (one shared `sn_html_a_hrefs()`), the correction-notice reader (notices inside groups now count), Jev tells, the OG card dek, the world map, the em-dash and editorial scanners, and the anchor scan. Each was pinned against its old output first; the differences are regex mistakes fixed (a link inside a comment or script no longer counts; `<a title="a > b">` is now read).
- **sn-apply's write path stands on core.** Block edits locate blocks with `WP_Block_Processor` and refuse unless `parse_blocks()` agrees on the block; link-reshape finds `<a>` with the Tag Processor. Offsets only: nothing outside the edit is re-serialized.
- **Generated block markup comes from core.** The `/resume` body and the Spanish fallback wrapper are built with `get_comment_delimited_block_content()` and core's post-content render (the wrapper's attributes now come in core's order).
- **The Jev verdict fields cannot be overwritten from the editor or REST.** Both keys are dropped from REST writes before the save; the server's verdict stands.
- **Orphaned-media and missing-alt checks know about scaled uploads.** They also match the attached file's name, so a large image is no longer read as unused.
- Tests load core's real HTML API and block parser (`tools/fetch-wp-test-core.sh` fetches them); no stubs.

### Changed
- **Core-first audit, part 2: core features this plugin had switched off work again, and API detours go through core.**
  - Signed in, `/admin`, `/login` and `/dashboard` are core's shortcuts again; a visitor who is not signed in still gets the same 404.
  - Settings > Writing > Default Post Category sticks: the plugin sets it once at setup instead of rewriting it on every admin load. The permalink structure is set through `$wp_rewrite`.
  - A post split with page breaks gets the page in its title ("Title — Site — Page 2"); every other title is unchanged.
  - Site name, tagline and locale in Signal & Noise settings are an override of Settings > General: an empty value, or one equal to core's, reads core live. Nothing changes until the Identity form is saved again.
  - Analytics "today" is the site's day everywhere: "Today so far" no longer disappears in the evening, and a custom window cannot end tomorrow.
  - A note's Last-Modified and 304 answer are no longer sent to signed-in or password-protected views, where core strips them.
  - `did.json`, the key routes, webfinger and `/verify` no longer go out after core's 404 status and no-cache headers; `did.json` and `/verify` cache for five minutes.
  - The feed-open pixel is served through core's REST pipeline (same image and caching, plus core's REST headers).
  - The Cloudflare sitemap purge reads core's sitemap API for its pages, and purges the tag sitemap too.
  - Recently modified posts (`sn-posts`, `sn-scan`, Insights) are read with `WP_Query` instead of raw SQL or a whole-corpus PHP filter; an old post edited recently is no longer missed behind the 2,000 newest.
  - The theme-presets and plugin-registry health checks use core's `WP_Theme_JSON` preset list and `validate_plugin()`.
  - RSS tracker settings are registered with the Settings API (stored values unchanged).

### Changed
- **Core-first audit, part 1: what the code says about WordPress now matches WordPress 7.1.4.** A plugin-wide audit (2026-11-10) read every module against core 7.1.4, the AI plugin and mcp-adapter. This part corrects comments, docblocks and admin text that described core wrongly. Owner-visible: the Connect screen now says which abilities mcp-adapter carries (`meta.mcp.public`, or `meta.public` when that is unset) and that the AI plugin's MCP Access experiment installs the adapter; the Performance form dates native speculative loading to WordPress 6.8 (it said 7.0) and no longer claims the login URL is excluded (it deliberately is not).
- **The "reaches the outside world" hint reaches MCP clients.** 51 abilities declared it as `open_world_hint`, a key nothing reads; it is now `openWorldHint`, the key mcp-adapter maps.

## [2026.11.20] - 2026-11-10 — The official AI plugin writes the excerpt and meta description; guards for analytics worker 2.0.0

### Changed
- **The AI plugin writes the excerpt and the meta description again; this plugin attaches to it instead of replacing it.** Since June this plugin switched both official features off and ran its own two buttons, while Settings › AI went on showing them as on. The two off switches are removed. The official buttons now run with three things attached: the description is stored in this plugin's own field (`_sn_meta_description`), so the SEO module keeps printing the one description tag and the AI plugin prints none; the house voice rules are appended to the official instruction for both; and a person still applies and saves the result. This plugin's two buttons stand down while the official feature is on and the post is long enough for it (the official buttons are greyed under 250 characters), and stay otherwise: on a short post, an empty template page, with the feature switched off, or with the AI plugin absent. Not carried over: these calls are the AI plugin's, so they sit outside the monthly cap and are listed under Other AI. Their model is the one chosen in Settings › AI. New `inc/ai-official-generators.php`; tests `tests/ai-official-generators.php`, `tests/ai-ai-dedupe.php`.
- **The AI plugin's bulk "Generate Summary" action is removed from the Posts list.** It wrote model text into up to 100 selected posts with nobody reading each result, and on a published note every save is a provenance version made outside the write door. The per-post Summary button in the editor stays: there a person reads the summary before saving. A crafted request for the removed action is not handled. New `inc/ai-official-complement.php`; test `tests/ai-official-complement.php`.
- **A note advertises one Markdown copy.** Each note's head carried two `text/markdown` links to two different documents: this site's `.md` (served at the edge, with the provenance record) and the AI plugin's `?output_format=markdown`. On notes the second link is no longer printed; the Markdown feed link stays, pages are untouched, and the document itself still answers.
- Admin text: the "no token record" notice points to Tools › AI Request Logs, where AI plugin 1.5.0 put them.
- The `analytics-dual-write` tool's description says what it shows once worker 2.0.0 has stopped the legacy write: the verdict is frozen, and later days read as a mismatch that moves nothing.
- The two "analytics worker misconfigured" messages name the dataset bindings worker 2.0.0 uses (`SN_AE_PV`, `SN_AE_EVENTS`) beside the old `SN_AE`.

### Fixed
- **The analytics verdict can no longer be cleared by a failed version read or a worker rollback.** Analytics worker 2.0.0 stops writing the legacy dataset, and the plugin learns that only from the worker's version. Two gaps let the daily check compare against a dataset that receives nothing, call every day since the stop a mismatch and send every read, and the nightly rollups that replace stored days, back to it. A good frozen verdict is now final: the check returns it without comparing, whatever the version reads later; clearing `frozen` is done by hand. And a mismatch only replaces a good verdict when the edge itself answers with a version: if that read fails, the stored verdict is kept and the next run decides (it used to fall back to the last good version, which can predate the stop). Install this before worker 2.0.0 is merged. Tests: `tests/analytics-generation.php`.
- **A note's "after the first hour" figures keep counting for notes that went live before October 18.** The publish window read the whole span from one dataset, the legacy one for those notes, so the figures would have stopped growing when the legacy write stops. It is now read in two halves like the lanes table. Test: `tests/publish-window.php` (fails without the fix).

