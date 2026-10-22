# Changelog

All notable changes to Signal & Noise Tools are documented here.

This file holds two things only: **`## [Unreleased]`**, the working log that
accumulates across pull requests, and the **current release**. Everything older
lives in [docs/changelog/](docs/changelog/).

A pull request does not bump `Version` and does not tag — it closes an issue and
adds a bullet below. A release is a separate, deliberate act:
`tools/cut-release.sh`.

## [Unreleased]

## [2026.10.56] - 2026-10-22 — the indexing check counts links from the nav, templates and rendered blocks


- **Fixed: the indexing publish check counts the links the site actually prints.** The link graph read raw post bodies, so it missed three kinds of real links, and pages like /about, /resume, /now and /colophon were flagged for having almost no inbound links. Now: (1) each note and page body is read rendered through core (blocks, then shortcodes, with the post set up and HTTP short-circuited), so links a dynamic block or a shortcode prints count, such as the /maturity index cards; a render that throws falls back to that post's raw body and is retried on the next read; a successful render is cached per post on its modified time and the plugin and theme versions; one read renders for at most 15 seconds, saving every 20 renders, and leaves the rest to raw bodies until the next read continues; style and script queues are restored after each render; and the front page counts as a source. (2) The active theme's header and footer, rendered through core and cached per theme and parts, count as links from every page; a Spanish page counts when its English page is linked there, since the Spanish header links the twin. (3) Links in the single and page templates outside the post content count once per indexed note or page, and the front-page template's once for the home page; templates are read from their block markup, because per-note blocks would otherwise count links that are not on every note. Sources are counted as one set, so a page linking in its body and its template counts once. Failure rows name their `inbound_source` (chrome, template or body), request and stuck rows carry `chrome` and `template` beside the unchanged body count, the publish check names the sets it used and the cost of the read (`graph_render`), and both report surfaces say "linked from the site header or footer" or "linked from every note's template" instead of a bare 0. Notes never borrow the header and footer. Remote contract 23; the remote worker (sn-remote-mcp-worker) needs its own CONTRACT_VERSION bump to 23 in a separate pull request (`inc/ml-link-graph-render.php`, `inc/search-console-indexing-chrome.php`, `inc/search-console-indexing-site.php`, `tests/ml-link-graph-render.php`, `tests/search-console-indexing-chrome.php`).


