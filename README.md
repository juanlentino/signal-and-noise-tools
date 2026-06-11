# Signal & Noise Tools

Companion plugin to the [**Signal & Noise** theme](https://github.com/juanlentino/signal-and-noise) for [juanlentino.com](https://juanlentino.com). It holds the operational tooling that doesn't belong in a presentation theme — SEO, security, analytics, admin surfaces, and AI-assisted editorial helpers — so the theme stays focused on design and the plugin owns behaviour.

Built on WordPress 7.0's Abilities API and AI Client: it both registers the site's capabilities for AI agents and ships in-editor AI helpers (alt text, meta descriptions, excerpts, brand-voice checks) that call the site owner's configured model provider.

<!-- screenshot placeholder — admin UI (Appearance → Signal & Noise) -->
<!-- ![Signal & Noise Tools admin](docs/screenshot.png) -->

## What it does

- **SEO** — meta descriptions, canonical handling, Open Graph, and cache excludes (replaces a third-party SEO plugin)
- **Security** — HTTP security headers, login hardening / custom login slug, WP hardening
- **Analytics** — self-hosted Plausible Stats API client with a stale-while-revalidate cache and grandfathered dashboard widgets
- **Edge cache** — automatic Cloudflare purge on save / theme update
- **Admin UI** — a tabbed settings surface, command palette, cron dashboard, audit log, and deploy/health views (native wp-admin styling)
- **AI-assisted editorial** — alt text, meta description, excerpt, OG title, brand-voice alignment, and content-opportunity suggestions, each an opt-in suggest-and-apply surface
- **Self-updater** — GitHub-poll updater wired into WordPress's native update system

## Cross-package contracts

The plugin coordinates with the theme through WordPress hooks rather than shared code:

| Hook | Direction | Purpose |
| --- | --- | --- |
| `sn_purge_all_caches_result` | Plugin → Theme | Trigger the theme's cache purge, return a count |
| `sn_self_heal_force_run_result` | Plugin → Theme | Trigger the theme's template self-heal |
| `sn_updater_branch` / `sn_updater_force_check` | Plugin → Theme | Read / re-poll the theme updater |

## Requirements

- WordPress 7.0+ · PHP 8.0+
- The **Signal & Noise** theme at v8.2.0+ (the release that moved these modules out of the theme; the plugin shows an admin notice rather than fataling if the theme is older)

## Install

Distributed via GitHub releases. Install/update through **wp-admin → Dashboard → Updates → Update plugin**, powered by the plugin's self-updater (`inc/wp-update-integration.php`).

## License

[GPL-2.0-or-later](LICENSE).

---

<sub>Built for [juanlentino.com](https://juanlentino.com). Full release history in [CHANGELOG.md](CHANGELOG.md).</sub>
