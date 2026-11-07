# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.15] - 2026-11-07 — Contact form mail from its topic address

### Fixed
- **Contact form mail can go out from its topic address.** Each form rule sets a From address (consulting@, press@ and so on), but FluentSMTP sends a Gmail connection only as its one sender and rewrites any other From, and it offers extra senders only for Amazon SES and toSend. `inc/mail-topic-senders.php` (new) adds the six topic addresses to its sender mappings, on the default connection, through a filter on `option_fluentmail-settings`. A mapping already stored wins, and nothing changes when there is no default connection. FluentSMTP saves what it reads, so its next write stores these mappings on that connection, and they stay there if another connection later becomes the default, until that connection is deleted. Google still has to list each address under Send mail as. Test: `tests/mail-topic-senders.php` (new).

### Changed
- **The service pages no longer name Panacea as the only room.** The owner can be called to other studios, so the hub's Production note reads "In the studio", Artist & Producer Development gains "In the studio or remote", and the music page says records happen "at Panacea in Buenos Aires or wherever the record needs". The live pages changed through `sn-apply`, and the seed files follow.
- **The services seed pages follow the redesign: Side A and Side B, with photos.** The owner redesigned the hub as a record sleeve and asked for images back, so `/services`, `/services/music/`, `/services/consulting/` and their Spanish pages were rewritten through `sn-apply`, and the six seed files now hold the same text. The migration is spent on the live site, so nothing runs on install. New copy on the hub: Side A, Side B, Play side A, Play side B, and a short line for each side. Eyebrows read "Side A · 05 Offerings" and "Side B · 03 Offerings". How a side is built and how to add a third: `docs/BILINGUAL.md`.

