# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Changed
- **The AI plugin writes the excerpt and the meta description again; this plugin attaches to it instead of replacing it.** Since June this plugin switched both official features off and ran its own two buttons, while Settings › AI went on showing them as on. The two off switches are removed. The official buttons now run with three things attached: the description is stored in this plugin's own field (`_sn_meta_description`), so the SEO module keeps printing the one description tag and the AI plugin prints none; the house voice rules are appended to the official instruction for both; and a person still applies and saves the result. This plugin's two buttons stand down while the official feature is on and the post is long enough for it (the official buttons are greyed under 250 characters), and stay otherwise: on a short post, an empty template page, with the feature switched off, or with the AI plugin absent. Not carried over: these calls are the AI plugin's, so they sit outside the monthly cap and are listed under Other AI. Their model is the one chosen in Settings › AI. New `inc/ai-official-generators.php`; tests `tests/ai-official-generators.php`, `tests/ai-ai-dedupe.php`.

### Changed
- **The AI plugin's bulk "Generate Summary" action is removed from the Posts list.** It wrote model text into up to 100 selected posts with nobody reading each result, and on a published note every save is a provenance version made outside the write door. The per-post Summary button in the editor stays: there a person reads the summary before saving. A crafted request for the removed action is not handled. New `inc/ai-official-complement.php`; test `tests/ai-official-complement.php`.
- **A note advertises one Markdown copy.** Each note's head carried two `text/markdown` links to two different documents: this site's `.md` (served at the edge, with the provenance record) and the AI plugin's `?output_format=markdown`. On notes the second link is no longer printed; the Markdown feed link stays, pages are untouched, and the document itself still answers.
- Admin text: the "no token record" notice points to Tools › AI Request Logs, where AI plugin 1.5.0 put them.

### Fixed
- **The analytics verdict can no longer be cleared by a failed version read or a worker rollback.** Analytics worker 2.0.0 stops writing the legacy dataset, and the plugin learns that only from the worker's version. Two gaps let the daily check compare against a dataset that receives nothing, call every day since the stop a mismatch and send every read, and the nightly rollups that replace stored days, back to it. A good frozen verdict is now final: the check returns it without comparing, whatever the version reads later; clearing `frozen` is done by hand. And a mismatch only replaces a good verdict when the edge itself answers with a version: if that read fails, the stored verdict is kept and the next run decides (it used to fall back to the last good version, which can predate the stop). Install this before worker 2.0.0 is merged. Tests: `tests/analytics-generation.php`.
- **A note's "after the first hour" figures keep counting for notes that went live before October 18.** The publish window read the whole span from one dataset, the legacy one for those notes, so the figures would have stopped growing when the legacy write stops. It is now read in two halves like the lanes table. Test: `tests/publish-window.php` (fails without the fix).

### Changed
- The `analytics-dual-write` tool's description says what it shows once worker 2.0.0 has stopped the legacy write: the verdict is frozen, and later days read as a mismatch that moves nothing.
- The two "analytics worker misconfigured" messages name the dataset bindings worker 2.0.0 uses (`SN_AE_PV`, `SN_AE_EVENTS`) beside the old `SN_AE`.

## [2026.11.19] - 2026-11-09 — The SN Queue keeps a weekday rule

### Added
- **The SN Queue keeps a weekday rule: from Jan 4, 2027, notes go out on Mondays and Thursdays.** Queue calendar phase 2, item 4, the rule the owner picked on 2026-11-09 (all 18 notes scheduled from Jan 4 already keep it).
  - A scheduled note on a day the rule leaves out is an "Off day" line among the cadence warnings, on the card and in the Cadence box on both Connections › Scheduled twins: "Off day: a note on Tue, Jan 12, 2027 (from Jan 4, 2027, notes go out on Mon and Thu)".
  - Suggested slots land only on an allowed day. When the usual spacing lands on a day the rule leaves out, the slot takes the nearest allowed day, held 2 to 7 days after the last note, so after a Monday it suggests the Thursday, not the next Monday a week on. On the live queue, three drafts would be suggested Mon Mar 8, Thu Mar 11 and Mon Mar 15, 2027.
  - The rules are a setting (`sn_content_queue_weekday_rules`), edited in a new **Weekday rules** box on both Scheduled twins: each rule in words, then Starts, Ends and a checkbox per weekday. The owner's rule is seeded once on the first admin load after install. Where two rules cover a day, the later start wins.
  - An independent review found six issues, all fixed before merge. A pull could land 8 days on, opening a gap. A reason named the wrong cause after a walk past a planned day. The native form's weekday boxes had no group name. Its end field was labeled differently from the classic one. Two orderings had no test (an off day before a gap, and the tie between two allowed days).
  - Without a rule, the warnings and slots are exactly what they were. The content-queue ability declares the new `offday` kind.
  - Item 5 (pending links) is parked: its one real case, "The catalog with no engineer" linking to "The key stays in the drawer", is already linked.
  - Docs: `docs/DESKTOP-CARDS.md` (Weekday rules). Tests: `tests/queue-weekday-rules.php`, `tests/queue-weekday-twins.php` (both new), `tests/abilities-content-queue.php`, `tests/queue-slot-twins.php`.

