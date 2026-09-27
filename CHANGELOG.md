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
- **Correction to 19.3.2: `lib/.htaccess` is inert on this host.** Cloudways hands PHP to Nginx and PHP-FPM, which ignore `.htaccess` (as `tests/.htaccess` already noted), so `lib/pdf/vendor/*.php` still answered 200 after the release. The block is enforced at the edge instead: the Cloudflare custom rule "readme+licence" now also blocks `*.php` under `/wp-content/plugins/signal-and-noise-tools/lib/` (verified 403). The `.htaccess` stays for Apache hosts.

## [19.3.2] - 2026-09-27 — PHP under lib/ is denied over HTTP

### Security
- **PHP under `lib/` is denied over HTTP.** The vendored Dompdf tree (3.1.6, patched against every published advisory) has no ABSPATH guards, and `lib/pdf/vendor/autoload.php` answered a direct request with 200. Its files only define classes, so nothing ran, but nothing needs them over the web either: `lib/.htaccess` denies `*.php`. Found in the 2026-09-27 security audit.

