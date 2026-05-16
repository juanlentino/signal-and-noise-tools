# Changelog

All notable changes to Signal & Noise Tools are documented here.

## [1.11.0] - 2026-05-16

### Added
- **`inc/sitemap.php` — sitemap filter for WP core's built-in `/wp-sitemap.xml`.** Hooks `wp_sitemaps_posts_query_args` to exclude two classes of posts from the sitemap:
  - Posts with `_sn_noindex = '1'` (the per-post noindex flag from v1.10.0). If a post is hidden from search engines, it shouldn't be in the sitemap either.
  - Posts with a non-empty `_sn_canonical_url` (per-post canonical override from v1.10.2). Canonical pointing elsewhere = this URL isn't the source-of-truth → exclude from our sitemap.
- Scoped to `post` + `page` post types (matches `SN_POST_SETTINGS_POST_TYPES`).

### Architectural note — dormant until Phase 13
- **Currently inactive on the live site.** The SEO Framework (TSF) is still active on juanlentino.com; TSF deregisters WP core's `/wp-sitemap.xml` route and serves its own at `/sitemap.xml` instead. Our filter targets WP core's sitemap, so it doesn't fire as long as TSF runs.
- **Activates automatically at Phase 13 cutover** (v2.0.0). When TSF is deactivated, WP core's sitemap takes over at `/wp-sitemap.xml` and our filter immediately starts honoring the per-post overrides.
- Registered unconditionally because doing so is cheap (no overhead when the hook never fires) and avoids coordination logic with TSF. The pattern "register filters that activate when their feature target becomes live" is the same approach used in the v1.5.0 login-hide module (stands down while wps-hide-login is active; activates when that plugin is removed).

### Sitemap features NOT shipped in v1.11.0
- **Custom URL routing** (intercepting `/sitemap.xml` while TSF is active) — would create two competing sitemaps. Wait for Phase 13.
- **Image sitemap extensions** — marginal SEO value; Google indexes inline `<img>` regardless.
- **Video sitemap, news sitemap** — not applicable to this site (no video catalog, not a news publisher).
- **Per-post `changefreq` / `priority`** — Google has explicitly stated these are ignored. WP core skips them.
- **Sitemap ping on update** — Google deprecated sitemap ping in 2023. WP core never implemented it. Search engines discover sitemap updates via robots.txt + crawl cadence.

### Notes
- **MINOR bump (v1.11.0).** New file + new user-visible behavior (when activated), even though the activation is currently latent. Aligns with the project's pattern of preferring MINOR for additive features and reserving PATCH for fixes / refactors.
- **Ships through the WP-UI-updates flow** introduced in v1.10.1. Push tag → `wp-admin → Updates` → "Update Now". (Or `gh workflow run deploy.yml --ref v1.11.0` for emergency.)
- **Theme parallel work** still queued: the theme repo needs the same WP-UI-updates treatment as the plugin got in v1.10.1. Will ship as `v8.5.1` in a separate task — same file changes (deploy.yml trigger + wp-update-integration.php).

## [1.10.2] - 2026-05-16

### Added
- **Three new per-post override fields** in the "Signal & Noise" meta box:
  - **Custom canonical URL** (`_sn_canonical_url`) — overrides `<link rel="canonical">` for this post. Use case: republished or syndicated content where the canonical lives at the original publisher's URL. Empty falls back to the permalink.
  - **No archive checkbox** (`_sn_noarchive`) — appends `noarchive` to the robots meta tag (tells Google etc. not to show a cached version).
  - **No image index checkbox** (`_sn_noimageindex`) — appends `noimageindex` to the robots meta tag (images on this page won't appear in Google Images).
- Three new typed accessors in `inc/post-settings.php`: `sn_post_settings_get_canonical_url()`, `sn_post_settings_get_noarchive()`, `sn_post_settings_get_noimageindex()`. All three meta keys registered via `register_post_meta()` with `show_in_rest=true` — same architectural pattern as v1.10.0's three fields. REST `/wp-json/wp/v2/posts/{id}` now exposes `meta._sn_canonical_url`, `meta._sn_noarchive`, `meta._sn_noimageindex` alongside the v1.10.0 fields.

### Changed
- **`inc/seo.php` canonical emitter** — checks `_sn_canonical_url` first for singulars before falling back to the permalink returned by `sn_seo_meta_for_current_view()`.
- **`inc/seo.php` robots emitter** refactored from a hardcoded string concatenation to a directives-array build pattern. Honors all four robots flags (`noindex` + auto `nofollow` since v1.6.0; `noarchive` + `noimageindex` added in v1.10.2). The permissive defaults (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`) are always appended regardless of per-post flags. Backward compatible: existing `_sn_noindex` behavior unchanged.
- **`inc/post-settings.php` save handler** refactored to iterate over field-key maps (boolean fields and URL fields) rather than 1:1 inline blocks per field. Same behavior; halves the LOC and makes adding more fields later trivial.

### Notes
- **TSF parity:** these are the per-post equivalents of three TSF settings (canonical URL override, noarchive directive, noimageindex directive). Combined with v1.10.0's noindex + meta description + OG image, the SN per-post meta box now covers ~80% of TSF's per-post SEO controls. Remaining TSF features queued: focus keyword analysis (complex UI, marginal value), nofollow / nosnippet standalone toggles (incremental).
- **PATCH bump within `1.10.x`.** Additive only; no schema migrations.
- **First update via WP UI flow** since v1.10.1 shipped the fix. Push the tag → check `wp-admin → Dashboard → Updates` → "Update Now" → installs from GitHub archive. (12h cache TTL on update detection — can be forced via "Check Again" button on the Updates page.)

## [1.10.1] - 2026-05-16

### Fixed
- **WP update gating — updates now go through the WordPress admin Updates page.** Previously, pushing a `vX.Y.Z` tag triggered the GHA deploy workflow which SSH'd into Cloudways and `git checkout`ed the new tag ~30s later. The `inc/wp-update-integration.php` UI was just a deploy-health indicator — it explicitly REJECTED actual "Update Now" clicks (`upgrader_pre_install` filter returned a `WP_Error`). Net effect: tag push = update lands without maintainer confirmation. After v1.10.1, tag pushes do nothing automatically; updates appear in `wp-admin → Updates` and `wp-admin → Plugins`, and the maintainer clicks "Update Now" to install.

### Changed
- **`.github/workflows/deploy.yml` trigger** changed from `on: push: tags: 'v*'` to `on: workflow_dispatch:` only. Tag pushes no longer fire the workflow. Manual emergency-hotfix deploys remain available via the GitHub Actions UI or `gh workflow run deploy.yml --ref vX.Y.Z`.
- **`inc/wp-update-integration.php`**:
  - **Removed** the `upgrader_pre_install` filter that rejected WP installer attempts with a `WP_Error` directing the maintainer to push a git tag instead.
  - **Added** `upgrader_source_selection` filter to rename the unpacked source directory from GitHub's auto-generated format (`signal-and-noise-tools-1.10.1/`) to the plugin slug (`signal-and-noise-tools/`). Without this, WP would install to the wrong directory and the plugin would deactivate on update.
  - Docstring rewritten to describe the new WP-UI-driven flow.
- The `package` URL (pointing at `https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/<tag>.zip`) was already set correctly since v1.4.0 — only the install path needed fixing.

### How updates work from v1.10.1 onward
1. Maintainer pushes tag `vX.Y.Z` to GitHub.
2. WP poll (every 12h, cached in `sn_gh_latest_plugin` transient) sees the new tag.
3. `wp-admin → Dashboard → Updates` shows "Signal & Noise Tools" with an "Update Available" badge.
4. Maintainer clicks "Update Now". WP downloads the GitHub tag archive, the source-selection filter renames the directory, WP installs over the previous version, plugin reactivates.

### Emergency hotfix path
If the WP UI install ever fails (e.g., GitHub API down, ZIP fetch blocked, file-permission issue on Cloudways), trigger the workflow manually:
```bash
gh workflow run deploy.yml --ref v1.10.1 --repo juanlentino/signal-and-noise-tools
```
This runs the same SSH + `git checkout` path the legacy auto-deploy used. Reserved for emergencies — the canonical flow is the WP UI.

### Notes
- **PATCH bump within `1.10.x`.** No plugin schema or functional change; only the release-pipeline trigger gating changed.
- **First install bootstrap:** the v1.10.1 tag itself can't be installed via WP UI because the v1.10.0 server-side has the OLD code that rejects WP installer attempts. v1.10.1 lands on Cloudways via one manual `gh workflow run` invocation. After that, v1.10.2+ install via the WP UI.
- **One-time loss of `.git` directory on first WP UI install.** WP's installer wipes the existing plugin directory before unpacking the new version, including the legacy `.git` checkout from past SSH deploys. Harmless — the SSH-based deploy path is no longer needed (still available via `workflow_dispatch` emergency fallback, which re-clones).
- **Theme repo** (`signal-and-noise`) gets the equivalent treatment in v8.5.1 (separate ship). Same one-line workflow change + same `wp-update-integration.php` fixes; the existing wp-update-integration.php in the theme mirrors this plugin's pattern.
- **Replaces the failed v1.10.1 attempt** (force-reverted) that incorrectly used a GitHub Actions environment approval gate instead of the WP admin UI flow.

## [1.10.0] - 2026-05-16

### Added
- **Per-post SEO settings UI.** New "Signal & Noise" meta box on the post + page editor (auto-converts to a sidebar panel in the block editor) exposing three overrides:
  - **Noindex toggle** — when checked, adds `noindex,nofollow` to the robots meta tag for that post. Reader has existed since v1.6.0 via `_sn_noindex` post meta; v1.10.0 adds the write path.
  - **Custom meta description** — overrides the post excerpt for `<meta name="description">`, `og:description`, `twitter:description`, AND the JSON-LD Article schema description. Empty falls back to the excerpt.
  - **Custom OG image URL** — overrides the featured image / auto-generated card / site default. Highest priority in the OG image resolution chain. Explicit beats implicit.
- **REST API exposure for all three meta keys** via `register_post_meta()` with `show_in_rest=true`. `/wp-json/wp/v2/posts/{id}` (and pages endpoint) now include `meta._sn_noindex`, `meta._sn_meta_description`, `meta._sn_og_image_url`. `auth_callback` requires `edit_posts` for writes; reads are public (these are user-facing values).
- **`sn_post_settings_get_noindex/description/og_image_url($post_id)` typed accessors** — consumers call these instead of `get_post_meta()` directly so the type contract lives in one place. `function_exists()` guards on every cross-module call so the new module can be selectively deactivated without breaking the existing readers.

### Changed
- **`inc/seo.php`** `sn_seo_meta_for_current_view()` singular branch now checks `_sn_meta_description` before falling back to `$post->post_excerpt`.
- **`inc/seo-schema.php`** Article schema `description` field follows the same fallback chain via new `sn_schema_article_description()` helper. Preserved the existing conditional-assignment pattern that OMITS the description key from JSON-LD when nothing resolves (rather than emitting an empty string) — schema validators see identical clean structure when no override or excerpt exists.
- **`inc/og-card-generator.php`** OG image filter chain checks `_sn_og_image_url` first, beating featured image / auto card / site default when set.

### Architecture
- **Hybrid PHP meta box + REST exposure** — Approach C from spec research. Zero build pipeline preserved. Same architectural pattern Yoast Free uses at scale. Future migration to a React block-editor sidebar is free thanks to REST exposure — meta keys and storage stay the same.
- Save handler on `save_post` with full guard chain (nonce → DOING_AUTOSAVE → wp_is_post_revision → cap → sanitize). Empty values trigger `delete_post_meta()` to keep the DB clean.
- All three reader integrations use `function_exists()` guards on `sn_post_settings_get_*` calls — defensive against `inc/post-settings.php` absence.
- Two affected post types: `post` + `page` (matches existing hook guards across `inc/reading-time.php`, `inc/og-card-generator.php`, `inc/cloudflare-purge.php`).

### Process notes
- **Built via `subagent-driven-development`** — Tasks 4/5/6 (the three independent reader integrations) dispatched as 3 parallel subagents. Each subagent verified its own edit, then the main session re-verified each independently before committing per the spec-reviewer discipline.
- Two subagent judgment calls preserved: (1) seo-schema.php's conditional-assignment pattern (better than the prompt assumed); (2) og-card-generator.php's filter-callback structure differs from the prompt's assumed inline featured-image check — subagent inserted at the structurally analogous position before the helper delegation. Both calls verified correct.

### Notes
- **MINOR bump despite minor cap.** Project cap is 5 minors per major; the plugin already exceeded that mid-Phase-1 (shipped 1.0 through 1.9 without rolling to 2.0). Continuing the existing pattern. A strict cap enforcement would require renumbering the 1.6-1.9 backlog as v2.x — not justified for a single-user plugin.
- **Spec**: `docs/superpowers/specs/2026-05-16-per-post-settings-v1.10.0-design.md`. **Plan**: `docs/superpowers/plans/2026-05-16-per-post-settings-v1.10.0-plan.md`. Both grounded in two parallel research-agent reports (codebase mapping + UI architecture).
- **Queued next:**
  - **v1.10.1** — WP admin update gating fix. The auto-deploy GHA pipeline bypasses the WP admin update approval gate; plugin updates land without user confirmation. Reverting plugin to manual-update-from-WP-UI flow (theme keeps auto-deploy).
  - **v1.10.2** — per-post canonical URL override + custom robots directives (additional fields on the existing meta box; small TSF-equivalent additions).
  - **v1.11.0** — sitemap.xml generation (real new feature, TSF parity).
- **Out of scope** (deferred further): React block-editor sidebar, focus keyword analysis (TSF Focus extension), bulk-edit / quick-edit support, bulk import/export.

## [1.9.6] - 2026-05-16

### Added
- **Identity tab dirty-tracking on the sticky save bar.** JS snapshots all form values on `DOMContentLoaded`; on any field change, the save bar hint switches from default copy to "N unsaved change(s)" with a subtle amber dot prefix. Reverts cleanly when you type back to the original value. Scoped to `.sn-identity-form` only — Login (single field), Cloudflare, and Plausible have inline save buttons where this is overkill.
- **"+ Add another profile URL" button** in the sameAs section, replacing the v1.9.5 always-shown trailing empty input. Click → JS clones a fresh empty `<input type="url">` row above the button, focuses it, fires a custom `sn:row-added` event so the dirty-tracker doesn't read the empty row as "dirty" before typing. `<noscript>` fallback preserves the v1.9.5 single-trailing-input behaviour for users with JS disabled.
- New `assets/admin.js` (~150 LOC vanilla JS, no jQuery, no build pipeline). Enqueued only on SN admin pages via the same hook-suffix guard as `admin.css`. Loaded in the footer (`$in_footer = true`) so it runs after DOM is parsed.

### Accessibility (WCAG 2.1 AA)
- **Focus ring contrast**: `.sn-add-row-btn:focus-visible` box-shadow opacity at 0.65 (≈3:1 against white card surface) — meets WCAG 1.4.11 non-text contrast minimum.
- **JS-added inputs get `aria-label="Profile URL"`** — placeholders don't satisfy WCAG 4.1.2 / 3.3.2; each row needs its own accessible name beyond the group label.
- **`prefers-reduced-motion` query** disables the row fade-in animation and button transitions for users who've expressed that OS-level preference.
- **`:focus-visible`** (not `:focus`) so the focus ring only shows for keyboard users, not mouse clicks.
- **`<button type="button">`** native element with descriptive `aria-label` and real text content (not icon-only).

### Notes
- **Pure UX polish — no schema change, no server-side behaviour change.** The form submits identically: `social_same_as[]` array with one or more URLs, sanitized by `sn_settings_save()` (empty values filtered, valid URLs persisted).
- Zero-build-pipeline architecture preserved. The plugin still has no webpack / babel / npm pipeline; `admin.js` is hand-written vanilla JS that ships as-is.
- PATCH bump within `1.9.x` (counter at 6/7 of the per-minor cap).

## [1.9.5] - 2026-05-16

### Fixed
- **Three more latent `themes.php?page=` URL bugs** of the same class fixed in v1.9.4. Surfaced by a sweep after the Reading Time fix:
  - [`inc/admin-bar.php`](inc/admin-bar.php) — top-level "S&N" admin bar item and "⚙ Open Dashboard" submenu both pointed at the pre-v1.8.1 location.
  - [`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php) — the "Settings & activity" link from the RSS widget pointed at `themes.php?page=sn-theme-options&tab=rss` (legacy compound URL); now points at the cleaner v1.9.0 submenu URL `admin.php?page=sn-rss`.
- All have been latently broken since v1.8.1 (top-level menu move). Effect: clicking these links 404'd silently. Cleared in one sweep so this URL class is fully retired.

### Notes
- PATCH bump within `1.9.x`. No schema or behavior change.
- After this, **all admin-page URLs across the plugin codebase use the v1.9.0 sidebar submenu pattern** (`admin.php?page=sn-<slug>`). Confirmed by `grep -rn 'themes.php?page=sn-theme-options' inc/` returning zero matches.

## [1.9.4] - 2026-05-16

### Changed
- **Reading Time tab redesigned** with the v1.9.0 design system. Inline-styled cards replaced with `.sn-fieldset` / `.sn-card-grid` / `.sn-card`. Inline style strings dropped from 14 → 4 (remaining are minor max-width + monospace family on the match-display pill).
- **Tool flow restructured** as numbered steps inside one fieldset: *1 · Preview* (always shown) → *2 · Apply* (shown only after preview runs). Destructive-action warning copy is now on the Apply card itself, with a count of matches and an explicit "Destructive — cannot be undone — back up first" callout. Disabled when zero matches.
- **Empty-state for "no matches"** uses `.sn-status-box` (green) with a clean-state pill. Previously a single inline-coloured `<p>` that was easy to miss.
- **Matches table** stays on `widefat striped` (the right WP pattern for multi-column post lists per the v1.9.1 handoff). Match cells now use `.sn-pill --err` for the matched substring instead of inline-colored spans.

### Fixed
- **Legacy URL bug** — the preview link previously pointed at `themes.php?page=sn-theme-options&sn_rt_preview=1` (the URL pattern from pre-v1.8.1 when the admin page lived under Appearance). After v1.9.0's sidebar submenu refactor, that URL 404s. Now correctly points at `admin.php?page=sn-reading-time&sn_rt_preview=1`. This bug would have surfaced the first time anyone clicked Run Preview after the v1.8.1 menu move.

### Architecture
- **`apply_reading_time_cleanup` POST handler moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. Same PRG flow as Identity / Login / Plausible / Cloudflare. Count of cleaned posts encoded in the flash code (`rt_applied_N` pattern, same as `cleared_N` / `reset_N` for the maintenance actions).
- **`reading-time.php`'s admin tab callback is now render-only.**
- **1 new flash code pattern**: `rt_applied_N`.

### Notes
- **All 3 queued tab module redesigns complete** (v1.9.2 Plausible, v1.9.3 Cloudflare, v1.9.4 Reading Time). All 8 tabs now use the v1.9.0 design system. Inline-style total across the plugin's admin surface: was 80+, now 14.
- No schema or behavior changes. PATCH bump.
- **Suggested next: v1.9.5 if any cross-tab polish surfaces from this round; or sit at v1.9.4 and let it bake.** The 7-patch cap before rolling to v2.0.0 leaves room (v1.9.5, 1.9.6, 1.9.7 available within the 1.9.x lifecycle per project versioning rules).

## [1.9.3] - 2026-05-16

### Changed
- **Cloudflare tab redesigned** with the v1.9.0 design system. Inline-styled cards and `<p><strong>` field labels replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card`. Inline style strings dropped from 21 → 5 (remaining are font-family monospace + max-width on the manual-purge card).
- **Status box at the top** with two states: *Configured — auto-purge active* (green) when both token + zone ID are set; *Not configured* (amber) otherwise. Body includes the last-purge timestamp + kind ("full zone" vs "N URL(s)") when available, so the status box is also the activity log.
- **Credentials fieldset** holds both API token (`.sn-field-w-lg`, monospace) and Zone ID (`.sn-field-w-md`, monospace). Each field independently locks (disabled state + "locked by constant" helper) when its respective wp-config.php constant is set. Save button hidden when both fields are constant-locked.
- **Manual purge as a `.sn-card`** in `.sn-card-grid` — consistent with Dashboard action cards. Disabled when module is not configured.

### Architecture
- **POST handling moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. `cf_save` and `cf_purge_now` now route through the central PRG handler — same redirect-after-save flow as Identity / Login / Plausible. `cloudflare-purge.php`'s admin tab callback is now render-only.
- **3 new flash codes**: `cf_saved`, `cf_purged_ok`, `cf_purged_unconfigured`.

### Notes
- No schema or behavior change for Cloudflare consumers (`sn_cf_*` functions, option keys, auto-purge hooks unchanged). PATCH bump.
- Queued next: v1.9.4 (Reading Time tab — last of the three module redesigns).

## [1.9.2] - 2026-05-16

### Changed
- **Plausible tab redesigned** with the v1.9.0 design system. Inline-styled cards and `form-table` replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card-grid`. Inline style strings dropped from 23 → 5 (remaining are minor font-family + max-width on action cards).
- **At-a-glance module status box at the top of the Plausible tab.** Reflects one of four states: *Configured* (green — token present + last call succeeded), *Configured but failing* (amber — token present + last call returned an HTTP error), *Misconfigured — wrong token namespace* (amber — only Plausible plugin's api_token available; will 401), *Not configured* (red — no token at all). Mirrors the Login tab's module-status pattern.
- **Status details fieldset** (domain / token source / last call) below the module status box. Status pills for Last call use `.sn-pill --ok / --err` instead of inline-colored spans.
- **Locked-field treatment** for the Stats API token when `SN_PLAUSIBLE_STATS_TOKEN` constant is set. Mirrors the Login slug's locked treatment — disabled input with explanatory helper text.
- **Token form uses `.sn-fieldset-actions`** for the inline Save button (short form pattern, same as Login post-v1.9.1). No sticky save bar.

### Architecture
- **POST handling moved from `inc/plausible-admin.php` to `sn_handle_admin_post()`** in `inc/admin-page.php`. Both `pl_save` and `pl_test` now go through the central PRG handler so they get the same redirect-after-save flow as Identity / Login (no more stale-form-after-save). `plausible-admin.php` is now a render-only callback.
- **7 new flash codes**: `pl_saved`, `pl_cleared`, `pl_unchanged`, `pl_locked`, `pl_test_ok`, `pl_test_err`, `pl_test_unconfigured`. Test result detail (visitor count / HTTP error) regenerated from the existing transients on the post-redirect render.

### Notes
- No schema or behavior changes for the Stats API consumers (`sn_plausible_*` functions, transient keys, option names unchanged). PATCH bump.
- Queued next: v1.9.3 (Cloudflare tab) and v1.9.4 (Reading Time tab) — same design-system rollout pattern.

## [1.9.1] - 2026-05-16

### Changed
- **Login tab save UI replaced with inline action row.** The sticky `.sn-savebar` made sense on Identity (long form, scrolling required) but felt misplaced on Login (single editable field, no scrolling). New `.sn-fieldset-actions` component renders an inline save button at the bottom of the fieldset card, with optional left-aligned hint text (only shown when the slug is locked by the `SN_LOGIN_SLUG` constant). Pattern: short forms get inline actions, long forms keep the sticky bar.
- **Tab-specific page subtitle.** Every tab gets its own one-sentence subtitle below the H1, describing what that tab is about (e.g. Login → *"Custom login URL and emergency unlock for the WordPress admin."*; Identity → *"Site name, social profiles, Open Graph cards, and per-route SEO copy."*). Replaces the v1.8.1+ static `"Theme management and maintenance."` that displayed on every tab regardless of context. Subtitles live in the `sn_admin_pages()` data structure alongside the slug/tab/label/title.
- **Page header H1 + subtitle moved from inline styles to `.sn-page-h1` + `.sn-page-subtitle` classes.** Last two inline-style strings on the page-shell removed.

### Notes
- **Design-system audit drove this patch.** Triggered by a critique that surfaced 4 cross-tab issues: save-UI pattern mismatch, generic subtitle on every tab, inline-style cards on un-redesigned tabs, no width-capping outside Identity. v1.9.1 ships fixes for the first two (low-risk pure CSS+markup); the un-redesigned tab modules (Cloudflare, Plausible, Reading Time) are queued for v1.9.2–v1.9.4 since they touch form handlers and need supervised verification.
- **No schema or behavior changes.** PATCH bump within `1.9.x`.

## [1.9.0] - 2026-05-16

### Added
- **One sidebar submenu per tab.** All 8 admin sections (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Links) now appear as nested entries under the top-level *Signal & Noise* menu. Each has a unique slug (`sn-identity`, `sn-login`, …) so the WP sidebar highlights correctly when on that page. In-page tab navigation is kept as a parallel orientation aid — same pattern as Yoast / WP Rocket / ACF.
- **New Login tab** (sidebar: *Signal & Noise → Login*). Promotes the login-hide module from a buried Identity sub-section to a focused tab with:
  - **Module status display** — ACTIVE / DORMANT (wps-hide-login conflict) / BYPASSED (`SN_LOGIN_BYPASS` constant set), rendered as a colored status box.
  - **Current login URL** as a clickable monospace chip.
  - **Slug edit form** — disabled with explanation when `SN_LOGIN_SLUG` constant overrides the setting.
  - **Emergency unlock docs** — both wp-config.php constants, copy-pasteable.
- **`save_login` action handler** — writes only to the login slice of `sn_settings`. Disambiguates `update_option`'s false-on-no-change-vs-failure return by re-reading the stored value.
- **Post/Redirect/Get for all settings saves.** New `sn_handle_admin_post()` runs on `admin_init` (before any output), processes the `$_POST`, and redirects with `?sn_flash=<status>`. The page callback translates the flash arg into a notice on the post-redirect GET. Fixes the stale-form-after-save bug we shipped in v1.8.0 (form re-rendered with cached pre-save values until manual reload). See `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19 for the architectural rationale.

### Changed
- **Identity tab rewritten** with a custom `.sn-fieldset` card layout. Replaces WP's `form-table` markup with vertical-flow `.sn-field` rows (label above, input below, helper below input). Inputs are width-capped per type via `.sn-field-w-{xs,sm,md,lg,xl}` modifiers (140 / 240 / 480 / 580 / 720 px) so the form stops looking wonky at wide widths.
- **Identity tab loses its Login section.** Slug field moves to the new Login tab. Section TOC now lists 4 jumplinks instead of 5.
- **Profile URLs (sameAs) trailing empty row** is now subtly styled (`.sn-sameas-empty`) with dashed border + italic placeholder, reading as *"add another"* instead of a forgotten dangling input.
- **`sn_settings_save()` preserves the existing login slug** when `login_slug` isn't in the form payload. Without this, saving Identity (which no longer includes the slug field) would clobber the configured slug back to the default. Read-existing-as-fallback pattern.

### Architectural notes
- **Deliberate deviation from the WP Plugin Handbook's Settings API recommendation.** The Handbook recommends `register_setting` + `add_settings_section` + `add_settings_field` (form posts to `options.php`, WP handles save + PRG + nonces). We instead use custom `$_POST` handlers (form posts to our own admin URL) because Settings API enforces one-option-per-setting, which doesn't fit our single nested-array `sn_settings` schema. Same trade-off Yoast Free / ACF / WP Rocket make. **The price of this deviation is owning every responsibility Settings API handles for you** — nonce, sanitization, PRG redirect, success/error flash. After v1.9.0 we do all four correctly. Documented in `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19.
- **Source-grounded sidebar registration.** Submenu registration uses the documented escape hatch for the auto-prepended duplicate-parent submenu (gotcha #14); enqueue guard handles `add_submenu_page`'s `false` return for low-cap users (gotcha #15); single source of truth (`sn_admin_pages()`) prevents duplicate-slug drift (gotcha #16).
- **Backward compat preserved.** Old v1.8.x deep links like `?tab=identity` still work — the callback's dispatcher checks `$_GET['tab']` first, falls back to deriving the tab from `$_GET['page']`. PRG redirect preserves `?tab=` if it was on the inbound request.
- **Other tab modules untouched.** Cloudflare / Plausible / RSS / Reading Time keep their v1.8.x form-table + inline styles. Same redesign pattern rolls across them in v1.9.x or v2.0.0.

## [1.8.1] - 2026-05-16

### Changed
- **Admin page promoted to top-level menu.** "Signal & Noise" now appears as its own item in the WP admin sidebar (megaphone icon, position 81) instead of a submenu under Appearance. New URL: `/wp-admin/admin.php?page=sn-theme-options` (the old `/wp-admin/themes.php?page=…` URL no longer resolves). The first submenu entry is labelled "Dashboard" to avoid the duplicate-parent-label pattern that `add_menu_page()` produces by default. Tab deep links (`?tab=identity` etc.) are unchanged.
- **Inline styles extracted to `assets/admin.css`.** 25+ duplicated `style="…"` attributes across the Dashboard and Identity tabs are now class-driven via a single enqueued stylesheet with CSS variables for surface/border/spacing/status. Other tab modules (Cloudflare, Plausible, Reading Time, RSS) still use inline styles — they get the same treatment in v1.9.0.
- **Status indicators replaced with pill badges.** Dashboard status row now uses `.sn-pill` / `.sn-pill--ok` / `.sn-pill--warn` / `.sn-pill--err` (rounded, colored dot prefix) instead of inline-coloured spans.

### Added
- **Identity tab section TOC.** Anchor-jump nav at the top — *Identity · Social · Open Graph · Login · SEO Copy* — for fast navigation on the long form. Each section header gets a matching `id` with `scroll-margin-top` so the target stays visible under the WP admin bar.
- **Identity tab sticky save bar.** Pure-CSS `position: sticky; bottom: 0;` keeps the Save button always one click away while editing the form, regardless of scroll position. Backdrop-blur for legibility on top of form content.
- **`sn_admin_page_hook()` static accessor.** Captures the hook suffix returned by `add_menu_page()` so the stylesheet-enqueue guard can't typo it. Cleaner than re-deriving `'toplevel_page_' . $slug` everywhere.

### Notes
- **No behaviour or schema change.** PATCH bump per project versioning caps (still within `1.8.x`). All `sn_settings` data shipped in v1.8.0 stays intact.
- **Out of scope:** refactor of other tab modules to use the new component classes (their inline styles still win on specificity); JS dirty-tracking on the sticky save bar; promoting tabs to individual sidebar submenu entries.

## [1.8.0] - 2026-05-16

### Added
- `inc/settings.php` — single source of truth for site-identity config (~216 LOC). Stores all settings in one `wp_options['sn_settings']` row across 5 categories: identity, social, og, login, seo_copy.
- **Identity tab in admin page** (`Appearance → Signal & Noise → Identity`) — single form with grouped fields for: site name + description + person name + locale; Twitter handle + sameAs profile URLs; default OG image URL + card dimensions; custom login slug; per-route SEO titles + descriptions.
- **Activation migration** (`sn_settings_seed_legacy_values`) — hostname-gated to `juanlentino.com`; seeds existing JL values into `wp_options` exactly once per environment. Subsequent activations no-op via `sn_settings_migrated_v1` flag. Lazy `admin_init` fallback covers SSH-based deploys where `register_activation_hook` doesn't fire.
- **`sn_setting('cat.field', $fallback)` accessor** — static-cached, dot-path read with deep-merge over defaults. Used throughout `seo.php` / `seo-schema.php` / `login-hide.php` in place of hardcoded literals.

### Changed
- `inc/seo.php`, `inc/seo-schema.php`, `inc/login-hide.php` refactored to read all site-identity values from `sn_setting()` instead of PHP literals. 12 hardcoded JL-specific values removed across the three files.
- Filter compat layer preserved: existing `apply_filters()` hooks (`sn_twitter_handle`, `sn_schema_same_as`, `sn_og_image_dimensions`) continue to work as override stack on top of stored settings. Pattern: `apply_filters('sn_X', sn_setting('path', $fallback))`.

### Notes
- **Live site output is byte-identical post-upgrade.** The activation migration seeds the JL-specific values into `wp_options['sn_settings']` so emitted meta tags match v1.7.0 exactly. Verifiable: diff a page's `<head>` pre/post-upgrade returns empty.
- **Generic defaults for fresh installs.** On any non-juanlentino.com host, the migration sets only the `sn_settings_migrated_v1` flag without seeding values. `sn_settings_defaults()` provides generic fallbacks pulled from `get_bloginfo()`.
- **Out of scope:** per-post settings UI (noindex toggle, custom meta description override per post), security toggles UI (xmlrpc, rest user lockdown, etc.), JS-driven add/remove for the sameAs list. Each becomes its own future phase.
- **Prereq for Phase 13 cutover** (v2.0.0, deactivates TSF + wps-hide-login). After v1.8.0, the plugin owns all site-identity emission with configurable values.

## [1.7.0] - 2026-05-16

### Added
- `inc/seo-schema.php` — JSON-LD structured data emission (~150 LOC). Single `@graph` script in `<head>` carrying three connected schemas:
  - **WebSite** — every page; publisher references the Person
  - **Person** — every page; name + URL + `sameAs` (X / Instagram / LinkedIn profiles, filterable via `sn_schema_same_as`)
  - **Article** — singular posts only; headline, `datePublished`, `dateModified`, `mainEntityOfPage`, image (via `sn_og_image_url` + `sn_og_image_dimensions` filters), references the Person as author + publisher

### Skipped (deliberate)
- **BreadcrumbList** — WordPress 7.0 ships a native Breadcrumbs block; use that instead.
- **SearchAction** — site has no `/search/{term}` route.
- **WebPage on non-post singulars** — marginal value; omit.

### Notes
- Output is `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for readability and minor byte savings. Single `<script>` tag (Google prefers connected `@graph` over multiple disjoint scripts).
- Schemas reference each other by `@id` (`#/schema/Person`, `#/schema/WebSite`, `<permalink>#article`) so Google's structured-data validator sees them as a graph, not isolated nodes.
- After Phase 13 cutover (TSF deactivation), this becomes the site's only structured-data emission. Until then, TSF emits its own `@graph` in parallel — duplicate schemas in `<head>` (verifiable via [Google Rich Results Test](https://search.google.com/test/rich-results)). The duplication is cosmetic; both validate; Phase 13 removes the second source.

## [1.6.0] - 2026-05-16

### Added
- **Canonical URL** emission via `<link rel="canonical">` on front page, /notes, /provenance, and singular posts/pages (`inc/seo.php`, wp_head priority 1).
- **Robots meta** emission via `<meta name="robots">` with TSF's default no-restrictions content (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`). Honors a per-post `_sn_noindex` post-meta flag for selective de-indexing (admin UI in Phase 11).
- **`og:locale`** meta emission (`en_US` hardcoded).
- **`og:image:width` + `og:image:height`** meta emission (defaults 1200×630 matching generated cards; filterable via new `sn_og_image_dimensions` filter).
- **`article:published_time` + `article:modified_time`** meta emission on singular posts (ISO 8601 UTC).
- **`twitter:site` + `twitter:creator`** meta emission (`@juan_lentino` hardcoded; filterable via new `sn_twitter_handle` filter).

### Removed
- Three dead `wpseo_*` filter hooks in `inc/og-card-generator.php` (`wpseo_opengraph_image`, `wpseo_twitter_image`, `wpseo_opengraph_image_size`). They were copy-pasted from a Yoast-era assumption; the active site runs The SEO Framework which uses a different filter namespace. Hooks were dead code — never fired. OG card surfacing flows through our own `sn_og_image_url` filter consumed by `inc/seo.php`.

### Behaviour
- Brings the companion plugin's SEO emission to feature-parity with The SEO Framework's Open Graph, Twitter Card, canonical, and robots fields. Sets the stage for full TSF deactivation in Phase 13.

### Notes
- TSF still emits canonical, robots, and JSON-LD schemas in parallel until Phase 13 cutover. Our emission is the source of truth post-cutover. Until then, TSF's tags are competing — verify after Phase 13 deactivation that crawlers pick up the right ones.

## [1.5.0] - 2026-05-16

### Added
- `inc/login-hide.php` — custom login URL module (~110 LOC). Renames `/wp-login.php` to a custom slug (default: `/sn-login`). Direct visits to `/wp-login.php` and unauthenticated `/wp-admin` requests return 404. Login URL appears in password-reset emails and logout redirects via filter rewrites of `site_url()` / `wp_redirect()` output.

### Behaviour
- Configurable via wp-config.php constants: `SN_LOGIN_SLUG` (default `'sn-login'`) and `SN_LOGIN_BYPASS` (emergency unlock if you lock yourself out).
- **Defensive pre-flight:** module stands down while `wps-hide-login` is still active to avoid conflicting rewrite rules. Surfaces an admin notice explaining the situation. Once `wps-hide-login` is deactivated (Phase 13 of the absorption roadmap), this module takes over seamlessly.
- One-time `flush_rewrite_rules()` on first activation (and again whenever `SN_LOGIN_SLUG` constant changes). Keyed by current slug in `sn_login_rewrites_flushed` option.
- Allow-list for `admin-ajax.php`, `async-upload.php`, `wp-cron.php`, `/wp-json/`, `/feed` so REST + cron + feed flows aren't impacted.

### Notes
- Replaces the `wps-hide-login` third-party plugin. Their plugin is ~700 LOC; ours is ~110. Phase 13 of the absorption roadmap deactivates `wps-hide-login` after this module ships and verifies.

## [1.4.1] - 2026-05-16

### Fixed
- Duplicate `og:image` and `twitter:image` tags in `<head>` (Phase 6 diagnostic outcome). The plugin's `inc/seo.php` has been emitting our generated OG card URLs since Phase 1 (v8.2.0), but The SEO Framework (autodescription) was emitting competing tags first in the source — pointing at the site icon as fallback. Crawler parsing of duplicate `og:image` is undefined; Facebook Debugger would flag the page.

### Behaviour
- Added `the_seo_framework_meta_generator_pools` filter to remove `Open_Graph`, `Facebook`, and `Twitter` pools from TSF's output. Our `wp_head` emission becomes the single source of truth for OG/Twitter meta tags site-wide. TSF still owns canonical URLs, robots meta, JSON-LD schemas, and a handful of og:* fields we don't yet emit (og:locale, og:image:width/height, article:published_time, twitter:site/creator) — those migrate to our seo.php in Phase 10+11.

### Notes
- Stopgap fix until full SEO absorption (Phase 10-13) replaces TSF entirely.

## [1.4.0] - 2026-05-16

### Added
- `inc/wp-update-integration.php` — registers the plugin with WordPress's native update system. Plugin now appears in `wp-admin/update-core.php` and Plugins → Installed Plugins alongside other plugins, showing current version and "up to date" status (or "update available" if auto-deploy ever falls behind a tag). ~130 LOC.

### Behaviour
- Polls GitHub Tags API every 12h (cached in `sn_gh_latest_plugin` site transient). Picks the highest `v\d+\.\d+\.\d+` semver tag from `juanlentino/signal-and-noise-tools`.
- Hooks `pre_set_site_transient_update_plugins` to inject the plugin into WP's update registry: into `->no_update` when local matches GitHub (the normal case under Phase 2c auto-deploy), into `->response` when GitHub is ahead.
- Hooks `upgrader_pre_install` to intercept "Update Now" with a WP_Error directing the maintainer to push a git tag instead — preserves the git checkout that the SSH-based auto-deploy depends on.

### Notes
- Mirror of the theme's equivalent `inc/wp-update-integration.php` shipped in `signal-and-noise` v8.5.0. Both deliver the same UX (visibility in WP's standard update UI) using package-specific filter hooks (plugins vs themes have different transient shapes).
- GitHub API queried unauthenticated; 60 requests/hour limit is plenty given the 12h cache TTL (≤2 requests/day).

## [1.3.0] - 2026-05-16

### Added
- `inc/og-card-generator.php` — OG/Twitter card PHP GD generation, caching, Yoast filter integration. Fonts provided by the theme via `sn_og_font_paths` filter (new cross-package contract).
- `inc/reading-time.php` — reading time calculation, caching in `_sn_reading_time_minutes` post meta, `[sn_reading_time]` shortcode, `render_block` bridge for block-context shortcodes. The previously cross-package `sn_admin_reading_time_tab` hook is now intra-plugin.
- `inc/content-surfaces.php` — Notes category, /notes Page, /provenance + /over-detection + /as-substrate Pages, permalink structure, query loop scoping.
- `inc/content-migrations.php` — 11 one-shot content seed migrations for the Provenance pillar (body, refinements, byline reading time, split, AS substrate seed, card2 longform, card readtimes dynamic, catalog numbers, post-date displaytype, eyebrow dynamic, clear notes template override).
- `inc/content-rendering-helpers.php` — Gutenberg block-markup generators called from migrations (byline_reading_time, toc, papers_index).
- `inc/seed-content/` — HTML bodies consumed by content migrations.

### Changed
- Pre-flight guard #3 added to bootstrap: bails with admin notice if the theme still ships `inc/og-image.php`, `inc/reading-time.php`, or `inc/notes-and-provenance.php` (defends against accidental install-order inversion).

### Notes
- Requires theme v8.4.0+. If installed against an older theme, guard #3 fires; plugin loads dormant. After upgrading the theme, plugin activates normally.
- One new cross-package contract: `sn_og_font_paths` filter (theme listens, plugin dispatches).

## [1.2.0] - 2026-05-15

### Removed
- "Latest on GitHub" status row + "Check Now" button in admin Dashboard tab (`inc/admin-page.php`).
- "Heal Templates" form handler + UI card in admin Dashboard tab (`inc/admin-page.php`).
- Quick "Check updates" entry in WP admin bar (`inc/admin-bar.php`).
- REST routes `/check-updates` and `/heal-templates` (`inc/rest-api.php`). Their backing theme modules retired in theme v8.3.0.
- `upgrader_process_complete` hook in `inc/cloudflare-purge.php` — replaced by deploy-time REST call from GitHub Actions.

### Changed
- `/full-reset` REST endpoint no longer includes a "heal templates" step. New behavior: purge caches + clear DB template overrides only.

### Notes
- Requires theme v8.3.0+. If installed against an older theme, the plugin still loads cleanly — the removed UI elements were the only readers of the retired contracts.

## Infrastructure — Phase 2c (no version bump)

- `.github/workflows/deploy.yml` added: SSH-based auto-deploy. Tag push to plugin repo → GitHub Actions SSHes into Cloudways as a **dedicated, application-scoped SSH user** (`sn-plugin`, alias for `nffqxsrgxz`) and runs `git fetch && git checkout <tag>` in the plugin directory → POSTs to `/purge-cache` for CF cache invalidation. Same tag-push ritual as the theme repo.
- **Security posture:** the deploy SSH key is bound to `sn-plugin`, a dedicated additional user with access only to this application's filesystem. If the GitHub Actions secret is ever leaked, blast radius is bounded to this WP app's content (same as a compromised WP admin), NOT the whole Cloudways server. Earlier intermediate setup using `master_user` was discarded for this reason.
- **One-time live cutover (2026-05-16):** plugin directory renamed from `signal-and-noise-tools-1.2.0` (artifact of the Upload Plugin flow) to the canonical `signal-and-noise-tools`. Done via WP-CLI `deactivate → mv → git clone → checkout v1.2.0 → activate`, sub-second downtime. Backup retained on the live server at `signal-and-noise-tools-1.2.0-old` and `signal-and-noise-tools-OLD-MASTER`; delete after a few days of stable operation.
- Cloudways → GitHub auth uses a dedicated read-only deploy key (`cloudways-server-readonly`) on this repo; the private key lives in the `sn-plugin` user's writable `~/.openssh/cw-to-gh-deploy_ed25519` (Cloudways convention: `~/.ssh/` is root-owned for additional users, `~/.openssh/` is user-writable). The workflow exports `GIT_SSH_COMMAND` on the remote shell to point git at this key without needing a `~/.ssh/config` file.
- Treated as build infra per CLAUDE.md `.github/workflows/` convention (mirrors theme Phase 2a — no version bump for the workflow file itself).

## [1.1.0] — RSS Plausible Tracker migrated from theme MU plugin

First minor in the 1.x line. Brings the early slice of Phase 4 forward (ahead of Phase 2's updater migration) to resolve the awkward dual-state where `rss-plausible-tracker.php` lived in the theme repo but was distributed manually to `wp-content/mu-plugins/`.

### Added

- **[`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php)** — RSS subscriber tracker (formerly `mu-plugins/rss-plausible-tracker.php` in the theme repo, v1.2.0 of the MU plugin). Same DB table (`wp_rss_feed_log`), same option keys (`sn_rss_tracker_settings`, `sn_rss_tracker_db_version`), same cron hook (`sn_rss_tracker_daily_prune`), same admin tab and dashboard widget. Only the file location and surrounding bootstrap changed.
- **[`tests/bot-detection.php`](tests/bot-detection.php)** — standalone PHP fixture test for `sn_rss_tracker_is_bot()`. Moved from theme repo's `mu-plugins/tests/`. Runnable as `php tests/bot-detection.php`.
- **Pre-flight guard #2** in [`signal-and-noise-tools.php`](signal-and-noise-tools.php) — before `require_once`ing the rss tracker module, check `file_exists( WPMU_PLUGIN_DIR . '/rss-plausible-tracker.php' )`. If the legacy MU plugin file is still on disk under `wp-content/mu-plugins/`, this plugin skips loading its own copy and emits a one-line admin notice asking the maintainer to delete the MU file. MU plugin continues serving tracking; no fatal, no downtime, no data loss.

### Migration order

The dual-existence problem is the same shape as Phase 1's, with the same solution: pre-flight guard means no fatal regardless of order.

1. **Install plugin v1.1.0 first.** Maintainer's WP admin → Plugins → existing *Signal & Noise Tools* listing → manual upgrade via Upload Plugin (until Phase 2's auto-updater lands). Plugin's guard sees `wp-content/mu-plugins/rss-plausible-tracker.php` still present → skips loading the new tracker module → MU plugin continues serving tracking → admin notice instructs maintainer on next step.
2. **Delete the MU file via SFTP:** `wp-content/mu-plugins/rss-plausible-tracker.php`. Or via WP-CLI: `wp mu-plugin delete rss-plausible-tracker` (if available).
3. **Next admin pageview:** guard sees the MU file gone → loads our tracker module → tracking continues seamlessly via the plugin. Admin notice disappears.

### Data continuity

- **`wp_rss_feed_log` table:** untouched. Plugin reads/writes the same rows.
- **Options:** `sn_rss_tracker_settings`, `sn_rss_tracker_db_version` — same keys, same values.
- **Cron event:** `sn_rss_tracker_daily_prune` — same hook name. When the MU plugin stops loading, the cron event remains scheduled but its handler is now in the active plugin (function name `sn_rss_tracker_cron_prune` is identical). WP fires the event → plugin's handler runs. Seamless.

### Why minor

New module added, new bootstrap guard, theme repo cleanup in coordinated theme v8.2.1 release — meaningful capability shift in plugin scope (it now owns RSS analytics, not just admin/REST/security tooling). No breaking change. First minor in the 1.x line.

### Coordinated theme release

Ships alongside theme `v8.2.1` (docs-only-ish), which removes `mu-plugins/rss-plausible-tracker.php` from the theme repo and updates the WORDPRESS-REFERENCE §10.0 phase plan. Theme update can ship before, during, or after the plugin update — the plugin's guard handles all orderings.

## [1.0.1] — Pre-flight legacy-theme guard

Patch fix for an order-of-operations footgun discovered during the v1.0.0 install on the live site.

### Why this exists

The Phase 1 split was designed as a coordinated release: install plugin v1.0.0 first, then click the theme update to v8.2.0. The original CHANGELOG entry framed the "duplication window" between these two steps as a cosmetic issue ("WP registers hooks twice"). That framing was wrong.

The actual failure mode: if the plugin loads while the theme is still at v8.1.x, both packages have `function sn_purge_all_caches()`, `function sn_handle_quick_purge_caches()`, and the seven other moved-function declarations on disk. PHP fatals at parse time with "Cannot redeclare function sn_*", WordPress catches it during plugin activation, and the user sees *"Plugin could not be activated because it triggered a fatal error."* It's a hard fatal, not a hook-collision cosmetic.

WordPress hooks ARE idempotent (the `add_action` layer); PHP function declarations are NOT. These are two different layers of WordPress, and the original spec conflated them.

### Fixed

- **[`signal-and-noise-tools.php`](signal-and-noise-tools.php) — pre-flight check at bootstrap.** Before the `require_once` chain runs, the plugin checks whether `wp-content/themes/signal-and-noise/inc/admin-page.php` exists on disk. If it does, the theme is still at v8.1.x and the require chain would fatal. The plugin returns early (skipping module loading entirely) and surfaces an admin notice asking the maintainer to update the theme first. After the theme update lands, the next admin pageview sees the file gone, the guard passes, and modules load normally.

### Behavior contract

- **Theme is at v8.2.0+ (legacy files deleted):** plugin loads modules as usual. No-op cost.
- **Theme is at v8.1.x (legacy files still present):** plugin bootstrap bails before any function is declared; admin notice tells the maintainer to update the theme. No fatal, no broken admin.
- **A non–Signal & Noise theme is active:** guard is skipped (no conflict possible); plugin loads normally.
- **Theme is downgraded back to v8.1.x while the plugin is active:** next request, the guard re-runs and bails. Plugin functions stop being declared; the theme reclaims ownership. No fatal.

### Why patch

No new feature; no breaking change. One pre-flight check added to the bootstrap; the rest of the plugin is byte-identical to v1.0.0. Patch bump per SemVer.

## [1.0.0] — Phase 1: scaffold + easy moves

First release. Nine modules moved from the theme repo via the WP action/filter contract pattern.

### Added

- Plugin bootstrap (`signal-and-noise-tools.php`) with standard WP plugin header.
- 9 modules under `inc/`, mirroring the theme's flat module structure: `seo.php`, `security-headers.php`, `cloudflare-purge.php`, `plausible-api.php`, `plausible-admin.php`, `plausible-widget.php`, `admin-bar.php`, `admin-page.php`, `rest-api.php`.
- Cross-package contracts: three filters (`sn_purge_all_caches_result`, `sn_self_heal_force_run_result`, `sn_updater_branch`) and two actions (`sn_updater_force_check`, `sn_updater_clear_error`).
- GitHub Actions lint workflow (`php -l` on every PHP file).

### Coordination

Ships alongside theme Signal & Noise `v8.2.0`, which deletes the original copies of these 9 modules and registers the listener side of the contracts. Install plugin first, then ship the theme update.

### Spec + plan (from theme repo)

- `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md`
- `docs/superpowers/plans/2026-05-15-companion-plugin-phase-1.md`
