# Signal & Noise Tools

Companion plugin to the [**Signal & Noise** theme](https://github.com/juanlentino/signal-and-noise) for [juanlentino.com](https://juanlentino.com). It holds the operational tooling that doesn't belong in a presentation theme — SEO, security, analytics, admin surfaces, and AI-assisted editorial helpers — so the theme stays focused on design and the plugin owns behaviour.

Built on WordPress 7.0's Abilities API and AI Client: it both registers the site's capabilities for AI agents and ships in-editor AI helpers (alt text, meta descriptions, excerpts, brand-voice checks) that call the site owner's configured model provider.

<!-- screenshot placeholder — admin UI (Appearance → Signal & Noise) -->
<!-- ![Signal & Noise Tools admin](docs/screenshot.png) -->

## What it does

- **SEO** — meta, canonicals, OG cards, sitemaps + IndexNow, a redirect manager with a 404 log
- **Security** — WordPress hardening, a custom login slug, a read-only panel over the edge login guard
- **Analytics** — first-party, cookieless, edge-collected; SQL rollups, a dashboard, AI narration
- **Content health** — an 18-check scan from Measurement → Health or the `run-health-scan` ability
- **Provenance** — every Note Ed25519-signed and Bitcoin-anchored; readers verify without trusting the site
- **Citation graph** — a Webmention receiver that treats every claim as unverified until cron checks it
- **Edge cache** — automatic Cloudflare purge on save / theme update
- **Music / discography** — a daily Muso.AI + Spotify sync the theme's `/music` page reads
- **Admin UI** — eight tabs, the analytics dashboard, command palette, cron, audit log, deploy/health
- **OpenStation** — three native windows, 10 widgets, 22 palette commands, the Copilot seams
- **AI-assisted editorial** — alt text, meta, excerpt, OG title, brand voice; opt-in suggest-and-apply
- **Agent surface** — 96 abilities; an MCP server with a read door (33 tools) and a write door (8)
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

### Content health

an 18-check scan (missing alt text, orphaned media, broken internal + rotted external links, stale posts, time-phrase and color drift, unlinked mentions, link opportunities, edge security-header drift, edge-Worker reachability, analytics integrity, the provenance integrity sweep, the rights-signals drift probe, the public ledger's own CI, ML cousins, publishing cadence, and the rights-signal anchoring gap), run from Measurement → Health or the `run-health-scan` ability (`inc/health-check-*.php` — 22 modules; a check is only live once it carries all four of its registrations)

### Provenance

cryptographic provenance for Notes: every publish/edit is Ed25519-signed, content-hashed, and Bitcoin-anchored via OpenTimestamps, then mirrored to a public [git ledger](https://github.com/juanlentino/signal-and-noise-provenance). Readers get a byline verification chip + expandable record panel (with clickable Bitcoin-block links) and can independently verify any Note on the public `/verify` page — including a word-level diff between any two signed versions — no trust in the site required. Backed by a dedicated `sn-provenance` Cloudflare Worker; the plugin owns the canonical form, chain, and admin panel

### Citation graph

a W3C Webmention receiver at `signal-noise/v1/webmention`, advertised both ways the spec allows (a `<link rel="webmention">` and a `Link:` header on every publicly viewable singular page). A webmention is treated as an **unverified claim**, never a fact: the public endpoint can only ever create an `unverified` row, and adjudication happens later on cron, which re-fetches each claim and sorts it by what can actually be checked. Claims whose link has gone, or that could not be reached, are kept but shown to nobody

### Edge cache

automatic Cloudflare purge on save / theme update

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

A per-user preference picks native or classic-iframe windows for the two that have a classic twin.

Around the windows: 10 desktop widgets (site views, health, uptime, deploy status, cache, cron, quick actions, RSS subscribers, anchors, machine readers) · 22 `SN:` commands in the ⌘K palette · a dock entry with an update-count badge and two desktop icons · an attention badge carrying the plugin's real queues · an S&N Analytics card on Station Home (structured data, no plugin markup) · drop-to-draft on the shell's OS-file-drop pipeline · a repaired PWA manifest icon set · fixes to the shell's own Plugins window · a nav-id migration so dock placement survived the move from menus to apps.

The AI Copilot gets its tool schemas repaired at the boundary, a prune list that keeps the tool budget paid, a system-prompt appendix teaching it the analytics vocabulary, and generation-budget shaping for ceiling-bounded reasoning (upstream #517).

Every seam is pinned against a named upstream tag by `tests/openstation-compat.php`; `docs/openstation-compat.md` is the audit trail.

### AI-assisted editorial

alt text, meta description, excerpt, OG title, brand-voice alignment, and content-opportunity suggestions, each an opt-in suggest-and-apply surface

### Agent surface

96 plugin-registered Abilities (alongside the theme's 15) reachable via `wp ability run` and the Abilities REST route, plus a native MCP JSON-RPC server with two curated doors: a read-only door at `signal-noise/v1/mcp` (33 slugs) and a read-write door at `signal-noise/v1/mcp-rw` (8 slugs) gated by a kill switch, a bound application password, a per-minute rate limit, and its own audit log. The write door is deliberately small: `sn-apply` is one tool covering every mutation, behind four gates (fingerprint, validation, capability, idempotency) with `dry_run` defaulting to true. **Both door sizes are pinned by `tests/mcp-capabilities.php`** — that suite, not this paragraph, is where the number is true.

The write door, by name: `sn-apply`, `ai-link-apply`, `ai-pair-suggest`, `describe-tags`, `apply-tag-description`, `prune-unused-tags`, `unschedule-cron-event`, `purge-all-caches`. `describe-tags` is returns-only and sits here because it bills an AI call.

**The Abilities REST route is a write surface too.** Core registers `POST /wp-json/wp-abilities/v1/abilities/<slug>/run` for every `show_in_rest` ability — the write-door slugs and the pre-consolidation apply abilities included. Since v13.110.0 an application-password request there meets the same four controls as the write door (`sn_mcp_rw_guard_run_route`, `inc/mcp/mcp-rw-guard.php`); cookie-authenticated wp-admin buttons use the same route and pass untouched. A Cloudflare WAF rule also refuses `Authorization`-bearing requests to `/wp-abilities/` at the edge, and the `Cloudflare security headers` health check probes for it.

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

- WordPress 7.0+ · PHP 8.3+ (the plugin header's `Requires PHP`; production runs 8.4, CI pins 8.3)
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
`tools/cut-release.sh patch|minor|major "headline"` (add `--dry-run` to see what
it would touch).

<sub>Built for [juanlentino.com](https://juanlentino.com).</sub>
