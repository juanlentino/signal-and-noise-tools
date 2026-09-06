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

## [13.103.0] - 2026-09-06 — the phone

### Added
- An **Attention** section opens first at the root (position 5) -- what a
  phone opens on. Nine readers over signals something else already
  computed: the last provenance-integrity sweep's failing notes and its
  fleet-level key verdict (a mismatched, missing or unreachable ledger key
  file is one row about every signed subject, in the findings' own words),
  commits
  still unanchored or pending (both post types), the newest-per-post stale
  edge-probe verdict from the last twenty saves, citations never checked
  and citations due for a check, fragments and posts with a schedule
  transition inside 24 hours, posts and pages pending review (composed for
  everyone, shown only to a reader with the type's `edit_others_*`
  capability, decided when the queue is read and never when it is cached
  -- for anyone else the section's own scoped Pending pill is the honest
  surface), the last health scan's failing checks, ripe watches, and a
  stale machine-reader snapshot. A failing note whose only failures are
  outages or gaps (an unreachable twin or ledger, an unresolved subject
  kind) is toned warning, never danger -- the sweep's own distinction; a
  citations store that cannot answer is one warning row, not a zero; the
  anchors row says its source reads the newest hundred signed subjects. It
  reads; it never triggers a scan, a sweep, a probe or a re-check -- every
  reader gates on `function_exists()` and wraps its call in try/catch, so
  an absent subsystem makes no claim and a subsystem that cannot answer
  yields exactly one warning row, never a zero. Every row carries the
  reading's own stamp ("as of `<UTC>`", or "not stamped" when the signal
  carries none), and a row that names a post offers "Open the note" (or
  "Open the page") -- a new `jump` server action that sets the section and
  the item together, so the reader lands on the dossier without hunting
  for it -- but only when the section that would list the post actually
  holds it (Notes lists the note category, Pages the signed pages), so the
  jump never lands on nothing. The composed
  queue is cached for sixty seconds with its own `read_at`, so the nine
  readers run once a minute rather than on every root paint; the empty
  state ("Nothing needs you") says when its readers last looked. (#1071)
- The window now says when it is running a stale build. `SNT_VERSION`
  ships frozen into the document at render (`ctx.extra.version`) and fresh
  on every dispatch (`ctx.data.version`); OpenStation's own update toast is
  keyed to OpenStation's own asset stamp and can never see a plugin
  release, so on an installed phone PWA -- which can keep the same document
  alive for days -- a stale window had nothing telling it so. When the two
  disagree the client paints one line beside the crumbs, in both view
  branches -- "The installed build (X) is not the one this window was
  built from (Y).", which is all the compare knows -- with a Reload button
  the reader must click; the click awaits the shell's own session flush
  before reloading, and nothing reloads on its own. (#1071)

### Fixed
- Five defects measured on the phone. The list view now stacks into a
  card per row (`<os-table stacked>`, `sticky-columns` stood down) instead
  of a sideways scroll fighting a pinned column. A crossing between the
  desk and the phone band now repaints the window and mounts or tears down
  the desk-only marquee and drag listeners for the band it is in, rather
  than leaving a window painted for the band it was born in. The phone's
  item page gains a "‹ Back" control in its detail header, ahead of the
  title, on every section -- the crumb was the only way out, and the
  shell's own Back leaves the app rather than the item. A long press on
  the empty canvas now opens the canvas menu (Refresh); iOS never
  synthesises `contextmenu` from a held finger, so Refresh was unreachable
  there before. The iOS callout and text-selection suppression now runs
  under `@media (pointer: coarse)` as well as under the mobile-mode stamp,
  and covers the canvas and the table (whose rows live in a shadow root
  but inherit `user-select` from the host) alongside the folder tile and
  the cell; the dead `is-phone` class, which styled nothing, is gone.
  (#1071)
- A status pill that filters a list empty now says "Nothing under this
  filter." rather than the section's own empty wording -- Attention's
  "Nothing needs you" is a claim about the queue, not about the filter. A
  long press arms its tap-swallow only when it opened something, and one
  finger arms one press: a press that began on a tile no longer also arms
  the canvas's, which stranded a swallow that could eat the next tap.
  (#1071)

### Changed
- Discography's registration gate now asks `albums_count()` instead of
  `albums_items()` -- it was building every release's cover art, tracks
  and dossier on every single resolution of the registry just to answer
  whether the list was empty. It was the one section without a `count`
  callable, so that cost landed on every root paint, including the
  phone's first screen. (#1071)

