# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Fixed
- **A title that is itself the query passes check 28.** "Where AI actually saves time in record production" was flagged with an override equal to itself and again with none; the rule assumed every note title is an aphorism. A title opening with how, why, what, where, when, which or who, six or more words, no colon, no sentence-final punctuation, is a query and needs no second name (word count alone cannot separate it from a seven-word aphorism). Pinned both ways.
- **The Health leaf's table after Re-run scan paints its rows.** An `<os-table>` inserted by an action's re-paint held its row in `data` and showed "No rows." until the leaf was reopened. The host's paint pass now re-assigns the rows of a table in exactly that state (rows held, empty state shown) and touches no other. The fork has issues off; the upstream OpenStation watch (#808/#809) gains this row. Pinned.

## [16.3.0] - 2026-09-18 — a judge, not a generator

### Added
- **Jev reads the notes.** TypeSafe's Jev is a judge, not a generator: one request, typed questions, typed answers with a confidence. A key in the keyring (`typesafe_api_key`, probed with one Noul about a fixed sentence) schedules a daily pass (`sn_jev_notes_daily`) that sends every published and scheduled note as four short fields (title, search title, description, the opening's first 600 characters) with three questions answered in parallel: is the search title what a person would type (a three-level rubric written as concrete descriptions), does the description state the argument, does the opening name the subject. One request per note (a request evaluates one state), a refused key stops the pass after one, a failed request keeps the note's previous verdict with the error beside it, and the pass records its own tokens (they are not Anthropic's and stay out of that budget). Health check 30, "Notes Jev would not search for", reads the stored pass and never calls Jev: a finding needs a low rubric position AND confidence at or above 0.9 (TypeSafe's own "act automatically" line; their classification cookbook's confident half was right nine times in ten), and a reading below the floor is counted as unsure, never as a finding. No key or no pass yet reads skipped. `signal-noise/jev-notes` (read door) hands the pass to an agent. Read against the documentation's API, models, confidence and jev-1.13 jaggedness pages: the questions never ask Jev to count or to order dates, the state is small, the rubric levels are descriptions, not degrees. Pinned (36).

