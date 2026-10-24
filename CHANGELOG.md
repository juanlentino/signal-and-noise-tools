# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed (discovery on core, 2026-10-24)
- **Core prints the robots meta again.** The plugin removed `wp_robots()` and printed its own tag; it now hands the same directives to core through one `wp_robots` filter (`sn_seo_wp_robots()`), so core's own noindex runs again where the plugin had silenced it: a private site (`blog_public`), core search results, `?replytocom` and unapproved-comment previews. `sn_seo_robots_directives` stays the seam (the theme's notes-search noindex rides it). The tag now reads in core's format (`max-image-preview:large, max-snippet:-1, max-video-preview:-1`); the directive set is unchanged on every public page kind (home, a note, a page, tag archives, `/notes/` and its pages, `/notes/tags/`, the notes search, 404, MailPoet).
- **Core prints the canonical on singular views.** `rel_canonical()` is no longer removed: the per-post `_sn_canonical_url` override, MailPoet's no-canonical and the paged `/notes/` self-canonical go through `get_canonical_url` (`sn_seo_canonical_url()`), and core adds what the plugin lacked: page 2 of a note split with `<!--nextpage-->` canonicalizes to its own `/2/` URL, not to page 1. The plugin still prints it where core prints none (the posts page, tag archives, `/notes/tags/`). One canonical per page kind, pinned with core's own printers in `tests/seo-head-on-core.php`.
- **A feed's conditional GET is core's alone.** The plugin sent a second Last-Modified and ETag on top of core's `WP::send_headers()` and answered 304 to a mismatched ETag with a fresh If-Modified-Since (RFC 9110 says the ETag wins). Its validator is deleted; the last render change reaches core's through `get_lastpostmodified` (`sn_seo_feed_lastpostmodified()`), so a deploy still moves the feed validator. `get_feed_build_date` still dates `<lastBuildDate>`.
- **A feed 304 is counted.** Core's 304 exits before `template_redirect`, so the RSS tracker now counts on `wp_headers`, the last hook before that exit: a 200 and a 304 are each counted once. The feed `X-Robots-Tag` moved to the same filter, so a 304 carries it too. `tests/feed-conditional-get.php` runs core's real `send_headers()`.
- Dead The SEO Framework checks are gone from the touched files (TSF was removed 2026-05-17). `tools/fetch-wp-test-core.sh` also fetches `robots-template.php`, `link-template.php`, `class-wp.php` and `post.php` for the two suites.

## [2026.10.63] - 2026-10-24 — fixes from the core comparison

### Changed
- **The override count, list and confirm copy match what the clear removes.** Theme #604 scopes the template-override clear to the active theme's `wp_template` and `wp_template_part` rows, in every status core renders (auto-draft included), and keeps navigation posts (menus). The plugin still counted and listed every theme's rows plus menus, so the dashboard would keep reporting overrides after a clear and the list ability would disagree with the clear. One query, `snt_template_override_query_args()`, now feeds the dashboard count, the diagnostics list and `list-template-overrides`; the confirm dialog, command palette, admin bar and ability descriptions say menus are kept. Ships with the theme release carrying #604.

### Fixed (the core comparison, 2026-10-24)
- **The weekly narration digest gets its 120 seconds.** Core's AI client (7.0) passes an explicit 30 s timeout on every request, which beats `http_request_timeout`, so the raise did nothing and the digest kept dying at 30 s: no run is recorded and the remote narration is empty. The raise now goes through core's own `wp_ai_client_default_request_timeout`, scoped to the one call as before.
- **Logged-in visitors are not prerendered.** Core passes a null speculation configuration for a logged-in visitor (and a site without pretty permalinks); the plugin turned that back into prerender/moderate. Core's null now stays null; the toggle and the /contact/ exclusion are unchanged.
- **A note's Open Graph `article:modified_time` reads the same clock as its JSON-LD and sitemap** (`sn_schema_date_modified()`: the provenance commit, else post_modified). post_modified alone is untouched by a scheduled publish, so live notes said modified a week before published.
- **The CSP check reads again soon after an update.** Core's import-map hash changes with every core update and an update can add an inline script; enforced, a stale hash stops every script module, and the daily read alone left that up to a day. `upgrader_process_complete` and a changed core version (a host-side update) now queue one read five minutes out.
- **The door leak check reads a password-protected post.** Core posts the password form to `wp-login.php?action=postpass`, which the door's URL filter rewrites to the hidden slug, so one published protected post would publish the door. None exist today (95 published posts and pages, none protected); the newest one is read if one ever does.

### Removed
- **`inc/robots-txt.php`.** It never ran (a physical robots.txt and the rights-signals Worker answer /robots.txt), and its default list marked GPTBot, ClaudeBot, Google-Extended, CCBot and Applebot-Extended as allowed while the live rules block all five, so deleting the physical file would have printed a contradiction. The Worker owns the AI policy and the Sitemap line.

### Fixed
- **A post save purges its Markdown twin too.** The WordPress AI plugin's Markdown Feeds experiment serves every post and page as Markdown at `?output_format=markdown`, and the edge caches that address under its query string (`cf-cache-status: HIT`), so an edit left agents reading the old text until the cache revalidated. That copy answers before the theme tags the response, so the tag purge the live site uses never reaches it: every save path (the note and wide tag purges, and the URL list for an older theme) now sends the permalink's `?output_format=markdown` by URL, from the pre-change post when leaving publish.

