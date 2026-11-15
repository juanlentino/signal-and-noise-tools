# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.
- **Preview PDF previews the résumé PDF.** With résumé settings in the draft it streams the one résumé's layout (two pages, What I've Built, verified credits), the file Publish would build, instead of the music layout nothing publishes (`sn_resume_pdf_draft_parts()`, layout shared with Generate through `sn_resume_ops_pdf_layout()`). Test: `tests/resume-ops-pdf-build.php`.

## [Unreleased]

### Fixed
- **A coded link stays the share when the app then fetches the page it names.** `/r/CODE` names `/resume/` as its `og:url`, and Telegram fetches that page right after the link (2026-11-14 20:01 ET: `/r/prueba` sent, `/resume/` fetched two seconds later). Fetches by one app within 60 seconds are one share credited to the last page, so the share landed on `/resume` and later plain `/resume` visits inherited it. Within a burst, a plain page no longer replaces an `/r/` link; a later `/r/` fetch still replaces a plain one, so a link typed letter by letter still ends on the link sent. Shares are joined when read, so past shares are corrected. Test: `tests/share-echoes.php` (2 checks red without the change).

## [2026.11.44] - 2026-11-15 — A visit is credited to a share through its own app; Meta's previews count

### Fixed
- **A visit is credited only to a share through the app it came from, and Meta's previews of plain pages count.** On 2026-11-15 an Instagram visit to `/resume` was credited to a Telegram share from 19 hours before, because the join took the latest share through any app. When a visit's source names an app (Instagram, Facebook, Messenger and Threads are Meta; WhatsApp, LinkedIn, Telegram, Slack, Discord, X), only that app's shares count now, and with none in the 72 hours the visit has no share. Visits with no named source (direct, internal, search) keep the latest share through any app. The Shares list follows the same rule. A Meta fetch the rights-signals worker matched by Meta's network (1.40.0, `via: network`: the preview renderer that runs the page like a phone browser when a link is sent) is now a share on any page; Meta fetches matched by `facebookexternalhit` still count only on `/r/` links, since that agent also crawls on its own. Shares are joined when read, so past visits are re-credited; no Meta echo was recorded for the 11-15 send, so that visit now shows no share. Test: `tests/share-echoes.php` (red without the change).

