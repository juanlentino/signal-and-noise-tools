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
- **About 400 KB less on every OpenStation page load.** `sntAbilityRunData` (the ability verb map and REST root, 5.5 KB) was localized onto `snt-ability-run`, and OpenStation serializes every dependency's l10n into each command and widget that depends on it: the 27 S&N commands and 11 S&N widgets each carried a copy in `scriptDeps`, and the station page printed the variable 35 more times. Measured on the live station (2026-09-22): 192 KB of repeated `sntAbilityRunData` plus about 209 KB inside `scriptDeps`, of a 489 KB `openStationConfig` in a 1.17 MB HTML document. The data now prints once per page from its own src-less head handle (`snt-ability-run-data`) that nothing depends on, and the runner reads it at call time, so scripts the station lazy-loads later still find it. `tests/ability-run-client.php` pins that nothing is localized onto the runner, that the data handle is src-less, in the head, enqueued and not a dependency, and that the runner reads the root at call time; localizing onto the runner again fails it.

### New
- **Logging in lands admins straight in OpenStation.** A login with no specific destination used to load the Dashboard and then redirect to the station, a second full page load every time. `login_redirect` now sends an administrator to `admin.php?page=openstation` when OpenStation is installed. A login that asked for a page (an edit link, the PWA start URL, a reauth) keeps its destination; non-admins and failed logins are untouched. `tests/openstation-login-redirect.php` (7) pins each branch; removing the OpenStation check fails it.

## [17.7.2] - 2026-09-22 — the phone toggle, a website field and local time

### Fixed
- **The phone toggle works in the dashboard editor, and cannot publish the phone by itself.** The Resume leaf drew "Include the phone in the public PDF" as an `os-switch`, but os-form reads only checkboxes as booleans; any other tag submits its static value, so the switch posted `1` in both positions and every save turned the phone ON for the next Generate PDF (owner, 2026-09-22: "the toggle for the phone isn't working"). The published PDF was checked and carries no phone. It is now a real checkbox, the pattern the other leaves already use (`security-login-defense.php` documents the trap). `tests/os-leaf-content-resume.php` pins the checkbox and that the leaf carries no `os-switch` at all; putting the switch back fails both.
- **The Resume PDF box shows the generation time in the site's timezone.** It printed the stored UTC stamp raw ("2026-09-22T23:21:54+00:00", 7:21 pm in Orlando), which read as a time that wasn't the owner's. The option still stores UTC; both editors now show it through `wp_date()` with the site's date and time formats ("September 22, 2026 at 7:21 pm"). `tests/resume-pdf.php` (34) pins the conversion with the owner's own stamp and that neither editor prints the raw value (falsified).

### New
- **A Website field in the resume's PDF-only section**, both editors. The PDF prints it (bare host, linked); blank falls back to the site's own home URL, as 17.7.1 does. `tests/resume-pdf.php` (31) pins the override.

