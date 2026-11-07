# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

### Added
- **/services is now a hub with two doors: Music & Production and Consulting.** The next admin load after install runs one migration (`inc/services-hub-migration.php`). It creates `/services/music/`, `/services/consulting/`, `/servicios/musica/` and `/servicios/consultoria/`, published, on the Services template, with their Spanish pairs and descriptions, and only then replaces the `/services` and `/servicios` bodies. A hub whose body no longer matches the text captured on 2026-11-06 is left alone, an existing child is kept, and the result is stored in `sn_services_hub_migrated_v1`. The flag waits until every step has settled, so a run that finds the forms plugin inactive tries again on the next admin load. The music pages are built from the live bodies, so their words moved unchanged. Removed there: the Operations & AI Strategy card (replaced by the Consulting page), with its "decade-plus running Panacea" line and its `/workflow/` link; the "06 Offerings · 02 Sections" line and the "04 / 06" and "02 / 06" counters (now "05 Offerings" and "05 / 05"); the "Business & Strategy" label; "and AI" in the remote-work sentence. The hire line moved to Consulting. The next theme release carries the row styles, so install it first. How the hub works and how to add a third door: `docs/BILINGUAL.md`. Test: `tests/services-hub-migration.php` (new).
- **The contact form takes a consulting topic.** The same migration adds "Business consulting" (value `Consulting`) to the form's `intent` field, right after the music one, and sets the field to preselect from `?topic=`, so the Consulting button lands on `/contact/?topic=Consulting` with it chosen. The form is English only, so Spanish visitors see the English label.

### Changed
- **The services schema lists what is offered now.** `ProfessionalService` on the hub replaces "Operations & AI Strategy" with Strategy & Growth, Pricing & Operations and AI Adoption.
- **The two new English pages count as core pages for indexing**, and their Spanish pairs follow through `_sn_en_counterpart`.

### Fixed
- **`sn_lang_is_page()` no longer treats a child page as the top-level page with its slug.** `/services/music/` needs the slug `music`, so core's `is_page( 'music' )` is true there, and the music schema would have run on it and on `/servicios/musica/`.

## [2026.11.13] - 2026-11-06 — Each stored 5xx says when it happened

### Added
- **Each stored 5xx now says when it happened and from which network.** The daily rollup keeps how many and on which path, so on 2026-11-05 six 522s on the home page had no time to hold up against the origin's firewall. A separate query (a refusal costs the times, never the counts) stores each error group's minute in UTC, Cloudflare colo, asker, status, network number and name, and path, as `err_when` rows. No IP address is stored. It reads back as `errors_5xx.when` on the local `cloudflare-status`, newest first up to 100 with a flag when older rows exist past that, along with the query's last outcome so an empty list is not read as no errors, and whether the day reached the limit of 100 groups. Local door only: the remote contract does not move. It starts with the next daily rollup; earlier days have no times. `inc/edge-error-when.php` (new). Test: `tests/edge-errors-when.php` (new).

