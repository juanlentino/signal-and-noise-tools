# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.7.2] - 2026-09-22 — the phone toggle, a website field and local time

### Fixed
- **The phone toggle works in the dashboard editor, and cannot publish the phone by itself.** The Resume leaf drew "Include the phone in the public PDF" as an `os-switch`, but os-form reads only checkboxes as booleans; any other tag submits its static value, so the switch posted `1` in both positions and every save turned the phone ON for the next Generate PDF (owner, 2026-09-22: "the toggle for the phone isn't working"). The published PDF was checked and carries no phone. It is now a real checkbox, the pattern the other leaves already use (`security-login-defense.php` documents the trap). `tests/os-leaf-content-resume.php` pins the checkbox and that the leaf carries no `os-switch` at all; putting the switch back fails both.
- **The Resume PDF box shows the generation time in the site's timezone.** It printed the stored UTC stamp raw ("2026-09-22T23:21:54+00:00", 7:21 pm in Orlando), which read as a time that wasn't the owner's. The option still stores UTC; both editors now show it through `wp_date()` with the site's date and time formats ("September 22, 2026 at 7:21 pm"). `tests/resume-pdf.php` (34) pins the conversion with the owner's own stamp and that neither editor prints the raw value (falsified).

### New
- **A Website field in the resume's PDF-only section**, both editors. The PDF prints it (bare host, linked); blank falls back to the site's own home URL, as 17.7.1 does. `tests/resume-pdf.php` (31) pins the override.

