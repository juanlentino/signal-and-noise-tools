# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.5.0] - 2026-09-27 — feed readers get the whole note

### Added
- The notes feed at /notes/feed/ now carries the full note in each item's content:encoded. The description stays the excerpt. The feed was excerpt-only because the WordPress setting "For each post in a feed, include" was set to Excerpt; nothing in code forced it. The plugin now switches that setting off for the notes feed only, so the site-wide setting and /feed/ are unchanged.
- Feed content is made safe for feed readers. Root-relative links and images become absolute, in-page anchors point at the note, embeds such as iframes become plain links, a provenance panel becomes one link to the note's record, and scripts, forms, buttons and the table of contents are removed. Each item ends with a plain "Read on the site" link.
- Feed reach gets its own number: feed opens. Each notes feed item carries a 1x1 image whose URL holds only the note id. A fetch counts one open per note, per day, per reader app, stored with a hashed user agent and no cookie or IP. Bots are dropped by the RSS tracker's own classifier. Many readers block images, so it is shown as a floor ("at least").
- Feed opens appear beside the north star's inputs (the north-star ability's layers.inputs.feed_opens, next to rss_readers and feed_clicks) and as a tile on Monitoring > RSS, each with a one-line definition. The north star itself still counts on-site reads only; a test pins that feed opens cannot change it.

