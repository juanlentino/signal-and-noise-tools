# UI/UX audit — plugin admin

**Scanned:** 2026-05-25

## Summary

The admin UI is architecturally coherent — the 6-tab + sub-tab IA is correct, the `.sn-fieldset` / `.sn-field` design system is used consistently in the newer tabs, and the v4.0.3 modal pattern is in place for Health destructive confirms. The main rough edges are:

- **Three `window.confirm` calls remain** after v4.0.3's migration: Cron tab (2× JS), Webhooks Delete (1× PHP `onclick`), Insights Dismiss (1× PHP `onclick`), and RSS Tracker (2× PHP `onclick`).
- **`sn-badge` used in Cron tab with no CSS definition** — the class is rendered but never styled.
- **Inline styles dominate the JS modal and verdict panels** — `openApplyModal`, `renderVerdictSuggestion`, `buildDriftModalContent`, and `buildOrphanDeleteModalContent` set styles via `setAttribute('style', ...)` for every element, totalling ~38 inline style calls; none of this is in `admin.css`.
- **`--sn-space-3` and `--sn-space-4` are identically 12px** — a meaningless alias that causes confusion when reading the CSS.
- **Stale user-facing copy**: "Net-new in v3.6.0." in the Insights intro paragraph; "v1 finds problems; the editor is the fix surface" in Health; a stale JS comment says inline-img findings get no Suggest button (they do since v4.0.2).

---

## Findings

### U-01 [severity: high] — `window.confirm` still used in Cron, Webhooks, Insights, RSS tabs

**Surface:**
- `assets/cron-dashboard.js:125` and `:288` — Run Now + Unschedule confirmations
- `inc/webhooks-admin.php:108` — Delete webhook `onclick="return confirm(...)"`
- `inc/insights-admin.php:152` — Dismiss recommendation `onclick="return confirm(...)"`
- `inc/rss-plausible-tracker.php:498` and `:538` — Reset to Defaults + Purge log `onclick="return confirm(...)"`

**Issue:**
v4.0.3 migrated health-suggest's Apply confirmation to `openApplyModal`, but the five remaining `window.confirm` calls were not migrated. The comment at `health-suggest-actions.js:686` says "v4.0.3: replace window.confirm() with Before/After preview modal" — that intent was not applied to the other tabs.

**Why it matters:**
`window.confirm` is blocked by most browsers in cross-origin iframes (desktop-mode portal), produces a non-styleable native dialog inconsistent with every other modal in the UI, and cannot be keyboard-dismissed the same way. The Unschedule confirmation in particular contains multi-line warning text that renders poorly in native confirm dialogs.

**Proposed fix:**
For Cron Run Now + Unschedule: replace `window.confirm` with an inline confirmation UX — either the `openApplyModal` pattern (if the shared JS is available on the Cron page) or a simpler inline button swap ("Are you sure? [Confirm] [Cancel]" replacing the clicked button text). For the PHP `onclick` buttons (Webhooks Delete, Insights Dismiss, RSS Reset/Purge): move the confirmation to an `openApplyModal`-style modal, OR at minimum convert to a data-attribute-driven pattern so the JS can intercept and show a minimal confirm UI without a page-blocking native dialog.

**Risk:** medium — behavioral change to destructive confirms. Test all five call sites.

---

### U-02 [severity: high] — `sn-badge` and `sn-badge-warn` classes have no CSS definition

**Surface:** `inc/cron-dashboard-admin.php:136` and `:140`

**Issue:**
The Cron tab renders "SN" and "orphan" badges:
```php
echo ' <span class="sn-badge" title="…">SN</span>';
echo ' <span class="sn-badge sn-badge-warn" title="…">orphan</span>';
```
Neither `sn-badge` nor `sn-badge-warn` appears in `assets/admin.css` or `assets/audit-log.css`. The badges render as unstyled inline text — visually indistinguishable from surrounding code text, defeating the purpose of the visual affordance.

**Why it matters:**
The "SN" badge is supposed to visually distinguish Signal & Noise–owned events from WordPress core events. The "orphan" badge warns about dangling scheduled hooks. Both are invisible if unstyled.

**Proposed fix:**
Add `.sn-badge` and `.sn-badge-warn` to `assets/admin.css`. The existing `.sn-pill` pattern is similar but uses `border-radius:999px` (oval). A badge is conventionally a smaller, squarish chip: `display:inline-block; padding:1px 6px; border-radius:3px; font-size:0.75em; font-weight:600; background:rgba(0,0,0,0.07); color:var(--sn-text);`. The `--warn` variant should use `background:rgba(219,166,23,0.15); color:#6e4d00;`.

**Risk:** low — additive CSS only.

---

### U-03 [severity: high] — Modal and verdict panel styles are 100% inline JS; no CSS rule covers them

**Surface:** `assets/health-suggest-actions.js` — `openApplyModal` (lines 97–225), `renderVerdictSuggestion` (517–596), `buildDriftModalContent` (303–350), `buildOrphanDeleteModalContent` (645–678), `renderSuggestion` (421–501)

**Issue:**
Every DOM element constructed in the JS uses `setAttribute('style', '...')` directly. The modal backdrop, box, header, body, footer, panes, text spans, and action buttons all have inline style strings. There are approximately 38 `setAttribute('style', ...)` calls in this one file. `admin.css` has no rules for `.snt-modal-backdrop`, `.snt-modal-box`, `.snt-modal-header`, `.snt-modal-body`, `.snt-modal-footer`, `.snt-suggest-panel`, `.snt-verdict-panel`, or `.snt-suggest-textarea`.

**Why it matters:**
1. The modal cannot be restyled without touching JS — every visual tweak requires a code change, not a CSS change.
2. Inline styles on the modal box override any future `prefers-color-scheme` or `prefers-reduced-motion` CSS that might be added.
3. The `admin.css` comment at line 576 says "v1.14.0 — Admin UI unified pattern" and that "NO inline styles" is a design principle — this file was written before that principle was formalized.

**Proposed fix:**
Extract the inline style values into named classes in `admin.css`: `.snt-modal-backdrop`, `.snt-modal-box`, `.snt-modal-header`, `.snt-modal-footer`, `.snt-modal-pane-before`, `.snt-modal-pane-after`, `.snt-suggest-panel`, `.snt-verdict-panel`. The JS sets classNames instead of style attributes. Keep only the dynamic computed values (the `grid-template-columns` that switches on `isMobile`) as inline styles, since those are genuinely dynamic.

**Risk:** medium — visual regression possible if CSS specificity differs from inline styles. Test modal at multiple viewport widths.

---

### U-04 [severity: medium] — `--sn-space-3` and `--sn-space-4` are both 12px; the alias adds confusion

**Surface:** `assets/admin.css:24–25`

```css
--sn-space-3:     12px;
--sn-space-4:     12px;
```

**Issue:**
Both tokens resolve to 12px. This is not a spacing scale — it's a coincidence or a vestige of a prior scale where `space-4` was 16px (the comment "was var(--sn-space-4) = 16px" on line 39 confirms the original intent was 16px). Now both tokens are identical, so a developer reading `padding: var(--sn-space-4) var(--sn-space-5)` cannot know whether `space-4` will remain 12px or whether `space-3` is available as a synonym.

**Why it matters:**
Future spacing changes are error-prone: changing `--sn-space-4` to restore the 16px value would break components that expected both tokens to be equal. Conversely, some components may be using `--sn-space-3` and `--sn-space-4` interchangeably, making it hard to establish which token is "correct."

**Proposed fix:**
Decide: either restore `--sn-space-4` to 16px (auditing every use site for layout impact), or collapse both tokens to a single `--sn-space-3: 12px` and replace all `--sn-space-4` references with `--sn-space-3`. Add a code comment explaining the scale intent.

**Risk:** low — CSS token rename. All usages are in one file.

---

### U-05 [severity: medium] — Insights Dismiss uses `window.confirm`; but also, the `.sn-fieldset-actions` wrapper is used as a `<form>` element

**Surface:** `inc/insights-admin.php:145`

```php
echo '<form method="post" class="sn-fieldset-actions" style="display:inline-block;">';
```

**Issue:**
This form element has both `class="sn-fieldset-actions"` AND `style="display:inline-block;"`. The CSS for `.sn-fieldset-actions` sets `display:flex` with `justify-content:flex-end`. Applying `display:inline-block` via inline style overrides the flex layout, producing a form wrapper that neither flex-aligns the buttons nor inline-aligns them consistently with adjacent text. The intent (make the action buttons appear inline with the card, not as a full-width block) is reasonable but the execution overrides the design system class.

**Why it matters:**
The action buttons ("Mark done", "Snooze 30d", "Dismiss") inside each recommendation card appear visually detached from the card border because the override breaks the expected `.sn-fieldset-actions` padding and border-top separator. This is inconsistent with every other use of `.sn-fieldset-actions` in the codebase.

**Proposed fix:**
Either (a) don't use `.sn-fieldset-actions` as the form wrapper — use a `<div class="sn-fieldset-actions">` containing `<button>` elements that each submit a separate hidden-field form, or (b) add a `.sn-fieldset-actions--inline` modifier to `admin.css` that sets `display:inline-flex` and removes the border-top, and apply that instead of the inline style override.

**Risk:** low.

---

### U-06 [severity: medium] — Stale user-visible copy in Insights intro: "Net-new in v3.6.0."

**Surface:** `inc/insights-admin.php:32`

```php
echo '<p class="sn-prose">…<strong>Net-new in v3.6.0.</strong></p>';
```

**Issue:**
The plugin is now at v4.1.0. "Net-new in v3.6.0" is a developer-facing release note that has no meaning to a user looking at this tab for the first time. It reads as dead copy.

**Why it matters:**
Users navigating the admin do not know what v3.6.0 is or why it matters. The note adds nothing and signals that the UI copy has not been maintained.

**Proposed fix:**
Remove the "Net-new in v3.6.0." sentence entirely. The feature speaks for itself via the tab's subtitle and the run form.

**Risk:** trivial — copy-only change.

---

### U-07 [severity: medium] — Health tab intro copy says "v1 finds problems; the editor is the fix surface"

**Surface:** `inc/health-checks-admin.php:33`

```php
echo '<p class="sn-prose">Detection-only scans of your post + attachment graph. v1 finds problems; the editor is the fix surface. Results cache for 24 hours.</p>';
```

**Issue:**
The Health tab now ships Suggest+Apply for alt text, drift phrases, and orphaned media (as of v4.1.0). The sentence "v1 finds problems; the editor is the fix surface" directly contradicts this — it implies there is no Apply action in this tab, which is wrong. The "v1" label is also meaningless to users.

**Why it matters:**
A user who reads this intro will not realize AI-assisted fix actions are available in the same table, or may be confused when they see "AI fix" column headers and Suggest buttons.

**Proposed fix:**
Replace with: "Detection scans of your post and attachment graph. AI-assisted fixes for alt text, time-phrase drift, and orphaned media are available inline. Results cache for 24 hours."

**Risk:** trivial — copy-only change.

---

### U-08 [severity: medium] — Stale JS comment says inline-img findings get no Suggest button

**Surface:** `assets/health-suggest-actions.js:369–372`

```js
// Note: v4.0.0 only handles attachment-alt + drift findings.
// Inline-img findings get no Suggest button at the PHP-render
// layer (see sn_health_render_suggest_cell in inc/health-checks-admin.php).
```

**Issue:**
This comment was accurate at v4.0.0 but is wrong since v4.0.2. `sn_health_render_suggest_cell` in `inc/health-checks-admin.php` now emits a Suggest button for `missing_alt_inline` findings (lines 172–178), and the JS dispatch table at line 38 routes `missing_alt_inline` to `ai-alt-inline-suggest`. The comment says the opposite of what the code does.

**Why it matters:**
A developer reading this comment to understand the inline-img code path will be misled about where the Suggest button comes from.

**Proposed fix:**
Replace the stale comment with: "Inline-img findings emit a Suggest button since v4.0.2 — see `missing_alt_inline` handling below."

**Risk:** trivial — comment-only change.

---

### U-09 [severity: medium] — New-webhook "secret" highlighted with inline `style="background:#fffbcc;"` not a CSS class

**Surface:** `inc/webhooks-admin.php:97`

```php
echo '<input type="text" readonly value="..." class="sn-mono" style="background:#fffbcc;">';
```

**Issue:**
The freshly-generated webhook secret is highlighted in yellow to signal "copy this now." This is done via an inline style with a hardcoded hex value (`#fffbcc`) that has no CSS variable counterpart or class. The color is reasonable but is not part of the established palette (which uses `--sn-ok`, `--sn-warn`, `--sn-err` for semantic colors).

**Why it matters:**
The "new webhook highlighted" pattern (`border-left:3px solid var(--wp--preset--color--blood, #e00404)` on the fieldset + yellow input background) is inconsistent: the fieldset highlight uses a CSS variable, the input uses a hardcoded non-palette hex. If the palette ever changes this field will not track.

**Proposed fix:**
Add `.snt-input-highlight` to `admin.css` with `background: #fffbcc;` (or reuse a palette-adjacent tint like `rgba(219,166,23,0.12)` for consistency with `--sn-warn`). Remove the inline style.

**Risk:** low.

---

### U-10 [severity: medium] — Modal box lacks `role="dialog"` and `aria-modal="true"` / `aria-labelledby`

**Surface:** `assets/health-suggest-actions.js:109–111` (modal box construction in `openApplyModal`)

**Issue:**
The modal `div.snt-modal-box` has no `role="dialog"`, `aria-modal="true"`, or `aria-labelledby` pointing to the `h2` title element. The focus trap (lines 206–213) correctly redirects focus back into the box on `focusin`, but screen readers will not announce this as a dialog and will not suppress background content appropriately.

**Why it matters:**
Screen reader users: (1) will not hear "dialog" announced on modal open, (2) will be able to "escape" the modal via virtual cursor and browse background content while the modal is open, (3) will not have the modal title read as the dialog's accessible name.

**Proposed fix:**
```js
box.setAttribute('role', 'dialog');
box.setAttribute('aria-modal', 'true');
titleEl.id = 'snt-modal-title-' + Date.now(); // unique per instance
box.setAttribute('aria-labelledby', titleEl.id);
```

**Risk:** low — additive ARIA attributes.

---

### U-11 [severity: medium] — Cron tab filter input uses inline `style="width:320px; padding:6px 10px;"` instead of a class

**Surface:** `inc/cron-dashboard-admin.php:113`

```php
echo '<input type="search" id="sn-cron-filter" placeholder="…" style="width: 320px; padding: 6px 10px;" />';
```

**Issue:**
This is the only search/filter input in the plugin admin. Its dimensions are set inline, inconsistent with all other inputs in the system (which use `.sn-field` + width modifier classes like `.sn-field-w-sm`/`.sn-field-w-md`).

**Why it matters:**
Minor visual inconsistency — the input's sizing does not respond to the same CSS variable changes that would affect other inputs. Also, it is not wrapped in a `.sn-field` container, so it has no associated `.sn-field-label` — the label is `class="screen-reader-text"` only, which means the visual label is absent for sighted users. Sighted users have to guess what the search box filters (the placeholder suffices, but it disappears on focus).

**Proposed fix:**
Wrap in `<div class="sn-field sn-field-w-sm">` with a visible `.sn-field-label` and remove the inline style. Or at minimum, promote the inline styles to a utility class `.snt-filter-input` in `admin.css`.

**Risk:** low.

---

### U-12 [severity: low] — Audit log uses its own `.sn-audit-card` component instead of the shared `.sn-state-card`

**Surface:** `assets/audit-log.css` (`.sn-audit-state-grid`, `.sn-audit-card`, `.sn-audit-card-label`, `.sn-audit-card-value`, `.sn-audit-card-sub`) vs `assets/admin.css` (`.sn-state-grid`, `.sn-state-card`, `.sn-state-card__label`, `.sn-state-card__value`, `.sn-state-card__meta`)

**Issue:**
The Dashboard tab and the Audit log sub-tab both render a 4-card hero stat grid. The Dashboard uses `.sn-state-grid` + `.sn-state-card__*` from `admin.css`. The Audit log uses `.sn-audit-state-grid` + `.sn-audit-card*` from `audit-log.css`. The styles are near-identical (same 4-column grid, same border/radius/padding, same font sizes) but are duplicated in separate files with different class names.

**Why it matters:**
Any visual tweak to the hero card pattern (tighter padding, different label font size) must be applied in two places. `audit-log.css` is only loaded on the audit-log sub-tab, making a unified card style impossible without restructuring the enqueue.

**Proposed fix:**
Promote `.sn-state-card` to the shared `admin.css` as the canonical hero card class (it already is there). Update `audit-log-admin.php` to use `.sn-state-card` classes instead of `.sn-audit-card*`. The `audit-log.css` can then be reduced to Audit-log-specific table styles (`.sn-audit-timeline`, `.sn-audit-row-empty`).

**Risk:** low — CSS rename, verify visual output.

---

### U-13 [severity: low] — Dashboard maintenance cards use `class="button"` without a hierarchy marker for two secondary actions

**Surface:** `inc/admin-tab-dashboard.php:154, :160`

```php
echo '<button type="submit" … class="button">Clear Overrides</button>';
echo '<button type="submit" … class="button">Purge All Caches</button>';
```

**Issue:**
The three maintenance cards use three different button weights: "Full Reset" is `button-primary`, "Clear Overrides" and "Purge All Caches" are bare `class="button"` (secondary), and "Check for Updates" is also bare `class="button"`. This is correct WP hierarchy (primary for most-destructive, secondary for reversible), but the visual result is that three of the four action cards look equally de-emphasized. There is no clear primary action for a user doing routine maintenance.

**Why it matters:**
"Purge All Caches" is the most-common routine action (after pushing a new release, you purge caches). It should probably be `button-primary`, or at least visually separated from the destructive full-reset action.

**Proposed fix:**
Make "Purge All Caches" use `button-primary`, and make "Full Reset" use `button-link-delete` (red destructive style) since it is the most destructive action. Leave "Clear Overrides" as bare `button`. "Check for Updates" can remain bare `button` since it's informational.

**Risk:** low — button class change, no logic change.

---

### U-14 [severity: low] — Insights evidence pills all use `sn-pill--ok` (green) regardless of evidence type

**Surface:** `inc/insights-admin.php:133`

```php
echo '<span class="sn-pill sn-pill--ok" style="margin-right:0.5rem;">' . esc_html( $pill ) . '</span>';
```

**Issue:**
Every evidence pill on every recommendation card renders as green (`sn-pill--ok`). Evidence pills are data snippets ("3 posts in 7 days", "top 20% by views") — not status indicators. Using the "ok" semantic color for all evidence makes them visually indistinguishable from a status signal, and the green color carries an unintended "good" connotation for neutral data points.

**Why it matters:**
Minor semantic mismatch. A user scanning the card will see green pills and interpret them as "all good" rather than as supporting evidence for the recommendation.

**Proposed fix:**
Use the base `.sn-pill` (no semantic modifier) for evidence pills — it renders with a neutral gray background. Reserve `sn-pill--ok` / `sn-pill--warn` / `sn-pill--err` for status-type pills (the recommendation type badge, scan status). Also remove the inline `style="margin-right:0.5rem;"` — add a `gap` to the parent `<p>` or a `.sn-pill + .sn-pill` selector in `admin.css`.

**Risk:** trivial — class change and minor CSS addition.

---

### U-15 [severity: low] — `setStatus` helper function is duplicated in three separate post-editor JS files

**Surface:**
- `assets/ai-meta-description.js:127`
- `assets/ai-excerpt.js:148`
- `assets/ai-og-card-title.js:111`

**Issue:**
The same `setStatus(node, text, kind)` function (switching on `'ok'/'warn'/'err'/'info'` to set `node.style.color`) is copy-pasted identically in three separate files. Each copy has the same color values (`#0a5a1a`, `#6e4d00`, `#8b1a1a`, `#646970`). A fourth copy exists in `health-suggest-actions.js:71`.

**Why it matters:**
Any palette change (e.g., moving to CSS variables instead of hardcoded hex) must be applied in four separate files. One copy will eventually drift if a color is changed in one file but not the others.

**Proposed fix:**
Extract `setStatus` into a shared `assets/admin-utils.js` that all four files depend on. This is a larger refactor with enqueue-dependency implications. Alternatively, convert all four copies to use CSS classes (`node.className = 'snt-status--ok'` etc.) instead of `style.color`, and define `.snt-status--ok { color: var(--sn-ok-text, #0a5a1a); }` in `admin.css`. The CSS-class approach is the cleanest long-term fix.

**Risk:** low (additive CSS) to medium (if dependency graph is changed for the shared JS file).
