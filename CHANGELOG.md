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
- **MIO in the plugin's windows (OpenStation 1.1.9).** The shell's companion can now live inside the Signal & Noise app, S&N Home and S&N Analytics, on three per-user switches in OS Settings › Signal & Noise (`mio_tips`, `mio_help`, `mio_look`; `inc/openstation-preferences.php`, defaults on/on/off). *Tips*: `lease.showCallout()` beside a control, plain text and never a model call. The app shows one per selected item for the states a reader could not tell apart before today: an integrity verdict names the sweep it came from and says a failing subject is re-read first; a date-only watch says a date passed and nothing was measured; a scheduled note says it leaves on its own; a Search item says requesting indexing lives in Search Console. S&N Home shows one beside an empty Commits table that quotes failures, pointing at Trust checks. Dismissal is per item for the life of the window. *Help*: five Markdown documents under `apps/signal-noise/help/` (the app, the queue, the ten readers, stamps and Acknowledge, the two windows) and a prompt scoped to the window, plus two read-effect tools over what the window already shows (`list_items`, `read_item`), registered through `wp.os.mio.registerWindow()`; Ask MIO stays behind the shell's AI switch and connector gate, the plugin makes no model call, and no write-effect tool exists. With help off, no document or prompt leaves the server. *Look*: bone body and a blood-to-signal ring through `openstation_mio_config`, opt-in; the user's saved look wins. `inc/openstation-mio.php` is the PHP half (documents, prompt bases, the `sntMio` bag, the filter); `apps/signal-noise/signal-noise-client.js` and `assets/os-host.js` register their windows by instance id and dispose on teardown. Tests: `tests/openstation-mio.php` (53: documents resolve their links inside the collection and name all ten readers, no em dash; prompt names the window and its limits; bag follows the switches; look gated and colour-only; JS contracts on source with comments stripped), preference suites re-pinned to the five-key schema, the settings tab pins its three toggles. Mutation red: look ignoring its switch. Docs: `docs/openstation-compat.md` names the four seams; README.

## [14.7.6] - 2026-09-14 — doors open the native window

### Fixed
- **Doors to the plugin's own pages opened an iframe of the classic page, not the native window (#1304).** "Open Trust checks in S&N Dashboard", the Analytics gate and every kit door naming `admin.php?page=sn-theme-options` or `page=sn-analytics` went through the framework's `open_url`, which skips the native-URL remap registry every other opener consults (dock, portal, link interceptor, files-on-the-desktop); the dock tile beside the door opened the native window. Upstream fix: WordPress/openstation#819. Until it ships, `assets/os-settings-tab.js` (which owns the two remaps) publishes `sntOpenStationPreferences.tryNativeRemap( url )`: the same match and params as its registry entries, honouring the same preferences, opening through `wp.os.openWindow` and saying whether it did; a capture-phase document click claims kit doors (`os-action="door"`) to those pages before the runtime sees them, and the Signal & Noise app's `openLink` asks it before `host.openUrl`. Other doors, other origins, modified clicks and a shell that refuses the id are untouched. Suite: `tests/os-settings-tab-doors.php` over `tests/js/os-settings-tab-doors.mjs` (eight scenarios in a vm context). Mutation red: door click not claimed.

