# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.58] - 2026-11-23 — Saving a switch in OS Settings no longer removes the Signal & Noise tab

### Fixed
- **Saving a switch in OS Settings › Signal & Noise no longer removes the tab.** The shell rebuilds its payload inside window requests (the hidden menu-refresh probe behind `wp.os.refreshMenu()`, and the Plugins and Updates screens) and unregisters every settings tab whose script handle that payload no longer names. `snt-os-settings-tab` was registered on shell requests only, so the save, which refreshes the menu, dropped the tab and the open pane with it until a reload; "Dress MIO in the site's palette" is where it was seen, and every switch in the tab did it. The handle is now registered on window requests too and enqueued on the shell only (`snt_os_enqueue_settings_script()`). MIO's help bag is still built on the shell and on the refresh probe only, not on every window request (`snt_os_mio_localize()`). Tests: `tests/openstation-preferences.php` (+3), `tests/openstation-mio.php` (+3).

