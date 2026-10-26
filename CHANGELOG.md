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
- **Tables are cards on a phone, everywhere.** The owner opened S&N Analytics › Posts on a phone: three columns crammed into 375px, and the "Not indexed" pill broke a letter per line. Three causes, one fix each. Every report table S&N Analytics builds (`apps/sn-analytics/native-tables.js`, all 15 tabs) now carries OpenStation's `stack-on-phone`, so each row is a card with its first column as the title and the rest as labelled lines (OpenStation 1.1.16+; older versions ignore the attribute and keep the grid). `snt_kit_table()` makes the same choice by default, so the 32 Dashboard tables that never asked for it now stack too; `'stack_on_phone' => false` keeps a grid. A pill never breaks inside a word (it wraps at spaces, so a sentence-long pill still fits). A stacked table has no header row, so a table's filter box is gone on a phone (Content › Scheduled posts' Title filter is the one this newly reaches). And the hour heatmap's screen-reader companion (and the reader-evidence charts') stays hidden: the native wrapper had turned it into a visible 25-column grid.

## [2026.10.68] - 2026-10-26 — exports download in place; tables stack through OpenStation

### Changed
- **Leaf tables stack on a phone through OpenStation itself.** OpenStation 1.1.16 shipped `<os-table stack-on-phone>` (WordPress/openstation #981, the fix for our report #900), which also keeps the cards through a repaint and lifts pinned columns on a phone. From that version the kit emits the native attribute and the dashboard no longer loads the plugin's own `assets/os-kit-stack.js` shim; on an older OpenStation the shim path is unchanged. The shim goes when the plugin stops supporting OpenStation before 1.1.16.

### Fixed
- **An analytics export no longer opens an empty app window.** Both export forms, the native S&N Analytics toolbar's and the classic toolbar's as the OpenStation host keeps it, posted with `target="_blank"`. Inside the installed PWA a same-origin new-window target relaunches the app (the host recorded this for links in 14.7.5), so every export opened an empty window behind the save dialog. The forms now submit in the same window: the handler answers with an attachment, which downloads without leaving the desktop. If the form's security token has expired (a window left open over a day), WordPress's expiry page replaces the desktop for that one click; Back returns to it.

