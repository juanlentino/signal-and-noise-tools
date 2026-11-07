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
- **The services seed pages follow the redesign: Side A and Side B, with photos.** The owner redesigned the hub as a record sleeve and asked for images back, so `/services`, `/services/music/`, `/services/consulting/` and their Spanish pages were rewritten through `sn-apply`, and the six seed files now hold the same text. The migration is spent on the live site, so nothing runs on install. New copy on the hub: Side A, Side B, Play side A, Play side B, and a short line for each side. Eyebrows read "Side A · 05 Offerings" and "Side B · 03 Offerings". How a side is built and how to add a third: `docs/BILINGUAL.md`.

## [2026.11.14] - 2026-11-07 — Services hub, Music & Production and Consulting

### Added
- **/services is now a hub with two doors: Music & Production and Consulting.** The next admin load after install runs one migration (`inc/services-hub-migration.php`). It creates `/services/music/`, `/services/consulting/`, `/servicios/musica/` and `/servicios/consultoria/`, published, on the Services template, with their Spanish pairs and descriptions, and only then replaces the `/services` and `/servicios` bodies. A hub whose body no longer matches the text captured on 2026-11-06 is left alone, an existing child is kept, and the result is stored in `sn_services_hub_migrated_v1`. The flag waits until every step has settled, so a run that finds the forms plugin inactive tries again on the next admin load. The music pages are built from the live bodies, so their words moved unchanged. Removed there: the Operations & AI Strategy card (replaced by the Consulting page), with its "decade-plus running Panacea" line and its `/workflow/` link; the "06 Offerings · 02 Sections" line and the "04 / 06" and "02 / 06" counters (now "05 Offerings" and "05 / 05"); the "Business & Strategy" label; "and AI" in the remote-work sentence. The hire line moved to Consulting. Theme 2026.11.5 carries the row styles, so install it first. How the hub works and how to add a third door: `docs/BILINGUAL.md`. Test: `tests/services-hub-migration.php` (new).
- **The contact form takes a consulting topic.** The same migration adds "Business consulting" (value `Consulting`) to the form's `intent` field, right after the music one, and sets the field to preselect from `?topic=`, so the Consulting button lands on `/contact/?topic=Consulting` with it chosen. The form is English only, so Spanish visitors see the English label.

### Changed
- **The AI plugin experiments that can change a signed note are locked off in code.** Editorial Updates, Editorial Notes, WebMCP and Text to Speech can edit a note outside sn-apply, so its gates and change_class never see it and provenance mints an unlabeled version. A Settings checkbox was all that kept them off. They now use the same `wpai_feature_{id}_enabled` filter as the two duplicates, ids verified at AI plugin 1.5.0. The plugin's other features and its connector access are unchanged. `inc/ai-ai-dedupe.php`. Test: `tests/ai-ai-dedupe.php`.
- **The MCP adapter watch says what it waits on.** MCP Adapter 0.7.1 is on WordPress.org, so the port waits on the owner turning MCP Access on, not on a release. The note says so, and the watch stays quiet rather than ripe until that call is made. `inc/watches.php`.
- **The test sweep is ready for WordPress 7.2's block parser.** From 7.2, `WP_Block_Parser` needs `WP_Block_Processor`, and `tests/resume-lane-marks.php` did not load it, so the suite crashed on Beta 4 with `Class "WP_Block_Processor" not found`. The suite now loads it, and `tools/fetch-wp-test-core.sh` fetches it. The file also exists in 7.1, so nothing changes for the current sweep. Checked on 7.1.4 and 7.2 Beta 4: 40 passed each. Test setup only: the site does not change.
- **The services schema lists what is offered now.** `ProfessionalService` on the hub replaces "Operations & AI Strategy" with Strategy & Growth, Pricing & Operations and AI Adoption.
- **The two new English pages count as core pages for indexing**, and their Spanish pairs follow through `_sn_en_counterpart`.

### Fixed
- **`sn_lang_is_page()` no longer treats a child page as the top-level page with its slug.** `/services/music/` needs the slug `music`, so core's `is_page( 'music' )` is true there, and the music schema would have run on it and on `/servicios/musica/`.

