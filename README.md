# Signal & Noise Tools

Companion plugin to the [**Signal & Noise** theme](https://github.com/juanlentino/signal-and-noise) for [juanlentino.com](https://juanlentino.com). It holds the operational tooling that doesn't belong in a presentation theme — SEO, security, analytics, admin surfaces, and AI-assisted editorial helpers — so the theme stays focused on design and the plugin owns behaviour.

Built on WordPress 7.0's Abilities API and AI Client: it both registers the site's capabilities for AI agents and ships in-editor AI helpers (alt text, meta descriptions, excerpts, brand-voice checks) that call the site owner's configured model provider.

<!-- screenshot placeholder — admin UI (Appearance → Signal & Noise) -->
<!-- ![Signal & Noise Tools admin](docs/screenshot.png) -->

## What it does

- **SEO** — meta descriptions, canonical handling, Open Graph cards, sitemaps + IndexNow, a redirect manager with a 404 capture log, and cache excludes (replaces a third-party SEO plugin)
- **Security** — WordPress hardening (Permissions-Policy, REST user-enumeration lock, XML-RPC off), a custom login slug, and a read-only login-defense panel over the edge login-guard Worker's decisions; the five headers Cloudflare emits at the edge (CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`) are drift-probed by a health check rather than duplicated in PHP
- **Analytics** — first-party, cookieless edge analytics: a Cloudflare Worker collects pageviews into Cloudflare Analytics Engine, server-side SQL rollups aggregate them into durable tables, and a server-rendered dashboard plus two home-dashboard widgets read them, with AI insights and narration derived from the same rollups (the third-party Plausible dependency this replaced is fully retired; only the widget IDs still carry the old name)
- **Content health** — an 18-check scan (missing alt text, orphaned media, broken internal + rotted external links, stale posts, time-phrase and color drift, unlinked mentions, link opportunities, edge security-header drift, edge-Worker reachability, analytics integrity, the provenance integrity sweep, the rights-signals drift probe, the public ledger's own CI, ML cousins, publishing cadence, and the rights-signal anchoring gap), run from Measurement → Health or the `run-health-scan` ability (`inc/health-check-*.php` — 22 modules; a check is only live once it carries all four of its registrations)
- **Provenance** — cryptographic provenance for Notes: every publish/edit is Ed25519-signed, content-hashed, and Bitcoin-anchored via OpenTimestamps, then mirrored to a public [git ledger](https://github.com/juanlentino/signal-and-noise-provenance). Readers get a byline verification chip + expandable record panel (with clickable Bitcoin-block links) and can independently verify any Note on the public `/verify` page — including a word-level diff between any two signed versions — no trust in the site required. Backed by a dedicated `sn-provenance` Cloudflare Worker; the plugin owns the canonical form, chain, and admin panel
- **Citation graph** — a W3C Webmention receiver at `signal-noise/v1/webmention`, advertised both ways the spec allows (a `<link rel="webmention">` and a `Link:` header on every publicly viewable singular page). A webmention is treated as an **unverified claim**, never a fact: the public endpoint can only ever create an `unverified` row, and adjudication happens later on cron, which re-fetches each claim and sorts it by what can actually be checked. Claims whose link has gone, or that could not be reached, are kept but shown to nobody
- **Edge cache** — automatic Cloudflare purge on save / theme update
- **Music / discography** — a daily sync mirrors Muso.AI verified producer credits + Spotify album media into a cached store, exposed to the theme's `/music` page (role-filtered discography grid + featured player) via filters
- **Admin UI** — eight intent-coherent tabs (Dashboard, Site, Content, Connections, Measurement, AI, Security, Tools) plus the analytics dashboard, command palette, cron dashboard, audit log, and deploy/health views (native wp-admin styling)
- **AI-assisted editorial** — alt text, meta description, excerpt, OG title, brand-voice alignment, and content-opportunity suggestions, each an opt-in suggest-and-apply surface
- **Agent surface** — 96 plugin-registered Abilities (alongside the theme's 15) reachable via `wp ability run` and the Abilities REST route, plus a native MCP JSON-RPC server with two curated doors: a read-only door at `signal-noise/v1/mcp` (33 slugs) and a read-write door at `signal-noise/v1/mcp-rw` (8 slugs) gated by a kill switch, a bound application password, a per-minute rate limit, and its own audit log. The write door is deliberately small: `sn-apply` is one tool covering every mutation, behind four gates (fingerprint, validation, capability, idempotency) with `dry_run` defaulting to true. **Both door sizes are pinned by `tests/mcp-capabilities.php`** — that suite, not this paragraph, is where the number is true
- **Self-updater** — GitHub-poll updater wired into WordPress's native update system

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
