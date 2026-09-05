# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [13.97.0] - 2026-09-05 — the readout that could not tell empty from broken

### Added
- A 23rd health check: the plugin registry against `active_plugins`. The
  OpenStation Plugins window showed an empty installed list on a phone AND a
  desk, cleared only by remounting; it was chased as a client bug and was not
  one. That window prints `Could not load plugins: <error>` whenever its fetch
  fails, and no such message appeared — so `GET /wp/v2/plugins` had answered
  **zero plugins with a 200**. `WP_REST_Plugins_Controller` reads
  `get_plugins()`, which is memoised through the object cache, so a stale entry
  reports "no plugins installed" in exactly the shape of a site that has none,
  and every consumer believes it. `active_plugins` is a plain option and cannot
  fail for the same reason, which makes it a usable oracle: every basename in it
  must appear in the registry. Two findings, not one, because there are two
  repairs — a file present on disk but unregistered means the CACHE is wrong
  (`wp cache flush`), while a file absent means the PLUGIN is gone (deactivate
  the orphan). Surface `health`: it is a defect, it reaches zero and stays there,
  and no other surface owns it. (#1026)
- A runtime probe beside it. The poisoning is TRANSIENT — it can be served, be
  seen by a person, and be gone before the next scheduled scan, so a scheduled
  check alone would report a clean site for a fault someone watched happen.
  `rest_request_after_callbacks` now notices when `GET /wp/v2/plugins` answers
  an EMPTY collection with a success status while plugins are active, and
  writes down the time and the active count. The health check reports that
  observation for seven days, stating plainly that it is the observation and
  not the current state, then lets it expire so the check can reach zero again.
  It records and never repairs: flushing a cache from inside a read request
  would destroy the evidence. (#1026)

### Internal
- The check reports `skipped` rather than a silent zero whenever it could not
  run — `get_plugins()` unavailable, `active_plugins` unreadable, no active
  plugins to compare against, or a non-array registry. Its suite pins the split
  that matters: the two notes must never be the same sentence, since that is
  precisely what made the original incident unreadable. (#1026)
- Nine docblocks still described the admin tables as `.wp-list-table` after
  v13.96.5 removed the class from all fifteen of them. Prose drift from my own
  change: the code was right and the comments explaining it were not, which is
  the pairing most likely to send the next reader back down the wrong path.
  The two surviving mentions are deliberate — each states the rule the class
  would re-arm. `docs/changelog/` is untouched: it is history, and it correctly
  records what was true when written. (#1021)

