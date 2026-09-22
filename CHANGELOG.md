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
- **The phone toggle works in the dashboard editor, and cannot publish the phone by itself.** The Resume leaf drew "Include the phone in the public PDF" as an `os-switch`, but os-form reads only checkboxes as booleans; any other tag submits its static value, so the switch posted `1` in both positions and every save turned the phone ON for the next Generate PDF (owner, 2026-09-22: "the toggle for the phone isn't working"). The published PDF was checked and carries no phone. It is now a real checkbox, the pattern the other leaves already use (`security-login-defense.php` documents the trap). `tests/os-leaf-content-resume.php` pins the checkbox and that the leaf carries no `os-switch` at all; putting the switch back fails both.

### New
- **A Website field in the resume's PDF-only section**, both editors. The PDF prints it (bare host, linked); blank falls back to the site's own home URL, as 17.7.1 does. `tests/resume-pdf.php` (31) pins the override.

## [17.7.1] - 2026-09-22 — the resume PDF contact line

### Fixed
- **The resume PDF's contact line reuses what the resume already knows.** The first generated PDF showed only LinkedIn: the location came solely from the new PDF-only field, which was blank, so "Orlando, FL" (already on the web page's contact line) silently vanished, and the site address the design shows was never printed (owner, 2026-09-22). The location now falls back to the web contact line when the PDF-only field is empty, and the site's home URL prints as its bare host, linked (`juanlentino.com`). Order as the design: location, phone (per the switch), email, LinkedIn, site. `tests/resume-pdf.php` (30) reads the location, LinkedIn and the site back out of the PDF bytes with the field blank; removing the fallback fails it. Headline, tagline and email have no web equivalent and still come from the PDF-only section.

