# S&N Analytics host — contract plan (#1075)

**Spec:** docs/proposals/2026-09-06-openstation-hosts.md. **Map:** `map/port-map.json` key `map:analytics` (13 views: forms, `$_GET`, links, scripts, endpoints per view, and the summary of the page's structure). **Depends on:** host one's `inc/openstation-host.php` (capture, rewrite, replay, notice) and `assets/os-host.js` — reuse, never copy.

## Tasks (three Opus builders on disjoint files)

### Task A — the host (server)

Files: `apps/sn-analytics/sn-analytics.os.php` (NEW), `apps/sn-analytics/parts/view.php`, `parts/state.php` (NEW), the window-args seam entries for `sn-analytics`, `inc/desktop-mode-dock.php` (if the analytics page has a manual dock item, remove it the same way), `tests/openstation-host-analytics.php` (NEW).

1. State = the page's params, named as the page names them minus the `sn_` prefix: `view`, `range`, `from`, `to`, `class`, `compare`, `drill`, `event_prop`, `lg_range`, `notice`. `parts/state.php`: `snt_os_analytics_apply( State $state, array $args )` applies args THROUGH THE PAGE'S OWN RESOLVERS (`snt_analytics_resolve_view`, `snt_analytics_resolve_window` for range/from/to, `snt_analytics_resolve_class`, `snt_analytics_resolve_compare`; drill/event_prop/lg_range validated the way the views validate them — read inc/analytics-admin.php and the view files) and, when `view` changes, resets exactly the keys in `snt_analytics_view_reset_params()` (source-parity pin: the list in the test is read from that function, never retyped) and never `compare`. `snt_os_analytics_get( State $state )` builds the `$_GET` array (`sn_view`, `sn_range`, …, `page` => 'sn-analytics') the dispatcher reads.
2. `sn-analytics.os.php`: `App::define( 'sn-analytics' )` — title 'S&N Analytics', icon the page's menu icon, size 1280×860, placement dock, `capabilities( 'manage_options' )`; `mount`/`reopen` read params through the same apply; actions `go` (args → apply; `values` from the custom-date GET form are args too), `post` (only for the export form — see 4), `door`, `refresh`; view: the `<h1>Analytics</h1>` and the notice as inc/analytics-dashboard-page.php prints them, then `snt_os_host_rewrite( snt_os_host_capture( 'snt_analytics_render_dashboard', snt_os_analytics_get( $state ) ), array( 'sn-analytics', 'sn-theme-options' ) )` — the tab strip is the page's own (rewritten to `go` by the pass), the custom-date form becomes `go`, the class/compare pills become `go`.
3. Links into `sn-theme-options` (the settings CTA when unconfigured; the Measurement → Analytics door) become `door` (the classic page opens as an admin window) — a cross-host `go` is out of scope until the doors program.
4. The export form (POST, streams CSV/JSON): the rewrite pass must leave it a REAL form — extend `snt_os_host_rewrite()` with a keep-list (`data-snt-keep-form` set by the host on forms whose `sn_action` is the export action, read from the map) that sets `action` to the classic page URL and `target="_blank"`. Recorded deviation. Pin it.
5. Assets: the page's own handles (read inc/analytics-dashboard-page.php's enqueue and inc/analytics-admin*.php: the admin css, tokens, brush, the chart script(s), any localized data) appended through the seam, plus `snt-os-host`.
6. Tests: definition; every `SN_ANALYTICS_VIEWS` slug reachable through `go` and unknown → overview; the reset list parity; range/class/compare through the resolvers (a bad range falls back exactly as the page falls back); `$_GET` shape equals what the page reads (a pin that lists the `$_GET` keys the dispatcher reads by scanning inc/analytics-admin.php:389-440 and asserts the host emits each); the export form kept; assets appended once.

### Task B — assets

Only if a view's inline script or chart needs a re-arm beyond `os-host.js` (the map lists 3 scripts per view); otherwise no new file. `tests/openstation-host-assets.php` extended if anything changes.

### Task C — docs

CHANGELOG [Unreleased], compat seams, spec amendments.

## After the builders

Re-run, sandbox (13 views over two ranges and a custom window, a class pill, a compare pill, a drill, the login-defense range, the export form opening a new tab, the unconfigured gate's door), four-lens review, fold, PR "Fixes #1075", CI, merge, cut MINOR.
