# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.106.21] - 2026-09-08 — Fix MFA-aware audit login reporting

### Fixed
- Record MFA-protected logins only after Two-Factor completes verification, including WebAuthn and backup methods. Add MFA rejection, rate-limit, and other-error subsets to audit tables, exports, and the security digest without double-counting failures or storing credentials. Preserve historical records and leave authentication enforcement unchanged.

