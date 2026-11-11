# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.11.25] - 2026-11-11 — Ask AI carries seven abilities, legacy bookmarks redirect, RSS forms return home

### Changed
- **Ask AI carries seven of this plugin's abilities instead of 86.** OpenStation offers every read-only ability on the site to Ask AI and sends their definitions on every call, before the question is read. Measured live on 2026-11-11: 125 tool definitions, about 156k characters, of which this plugin's were 122k. The AI request log since 2026-07-29 shows Ask AI used only a handful of them. The Copilot list is now opt-in (`snt_dm_ai_copilot_kept_abilities()` in `inc/desktop-mode-ai.php`): `sn-posts`, `list-posts`, `get-post-content`, `content-queue`, `get-analytics-summary`, `live-now` and `provenance-integrity-status` stay; the other 79 leave Ask AI only. Every ability stays registered and callable through REST, MCP and agents (the agents runner builds its tools from your picks, not from this list), and a new ability stays off Ask AI until it is added. The theme's, OpenStation's and core's tools are untouched. By estimate this takes about 30k tokens off each Ask AI call. Test: `tests/desktop-mode-integration.php`, which fails against the old deny list.

### Fixed
- **Saving, resetting or purging on the classic RSS screen returns to that screen with its message.** The forms redirected to the old `?tab=rss` address, which no longer forwards to Monitoring > RSS on the live site: the page showed the Dashboard and the "saved" message was lost. They now redirect to `tab=monitoring&sub=rss`. The OpenStation window was not affected.
- **An old admin bookmark opens its new home instead of an empty page.** `sn_admin_maybe_redirect_legacy()` ran inside `sn_theme_options_page()`, the menu render callback, which core calls after `admin-header.php` has printed. Its 301 header failed, `exit` ran, and a legacy URL such as `?page=sn-theme-options&tab=rss` answered 200 with an empty `#wpbody-content` (seen live on 2026-11-10). It now runs on `load-{$hook}` for every registered S&N page (`inc/admin-menu.php`), after core's page-access check and before any output. It answers GET and HEAD only, so a form posted to a legacy URL keeps its body, and it carries every other query arg through, so a handler's flash (`?sn_rss_ok`, `?sn_flash`) survives the hop and lands ahead of the `#fragment`. Test: `tests/admin-legacy-redirect-early.php` (new), which fails on the old wiring.
- **A Copilot Usage bookmark opens AI > Agent tools.** `tools/copilot-usage` still pointed at `ai/copilot-usage`, a leaf that 15.6.0 folded into Agent tools. Both now land on `ai/agent-tools`, and `tests/admin-ia-redirect.php` checks that every move in the map lands on a leaf that exists.

