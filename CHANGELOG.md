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
- **Core-first audit follow-ups (five small fixes).** (1) A scheduled fragment's start and end cron events now carry their own args, `array( row_id, 'start' )` and `array( row_id, 'end' )`, so core's `wp_schedule_single_event()` no longer rejects the end as a duplicate of the start when the two are within 10 minutes; events armed before this release (`array( row_id )`) still fire, are cleared on every re-save or delete, and count as armed in the reconcile. (2) The webhook dispatch, the family-drift fetch and the worker-version probe now also pass `reject_unsafe_urls`, so core validates the URL at send time beside the plugin's SSRF guard, which stays. (3) A sitemap entry whose post has no real date now emits no `lastmod` instead of keeping core's. (4) The generic `content` ability category is registered at priority 20 as a fallback, so the official AI plugin's or core's wording wins. (5) The inert The SEO Framework OG pool filter is removed; nothing read it. Tests: `tests/schedule-sync.php`, `tests/schedule-fire.php`, `tests/webhooks.php`, `tests/family-drift.php`, `tests/worker-version.php`, `tests/sitemap.php`, `tests/abilities-categories.php`.

## [2026.11.21] - 2026-11-10 — WordPress core first: the plugin builds on core and the official plugins

### Fixed
- **A fingerprint block replacement changes only that block.** The block-fingerprint engine (sn-apply surfaces, block migrations, pattern adoption) re-serialized the whole post with `serialize_blocks()` after swapping the matched block, so bytes elsewhere in a published note could change and mint a provenance version for nothing; the dry-run preview did the same. Both now splice the one block's byte span (`snt_sn_apply_fp_splice()` in `inc/sn-apply/block-delimiters.php`), after checking that core's block parser and its delimiter reader agree on it. A post where they disagree is refused with 422 `snt_sn_apply_block_path_unresolvable` instead of being rewritten. Test: `tests/sn-apply-fingerprint-splice.php`.

### Changed
- **The focus keyword reaches the AI plugin's meta description again.** Since 2026.11.20 the AI plugin writes the description, and its prompt did not carry the post's focus keyword, so the field's promise ("requires it verbatim") was not kept. The saved `_sn_focus_keyword` is now appended to the official prompt as the line the house rules already name (`inc/ai-official-generators.php`, hooks `wp_before_execute_ability` and `wpai_meta_description_prompt`). A post with no keyword, or a run on pasted text with no post, changes nothing. The field's help text says "saved keyword": a keyword typed and not yet saved is not read. Test: `tests/ai-official-generators.php`.
- **Core-first audit, part 4: three rebuilds on core and the official plugins.**
  - **Plugin updates** are offered through core's `update_plugins_github.com` hook, the one core provides for this plugin's Update URI, instead of editing the update record directly. One difference: when WordPress.org itself cannot be reached during a check, no offer appears until the next successful check.
  - **The Insights scan** passes its model options (adaptive thinking, effort, token ceiling) through the AI Client instead of rewriting the request on the wire. The request Anthropic receives is unchanged; the budget cap is untouched.
  - **`/resume?output_format=markdown`** goes through the AI plugin's Markdown handler with the résumé as its only section. `/resume.md` and `/cv.md` are unchanged.
- **Core-first audit, part 3: HTML and block markup are read with core's parsers, not regular expressions.** Readers move to `WP_HTML_Tag_Processor`, `WP_HTML_Processor`, `parse_blocks()` and `WP_Block_Processor`: health checks, the citation verifier, link extraction (one shared `sn_html_a_hrefs()`), the correction-notice reader (notices inside groups now count), Jev tells, the OG card dek, the world map, the em-dash and editorial scanners, and the anchor scan. Each was pinned against its old output first; the differences are regex mistakes fixed (a link inside a comment or script no longer counts; `<a title="a > b">` is now read).
- **sn-apply's write path stands on core.** Block edits locate blocks with `WP_Block_Processor` and refuse unless `parse_blocks()` agrees on the block; link-reshape finds `<a>` with the Tag Processor. Offsets only: nothing outside the edit is re-serialized.
- **Generated block markup comes from core.** The `/resume` body and the Spanish fallback wrapper are built with `get_comment_delimited_block_content()` and core's post-content render (the wrapper's attributes now come in core's order).
- **The Jev verdict fields cannot be overwritten from the editor or REST.** Both keys are dropped from REST writes before the save; the server's verdict stands.
- **Orphaned-media and missing-alt checks know about scaled uploads.** They also match the attached file's name, so a large image is no longer read as unused.
- Tests load core's real HTML API and block parser (`tools/fetch-wp-test-core.sh` fetches them); no stubs.
- **Core-first audit, part 2: core features this plugin had switched off work again, and API detours go through core.**
  - Signed in, `/admin` and `/dashboard` are core's shortcuts to `/wp-admin/` again. `/login` stays a 404 for everyone, so no cached redirect can hand the login address to a stranger. Visitors who are not signed in get the same 404s as before.
  - Settings > Writing > Default Post Category sticks: the plugin sets it once at setup instead of rewriting it on every admin load. The permalink structure is set through `$wp_rewrite`.
  - A post split with page breaks gets its page number in the title ("Page 2"); every other title is unchanged.
  - Site name, tagline and locale in Signal & Noise settings are an override of Settings > General: an empty value, or one equal to core's, reads core live. Nothing changes until the Identity form is saved again.
  - Analytics "today" is the site's day everywhere: "Today so far" no longer disappears in the evening, and a custom window cannot end tomorrow.
  - A note's Last-Modified and 304 answer are no longer sent to signed-in or password-protected views, where core strips them.
  - `did.json`, the key routes, webfinger and `/verify` no longer go out after core's 404 status and no-cache headers; `did.json` and `/verify` cache for five minutes.
  - The feed-open pixel is served through core's REST pipeline (same image and caching, plus core's REST headers).
  - The Cloudflare sitemap purge reads core's sitemap API for its pages, and purges the tag sitemap too.
  - Recently modified posts (`sn-posts`, `sn-scan`, Insights) are read with `WP_Query` instead of raw SQL or a whole-corpus PHP filter; an old post edited recently is no longer missed behind the 2,000 newest.
  - The theme-presets and plugin-registry health checks use core's `WP_Theme_JSON` preset list and `validate_plugin()`.
  - RSS tracker settings are registered with the Settings API (stored values unchanged).
- **Core-first audit, part 1: what the code says about WordPress now matches WordPress 7.1.4.** A plugin-wide audit (2026-11-10) read every module against core 7.1.4, the AI plugin and mcp-adapter. This part corrects comments, docblocks and admin text that described core wrongly. Owner-visible: the Connect screen now says which abilities mcp-adapter carries (`meta.mcp.public`, or `meta.public` when that is unset) and that the AI plugin's MCP Access experiment installs the adapter; the Performance form dates native speculative loading to WordPress 6.8 (it said 7.0) and no longer claims the login URL is excluded (it deliberately is not).
- **The "reaches the outside world" hint reaches MCP clients.** 51 abilities declared it as `open_world_hint`, a key nothing reads; it is now `openWorldHint`, the key mcp-adapter maps.

