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
- **S&N Home has a Settings tab, and the three email switches are its first pane: Settings › Notifications and reports.** Settings sat among the readings; the convention this follows is the preferences area of System Settings on macOS and Settings on Windows, one pane per subject, last in the list. The tab is the ninth (page slug `sn-settings`), on the classic screens and in the native window, both read from `sn_admin_top_tabs()`. Option keys, form actions (`morning_brief_save`, `security_digest_save`) and handlers are unchanged, and a save or a test from the pane returns to the pane with its notice on both surfaces.
  - **Left Connections › Cron** (the "Morning operations brief" box), for the pane: the switch "Email a daily morning brief to the admin address" and its hint; the switch "Email an alert when a page spikes or breaks" and its hint; the Save button for the two; the "Send test brief" button; the brief's "Last sent" line and its "Last send failed" line.
  - **Stayed on Connections › Cron:** the "Configuration drift: N settings differ from the acknowledged snapshot." notice and its "Acknowledge current settings" button, now in a box of their own headed "Configuration drift" (it reads "No settings differ from the acknowledged snapshot." when there is none, a line that is new); the events ledger; the "Scheduled read-only runs" box; the "Action Scheduler backlog" box. One line was added: where the two switches went, with a link.
  - **Left Security › Login defense** (the "Weekly security digest" box, whole), for the pane: the switch "Email a weekly security digest to the admin address" and its hint; its Save button; the "Send test digest" button and the line that says what it does; the digest's "Last sent" line and its "Last send failed" line.
  - **Stayed on Security › Login defense:** the "Login guard status" box and the "Breached passwords" box, every figure in them. One line was added: where the switch went, with a link.
  - **New on the pane:** one line saying in-app alert notifications are set in OS Settings › Signal & Noise. It has no control.
  - **Repointed:** the alert email's footer ("Switch alerts off in…"), the weekly digest's footer ("Turn it off in…") and the failed test digest notice now name the pane.
  - Tests: `tests/os-leaf-settings-notifications.php` (new), `tests/os-leaf-connections-cron.php`, `tests/os-leaf-security-login-defense.php`, `tests/admin-ia-redirect.php`, `tests/openstation-host-dashboard.php`.

## [2026.11.59] - 2026-11-23 — OS Settings › Signal & Noise in three sections, with an alert notification switch and Restore defaults

### Changed
- **OS Settings › Signal & Noise is laid out as a desktop preference pane.** Three headed sections replace the one catch-all "Native window replacements" group: **Windows** (which window opens for S&N Home and Analytics), **MIO** (tips, Ask MIO, the site palette) and **Notifications**. The convention is the one System Settings on macOS and Settings on Windows follow: one group per subject, and only the choices that belong to the system. Every switch keeps its key, its default and its saved value. Test: `tests/openstation-preferences.php`.

### Added
- **A switch for alert notifications in the app, with a test.** "Show alerts as notifications in this app" (per user, on by default, which is what happened before the switch existed) decides whether the app is handed the hourly alert run's notice: off, neither the boot value nor the 15-minute read of `/desktop/alert-notice` returns one for that user. The alert email and the stored notice are untouched. "Send a test notification" calls `wp.os.notify` from the click (the browser's permission prompt needs one), with its own tag, so a test never replaces or hides a real alert. Tests: `tests/alerts.php`, `tests/openstation-preferences.php`.
- **Restore defaults in OS Settings › Signal & Noise.** One request posts the server's own default list (it rides the tab's localized data, so the client holds no second copy), then the tab repaints and the menu refreshes as after any switch.

