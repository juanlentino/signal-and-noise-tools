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
- **The forms spam scan reads the entries it scans.** AllTerrain Forms stores an entry's values as a JSON string; the 18.8.0 sweep cast that string to an array, so the rules saw one opaque value and flagged none of 338 inbox entries. It now decodes them (`snt_fs_entry_values()`, the same `json_decode` Forms uses). The live filter on new submissions was unaffected: Forms hands it the decoded array. The test had built entries as arrays, looser than the store; it now stores them as Forms does, and fails on the 18.8.0 read.

## [18.8.0] - 2026-09-26 — contact-form spam caught on our side

### New
- **Contact-form spam is caught on our side of AllTerrain Forms.** A filter on its `alltfo_spam_verdict` runs after the form's own honeypot, time trap and rate limit, on this server, with no outside service. Strong signals mark spam alone: a link or a crypto/"NEW MESSAGE" lure in the name, a name no person has ("RobertBiB RonaldBiBGM", "NATREGTEGH475080..."). Weak ones need two: an emoji in the name, three or more short letter-and-digit answers, a throwaway mail domain. The entry's reason reads `snt:<signals>`. `signal-noise/forms-spam-scan` (read door 50 to 51) lists inbox entries the rules would catch and each form's defences; `signal-noise/forms-spam-apply` (rw door 16 to 17) marks only entries the rules flag at call time, through Forms' own status setter so Not spam undoes it, and can switch the free defences on where they are off. Nothing is deleted. It also keeps the north star's inquiries count honest. `tests/forms-spam.php` runs the rules on the real spam and on plausible real inquiries that must pass, with a negative control on the two-weak threshold.

