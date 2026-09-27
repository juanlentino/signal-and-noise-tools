# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.5.1] - 2026-09-27 — the main feed carries the whole note too


- The main posts feed at /feed/ now carries the whole note too, the same as /notes/feed/: full text made safe for feed readers, the "Read on the site" link and the feed-open pixel. Comment feeds and admin keep the excerpt, and the site-wide feed setting is untouched.
- The RSS stats reading (get-rss-stats, and sn-metrics rss_stats) now reports feed opens: a 7-day and 30-day total and the most opened notes over 30 days, labelled as a floor ("at least"), since readers that block images never load the pixel. The remote payload contract moves 10 to 11 for this additive field; the remote worker's CONTRACT_VERSION needs the same bump.


