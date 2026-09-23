# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### New
- **A Professional summary field in the resume's PDF-only section**, both editors. The PDF prints it under PROFESSIONAL SUMMARY; blank falls back to the Summary from Hero, as before. /resume never reads it (the sync engine ignores `pdf`). `tests/resume-pdf.php` (36) pins the override and the fallback (dropping the override fails it), `tests/resume-pdf-page-invariance.php` (18) that the page stays byte-identical with it filled, and `tests/os-leaf-content-resume.php` (86) that the dashboard leaf carries it and saves it round trip.

## [17.7.3] - 2026-09-23 — OpenStation, lighter and first

### Fixed
- **About 400 KB less on every OpenStation page load.** `sntAbilityRunData` (the ability verb map and REST root, 5.5 KB) was localized onto `snt-ability-run`, and OpenStation serializes every dependency's l10n into each command and widget that depends on it: the 27 S&N commands and 11 S&N widgets each carried a copy in `scriptDeps`, and the station page printed the variable 35 more times. Measured on the live station (2026-09-22): 192 KB of repeated `sntAbilityRunData` plus about 209 KB inside `scriptDeps`, of a 489 KB `openStationConfig` in a 1.17 MB HTML document. The data now prints once per page from its own src-less head handle (`snt-ability-run-data`) that nothing depends on, and the runner reads it at call time, so scripts the station lazy-loads later still find it. `tests/ability-run-client.php` pins that nothing is localized onto the runner, that the data handle is src-less, in the head, enqueued and not a dependency, and that the runner reads the root at call time; localizing onto the runner again fails it.

### New
- **Logging in lands admins straight in OpenStation.** A login with no specific destination used to load the Dashboard and then redirect to the station, a second full page load every time. `login_redirect` now sends an administrator to `admin.php?page=openstation` when OpenStation is installed. A login that asked for a page (an edit link, the PWA start URL, a reauth) keeps its destination; non-admins and failed logins are untouched. `tests/openstation-login-redirect.php` (7) pins each branch; removing the OpenStation check fails it.

