# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **OS Settings › Signal & Noise is laid out as a desktop preference pane.** Three headed sections replace the one catch-all "Native window replacements" group: **Windows** (which window opens for S&N Home and Analytics), **MIO** (tips, Ask MIO, the site palette) and **Notifications**. The convention is the one System Settings on macOS and Settings on Windows follow: one group per subject, and only the choices that belong to the system. Every switch keeps its key, its default and its saved value. Test: `tests/openstation-preferences.php`.

### Added
- **A switch for alert notifications in the app, with a test.** "Show alerts as notifications in this app" (per user, on by default, which is what happened before the switch existed) decides whether the app is handed the hourly alert run's notice: off, neither the boot value nor the 15-minute read of `/desktop/alert-notice` returns one for that user. The alert email and the stored notice are untouched. "Send a test notification" calls `wp.os.notify` from the click (the browser's permission prompt needs one), with its own tag, so a test never replaces or hides a real alert. Tests: `tests/alerts.php`, `tests/openstation-preferences.php`.
- **Restore defaults in OS Settings › Signal & Noise.** One request posts the server's own default list (it rides the tab's localized data, so the client holds no second copy), then the tab repaints and the menu refreshes as after any switch.

## [2026.11.58] - 2026-11-23 — Saving a switch in OS Settings no longer removes the Signal & Noise tab

### Fixed
- **Saving a switch in OS Settings › Signal & Noise no longer removes the tab.** The shell rebuilds its payload inside window requests (the hidden menu-refresh probe behind `wp.os.refreshMenu()`, and the Plugins and Updates screens) and unregisters every settings tab whose script handle that payload no longer names. `snt-os-settings-tab` was registered on shell requests only, so the save, which refreshes the menu, dropped the tab and the open pane with it until a reload; "Dress MIO in the site's palette" is where it was seen, and every switch in the tab did it. The handle is now registered on window requests too and enqueued on the shell only (`snt_os_enqueue_settings_script()`). MIO's help bag is still built on the shell and on the refresh probe only, not on every window request (`snt_os_mio_localize()`). Tests: `tests/openstation-preferences.php` (+3), `tests/openstation-mio.php` (+3).

