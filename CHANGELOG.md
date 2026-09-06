# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- Both host windows now paint wp-admin's own canvas. The App Framework's
  window root sets the SHELL's theme tokens and colour scheme, and the
  classic pages are light-only, so under the dark palette on live the
  Analytics window showed white cards with inherited light text -- headings
  and row labels gone -- and the Dashboard host's heading went dim.
  `assets/os-host-admin.css` declares wp-admin's `common.css` values as the
  hosts' own tokens (canvas, text, link, borders, the font stack), remaps
  the shell's `--os-ui-*` tokens onto them and sets `color-scheme: light`,
  which is the shell's stated rule for an admin page in a window: it renders
  exactly as it does outside one. (#1080)
- The Dashboard's tab strip is visible again in every classic window opened
  by URL. `assets/admin.css` had hidden `.sn-nav-tabs` under the chromeless
  body class since v9.62.3, because the shell built its own in-window strip
  from our dock item's submenu; v13.104.0 retired that dock item (the app
  owns the dock entry and the auto-imported menu is hidden), so the shell
  built no strip and ours stayed hidden -- a window opened from the desktop
  icon or a door showed one leaf and no way to the other 34, measured on
  live. The rule is gone; the page's own strip is the navigation in every
  context, as on the classic page. (#1080)
- The S&N Dashboard desktop icon opens the host window, not the classic
  page in a chromeless frame: the icon keeps its id (its position and the
  attention badge survive) and targets `window => sn-dashboard`, the shell's
  own icon target for a native window. One surface per id. (#1080)
- A user's placement preference for the two hosts survives the update. The
  shell keys `navPlacement`, `navOrder`, `mobileTabs` and
  `dockPromotedPositions` by nav id, and the auto-imported menu tiles the
  hosts replaced were keyed by the menu's hook name
  (`toplevel_page_sn-analytics`), not the app's (`sn-analytics`); a
  preference set on the old id named nothing the shell paints, so Analytics,
  moved to the desktop before the update, sat back in the dock after it. A
  one-time sweep on `admin_init` (`inc/desktop-mode-nav-ids.php`, behind
  the option `snt_os_nav_id_migration`) copies each preference onto the app
  id for every user who has the shell's meta -- placement, order slot, phone
  tab slot and the dragged desktop position -- never overwriting a value the
  user has since set on the app id, and keeps the old key so the menu tile
  is where it was if the apps ever go. (#1080)

## [13.105.0] - 2026-09-06 — the S&N Analytics host

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
- A navigation in the Analytics window is the whole next URL. The first
  build merged a link's params over the current state, so the three
  controls that reset by omitting their param -- the Compare Off pill,
  Clear drill-down and the Events property Clear -- and the movers' bare
  deep link were dead in the window (measured by the review). A `go` is
  applied wholesale now: a param the link does not carry takes the page's
  default, as the classic dispatcher reads an absent `$_GET` key; the brush
  ships the whole current navigation with its window merged in. The lent
  request URI encodes its values (`add_query_arg()` does not), and the two
  host suites count CI-skipped rewrite pins into the summary line the
  runner reads. (#1075)

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

