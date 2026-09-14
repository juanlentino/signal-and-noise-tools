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
- **Doors to the plugin's own pages opened an iframe of the classic page, not the native window (#1304).** "Open Trust checks in S&N Dashboard", the Analytics gate and every kit door naming `admin.php?page=sn-theme-options` or `page=sn-analytics` went through the framework's `open_url`, which skips the native-URL remap registry every other opener consults (dock, portal, link interceptor, files-on-the-desktop); the dock tile beside the door opened the native window. Upstream fix: WordPress/openstation#819. Until it ships, `assets/os-settings-tab.js` (which owns the two remaps) publishes `sntOpenStationPreferences.tryNativeRemap( url )`: the same match and params as its registry entries, honouring the same preferences, opening through `wp.os.openWindow` and saying whether it did; a capture-phase document click claims kit doors (`os-action="door"`) to those pages before the runtime sees them, and the Signal & Noise app's `openLink` asks it before `host.openUrl`. Other doors, other origins, modified clicks and a shell that refuses the id are untouched. Suite: `tests/os-settings-tab-doors.php` over `tests/js/os-settings-tab-doors.mjs` (eight scenarios in a vm context). Mutation red: door click not claimed.

## [14.7.5] - 2026-09-14 — same-origin links open as windows in the PWA

### Fixed
- **In the installed PWA, same-origin links launched a second OpenStation (#1301).** A `target="_blank"` navigation, or `window.open( …, '_blank' )`, to any URL on this site is inside the app's scope, so the browser started the app again instead of opening a tab. Every front-end link out of a window did it ("View the note", "Verification docket", /now, /resume, /about/uses, a candidate's permalink), so did the Signal & Noise app's "View the note" and dossier URL actions, and the desktop dropzone's "open the draft". The shell's own interceptor bails on `target="_blank"` and claims only `/wp-admin/` paths, so the plugin routes these itself now, on one rule: same origin is a window (the shell iframes the front end the way it iframes previews), another origin is a tab, and a same-origin file (.xml/.csv/.json/.txt/.zip/.pdf) stays a navigation. The host rewrite turns same-origin non-admin anchors into doors even when the leaf authored `target="_blank"` for the classic page; the Dashboard and Analytics `door` handlers accept any same-origin URL and still refuse other origins; `snt_kit_link()` and list-row hrefs paint a door for same origin and a tab for external; the app has one `openLink()` (its only `window.open`); the dropzone opens the draft through `wp.os.windowManager`. The host's URL helpers moved to `inc/openstation-host-urls.php` (function_exists-guarded) so the kit can decide door-or-tab without loading the host. Pins: `tests/openstation-host.php` (three link shapes, HTML-API run), both host app suites (front end opens, other origins refused), six leaf suites re-pinned to door markup, `tests/openstation-app-client.php` (one `window.open`, both call sites through `openLink`, comments stripped). Mutation red: host branch off.

