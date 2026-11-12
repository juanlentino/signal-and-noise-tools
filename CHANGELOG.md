# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.33] - 2026-11-12 — Spanish CV PDF credits line in Spanish

### Fixed
- **The Spanish CV PDF's credits line is in Spanish.** The PDF template printed "Verified credits:" in English on JuanLentino_CV.pdf too; it now reads "Créditos verificados:" there, as /cv already did (`inc/resume-pdf/template.php`). Test: `tests/resume-variants.php`.

