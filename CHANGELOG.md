# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [17.8.0] - 2026-09-23 — Home, editable

### New
- **The Home page is a CMS page you can edit.** The front-page Page (Settings → Reading) sat at 0 words while the theme drew the hero from `templates/front-page.html`; a Site Editor edit to the hero line became a template override, and the theme deletes those on every activation and every Purge All Caches, so the owner's line kept disappearing (2026-09-23). `inc/home-page-seed.php` seeds that Page once from `inc/seed-content/home-body.html`: the same hero, with its raw-HTML wrapper turned into a Group block so the editor can hold it. Create-once, never overwrites a Page with content, retries while there is no static front page or seed, on its own `admin_init` hook (the content-migrations master flag is already set on the live site, so a registry entry would never run). Install this BEFORE theme 14.3.0, which starts rendering the Page. `tests/home-page-seed.php` (8) pins the seed and each branch; dropping the never-overwrite guard fails it.

