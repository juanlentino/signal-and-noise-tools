# Cross-repo consistency audit — theme ↔ plugin

**Scanned:** 2026-05-25

## Summary

The theme/plugin split is architecturally clean. No duplicate logic was found in hot paths. The 3 documented filter contracts work correctly. The highest-severity issue is a live contract violation: the plugin's admin dashboard tab calls `sn_gh_latest_theme_tag()` — a theme-owned function — directly via `function_exists`, violating the documented "never let plugin code directly call a theme function" rule. Everything else is documentation drift and orphan code from the v8.3.0 self-heal retirement that never got fully cleaned up.

## Findings

### X-01 [severity: high] — Plugin calls theme function directly (contract violation)

**Surfaces:**
- Theme: `inc/wp-update-integration.php:63` — `function sn_gh_latest_theme_tag()`
- Plugin: `inc/admin-tab-dashboard.php:58` — `function_exists('sn_gh_latest_theme_tag') ? sn_gh_latest_theme_tag() : null`

**Issue:**
The plugin's `snt_deploy_status_for('theme')` path calls `sn_gh_latest_theme_tag()` directly with a `function_exists` guard. `WORDPRESS-REFERENCE.md §10.0` explicitly states: *"Never let plugin code directly call a theme function — even with `function_exists` guards. The contract pattern is non-negotiable."* This violates that rule. The plugin's deploy-status card on the Dashboard tab depends on this call to show "Theme 9.1.5 · up to date"; it works only because the theme happens to be active.

**Which side should win:**
Plugin side should use a filter contract, not a direct call. The correct pattern mirrors `sn_purge_all_caches_result`: plugin dispatches `apply_filters('sn_gh_latest_theme_tag_result', null)`, theme adds a filter listener returning `sn_gh_latest_theme_tag()`. This makes the plugin tolerant of "theme absent/inactive" by design rather than by luck.

**Proposed fix:**
1. Add a new filter `sn_gh_latest_theme_tag_result` (dispatched by plugin, listened by theme).
2. In theme `inc/wp-update-integration.php`: `add_filter('sn_gh_latest_theme_tag_result', 'sn_gh_latest_theme_tag')`.
3. In plugin `inc/admin-tab-dashboard.php`: replace the direct call with `apply_filters('sn_gh_latest_theme_tag_result', null)`.
4. Add the new hook to the contract table in `docs/WORDPRESS-REFERENCE.md §10.0`.

**Risk:** medium — currently safe because theme is always active on this install, but it's a correctness hazard for any install where the theme is deactivated or temporarily replaced.

---

### X-02 [severity: medium] — Ability category registrations: plugin fires `_doing_it_wrong` silently

**Surfaces:**
- Theme: `inc/abilities-registration.php:125–151` — registers 3 categories (`diagnostics`, `content`, `ai-generation`) with `wp_has_ability_category()` guards
- Plugin: `inc/abilities-registration.php:58–88` — registers 5 categories including the same 3 (`content`, `diagnostics`, `ai-generation`) without guards

**Issue:**
Both files hook `wp_abilities_api_categories_init` at default priority 10. WordPress loads themes before plugins, so the theme registers first. The plugin then re-registers the 3 overlapping categories without calling `wp_has_ability_category()` first. Per the theme comment (line 10): *"calling `wp_register_ability_category()` on an already-registered slug fires `_doing_it_wrong`."* The plugin comment (line 54) claims the registry *"silently bails"* — these are contradictory descriptions of the same upstream behavior. Regardless, one of the two assessments is wrong, and either firing `_doing_it_wrong` 3 times per request (PHP notice on debug installs) or silently bailing (no harm but no documentation) needs clarification.

**Which side should win:**
Plugin should add the same `wp_has_ability_category()` guards the theme already uses. Plugin typically loads after theme — guards are the safe path and cost nothing.

**Proposed fix:**
In plugin `inc/abilities-registration.php:63–86`, wrap each of the 3 overlapping `wp_register_ability_category()` calls with `if ( ! wp_has_ability_category('content') )` etc., mirroring the pattern already in the theme. Add a code comment explaining why the guard is necessary. Also reconcile the two conflicting docblock descriptions of the upstream behavior by checking the actual `WordPress/abilities-api` source.

**Risk:** low — no user-visible breakage today; risk is PHP notices on debug installs and future maintainers reasoning from the incorrect comment.

---

### X-03 [severity: medium] — `sn_github_local_sha` option: fetched but never used (dead DB read)

**Surfaces:**
- Plugin: `inc/admin-page.php:956` — `$local_sha = (string) get_option('sn_github_local_sha', '');`

**Issue:**
`$local_sha` is fetched once and never referenced again anywhere in `admin-page.php` or `admin-tab-dashboard.php`. The option was populated by the retired legacy updater (`inc/updater.php`, removed in theme v8.3.0). That module no longer writes `sn_github_local_sha`, so the option is always an empty string on current installs. The fetch is a dead DB read and dead variable.

**Which side should win:**
Delete from plugin. The option itself (if it still exists in the DB from an older install) is harmless leftover data; no migration needed.

**Proposed fix:**
Remove line 956 from plugin `inc/admin-page.php`. No coordinated change needed in the theme.

**Risk:** low — the variable is never rendered; removing it is a one-line cleanup with zero behavior change.

---

### X-04 [severity: medium] — Contract doc `§10.0` intro paragraph is stale (refers to retired files, wrong hook count)

**Surfaces:**
- Theme: `docs/WORDPRESS-REFERENCE.md:284–288`

**Issue:**
The section 10 opening paragraph has three stale claims:

1. *"Understand both before touching `inc/updater.php`, `inc/template-self-heal.php`"* — both files were retired in v8.3.0 and no longer exist in the theme. The correct file to reference is `inc/template-maintenance.php`.
2. *"They communicate via 7 WP hooks"* — current state is 3 hooks (the 5 updater/self-heal contracts were retired in v8.3.0). The table below correctly says 3; the intro contradicts it.
3. *"The split is partial as of v8.2.0 — 9 modules moved (Phase 1); Phases 2–4 will migrate the rest."* — Phases 2–4 are complete. Phase 3 moved og-card-generator, reading-time, and content modules (v8.4.0 / Tools v1.3.0). Phase 4 was declared empty. No further migrations are planned.

**Which side should win:**
Theme doc is the canonical contract surface; update the doc.

**Proposed fix:**
Rewrite the intro paragraph to:
- Replace `inc/updater.php, inc/template-self-heal.php` with `inc/template-maintenance.php`.
- Change "7 WP hooks" to "3 WP hooks".
- Change "The split is partial" to "The split is complete as of v8.4.0 / Tools v1.3.0; no further migrations are planned."

**Risk:** low — documentation only; no code change.

---

### X-05 [severity: medium] — Contract doc `§10.0` "Modules still in theme" list is incomplete

**Surfaces:**
- Theme: `docs/WORDPRESS-REFERENCE.md:295`

**Issue:**
The line reads: *"Modules still in theme: `setup.php`, `assets-frontend.php`, `frontend-filters.php`, `og-fonts.php`, `template-maintenance.php`, `page-notes-template.php`, `page-notes-render.php`, `patterns.php`."* Three files exist in `inc/` that are not listed:
- `inc/wp-update-integration.php` (added v8.5.0)
- `inc/wp-update-git-preservation.php` (added v8.5.2)
- `inc/abilities-registration.php` (added v9.1.0)

These are referenced in `§10.5` and the functions.php module map, but the canonical list at `§10.0` is the first place a maintainer looks when reasoning about what lives where.

**Which side should win:**
Theme doc — add the 3 missing files to the list.

**Proposed fix:**
Append to the `§10.0` modules-in-theme bullet: `, `wp-update-integration.php`, `wp-update-git-preservation.php`, `abilities-registration.php``. All are presentation/operational-visibility or theme-specific; the rationale for keeping them in the theme is clear from their docblocks.

**Risk:** low — documentation only.

---

### X-06 [severity: medium] — Contract doc `§10.0` "Direct dependencies" cites retired constants

**Surfaces:**
- Theme: `docs/WORDPRESS-REFERENCE.md:314`

**Issue:**
The "Direct dependencies kept (no contract — stable by design)" block includes: *"`SN_GITHUB_REPO` / `SN_THEME_SLUG` constants — plugin reads with `defined()` guard."* Both constants were defined inside `inc/updater.php` (the retired legacy updater, removed v8.3.0). Neither constant is defined anywhere in the current theme codebase, and a grep of the current plugin confirms no plugin file calls `defined('SN_GITHUB_REPO')` or `defined('SN_THEME_SLUG')` either. These constants are gone from both repos and the doc entry is purely misleading.

**Which side should win:**
Theme doc — remove the two retired constants from the list; replace with the current relevant constants (`SN_GH_THEME_OWNER`, `SN_GH_THEME_REPO`, `SN_GH_THEME_STYLESHEET`, etc. defined in `inc/wp-update-integration.php`) if the plugin actually reads any of them. A quick grep shows the plugin does not read theme constants directly — so the cleanest fix is to just remove the bullet.

**Proposed fix:**
Delete the `SN_GITHUB_REPO / SN_THEME_SLUG` line from `§10.0`'s "Direct dependencies kept" block. Optionally document that the theme's `sn_last_seen_theme_version` option is the only theme-written key that the plugin uses (indirectly, via the shared admin UI).

**Risk:** low — documentation only; no code change needed.

---

### X-07 [severity: low] — `sn_purge_all_caches()` references `SN_SELF_HEAL_*` constants from a retired file

**Surfaces:**
- Theme: `inc/template-maintenance.php:92–108`

**Issue:**
The `self_heal_state` branch in `sn_purge_all_caches()` reads:
```php
if ( defined( 'SN_SELF_HEAL_LAST_CHECK_OPT' ) ) { delete_option( SN_SELF_HEAL_LAST_CHECK_OPT ); }
if ( defined( 'SN_SELF_HEAL_FAILURES_OPT' ) ) { delete_option( SN_SELF_HEAL_FAILURES_OPT ); }
```
The inline comment says: *"Constants come from `inc/template-self-heal.php`."* That file was retired in v8.3.0. Neither constant is ever defined in the current codebase, so both `defined()` checks are permanently false — the branch is dead code. This is safe (the `defined()` guards prevent errors), but it adds confusion and a false pointer to a file that doesn't exist.

**Which side should win:**
Theme — remove the dead branch.

**Proposed fix:**
Delete the entire `if ( $args['self_heal_state'] )` block (lines ~92–108) from `sn_purge_all_caches()`. Also remove `'self_heal_state' => true` from the `$args` defaults array and from the `@param` docblock. This is a theme-only change; the plugin's existing calls pass no `self_heal_state` key so they're unaffected.

**Risk:** low — the `defined()` guards ensure nothing breaks; this is cosmetic cleanup of dead code.

---

### X-08 [severity: low] — Contract namespace `signal-noise/` (plugin) vs `signal-and-noise/` (theme) not documented

**Surfaces:**
- Theme: `inc/abilities-registration.php` — all 12 abilities use `signal-and-noise/*` namespace
- Plugin: `inc/abilities-registration.php` — all 26 abilities use `signal-noise/*` namespace

**Issue:**
The two namespaces are intentionally different (the `signal-and-noise/` prefix was specifically chosen in v9.1.1 to match `get_stylesheet()` so WP's `ai/ai` plugin classifies theme abilities correctly as "Theme"). This is correct design, but the rationale is documented only in the theme's v9.1.1 CHANGELOG entry — not in `docs/WORDPRESS-REFERENCE.md §10.0` or the abilities-registration docblocks on either side. A future maintainer writing a new ability must know which namespace to pick, and there's no single reference for the rule.

**Which side should win:**
Document in both files and in `§10.0`.

**Proposed fix:**
Add a comment block at the top of both `inc/abilities-registration.php` files explaining the namespace split: theme uses `signal-and-noise/*` (matches stylesheet slug → detected as "Theme" by ai/ai), plugin uses `signal-noise/*` (matches plugin slug). Also add one sentence to `§10.0` of `WORDPRESS-REFERENCE.md` explaining this.

**Risk:** low — no code change needed; documentation only.

---

### X-09 [severity: low] — `sn_reading_time` shortcode path in theme is an indirect call through plugin

**Surfaces:**
- Theme: `inc/page-notes-render.php:41–48` — `sn_notes_reading_time_for_slug()` calls `do_shortcode('[sn_reading_time slug="..."]')`
- Plugin: `inc/reading-time.php` — defines `[sn_reading_time]` shortcode via `add_shortcode()`

**Issue:**
`sn_notes_reading_time_for_slug()` wraps `do_shortcode()` to invoke the `[sn_reading_time]` shortcode, which is registered by the plugin's `reading-time.php`. This is an implicit dependency: if the plugin is absent, `do_shortcode('[sn_reading_time]')` returns the raw shortcode string, and `sn_notes_reading_time_for_slug()` falls back to `'5 min'` (acceptable). The dependency is not documented in `§10.0`. The theme also has a more direct path available: `sn_notes_render_reading_time()` (same file, line 73) uses `get_post_meta($post_id, $meta_key)` with a `SN_READING_TIME_META_KEY` fallback — but this path isn't used in the pillar section because the pillar helper needs a slug, not a post ID.

**Which side should win:**
The current implementation is fine — the shortcode path with '5 min' fallback is acceptable. Document the implicit dependency.

**Proposed fix:**
Add the `[sn_reading_time]` shortcode (registered by plugin `inc/reading-time.php`) to the `§10.0` "Direct dependencies kept" block in `docs/WORDPRESS-REFERENCE.md`. No code change needed.

**Risk:** low — already has a fallback; documentation gap only.

---

### X-10 [severity: low] — `sn_after_full_cache_flush` action not documented in the contract surface

**Surfaces:**
- Theme: `inc/template-maintenance.php:133` — `do_action('sn_after_full_cache_flush', $args, $cleared)`

**Issue:**
`sn_purge_all_caches()` dispatches `sn_after_full_cache_flush` as an extension hook for future modules. The plugin doesn't currently listen to it. This hook is not listed in the `§10.0` contract table and isn't mentioned anywhere in `docs/WORDPRESS-REFERENCE.md`. As an extension point, it's arguably part of the public contract surface — if the plugin ever needs to hook cache-flush completion (e.g., to invalidate its own caches), this is the right integration point, but a developer would have to read the source to discover it.

**Which side should win:**
Document in `§10.0` as an available extension hook.

**Proposed fix:**
Add a row to the `§10.0` contract table: `sn_after_full_cache_flush | action | theme: `sn_purge_all_caches()` dispatches after all clears | available for plugin extension (currently no listeners)`.

**Risk:** low — documentation gap only; no code change needed.
