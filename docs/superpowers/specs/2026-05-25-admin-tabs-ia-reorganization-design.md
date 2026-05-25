# Design — SN admin tabs IA reorganization (ships as v3.8.0)

**Date:** 2026-05-25
**Status:** APPROVED (4 sections, user-confirmed via brainstorming)
**Ships as:** plugin **v3.8.0** (cap-hit on v3.7.x forces minor bump; "user-visible capability surface" change justifies minor)
**Scope:** UI refactor only. No new functionality. No data-schema changes. No module hook contract changes.
**Companion ship:** **v3.8.1** (Login hardening audit log) lands as a follow-up — see Section 4.
**Brainstorm session:** 2026-05-25 (this doc derives from that session's approvals)

---

## TL;DR

The plugin admin page has accumulated **12 flat top-level tabs** (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Cron, Webhooks, Insights, Health, Links). User feedback: "out of control" — navigation discoverability, visual presentation, and use-frequency mismatch are all degraded.

This spec consolidates to **6 hierarchical top tabs with internal-TOC sub-sections**, mirroring the already-proven pattern from the existing Identity tab:

```
Dashboard │ Site │ Security │ Automation │ Monitoring │ Tools
```

**14 sub-sections at v3.8.0** distributed across the 6 top tabs (becomes 15 in v3.8.1 when Login hardening's audit-log sub-section lands under Security). All 12 legacy URLs survive via 301 redirects to canonical `?tab=<category>#sn-sec-<subsection>` destinations. WP sidebar keeps all 12 entries as direct-jump shortcuts. **Implementation contained to `inc/admin-page.php`** — module hook contracts unchanged (each module's `do_action('sn_admin_<slug>_tab')` still fires identically; just dispatched from a different parent).

**~150 LOC actually-new in admin-page.php; zero LOC in any other file.** Single atomic commit; one full-session ship.

---

## Problem statement

The current 12-flat-tab IA has three concrete pain axes (user-confirmed in brainstorm, 3 of 4 selected):

1. **Navigation discoverability:** scanning 12 labels to remember "where does X live?" is cognitively expensive. Past the 5-7 magic number for top-level nav.
2. **Visual presentation:** 12 tabs wrap to two rows in narrower viewports; the row visually overwhelms the page header.
3. **Use-frequency mismatch:** legacy / one-off surfaces (Reading Time) get equal real estate to daily-use surfaces (Insights, Dashboard).

**Not a problem (explicitly NOT selected by user):** mental-model mismatch. The categorization itself is fine — users don't need to relearn what "Cloudflare" means or rethink why Webhooks exists. The reorg is structural compression, not conceptual rethink.

**Constraints discovered during exploration:**
- Legacy `?page=sn-theme-options&tab=<slug>` URLs must survive (v1.8.x bookmarks)
- Legacy `?page=sn-<slug>` URLs must survive (v1.9.0+ per-page shortcuts)
- The internal-TOC pattern is already proven on the Identity tab (`admin-page.php:722-728`) — apply it consistently across other multi-section tabs
- Module-hook contract (`do_action('sn_admin_<slug>_tab')`) is the source of decoupling — each module renders its own UI, parent dispatches; this lets us re-arrange parents without touching modules

---

## Approach chosen

**Approach 1 — 6 top-level tabs with internal-TOC sub-sections.**

Alternatives considered:
- **Approach 2 — 5 tabs + dashboard cards for Cloudflare/Tools/Links.** Rejected: Cloudflare-as-dashboard-card feels demoted given its use frequency.
- **Approach 3 — WP-sidebar-led multi-page (each category its own admin page).** Rejected: biggest paradigm shift, breaks in-page tab muscle memory, requires touching all module render code.

Approach 1 wins because:
1. Smallest reorg that solves all three pain axes
2. Reuses the already-proven internal-TOC pattern (Identity tab)
3. Easiest URL backward-compat (each old top tab maps to one canonical `?tab=<category>#sn-sec-<old>`)
4. Module hook contracts stay unchanged (zero LOC in non-admin-page files)
5. Sidebar keeps all 12 shortcuts (muscle memory preserved)

---

## Section 1 — Final tab structure + sub-section mapping

```
┌──────────────────────────────────────────────────────────────────────┐
│  Dashboard │ Site │ Security │ Automation │ Monitoring │ Tools       │
└──────────────────────────────────────────────────────────────────────┘
```

| Top tab | Sub-sections (in display order, via internal TOC) | What moves in (from current 12) | Owner file (today) |
|---|---|---|---|
| **Dashboard** | — (landing page, no sub-sections) | Dashboard | `inc/admin-tab-dashboard.php` (hooked) |
| **Site** | Identity, Social, Open Graph, SEO Copy, **Cloudflare** | Identity tab's 4 internal sections become top-of-page TOC targets; Cloudflare joins (site infrastructure) | inline `admin-page.php` + `inc/cloudflare-purge.php` |
| **Security** | Login URL (v3.8.0); Audit log added in v3.8.1 | Login URL ships at v3.8.0; the audit log lands in the follow-up v3.8.1 | inline `admin-page.php` + `inc/login-audit.php` (v3.8.1) |
| **Automation** | Webhooks, Cron | "Things that fire automatically AND can be manually triggered" | `inc/webhooks-admin.php`, `inc/cron-dashboard-admin.php` |
| **Monitoring** | Insights, Health, Plausible, RSS | Pure data displays — read-mostly dashboards | `inc/insights-admin.php`, `inc/health-checks-admin.php`, `inc/plausible-admin.php`, `mu-plugins/rss-plausible-tracker.php` |
| **Tools** | Reading Time, Links | Legacy + utility surfaces | `inc/reading-time.php` + inline `admin-page.php` |

**6 top tabs, 14 sub-sections at v3.8.0** (becomes 15 at v3.8.1 with the Login hardening audit-log addition). Vs. 12 flat tabs today. Within the 5-7 magic number for top-level nav.

**Three categorization decisions worth flagging:**

1. **Cloudflare → Site (not Automation).** CDN/cache config = site infrastructure (set once, rarely revisit). The "manual zone purge" button is action-oriented but rare. Keeps "Automation" semantically tight (Webhooks + Cron = both "fire on schedule/event + manually re-trigger"). User-approved (decision: "Approved — proceed").

2. **Cron → Automation (not Monitoring).** Cron has a monitoring aspect (was this job late?), but the differentiator vs. Insights/Health is the manual-trigger button. Cron is action-affordant, not just display. Sits cleaner with Webhooks. User-approved.

3. **Nothing deleted, but two things demoted.** Reading Time stays under Tools (legacy / one-time cleanup; visible but secondary). Links could honestly live as a footer on Dashboard but kept in Tools as the safer default. User-approved.

**What's NOT changing:**
- The WP sidebar still shows all 12 entries — existing muscle memory + per-slug deep links survive (they redirect to the new tab + anchor)
- The internal-TOC pattern is the same; just applied consistently across all 6 sub-section-bearing tabs
- Each module's existing `do_action('sn_admin_*_tab')` hook still exists — just dispatched under a new parent

---

## Section 2 — URL backward compatibility

**The challenge:** today there are ~24 valid URLs across two patterns:
- `?page=sn-theme-options&tab=<slug>` (12 variants — v1.8.x flat pattern)
- `?page=sn-<slug>` (12 variants — v1.9.0+ per-page pattern)

After reorg, canonical URLs collapse to:
- `?page=sn-theme-options&tab=<category>` (6 variants — new top tabs)
- `?page=sn-<category>` (6 variants — sidebar shortcuts)
- Plus URL fragment `#sn-sec-<oldslug>` to jump into a sub-section

**Strategy: 301 redirects + all 12 sidebar shortcuts preserved.**

### 1. Server-side 301 redirect map

Added in `sn_theme_options_page()` before output. If `$_GET['tab']` is a legacy slug, issue 301 redirect to `admin.php?page=sn-theme-options&tab=<canonical>#<anchor>` and exit.

```php
$legacy_to_canonical = array(
    'identity'     => array( 'tab' => 'site',       'anchor' => 'sn-sec-identity' ),
    'cloudflare'   => array( 'tab' => 'site',       'anchor' => 'sn-sec-cloudflare' ),
    'login'        => array( 'tab' => 'security',   'anchor' => 'sn-sec-login' ),
    'webhooks'     => array( 'tab' => 'automation', 'anchor' => 'sn-sec-webhooks' ),
    'cron'         => array( 'tab' => 'automation', 'anchor' => 'sn-sec-cron' ),
    'insights'     => array( 'tab' => 'monitoring', 'anchor' => 'sn-sec-insights' ),
    'health'       => array( 'tab' => 'monitoring', 'anchor' => 'sn-sec-health' ),
    'plausible'    => array( 'tab' => 'monitoring', 'anchor' => 'sn-sec-plausible' ),
    'rss'          => array( 'tab' => 'monitoring', 'anchor' => 'sn-sec-rss' ),
    'reading-time' => array( 'tab' => 'tools',      'anchor' => 'sn-sec-reading-time' ),
    'links'        => array( 'tab' => 'tools',      'anchor' => 'sn-sec-links' ),
    // dashboard maps to itself (already canonical)
);
```

Same map applies to `?page=sn-<slug>` URLs (route through the same redirect logic — derive the implied tab from the page slug per existing `sn_admin_page_tab_for_slug()` helper, then check against `$legacy_to_canonical`).

**Why 301 not 302:** 301 = "Moved Permanently" tells browsers + crawlers to update their cached URL. Since the reorg is permanent, 301 is correct signaling.

**URL-fragment-survives-redirect technique:** WordPress's `wp_safe_redirect()` strips fragments by default for security. We bypass `wp_safe_redirect()` for same-host admin URLs (which are trusted) and use raw `header('Location: …', true, 301)` + `exit;` — the fragment passes through to the browser, which auto-scrolls to the anchor on page load.

### 2. WP sidebar — keep all 12 entries

The submenu registration in `add_submenu_page()` stays as-is — each entry's URL is constructed to the legacy form (`?page=sn-<slug>`), which then 301-redirects to canonical.

**Reasoning:** the sidebar is a different surface than the in-page tabs. In-page tabs optimize for presentation density (visual clutter). The sidebar optimizes for navigation density (number of shortcuts). Reducing sidebar entries to match in-page tabs would conflate two different surfaces' goals. User-approved (decision: "Approved — proceed").

### 3. Edge cases

| Case | Handling |
|---|---|
| External tool / docs link to old `?tab=login` | 301-redirects to new canonical; works correctly |
| User shares an old URL (e.g., Slack message) | Recipient gets 301, lands on correct sub-section |
| Search engine indexes old admin URLs | N/A (admin requires auth; no public crawling) |
| `?sn_flash=login_saved` after slug save | Existing PRG (Post-Redirect-Get) pattern preserves flash; the redirect destination just needs the new canonical tab. Tiny adjustment to `sn_handle_admin_post()` to redirect to the new tab. |
| Browser fragment scroll before stylesheet loads (FOUC on anchor) | Browsers handle this; if it becomes a visible issue, we add a tiny "smooth-scroll once stylesheet ready" JS shim. Defer until observed. |

---

## Section 3 — Implementation: refactor order + hook contract

**The key architectural insight: module hook contracts stay unchanged.** Currently each module file registers a listener on `sn_admin_<slug>_tab` (e.g., `sn_admin_cloudflare_tab` in `cloudflare-purge.php`). Admin-page.php dispatches by firing that action for the active tab.

After reorg, the listener hooks **still fire identically** — they just get called from a different parent. The Cloudflare module doesn't know it's now rendered as a sub-section of Site instead of a top-level tab. Its hook signature stays the same; its HTML output stays the same; its POST handler stays the same.

**This means the entire refactor is contained to `admin-page.php`.**

### Files touched

| File | Change | Est. LOC delta |
|---|---|---|
| `inc/admin-page.php` | Restructure `sn_admin_pages()` (add `sub_sections` field), new dispatch with sub-section rendering, add `$legacy_to_canonical` redirect map, wrap existing module hooks in `<section id="...">` blocks, restructure inline Identity/Login/Links renders | ~+150 added (new dispatch arms + helpers + redirect map); ~-50 removed (old top-level dispatch arms); **net file-size delta ≈ +100 lines** |
| `signal-and-noise-tools.php` | None (sidebar entries unchanged) | 0 |
| `inc/settings.php` | None (sanitize+save logic doesn't know about tab structure) | 0 |
| All other `inc/*-admin.php` and module files | None (hook contracts unchanged) | 0 |
| `CHANGELOG.md` | New v3.8.0 entry | ~30 lines |
| Plugin main file `Version:` header | Bump 3.7.6 → 3.8.0 | 1 |

**Single-file refactor + chrome.** The "+150 added" is the LOC complexity budget — that's how much new code the writing-plans phase needs to budget for. The "net +100" is the resulting file-size growth after also accounting for the old top-level dispatch arms being removed/restructured.

### New dispatch shape (sketched)

```php
} elseif ( 'site' === $active_tab ) {
    sn_admin_render_toc( 'site' );  // anchor list at top, reads from sn_admin_pages()['site']['sub_sections']

    sn_admin_render_section( 'identity', function() {
        // existing Identity render block, moved here
    } );
    sn_admin_render_section( 'social', function() { /* … */ } );
    sn_admin_render_section( 'open-graph', function() { /* … */ } );
    sn_admin_render_section( 'seo-copy', function() { /* … */ } );
    sn_admin_render_section( 'cloudflare', function() {
        do_action( 'sn_admin_cloudflare_tab' );  // existing module hook unchanged
    } );
}
```

Two new helpers (~30 LOC total):
- `sn_admin_render_toc( $tab_slug )` — generates the in-page anchor nav at top of multi-section tabs. Reads from `sn_admin_pages()[$tab]['sub_sections']` for the link list.
- `sn_admin_render_section( $section_slug, $render_callback )` — wraps content in `<section id="sn-sec-<slug>" class="sn-section">` + `<h2>` heading + invokes callback. Enforces consistent markup across all 5 sub-section-bearing tabs.

### Extended `sn_admin_pages()` structure

```php
function sn_admin_pages() {
    return array(
        array(
            'slug'         => 'sn-theme-options',
            'tab'          => 'dashboard',
            'label'        => 'Dashboard',
            'title'        => 'Signal & Noise — Dashboard',
            'subtitle'     => 'Status overview and maintenance actions.',
            'sub_sections' => array(),  // landing page, no sub-sections
        ),
        array(
            'slug'         => 'sn-site',
            'tab'          => 'site',
            'label'        => 'Site',
            'title'        => 'Signal & Noise — Site',
            'subtitle'     => 'Site identity, social profiles, Open Graph, SEO copy, Cloudflare.',
            'sub_sections' => array(
                'identity'   => array( 'label' => 'Identity' ),
                'social'     => array( 'label' => 'Social' ),
                'open-graph' => array( 'label' => 'Open Graph' ),
                'seo-copy'   => array( 'label' => 'SEO Copy' ),
                'cloudflare' => array( 'label' => 'Cloudflare' ),
            ),
        ),
        array(
            'slug'         => 'sn-security',
            'tab'          => 'security',
            'label'        => 'Security',
            'title'        => 'Signal & Noise — Security',
            'subtitle'     => 'Custom login URL and audit log.',
            'sub_sections' => array(
                'login'     => array( 'label' => 'Login URL' ),
                // 'audit-log' added in v3.8.1
            ),
        ),
        array(
            'slug'         => 'sn-automation',
            'tab'          => 'automation',
            'label'        => 'Automation',
            'title'        => 'Signal & Noise — Automation',
            'subtitle'     => 'Webhooks and scheduled jobs.',
            'sub_sections' => array(
                'webhooks' => array( 'label' => 'Webhooks' ),
                'cron'     => array( 'label' => 'Cron' ),
            ),
        ),
        array(
            'slug'         => 'sn-monitoring',
            'tab'          => 'monitoring',
            'label'        => 'Monitoring',
            'title'        => 'Signal & Noise — Monitoring',
            'subtitle'     => 'Insights, content health, analytics, RSS subscribers.',
            'sub_sections' => array(
                'insights'  => array( 'label' => 'Insights' ),
                'health'    => array( 'label' => 'Health' ),
                'plausible' => array( 'label' => 'Plausible' ),
                'rss'       => array( 'label' => 'RSS' ),
            ),
        ),
        array(
            'slug'         => 'sn-tools',
            'tab'          => 'tools',
            'label'        => 'Tools',
            'title'        => 'Signal & Noise — Tools',
            'subtitle'     => 'Utility surfaces and external shortcuts.',
            'sub_sections' => array(
                'reading-time' => array( 'label' => 'Reading Time' ),
                'links'        => array( 'label' => 'Links' ),
            ),
        ),
    );
}
```

**Note:** the 12 legacy submenu entries are registered SEPARATELY via `add_submenu_page()` — they don't appear in `sn_admin_pages()` (which now lists the 6 canonical pages). The sidebar registration loop iterates a `$legacy_sidebar_entries` array, each entry constructed with the legacy `?page=sn-<slug>` URL that 301-redirects to canonical.

### Commit strategy

**Recommendation: one atomic commit** (`v3.8.0: admin tabs IA reorg — 12 flat → 6 hierarchical`). Reasoning: the refactor needs to ship atomically because partial state (some old tabs gone, some new tabs missing) breaks the admin UI.

If diff feels too big at implementation time, fall back to per-tab commits (one commit per top tab restructured) — but default to atomic. Single revert returns to v3.7.6 behavior.

User-approved (decision: "Approved — proceed to Section 4").

---

## Section 4 — Testing, edge cases, Login hardening re-integration

### Verification gates (run before claiming v3.8.0 ships)

| # | Gate | How to verify |
|---|---|---|
| 1 | All 12 legacy URLs 301-redirect to correct canonical | Manual smoke: visit each old URL via WP admin sidebar; confirm landing tab + scroll position |
| 2 | All 6 new top tabs render content (no blank pages) | Click through each top tab; verify TOC + at least one sub-section visible |
| 3 | All 14 sub-sections reachable via TOC anchor | Click each TOC anchor in each tab; verify scroll lands on correct `<section>` |
| 4 | Settings save (any tab) still works → redirects to correct new canonical | Save Identity, Login slug, Cloudflare token; verify flash notice on right tab |
| 5 | WP sidebar's 12 entries each lead to correct sub-section | Click each sidebar entry; verify landing canonical URL via 301 |
| 6 | Existing module hooks still fire | Visit Site → Cloudflare section → verify CF UI renders (proves `do_action('sn_admin_cloudflare_tab')` still triggers) |
| 7 | Active-tab visual state correct | Top tab highlighted = current category; TOC anchor highlighted if implemented (defer if not in v1) |
| 8 | PRG flash messages preserve across tab redirect | Save Identity; verify "Identity settings saved" notice appears on Site tab after redirect |

### Edge cases (no special handling needed, documented for awareness)

| Case | Behavior | Action |
|---|---|---|
| Module hook listener errors (e.g., DB issue in Cron) | Sub-section renders empty / PHP warning | Same as today; no regression |
| Anchor scroll on below-fold sub-section | Browser auto-scrolls to anchor | Native behavior, works correctly |
| Sub-section render is slow (e.g., Cron history DB query) | Inline render delay | Same as today; per-section perf unchanged |
| User visits new `?tab=site` URL directly (no redirect needed) | New dispatch fires | Works |
| Screen reader / a11y | `<nav class="sn-toc">` already labeled (Identity pattern at `admin-page.php:722`); each section has `<h2>` heading | Inherited from existing pattern |

### Login hardening audit log re-integration

The paused Login hardening brainstorm's Section 1 design (`docs/superpowers/specs/` — yet to be written; see brainstorm transcript in this session) had the audit log displayed "ABOVE the slug edit form on the Login tab." After this reorg, that location no longer exists.

**Updated placement:** the audit log becomes **its own sub-section under Security**, peer to the Login URL sub-section. The Security tab's `sub_sections` array gains a second entry in v3.8.1:

```php
'security' => array(
    'sub_sections' => array(
        'login'     => array( 'label' => 'Login URL' ),
        'audit-log' => array( 'label' => 'Audit log' ),  // ← added in v3.8.1
    ),
),
```

**What changes from the paused Login hardening Section 1:** ONLY the display location. Data model (`wp_option('sn_login_audit')` with date-keyed buckets), hooks (`wp_login`, `wp_login_failed`, plus counting calls in `login-hide.php` 404 branches), HMAC-SHA256 hashing bound to `wp_salt('auth')`, 90-day window — all unchanged.

The audit log file (`inc/login-audit.php`) registers its listener as `do_action('sn_admin_security_audit_log_section')` following the section-aware naming convention introduced by this reorg.

### Sequencing

| Version | Scope | Ships |
|---|---|---|
| **v3.8.0** | Tab reorg only (this spec) | Current session (after writing-plans → execution → verification) |
| **v3.8.1** | Login hardening audit log (paused Section 1 design + re-integration per Section 4 above) | Follow-up session |
| (docs-only) | Roadmap reality reconciliation — audit the 15-phase absorption roadmap doc against shipped code | Follow-up session, no version bump |

Splitting v3.8.0 from v3.8.1 gives clean verification of the reorg in isolation before bolting on new functionality. Each ship has a smaller verification surface; each is individually reversible.

---

## Out of scope (explicitly)

- **No new functionality.** This reorg ships zero new features. The Login hardening audit log lands in v3.8.1 as a separate ship.
- **No data-schema changes.** `sn_settings` schema unchanged. No new wp_option keys created in v3.8.0.
- **No module hook contract changes.** Each module's `do_action('sn_admin_<slug>_tab')` still fires identically; signature unchanged; HTML output unchanged.
- **No CSS changes (in v3.8.0).** The existing `assets/admin.css` already supports `.sn-fieldset`, `.sn-section`, `.sn-toc` classes (proven on the Identity tab). If the new tabs surface CSS gaps at implementation time, address them inline; otherwise no CSS work.
- **No JavaScript changes.** Anchor scroll is browser-native; no JS shim needed in v1. Defer JS work until observed need.
- **No deletion of existing surfaces.** Reading Time stays under Tools even though it's legacy. Pruning happens (or doesn't) in a separate v3.9.x decision after we observe what's actually used.
- **No changes to per-route SEO copy emission, OG card generation, or any other plugin functionality.** This is purely a UI-shell refactor.

---

## References

**Spec lineage:**
- Cancelled prior v3.8.0 spec: `docs/superpowers/specs/2026-05-24-plugin-v3.8.0-anthropic-provider-design.md` (Anthropic provider, cancelled per WordPress/desktop-mode#271 maintainer signal)
- 15-phase plugin absorption roadmap: `signal-and-noise/docs/superpowers/specs/2026-05-16-plugin-absorption-roadmap.md` (theme repo) — this reorg is NOT part of that roadmap; it's a cross-cutting UX improvement

**Handoffs:**
- `signal-and-noise/docs/superpowers/handoffs/2026-05-25-item-d-shipped-item-e-queued.md` — context for why this session was spent on Login hardening + reorg
- `signal-and-noise/docs/superpowers/handoffs/2026-05-24-ai-readiness-arc-complete.md` — prior session covering the v3.7.6 ship

**Project conventions (CLAUDE.md):**
- Patch cap: plugin v3.7.x is HIT at 7/7 — v3.8.0 is mandatory for the next code-bearing release
- Versioning: ships as v3.8.0 because this is a "new user-visible capability surface" (per the global SemVer override). Reorg-only refactors normally would be patch, but the capability surface change (12 → 6 tabs) is user-facing enough to warrant minor.

**Memory:**
- `feedback_skills_plugins_docs_always.md` — hard rule that drove the exploration phase (which surfaced that Login UI was already fully shipped, requiring the scope pivot to this reorg)
- `feedback_no_dashboard_widgets.md` — operational info goes in SN tabs, not WP dashboard widgets

**Existing code patterns reused:**
- Internal TOC pattern: `inc/admin-page.php:722-728` (currently on Identity tab) — generalized via new `sn_admin_render_toc()` helper
- Section wrapping pattern: `inc/admin-page.php:931-967` (the existing Login tab's `.sn-fieldset` blocks) — generalized via new `sn_admin_render_section()` helper
- Module hook dispatch: `inc/admin-page.php:585-668` (Dashboard, Cloudflare, Plausible, RSS, Reading Time, Cron, Webhooks, Insights, Health) — unchanged contract, new parent dispatchers

---

## What writing-plans should produce

When this spec is approved, the next step is `superpowers:writing-plans` to produce an implementation plan. That plan should:

1. **Task breakdown** — likely 4-6 sub-tasks: (a) add new `sn_admin_pages()` shape + helpers, (b) build the legacy redirect map, (c) move inline Identity render into Site sub-sections, (d) move inline Login render into Security sub-section, (e) move inline Links render into Tools sub-section, (f) wire each module-hook dispatch into the new parent
2. **Verification gates** — the 8 gates from Section 4 above, ideally as a smoke-test checklist
3. **Atomic commit boundaries** — recommend one big commit but document the per-tab fallback path
4. **CHANGELOG entry draft** — for v3.8.0
5. **Rollback plan** — single revert commit restores v3.7.6 behavior; clean PR boundary

---

**Status: spec approved through brainstorming. Awaiting user review of this written document before transitioning to `superpowers:writing-plans`.**
