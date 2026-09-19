# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [16.8.0] - 2026-09-19 — a tag is the one field a note can still change

### Docs
- Session doc extended: "The file is named, and the option the form never posted" (16.7.0 through 16.7.2: the anti-tell pass and its first corpus reading, the lane map on one copy of the corpus, the logo that named a deleted file, and the Settings › General banner traced to the AI plugin registering `admin_email` into the General save; WordPress/ai#1048). Left open updated.

### Added
- **Tag fit: Jev reads every note against its tags, and against the tags it does not carry.** Tags are the one editorial field a published note can still change: they are not prose, so a tag edit moves nothing the signature covers. `inc/jev-tags.php` sends one request per published or scheduled note with the tag descriptions as the state: one Score per attached tag ("Does `note` argue about what `tags.tN` names?": 0 attached for reach, 1 touches it, 2 argues it) and one Noul per tag the note does not carry ("Would a reader browsing `tags.tN` expect to find `note` under it?"), the sixty most-used candidates. Weekly (`sn_jev_tags_weekly`, registries and deactivation list) and on demand. **Check 31, `jev_tags`**, lists per note the attached tags scored under 1 of 2 and the missing tags at 0.6 or above, and its hint says the part that matters: Jev read each tag's DESCRIPTION, so a wrong reading of a right tag is the description to fix under Content › Tags, not the tag. Abilities `jev-tags-now` (WRITE, rw door 14 → 15) and `jev-tags` (READ, 43 → 44), pinned; meter feature `tags`. `tests/jev-tags.php` (17).
- **A watch for the General-save guard.** `general_save_guard_ai_1048` ripens when `admin_email` stops landing in the "general" allowed group after the Abilities registry has initialised (WordPress/ai#1048 shipped, or the AI plugin gone), which is when to verify a save without the guard and remove `inc/general-save-guard.php`. Pinned.

