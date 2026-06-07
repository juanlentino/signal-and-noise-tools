# B2 — Editor UX + frugal AI (plugin v4.11.0) — Design Spec

**Track B bundle B2** of the [upgrade-opportunities roadmap](2026-06-06-upgrade-opportunities-roadmap.md). Five additive items. All MINOR → **v4.11.0**. Grounded against the real v4.10.1 code (workflow `wff02fw1h`, 2026-06-07); every item verified SN-owned (no `ai/ai` or desktop-mode overlap).

**Frugality:** items 1 + 5 add **zero** new AI calls; items 2 + 3 are pure client-side JS (no network); only item 4 makes a new AI call (on-demand, input-capped). Whole-bundle annual cost delta ≈ the roadmap's ~$0.42/yr claim — verified against the `wp-ai-client` model pin (`claude-sonnet-4-6`) + token math.

**Build rules:** TDD per item (pure-PHP CLI harness; JS items are PHP-wiring-tested + a documented manual-UAT checklist). Commit-per-item. Read the REAL source before each edit. Settings additions migration-free + a `sn_settings_save()` preservation guard for any new subtree. No new dashboard widget / no new top-level admin-bar node (editor surfaces + existing-tab additions are allowed).

---

## Item 1 — Body-ground the Insights advisor (S · zero new AI calls)
**Goal:** upgrade the weekly Content Opportunity Advisor from metadata-only to content-aware by feeding bounded excerpts of the top posts into the **existing** weekly call.

**Approach** (entirely inside `inc/insights.php`):
- Add consts near the others (insights.php:32-36): `SN_INSIGHTS_EXCERPT_CAP = 25`, `SN_INSIGHTS_EXCERPT_WORDS = 120`, and a total-payload ceiling `SN_INSIGHTS_EXCERPT_TOTAL_CHARS` (e.g. 60000) as a hard backstop.
- In `snt_insights_collect_signals()`, **after** the existing `views_7d`-desc / `days_since_publish`-asc sort + `SN_INSIGHTS_POST_CAP` slice (insights.php:143-151), iterate the first `SN_INSIGHTS_EXCERPT_CAP` posts and attach an `excerpt` field. Source preference: author `post_excerpt` (trimmed; treat whitespace-only as empty) → fall back to `snt_ai_extract_post_text($id, SN_INSIGHTS_EXCERPT_WORDS)` (ai-bootstrap.php:267-280, the canonical body-bounding helper). Enforce BOTH the per-excerpt word cap AND the running total-chars ceiling (stop attaching once exceeded).
- One sentence added to `snt_insights_system_instruction()` (insights.php:242-267) telling the model the top posts now include a content excerpt — keep minimal so the 5-rec output shape + `snt_insights_parse_response` validation are unaffected.
- Optional: `excerpts_count` in `signal_summary` (insights.php:455-459) for observability.

**Locked decisions:** top-25 via the existing sort (the posts that matter); 120 words/excerpt; prefer `post_excerpt` then body; two-layer cap. No new call/cron/admin surface.

**Test** (`tests/insights.php`): add `strip_shortcodes` / `wp_strip_all_tags` / `wp_trim_words` stubs + `post_content` (and some `post_excerpt`) on the fixture posts. Assert: top-25 carry excerpts; posts 26+ do not; whitespace-only `post_excerpt` falls back to body; the total-chars ceiling truncates the set; an excerpt respects the word cap.

**Commit:** `feat(insights): body-ground the weekly advisor with bounded top-25 excerpts`

---

## Item 2 — Pre-publish mistake gate (S · client-side, no AI/network)
**Goal:** advisory warnings at publish time for common mistakes.

**Approach** (mirrors `inc/command-palette.php`'s classic-script IIFE pattern, no JSX):
- NEW `inc/pre-publish-gate.php` (~45 lines): `add_action('admin_enqueue_scripts', …)` gated on `$hook_suffix` `post.php` || `post-new.php` AND `current_user_can('edit_posts')`. **Do NOT** gate on `snt_ai_is_available()` (no AI dependency). Register `snt-pre-publish-gate` with deps `wp-plugins, wp-editor, wp-data, wp-element, wp-i18n`, `SNT_VERSION`, in-footer.
- NEW `assets/pre-publish-gate.js` (~80 lines, no JSX): `wp.plugins.registerPlugin` → `PluginPrePublishPanel` (from `wp.editor`; guard for absent `wp.plugins`/`wp.editor` on classic-editor contexts). Compute warnings via `wp.data.useSelect(core/editor)` reading the post + its `_sn_*` meta. Render advisory list with `wp.element.createElement`, `wp.i18n.__`.
- `require_once` in `signal-and-noise-tools.php` (~line 180, with the other editor includes).

**Checks (locked):** (a) noindex-left-on — read `_sn_noindex` (post-settings.php:68; bool, registered with `show_in_rest=true`); shown on post & page. (b) missing meta description (`_sn_meta_description`); post & page. (c) no tags; **post only** (pages have no tags). All `_sn_*` meta confirmed `show_in_rest=true` (post-settings.php:68,71).

**Severity (locked):** advisory only. `PluginPrePublishPanel` is non-blocking by design — do NOT attempt `lockPostSaving` (out of scope).

**Test** (`tests/pre-publish-gate.php`): PHP enqueue-wiring (hook registered, script handle + deps, page gate, capability gate). JS logic = manual-UAT checklist in the plan (no JS test harness).

**Commit:** `feat(editor): pre-publish advisory gate (noindex / meta-desc / tags)`

---

## Item 3 — Expand the ⌘K command palette (M · client-side)
**Goal:** add New-Note, tab-jumps, recent Notes, and the existing ability commands to the WP 7.0 palette.

**Approach** (stay within the existing two files; palette surface only):
- `inc/command-palette.php`: extend the `wp_localize_script` payload — `newNoteUrl => admin_url('post-new.php')`; `tabs => [{label,url}]` from `sn_admin_top_tabs()` (reuse the SSOT; `function_exists` guard) mapping each to `admin_url('admin.php?page=…')`; `notesCategoryId` via `get_term_by('slug', SN_NOTES_CATEGORY_SLUG, 'category')` (guard the const + function) → `0` when unseeded.
- `assets/command-palette.js`: register New-Note, 6 `SN: Go to <Tab>` commands, and — when `notesCategoryId > 0` — up to 5 recent Notes via ONE `apiFetch('/wp/v2/posts?categories=<id>&per_page=5&status=any')`, targeting the **edit screen** (`post.php?post=<id>&action=edit`), labels **entity-decoded** from `title.rendered` and passed as TEXT (no HTML injection). Keep the existing 5 ability commands.
- The whole palette already no-ops on WP<7.0 (deps-skip) — unchanged.

**Locked decisions:** static-register recent Notes (one `apiFetch` on init — matches the existing pattern; not `registerCommandLoader`); edit-screen targets; all 6 tabs; keep `manage_options`.

**Test** (`tests/command-palette-localize.php`): the PHP localize payload (newNoteUrl, tabs from the SSOT, notesCategoryId guarded to 0 when unseeded). JS = manual-UAT checklist.

**Commit:** `feat(palette): New-Note + tab-jumps + recent Notes in the ⌘K palette`

---

## Item 4 — AI release-notes drafter (M · one on-demand call) — Tools sub-tab + ability
**Goal:** draft a Mimestream-categorized (New / Improvements / Fixed) release note from a pasted CHANGELOG delta.

**Approach:**
- NEW `inc/release-notes-draft.php`: pure `snt_release_notes_draft_impl(string $changelog_delta): string|WP_Error`. Open with `$gate = snt_ai_require_text_generation(); if ($gate) return $gate;`. Trim + reject empty (`WP_Error('snt_rn_empty')`). **Hard-cap input ~4000 chars** before the call (FRUGAL). Define `SNT_RELEASE_NOTES_SYSTEM` (Mimestream: only New / Improvements / Fixed sections; concise; no invented changes). Call `snt_ai_generate_with_constraints($prompt, SNT_RELEASE_NOTES_SYSTEM, ~700)`. **Output = markdown** (paste → markdown, per user decision). Return the string.
- NEW `inc/admin-forms/release-notes.php`: `sn_admin_render_release_notes_section()` — a textarea (paste the delta) + submit + render the returned markdown in a copyable `<pre>`/`<textarea>`. Follows the existing admin-form + flash pattern.
- `inc/admin-tabs-data.php`: add a `release-notes` sub-tab under **Tools** (~line 104). ⚠ Tools goes 4→5 sub-tabs — verify against the desktop-mode horizontal-submenu-count rule ([[feedback_desktop_mode_horizontal_submenu_warning]]); in-page tab count must match the submenu count.
- `inc/admin-page.php`: dispatch branch for the `release-notes` sub-tab (~line 238).
- Callback `sn_handle_release_notes_draft` in `inc/admin-post-actions.php` + map entry in `sn_admin_post_handlers()` (inc/admin-post-handler.php:29). The dispatcher PRG-redirects, so surface the generated draft via a flash/transient.
- Ability `signal-noise/draft-release-notes` in `inc/abilities-system.php` (category `diagnostics`, perm `snt_ability_perm_manage_options`, `show_in_rest`, input `changelog_delta` string). **Annotations: `readonly` true, `idempotent` FALSE** — a generative call is not idempotent; do NOT copy `idempotent` from neighbors. Execute-callback delegates to `snt_release_notes_draft_impl`. → ability count **42→43**: update the `inc/abilities-registration.php` Total docblock + the `abilities-system.php` per-file count.
- `require_once` the new impl file + wire the new admin-form via the existing admin-forms include mechanism in `signal-and-noise-tools.php`.

**Test** (`tests/release-notes-draft.php`): standalone CLI with a stubbed `snt_ai_generate_with_constraints` (capture prompt) + stubbed gate. Assert: empty input → `WP_Error('snt_rn_empty')`; input over the cap is truncated before the call; the system instruction is passed; a normal delta returns the (stubbed) markdown. Plus `tests/admin-post-actions.php` handler-map count +1.

**Commit:** `feat(release-notes): AI release-notes drafter (Tools sub-tab + read-only ability)`

---

## Item 5 — "Create draft" button on Insights write_about cards (S · zero new AI calls)
**Goal:** seed a new draft from a cached `write_about` recommendation, no new AI call.

**Approach:**
- `inc/insights.php`: `snt_insights_find_rec($rec_id)` — read `snt_insights_last_scan()`, find the matching rec in `recommendations`, return it or null (handle a stale/expired cache → null). `snt_insights_create_draft_from_rec($rec): int|WP_Error` — build `$postarr` (`post_status=draft`, `post_type=post`, title derived from the rec, body = the rec rationale as a **valid `wp:paragraph` block** so the editor doesn't show block-recovery), assign the **Notes category**, `wp_insert_post(..., true)`.
- `inc/insights-admin.php`: a "Create draft" form/button on `write_about` cards only (carry the `rec_id` + nonce).
- Callback `sn_handle_insights_create_draft` in `inc/admin-post-actions.php` + map in `sn_admin_post_handlers()` (inc/admin-post-handler.php:29). **Redirect = Option A** (lowest blast radius): the shared dispatcher PRG-redirects back to the Insights tab; pass a success flash that includes a link to the new draft's editor (`get_edit_post_link`). Do NOT modify the shared dispatcher to redirect elsewhere. Mark the rec done on success to mute the card + reduce double-create.

**Locked decisions:** `write_about` only; `post` in Notes category; body seeded with the rationale as a valid block; Option A redirect.

**Test** (`tests/insights.php` + `tests/admin-post-actions.php`): `find_rec` hit/miss/expired; `create_draft_from_rec` builds a valid `$postarr` with Notes category + a valid block body; handler-map +1.

**Commit:** `feat(insights): "Create draft" button seeds a Note from a cached recommendation`

---

## Version + CHANGELOG (no-bump until release)
Bump `Version: 4.10.1 → 4.11.0`. CHANGELOG `[4.11.0]` (Mimestream): **New** — pre-publish gate, ⌘K expansion, release-notes drafter, Insights "Create draft"; **Improvements** — Insights advisor now content-aware (body-grounded).

## Verify gate
Full sweep (excl `contracts-smoke.php`) → 0 failures (50 baseline + new/extended suites). `composer run lint` clean + a falsification probe on a new file (note: phpcs `parallel=8` shows "8/8" = batches, not files — [[reference_phpcs_parallel_batches_not_files]]). Manual UAT: trigger each pre-publish warning; open ⌘K and run New-Note + a tab-jump + a recent Note; draft release notes from a pasted delta; click Create-draft on a write_about card. Then build→adversarial-review→fix→ship as **v4.11.0**.

## Out of scope (per grounding forks)
No `registerCommandLoader` live search (static-register); no `lockPostSaving` hard-block; no CHANGELOG.md write-back / auto-read (paste-only input); no create-draft for `topic_*` rec types (write_about only). Theme bundles B3/B4 follow in their own cycles.
