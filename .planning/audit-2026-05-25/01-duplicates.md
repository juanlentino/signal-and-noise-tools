# Duplication audit — plugin (signal-and-noise-tools)

**Scanned:** 2026-05-25
**Surface:** ~93 files, ~21K LOC PHP + JS + CSS

## Summary

The biggest duplication is a `force-check-updates` logic block that exists identically in two PHP files (`inc/admin-tab-dashboard.php` and `inc/desktop-mode-integration.php`) with no shared helper. A close second is the `sn_admin_pages()` table, which was superseded by `sn_admin_top_tabs()` at v3.8.0 but is still maintained in parallel for two residual callers that could reference the new table instead. The AI-feature files show a systematic pattern of copy-pasted guard expressions that belong in the shared bootstrap.

---

## Findings

### D-01 [severity: high] — Duplicate force-check-updates transient deletion

**Files:**
- `inc/admin-tab-dashboard.php:461-466` (inside the `admin_post_sn_force_update_check` handler)
- `inc/desktop-mode-integration.php:371-375` (inside `snt_cmd_impl_force_check()`)

**What's duplicated:**
Both blocks are byte-for-byte identical:
```php
delete_site_transient( 'sn_gh_latest_theme' );
delete_site_transient( 'sn_gh_latest_plugin' );
delete_site_transient( 'update_themes' );
delete_site_transient( 'update_plugins' );
```
If a fifth transient is added (e.g. a future `sn_gh_latest_*` key), only one location gets updated. In v1.15.2 the `update_themes`/`update_plugins` keys were deliberately added; there is no guarantee a future maintainer finds both callers.

**Proposed fix:**
Extract into `snt_cmd_impl_force_check()` (which already exists in `desktop-mode-integration.php`) and call it from the `admin_post_sn_force_update_check` handler in `admin-tab-dashboard.php` instead of repeating the four deletes inline. Net change: -4 lines in `admin-tab-dashboard.php`, zero new lines.

**Risk:** low — both paths exercise today; extracting is a mechanical rename.

---

### D-02 [severity: high] — `sn_admin_pages()` maintained alongside superseded `sn_admin_top_tabs()`

**Files:**
- `inc/admin-page.php:53-72` (`sn_admin_pages()` — 12-entry legacy table)
- `inc/admin-page.php:84-167` (`sn_admin_top_tabs()` — 6-entry canonical table)

**What's duplicated:**
`sn_admin_pages()` was the original 12-tab flat registry. `sn_admin_top_tabs()` replaced it at v3.8.0. All navigation, redirect, and tab-rendering code was migrated to `sn_admin_top_tabs()`. However, `sn_admin_pages()` still has two live callers:

1. `sn_admin_page_tab_for_slug()` (line 467) — iterates `sn_admin_pages()` as a fallback after `sn_admin_top_tabs()`. This is the redirect path for legacy URL slugs like `?page=sn-login`.
2. `sn_handle_admin_post()` (line 596) — uses `array_column( sn_admin_pages(), 'slug' )` to build the allowed-pages whitelist for POST routing.

Both callers could read from `sn_admin_top_tabs()` (6 slugs) combined with the `sn_admin_legacy_redirect_map()` (which already encodes all legacy→canonical mappings). The 12-entry `sn_admin_pages()` is conceptually dead but still being maintained and read.

**Proposed fix:**
In `sn_handle_admin_post()`, replace `array_column( sn_admin_pages(), 'slug' )` with `array_column( sn_admin_top_tabs(), 'slug' )` — the POST always arrives on a canonical top-tab page now (redirects are 301 before any form renders). In `sn_admin_page_tab_for_slug()`, the fallback loop over `sn_admin_pages()` can be replaced by a lookup into `array_keys( sn_admin_legacy_redirect_map() )` which already has all 12 legacy slugs. Then `sn_admin_pages()` can be deleted (~20 lines).

**Risk:** medium — requires confirming that all POST submissions reach the handler on a canonical top-tab URL slug (they do post-v3.8.0, but worth a smoke test). If any bookmark/widget still navigates to e.g. `?page=sn-login`, the POST guard would 404-silent. Conservative: keep the function but mark it `@deprecated` and schedule deletion for the next minor.

---

### D-03 [severity: high] — `function_exists( 'snt_ai_can_text_generate' )` guard repeated in 6 impl files

**Files:**
- `inc/ai-alt-text-suggest.php:63`
- `inc/ai-alt-inline-suggest.php:120`
- `inc/ai-drift-phrase-suggest.php:90`
- `inc/ai-meta-description.php:91`
- `inc/ai-og-card-title.php:110`
- `inc/ai-excerpt.php:98`
- `inc/ai-orphan-suggest.php:60`

**What's duplicated:**
Every single AI impl function opens with the identical four-line guard:
```php
if ( ! function_exists( 'snt_ai_can_text_generate' ) || ! snt_ai_can_text_generate() ) {
    return new WP_Error(
        'snt_ai_unavailable',
        __( 'AI text generation is not available. ...', 'signal-noise-tools' ),
        array( 'status' => 503 )
    );
}
```
The `snt_ai_generate_with_constraints()` central helper in `inc/ai-bootstrap.php:123-129` already has an equivalent guard using `snt_ai_is_available()`. The per-impl guards exist as defense in depth, but they also create drift: three files use the longer human-readable message, one (`ai-drift-phrase-suggest.php:91`) has a shorter message "AI text generation is not available." — already diverged.

**Proposed fix:**
Remove the per-impl guard from each `*_impl()` function. The central `snt_ai_generate_with_constraints()` already returns `WP_Error('snt_ai_unavailable', ...)` on every call path — the caller gets the error regardless. If defense-in-depth is still wanted, consolidate into a single `snt_ai_require_text_generation()` helper in `ai-bootstrap.php` that returns `WP_Error|null` and is called once at the top of each impl. Estimated change: -7 × 4 lines = -28 lines.

**Risk:** low — the inner guard in `snt_ai_generate_with_constraints()` is unchanged and still fires. The outer guard is redundant, not load-bearing.

---

### D-04 [severity: medium] — Two parallel alt-text system-prompt constants that are near-identical

**Files:**
- `inc/ai-alt-text-suggest.php:32-40` (`SNT_AI_ALT_SUGGEST_SYSTEM`)
- `inc/ai-alt-inline-suggest.php:35-43` (`SNT_AI_ALT_INLINE_SUGGEST_SYSTEM`)

**What's duplicated:**
Both constants open with the identical core instruction:
```
"Generate descriptive alt text for an image"
"Output 80-125 characters. Describe the image factually"
"No 'image of' / 'picture of' / 'photo of' preamble."
"No alt='' (empty) suggestions — if there is not enough context..."
"output only the literal marker: ALT_INSUFFICIENT_CONTEXT."
"Output ONLY the alt text or the marker — no quotes, no preamble, no markdown."
```
The only difference is the first sentence's context (`"for an image"` vs `"for an image referenced by URL in a post body"`) and one added sentence in the inline variant (`"based on the surrounding paragraph context + the URL filename"`). The max-token constants are also identical: both `= 80`.

**Proposed fix:**
Extract a shared constant `SNT_AI_ALT_SUGGEST_SYSTEM_BASE` in `ai-bootstrap.php` containing the common core. Each variant appends its one context-specific sentence. Reduces prompt drift risk if the shared rules ever need updating (e.g. adding "no emoji"). Estimated change: -8 lines across the two files, +3 lines in `ai-bootstrap.php`.

**Risk:** low — cosmetic refactor; the prompt content is identical where it matters.

---

### D-05 [severity: medium] — `snt_cron_sn_owned_hooks()` contains hook-name strings that duplicate constants in `inc/plausible-api.php`

**Files:**
- `inc/cron-dashboard.php:39-45`
- `inc/plausible-api.php:57-58`

**What's duplicated:**
`snt_cron_sn_owned_hooks()` hardcodes the string `'sn_plausible_refresh_dashboard'` and `'sn_plausible_refresh_realtime'` by string literal. These exact hook names are already declared as typed constants `SN_PLAUSIBLE_REFRESH_BATCH_HOOK` and `SN_PLAUSIBLE_REFRESH_REALTIME_HOOK` in `plausible-api.php`. If a hook is renamed, `cron-dashboard.php` will silently stop pinning it as "SN-owned."

**Proposed fix:**
Change `snt_cron_sn_owned_hooks()` to reference the constants:
```php
return array(
    SN_PLAUSIBLE_REFRESH_BATCH_HOOK,    // was: 'sn_plausible_refresh_dashboard'
    SN_PLAUSIBLE_REFRESH_REALTIME_HOOK, // was: 'sn_plausible_refresh_realtime'
    SN_RSS_TRACKER_CRON_HOOK,           // was: 'sn_rss_tracker_daily_prune'
);
```
`SN_RSS_TRACKER_CRON_HOOK` is already defined in `inc/rss-plausible-tracker.php:34`. All three constants are loaded before `cron-dashboard.php` via the plugin bootstrap. Net change: 3 string literals → 3 constant references.

**Risk:** low — constants are defined at module scope; load order in the plugin bootstrap guarantees they're available.

---

### D-06 [severity: medium] — `admin-page.php` directly calls `get_option('sn_settings')` bypassing `sn_setting()` accessor

**Files:**
- `inc/admin-page.php:623-629` (login save handler)
- `inc/admin-page.php:755-761` (insights settings save)
- `inc/settings.php:80-97` (`sn_setting()` canonical accessor)

**What's duplicated:**
The `sn_setting()` helper in `settings.php` provides a static-cached, dot-path, defaults-merged accessor. Two sections of `sn_handle_admin_post()` in `admin-page.php` read/write `sn_settings` directly via `get_option()` / `update_option()`. Direct access bypasses the static cache (so the next `sn_setting()` call reads the old merged copy until the cache is manually invalidated), and it bypasses the defaults schema.

The insights save at line 755 partially reads settings, mutates one key, and writes back — it must read the raw option since `sn_setting()` is read-only. But it could use `SN_SETTINGS_OPTION` (the constant from `settings.php`) instead of the bare string `'sn_settings'` for consistency. The login save at 623 also reads the full option array to update only the `login.slug` key.

**Proposed fix:**
1. Use `SN_SETTINGS_OPTION` constant everywhere instead of the literal `'sn_settings'` string (single-line change in each location).
2. After any `update_option( SN_SETTINGS_OPTION, ... )` call in `admin-page.php`, reset the `sn_setting()` static cache by adding a `sn_setting_reset_cache()` helper in `settings.php` (2 lines). This prevents the current silent bug where saving login slug during a request leaves a stale merged array in the static cache for any subsequent `sn_setting('login.slug')` call in that same request.

**Risk:** medium — the static cache bug is latent (login and insights settings saves are POST-redirect-GET so the stale cache never matters in practice today). But it's a footgun for future code.

---

### D-07 [severity: medium] — `sn_purge_all_caches_result` filter dispatched with subtly-different `$args` from 5 callsites

**Files:**
- `inc/admin-page.php:610` — `array( 'template_overrides' => false )` (purge_caches action)
- `inc/admin-page.php:613` — `array()` (full_reset action — note: empty array differs from `array( 'template_overrides' => true )`)
- `inc/admin-bar.php:148` — `array( 'template_overrides' => false )`
- `inc/rest-api.php:222` — `array( 'template_overrides' => false )` (purge-cache endpoint)
- `inc/rest-api.php:256` — `array( 'template_overrides' => false )` (full-reset endpoint — note: this endpoint separately calls `sn_clear_template_overrides_result`, so passing `false` here is intentional but silently different from the admin-page full_reset path)
- `inc/desktop-mode-integration.php:383` — `array( 'template_overrides' => ... )` (correct)
- `inc/abilities-registration.php:1240` — `array( 'template_overrides' => $include_overrides )` (correct)

**What's duplicated / diverged:**
The `full_reset` handler in `admin-page.php:613` passes `array()` (empty), while the REST `/full-reset` handler at `rest-api.php:256` passes `array( 'template_overrides' => false )` and separately fires `sn_clear_template_overrides_result`. Both claim to do a "full reset" but do it differently. The theme's filter listener may or may not interpret an empty `$args` array the same as `array( 'template_overrides' => true )` — undefined behavior at the call site.

**Proposed fix:**
Normalize `full_reset` in `admin-page.php:613` to `array( 'template_overrides' => true )` to be explicit. Add a clarifying comment. This is a 1-line change. Does not change the REST endpoint (its two-step behavior is intentional and documented).

**Risk:** low — the theme-side filter listener should handle both, but making intent explicit prevents future bugs.

---

### D-08 [severity: medium] — `snDesktopData` localized twice for two scripts that share the same window global

**Files:**
- `inc/desktop-mode-integration.php:113` — `wp_localize_script( 'sn-desktop-mode', 'snDesktopData', $shared )`
- `inc/desktop-mode-integration.php:114` — `wp_localize_script( 'sn-desktop-mode-widget', 'snDesktopData', $shared )`

**What's duplicated:**
`wp_localize_script()` outputs a `<script>var snDesktopData = {...};</script>` inline block before the enqueued script. When both scripts are enqueued on the same page, the same JSON object is emitted twice into the HTML, doubling the payload. Both scripts read from `window.snDesktopData` — they don't need two copies.

**Proposed fix:**
Localize to only `'sn-desktop-mode'` (the first script, lower sort order). `sn-desktop-mode-widget` depends on `sn-desktop-mode` (transitively, both depend on `wp-api-fetch`), so the global will be set by the time the widget script runs. Remove the second `wp_localize_script()` call. Net change: -1 line.

**Risk:** low — both scripts read from `window.snDesktopData` which is global. Provided `sn-desktop-mode` loads first (which it will, since widget registration happens after commands registration), the single localization is sufficient.

---

### D-09 [severity: medium] — Two drift-detection AI system prompts for related but distinct tasks in separate files

**Files:**
- `inc/health-checks.php:39-48` (`SNT_AI_DRIFT_SYSTEM` — batch classification: is this phrase stale?)
- `inc/ai-drift-phrase-suggest.php:32-43` (`SNT_AI_DRIFT_SUGGEST_SYSTEM` — single phrase: what should replace it?)

**What's duplicated:**
These are conceptually two halves of the same "drift" workflow: the health scan classifies phrases, and the suggest module generates replacements. They are correctly separate prompts solving different problems. However, they share domain vocabulary that could drift independently — e.g., the list of time-relative phrases that count as "stale" is implicit in `SNT_AI_DRIFT_SYSTEM` but not cross-referenced in `SNT_AI_DRIFT_SUGGEST_SYSTEM`. If one prompt's criteria change, the other won't update automatically.

This is a **low**-severity documentation/convention issue, not a true code duplication. No code runs from both constants together.

**Proposed fix:**
Add a cross-reference comment in each constant's docblock pointing to the sibling. No code change. 2 comment lines.

**Risk:** none — documentation only.

---

### D-10 [severity: low] — `trim( $suggestion, "\"'" )` pattern copy-pasted in 3 AI impl files

**Files:**
- `inc/ai-alt-text-suggest.php:141`
- `inc/ai-alt-inline-suggest.php:178`
- `inc/ai-drift-phrase-suggest.php:132`

**What's duplicated:**
All three files strip surrounding quotes from the AI suggestion string with the same one-liner after calling `snt_ai_generate_with_constraints()`. This is a post-processing convention that could be centralized inside `snt_ai_generate_with_constraints()` (since the system prompt for every caller says "no quotes"), or extracted as a `snt_ai_strip_surrounding_quotes( $text )` helper.

`ai-orphan-suggest.php` does NOT do this because it parses JSON instead (correctly), and `ai-meta-description.php` also does it but uses `trim( $description, "\"'" )` on line 124 (same pattern, different variable name).

**Proposed fix:**
Move the trim into `snt_ai_generate_with_constraints()` return path, or extract `snt_ai_strip_surrounding_quotes()` helper. The former is simpler but may affect callers that expect raw output (currently none do). Estimated change: +2 lines in `ai-bootstrap.php`, -4 lines across the 4 call sites.

**Risk:** low — the trim is idempotent on text without surrounding quotes.

---

### D-11 [severity: low] — `cron-dashboard-admin.php` JS guard uses `strpos( $hook_suffix, 'sn-cron' )` instead of the established `sn_admin_page_hooks()` pattern

**Files:**
- `inc/cron-dashboard-admin.php:19-22`
- `inc/admin-page.php:532-534`

**What's duplicated:**
`admin-page.php` establishes `sn_admin_page_hooks()` as the canonical pattern for guarding admin-page enqueues: `if ( ! in_array( $hook, sn_admin_page_hooks(), true ) )`. `cron-dashboard-admin.php` uses a fragile `strpos( $hook_suffix, 'sn-cron' )` string-search instead. The comment on line 20 even acknowledges this with "like 'signal-noise_page_sn-cron'" — the hook suffix format is WP-internal and could change.

**Proposed fix:**
Replace the `strpos` guard in `cron-dashboard-admin.php` with `in_array( $hook_suffix, sn_admin_page_hooks(), true )`. The cron JS only needs to load on SN admin pages anyway — loading on the specific cron page is a marginal optimization that adds fragility. Net change: 1 line.

**Risk:** low — minimal change. The JS would load on all SN admin pages instead of only the cron sub-page, but it's small (366 lines) and won't execute unless the cron table is present in the DOM.

---

### D-12 [severity: low] — `ai-og-card-title.php` uses `snt_ai_is_available()` in enqueue guard; sibling files use `snt_ai_can_text_generate()`

**Files:**
- `inc/ai-og-card-title.php:202` — `if ( ! function_exists( 'snt_ai_is_available' ) || ! snt_ai_is_available() )`
- `inc/ai-excerpt.php:162` — same pattern
- `inc/ai-alt-text-suggest.php:63` — uses `snt_ai_can_text_generate()` directly
- `inc/ai-drift-phrase-suggest.php:90` — uses `snt_ai_can_text_generate()` directly

**What's duplicated:**
`snt_ai_is_available()` is documented as a back-compat alias for `snt_ai_can_text_generate()` (see `ai-bootstrap.php:95-102`). Both return the same value. But some enqueue guards use the alias and some use the canonical function — minor inconsistency that creates confusion about which is authoritative.

**Proposed fix:**
Standardize all call sites to use `snt_ai_is_available()` (the alias) in enqueue guards — it reads better as "is AI available for this feature?" — and `snt_ai_can_text_generate()` in impl functions where the technical name is more precise. Document the convention in a comment in `ai-bootstrap.php`. No functional change.

**Risk:** none — both functions return identical values.

---

### D-13 [severity: low] — `desktop-mode-integration.php` has 3 separate `admin_enqueue_scripts` hooks where 1 or 2 would do

**Files:**
- `inc/desktop-mode-integration.php:53` — registers 4 scripts + localizes 2
- `inc/desktop-mode-integration.php:256` — registers commands via `desktop_mode_register_command()`
- `inc/desktop-mode-integration.php:332` — registers widgets via `desktop_mode_register_widget()`

**What's duplicated:**
All three hooks share the same guard: `if ( ! function_exists( 'desktop_mode_register_command' ) ) { return; }`. The functions registered in hooks 2 and 3 (`desktop_mode_register_command`, `desktop_mode_register_widget`) are guaranteed to exist if hook 1's guard passes. Splitting into 3 hooks adds no isolation benefit — they're in the same file, same class of concern.

**Proposed fix:**
Merge the 3 hooks into 1 (or at most 2: script registration + widget/command registration). Reduces PHP hook dispatch overhead by 2 `add_action` registrations. Estimated change: -10 boilerplate lines.

**Risk:** low — pure code organization. The merged hook fires at the same priority.
