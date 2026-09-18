# Signal & Noise Tools

Companion plugin to the [**Signal & Noise** theme](https://github.com/juanlentino/signal-and-noise) for [juanlentino.com](https://juanlentino.com). It holds the operational tooling that doesn't belong in a presentation theme — SEO, security, analytics, admin surfaces, and AI-assisted editorial helpers — so the theme stays focused on design and the plugin owns behaviour.

Built on WordPress 7.0's Abilities API and AI Client (what every model does here, and what agents read, is in [AI.md](AI.md)): it both registers the site's capabilities for AI agents and ships in-editor AI helpers (alt text, meta descriptions, excerpts, brand-voice checks) that call the site owner's configured model provider.

<!-- screenshot placeholder — admin UI (Appearance → Signal & Noise) -->
<!-- ![Signal & Noise Tools admin](docs/screenshot.png) -->

## What it does

- **SEO** — meta, canonicals, OG cards, sitemaps + IndexNow, a redirect manager with a 404 log
- **Security** — WordPress hardening, a custom login slug, a read-only panel over the edge login guard
- **Analytics** — first-party, cookieless, edge-collected; SQL rollups, a dashboard, AI narration
- **Content health** — a 30-check scan from Measurement → Health or the `run-health-scan` ability
- **Provenance** — every Note Ed25519-signed and Bitcoin-anchored; readers verify without trusting the site
- **Citation graph** — a Webmention receiver that treats every claim as unverified until cron checks it
- **Edge cache** — automatic Cloudflare purge on save / theme update
- **Music / discography** — a daily Muso.AI + Spotify sync the theme's `/music` page reads
- **Admin UI** — eight tabs, the analytics dashboard, command palette, cron, audit log, deploy/health
- **OpenStation** — three native windows, 10 widgets, 22 palette commands, the Copilot seams
- **AI, models and Jev** — three kinds of model, one job each: a text model suggests, an embedding model relates, and Jev judges; nothing a model says is written to a note without a human's click
- **Agent surface** — 109 abilities; an MCP server with a read door (41 tools) and a write door (12); the site as something agents read, with the rights terms they read it under
- **Self-updater** — GitHub-poll updater wired into WordPress's native update system

Each of these is expanded under [In depth](#in-depth).

## Cross-package contracts

The plugin coordinates with the theme through WordPress hooks rather than shared code:

| Hook | Direction | Purpose |
| --- | --- | --- |
| `sn_purge_all_caches_result` | Plugin → Theme | Trigger the theme's cache purge, return a count |
| `sn_clear_template_overrides_result` | Plugin → Theme | Clear the theme's template overrides, return a count |
| `sn_gh_latest_theme_tag_result` / `sn_gh_latest_theme_tag_error_result` | Plugin → Theme | Read the theme's latest GitHub tag (and why a lookup failed) for the update panel |
| `sn_discography_entries` | Plugin → Theme | Supply the synced Muso.AI + Spotify discography; theme renders the `[sn_discography]` grid |
| `sn_music_featured` | Plugin → Theme | Supply the featured Spotify embed config for the `/music` hero (`[sn_music_featured]`) |
| `sn_websub_hub` | Plugin ↔ Theme | Shared hub value — the theme advertises it in feeds, the plugin pings it on publish |
| `identity.availability` (setting) | Plugin → Theme | Availability string the theme surfaces via `[sn_availability]` on `/contact` + `/services` |
| `sn_note_provenance` | Plugin → Theme | Per-Note provenance view-model; theme renders the byline chip (`sn_prov_render_chip`) + record panel (`sn_prov_render_panel`) |

## In depth

### SEO

meta descriptions, canonical handling, Open Graph cards, sitemaps + IndexNow, a redirect manager with a 404 capture log, and cache excludes (replaces a third-party SEO plugin)

### Security

WordPress hardening (Permissions-Policy, REST user-enumeration lock, XML-RPC off), a custom login slug, and a read-only login-defense panel over the edge login-guard Worker's decisions; the five headers Cloudflare emits at the edge (CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`) are drift-probed by a health check rather than duplicated in PHP

### Analytics

first-party, cookieless edge analytics: a Cloudflare Worker collects pageviews into Cloudflare Analytics Engine, server-side SQL rollups aggregate them into durable tables, and a server-rendered dashboard plus two home-dashboard widgets read them, with AI insights and narration derived from the same rollups (the third-party Plausible dependency this replaced is fully retired; only the widget IDs still carry the old name)

The **Posts** view is not built on pageviews. One row per published note over the dense signals the site already syncs: Search Console impressions, clicks and position (the sync's own window, labelled once), URL Inspection coverage with the coverage state verbatim, the last crawl marked when it predates the last edit, inbound internal links from the live link graph, the anchored provenance version and block, the ML kernel's related-note count, and lifetime views as a raw number with no verdict attached. Three flags, each a true binary derived in one place: **Not indexed**, **Stale crawl**, **Orphaned**. The queue lists only flagged notes, most urgent first, with the fix each flag implies; the full table sits behind a toggle and sorts on every column. A missing signal renders as its reason (not shown, not inspected, never crawled, unsigned), never as a zero. Machine reads appear once, site-wide, because the crawler sensor keeps no document paths. The sustained / cooling / spike shape survives only where it feeds the insights forecast, and only above a floor of 15 lifetime views. **Stale crawl** compares the crawl to the last *body* change (the newest signed commit; `post_modified` only for unsigned posts), so a tag edit or a bulk save is not a reason to ask Google back. The same rows ride the read door as `signal-noise/posts-signals` and the `posts_signals` section of `sn-status`, so an agent reads the three flags rather than re-deriving them.

The queue closes the loop. A tenth Attention reader, **Search**, reads the same rows and emits a row when a flag has stood past a horizon: a note *Discovered, not indexed* after seven days, *Crawled, not indexed* on sight (a verdict, not a wait), a *stale crawl* fourteen days after the body change. Each row's door is Search Console's URL Inspection page for that exact URL, because Google exposes no API to request indexing; the row states, the door points, you press, and Acknowledge holds until the state moves. New notes get two single inspections of their own, at day 3 and day 10 after publish, written into the same coverage map the weekly run writes, so "Discovered" surfaces in days rather than at the next Monday.

### Content health

a 30-check scan (missing alt text, orphaned media, broken internal + rotted external links, stale posts, time-phrase and color drift, unlinked mentions, link opportunities, edge security-header drift, edge-Worker reachability, analytics integrity, the provenance integrity sweep, the rights-signals drift probe, the public ledger's own CI, ML cousins, publishing cadence, the rights-signal anchoring gap, search titles, Zenodo DOIs, and Jev's reading of every note's search title and description), run from Measurement → Health or the `run-health-scan` ability (`inc/health-check-*.php` — 26 modules; a check is only live once it carries all four of its registrations)

### Provenance

cryptographic provenance for Notes: every publish/edit is Ed25519-signed, content-hashed, and Bitcoin-anchored via OpenTimestamps, then mirrored to a public [git ledger](https://github.com/juanlentino/signal-and-noise-provenance). Readers get a byline verification chip + expandable record panel (with clickable Bitcoin-block links) and can independently verify any Note on the public `/verify` page — including a word-level diff between any two signed versions — no trust in the site required. Backed by a dedicated `sn-provenance` Cloudflare Worker; the plugin owns the canonical form, chain, and admin panel

### Citation graph

a W3C Webmention receiver at `signal-noise/v1/webmention`, advertised both ways the spec allows (a `<link rel="webmention">` and a `Link:` header on every publicly viewable singular page). A webmention is treated as an **unverified claim**, never a fact: the public endpoint can only ever create an `unverified` row, and adjudication happens later on cron, which re-fetches each claim and sorts it by what can actually be checked. Claims whose link has gone, or that could not be reached, are kept but shown to nobody

### Edge cache

ONE Cloudflare credential set under Connections › Cloudflare (API token, zone ID, account ID; `inc/cloudflare-credentials.php`), resolved by every Cloudflare call the plugin makes: the purge, the monitor, the Analytics Engine reads and the Edge view. The leaf lists the grants the one token needs, each marked documented, measured or candidate. A separately saved analytics token keeps working as an override until dropped; an empty central token takes it once on upgrade. Automatic Cloudflare purge on save / theme update, plus a daily Cloudflare monitor (`inc/cloudflare-monitor.php`, Connections › Cloudflare › Monitor, `sn-status{cloudflare}`): the API token's status and expiry, the zone's last seven days (requests, cached share, bytes, threats, 4xx/5xx) and the firewall's last 24 hours (events by action, top rules). A reading the token cannot make names the permission to add (Zone › Analytics › Read) and is never counted as zero. It exists because Cloudflare publishes no rate-limit headers, so the API-limits row could never fill; that row now reads the monitor.

### Music / discography

a daily sync mirrors Muso.AI verified producer credits + Spotify album media into a cached store, exposed to the theme's `/music` page (role-filtered discography grid + featured player) via filters

### Admin UI

eight intent-coherent tabs (Dashboard, Site, Content, Connections, Measurement, AI, Security, Tools) plus the analytics dashboard, command palette, cron dashboard, audit log, and deploy/health views (native wp-admin styling)

### OpenStation

The plugin is a first-class citizen of the [WordPress/openstation](https://github.com/WordPress/openstation) shell, and still works on the pre-rename "Desktop Mode" family — which one is live is detected at runtime, never declared.

Three native App Framework windows:

| Window | What it paints | How |
| --- | --- | --- |
| **S&N Dashboard** (`apps/sn-dashboard`) | the classic admin page, all ~35 leaves | a *faithful* port: the same render callables paint the same HTML, every form saves through the same handler table — a host layer of four seams (capture, rewrite, assets, four write pipelines) |
| **S&N Analytics** (`apps/sn-analytics`) | 13 report views — overview, visits, content, posts, engagement, campaigns, search, geography, technology, events, quality, edge, login defense | server views on the shell's `<os-*>` kit |
| **Signal & Noise** (`apps/signal-noise`) | Notes, Pages, Discography, Citations, Schedules, Attention | native-only client view |

A per-user preference picks native or classic-iframe windows for the two that have a classic twin. It lives as a **Signal & Noise** tab in OpenStation Preferences — registered server-side with `openstation_register_settings_tab()` and painted client-side through `wp.os.registerSettingsTab()` — and the two classic admin URLs are remapped to their native windows with `registerNativeUrlRemap()`, so a deep link into the old screen opens the new one when the preference says so.

MIO, the shell's companion (OpenStation ≥ 1.1.9), inside the three windows. Three per-user switches on the same Signal & Noise tab: **tips**, plain-text callouts that say which state an item is in (an integrity verdict names the sweep it came from; a date-only watch says a clock rang and nothing was measured; an empty Commits table points at Trust checks), never a model call; **help**, five Markdown documents (`apps/signal-noise/help/`) and two read-only tools (`list_items`, `read_item`) registered for Ask MIO, which stays behind the shell's AI switch and connector gate, so the plugin makes no call of its own; **look**, the site's palette on the mascot through `openstation_mio_config`, opt-in, the user's own saved look still winning. No write-effect tool exists: every write here is a button.

Two columns in the shell's own native Posts window (OpenStation ≥ 1.1.8, `openstation.postsWindow.columns`, `assets/os-posts.js`): **Provenance**, the same anchor-status badge the Explorer paints, from the `sn_provenance` REST field; and **Edge**, the post's last edge-cache probe verdict (`fresh` / `stale`) from the `sn_edge` field, read by the same function the note dossier's Edge block reads. Both ride the list request the window already makes — no extra request per row. An unsigned Note paints no Provenance; a post with no probe in the site-wide twenty-row log paints no Edge — absence is a gap, never a pass. The first Signal & Noise views to move onto the shell's surface instead of ours.

An **Attention** pill in the same window's toolbar (`openstation.postsWindow.toolbarTrailing`): "Attention · N", where N is what the app *last* composed — the pill reads the app's 60-second cache through `GET /openstation/attention` and never runs the ten readers itself; with no cache it paints without a number. A click opens the Signal & Noise app on its Attention section (`wp.os.openWindow` with a `section` param, honoured by the app's `mount`/`reopen`). The section stays ours; the pill only points at it.

An Attention row can be **solved where it is**: an edge row offers **Purge edge**, an anchors row **Retry anchor** — the note dossier's own dispatches, with the post's type resolved from the section that lists it, never from the client — and every row offers **Acknowledge**, which hides it at its current stamp and brings it back the moment the fact moves (a new probe, sweep or commit is a new stamp), so an acknowledgement can never bury a recurring fault. The pill counts what is left.

Around the windows: 10 desktop widgets (site views, health, uptime, deploy status, cache, cron, quick actions, RSS subscribers, anchors, machine readers) · 22 `SN:` commands in the ⌘K palette · a dock entry with an update-count badge and two desktop icons · an attention badge carrying the plugin's real queues · an S&N Analytics card on Station Home (structured data, no plugin markup) · drop-to-draft on the shell's OS-file-drop pipeline · a repaired PWA manifest icon set · fixes to the shell's own Plugins window · a nav-id migration so dock placement survived the move from menus to apps.

The AI Copilot gets its tool schemas repaired at the boundary, a prune list that keeps the tool budget paid, a system-prompt appendix teaching it the analytics vocabulary, and generation-budget shaping for ceiling-bounded reasoning (upstream #517).

Every seam is pinned against a named upstream tag by `tests/openstation-compat.php`; `docs/openstation-compat.md` is the audit trail.

What the integration hit on its way in went upstream. Six pull requests merged into [WordPress/openstation](https://github.com/WordPress/openstation) so far — tool-schema normalisation for the AI Copilot ([#366](https://github.com/WordPress/openstation/pull/366)), an empty final answer surfaced as an error instead of a silent success ([#530](https://github.com/WordPress/openstation/pull/530)), `--wp-admin-theme-color` registered so chromeless documents never compute it to transparent ([#706](https://github.com/WordPress/openstation/pull/706)), widget chrome buttons raised to the 24px target-size floor ([#791](https://github.com/WordPress/openstation/pull/791)), `hide-label` on `os-text-field` ([#792](https://github.com/WordPress/openstation/pull/792)), Post Stats chart chrome drawn in tokens ([#793](https://github.com/WordPress/openstation/pull/793)) — plus a seventh in review that lets a registered settings tab name its sidebar glyph ([#809](https://github.com/WordPress/openstation/pull/809)), and the issues that preceded each. None was sought out: every one is a seam this plugin crossed first, reported so the next integration doesn't have to.

### AI, models and Jev

Three kinds of model run in the ecosystem, and each has exactly one job: a text model (Claude, through WordPress's AI Client) suggests, an embedding model (Workers AI) relates, and Jev (TypeSafe's System One, through [Connector for TypeSafe Jev](https://github.com/juanlentino/jev-connector)) judges. A human clicks before anything a model said reaches a published note, and the site is itself a thing models read, under stated rights terms. The whole map, with every reading, its rubric, its cost and where it is pinned, is [AI.md](AI.md).

### Agent surface

109 plugin-registered Abilities (alongside the theme's 16) reachable via `wp ability run` and the Abilities REST route, plus a native MCP JSON-RPC server with two curated doors: a read-only door at `signal-noise/v1/mcp` (41 slugs) and a read-write door at `signal-noise/v1/mcp-rw` (12 slugs) gated by a kill switch, a bound application password, a per-minute rate limit, and its own audit log. The write door is deliberately small: `sn-apply` is one tool covering every mutation, behind four gates (fingerprint, validation, capability, idempotency) with `dry_run` defaulting to true. **Both door sizes are pinned by `tests/mcp-capabilities.php`** — that suite, not this paragraph, is where the number is true.

Both doors are **dual-era**: the legacy `initialize` handshake (`2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`) and the modern per-request-metadata revision (`2026-07-28`) on the same endpoint, selected by how the request opens — modern `_meta` or a modern `MCP-Protocol-Version` header goes to `inc/mcp/mcp-modern.php`; an `initialize` goes to the legacy router in `inc/mcp/mcp-server.php`, byte-for-byte as before. The modern layer implements `server/discover`, validates the mirrored `Mcp-Method` / `Mcp-Name` headers against the body (`-32020`), answers an unknown version with `-32022` and the supported list, pairs `-32601` with HTTP 404, and decorates every result with `resultType` and `serverInfo`, list and read results with `ttlMs` / `cacheScope: "private"`. It is the same layer, check for check, as `src/modern.mjs` in the remote Worker; `tests/mcp-modern.php` mirrors the Worker's suite. A GET or DELETE on either door answers 405.

The write door, by name: `sn-apply`, `ai-link-apply`, `ai-pair-suggest`, `describe-tags`, `apply-tag-description`, `prune-unused-tags`, `unschedule-cron-event`, `purge-all-caches`, and the four Jev passes (`jev-pass-now`, `jev-collision-check`, `jev-lane-map`, `jev-fit-now`). `describe-tags` is returns-only and sits here because it bills an AI call; the Jev passes are idempotent and sit here because each one spends a request per note.

**The Abilities REST route is a write surface too.** Core registers `POST /wp-json/wp-abilities/v1/abilities/<slug>/run` for every `show_in_rest` ability — the write-door slugs and the pre-consolidation apply abilities included. Since v13.110.0 an application-password request there meets the same four controls as the write door (`sn_mcp_rw_guard_run_route`, `inc/mcp/mcp-rw-guard.php`); cookie-authenticated wp-admin buttons use the same route and pass untouched. A Cloudflare WAF rule also refuses `Authorization`-bearing requests to `/wp-abilities/` at the edge, and the `Cloudflare security headers` health check probes for it.

**Before composing block markup, read `sn-site-facts{editorial_conventions}`.** It returns the theme's convention registry: every house form (patterns, block styles, className conventions on core blocks, dynamic blocks, markup idioms) with an exemplar to paste, when to use it, placement, whether its text is anchor-reachable, and whether the corpus has ever chosen it. Hand-rolled markup that ignores a convention is still valid markup and passes every other gate, which is how a correction notice once shipped as a plain `<em>` paragraph; `sn-validate`'s `editorial_convention` check now warns (never blocks) on markup that matches a convention's shape but not its form, naming the id, and `sn-scan{editorial_conventions}` finds the same drift across the corpus with `block_path` and, for a pure class fix, an `sn-apply block_replace` hint whose dry run reads `ledger_impact: coalesces`. The theme owns the data; without it the check says so and the scan refuses, neither reports a clean corpus.

### Public surface

Everything reachable without a credential, so nothing is public by accident. The plugin's REST population and its public routes are pinned by `tests/rest-routes.php`; a new `__return_true` has to be argued onto that list.

| Surface | Who serves it | Why it is public |
| --- | --- | --- |
| `POST /wp-json/signal-noise/v1/webmention` | plugin | W3C receiver; can only ever create an `unverified` row |
| `GET /wp-json/sn-prov/v1/credential/{uid}` | plugin | a verifiable credential exists to be verified by anyone |
| `POST /wp-json/signal-noise/v1/bridge` | plugin | bearer-checked in its handler; not registered unless armed; hidden from the index |
| `GET /wp-json/` route index, `/wp/v2/posts` (metadata only) | plugin-hardened core | discovery stays; anonymous callers get no rendered content, no users, no comments, no `/batch/v1` |
| `/_sn/version`, `/_sn/status`, `/_sn/verify` | `sn-provenance` Worker | build identity, anchoring status, the public verifier's API |
| `/_sn/rights-signals/{version,taxonomy,crawler-list-status}` | `sn-rights-signals` Worker | build identity and the machine-reader taxonomy (see [docs/MACHINE-READERS.md](docs/MACHINE-READERS.md)); `machine-readers` on the same prefix is bearer-gated |
| `/_sn/login-guard/status`, `/_sn/remote-mcp/status` | their Workers | presence booleans and contract version — never secrets |
| `/_sn/px` | `sn-analytics` Worker | the beacon; token-gated, but the token ships in every page, so it is a bot filter, not authentication — rate-limited per IP |
| `/.well-known/tdmrep.json`, `/license.xml`, `/robots.txt`, `/tdm-policy`, `/ns/tdm`, `/webmcp/bridge.js` | `sn-rights-signals` Worker | the rights surface itself |

### Self-updater

GitHub-poll updater wired into WordPress's native update system

## Requirements

- WordPress 7.0+, **tested up to and running on 7.1** (the plugin header's `Tested up to`; juanlentino.com runs 7.1 in production) · PHP 8.3+ (the plugin header's `Requires PHP`; production runs 8.4, CI pins 8.3)
- The **Signal & Noise** theme at v8.2.0+ (the release that moved these modules out of the theme; the plugin shows an admin notice rather than fataling if the theme is older)

## Install

Distributed via GitHub releases. Install/update through **wp-admin → Dashboard → Updates → Update plugin**, powered by the plugin's self-updater (`inc/wp-update-integration.php`).

## License

[GPL-2.0-or-later](LICENSE).

---

## Release log

[CHANGELOG.md](CHANGELOG.md) carries `## [Unreleased]` and the current release
only; everything older is in [docs/changelog/](docs/changelog/). A pull request
does not bump `Version` and does not tag — it closes an issue and adds a bullet
under Unreleased. Cutting a release is a separate, deliberate act:
`tools/cut-release.sh release|fix "headline"` (add `--dry-run` to see what it
would touch). Numbers follow the WordPress shape — `X.Y.0` a release, `X.Y.Z` a
fix, `X` rolling when `Y` would reach 10 — see [docs/VERSIONING.md](docs/VERSIONING.md).

<sub>Built for [juanlentino.com](https://juanlentino.com).</sub>
