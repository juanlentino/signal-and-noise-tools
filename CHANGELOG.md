# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.105.1] - 2026-09-06 — the two hosts on live: canvas, tab strip, icon, placement

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

