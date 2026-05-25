# Viewport-fit Admin Pages Implementation Plan (v3.8.6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make SN admin pages fit the desktop-mode portal viewport via sticky chrome (sub-tab nav + TOC) + internal-scroll wrappers for long tables (`.snt-scroll-table` opt-in) + system-wide density tightening (`.sn-fieldset` padding, hero stat cards, section intros).

**Architecture:** Pure CSS pass in `assets/admin.css` (~80-120 LOC added/changed) + 6 small HTML table-wrapper additions across 5 module files. No PHP logic changes. Density changes ripple via CSS variables (`--sn-space-4`, `--sn-space-5`) through 23 callsites — all tighten by ~25%, which is the intent.

**Tech Stack:** Vanilla CSS (no preprocessor, no framework), WordPress block theme + plugin admin UI, no JS framework, no test framework (manual smoke per the 16 gates from spec Section 6).

**Reference spec:** [`docs/superpowers/specs/2026-05-25-viewport-fit-admin-pages-design.md`](../specs/2026-05-25-viewport-fit-admin-pages-design.md) (commit `487cd8c`).

---

## File Structure

| File | Action | LOC est. | Responsibility |
|---|---|---|---|
| `assets/admin.css` | **Modify** | +80 / -8 lines | CSS variable tightening + sticky chrome (`.sn-sub-tabs`, `.sn-toc`) + `.snt-scroll-table` wrapper rules + hero stat-card density + `.sn-prose` / `.sn-fieldset-intro` density |
| `inc/audit-log-admin.php` | **Modify** | +4 lines | Wrap 2 tables (`sn-audit-timeline` at line 114, `sn-audit-logins` at line 151) in `<div class="snt-scroll-table">` |
| `inc/cron-dashboard-admin.php` | **Modify** | +2 lines | Wrap events table (line 116) in `<div class="snt-scroll-table">` |
| `inc/health-checks-admin.php` | **Modify** | +2 lines | Wrap scan results table (line 95) in `<div class="snt-scroll-table">` |
| `inc/webhooks-admin.php` | **Modify** | +2 lines | Wrap deliveries log table (line 118) in `<div class="snt-scroll-table">` |
| `inc/rss-plausible-tracker.php` | **Modify** | +2 lines | Wrap recent requests table (line 510) in `<div class="snt-scroll-table">` |
| `signal-and-noise-tools.php` | **Modify** | 1 line | Version bump 3.8.5 → 3.8.6 (docblock; SNT_VERSION derives) |
| `CHANGELOG.md` | **Modify** | +30 lines | v3.8.6 entry at top |

**6 files modified total.** No creates, no deletes.

---

## Verification Approach

CSS-only visual change. No automated tests. Verification is manual smoke testing against the **16 gates from spec Section 6** (G1-G16). Each gate is a quick browser check. Task 4 runs the full sweep.

---

## Commit Strategy

**Single atomic commit at the end of Task 3** (`v3.8.6: viewport-fit admin pages — sticky chrome + internal-scroll tables + density tightening`). Reasoning: CSS density changes are tightly coupled (the sticky offsets depend on the tightened padding values; the table wrappers depend on the new `.snt-scroll-table` rule existing). Splitting hides the coupled visual refresh and creates an intermediate state where some changes are live but not others.

**Working pattern:** complete Tasks 1-2 locally (CSS edits + HTML wrappers), run a quick `php -l` pass on all 5 PHP files modified, then commit + tag + push + deploy as Task 3. Task 4 runs gates post-deploy.

---

## Task 1: CSS edits in `assets/admin.css`

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/assets/admin.css` (multiple selector edits + 1 new rule block)

### Step 1.1: Tighten CSS variables `--sn-space-4` and `--sn-space-5`

Find lines 25-26 of `assets/admin.css`:

```css
	--sn-space-4:     16px;
	--sn-space-5:     24px;
```

Replace with:

```css
	--sn-space-4:     12px;
	--sn-space-5:     20px;
```

- [ ] **Step 1.1: Tighten the two CSS variables.**

### Step 1.2: Compact `.sn-prose` density

Find lines 35-41 of `assets/admin.css`:

```css
.sn-prose {
	color: var(--sn-text-muted);
	font-size: 0.95em;
	max-width: 680px;
	margin: 0 0 var(--sn-space-4);
	line-height: 1.5;
}
```

Replace with:

```css
.sn-prose {
	color: var(--sn-text-muted);
	font-size: 0.88em;          /* v3.8.6: tighter for viewport-fit (was 0.95em) */
	max-width: 680px;
	margin: 0 0 8px;            /* v3.8.6: tighter (was var(--sn-space-4) = 16px) */
	line-height: 1.5;
}
```

- [ ] **Step 1.2: Tighten `.sn-prose` font + margin.**

### Step 1.3: Compact `.sn-fieldset-h` top margin

Find the `.sn-fieldset-h` rule around line 219:

```css
.sn-fieldset-h {
	font-size: 1.15em;
	font-weight: 600;
	margin: 0 0 var(--sn-space-1);
	color: var(--sn-text);
	letter-spacing: 0.005em;
}
```

This rule is fine as-is (`var(--sn-space-1)` is `4px` and stays unchanged). No edit needed for the existing rule, but we ADD a top margin override:

```css
.sn-fieldset-h {
	font-size: 1.15em;
	font-weight: 600;
	margin: 16px 0 var(--sn-space-1);    /* v3.8.6: explicit top margin for inter-section rhythm */
	color: var(--sn-text);
	letter-spacing: 0.005em;
}
```

The change: `margin: 0 0 var(--sn-space-1)` → `margin: 16px 0 var(--sn-space-1)` (adds 16px top, keeps existing bottom).

- [ ] **Step 1.3: Add 16px top margin to `.sn-fieldset-h`.**

### Step 1.4: Compact `.sn-fieldset-intro` density

Find the `.sn-fieldset-intro` rule around line 227:

```css
.sn-fieldset-intro {
	color: var(--sn-text-muted);
	font-size: 0.88em;
	margin: 0 0 var(--sn-space-5);
	line-height: 1.5;
}
```

Replace with:

```css
.sn-fieldset-intro {
	color: var(--sn-text-muted);
	font-size: 0.88em;
	margin: 0 0 8px;                /* v3.8.6: tighter for viewport-fit (was var(--sn-space-5) = 20px now) */
	line-height: 1.5;
}
```

- [ ] **Step 1.4: Tighten `.sn-fieldset-intro` bottom margin.**

### Step 1.5: Add sticky positioning to `.sn-sub-tabs`

Find the `.sn-sub-tabs` rule around line 919:

```css
.sn-sub-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0 0 1.5em 0;
	padding: 4px;
	background: #f0f0f1;
	border-radius: 4px;
}
```

Replace with:

```css
.sn-sub-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0 0 1em 0;            /* v3.8.6: tighter for sticky context (was 1.5em) */
	padding: 4px;
	background: #f0f0f1;
	border-radius: 4px;
	/* v3.8.6: sticky below WP admin bar (32px desktop). Keeps sub-tab
	   context visible while user scrolls in-page. z-index keeps it above
	   table content scrolling past. */
	position: sticky;
	top: 32px;
	z-index: 10;
}
```

- [ ] **Step 1.5: Add sticky positioning + tightened margin to `.sn-sub-tabs`.**

### Step 1.6: Add sticky positioning to `.sn-toc`

Find the `.sn-toc` rule around line 120:

```css
.sn-toc {
	background: var(--sn-surface);
	border: 1px solid var(--sn-border);
	border-radius: var(--sn-radius);
	padding: var(--sn-space-3) var(--sn-space-4);
	margin: 0 0 var(--sn-space-5);
	display: flex;
	flex-wrap: wrap;
	gap: var(--sn-space-2) var(--sn-space-4);
	font-size: 0.9em;
}
```

Replace with:

```css
.sn-toc {
	background: var(--sn-surface);
	border: 1px solid var(--sn-border);
	border-radius: var(--sn-radius);
	padding: var(--sn-space-3) var(--sn-space-4);
	margin: 0 0 var(--sn-space-5);
	display: flex;
	flex-wrap: wrap;
	gap: var(--sn-space-2) var(--sn-space-4);
	font-size: 0.9em;
	/* v3.8.6: sticky below sub-tab nav (32px admin bar + ~48px sub-tab nav row).
	   z-index slightly below sub-tabs so the layering reads correctly. */
	position: sticky;
	top: 80px;
	z-index: 9;
}
```

- [ ] **Step 1.6: Add sticky positioning to `.sn-toc`.**

### Step 1.7: Tighten `.sn-state-card` (Dashboard hero)

Find the `.sn-state-card` rule around line 646:

```css
.sn-state-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 14px 16px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-height: 96px;
}
```

Replace with:

```css
.sn-state-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 10px 12px;          /* v3.8.6: tighter for viewport-fit (was 14px 16px) */
	display: flex;
	flex-direction: column;
	gap: 4px;                    /* v3.8.6: tighter gap (was 6px) */
	min-height: 76px;            /* v3.8.6: tighter min-height (was 96px) */
}
```

- [ ] **Step 1.7: Tighten `.sn-state-card` padding + gap + min-height.**

### Step 1.8: Tighten `.sn-state-card__value` font

Find the `.sn-state-card__value` rule around line 664:

```css
.sn-state-card__value {
	font-size: 1.4rem;
	font-weight: 500;
	color: #1d2327;
	margin: 0;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
}
```

Replace with:

```css
.sn-state-card__value {
	font-size: 1.2rem;           /* v3.8.6: tighter (was 1.4rem ≈ 22.4px → now ≈ 19.2px) */
	font-weight: 500;
	color: #1d2327;
	margin: 0;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
}
```

- [ ] **Step 1.8: Tighten `.sn-state-card__value` font.**

### Step 1.9: Tighten `.sn-audit-card` (Audit log hero)

Locate the `.sn-audit-card` rule. It's NOT in `assets/admin.css` — it's in `assets/audit-log.css` (created in v3.8.3). Open that file:

```bash
grep -n "sn-audit-card\b\|sn-audit-card-value" /Users/juanlentino/projects/signal-and-noise-tools/assets/audit-log.css
```

Find the `.sn-audit-card` and `.sn-audit-card-value` rules. They should look like:

```css
.sn-audit-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

...

.sn-audit-card-value {
	font-size: 28px;
	font-weight: 600;
	color: #1d2327;
	line-height: 1.1;
}
```

Replace the padding (`16px` → `12px`) and the value font (`28px` → `22px`):

```css
.sn-audit-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 12px;              /* v3.8.6: tighter for viewport-fit (was 16px) */
	display: flex;
	flex-direction: column;
	gap: 4px;
}

...

.sn-audit-card-value {
	font-size: 22px;            /* v3.8.6: tighter for viewport-fit (was 28px) */
	font-weight: 600;
	color: #1d2327;
	line-height: 1.1;
}
```

- [ ] **Step 1.9: Tighten `.sn-audit-card` padding + value font in `audit-log.css`.**

### Step 1.10: Append `.snt-scroll-table` rule block to `assets/admin.css`

Append this new rule block at the END of `assets/admin.css` (after the last existing rule):

```css

/* ─── Internal-scroll table wrapper (v3.8.6) ──────────────────────────────
   Opt-in via class="snt-scroll-table" on a div wrapping a long .widefat
   table. Caps the table at 50vh visible (~400px on an 800px viewport ≈ 12
   rows) and scrolls internally. The wrapped table's <thead> sticks at the
   wrapper's top so column headers stay visible during internal scroll.

   Pattern usage in PHP module:
       echo '<div class="snt-scroll-table">';
       echo '<table class="widefat">';
       echo '<thead>...</thead><tbody>...</tbody>';
       echo '</table>';
       echo '</div>';

   Used by: audit-log-admin (counter timeline + recent logins), cron-
   dashboard-admin (events), health-checks-admin (scan results), webhooks-
   admin (deliveries log), rss-plausible-tracker (recent requests).
*/
.snt-scroll-table {
	max-height: 50vh;
	overflow-y: auto;
	border: 1px solid var(--sn-border);
	border-radius: var(--sn-radius);
	margin: 0 0 var(--sn-space-4);
}

.snt-scroll-table .widefat {
	border: none;               /* outer wrapper owns the border */
	box-shadow: none;
	margin: 0;
}

.snt-scroll-table .widefat thead th {
	position: sticky;
	top: 0;
	background: #f0f0f1;        /* WP-admin native thead bg */
	z-index: 1;
}
```

- [ ] **Step 1.10: Append `.snt-scroll-table` rule block at end of `admin.css`.**

### Step 1.11: Verify CSS parses (no syntax errors)

CSS doesn't have a parser equivalent to `php -l`, but we can use a lightweight check via `npx`:

```bash
npx -y css-validator --version 2>/dev/null || echo "no css-validator; relying on browser dev tools"
```

If `css-validator` isn't trivially available, skip — the visual gates in Task 4 catch any syntax errors via "the page renders unstyled" symptom.

Quicker check: grep for unbalanced braces:

```bash
awk '{ gsub(/[^{]/, ""); o+=length } END { print "open braces:", o }' /Users/juanlentino/projects/signal-and-noise-tools/assets/admin.css
awk '{ gsub(/[^}]/, ""); c+=length } END { print "close braces:", c }' /Users/juanlentino/projects/signal-and-noise-tools/assets/admin.css
```

Expected: open and close counts match.

- [ ] **Step 1.11: Confirm brace counts match in `admin.css`.**

---

## Task 2: HTML wrapper additions (6 wraps across 5 files)

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/audit-log-admin.php` (2 wraps: lines 114-128 and 151-158, approximately)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/cron-dashboard-admin.php` (1 wrap: line 116 + matching close)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/health-checks-admin.php` (1 wrap: line 95 + matching close)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/webhooks-admin.php` (1 wrap: line 118 + matching close)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/inc/rss-plausible-tracker.php` (1 wrap: line 510 + matching close)

### Step 2.1: Wrap counter timeline table in `audit-log-admin.php`

Find this block in `inc/audit-log-admin.php` (around lines 110-130):

```php
function snt_audit_log_render_counter_table( $counters ) {
	echo '<h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
	echo '<table class="widefat sn-audit-timeline">';
	echo '<thead><tr>';
	...
	echo '</tbody>';
	echo '</table>';
}
```

Wrap the `<table>` open and close in `<div class="snt-scroll-table">` / `</div>`. The exact change: add one echo BEFORE the `<table>` open and one echo AFTER the `</table>` close.

Replace:

```php
function snt_audit_log_render_counter_table( $counters ) {
	echo '<h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
	echo '<table class="widefat sn-audit-timeline">';
```

With:

```php
function snt_audit_log_render_counter_table( $counters ) {
	echo '<h2 class="sn-fieldset-h">Counter timeline (last 30 days)</h2>';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat sn-audit-timeline">';
```

And replace (the matching close at end of this function):

```php
	echo '</tbody>';
	echo '</table>';
}
```

With:

```php
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}
```

- [ ] **Step 2.1: Wrap counter timeline table in audit-log-admin.php.**

### Step 2.2: Wrap recent logins table in `audit-log-admin.php`

Find this block in the SAME file (around lines 145-160):

```php
function snt_audit_log_render_logins_table( $logins ) {
	echo '<h2 class="sn-fieldset-h">Recent successful logins (last 30 days)</h2>';
	if ( empty( $logins ) ) {
		echo '<p class="sn-prose">No successful logins recorded in this window.</p>';
		return;
	}
	echo '<table class="widefat sn-audit-logins">';
	echo '<thead><tr><th>Timestamp</th><th>User</th></tr></thead>';
	...
	echo '</tbody>';
	echo '</table>';
}
```

Same wrap pattern. Replace:

```php
	echo '<table class="widefat sn-audit-logins">';
```

With:

```php
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat sn-audit-logins">';
```

And replace the closing `</table>` line:

```php
	echo '</tbody>';
	echo '</table>';
}
```

With:

```php
	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}
```

- [ ] **Step 2.2: Wrap recent logins table in audit-log-admin.php.**

### Step 2.3: Wrap cron events table in `cron-dashboard-admin.php`

Find line 116 of `inc/cron-dashboard-admin.php`:

```php
	echo '<table class="widefat striped" id="sn-cron-table">';
```

Add the wrap-open echo on the line BEFORE it:

```php
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped" id="sn-cron-table">';
```

Find the matching `</table>` close. Run:

```bash
grep -n '</table>' /Users/juanlentino/projects/signal-and-noise-tools/inc/cron-dashboard-admin.php
```

This locates the closing tag (should be the next `</table>` after line 116). Add `echo '</div>';` on the line AFTER that close.

Example: if the matching close is at line 175 and reads:
```php
	echo '</table>';
```

Replace with:

```php
	echo '</table>';
	echo '</div>';
```

- [ ] **Step 2.3: Wrap cron events table in cron-dashboard-admin.php.**

### Step 2.4: Wrap scan results table in `health-checks-admin.php`

Find line 95 of `inc/health-checks-admin.php`:

```php
		echo '<table class="widefat striped" style="margin-top:0.5rem;"><thead><tr>';
```

This is a more complex one because the inline style + thead are on the same line. Add a wrapping div on the line BEFORE:

```php
		echo '<div class="snt-scroll-table">';
		echo '<table class="widefat striped" style="margin-top:0.5rem;"><thead><tr>';
```

Find the matching `</table>` close:

```bash
grep -n '</table>' /Users/juanlentino/projects/signal-and-noise-tools/inc/health-checks-admin.php
```

Add `echo '</div>';` on the line AFTER the close.

- [ ] **Step 2.4: Wrap scan results table in health-checks-admin.php.**

### Step 2.5: Wrap deliveries log table in `webhooks-admin.php`

Find line 118 of `inc/webhooks-admin.php`:

```php
			echo '<table class="widefat striped" style="margin-top:0.5rem; font-size:0.85em;"><thead><tr>';
```

Same pattern. Add wrap-open BEFORE:

```php
			echo '<div class="snt-scroll-table">';
			echo '<table class="widefat striped" style="margin-top:0.5rem; font-size:0.85em;"><thead><tr>';
```

Find matching close:

```bash
grep -n '</table>' /Users/juanlentino/projects/signal-and-noise-tools/inc/webhooks-admin.php
```

Add `echo '</div>';` after the close.

- [ ] **Step 2.5: Wrap deliveries log table in webhooks-admin.php.**

### Step 2.6: Wrap recent requests table in `rss-plausible-tracker.php`

Find line 510 of `inc/rss-plausible-tracker.php`:

```php
	echo '<table class="widefat striped">';
```

The render function name is `sn_rss_tracker_render_recent_table()` starting around line 503. Add wrap-open BEFORE the table:

```php
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped">';
```

Find matching `</table>` close (within the same function):

```bash
grep -n '</table>' /Users/juanlentino/projects/signal-and-noise-tools/inc/rss-plausible-tracker.php
```

Add `echo '</div>';` after the close.

- [ ] **Step 2.6: Wrap recent requests table in rss-plausible-tracker.php.**

### Step 2.7: Verify all 5 modified PHP files parse

```bash
for f in inc/audit-log-admin.php inc/cron-dashboard-admin.php inc/health-checks-admin.php inc/webhooks-admin.php inc/rss-plausible-tracker.php; do
  php -l "/Users/juanlentino/projects/signal-and-noise-tools/$f"
done
```

Expected: each prints `No syntax errors detected in <path>`.

- [ ] **Step 2.7: Confirm all 5 `php -l` checks pass.**

---

## Task 3: Version bump + CHANGELOG + commit + tag + push + deploy

**Files:**
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/signal-and-noise-tools.php:6` (Version header)
- Modify: `/Users/juanlentino/projects/signal-and-noise-tools/CHANGELOG.md` (new entry at top)

### Step 3.1: Bump version in plugin docblock

Find line 6 of `signal-and-noise-tools.php`:

```php
 * Version:     3.8.5
```

Replace with:

```php
 * Version:     3.8.6
```

SNT_VERSION derives from this docblock at load time (per v3.8.2 retrofit) — no other constant edits needed.

- [ ] **Step 3.1: Bump `Version: 3.8.5` → `3.8.6`.**

### Step 3.2: Add CHANGELOG entry at top

Open `CHANGELOG.md` and add this entry at the TOP (immediately under the file's main heading + intro, before the previous most-recent entry — currently the `## [3.8.5] - 2026-05-25` block):

```markdown
## [3.8.6] - 2026-05-25

### Changed — Viewport-fit admin pages (system-wide CSS pass)

SN admin pages now fit the desktop-mode portal viewport via sticky chrome + internal-scroll for long tables + density tightening. Dashboard-app feel: chrome stays anchored (sub-tab nav + TOC + hero cards always visible), data regions (tables) scroll internally with sticky `<thead>` so column headers stay visible during scroll. Forms keep natural page scroll (users tab through fields).

**6 tactics:**

1. **Sub-tab nav becomes sticky** below the WP admin bar (`top: 32px`)
2. **TOC nav sticky** below the sub-tab nav (`top: 80px`) — used on Identity & SEO
3. **Long tables get `.snt-scroll-table` wrapper** with `max-height: 50vh`, sticky `<thead>`, scoped border. 6 wrappers across 5 module files: audit-log-admin (counter timeline + recent logins), cron-dashboard-admin (events), health-checks-admin (scan results), webhooks-admin (deliveries log), rss-plausible-tracker (recent requests)
4. **Hero stat cards tightened** — `.sn-audit-card` padding 16→12px, value font 28→22px; `.sn-state-card` padding 14/16→10/12px, value font 1.4→1.2rem, min-height 96→76px
5. **`.sn-fieldset` density tightened** via CSS variable updates — `--sn-space-4` 16→12px, `--sn-space-5` 24→20px; ripples through 23 callsites; all UI tightens by ~25%
6. **Section intros (`.sn-prose` / `.sn-fieldset-intro`) compacted** — 0.95→0.88em font, margins to 8px

**Pages affected:**

- Audit log, Cron Dashboard, Content Health, Webhooks, RSS Recent: get internal-scroll tables + sticky chrome + tighter density
- Dashboard: tighter density only (recent-deploys list is short)
- Identity & SEO, Cloudflare, Login, Plausible, Webhooks form: tighter density + sticky chrome; forms keep natural page scroll (users tab through fields)
- Insights, Reading Time: tighter density only

**Verification:** 16 manual smoke gates in the spec (G1-G16). The big wins: sub-tab nav never scrolls off, table headers stay visible during in-table scroll, hero cards stay anchored at top of every page.

**File diff:**

- Modified: `assets/admin.css` (+80 / -8 lines: variable tightening, sticky chrome, density, `.snt-scroll-table` rule)
- Modified: `assets/audit-log.css` (+0 lines, 2 line replacements: card padding + value font)
- Modified: `inc/audit-log-admin.php` (+4 lines: 2 wrappers)
- Modified: `inc/cron-dashboard-admin.php` (+2 lines: 1 wrapper)
- Modified: `inc/health-checks-admin.php` (+2 lines: 1 wrapper)
- Modified: `inc/webhooks-admin.php` (+2 lines: 1 wrapper)
- Modified: `inc/rss-plausible-tracker.php` (+2 lines: 1 wrapper)
- Modified: `signal-and-noise-tools.php` (version bump)

**Patch 6/7 in v3.8.x.** 1 patch remains before v3.9.0 rollover.

```

- [ ] **Step 3.2: Add CHANGELOG entry at top.**

### Step 3.3: Commit + tag + push + deploy

Run these commands (using HEREDOC for the commit message per project convention):

```bash
cd /Users/juanlentino/projects/signal-and-noise-tools

git add CHANGELOG.md signal-and-noise-tools.php assets/admin.css assets/audit-log.css \
        inc/audit-log-admin.php inc/cron-dashboard-admin.php inc/health-checks-admin.php \
        inc/webhooks-admin.php inc/rss-plausible-tracker.php

git commit -m "$(cat <<'EOF'
v3.8.6: viewport-fit admin pages — sticky chrome + internal-scroll + density

System-wide CSS pass making SN admin pages fit the desktop-mode portal
viewport with dashboard-app feel: chrome stays anchored, data regions
(tables) scroll internally with sticky <thead> so column headers stay
visible during in-table scroll.

6 tactics:
1. .sn-sub-tabs sticky below WP admin bar (top: 32px, z-index: 10)
2. .sn-toc sticky below sub-tab nav (top: 80px, z-index: 9)
3. .snt-scroll-table opt-in wrapper for long tables (max-height: 50vh,
   sticky <thead>, scoped border); 6 wraps across 5 module files
4. Hero stat cards tightened (.sn-audit-card + .sn-state-card; padding
   -25%, value font reduced)
5. .sn-fieldset density tightened via CSS variable updates
   (--sn-space-4 16→12px, --sn-space-5 24→20px; ripples through 23
   callsites; all UI tightens by ~25%)
6. .sn-prose + .sn-fieldset-intro compacted (font + margin)

Pure CSS pass plus 6 small HTML table-wrapper additions. No PHP logic
changes. Forms keep natural page scroll (users tab through fields).

Patch 6/7 in v3.8.x. 1 patch remains before v3.9.0 rollover.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"

git tag -a v3.8.6 -m "v3.8.6 — viewport-fit admin pages (sticky chrome + internal-scroll)"

git push origin main
git push origin v3.8.6

gh workflow run deploy.yml --repo juanlentino/signal-and-noise-tools --ref v3.8.6
```

After `gh workflow run` returns, wait for deploy completion:

```bash
sleep 8
RUN_ID=$(gh run list --repo juanlentino/signal-and-noise-tools --limit 1 --json databaseId --jq '.[0].databaseId')
until gh run view $RUN_ID --repo juanlentino/signal-and-noise-tools --json status --jq '.status' 2>/dev/null | grep -q "completed"; do sleep 5; done
gh run view $RUN_ID --repo juanlentino/signal-and-noise-tools --json status,conclusion
```

Expected: `{"conclusion":"success","status":"completed"}`.

- [ ] **Step 3.3: Commit + tag + push commit + push tag + trigger deploy + wait for completion.**

### Step 3.4: Confirm v3.8.6 live on production

```bash
ssh -i ~/.ssh/cloudways_deploy master_syguxtyfsh@157.245.116.64 \
  'php -r "include \"/home/master/applications/nffqxsrgxz/public_html/wp-load.php\"; echo SNT_VERSION;"' \
  2>&1 | grep -v WARNING | grep -v vulnerable | grep -v upgraded
```

Expected: `3.8.6`.

- [ ] **Step 3.4: Confirm SNT_VERSION=3.8.6 on production.**

---

## Task 4: Verification gate sweep (G1-G16) — manual, no commits

**Files:**
- No file changes. Manual verification only.

### Step 4.1: Hard-refresh + visit each affected page

The user should hard-refresh (Cmd+Shift+R) in the desktop-mode portal at `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options` to pick up the new CSS (cache-busted via SNT_VERSION change from 3.8.5 to 3.8.6).

Then walk through each gate:

| Gate | Page to visit | What to check |
|---|---|---|
| **G1** | `?tab=site`, `?tab=security`, `?tab=automation`, `?tab=monitoring`, `?tab=tools` | Sub-tab nav stays at top of viewport when scrolling page content |
| **G2** | `?tab=site&sub=identity-and-seo` | TOC nav ("Jump to Identity / Social / Open Graph / SEO Copy") stays at top of content area below sub-tab nav when scrolling |
| **G3** | `?tab=security&sub=audit-log` | Counter timeline table scrolls internally within ~50vh region; `<thead>` row stays visible during internal scroll |
| **G4** | `?tab=security&sub=audit-log` | Recent logins table scrolls internally with sticky `<thead>` |
| **G5** | `?tab=automation&sub=cron` | Cron events table scrolls internally with sticky `<thead>` |
| **G6** | `?tab=monitoring&sub=health` | Scan results table scrolls internally with sticky `<thead>` |
| **G7** | `?tab=automation&sub=webhooks` | Deliveries log table scrolls internally with sticky `<thead>` (if there are any deliveries; otherwise just verify section renders) |
| **G8** | `?tab=monitoring&sub=rss` | Recent requests table scrolls internally with sticky `<thead>` |
| **G9** | `?tab=security&sub=audit-log` | Audit log hero stat cards render with new tighter padding + 22px value font (visibly more compact than before) |
| **G10** | `?tab=dashboard` (or just `?page=sn-theme-options`) | Dashboard hero stat cards render with tighter padding + smaller font (visibly compact) |
| **G11** | Any page with `.sn-prose` or `.sn-fieldset-intro` (e.g., audit log opening paragraph, identity form intros) | Intro paragraph at 14px ~ish font with 8px bottom margin (visibly tighter than v3.8.5) |
| **G12** | `?tab=site&sub=identity-and-seo`, `?tab=site&sub=cloudflare`, `?tab=security&sub=login`, `?tab=monitoring&sub=plausible` | Forms NOT internally-scrolled — natural page scroll OK, users can tab through fields without scroll-jacking |
| **G13** | Desktop-mode portal | Sticky nav renders correctly inside portal iframe (no z-index conflict with desktop-mode chrome) |
| **G14** | Vanilla wp-admin (open `https://juanlentino.com/wp-admin/admin.php?page=sn-theme-options` in a regular browser tab, not the portal) | Sticky nav doesn't overlap wp-admin-native sticky elements (e.g., the admin bar itself) |
| **G15** | `?tab=security&sub=audit-log` | LLA summary card + Prune button still render correctly at bottom of page; no overflow issues |
| **G16** | Any page | `.sn-status-box`, `.sn-callout`, form rows, status pills don't break under the tightened `--sn-space-4` / `--sn-space-5` (look for cramped or overflowing content) |

### Step 4.2: Fix-forward any gate failures

If any gate fails: identify the failing case, propose a fix-forward (likely a small CSS adjustment), commit + tag as v3.8.7. Don't proceed to Task 5 until all gates pass OR the user accepts known limitations.

For known classes of issue:
- **Sticky nav not actually sticky:** check that the parent container doesn't have `overflow: hidden` (which kills sticky positioning). Common culprit: WP-admin's own wrapper.
- **Table not scrolling internally:** verify the `.snt-scroll-table` wrapper actually exists in the page HTML (view source / inspector). The PHP wrap might have a bracket mismatch.
- **Sticky `<thead>` not sticking:** verify the wrapper has `overflow-y: auto` (not `hidden`).
- **Hero cards looking weird:** check that `.sn-audit-card` rules in `audit-log.css` updated correctly (this file is enqueued only on the audit-log sub-tab).
- **Density too tight on a specific selector:** add a per-selector override; don't revert the global tightening.

- [ ] **Step 4.2: Fix-forward any failures with targeted edits + new patch tag if needed.**

---

## Task 5: Update reconciliation handoff (optional)

**Files:**
- Modify: `/Users/juanlentino/Projects/signal-and-noise/.claude/worktrees/nice-goldstine-063551/docs/superpowers/handoffs/2026-05-25-roadmap-reconciliation.md` (or wherever the latest handoff lives)

### Step 5.1: Add a session-end addendum noting v3.8.6 shipped

Append (or update existing addendum) to record:

```markdown
- **v3.8.6** (2026-05-25): viewport-fit admin pages — sticky chrome + internal-scroll for long tables + system-wide density tightening; pure CSS pass + 6 small HTML wrappers; no PHP logic changes.
```

Then commit + push the theme repo:

```bash
cd /Users/juanlentino/Projects/signal-and-noise/.claude/worktrees/nice-goldstine-063551
git add docs/superpowers/handoffs/2026-05-25-roadmap-reconciliation.md
git commit -m "docs(handoff): note v3.8.6 viewport-fit shipped"
git push origin HEAD:main
```

- [ ] **Step 5.1: Update + push reconciliation handoff.**

---

## Rollback plan

If v3.8.6 introduces a problem post-deploy:

1. **Quick revert (recommended):** `git revert v3.8.6` on main, push, redeploy via `gh workflow run`. Restores v3.8.5 visual state. No data state to worry about (CSS-only + HTML wrappers).
2. **Targeted fix-forward:** for a single failing gate, ship v3.8.7 with the specific selector/wrap fixed. Cheaper than a full revert.
3. **Hard revert:** `git reset --hard v3.8.5`, force-push. Avoid unless absolutely necessary (rewriting history on shared branches).

CSS variable changes leave no persistent state, so any rollback path returns the visual UI to the prior state cleanly.

---

## Self-review

Performed inline after writing. Checked against spec sections + verification gates:

- **Spec coverage:** every spec Section 1-8 element has a corresponding Task 1-5 step. All 16 gates from spec Section 6 are wired into Task 4. The `.snt-scroll-table` pattern matches spec Section 3 exactly (max-height, sticky thead, scoped border). The CSS variable tightening matches spec Section 4 (`--sn-space-4: 12px`, `--sn-space-5: 20px`). The hero card density matches spec Section 4. The sticky offsets (32px, 80px) match spec Section 2.
- **Placeholder scan:** no TBDs, no "implement later," no "similar to Task N." Every code block is complete and copy-pasteable.
- **Type consistency:** N/A (CSS + HTML, no function signatures). All CSS selectors used in later steps are defined in earlier steps (`.snt-scroll-table`, `.snt-scroll-table .widefat`, `.snt-scroll-table .widefat thead th`).
- **One callout:** Step 1.9 assumes `.sn-audit-card` rules exist in `audit-log.css` from v3.8.3. Verified earlier in this session that the file exists and contains those rules. Step 1.9 explicitly says to grep first to confirm before editing — safety net if anything changes.

No issues found. Plan is self-contained and executable.
