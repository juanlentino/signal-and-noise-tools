# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [19.9.0] - 2026-09-30 — résumé drafts, nothing goes live on save


- **Résumé drafts: saving the form no longer changes the live /resume page.** Content → Resume Page (both the native window and the classic screen) now saves a draft (`sn_resume_draft`, autoload off) and paints it back, with a status line saying whether the draft differs from live. New controls appear only when they can act: Preview page (the draft as an autosave of the /resume Page, opened in WordPress's own preview), Preview PDF (the draft's PDF under the public phone rule, streamed, never stored), Publish (today's save, then the PDF is rebuilt if one was ever generated, else /resume is purged; a draft equal to live publishes nothing), Discard, and Revert (swaps live with the version the last Publish replaced, each keeping its own date, so Revert twice returns). A stored draft that can no longer be read says so ("Draft could not be read; discard it.") and keeps its Discard button. Saving never publishes: the old save-and-publish route (`resume_save`, its handler and its four flash codes) is gone. This also fixes the existing "Download private copy (with phone)" control in the native window: it was a form, and a window replays a form's handler inside a dispatch, so the streamed PDF never reached the reader; it is now a door (and a new-tab link on the classic screen), like the previews. `tests/resume-draft.php` (41) pins the data layer, the Preview page autosave (slashed, as the Page sync hands `wp_update_post`) and redirect, and the handlers' flash codes.

- CI: the "Parity cron still firing" check reads each workflow's scheduled-run list three times and keeps the newest stamp. On 2026-09-30 GitHub twice served a stale list (newest run 8 and 25 days old while both crons had fired that morning) and redded #1806; one stale answer can no longer do that, and a cron that has really stopped still fails.


