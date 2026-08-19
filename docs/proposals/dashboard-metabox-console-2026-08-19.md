# The Dashboard becomes a real console

**Status:** design, approved 2026-08-19
**Supersedes:** the *presentation* half of [dashboard-mission-control-2026-08-19.md](dashboard-mission-control-2026-08-19.md).
That proposal's rules — `unknown` is a third state, absence is never zero, a view preference must not
hide a problem — all stand. Its **layout** does not.

---

## Why the first design failed

v11.28.0 implements the collapse rule faithfully and still isn't a command center. Measured on the
shipped page:

| Region | Share of viewport |
|---|---|
| Three zones — the entire site status, as three sentences | 10% |
| Measurement strip | 4% |
| Maintenance — four buttons pressed occasionally | 33% |
| Empty | 53% |

The hierarchy is inverted and half the canvas does nothing.

The root cause is in the proposal, not the code. **"State earns space" describes what alarms do. It
never says what the page IS when nothing is wrong** — which is nearly always. Applied literally, a
healthy site renders three grey lines above a maintenance panel. A flight deck shows every gauge at
all times and lets alarms assert themselves *over* that density; it is not empty when calm.

Two corroborating signals: the surface is stock wp-admin cards, the "dashboard-by-numbers" pattern the
project's own design rules reject; and the desktop-mode widgets in the right rail (Post Stats, Deploy
Status) are denser and more console-like than the Dashboard tab itself.

## What survives

The entire pure layer. Nothing below is rewritten:

- `sn_dash_zone_state()` — now drives a status dot instead of a collapse decision
- `sn_admin_card_wants_attention()` — the single shared opt-out predicate
- `sn_dash_zone_attention()`, `sn_dash_zone_fleet()`, `snt_dashboard_fleet_components()`
- `sn_dash_measurement_figures()`, `sn_dash_render_measurement_strip()`
- `snt_gsc_window_totals()`
- `inc/dash-deploy-rows.php`, `inc/dash-api-summary.php`, `inc/dash-debug-info.php`

What gets thrown away is exactly the part that was wrong: the presentation.

---

## Architecture

The tab becomes a **metabox host**. `snt_dashboard_tab_render()` stops rendering content and instead:

1. registers boxes (gated on the active tab — see *Screen Options is per-screen* below)
2. enqueues `postbox` and calls `postboxes.add_postbox_toggles()`
3. emits the `#poststuff` / `#post-body.columns-2` shell with the `closedpostboxesnonce` and
   `meta-box-order-nonce` fields
4. calls `do_meta_boxes()` for the `normal` and `side` contexts

WordPress then supplies drag-to-reorder, collapse, and Screen Options show/hide **natively**, and
persists all of it per user.

### The verified contract

Checked against WP core source 2026-08-19, per the framework-source-first rule. Do not re-derive.

| Concern | Value | Source |
|---|---|---|
| Collapsed boxes | user meta `closedpostboxes_{page}` | `wp_ajax_closed_postboxes()` |
| **Hidden** boxes (Screen Options) | user meta **`metaboxhidden_{page}`** | `wp_ajax_closed_postboxes()` |
| Box order | user meta `meta-box-order_{page}` | `wp_ajax_meta_box_order()` |
| Column count | user meta `screen_layout_{page}` | `wp_ajax_meta_box_order()` |
| Nonce actions | `closedpostboxes` and `meta-box-order` | both handlers |
| Script handle | `postbox` | — |
| JS init | `postboxes.add_postbox_toggles( page, args )`, `page` = screen id | `wp-admin/js/postbox.js` |
| Sortable container | `.meta-box-sortables` | `wp-admin/js/postbox.js` |

**Gotcha, recorded because it will bite:** the AJAX *actions* are `closed-postboxes` and
`meta-box-order`, but the *nonce action* for the first is `closedpostboxes` — **hyphen in the action,
none in the nonce.** Getting these to match each other silently breaks persistence with no error:
the drag works, the state just never comes back.

`$page` is run through `sanitize_key()` in both handlers. Our screen id
`toplevel_page_sn-theme-options` survives it unchanged, so the meta keys are stable.

`wp_ajax_closed_postboxes()` performs **no capability check** beyond the nonce. That is not a new
exposure — it writes only the calling user's own meta — but it means the box state is a per-user
preference with no privilege dimension, which is what we want.

**Also verified:** `add_meta_box()` accepts a screen id created by `add_menu_page()`;
`do_meta_boxes( $screen, $context, $data_object )` renders one context;
`WP_Screen::render_screen_options()` calls `render_meta_boxes_preferences()` unconditionally, and
that method early-returns unless `isset( $wp_meta_boxes[ $screen->id ] )`.

### The boxes

| Context | Box | Wraps |
|---|---|---|
| `normal` | **Systems** | `sn_dash_zone_attention()` cards, one row each |
| `normal` | **Fleet** | `snt_dashboard_fleet_components()` + folded recent deploys |
| `normal` | **Traffic** | `sn_analytics_daily_series()` → 30-day area chart |
| `side` | **At a glance** | `sn_dash_measurement_figures()` + the sparkline |
| `side` | **Maintenance** | the four existing actions, same form and nonce |
| `side` | **Diagnostics** | the override list, unchanged |

Each callback is a thin wrapper over an existing builder. **No box computes anything new.**

A box whose data source is entirely absent is **not registered**, rather than rendering an empty
shell — the same discipline as `sn_admin_glance_grid()`'s empty guard.

### The briefing band

A fixed band above the metabox holder, stating the situation in one sentence:

> **Everything is holding.** 103 views this week, up 39 on last. All 33 notes are anchored, nothing has
> cited you yet, and the Remote MCP worker is still warming its cache.

It is **chrome, not a box**: not draggable, not collapsible, not hideable. The reason is the safety
property below, not consistency.

---

## Data flow

Box callbacks run **in whatever order the user dragged them into**. If each box fetched its own data,
dragging a box would change how many outbound probes fire — behaviour coupled to layout.

Everything therefore routes through one memoized `snt_dashboard_snapshot()`, gathered once per
request: deploy structs, worker status, glance cards, recent runs, override count, measurement data.
Boxes read from it and never fetch.

**`snt_deploy_workers_status()` is called exactly once per render**, which also retires the
double-call introduced in #728.

### Probe budget and the warming fix

`probe_budget` stays at **1**. The v11.11.4 reasoning holds: a cold page load must not fan out five
live HTTP calls.

What changes is the *readout*. Today a cold worker sets `measured => false`, which forces the whole
fleet to `unknown` — so the page reports its own probe budget as a fact about the fleet, and says
"1 of 7 never probed" while the Deploy Status widget beside it shows all seven with versions (that
widget's ability uses `probe_budget => 5`, `inc/abilities-system.php:444`).

The fix reads the worker's own `reason`:

| Worker state | Reads as | Fleet box state |
|---|---|---|
| `live` present | the version | `ok` |
| `state === 'unknown'` and `reason === 'warming'` | `warming…` | `ok` — pending, not unknown |
| `state === 'unknown'`, any other reason | `unknown` | `unknown` — real missing evidence |

This is the v11.16.0 lesson applied one layer up: **cold is not broken.**

---

## The safety property

The superseded design guaranteed *a pin can open a zone, never close one* — a view preference must
not be able to hide a problem. Metaboxes reintroduce that risk twice: a box can be **collapsed**, and
it can be **hidden entirely** in Screen Options.

Two mechanisms preserve the guarantee:

1. **The state dot lives in the box TITLE, not the body.** Collapsing hides detail, never state — a
   collapsed Systems box still shows amber when something needs attention.
2. **The briefing band is the backstop.** Because it is fixed chrome, it states the situation even
   when every box on the page has been hidden. This is the whole reason it is not arrangeable.

---

## What gets deleted

| File / function | Why |
|---|---|
| `inc/dash-pins.php` (whole module, incl. the REST route) | Core stores collapse state and box order per user already. This module reimplemented a subset of it. |
| `sn_dash_zone_is_open()` | The postbox toggle replaces it. |
| `sn_dash_render_zone()` | The postbox replaces it. |
| `tests/dash-pins.php` | Follows its module. |
| The **SN Deploy Status** desktop widget | Closes the fifth cut from the superseded proposal — the one the implementation plan omitted. The Fleet box now carries all seven versions, so the widget is genuinely redundant rather than merely duplicative. |

`inc/dash-zones.php` keeps `sn_dash_zone_state()` and `SN_DASH_STATES`; it loses the renderer and the
open/closed decision.

---

## Edge cases

- **Screen Options is per-screen, not per-tab.** Every tab shares the screen id
  `toplevel_page_sn-theme-options`. Boxes are registered only when `tab=dashboard` is active, so on
  other tabs nothing is registered and the preferences panel renders empty — **verified**, not
  hoped: `render_meta_boxes_preferences()` early-returns unless `$wp_meta_boxes[ $screen->id ]` is
  set.
- **A hidden box is not a collapsed box.** They are different meta keys
  (`metaboxhidden_{page}` vs `closedpostboxes_{page}`) and the safety property has to hold for both.
  Collapse is answered by the title dot; hiding is answered by the briefing band.
- **A user with every box hidden** still gets the briefing band. Verified by test.
- **Absent accessors** render "not measured", never `0` — the existing discipline, unchanged.
- **JS disabled**: boxes render open and un-draggable; the page is still fully readable. Collapse is
  progressive enhancement.
- **`snt_dashboard_snapshot()` on a screen that is not the Dashboard tab** returns early and fetches
  nothing.

---

## Testing

The pure builders are already covered and do not change. New properties to pin:

1. Boxes register **only** when `tab=dashboard`.
2. `snt_deploy_workers_status()` fires **exactly once** per render, regardless of how many boxes
   render or in what order — behaviour must not depend on layout.
3. A **warming** worker leaves the Fleet box `ok`; a worker whose probe **failed** makes it `unknown`.
   Mutation-controlled, since this is the defect being fixed.
4. The state dot renders inside the box **title**, so a collapsed box still reports state.
5. The briefing band renders even when every box is hidden.
6. A box with no data source is not registered at all.

Existing suites to update: `tests/dashboard-layout.php`, `tests/admin-tab-dashboard-glance.php`.

Every new guard is negative-controlled by mutation, and a mutation killed by a **fatal** rather than a
failed assertion does not count as pinned.

---

## Out of scope

- **The GSC 250-row cap.** `snt_gsc_window_totals()` sums stored `pages` rows, and the page dimension
  is capped at 250, so on a large site the clicks figure is an undercount. Documented at the
  accessor; the fix is a separate site-total query without the page dimension.
- **A live/realtime box.** `sn_analytics_realtime()` exists and would suit a console, but it needs a
  refresh mechanism and a cost discussion of its own.
- Any change to the desktop-mode widget system beyond removing the one redundant widget.
