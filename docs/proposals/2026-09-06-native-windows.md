# The native windows — S&N Dashboard and S&N Analytics in the shell's idiom

**Date:** 2026-09-06 · **Issue:** #1083 · **Supersedes:** the host design in
[2026-09-06-openstation-hosts.md](./2026-09-06-openstation-hosts.md) (v13.104.0, v13.105.0,
v13.105.1), which the owner rejected on live: *"Nothing was ported... I've even lost the tabs."*

## What was wrong with the hosts

The hosts captured the classic admin page's wp-admin markup and painted it inside a framework
window, with the top tab in window state and the page's own strip painted in the body — because a
server action cannot switch a framework tab, and the design chose one view. v13.105.1 then pinned
wp-admin's light canvas onto the window. Beside the shell's own Posts window that is the old page
in a box: no title-bar tabs, no kit, wp-admin colours. The v13.98.0 lesson already on record — *a
window on the desktop must be built from the shell's own parts in the shell's own idiom* — was
not applied.

## The rebuild, from the upstream guide

Read against `docs/app-framework.md`, `docs/components-reference.md`, the component sources'
`static help` blocks and the shell's own apps in OpenStation 1.1.6 (`scratchpad/openstation-src`
during the build):

- **Framework tabs.** `App::tab( $slug, [ label, view, position ] )` registers a window tab; the
  shell builds the strip in the window chrome (`.os-window__tab`), and `Window.activateTab()` switches
  it from JS. *"Tabs are server views. Each tab panel is its own session — same declared state
  shape, separate values, `$os->view` tells an action which tab dispatched it."* A client bundle
  attaches to the main view only, so a window that wants title-bar tabs is a server-view window.
  The first tab is labelled with the window title unless `main_tab_label` says otherwise — set
  through `openstation_app_window_args`.
- **The kit from PHP.** `<os-table os-prop-columns os-prop-data>` feeds a property-driven component
  from markup (the runtime assigns the parsed JSON after every paint); `<os-histogram series
  columns start end>` reads JSON attributes; `<os-form os-action="post">` collects every `[name]`
  descendant, kit fields included, and ships them as `$args['values']`; `os-bind` writes a control's
  pick into state and repaints; `os-confirm` asks first; `os-segmented`/`os-tabs` emit `os-pick` /
  `os-tab-change`, which the runtime treats as the natural event. Every attribute a painter uses is
  in the component's help block; an invented name is silent.
- **Measured in the sandbox before building** (a probe app, `05-sn-kit-probe`): the strip paints
  with the tab labels; a server-painted `<os-stat>`, `<os-section>`, `<os-histogram>` render dark
  from the shell's tokens; `os-prop-*` fills a table; an `<os-form os-action>` ships kit fields;
  a bound segmented control and a bound `<os-tabs>` repaint through state.

## The design

**S&N Dashboard** (`apps/sn-dashboard`): the main view is the Dashboard tab; Site, Content,
Connections, Measurement, AI, Security, Integrity are framework tabs from `sn_admin_top_tabs()`.
State per session: `sub`, `anchor`, `flash`, `notice`, `params`, `post` — no `tab`. A tab view
(`parts/frame.php`) paints the notice, the sub-leaf strip (`<os-tabs class="os-app-list__tabs"
os-bind="sub">`, a pick repaints the tab with that leaf) and the leaf through its painter, registered
under `tab/sub` through `snt_os_dashboard_painters`. Painters live one per leaf under
`parts/leaves/`; the kit vocabulary they speak is `inc/openstation-kit*.php` (escaping mirrored on
the framework's `Html` helpers; stat, section, notice, badge, chip, code, empty, tabs; table,
histogram, list, facts; form and fields; buttons, one-click actions, doors, in-window links,
external links). Writes: an `<os-form os-action="post">` carries `sn_action` and the nonce, and the
`post` action replays through the pipelines the hosts built (capability, nonce, handler table,
flash → `<os-notice>` + toast); a one-click button carries `action` + `nonce` as arguments. Links
inside the window: same tab → `go`; another tab → `.snt-go[data-snt-tab]`, which the companion
script (`assets/os-kit.js`) turns into `activateTab()` plus a `go` on that tab's session.

**S&N Analytics** (`apps/sn-analytics`): Overview is the main view, the twelve other views are
tabs from `snt_analytics_views()`; state per session is the page's nine parameters. The frame
paints, in the classic composer's order, the notice, the AE diagnostic, the insights band, the
controls, the header region, the drill-down panel, the view body and the empty note, each through
a painter under `chrome/<piece>` or `view/<slug>` (`snt_os_analytics_painters`). A control's pick
reaches `go` as `{ key, value }` and `picked()` turns it into the next query by the classic link
rules (window args for range and class; `off` drops `sn_compare`); a link or form still ships the
whole next query, wholesale. The companion carries the window and the class across a tab switch,
as the classic tab link does.

**Faithful** means every leaf, field, action, readout and control of the classic page survives.
The leaf suites (`tests/os-leaf-*.php`, `tests/os-painter-*.php` on `tests/lib/os-leaf-harness.php`)
compare field names, `sn_action` values and wp-admin markers against the classic renderer; the app
suites (`tests/openstation-app-dashboard.php`, `tests/openstation-app-analytics.php`) hold the
port-complete guard — a leaf or piece without a painter fails the suite, so the classic scaffold
cannot ship.

## What changed shape, and why

Recorded per leaf in each builder's report and in the suites: script-only behaviours (DOM
reorder buttons, the trend brush, the suggest editor that mounts into a table cell) have no
counterpart in a window that runs no page script; the classic wp-admin layout classes (two-column
shells, field widths) become the kit's own layout; inline `<code>` inside a field hint becomes
plain text where the hint is an attribute. Nothing is dropped silently: every such change is in
the report and the suite.

## Verification

Sandbox at 1280×860 under the shell's palette: the Dashboard window with its eight title-bar tabs,
the Dashboard tab's verdict, stats, histogram, systems wall, detail lists and maintenance cluster;
the Security → Login leaf as a kit form; the Site tab's bound sub-strip round-tripping through
state; the Analytics window with its thirteen tabs. Screenshots, not DOM reads, decide.
