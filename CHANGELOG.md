# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.7.6] - 2026-09-14 — doors open the native window

### Fixed
- **Doors to the plugin's own pages opened an iframe of the classic page, not the native window (#1304).** "Open Trust checks in S&N Dashboard", the Analytics gate and every kit door naming `admin.php?page=sn-theme-options` or `page=sn-analytics` went through the framework's `open_url`, which skips the native-URL remap registry every other opener consults (dock, portal, link interceptor, files-on-the-desktop); the dock tile beside the door opened the native window. Upstream fix: WordPress/openstation#819. Until it ships, `assets/os-settings-tab.js` (which owns the two remaps) publishes `sntOpenStationPreferences.tryNativeRemap( url )`: the same match and params as its registry entries, honouring the same preferences, opening through `wp.os.openWindow` and saying whether it did; a capture-phase document click claims kit doors (`os-action="door"`) to those pages before the runtime sees them, and the Signal & Noise app's `openLink` asks it before `host.openUrl`. Other doors, other origins, modified clicks and a shell that refuses the id are untouched. Suite: `tests/os-settings-tab-doors.php` over `tests/js/os-settings-tab-doors.mjs` (eight scenarios in a vm context). Mutation red: door click not claimed.

