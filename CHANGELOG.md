# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

<<<<<<< HEAD
### Added
- **/cv/ and `JuanLentino_CV.pdf`, the Spanish résumé** (owner, 2026-10-15: a PDF too, and no second résumé system in the settings). One Spanish file, `inc/seed-content/resume-data-es.json`, renders both through the same page builder and PDF template as /resume, with their fixed headings in Spanish (`inc/resume-lang.php`); English output is byte for byte unchanged. Contact details come from the live English résumé, so the phone never reaches the repo. /cv/ re-renders on the next admin load after the file or the contact details change, and its PDF re-renders every time the English PDF is generated. /cv/ is a fifth Spanish page paired to /resume. The Spanish pages seed now records each slug it handled, so a slug added later is seeded on the next admin load. A lock (`sn_spanish_pages_seed_lock`, taken over after ten minutes) replaces the run-once flag. Pinned in `tests/resume-es.php` and `tests/bilingual.php`.
=======
### Changed
- **The Release notes box is compact** (Connections › Discography). Each saved note is now one line: the release name, then the English and Spanish fields side by side. "Add a note" is folded into one line until opened. It was a fieldset of three stacked labeled fields per note, and the owner flagged how much of the page it took (2026-10-15). Saving is unchanged.
>>>>>>> 72cacc396d4e6b22b3d8f4f4b6bc662ecea6bd06

## [2026.10.16] - 2026-10-15 — Spanish storefront pages and release notes

### Added
- **Spanish versions of the storefront pages: `/inicio/`, `/sobre-mi/`, `/musica/`, `/servicios/`.** Built into the plugin, with no translation plugin. A Spanish page is an ordinary Page whose one new meta row, `_sn_en_counterpart`, names its English page. The block editor sets it with a **Spanish version of** picker in the Signal & Noise panel. From that row the page gets:
  - `<html lang="es-AR">` (the copy is Argentine Spanish, voseo);
  - reciprocal hreflang links (`en`, `es`, and `x-default` pointing to English) on both pages of the pair;
  - `og:locale` `es_AR` and WebPage `inLanguage` `es-AR`;
  - its English page's template (`page_template_hierarchy`);
  - the English page's slug gates through `sn_lang_is_page()` (the music schema, the services schema, the ProfilePage type);
  - the English body, marked `lang="en"`, while its own body is empty;
  - the English meta description while its own is empty.
  English URLs did not change, and there is no `/es/` prefix. The four pages are seeded once as **drafts** with empty bodies (`sn_spanish_pages_seeded_v1`), skipping any slug already taken by a page or a redirect. A draft shows no toggle and no hreflang, so nothing is public until the owner publishes each page with its Spanish copy. Published Spanish pages are in the core sitemap and purge with every Page save. The cookieless analytics record `/musica` as its own path. How it works, and how to add a page: `docs/BILINGUAL.md`. The theme's EN / ES toggle ships in signal-and-noise alongside this. Pinned in `tests/bilingual.php`.
- **Release notes on /music (Connections › Discography › Release notes).** This is a short line under a release's roles, in English and Spanish, for what Muso.AI cannot say. The Richter releases get "Stage manager (listed on Muso.AI as Manager)." once, after the store has synced; the Spanish line is left for the owner. The notes are kept in their own option, `sn_discography_notes`, keyed by release id, because the daily sync rewrites the store. They are merged at the `sn_discography_entries` filter. Saving purges the page cache. The classic leaf and the dashboard twin both have the box, and each box saves only its own fields. Pinned in `tests/discography-notes.php`.

