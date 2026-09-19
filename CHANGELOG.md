# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Docs
- Session doc extended: "The file is named, and the option the form never posted" (16.7.0 through 16.7.2: the anti-tell pass and its first corpus reading, the lane map on one copy of the corpus, the logo that named a deleted file, and the Settings › General banner traced to the AI plugin registering `admin_email` into the General save; WordPress/ai#1048). Left open updated.

### Added
- **Tag fit: Jev reads every note against its tags, and against the tags it does not carry.** Tags are the one editorial field a published note can still change: they are not prose, so a tag edit moves nothing the signature covers. `inc/jev-tags.php` sends one request per published or scheduled note with the tag descriptions as the state: one Score per attached tag ("Does `note` argue about what `tags.tN` names?": 0 attached for reach, 1 touches it, 2 argues it) and one Noul per tag the note does not carry ("Would a reader browsing `tags.tN` expect to find `note` under it?"), the sixty most-used candidates. Weekly (`sn_jev_tags_weekly`, registries and deactivation list) and on demand. **Check 31, `jev_tags`**, lists per note the attached tags scored under 1 of 2 and the missing tags at 0.6 or above, and its hint says the part that matters: Jev read each tag's DESCRIPTION, so a wrong reading of a right tag is the description to fix under Content › Tags, not the tag. Abilities `jev-tags-now` (WRITE, rw door 14 → 15) and `jev-tags` (READ, 43 → 44), pinned; meter feature `tags`. `tests/jev-tags.php` (17).
- **A watch for the General-save guard.** `general_save_guard_ai_1048` ripens when `admin_email` stops landing in the "general" allowed group after the Abilities registry has initialised (WordPress/ai#1048 shipped, or the AI plugin gone), which is when to verify a save without the guard and remove `inc/general-save-guard.php`. Pinned.

## [16.7.2] - 2026-09-19 — the file is named, not read

### Fixed
- **The OG default image pointed at a file that no longer exists.** `og.default_image_url` named the February logo PNG; the logo was re-uploaded as WebP under `uploads/2026/09/` on 2026-09-19 and the PNGs removed (the theme's header had the same path, theme 13.3.2). The default is now the 300 WebP. It is only the fallback for a route with no generated card, which is why nothing visible broke on the notes.
- **Settings › General saves clean again: `admin_email` is dropped from the General save.** The AI plugin (1.2.0+, WordPress/ai#856) calls Core's `register_initial_settings()` early on `wp_abilities_api_init`, so on an admin POST where the Abilities registry is initialised Core's nine general settings land in the "general" allowed group; options.php saves every entry in the group, posted or not, and `admin_email`, which the form does not post (it posts `new_admin_email`), was saved as NULL and rejected with "not a valid email address" on every save. Traced on the live site with a filter on `sanitize_email` and a backtrace to `options.php:345`; filed upstream as WordPress/ai#1048. `inc/general-save-guard.php` filters `allowed_options` at 20 (after Core's `option_update_filter`) and removes `admin_email` from the group; Core's own list never contains it. Remove the day upstream ships. Pinned (5).
- **One lane map, one mode.** 16.7.1 kept the per-note path as `mode: notes` for a live comparison on the same corpus in the same hour: 44 requests, 485k tokens, 17 pairs at 0.5, against 4 requests, 55k tokens, 4 pairs. The same four pairs at the top in the same order, values about 0.08 lower, the 0.5-to-0.6 vocabulary band mostly under the line, nine times cheaper. The per-note path is gone; `jev-lane-map` takes no input again and `jev-lanes` reports `requests`. Pinned (39).

### Added
- **Two watches from the WordPress 7.2 roadmap read.** `connector_key_wipe_65551`: since 16.5.2 the TypeSafe key is Core's connector's, and Core's settings save deletes a key it cannot validate, `null` included (Trac #65551, unmerged); the watch ripens on WordPress 7.2, when the fix is to be verified. `mcp_adapter_read_door`: the plugin hand-rolls its MCP transport and WordPress/mcp-adapter is heading for the directory with the 2026-07-28 revision; the watch ripens when the adapter class is loaded, when the abilities register with it and the read door retires first. Both state-ripe, both pinned. The full read is `docs/ops/wordpress-7-2-roadmap-read.md`.

