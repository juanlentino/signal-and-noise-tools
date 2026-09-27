# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

- Feed reach now counts subscribers honestly. Feed aggregators such as Feedly, NewsBlur, Feedbin, Inoreader and The Old Reader put their subscriber count in the request they send for the feed, so one Feedly fetch can stand for many readers but used to count as one. The RSS tracker now keeps, per day, the highest count each aggregator reported for each feed, plus one for every direct reader, and reads the busiest day of the last 7 as the figure, labelled a ceiling. Only the parsed name, feed id and count are stored, never the full user agent. A claim above 1,000,000 is capped and flagged. Monitoring > RSS shows feed reach as three separate numbers that are never added together: Subscribers (reported by readers, a ceiling), Opens (at least; many readers block images), and Clicks (visits from feed links). The north star gains a `feed_subscribers` input and its value is unchanged; `rss_readers` stays and means distinct fetchers. The RSS stats tool gains a `subscribers` block, which moves the remote contract to version 12; the remote worker's contract version must move to 12 in its next deploy.
- The cron health reading now says plainly what its old `cron_disabled_constant` field meant. Two new fields sit beside it: `disable_wp_cron` (is the constant set, nothing more) and `cron_stalled_no_runner` (the alarm: the constant is set, nothing has fired recently, and no system cron is declared). The old field keeps its value for existing readers but is deprecated; it read false on a site with the constant set and a system cron running, which looked like "the constant is not set". The summary line now names all three conditions. The new fields are in the payload only, not yet in the declared schema, so the remote contract (version 11) is unchanged; declaring them is a contract bump for a later worker deploy.

## [19.5.1] - 2026-09-27 — the main feed carries the whole note too


- The main posts feed at /feed/ now carries the whole note too, the same as /notes/feed/: full text made safe for feed readers, the "Read on the site" link and the feed-open pixel. Comment feeds and admin keep the excerpt, and the site-wide feed setting is untouched.
- The RSS stats reading (get-rss-stats, and sn-metrics rss_stats) now reports feed opens: a 7-day and 30-day total and the most opened notes over 30 days, labelled as a floor ("at least"), since readers that block images never load the pixel. The remote payload contract moves 10 to 11 for this additive field; the remote worker's CONTRACT_VERSION needs the same bump.


