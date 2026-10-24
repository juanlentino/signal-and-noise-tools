# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed (content built on core, 2026-10-24)
- **A tag merge hands the reassignment to core.** `sn_tag_merge()` re-implemented what core's `wp_delete_term()` already does with `default` and `force_default`: give every post carrying the old tag the canonical, then delete the old tag. It now makes that one call per source tag. `posts_moved` is still counted from `get_objects_in_term()` before each delete, since core returns no list; the history entry and the old-slug redirect map are recorded as before.
- **A renamed or re-parented page keeps its old URL.** Core keeps a renamed post reachable but skips hierarchical types, so a page whose slug or parent changed, and every page under it, 404ed at its old path (the Search Console 404s that grew `inc/legacy-page-redirects.php`). A `post_updated` hook (`inc/page-rename-redirects.php`) now records a 301 from each retired path to the new one in the redirect store, where Site > Redirects lists it like any other entry. Published before and after only (no drafts, no unpublish, no trash); each published descendant moves at its own path; an old path another published page now holds is never recorded; a redirect sitting on a moved page's new path is removed (it would hide the page, and after a rename back it is half of a loop), owner entries included; entries aimed at an old path are repointed so every redirect stays one hop.
- **Each provenance commit records the core revision saved with it.** `sn_prov_record()` stores the newest core revision id as `core_revision_id` beside the signed payload, the way `change_class` sits there: outside the hashed bytes, so no content hash, signature, Worker body or ledger record changes (a test proves the hash and canonical bytes are identical with and without it). anchor-status in-flight rows carry it, and the note dossier's Edits table (sn-posts `include_dossier`) gains a Core revision column. The id is a pointer, not evidence: core prunes revisions, so a stored id may name one that no longer exists, and the dossier says so. Revisions off for a post means no id.

### Fixed (content built on core, 2026-10-24)
- **The legacy page map no longer says 404 slug guessing is off.** Its header said general 404 slug guessing was "deliberately disabled site-wide". Nothing hooks `do_redirect_guess_404_permalink` (neither this plugin nor the theme ever has), so core's prefix guessing is on. The behavior is unchanged; whether to keep it is an open owner question.

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

