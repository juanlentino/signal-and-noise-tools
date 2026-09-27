# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.3.2] - 2026-09-27 — PHP under lib/ is denied over HTTP

### Security
- **PHP under `lib/` is denied over HTTP.** The vendored Dompdf tree (3.1.6, patched against every published advisory) has no ABSPATH guards, and `lib/pdf/vendor/autoload.php` answered a direct request with 200. Its files only define classes, so nothing ran, but nothing needs them over the web either: `lib/.htaccess` denies `*.php`. Found in the 2026-09-27 security audit.

