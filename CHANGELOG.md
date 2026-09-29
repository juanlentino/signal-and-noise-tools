# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- docs/SECURITY.md records that the CVE-2026-87902 `pagename` traversal rule is live in Cloudflare (merged into the readme+licence block rule, url_decode form) and what was verified.

## [19.7.1] - 2026-09-29 — emoji stays for readers

### Fixed
- **Emoji is back on the public site, and the core version stays hidden.**
  19.7.0 turned emoji off everywhere to stop `wp-emoji-release.min.js?ver=7.1.2`
  leaking the WordPress version. Emoji now works as stock WordPress on the front
  end, in embeds, feeds and email. It stays off in wp-admin only (the admin
  script and styles and the classic editor plugin), as core's block editor
  already does. The emoji script URL passes through the same filter that swaps
  core's version for a private token, so it shows the token instead. The test
  runs WordPress's own emoji printer and checks that `7.1.2` appears nowhere.
  CI now fetches `formatting.php` for that check.

