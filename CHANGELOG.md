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
- **Gap analysis: gap 3 is designed within one author, and nothing waits on another person.** `docs/proposals/proving-the-provenance-thesis.md` records the owner's 2026-10-05 direction that this is and stays a single-author site and the design works within that: roles as a one-party list, additive signatures from the author's own second key (which also opens custody), ownership as signed mutable rights records, and disputes as self-corrections on the retraction shape. Gap 1's parked list now says what each item actually needs: a recognition attestation is a third party's signed claim about the author (none exists, so the attestation term stays zero), and custody needs no one else. Docs only.
- **The dual-write check accepts the same sample on both sides.** Analytics Engine samples ordinary visitor-days at write time (22.3.0's diagnostic: about one in six human visitor-days, typically 2 stored rows standing for 4; every figure has always carried these estimates), so a day with any sampled event could never match and analytics 2.0 could never start. The two pageview datasets were measured holding the same sample. An event type now counts as exact when both pageview datasets hold the same sample: the same totals AND, read visitor by visitor, the same sampled visitor-days with the same stored rows and weights. It is listed under `identical_sample`; a different sample stays inconclusive and an exact difference is still a mismatch. Owner-approved.

## [22.4.0] - 2026-10-05 — BREAKING: the publish-time AI auto-fill is gone

### Removed
- **The "auto-generated at publish" notice, its dismiss ability and the sentinels.** They tracked fields the removed auto-fill had written and nobody had yet acknowledged. The four it left on the site (the meta description and OG card title of "Uses" and of "Market harm names no track") were each reviewed by the owner on 2026-10-05 and the sentinel count read zero, so the tracking went too: `inc/ai-prepopulate.php`, `inc/ai-prepopulate-notice.php`, `assets/prepop-notice.js`, the panel's notice and its REST-save acknowledgement, the three sentinel meta registrations, and the `signal-noise/prepop-dismiss` ability (the notice file's header also named a dismiss REST route, but none was registered by then). Figures this changes: the catalog's plugin-ability total goes from 113 to 112 (146 to 145 with twins and theme; 48 to 47 on neither door); no door count changes, since the ability was on neither. The AI-maturity page and AI.md now say the fields the fill had left marked were reviewed. `tests/prepop-removed.php` now fails if any shipped file names the fill or its tracking again.
- **The publish-time AI auto-fill.** When a note or page went live with an empty meta description, OG card title or excerpt, a background job filled them with no review step. It is removed (owner decision 2026-10-05), so no model text reaches a published field without a person applying it: the editor's Suggest and Apply buttons are the path for all three. Text the job already wrote keeps its "auto-generated at publish" notice until a person saves or dismisses it; the notice, its dismiss ability and the sentinels stay until none is left on the site. Removed with it: the `snt_prepop_event` handler, schedule and deactivation entry (an event still queued at deploy fires once with no handler), `snt_prepop_passes_content_gate()`, the `SNT_PREPOP_*` constants and the `snt_prepop_min_words`, `snt_prepop_schedule_jitter_max` and `snt_prepop_daily_call_ceiling` filters (no caller here or in the theme). No figure on any screen came from the job. AI.md drops its exception and the public AI-maturity page says nothing is generated at publish. Pinned in `tests/prepop-removed.php`, which fails against the old file.

