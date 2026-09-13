# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [14.4.1] - 2026-09-13 — the Attention pill keeps the app's last count

### Fixed
- **The Attention pill was blank whenever the app had not been opened within the minute.** 14.4.0 read only the `snt_os_attention` transient, and a transient does not go stale at 60 s — it *expires*. Measured on the live shell after install: `count: null` with a full queue, because the last composition was older than a minute. `attention_rows()` now also writes the composition's headline `{ count, read_at, stamp }` to a plain option, `snt_os_attention_last` (autoload off, written only beside a composition, never on a cache hit), and `snt_os_attention_snapshot()` falls back to it when the transient is gone. Still never composes; the pill now says what the app last saw, marked stale in its title, which is what the plan promised. Guards: the app writes the option on compose and not on a hit; the snapshot prefers a live transient, takes the option when the transient is gone, and refuses an option without `read_at`; key parity pinned between the two files.

