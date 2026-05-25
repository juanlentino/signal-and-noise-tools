# v3.8.6 design — Viewport-fit admin pages (system-wide CSS pass)

**Date:** 2026-05-25
**Target version:** plugin v3.8.6 (patch 6/7 in v3.8.x — 1 patch remains before v3.9.0 rollover)
**Status:** Approved via brainstorming session; ready for writing-plans
**Author intent:** make SN admin pages fit the desktop-mode portal viewport with internal-scroll for long tables and sticky chrome — eliminating most page-level scroll. Dashboard-app feel.

---

## TL;DR

Pure CSS pass (one file modified, `assets/admin.css`, ~80-120 LOC added) plus **6 HTML table-wrapper additions across 5 module files** (audit-log-admin gets 2 wrappers; others 1 each). Six tactics combined:

1. **Sub-tab nav becomes sticky** below the WP admin bar (32px offset)
2. **TOC nav also sticky** (used on Identity & SEO) below the sub-tab nav (80px offset)
3. **Long tables get internal scroll** via opt-in `.snt-scroll-table` wrapper (`max-height: 50vh`, sticky `<thead>`, scoped border)
4. **Hero stat cards tightened** (padding 16→12px, value font 28→22px)
5. **`.sn-fieldset` density tightened** via CSS variable updates (`--sn-space-4` 16→12px, `--sn-space-5` 24→20px) — ripples through 23 callers, all tighten by ~25%
6. **Section intro paragraph (`.sn-prose`) compacted** (14px font, 8px bottom margin)

Net effect: every page with a `.widefat` table (Audit log, Cron Dashboard, Health, Webhooks, RSS) gets dashboard-app feel — chrome stays anchored, data scrolls internally. Form pages (Identity & SEO, Cloudflare, Login URL) keep natural page scroll because users tab through fields.

---

## Problem statement

The SN admin pages currently scroll the whole page when content is long:

- **Audit log** (just shipped v3.8.3): 4 hero cards (~120px) + counter timeline table (30 rows × ~32px = ~960px) + recent logins table + LLA card + Prune button ≈ 2000px of content. Desktop-mode portal viewport is ~700-900px tall. User scrolls a lot.
- **Cron Dashboard** (v3.0.0): 30-50 scheduled cron events in a table. ~1600px content, same viewport.
- **RSS** (v1.13.0, stack-fixed in v3.8.5): Activity stats + Recent requests table + Settings form + Maintenance form. Long vertical column.
- **Identity & SEO** (Site tab default): TOC + 4 form sections (Identity, Social, Open Graph, SEO Copy). Long form.

When users scroll, they lose context:
- The sub-tab nav (which tells them which sub-tab they're on) scrolls off
- The hero cards (which summarize current state) scroll off
- The page header (which says which top tab is active) scrolls off

User stated: *"I don't like the scroll in pages. We need to make a way to justify the scrolls. If we can adapt everything to be in the viewport, it'd be great."*

Interpreting "justify the scrolls" as "contain scroll to specific regions rather than the whole page" — i.e., dashboard-app pattern where chrome stays anchored and data regions scroll internally.

---

## Approach chosen

Per brainstorming, 3 locked decisions:

| Q | Decision |
|---|---|
| Scope | **System-wide CSS pass affecting all pages** — one patch ships universal foundation; per-page tweaks land after if needed. |
| Tactics | **Tables + sticky + spacing only** — no form-page restructure (Identity & SEO keeps its long form layout with internal TOC). |
| Density recipe | **Recommended numeric knobs** — padding -25%, hero font 22px (from 28px), table internal-scroll max-height 50vh, sticky sub-tabs at top: 32px. |

---

## Section 1 — Architecture + files touched

```
signal-and-noise-tools/
  assets/
    admin.css                      # MODIFY: ~80-120 LOC added/changed
  inc/
    audit-log-admin.php            # MODIFY: wrap 2 tables in .snt-scroll-table
    cron-dashboard-admin.php       # MODIFY: wrap events table in .snt-scroll-table
    health-checks-admin.php        # MODIFY: wrap scan results in .snt-scroll-table
    webhooks-admin.php             # MODIFY: wrap deliveries log in .snt-scroll-table (if long enough)
    rss-plausible-tracker.php      # MODIFY: wrap recent requests table in .snt-scroll-table
```

**6 files total touched.** Pure CSS in 1 file. Wrapper HTML additions in 5 module files (`<div class="snt-scroll-table">` opening and closing tags around existing `<table class="widefat">` markup). No PHP logic changes anywhere.

**Why opt-in `.snt-scroll-table` wrapper instead of styling `.widefat` directly:** several short tables also use `.widefat` (e.g., Reading Time's small list, ad-hoc tables in settings forms). Direct styling on `.widefat` would force internal scroll where it isn't wanted. Opt-in wrapper means each callsite explicitly chooses internal-scroll behavior.

---

## Section 2 — Sticky chrome

```css
/* Sub-tab nav sticks below WP admin bar (32px desktop, 46px mobile via WP @media).
   Adopting 32px as the static top — mobile is out-of-scope for SN admin per project. */
.sn-sub-tabs {
    position: sticky;
    top: 32px;
    background: var(--sn-surface);
    z-index: 10;
    /* preserves all existing pill styling */
}

/* TOC nav (used on Identity & SEO sub-tab) sticky below the sub-tab nav.
   The 80px offset = 32px admin bar + ~48px for the sub-tab nav row. */
.sn-toc {
    position: sticky;
    top: 80px;
    z-index: 9;
    /* preserves all existing box styling */
}
```

**Why these specific offsets:**
- WP's admin bar is 32px tall on desktop (per `wp-includes/css/admin-bar.css` `@media (min-width: 783px)` rule). We're admin-only and desktop-focused, so 32px is correct.
- Sub-tab nav with current padding renders at ~48px tall. Adding 32px (admin bar) gives 80px for the TOC top offset.
- `z-index: 10` for sub-tabs and `z-index: 9` for TOC ensures they layer above table content but below WP admin bar (which uses higher z-index).

**Why sticky and not fixed:**
- `position: sticky` respects the page flow — the sub-tab nav scrolls with the page until it would hit `top: 32px`, then it sticks. This means on short pages where the entire content fits in viewport, the sub-tab nav behaves normally (no fixed-position overlap with content).
- `position: fixed` would always be at top, taking layout space even on short pages where it's unnecessary.

---

## Section 3 — Internal-scroll table wrapper

The CSS pattern:

```css
.snt-scroll-table {
    max-height: 50vh;                /* ~400px on 800px viewport → ~12 rows visible */
    overflow-y: auto;
    border: 1px solid var(--sn-border);
    border-radius: var(--sn-radius);
    margin: 0 0 var(--sn-space-4);
}

.snt-scroll-table .widefat {
    border: none;                    /* outer wrapper owns the border now */
    box-shadow: none;
    margin: 0;
}

.snt-scroll-table .widefat thead th {
    position: sticky;
    top: 0;
    background: #f0f0f1;             /* WP-admin native thead bg */
    z-index: 1;
}
```

**Pattern usage** — each module wraps its long table:

```php
echo '<div class="snt-scroll-table">';
echo '<table class="widefat">';
echo '<thead>...</thead><tbody>...</tbody>';
echo '</table>';
echo '</div>';
```

**Why `display: block` is NOT needed** on `.widefat` here: when a table is inside a wrapper div with `max-height + overflow-y: auto`, the table itself remains `display: table`. The wrapper's overflow scrolls the table content. Sticky `<thead>` works inside this wrapper because `position: sticky` traverses up to the nearest scrolling ancestor (the wrapper).

**Tables that get wrapped** (5 callsites):
- `audit-log-admin.php` → counter timeline table + recent logins table (2 wrappers)
- `cron-dashboard-admin.php` → scheduled events table
- `health-checks-admin.php` → scan results table (verified at line 95)
- `webhooks-admin.php` → deliveries log table (verified at line 118)
- `rss-plausible-tracker.php` → recent requests table

**Tables that do NOT get wrapped** (intentional):
- `reading-time.php` → list is short; natural-flow OK
- Ad-hoc tables inside form pages (Identity, Cloudflare) — these are usually short field-row tables, not data tables

---

## Section 4 — Density tightening

**CSS variable changes:**

```css
:root {
    /* WAS: 16px */
    --sn-space-4: 12px;
    /* WAS: 24px */
    --sn-space-5: 20px;
    /* sn-space-1, sn-space-2, sn-space-3 unchanged (4px / 8px / 12px) */
}
```

This affects **23 callsites** in `admin.css` (15 for `--sn-space-4`, 8 for `--sn-space-5`). All UI density tightens by ~25%. The intent: less vertical bloat in fieldset padding, section margins, and grid gaps — directly reducing scroll height.

**Hero stat-card density** (audit log + dashboard):

```css
.sn-audit-card,
.sn-state-card {
    padding: 12px;                   /* was 16px */
}

.sn-audit-card-value,
.sn-state-card__value {
    font-size: 22px;                 /* was 28px */
    line-height: 1.1;
}
```

**Section intro paragraph density:**

```css
.sn-prose,
.sn-fieldset-intro {
    font-size: 14px;                 /* was inherited 16px from WP admin */
    margin: 0 0 8px;                 /* was 0 0 var(--sn-space-3) = 12px */
}
```

**Section heading top margin:**

```css
.sn-fieldset-h {
    margin-top: 16px;                /* was 24px from existing rule */
}
```

**Cross-reference with already-touched selectors from prior v3.8.x ships:**
- `.sn-audit-state-grid` (v3.8.3) — uses 12px gap which is unchanged
- `.sn-2col` (v3.8.4 → v3.8.5) — always-stack remains the design intent

---

## Section 5 — Per-page exception list

**Pages that gain internal-scroll tables + tight density + sticky nav:**

| Page | Module | What scrolls internally |
|---|---|---|
| Audit log | `audit-log-admin.php` | Counter timeline + recent logins (2 tables) |
| Cron Dashboard | `cron-dashboard-admin.php` | Scheduled events table |
| Content Health | `health-checks-admin.php` | Scan results table (verified: `.widefat striped` at line 95) |
| Webhooks (Automation tab) | `webhooks-admin.php` | Deliveries log table (verified: `.widefat striped` at line 118; already has inline `font-size:0.85em`) |
| RSS (Monitoring tab) | `rss-plausible-tracker.php` | Recent requests table |

**Pages that gain only sticky chrome + tight density (no internal-scroll):**

| Page | Module | Why no internal-scroll |
|---|---|---|
| Dashboard | `admin-tab-dashboard.php` | Recent deploys list is naturally short (5 items) |
| Identity & SEO | `admin-page.php` inline | Long FORM — users tab through fields; internal-scroll would interrupt |
| Cloudflare | `cloudflare-purge.php` | Form |
| Login URL | `admin-page.php` inline | Form + emergency unlock docs |
| Plausible | `plausible-admin.php` | Form |
| Insights | `insights-admin.php` | Recommendations are cards, not a data table; current rendering is OK |
| Reading Time | `reading-time.php` | List is short; flow OK |

---

## Section 6 — Verification gates

This is a CSS-only visual change with HTML wrapper additions. No automated tests. Verification is manual smoke per page in the desktop-mode portal (where the user works) and in vanilla wp-admin (where the breakpoint behaviors differ).

| Gate | Manual check |
|---|---|
| **G1** | Sub-tab nav stays at top of viewport when scrolling on Site / Security / Automation / Monitoring / Tools (each top tab that has sub-tabs) |
| **G2** | TOC nav stays at top of content area when scrolling Identity & SEO sub-tab (below sub-tab nav) |
| **G3** | Audit log counter timeline table scrolls internally; `<thead>` stays visible during scroll |
| **G4** | Audit log recent logins table scrolls internally with sticky header |
| **G5** | Cron Dashboard events table scrolls internally with sticky header |
| **G6** | Health checks scan results scroll internally with sticky header |
| **G7** | Webhooks deliveries log scrolls internally with sticky header |
| **G8** | RSS recent requests table scrolls internally with sticky header |
| **G9** | Hero stat cards on Audit log render with new tighter padding + 22px values |
| **G10** | Hero stat cards on Dashboard render with new tighter padding + 22px values |
| **G11** | Section intros (`.sn-prose` / `.sn-fieldset-intro`) render at 14px with 8px bottom margin |
| **G12** | Forms on Identity & SEO, Cloudflare, Login, Plausible NOT internally-scrolled (natural page scroll OK) |
| **G13** | Desktop-mode portal: confirm sticky nav works inside portal iframe (no z-index conflict with desktop-mode's own chrome) |
| **G14** | Vanilla wp-admin (non-portal): sticky nav doesn't overlap any wp-admin-native sticky elements |
| **G15** | No visual regressions on the audit log Prune button + LLA summary card + button placement |
| **G16** | Existing `.sn-status-box`, `.sn-callout`, form rows, etc. don't break under the tightened `--sn-space-4` / `--sn-space-5` |

---

## Section 7 — Edge cases + risks

**E1. CSS variable change ripples.** Changing `--sn-space-4` (15 callers) and `--sn-space-5` (8 callers) affects every selector using them. Risk: a callsite expecting 16px/24px now looks too tight. Mitigation: grep all callers and verify each visually post-deploy.

**E2. Sticky positioning + desktop-mode portal iframe.** Desktop-mode wraps admin pages in a chrome-extension portal iframe. Sticky position should still work (it's relative to the scrolling ancestor, which is the inner page document, not the iframe boundary). Risk: portal-injected CSS overrides our z-index. Verification gate G13 covers this.

**E3. Sticky `<thead>` inside `.widefat`.** WP-admin's default `.widefat` styling has thead background-color but no `position`. Our wrapper sticky-`<thead>` rule should layer cleanly on top. Risk: if WP core ever adds `position: sticky` to `.widefat thead th` itself in a future version, our rule becomes redundant but not broken.

**E4. Mobile/narrow viewports.** Sticky nav + internal scroll on a <500px viewport could look strange (everything sticks; not much room for content). Out of scope — SN admin is desktop-only per project convention. But: the sticky rule should gracefully degrade. No `@media (max-width)` carve-out needed at this scope.

**E5. Print stylesheet.** Sticky positioning has no effect in print. Internal-scroll wrappers may clip printed content. Risk: low — admin tables aren't typically printed. No print stylesheet change needed.

**E6. Re-running `git revert`.** If v3.8.6 breaks any page, `git revert v3.8.6` cleanly restores. All changes are CSS + small HTML wrappers — no migrations, no data state.

---

## Section 8 — Ship sequencing

**Version target:** plugin v3.8.6 (patch 6/7 in v3.8.x — 1 patch remains before v3.9.0 rollover).

**Wave plan** (single wave, one commit, since CSS-only + small HTML wrappers):

| Wave | Scope |
|---|---|
| 1 | All CSS changes + 6 HTML wrapper additions across 5 module files + version bump + CHANGELOG + commit + tag v3.8.6 + push + deploy |

Estimated LOC: ~80-120 lines CSS + ~12 lines HTML wrapper changes (6 wrappers × ~2 lines each) across 5 files. Single atomic commit (CSS density changes are coupled — splitting hides what's a single visual refresh).

**Deploy:** `gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref v3.8.6` (per project workflow — tag pushes don't auto-deploy since v1.10.1; deploy requires explicit gh workflow run with a `v[0-9]*` tag ref).

---

## Out of scope (explicitly)

- **No form-page restructure** (Q2 decision — Identity & SEO + others keep long-form layout)
- **No new JS** (sticky + internal scroll are pure CSS)
- **No mobile breakpoint changes** (SN admin is desktop-only)
- **No public-facing site CSS** (only `assets/admin.css`)
- **No collapsible / disclosure widgets** for sections
- **No restructuring of `.widefat` itself** (opt-in wrapper preserves backward compat)
- **No changes to default page-load tab/sub-tab** (sticky nav helps when user scrolls; doesn't change navigation flow)

---

## References

**Memory:**
- `feedback_skills_plugins_docs_always.md` — hard rule that drove this brainstorm (skill + read source + read docs)
- `feedback_no_brutalist_in_admin_ui.md` — wp-admin native classes + zero brutalist treatment
- `reference_desktop_mode_ai_copilot.md` — desktop-mode portal is primary user surface
- `feedback_desktop_mode_horizontal_submenu_warning.md` — earlier lesson about portal viewport differences

**Code reference:**
- `assets/admin.css` lines 47-52: `.sn-fieldset` padding (uses the variables we're tightening)
- `assets/admin.css` lines 118-154: `.sn-toc` (gets sticky positioning added)
- `assets/admin.css` lines 588-601: `.sn-2col` (already simplified in v3.8.5)
- `assets/admin.css` lines 630-672: `.sn-state-card` family (gets density tightening)
- `inc/audit-log-admin.php` `snt_audit_log_render_counter_table()` + `snt_audit_log_render_logins_table()` (tables to wrap)
- `inc/cron-dashboard-admin.php` (events table to wrap)
- `inc/health-checks-admin.php` (results table to wrap)
- `inc/webhooks-admin.php` (deliveries table to wrap)
- `inc/rss-plausible-tracker.php` `sn_rss_tracker_render_recent_table()` (recent table to wrap)

**Project conventions (CLAUDE.md):**
- Plugin patch cap: 7 per minor. v3.8.x currently at 5/7; v3.8.6 makes it 6/7. 1 patch remains before v3.9.0 rollover.
- Versioning: v3.8.6 is a PATCH (visual refinement of existing surfaces, no new capabilities, no behavior changes).

---

## What writing-plans should produce

When this spec is approved, the next step is `superpowers:writing-plans` to produce an implementation plan. That plan should:

1. **Single-wave task breakdown** matching Section 8.
2. **Verification gates** — the 16 gates from Section 6 wired into the wave completion check.
3. **Atomic commit** — single commit including all CSS + HTML wrapper changes + version bump + CHANGELOG.
4. **CHANGELOG entry** drafted for v3.8.6 — calling out the 6 tactics + the 6 wrapped tables in 5 files + the 23 CSS-variable callers tightened.
5. **Rollback plan** — clear path back to v3.8.5 if needed (single `git revert v3.8.6`).
