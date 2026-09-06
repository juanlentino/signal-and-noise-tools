# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **S&N Analytics opens as a native OpenStation window too.** `sn-analytics`
  is the second App Framework app on the seam host one built
  (`apps/sn-analytics/`, reusing `inc/openstation-host.php` and
  `assets/os-host.js` rather than copying them), and what it paints IS the
  classic Analytics page: the `<h1>Analytics</h1>` and the flash notice
  `inc/analytics-dashboard-page.php` prints, then the whole body from the
  SAME `snt_analytics_render_dashboard()` dispatcher under an output
  buffer, with `$_GET` set from the window's state for the length of the
  capture and restored after it. All thirteen views of
  `SN_ANALYTICS_VIEWS` paint -- Overview, Content, Campaigns, Posts,
  Technology, Geography, Engagement, Sessions, Quality, Search, Events,
  Traffic & edge and Login defense -- each from its own render callable,
  with the shared header region, the insights band, the Movers and Uptime
  rail, the drill-down panel and Login defense's own chrome exactly as the
  page renders them. Nothing is redesigned, dropped or simplified.

- **The URL was the state, and the window keeps the same state with the
  same validators.** `view`, `range`, `from`, `to`, `class`, `compare`,
  `drill`, `event_prop` and `lg_range` live in window state and are applied
  through the page's OWN resolvers -- `snt_analytics_resolve_view()`,
  `snt_analytics_resolve_window()`, `snt_analytics_resolve_class()`,
  `snt_analytics_resolve_compare()` -- so an unknown view falls back to
  Overview where the page falls back, a malformed custom window falls back
  to the range the page falls back to, and a bookmarkable classic URL and a
  window state are the same fact. A view switch resets exactly the keys
  `snt_analytics_view_reset_params()` names and never `compare`, which is
  the one param that deliberately rides along; the list is read from that
  function, never retyped. Every navigation on this page is a plain GET on
  the classic page and none of them can navigate the top document inside a
  window, so each becomes a `go` through the rewrite pass: the thirteen-tab
  strip, the range dropdown's custom-date `<form method="get">`, the
  human/suspect/bot class pills, the off/prev/yoy compare pills, the
  cross-tab drill-down links and the Overview's doorway links into
  Sessions, Content, Campaigns, Geography, Technology and Posts. Links out
  to the settings page -- the unconfigured gate's CTA into Measurement ->
  Analytics -- become `door` and open the classic Dashboard page as an
  admin window: the mirror of host one, where `sn-analytics` is the door.
  The classic page at `admin.php?page=sn-analytics` is untouched and still
  reachable.

- **One deviation, recorded rather than hidden: the export form stays a
  real form.** The toolbar's CSV and JSON buttons post
  `sn_action=analytics_export` to `admin.php`, and its handler
  (`sn_handle_analytics_export`) renders no HTML at all -- it sets
  `Content-Disposition`, echoes a raw CSV or JSON body and `exit()`s. A
  download is a navigation, and a view whose contract is "echo HTML that
  gets captured" cannot be one. So `snt_os_host_rewrite()` gains a
  keep-list: a form the host marks stays a real `<form method="post">`,
  keeps its `action` pointed at the classic page URL and gains
  `target="_blank"`, so the export downloads in a new tab -- the least a
  window can do, and the same file by the same handler with the same
  `sn_theme_options_nonce`. A second limitation is restated rather than
  discovered: the analytics panels' collapse state persists to
  `localStorage` and is restored by a pass that runs once over `document`
  at parse time, outside `window.snAdmin.init( root )`, so inside a window
  the panels open and close but do not come back the way they were left.
  (#1075)

- The Analytics window's `range` is declared as a string. The framework's
  `State` coerces every write onto the declared default's type and falls
  back when the shapes disagree; declared as the integer 7, `custom` and
  the seven calendar presets silently became the rolling week. The three
  host suites' `State` stubs now coerce exactly as the framework does, so a
  typed default can never hide a string param again. (#1075)

### Changed
- The Analytics menu is no longer auto-imported onto the dock as a URL
  tile. `add_menu_page( 'S&N Analytics', …, 'sn-analytics',
  'dashicons-chart-area' )` was picked up by the shell's automatic dock
  import -- the `dock_placement` filter in `inc/desktop-mode-dock.php` had
  only ever hidden `sn-theme-options` -- so with the app registering its own
  entry under the same id there would have been two tiles naming one thing,
  the trap v13.100.0 fixed. The filter now hides `sn-analytics` as well and
  the app's own tile carries the same title and the same chart icon.
  `snt_desktop_admin_url()` keeps its `sn-analytics` special case
  unchanged, so every door that opens the classic Analytics URL today still
  opens it. (#1075)

## [13.104.0] - 2026-09-06 — the S&N Dashboard host

### Added
- **The S&N Dashboard opens as a native OpenStation window.** `sn-dashboard`
  is an App Framework app (`apps/sn-dashboard/`) instead of a dock shortcut
  to the classic admin page, and what it paints IS the classic admin page:
  the 8 top tabs and 34 leaves of `sn_admin_top_tabs()` plus the two spliced
  ones (Machine Readers, Search Console), read from the registry on every
  paint rather than baked at definition, each leaf produced by the SAME
  render callable through `sn_admin_render_active_tab()` under an output
  buffer -- wrapper classes, sub-tab strip, notices and all. Nothing is
  redesigned, dropped or simplified. The desktop document IS wp-admin
  (`body.wp-admin.wp-core-ui`, with core's `common`, `forms`, `dashboard`,
  `list-tables`, `buttons`, `edit` and `media` stylesheets already loaded --
  measured, not assumed), so the leaves' admin HTML styles natively inside
  the window and **no iframe is used**. One pass with core's
  `WP_HTML_Tag_Processor` rewrites only the seams that would navigate the top
  document or never fire: a `<form method="post">` becomes `os-action="post"`,
  a link into our own pages becomes `os-action="go"` carrying `tab`, `sub`,
  the `#sn-sec-*` fragment and every `sn_*` query param as `os-arg-*`
  attributes, a link to any other admin URL becomes `os-action="door"` and
  opens as a shell admin window, and every rewritten link loses its `href`
  (the runtime `preventDefault`s a submit but never a click, so a surviving
  href would navigate the desktop out from under itself). A save runs the
  classic pipeline minus the two things a window cannot do (`header()` and
  `exit`): the form's fields arrive as `$args['values']`, the action
  re-checks `manage_options`, verifies the same `sn_theme_options_nonce`,
  looks `sn_action` up in the same `sn_admin_post_handlers()` table, calls
  the same handler with `$_POST` slashed exactly as `admin-post.php` slashes
  it, and maps the returned flash code through the same
  `sn_admin_flash_to_notice()` into the same
  `<div class="notice notice-{severity}">` above the tab strip -- and a toast
  -- landing on the tab, sub-tab and `#sn-sec-*` anchor
  `sn_admin_post_redirect_target()` names. An action that is not in the
  handler table runs nothing and says "Nothing was saved." rather than
  reporting a save. Two deviations are recorded rather than hidden: an
  external link that carries no `target` gains
  `target="_blank" rel="noopener noreferrer"`, because in the classic page
  such a link replaces the admin tab and in a window it would replace the
  whole desktop; and the `sn_force_update_check` door opens `update-core.php`
  as an admin window through `open_url` instead of navigating to it, which
  reaches the same screen by the only mechanism a window has. Faithful means
  the destination still happens, not that the mechanism is identical. The
  classic page at `admin.php?page=sn-theme-options` is untouched and still
  reachable -- a port is not a removal. (#1074)
- The host's form replay knows every write pipeline the classic page has,
  not only the shared one. Four forms in Integrity -> Provenance post to
  `admin-post.php` with their own action and nonce; Measurement -> RSS's
  three carry `sn_rss_action` and their own nonce into an `admin_init`
  handler; Security -> Audit log's Prune now is handled inside its own
  render. Each ran nowhere in the window and refused with "the form
  expired", a cause never measured. The replay now dispatches by pipeline:
  the admin-post and RSS handlers run under a redirect interceptor (their
  `wp_safe_redirect` + `exit` becomes the landing tab, the flash and the
  `sn_*` params the classic page reads from its URL; their own
  `check_admin_referer()` failure becomes the notice, in the handler's own
  words), and an inline-handled form re-renders its leaf once with `$_POST`
  populated. Bracketed field names (`social_same_as[]`, `sn_tag_from[]`,
  `now[groups][0][items]`) are expanded into PHP arrays the way PHP's own
  request parsing does, before `$_POST` or the window's params are built;
  a repeated `sn_action` collapses to its last value. Own-page links are
  recognised by every slug the page answers to (the eight tab slugs and the
  legacy ones), any fragment is carried as the element id, `_wpnonce` rides
  a nonce-gated GET link, an existing `rel` is merged rather than replaced,
  and the Machine Readers sheet and the admin heartbeat script ride the
  window too. The submitter seam disables a same-named field before it
  carries the clicked button, since the runtime turns a repeated name into
  an array; admin.js marks bound elements with a property the runtime's
  morph cannot strip (it removes any attribute the server did not paint, so
  an attribute marker re-armed every paint and the add-row button fired
  twice); every leaf script that binds to leaf DOM at load exposes an
  idempotent `init( root )` and re-arms on the `snt:paint` event the host
  script dispatches after each paint. And because a window's save and
  repaint share one request where the classic page had two, a successful
  write now resets the request memos a leaf reads (`sn_setting()`'s merged
  settings, the AI availability memo) and fires `snt_os_host_wrote` -- the
  Identity save had persisted `social_same_as[]` while the same response
  painted the field empty. (#1074)

### Changed
- The manual `sn-dashboard` dock item in `inc/desktop-mode-dock.php` is
  retired in favour of the app's own entry: same id, same title, same shield
  icon, so one id still names one thing. Its 8-tab submenu is carried over as
  the window's `menu` -- one `go` per top tab, in registry order, refreshed
  after every action -- and its badge as `$os->badge()`, from the SAME
  `snt_desktop_dock_badge()`; neither is recomputed a second way.
  `snt_desktop_admin_url()` is unchanged, so every door the Signal & Noise
  app, the note dossier and the Attention rows already open still opens what
  it opened. (#1074)
- `assets/admin.js` gains `window.snAdmin.init( root )`. All three behaviours
  it armed on `DOMContentLoaded` -- the `sub_sections` section tabs, the
  sticky save bar's dirty-tracking and the "+ Add another profile URL" row --
  move into one idempotent, root-scoped `init`: it remembers each element it
  binds in a WeakSet (the runtime's morph strips any attribute the server
  did not paint, so an attribute marker re-armed every paint) and skips
  anything already bound, and it looks its
  nav, panels and form up inside the root it was handed rather than in
  `document`, so one window never binds another's leaf. `DOMContentLoaded`
  now calls `init( document )`, which is exactly what the page did before the
  seam existed. The host needs it because a
  window's HTML is painted by the runtime long after that event has fired,
  and the new host script (`assets/os-host.js`) re-arms it on every paint --
  along with re-creating the leaves' inline `<script>` blocks, which a paint
  cannot execute because painted HTML is parsed into a `<template>` before it
  is patched into the DOM. (#1074)

