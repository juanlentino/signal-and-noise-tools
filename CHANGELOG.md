# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [22.9.0] - 2026-10-07 — a watch for the Secrets API, an unreadable contrast summary says so

### Added
- **A watch for Core's Secrets API** (#1625): `secrets_api_keyring_storage` reads pending until `wp_set_secret` exists on the site (WordPress 7.2 Beta 1 is due 20 to 22 October), then ripens with the plan: keyring storage moves onto it, the registry, probes and Verify all stay ours, and no issued row becomes a Core connector.

### Fixed
- **A contrast summary the report cannot read says so.** A green run whose `contrast-summary` is present but unusable read as the exit-2 diagnosis (the sitemap, a page or the edge failed); it now has its own reason and line, and points at the runner's output.

