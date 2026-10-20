# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.53] - 2026-10-20 — the strict CSP can switch on: pages that need the loose one say so


- **Responses the strict CSP must leave loose now say so.** The plugin sends `X-SN-CSP: lax`, once, on every front-end request from a signed-in user (the admin bar), on wp-admin outside admin-ajax, on every login screen through `login_init` (the hidden door and its lost-password, reset, 2FA and passkey steps), and on any page that carries the AllTerrain Forms form wherever it is placed: its block or shortcode in the content or rendering in a template or pattern, or its `allterrain-forms-config` script queued, plus `/contact/` by slug (the form prints a per-request config script the strict policy blocks). A Cloudflare Transform Rule enforces the strict policy on everything without the marker; a stranger on an ordinary public page is never marked, and REST is not. The daily CSP check now ripens the `csp_hashes` watch when a page in its cookieless set answers with the marker, ahead of the "not deployed" verdict that a fully marked set would otherwise hide behind (`inc/csp-lax-marker.php`, `tests/csp-lax-marker.php`).


