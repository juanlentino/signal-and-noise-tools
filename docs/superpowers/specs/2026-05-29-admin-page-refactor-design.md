# v4.5.3 design — Split `inc/admin-page.php` into handler + flash-data + form modules

**Date:** 2026-05-29
**Target version:** plugin v4.5.3 (PATCH — pure behavior-preserving refactor, no functional change)
**Status:** Approved via brainstorming session; ready for writing-plans
**Author intent:** dissolve the 1,468-line `inc/admin-page.php` monolith (flagged HIGH in the 2026-05-29 full-codebase QA audit — ~10× the project's ~150-line convention, 2× the next-biggest file) into focused, individually testable modules, **changing zero behavior**.

---

## TL;DR

`inc/admin-page.php` welds three responsibilities the audit named:

1. **`sn_handle_admin_post()`** (~lines 633–901, ~270 lines) — a 22-branch `if/elseif` form-action dispatcher on `admin_init` (Post/Redirect/Get).
2. **The flash-code → admin-notice translator** inside `sn_theme_options_page()` (~lines 941–1025, ~85 lines) — a SECOND `if/elseif` over the flash vocabulary the dispatcher produces, maintained ~40 lines away. Adding an action means editing both ladders by hand (the file's own history shows `block_migrations_scan`/`block_migrations_scanned` added to both).
3. **Inline-HTML `echo` walls** in the rest of `sn_theme_options_page()` (~lines 1095–1462) — the Identity/Social/OG/SEO-Copy form (~140 lines), the Login section (~90 lines), and the Links grid (~37 lines), none unit-testable.

The split produces **10 new files** (loaded via the existing flat `require_once` manifest in `signal-and-noise-tools.php`) and reduces `admin-page.php` to a **~170-line orchestrator**. Behavior is preserved exactly: every action, nonce check, capability gate, sanitize/unslash, flash message, redirect, and tab renders identically. Baseline **945 passed / 0 failed across 27 suites** must hold (plus new tests for the extracted handlers/flash).

---

## Problem statement

`admin-page.php` is the plugin's only remaining monolith. The three welded responsibilities make it the highest-friction file to change:

- The **two-ladder sync hazard** is the worst: a new form action requires a branch in the dispatcher (`if 'block_migrations_scan' === $action`) AND a matching branch in the flash translator (`if 'block_migrations_scanned' === $flash`), ~80 lines apart, hand-kept in sync. This is the same *class* of bug the v3.0.0 → v3.0.2 regression fixed for tab whitelists (and which `tests/admin-tabs.php` now guards). The flash ladder has no such guard.
- The dispatcher's 22 branches are **untestable** — there is currently zero unit coverage of any form-action handler (`grep` confirms no test references `sn_action`/`sn_flash`). The only adjacent test, `tests/audit-retention-bounds.php`, *replicates* the clamp formula and then greps source to confirm the real one matches — a brittle proxy for a behavioral test.
- The inline form HTML buries escaping inside string concatenation and can't be exercised without a full request.

This refactor is **pure structure**. No user-visible behavior, settings schema, URL, nonce, or flash message changes. Version bump is PATCH.

---

## Approach chosen

Per brainstorming, locked decisions:

| Q | Decision |
|---|---|
| Include strategy | **Flat `require_once` manifest in `signal-and-noise-tools.php`** (matches the existing 67-module convention; most discoverable). NOT nested requires inside `admin-page.php`. |
| Handler placement | **Dedicated `inc/admin-post-*.php` files**, NOT scattered into owning modules (lower regression surface; keeps dispatch readable; matches the audit recommendation). |
| Form extraction | **Direct render functions in `inc/admin-forms/*.php`** (sanctioned by the task; these forms are not pluggable extension points). The `<form>`/nonce/savebar wrapper moves *with* the identity form. |
| Field rewrite | **Faithful echo-for-echo move** — NOT a field-spec/`sn_admin_render_field()` rewrite. A clever renderer increases regression risk on a behavior-preserving pass; deferred as a future improvement. |
| Actions file size | **One cohesive `admin-post-actions.php` (~195 lines)** of 22 atomic handlers (approved). |

---

## Section 1 — Architecture + files touched

### New files (all `require_once`'d in `signal-and-noise-tools.php` immediately after `inc/admin-page.php`, line 74)

| File | Functions moved (verbatim unless noted) | ~Lines |
|---|---|---|
| `inc/admin-tabs-data.php` | `sn_admin_top_tabs()` | ~95 |
| `inc/admin-tabs.php` | `sn_admin_page_valid_tabs()`, `sn_admin_page_tab_labels()`, `sn_admin_page_subtitle_for_tab()`, `sn_admin_get_sub_tabs()`, `sn_admin_resolve_active_sub()`, `sn_admin_render_toc()`, `sn_admin_render_sub_tabs()`, `sn_admin_render_section()` | ~135 |
| `inc/admin-legacy-redirect.php` | `sn_admin_pages()` (keep `@internal` docblock framing), `sn_admin_legacy_redirect_map()`, `sn_admin_page_tab_for_slug()`, `sn_admin_maybe_redirect_legacy()` | ~160 |
| `inc/admin-menu.php` | `sn_admin_page_hooks()`, the `admin_menu` registration closure, the `admin_enqueue_scripts` enqueue closure | ~145 |
| `inc/admin-flash-messages.php` | **NEW** `sn_admin_flash_messages()` (static map) + `sn_admin_flash_to_notice( $flash )` resolver | ~115 |
| `inc/admin-post-handler.php` | `add_action( 'admin_init', 'sn_handle_admin_post' )`, slimmed `sn_handle_admin_post()`, **NEW** `sn_admin_post_handlers()` map | ~130 |
| `inc/admin-post-actions.php` | **NEW** 22 per-action handler functions `sn_handle_<action>( array $post ): string` | ~195 |
| `inc/admin-forms/identity-and-seo.php` | **NEW** `sn_admin_render_identity_and_seo_form()` + 4 section field-renderers | ~165 |
| `inc/admin-forms/login.php` | **NEW** `sn_admin_render_login_section()` | ~100 |
| `inc/admin-forms/links.php` | **NEW** `sn_admin_render_links_section()` | ~50 |

### Modified files

- **`signal-and-noise-tools.php`** — add 10 `require_once` lines after line 74, in a commented group ("Admin UI — split out of admin-page.php in v4.5.3"). Order among them is irrelevant (all cross-calls are runtime, inside hooks). Bootstrap order, guard blocks, and `SNT_VERSION` derivation (`get_file_data`) are untouched.
- **`inc/admin-page.php`** — reduced to docblock + `sn_theme_options_page()` orchestrator (~170 lines). Keeps: cap check, `sn_admin_maybe_redirect_legacy()` call, tab resolution, the flash→notice **loop** (consuming the resolver), the webhook `new_id` `$_GET` massaging (10 lines, request-state plumbing), the page shell (wrap/h1/subtitle/notices), the top-tab nav, and the `$active_tab` router (each arm calls a render fn or `do_action`).

### Files NOT touched (verified)

- `inc/settings.php` — `sn_settings_save()`'s `login`/`audit` subtree preservation on whole-option replace stays exactly as-is. `save_identity` still calls `sn_settings_save( $_POST )`.
- The 67-module bootstrap order, guards #1/#2/#3, `SNT_VERSION`.
- `tests/contracts-stub.php` — only *mentions* `admin-page.php` in comments; no require. The `sn_purge_all_caches_result` / `sn_clear_template_overrides_result` filter dispatches move with `full_reset`/`clear_overrides`/`purge_caches` handlers into `admin-post-actions.php`; this test stubs the filter system independently and is unaffected.

### Loading correctness (WordPress)

Hook *registration* order is irrelevant — `add_action('admin_init'|'admin_menu'|'admin_enqueue_scripts', …)` only register callbacks during plugin load; WP fires them later. Every `sn_admin_*` call happens inside a hook/function (never at file top-level), so function availability at call time is guaranteed once the flat manifest has run. `require_once` prevents double-declaration.

---

## Section 2 — Responsibility #1: the dispatcher

`sn_handle_admin_post()` (in `inc/admin-post-handler.php`) keeps its guards and redirect **verbatim**:

- `isset( $_POST['sn_action'] )` early return
- `current_user_can( 'manage_options' )` gate
- page-slug allowlist: `in_array( $current_page, array_column( sn_admin_pages(), 'slug' ), true )`
- `check_admin_referer( 'sn_theme_options_nonce' )`
- the full redirect block (canonical top-tab + `&sub=` + `#sn-sec-` anchor, raw `header( 'Location:', 302 )`)

The 22-branch `if/elseif` is replaced by:

```php
$action   = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );
$handlers = sn_admin_post_handlers();
if ( ! isset( $handlers[ $action ] ) ) {
    return;                                  // unknown action — same as the old trailing `else { return; }`
}
$flash = (string) call_user_func( $handlers[ $action ], $_POST );   // RAW $_POST — see contract below
```

`sn_admin_post_handlers()` returns the action→callback map:

```php
return array(
    'clear_overrides'            => 'sn_handle_clear_overrides',
    'purge_caches'               => 'sn_handle_purge_caches',
    'full_reset'                 => 'sn_handle_full_reset',
    'save_identity'              => 'sn_handle_save_identity',
    'save_login'                 => 'sn_handle_save_login',
    'pl_save'                    => 'sn_handle_pl_save',
    'pl_test'                    => 'sn_handle_pl_test',
    'cf_save'                    => 'sn_handle_cf_save',
    'cf_purge_now'               => 'sn_handle_cf_purge_now',
    'apply_reading_time_cleanup' => 'sn_handle_apply_reading_time_cleanup',
    'health_scan'                => 'sn_handle_health_scan',
    'webhook_add'                => 'sn_handle_webhook_add',
    'webhook_update'             => 'sn_handle_webhook_update',
    'webhook_delete'             => 'sn_handle_webhook_delete',
    'insights_run'               => 'sn_handle_insights_run',
    'insights_dismiss'           => 'sn_handle_insights_dismiss',
    'insights_snooze'            => 'sn_handle_insights_snooze',
    'insights_mark_done'         => 'sn_handle_insights_mark_done',
    'save_insights_settings'     => 'sn_handle_save_insights_settings',
    'audit_save_retention'       => 'sn_handle_audit_save_retention',
    'pattern_adoption_scan'      => 'sn_handle_pattern_adoption_scan',
    'block_migrations_scan'      => 'sn_handle_block_migrations_scan',
);
```

**Handler contract:** `sn_handle_<action>( array $post ): string` — reads/sanitizes from `$post`, performs the same side effects (option writes, filter dispatch, module calls), and **returns the flash code string**. No handler echoes. The dispatcher passes **raw `$_POST`** as `$post`; **each handler performs its own unslashing exactly as its old arm did** — `sn_handle_save_identity()` calls `sn_settings_save( $post )` on the raw array (preserving the original slashed pass-through — do NOT "fix" it), the `webhook_*` handlers call `wp_unslash( $post )`, and field-readers call `wp_unslash( $post['field'] )` per field. Each handler's body is a verbatim lift of its old `if/elseif` arm with `$flash = …` replaced by `return …` (substituting `$post` for `$_POST`).

**Critical fidelity notes (carry verbatim):**
- `full_reset` passes `array( 'template_overrides' => true )` (the D-07 explicitness).
- `pl_save` keeps the `SN_PLAUSIBLE_STATS_TOKEN` constant short-circuit and the `'••••'` obscured-placeholder leave-alone branch.
- `cf_save` keeps the `SN_CLOUDFLARE_API_TOKEN`/`SN_CLOUDFLARE_ZONE_ID` constant locks and the `'clear'`/placeholder/empty branches for both token and zone; always returns `'cf_saved'`.
- `save_login` keeps the empty-slug guard and `sn_setting_update( 'login.slug', … )` write.
- `audit_save_retention` keeps `max( 7, min( 365, $raw ) )`.
- `webhook_add`/`webhook_update` keep the `is_wp_error` → `wh_invalid`/`wh_not_found` mapping and encode the id into `wh_added_<id>`/`wh_rotated_<id>`.
- All `function_exists()` guards on module calls are preserved.

---

## Section 3 — Responsibility #2: the flash registry

`inc/admin-flash-messages.php` exposes two functions.

`sn_admin_flash_messages()` — the shared **static** map (single source of truth), e.g.:

```php
return array(
    'identity_saved'      => array( 'success', 'Identity settings saved.' ),
    'identity_unchanged'  => array( 'info',    'No changes to save.' ),
    'login_empty'         => array( 'error',   'Login slug cannot be empty.' ),
    // … all exact-match codes, severities + messages copied verbatim
    'block_migrations_scanned' => array( 'success', 'Block migration scan complete.' ),
);
```

`sn_admin_flash_to_notice( $flash )` — returns `array( $severity, $message_html )` or `null`. Resolution order (handles the **three message shapes** in the original ladder):

1. **Exact static** — `if ( isset( $static[ $flash ] ) ) return $static[ $flash ];` (~28 codes).
2. **Live-data codes** (computed at render time, NOT static):
   - `login_saved` → reads `sn_setting( 'login.slug', 'sn-login' )`, builds `home_url( '/' . $slug )`, returns the `<a>`-linked success message.
   - `pl_test_ok` → reads `get_transient( SN_PLAUSIBLE_BATCH_KEY )` for the visitor count, returns the `&#10003;` success message.
   - `pl_test_err` → reads `sn_plausible_last_error()`, returns the `&#10005;` error detail.
3. **Count-prefixed codes** (`sprintf` on the parsed int):
   - `rt_applied_<n>` → `'%d post(s) cleaned. Reading-time cache rebuilt.'`
   - `cleared_<n>` → `'%d database override(s) cleared. Site is reading from theme files.'`
   - `reset_<n>` → `'Full reset: %d override(s) cleared + all caches purged.'`
4. **Id-prefixed codes** (static message; id consumed elsewhere for row highlight):
   - `wh_added_<id>` → the "Webhook added. Copy the signing secret…" message.
   - `wh_rotated_<id>` → the "Signing secret was rotated…" message.
5. **Fallback** — `return null;` (renders no notice, matching the old "no matching branch" behavior).

**Renderer change** in `sn_theme_options_page()` — the ~85-line `if/elseif` becomes:

```php
if ( isset( $_GET['sn_flash'] ) ) {
    $notice = sn_admin_flash_to_notice( sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) ) );
    if ( $notice ) {
        $notices[] = $notice;
    }
}
```

The downstream notices loop (`wp_kses_post` on the body, `esc_attr` on severity) is unchanged, so inline `<a>`/`<code>`/`<strong>` markup in messages still renders.

**Exactness requirement:** every severity string (`success`/`info`/`error`/`warning`) and every message — including HTML entities (`&#10003;`, `&#10005;`, `&mdash;`, `&middot;`), `<a>`/`<code>`/`<strong>` tags, and the `cf_purged_unconfigured` = `warning` severity — must be copied byte-for-byte.

---

## Section 4 — Responsibility #3: the form partials

Faithful echo-for-echo lifts. Escaping (`esc_attr`/`esc_html`/`esc_textarea`/`esc_url`), `sn_setting()` default values, `name=` attributes, anchor IDs, ARIA labels, `<noscript>` rows, and helper copy are preserved exactly.

- **`inc/admin-forms/identity-and-seo.php`** — `sn_admin_render_identity_and_seo_form()` emits the `<form class="sn-identity-form">`, `wp_nonce_field( 'sn_theme_options_nonce' )`, `<input hidden sn_action="save_identity">`, the four `sn_admin_render_section( '<slug>', … )` calls (each field body becomes a named function — `sn_admin_render_identity_fields()`, `_social_fields()`, `_open_graph_fields()`, `_seo_copy_fields()` — passed as the section callback), and the sticky savebar. The site-tab `else` branch in the orchestrator becomes:
  ```php
  sn_admin_render_toc( 'site', 'identity-and-seo' );
  sn_admin_render_identity_and_seo_form();
  ```
- **`inc/admin-forms/login.php`** — `sn_admin_render_login_section()`: the module-state detection (wps-hide-login / `SN_LOGIN_BYPASS` / `SN_LOGIN_SLUG`), the status box, the slug form (with the constant-locked disabled variant), and the emergency-unlock `<pre>` block — verbatim. Called via `sn_admin_render_section( 'login', 'sn_admin_render_login_section' )`.
- **`inc/admin-forms/links.php`** — `sn_admin_render_links_section()`: the `$link_groups` array + `.sn-link-grid` render — verbatim. Called via `sn_admin_render_section( 'links', 'sn_admin_render_links_section' )`.

The `audit-log` section still uses `sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' )` (module-owned, unchanged). All `do_action('sn_admin_*_tab')` hook arms (cloudflare, cron, webhooks, insights, health, plausible, rss, block-migrations, reading-time) stay in the orchestrator's router unchanged.

---

## Section 5 — Test plan

Baseline: **945 passed / 0 failed across 27 suites.** Target: **≥945 passed / 0 failed, 0 failing suites** (higher with new tests).

### Update 3 (behavior-preserving re-point)

| Test | Current coupling | Change |
|---|---|---|
| `tests/admin-tabs.php` | `require_once .../inc/admin-page.php` then calls top_tabs / pages / legacy_redirect_map / valid_tabs / tab_labels / subtitle / tab_for_slug | Replace the single require with requires of `inc/admin-tabs-data.php`, `inc/admin-tabs.php`, `inc/admin-legacy-redirect.php` (where those functions now live). Assertions unchanged. |
| `tests/legacy-url-redirect.php` | `file_get_contents( '.../admin-page.php' )` + greps for `function sn_admin_maybe_redirect_legacy`, `$_GET['page']`, the canonical-URL string, `'login'`, `'rss'`, `sn_admin_pages` framing | Point `$path` at `inc/admin-legacy-redirect.php` — all 6 needles live there by design. Assertions unchanged. |
| `tests/audit-retention-bounds.php` | `file_get_contents( '.../admin-page.php' )` + `strpos( …, "max( 7, min( 365" )` | Point the source-grep at `inc/admin-post-actions.php`, AND upgrade: require `admin-post-actions.php` + `settings.php` + option stubs, then call `sn_handle_audit_save_retention()` directly and assert the stored value is clamped + the returned flash. Keeps the 7 clamp-math asserts; replaces the brittle proxy with real behavior. |

### Add 2 (new coverage — currently zero on dispatcher/flash)

- **`tests/admin-flash-messages.php`** — require `inc/admin-flash-messages.php` + minimal stubs (`sn_setting`, `home_url`, `esc_url`, `esc_html`, `get_transient`, `sn_plausible_last_error`, `number_format_i18n`, sanitizers). Assert: static codes resolve to exact `[severity,message]`; `cleared_5`/`reset_3`/`rt_applied_12` parse the int and `sprintf`; `wh_added_<id>`/`wh_rotated_<id>` resolve to their static messages; `login_saved`/`pl_test_ok`/`pl_test_err` compute via stubs; unknown code → `null`. **Coordination guard:** every flash code the actions can emit has a resolver branch (mirrors `admin-tabs.php`'s anti-drift philosophy).
- **`tests/admin-post-actions.php`** — require `inc/admin-post-actions.php` + `inc/settings.php` + WP stubs (`get_option`/`update_option`/`delete_option`, `sanitize_*`, `wp_unslash`, `apply_filters`). Assert handler returns + side effects:
  - `sn_handle_save_login( array() )` → `'login_empty'`; `sn_handle_save_login( array( 'login_slug' => 'secret-door' ) )` → `'login_saved'` and `sn_setting('login.slug')` updated.
  - `sn_handle_audit_save_retention( array( 'audit_retention_days' => 999 ) )` → stored `365`; `2` → `7`; `90` → `90`.
  - `sn_handle_save_identity()` → `'identity_saved'`/`'identity_unchanged'` per `sn_settings_save` return.
  - `sn_handle_cf_save()` with a defined `SN_CLOUDFLARE_API_TOKEN` constant skips the token write (constant-lock).
  - `sn_handle_pl_save()` clear / placeholder-leave-alone / save branches.

Optional: a tiny `sn_admin_post_handlers()` shape assert (every value is `callable`) can live in `admin-post-actions.php`'s test.

### Run command (before AND after)

```bash
for f in tests/*.php; do php "$f" 2>&1 | tail -1; done
```

(Plus the aggregate tally script used to establish the 945/0 baseline.)

---

## Section 6 — Verification gates

1. `php -l` clean on all 10 new files + the 2 modified PHP files (no parse errors).
2. Full suite: ≥945 passed / 0 failed, 0 failing suites.
3. `git grep -n` confirms NO orphaned reference to a moved function in `admin-page.php` (i.e., it no longer *defines* them, only *calls* them).
4. `admin-page.php` line count < 300 (target ~170).
5. Each new file: file docblock present, `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard, 100% tab indentation, `sn_`/`snt_` prefixes matching current usage.
6. Spot-diff the rendered HTML mentally: the four flash message shapes, the constant-locked Login/Plausible/Cloudflare fields, and the identity form `name=` attributes match the originals.

---

## Section 7 — Edge cases + risks

- **Source-grep tests are the main trap.** `legacy-url-redirect.php` and `audit-retention-bounds.php` assert against file *contents*, so moved code breaks them regardless of include wiring. Mitigation: Section 5 re-points each at the file the code moved to (grouping chosen so each test's needles stay in ONE file).
- **Unslash fidelity (the sharpest trap).** The original arms are *inconsistent*: `save_identity` passes RAW (slashed) `$_POST` to `sn_settings_save()`, while `webhook_*` pass `wp_unslash( $_POST )`, and field-readers unslash per field. The dispatcher therefore passes **raw `$_POST`** to every handler, and each handler reproduces its original unslashing verbatim. A blanket "unslash once" would change how `save_identity` stores apostrophes/quotes — a behavior change disguised as cleanup. Gate: diff each handler's unslash calls against the original arm.
- **Flash message byte-fidelity.** HTML entities and inline tags must be copied exactly; a single changed character is a visible regression. Gate 6 + the new flash test cover this.
- **`desktop-mode-integration.php` calls `sn_admin_top_tabs()`** and `audit-log-admin.php`/`cron-dashboard-admin.php` call `sn_admin_render_section()`/`sn_admin_page_hooks()` at runtime — all remain globally available via the flat manifest. No change needed in those callers.
- **`save_identity` subtree preservation** lives in `settings.php` (untouched); the handler still calls `sn_settings_save()`. `tests/settings-save-preserves-subtrees.php` continues to guard it.

---

## Out of scope (explicitly)

- Switching to WP-native `admin_post_{$action}` hooks or the Settings API (behavior change — different URLs/nonces/notices).
- A field-spec `sn_admin_render_field()` renderer (clever rewrite; deferred).
- Scattering handlers into their owning subsystem modules.
- Any CSS, copy, URL, settings-schema, or flash-message wording change.
- Touching the bootstrap guard blocks or `SNT_VERSION` derivation.

---

## References

- 2026-05-29 full-codebase QA audit (HIGH: `admin-page.php` size + welded responsibilities).
- `inc/admin-page.php` (current, 1,468 lines) — source of all moved code.
- `inc/settings.php` `sn_settings_save()` — subtree preservation contract (v4.5.2).
- `tests/admin-tabs.php` — the anti-drift philosophy the new flash coordination guard mirrors.
- CLAUDE.md — ~150-line file convention, "shared data in separate files", tab indentation, native-WP admin styling, Mimestream CHANGELOG headers, SemVer + release workflow.

---

## What writing-plans should produce

An ordered, atomically-committable plan. Suggested sequence (each step ends green):

1. **Extract pure data/framework** (`admin-tabs-data.php`, `admin-tabs.php`, `admin-legacy-redirect.php`, `admin-menu.php`) + wire requires; re-point `admin-tabs.php` and `legacy-url-redirect.php` tests. Run suite.
2. **Extract flash registry** (`admin-flash-messages.php`) + swap the renderer loop; add `tests/admin-flash-messages.php`. Run suite.
3. **Extract dispatcher + actions** (`admin-post-handler.php`, `admin-post-actions.php`) + swap `sn_handle_admin_post()`; re-point + upgrade `audit-retention-bounds.php`; add `tests/admin-post-actions.php`. Run suite.
4. **Extract form partials** (`admin-forms/identity-and-seo.php`, `login.php`, `links.php`) + swap the router arms. Run suite.
5. **Verify gates** (Section 6), bump docblock → 4.5.3, CHANGELOG `### Cleanup` entry, commit, tag, push per CLAUDE.md release workflow. No auto-deploy.

TDD note: for the NEW handler/flash functions, write the test first (red), then move the code (green). For the verbatim *moves* of existing tested code, the existing suite IS the regression net — keep it green at every step.
