# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.68] - 2026-10-26 — exports download in place; tables stack through OpenStation

### Changed
- **Leaf tables stack on a phone through OpenStation itself.** OpenStation 1.1.16 shipped `<os-table stack-on-phone>` (WordPress/openstation #981, the fix for our report #900), which also keeps the cards through a repaint and lifts pinned columns on a phone. From that version the kit emits the native attribute and the dashboard no longer loads the plugin's own `assets/os-kit-stack.js` shim; on an older OpenStation the shim path is unchanged. The shim goes when the plugin stops supporting OpenStation before 1.1.16.

### Fixed
- **An analytics export no longer opens an empty app window.** Both export forms, the native S&N Analytics toolbar's and the classic toolbar's as the OpenStation host keeps it, posted with `target="_blank"`. Inside the installed PWA a same-origin new-window target relaunches the app (the host recorded this for links in 14.7.5), so every export opened an empty window behind the save dialog. The forms now submit in the same window: the handler answers with an attachment, which downloads without leaving the desktop. If the form's security token has expired (a window left open over a day), WordPress's expiry page replaces the desktop for that one click; Back returns to it.

