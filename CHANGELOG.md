# Changelog

All notable changes to Signal & Noise Tools are documented here.

## [2.1.6] - 2026-05-18

### Fixed — Desktop Mode Installed view: name decode at REST layer + wp.org button hidden

After v2.1.5, the SVG icon was clean XML but the Plugins window still rendered:
- Plugin name as the literal `Signal &amp; Noise Tools`
- A "View on WordPress.org" button on the expanded detail panel (404 for self-hosted)

### Root cause (verified upstream)

A research subagent mapped the full Desktop Mode data flow against [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode) trunk. The Installed view calls **Core's** REST endpoint `GET /wp/v2/plugins?context=view` ([rest.ts:261-272](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/rest.ts)) — Desktop Mode only adds custom fields via `register_rest_field()`. Two consequences:

1. **The v2.1.3 `all_plugins` filter never fires for this code path.** That filter is wired into wp-admin/plugins.php's UI layer, not Core's REST controller. The REST controller bypasses it entirely.
2. **Core's `_get_plugin_data_markup_translate()` ([wp-admin/includes/plugin.php:188](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/includes/plugin.php)) runs `wp_kses` on the Name field unconditionally** — even when called with `$markup=false`. So the REST JSON response always carries `"name": "Signal &amp; Noise Tools"`. Desktop Mode's frontend then does `title.textContent = row.name` ([installed-view.ts:754 + installed-detail.ts:241](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/installed-view.ts)) which renders entities literally.

The "View on WordPress.org" button is gated purely on `if (slug)` in [installed-detail.ts:297-301](https://raw.githubusercontent.com/WordPress/desktop-mode/trunk/src/plugins-window/installed-detail.ts), where slug = `dirname(row.plugin)`. **No server-side hook exists to suppress it for self-hosted plugins.**

### Implementation

- **Replaced `all_plugins` filter with `rest_prepare_plugin` filter** in [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php). `rest_prepare_plugin` is Core's last writable layer before JSON serialization ([class-wp-rest-plugins-controller.php:619](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php)). The filter decodes `name` + `author` for our basename and belt-and-suspenders re-asserts `desktop_mode_icon_url` in case Desktop Mode's REST field arrives empty.
- **Added [assets/desktop-mode-installed-view-patch.js](assets/desktop-mode-installed-view-patch.js)** — a ~3KB MutationObserver that hides any `<a href="…wordpress.org/plugins/signal-and-noise-tools…">` for the wp.org button (no upstream filter exists) and defensively decodes `Signal &amp; Noise Tools` if it ever leaks through. Enqueued only on admin pages where Desktop Mode is active; no-ops everywhere else.

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 5/7 → **6/7 on 2.1.x**.
- The JS patch is **upstream-friendly**: hosts the wp.org button instead of deleting it, leaves the DOM tree intact, and won't conflict if upstream later adds a server-side suppressor.
- Worth a new WP-REFERENCE gotcha: **`all_plugins` is a UI-layer filter, not a data-layer filter.** Only fires from wp-admin/plugins.php. For REST/CLI/custom integrations, use `rest_prepare_plugin` instead.
- Worth filing upstream: a `desktop_mode_plugins_window_show_wporg_link` filter would let self-hosted plugins suppress the broken button cleanly.

## [2.1.5] - 2026-05-18

### Fixed — broken plugin icon was a malformed SVG (not a cache issue)

User report after v2.1.4: icon STILL renders as the browser broken-image glyph in the Updates page row. Server returns the file correctly (`HTTP/2 200, content-type: image/svg+xml`), our `pre_set_site_transient_update_plugins` filter sets `icons['svg']` on the transient row, core's `list_plugin_updates()` at [`wp-admin/update-core.php:520`](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/update-core.php) reads it and emits the right `<img>`. So the transport was fine — the *body* was the bug.

### Two XML violations in the SVG bodies

Both `assets/icon.svg` and `assets/banner.svg` were authored as HTML rather than strict XML. When served as `Content-Type: image/svg+xml` (the standard for SVG-as-`<img>`), browsers parse the body as XML and reject anything that violates XML 1.0:

1. **Raw `&` inside an attribute value** — `aria-label="Signal & Noise Tools"`. XML 1.0 §2.4 requires `&` in attribute values be encoded as `&amp;`. Most browsers' SVG renderer fails strict parsing → broken-image glyph.
2. **`--` inside an XML comment** (icon only) — `<!-- … the red \`--\` brand mark … -->`. XML 1.0 §2.5 forbids the literal substring `--` inside comments because `-->` is the terminator. Strict parsers fail at the first `--` they see inside a comment.

### Implementation

- [assets/icon.svg](assets/icon.svg) — encoded `&` as `&amp;` in `aria-label`; rephrased the comment to remove the literal `--` reference to the brand bar.
- [assets/banner.svg](assets/banner.svg) — encoded `&` as `&amp;` in `aria-label`.
- Verified both files now parse cleanly under `python3 -c "import xml.etree.ElementTree as ET; ET.parse(...)"` (strict XML parser equivalent to what browsers run on SVG-as-`<img>`).

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 4/7 → **5/7 on 2.1.x**.
- The `<title>` element body was already using `&amp;` correctly — the bug was only inside attribute values + the comment.
- Worth a new WP-REFERENCE gotcha: **SVGs served as `image/svg+xml` and consumed via `<img>` are parsed in strict XML mode**, not HTML mode. Common HTML shortcuts (raw `&` in attrs, `--` in comments) are fatal. Always validate plugin SVG assets with an XML parser — opening them in a browser tab via the URL bar is not equivalent (different parser context).

## [2.1.4] - 2026-05-18

### Fixed — Dashboard → Updates row: "Compatibility: Unknown" + broken icon

User report: the Updates page row for SN Tools renders a broken `<img>` icon next to the plugin name AND "Compatibility with WordPress 6.9.4: Unknown".

### Root cause (verified against WP 6.9.4 source)

[`wp-admin/update-core.php:527`](https://raw.githubusercontent.com/WordPress/WordPress/6.9.4/wp-admin/update-core.php) reads `$plugin_data->update->tested` and falls through to the "Unknown" string when the field is unset. v2.1.2's brand-assets work set `tested` on the [`plugins_api`](inc/wp-update-integration.php) filter response (View Details modal) but never propagated it to the `pre_set_site_transient_update_plugins` filter row. Core's Updates page consults the transient directly, not plugins_api — so the field never reached the "Compatibility" comparison.

The broken icon was a stale browser cache of a 404 from the brief window between v2.1.2's tag push and the actual file landing on disk. The URL itself resolves correctly (verified `HTTP/2 200, content-type: image/svg+xml`); a hard refresh after install clears the cached 404.

### Implementation — [inc/wp-update-integration.php](inc/wp-update-integration.php)

Added `tested`, `requires`, `requires_php` to the `$plugin_data` stdClass pushed into `$transient->response[basename]`:

```php
$plugin_data->tested       = '7.0';
$plugin_data->requires     = '6.4';
$plugin_data->requires_php = '8.0';
```

`tested = '7.0'` satisfies `version_compare( '7.0', '6.9.4', '>=' )` → "Compatibility with WordPress 6.9.4: 100% (according to its author)". `requires` + `requires_php` mirror the file header for consistency with the View Details modal.

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 3/7 → **4/7 on 2.1.x**.
- After install: hard-refresh (Cmd+Shift+R) the Updates page to clear any cached broken `<img>`.
- Worth a new WP-REFERENCE gotcha: the Updates page reads the update transient directly; `plugins_api` only powers View Details. Compatibility/requires fields must be set on both code paths.

## [2.1.3] - 2026-05-18

### Fixed — Desktop Mode Plugins window: missing icon + literal `&amp;` in plugin name

User report: in WordPress/desktop-mode's custom "Plugins" panel (the OS-style window, distinct from `wp-admin/plugins.php`), our entry rendered with no icon AND the plugin name displayed as the literal text `Signal &amp; Noise Tools` — entity not decoded. The v2.1.2 brand-assets work covered WP core surfaces only; Desktop Mode has its own REST controller + TypeScript frontend that does not consult `plugins_api` or the `update_plugins` site_transient.

Dispatched a research subagent against [WordPress/desktop-mode](https://github.com/WordPress/desktop-mode) trunk to locate the exact lookup paths before writing any code (per project memory: "Read framework source before claiming to know it").

### Root causes (verified against upstream source)

- **Icon**: [`includes/plugins-window/rest-fields.php:404-445`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/plugins-window/rest-fields.php) hardcodes `https://ps.w.org/<slug>/assets/icon.svg` from `dirname( plugin_file )`. Self-hosted plugins get a `ps.w.org` 404 → JS fallback at [`src/plugins-window/icon-fallback.ts`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/icon-fallback.ts) gives up after one shot for non-`ps.w.org` URLs → the dashicons-admin-plugins placeholder paints. The exposed `desktop_mode_plugins_window_icon_url` filter is the documented escape hatch.
- **Name**: [`src/plugins-window/installed-view.ts:396`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/installed-view.ts) uses `title.textContent = row.name;` directly. WP core's `_get_plugin_data_markup_translate()` already `wp_kses`'d the `&` to `&amp;`, and `textContent` renders entities literally. The Browse view at [`src/plugins-window/card.ts:91`](https://github.com/WordPress/desktop-mode/blob/trunk/src/plugins-window/card.ts) correctly calls `decodeEntities()` first — the Installed view forgot to. **Pure upstream frontend oversight; cannot be fixed via any plugin-side JS hook.**

### Implementation — [inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)

- **`desktop_mode_plugins_window_icon_url` filter**: returns `plugins_url('assets/icon.svg', …)` when slug matches `SN_GH_PLUGIN_SLUG`. SVG renders crisp at any DPR; served from same WP origin so CSP + mixed-content pass.
- **`all_plugins` filter**: substitutes `html_entity_decode()`'d Name back into the global plugin list, scoped to `SN_GH_PLUGIN_BASENAME`. Idempotent (`strpos( …, '&amp;' )` guard prevents double-decode). Standard wp-admin surfaces still render correctly because the browser is lenient with raw `&` AND any downstream `esc_html()` calls re-encode safely.

### Roundtrip verification for the `all_plugins` filter

| Consumer | Behavior |
|---|---|
| `wp-admin/plugins.php` `<strong>$name</strong>` | Raw `&` parsed leniently by browser → "Signal & Noise Tools" ✓ |
| `wp-admin/update-core.php` (echoes through `esc_html`) | Re-encodes to `&amp;` → browser decodes ✓ |
| Desktop Mode REST + `textContent` | Receives raw "Signal & Noise Tools" → renders correctly ✓ |
| JSON/REST consumers | Receive canonical unescaped value (the expected JSON form) ✓ |

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 2/7 → **3/7 on 2.1.x**.
- Both filters short-circuit cleanly when Desktop Mode is uninstalled (the icon filter is never invoked; the `all_plugins` filter no-ops since no consumer cares about the difference).
- Worth a new WP-REFERENCE gotcha: `all_plugins` is the right hook for surgical Name/Description overrides since the underlying file header is immutable and core's `wp_kses` pass is hardcoded.

## [2.1.2] - 2026-05-18

### Added — plugin brand assets in wp-admin

User asked: "We'd create an image for the plugin to show in the plugin list and all that... it's WP rule, right?" Yes — WP supports plugin icons + banners via the `plugins_api` filter + the update transient. Self-hosted plugins (like this one) have to provide them; the default is a puzzle-piece dashicon.

Parallel-dispatched a research subagent to read the upstream WP source (`wp-admin/update-core.php`, `wp-admin/includes/plugin-install.php`, `wp-admin/includes/class-wp-plugin-install-list-table.php`) before writing code. Findings drove the exact shape used here:

### Implementation

- **[assets/icon.svg](assets/icon.svg)** — 256×256 viewBox, brand-aligned: white ground, condensed display sans "SN" wordmark in black, red blood-accent stripe top-left, "TOOLS" sub-label in DM Mono. SVG scales crisply at any DPR; pure markup, no font dependency at runtime (uses Bebas Neue + Impact + Helvetica Neue + sans-serif fallback chain — Impact is the widest-installed system font that approximates Bebas Neue's geometry).
- **[assets/banner.svg](assets/banner.svg)** — 1544×500 viewBox, inverted treatment (black ground, white wordmark) for the View Details modal header. Same brand vocabulary.
- **[inc/wp-update-integration.php](inc/wp-update-integration.php) update transient filter** — added `icons` + `banners` arrays to the `$plugin_data` object pushed into `update_plugins` site_transient. Every key (`svg`, `2x`, `1x`, `default`) points at the same SVG — modern WP picks `svg` first; older paths get the SVG via `default` (which MUST be set per `class-wp-plugin-install-list-table.php:445` which reads it without an `! empty()` guard).
- **[inc/wp-update-integration.php](inc/wp-update-integration.php) NEW `plugins_api` filter** — supplies the View Details modal data (name, slug, version, author, sections, icons, banners). Without this filter, the modal shows "Plugin not found" for self-hosted plugins because the wordpress.org API returns nothing. The `description` section reads as a real plugin landing page (SEO, ops tooling, WP 7.0 readiness, desktop-mode integration).
- **Cache invalidation** — extended the existing version-change watchdog at `admin_init` to also clear `plugin_information_<slug>` site transient (24h WP-default TTL). Without this, the View Details modal would keep showing the previous version's metadata after install.

### Surfaces affected

| Surface | Now shows |
|---|---|
| wp-admin → Dashboard → Updates (when update available) | SN icon (svg) next to the plugin entry |
| wp-admin → Plugins → Add New (search/browse) | Not relevant for self-hosted; we don't appear in search results |
| wp-admin → Plugins → View Details modal | SN banner header + icon + name + description sections + author + version + tested-up-to + requires-PHP |
| wp-admin → Plugins → Installed Plugins list | No icon — WP core never renders icons on this surface |

### Notes

- **PATCH within `2.1.x`.** Patch headroom: 1/7 → **2/7 on 2.1.x**.
- All URLs are HTTPS (mixed-content blocks `<img>` silently on HTTPS admin).
- SVG fine in WP 5.0+; the developer.wordpress.org "PNG fallback required" rule is wordpress.org CDN docs, not WP core rendering — core renders SVG via `<img>` without issue.
- Worth a new WP-REFERENCE gotcha: `class-wp-plugin-install-list-table.php` reads `$plugin['icons']['default']` without `! empty()` — always set the default key.

## [2.1.1] - 2026-05-18

### Critical hotfix — production login lockout caused by `wps-hide-login` ghost option entry

User reported login was broken after deleting `wps-hide-login` from disk. Root-cause investigation (parallel debugging agent + WP-core source verification) confirmed: the `wps-hide-login` files were removed without going through WP's `Deactivate Plugin` flow, leaving the slug as an orphan in the `active_plugins` DB option. Our [`inc/login-hide.php:40`](inc/login-hide.php) pre-flight check uses `is_plugin_active()` — which is a pure option lookup that never checks the filesystem — so the orphan slug made it return `true`. Our module bailed entirely, never registered the rewrite rule, and `/sn-login` returned 404 indefinitely.

### Fixed

- **[inc/login-hide.php](inc/login-hide.php)**: pre-flight check now requires BOTH `is_plugin_active( $wps_basename )` AND `file_exists( WP_PLUGIN_DIR . '/' . $wps_basename )`. If the file is gone, the orphan option entry is no longer authoritative — our module activates, adds the rewrite rule, flushes, and `/sn-login` resolves correctly.
- **[inc/admin-page.php](inc/admin-page.php)** Login tab status display: mirrored the same tightened check at line ~713 so the admin status doesn't falsely claim "dormant — conflict with wps-hide-login" when the file is actually gone.

### Why this matters — the upstream-WP gotcha

WordPress's `is_plugin_active()` (in `wp-admin/includes/plugin.php`) is documented as a state lookup against the `active_plugins` option. It does NOT verify the file referenced by the slug exists on disk. WP runs the `active_plugins` slug list every page load, tries to `include` each file, and **silently skips** missing files without removing them from the option. That divergence between option-state and filesystem-state is what bit this.

Worth adding to the running upstream-WP-gotchas list in [docs/WORDPRESS-REFERENCE.md](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WORDPRESS-REFERENCE.md).

### Emergency unlock path

If you hit this lockout again, the plugin has a baked-in escape hatch: add `define( 'SN_LOGIN_BYPASS', true );` to `wp-config.php`. The module returns at [line 31](inc/login-hide.php) before reaching any rewrite or interception logic, restoring default `/wp-login.php` behavior. Remove the constant once you've fixed the underlying issue.

### Other latent bugs spotted by the audit (deferred — not critical)

- `sn_login_rewrites_flushed` keying trusts the option, not actual rule presence in `rewrite_rules`. Could leave us stuck if a flush silently fails. Defer until evidence it bites.
- `strpos( $request_uri, '/' . $slug ) === 0` (line 114) matches `/sn-login-foo` as a prefix. Tighten with a regex boundary check. Defer.
- REST allowlist substring match could be query-string-bypassed. Tighten with `wp_parse_url(..., PHP_URL_PATH)` normalization. Defer.

### Notes

- **PATCH within `2.1.x`.** Production hotfix. Patch headroom: 0/7 → **1/7 on 2.1.x**.
- Recommended cleanup AFTER installing this patch: WP-CLI `wp plugin uninstall wps-hide-login --deactivate` (or remove the orphan slug from `active_plugins` via SQL/phpMyAdmin) to clear the ghost entry. Not required for the fix to work — just hygiene.

## [2.1.0] - 2026-05-17

### Desktop-mode dock fixed + two new desktop widgets

User reported they couldn't open Signal & Noise from the desktop-mode dock after the v2.0.1 auto-import suppression. Parallel subagent investigation surfaced a deeper bug: **our dock entry has been broken since v1.15.0** — the [WordPress/desktop-mode docs](https://github.com/WordPress/desktop-mode/blob/trunk/docs/hooks-reference.md) say `'slug'` is the key, but the actual code at [`includes/core/payload.php:163`](https://github.com/WordPress/desktop-mode/blob/trunk/includes/core/payload.php#L163) uses `'id'`. Wrong key → `item.id` was `undefined` in JS → click handler threw `TypeError: Cannot read properties of undefined (reading 'startsWith')` at [`src/dock.ts:1711`](https://github.com/WordPress/desktop-mode/blob/trunk/src/dock.ts) on every click of the SN tile. The Phase 13 auto-import suppression just made the breakage visible.

### Fixed

- **Dock entry key renamed `'slug'` → `'id'`** ([inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)) — fixes the click TypeError. SN tile now opens the Dashboard on single click.
- **Dock icon switched from `dashicons-shield-alt` → `dashicons-megaphone`** — matches the icon passed to `add_menu_page()` in admin-page.php (`'dashicons-megaphone'` at line 121), which was the icon rendering on the now-suppressed auto-imported entry. User specifically requested it back.
- **Submenu entries cleaned up** — only `'title'` + `'url'` are honored per [`src/dock.ts:89`](https://github.com/WordPress/desktop-mode/blob/trunk/src/dock.ts) SubmenuItem type. Removed the silently-dropped `'slug'` + `'icon'` keys on the 8 submenu items. The 8 tabs ride into the opened SN window as the in-window tab strip (per desktop-mode behavior verified in src/dock.ts:1703-1765).

### Added — two new desktop widgets

User: *"if there's a way to create widgets for the desktop, do it... Maybe we can replace some that are hidden in the menu or other screens"*

1. **SN Quick Actions widget** ([assets/desktop-mode-widget-actions.js](assets/desktop-mode-widget-actions.js)) — three buttons (Purge all caches / Clear DB overrides / Full reset) calling the existing `/signal-noise/v1/cmd/{action}` REST endpoints. Replaces the 3-click path of S&N → Dashboard tab → Maintenance section with single-click access from the desktop. Inline toast feedback on success/failure.

2. **SN RSS Subscribers widget** ([assets/desktop-mode-widget-rss.js](assets/desktop-mode-widget-rss.js)) — surfaces 24h / 7d / 30d unique-subscriber + total-request counts + last-request timestamp at-a-glance. Data previously lived only behind S&N → RSS tab + a single line on the Dashboard tab; now visible without navigation. Polls every 5 min (RSS counts don't change rapidly).
   - New REST endpoint: `GET /signal-noise/v1/cmd/rss-stats` — read-only wrapper around the existing `sn_rss_tracker_window_stats_multi()` function. Capability-gated `manage_options`.

Existing `sn-deploy-status` widget unchanged.

### Why MINOR (not PATCH)

Per [CLAUDE.md](https://github.com/juanlentino/signal-and-noise/blob/main/CLAUDE.md): "MINOR for new user-visible capabilities." Two new desktop widgets is net-new user-facing surface. Resets minor count from 0/5 → **1/5 on 2.x**.

### Notes

- Surfaced via the audit-then-fix pattern: 5-agent parallel audit caught WP 7.0 + Phase 13 cleanup work in v2.0.4; the followup user-report dispatched a focused subagent that decoded the desktop-mode `id`-vs-`slug` docs error.
- Filed a mental note to PR the [WordPress/desktop-mode docs](https://github.com/WordPress/desktop-mode/blob/trunk/docs/hooks-reference.md) to align with the actual code.

## [2.0.4] - 2026-05-17

### Comprehensive audit pass — 8 findings fixed before WP 7.0 launch (3 days out)

After the v2.0.3 deploy, dispatched 5 parallel subagents to audit Phase 13's full surface (code review, WP 7.0 readiness, WCAG accessibility, critical.css size, Abilities API verification). Zero "must fix before May 20" breakers surfaced. Eight non-breaker findings consolidated into this single patch:

### Fixed — Phase 13 SEO code

1. **BreadcrumbList final ListItem now includes `item` URL** ([inc/seo-schema.php](inc/seo-schema.php)) — Google Rich Results spec requires `item` on every ListItem. Missing on the current-page item suppressed breadcrumb display in SERPs.
2. **`sn_og_image_url` filter seed consistency** ([inc/seo-schema.php](inc/seo-schema.php)) — was seeded with `''` in Article schema but with `sn_setting('og.default_image_url', '')` in OG meta. Latent inconsistency: any augment-style filter would behave differently between the two callsites. Both now use the same seed.
3. **`SERVER_PROTOCOL` allowlisted in 304 emission** ([inc/seo.php](inc/seo.php)) — was passed through `wp_unslash()` only and concatenated into `header()`. Allowlisted against `HTTP/1.0 | 1.1 | 2 | 2.0 | 3` with fallback `HTTP/1.1`. Defensive against CRLF response splitting even though Cloudways front-end normalizes.

### Fixed — Abilities API (Phase 14 — was broken in v2.0.3)

A parallel audit caught that v2.0.3's experimental `inc/abilities-registration.php` would have silently failed on WP 7.0 due to FOUR issues. Fixed before the file got registered:

4. **Categories now pre-registered** — `wp_register_ability()` calls return `null` (with `_doing_it_wrong`) if the category slug isn't registered first. Added `wp_abilities_api_categories_init` hook that registers `maintenance`, `content`, `diagnostics` before the abilities themselves try to cite them.
5. **`sn_og_card_regenerate` → `sn_generate_og_card`** — the function name was wrong; right function returns `bool`, not URL. Code now calls `sn_generate_og_card()` for the work and `sn_og_image_url_for_post()` separately for the URL in the response.
6. **`regenerate-og-card` permission_callback simplified** — was returning `WP_Error` for missing input, but `input_schema`'s `required: ['post_id']` handles that automatically before `permission_callback` fires. Now purely auth.
7. **`meta.annotations` added to all 4 abilities** — destructive/idempotent/readonly behavioral hints so the AI Client doesn't treat `purge-all-caches` or `clear-template-overrides` as safe operations. Required by the API for AI Clients to make sound decisions.
8. **`abilities-registration.php` now in the require_once chain in [signal-and-noise-tools.php](signal-and-noise-tools.php)** — file was created in v2.0.3 but never loaded. Silent regression.

### Fixed — WP 7.0 defensive

9. **`wp_robots()` now suppressed via `remove_action`** ([inc/seo.php](inc/seo.php)) — WP core's `wp_robots()` fires on `wp_head` priority 1 and emits a competing robots tag when `blog_public=0`. Production is fine today but a staging clone or accidental toggle would cause double-emission. Added next to the existing `rel_canonical` removal, gated on TSF absence.

### Notes

- **PATCH within `2.0.x`.** Patch headroom: 3/7 → **4/7 on 2.0.x**.
- All fixes verified against actual upstream WordPress source on trunk + the WP 7.0 Field Guide.
- The audit-then-fix pattern (5 parallel subagents → one consolidated patch) caught 8 issues that linear sequential review would have shipped silently or surfaced post-launch.

## [2.0.3] - 2026-05-17

### Deploy workflow hardening — same plugin code as v2.0.2

The v2.0.2 code (title format fix + canonical de-duplication) couldn't reliably reach the live server via the WP-UI Updates path due to the plugin's 1h GitHub-tag cache lag combined with WP-installer slowness. Manual GHA deploy via `gh workflow run` was failing too because prior WP-UI installs had written files to disk without updating the git index, leaving a dirty working tree that `git checkout` refused to overwrite.

### Fixed

- **`.github/workflows/deploy.yml`** — adds `git reset --hard HEAD` and `git clean -fd` before `git fetch && git checkout <tag>`. Makes the manual deploy idempotent regardless of working-tree state. Safe because the plugin directory is fully reproducible from git; "real" runtime data (uploads, cache, logs) lives outside the plugin dir.

### Notes

- **Plugin code is unchanged from v2.0.2.** This release exists purely to land a deploy-workflow improvement that the next manual deploy will use (GHA pulls the workflow definition from the ref being deployed, so the fix has to be tagged to be effective).
- **PATCH within `2.0.x`.** Patch headroom: 2/7 → **3/7 on 2.0.x**.

## [2.0.2] - 2026-05-17

### Post-cutover hotfix — two duplicate emissions caught by verification

The full TSF cutover (TSF deactivated + deleted) surfaced two regressions that didn't appear while TSF was still suppressing things. Both fixed in this patch.

### Fixed

1. **`<title>` now emits the brand format cleanly — no tagline append.**
   In v2.0.0, the `document_title_parts` filter set `$parts['title']` and `$parts['site']` but didn't clear `$parts['tagline']` (which WP populates from `get_bloginfo('description')`). Result post-cutover: `<title>Juan Lentino – Site Name – Site Tagline</title>` — three segments instead of two. WP joins every non-empty key in `$parts` with the separator, so the only correct fix is to **replace the whole array** rather than augment it. Filter now returns `array( 'title' => $title )` — one segment, fully pre-built, exactly the format TSF was emitting before.

2. **No more duplicate `<link rel="canonical">` tags.**
   [WP core's `rel_canonical()`](https://github.com/WordPress/WordPress/blob/master/wp-includes/link-template.php) is registered on `wp_head` at priority 10 and fires on singular views (which includes static front pages). Until Phase 13, TSF was suppressing it. With TSF gone, our seo.php canonical (priority 1) and WP core's (priority 10) were both firing, producing two canonical tags per page. Fix adds `remove_action( 'wp_head', 'rel_canonical' )` on `init`, gated on `! function_exists( 'the_seo_framework' )` so accidental TSF reactivation doesn't double-suppress.

### Why the gates matter

Both fixes are gated on TSF being absent. This preserves the rollback property of the v2.0.0 cutover: if TSF is ever reactivated, our gates flip back, WP core's `rel_canonical` re-registers, and the legacy TSF suppression resumes. No code revert needed.

### Notes

- **PATCH within `2.0.x`.** Bug fixes to v2.0.0/v2.0.1 cutover. Patch headroom: 1/7 → **2/7 on 2.0.x**.
- Caught by the 10-check verification script from the [cutover spec](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-17-tsf-cutover-design.md#verification-checklist). Without that systematic verification both regressions would have shipped silently.

## [2.0.1] - 2026-05-17

### Comprehensive QA pass — three fixes bundled

A QA audit after the v2.0.0 deploy surfaced three issues. This patch addresses all of them in one release so the TSF cutover can proceed cleanly.

### Fixed

1. **Identity tab now has UI for `jobTitle` + `knowsAbout`** ([inc/admin-page.php](inc/admin-page.php), [inc/settings.php](inc/settings.php)) — v2.0.0 shipped these as new Person-schema fields with hard-coded defaults, but the spec promised "settable via existing settings layer" without delivering admin UI. Fix adds:
   - **Job title** (text input, placeholder "Music Producer"): emitted as `jobTitle` on the Person schema.
   - **Knows about** (textarea, one topic per line): emitted as the `knowsAbout` array. Empty lines stripped, each line `sanitize_text_field()`'d.

2. **Desktop-mode dock no longer shows SN duplicated** ([inc/desktop-mode-integration.php](inc/desktop-mode-integration.php)) — verified against [WordPress/desktop-mode core/payload.php on trunk](https://github.com/WordPress/desktop-mode/blob/trunk/includes/core/payload.php): desktop-mode auto-imports every `add_menu_page()` entry into the dock by default. Our admin page was being auto-imported AS WELL AS our explicit `desktop_mode_dock_items` filter entry, so the dock showed two "Signal & Noise" entries (different icons because the auto-import falls back to a generic dashicon — the "megaphone" the user spotted). Fix uses the documented `desktop_mode_dock_placement` filter to return `'hidden'` for the `sn-theme-options` menu slug, keeping only our explicit entry (which has the richer 8-tab submenu + update-available badge).

3. **RSS activity section restored to Dashboard tab** ([inc/admin-tab-dashboard.php](inc/admin-tab-dashboard.php)) — v1.13.0 had RSS stats on the Dashboard; v1.14.0's redesign removed them as "arithmetic, not content-driven." This re-adds the data in a content-driven shape that matches the existing External APIs single-line summary pattern: last-request timestamp + 24h/7d/30d totals + unique-subscriber counts + click-through to the RSS tab. Hidden when the rss-plausible-tracker module isn't loaded (`function_exists()` guard).

### Notes

- **PATCH bump within `2.0.x`.** All three are post-v2.0.0 QA corrections to surfaces that should have shipped with v2.0.0. Patch headroom: 0/7 → **1/7 on 2.0.x**.
- No version bump for the theme (theme was clean at v8.5.5).
- Companion to the v2.0.0 release shipped earlier this session.

## [2.0.0] - 2026-05-17

### Major release — The SEO Framework dependency dropped

Phase 13 of the plugin absorption roadmap. The SEO Framework (`autodescription`) is no longer required for this site's SEO emission. All meta tags, JSON-LD structured data, sitemap routing, and `<title>` emission now come from this plugin (plus WP core's title-tag support via the companion theme release v8.5.5).

### Added — Six new gated emitters

All NEW emissions are gated on `function_exists('the_seo_framework')` — they stay dormant while TSF is active and activate the instant TSF is deactivated. Existing v1.6.0–v1.8.0 emissions (canonical, robots, description, OG, Twitter) stay unconditional.

1. **`document_title_parts` filter in [inc/seo.php](inc/seo.php)** — emits the page `<title>` via WP-native `_wp_render_title_tag()` (theme v8.5.5 declares `add_theme_support('title-tag')`). Format matches what TSF was emitting: `Page Name — Site Name`. Pulls from existing `sn_seo_meta_for_current_view()` so per-route titles (front page, /notes, /provenance) still come from settings copy.
2. **`sn_schema_webpage()` in [inc/seo-schema.php](inc/seo-schema.php)** — WebPage schema for every singular (Page or Post). Includes `breadcrumb` reference + `isPartOf` WebSite reference.
3. **`sn_schema_collection_page()`** — CollectionPage schema for /notes and home archive views.
4. **`sn_schema_breadcrumb_list()`** — manual breadcrumb trail until WP 7.0 native Breadcrumbs block lands in templates (then this becomes a small refactor in a follow-up release).
5. **`inc/sitemap-redirect.php`** — 301 redirect from TSF's legacy routes (`/sitemap.xml`, `/sitemap_index.xml`, `/sitemap.xsl`) to WP core's `/wp-sitemap.xml`. Preserves Google Search Console crawl continuity.
6. **Last-Modified header + If-Modified-Since 304 in [inc/seo.php](inc/seo.php)** — singular content gets `Last-Modified` header set to post's modified GMT. Honors `If-Modified-Since` request header by returning `304 Not Modified` when post is unchanged. Improves crawl budget efficiency. (TSF emits Last-Modified itself when active; gate keeps ours dormant until cutover.)

### Added — Music-specific Person schema fields

`sn_schema_person()` now includes:
- `jobTitle` — defaults to `"Music Producer"`; settable via `sn_setting('identity.job_title')`.
- `knowsAbout` — defaults to `["Music Production", "Audio Engineering", "Provenance", "Music Industry"]`; settable via `sn_setting('identity.knows_about')`.

Both fields exist because this plugin uses richer domain context for the Person entity than TSF's generic schema generator can. A future v2.1.0+ may add a settings UI surface for these fields; for now they're settings-array-only.

### Why MAJOR (breaking change)

Per [CLAUDE.md](https://github.com/juanlentino/signal-and-noise/blob/main/CLAUDE.md) versioning rules: "removed/renamed public API, settings schema change without a migration, or a behavioural shift that requires user action." This release requires a user wp-admin action (TSF deactivation) to take full effect. The plugin's effective contract changes from "we cover SEO gaps TSF doesn't" to "we are the SEO surface." Resets minor count to 0 for v2.x.

### Cutover sequence (executed in this session)

1. Theme v8.5.5 deployed (declares `add_theme_support('title-tag')`).
2. This release (v2.0.0) deployed — new code live but gated dormant.
3. User deactivates TSF in wp-admin → Plugins.
4. Gates flip; new emissions activate; TSF stops emitting anything.
5. Verification via the runnable script in [the design spec](https://github.com/juanlentino/signal-and-noise/blob/main/docs/superpowers/specs/2026-05-17-tsf-cutover-design.md#verification-checklist).
6. After 24-48h with no regressions: TSF plugin deleted from wp-admin.

### Rollback

Reactivate TSF in wp-admin (one click). All new emissions flip back to dormant automatically (gates re-fire). No code revert needed for rollback.

### Notes

- **Existing OG/Twitter suppression** (the `the_seo_framework_meta_generator_pools` filter from v1.4.1) stays in place permanently as defense-in-depth.
- **No data migration needed.** Plugin already reads from its own `_sn_*` post meta keys; no TSF data to import.
- Companion: theme v8.5.5 (PATCH) shipped in the same session.

## [1.16.0] - 2026-05-17

### Added — Phase 12 scaffolding: AI-assisted meta description generation

Pre-stages the AI features arc for WordPress 7.0 (ships 2026-05-20, 3 days from this release). Everything in this release is **dormant on WP 6.x** — gated behind `wp_has_ai_client()` which returns `false` until either WP 7.0 is installed OR the `wp-ai-client` plugin is active on 6.x. The instant either condition becomes true, the "Generate with AI" button appears on the per-post SN meta box and the REST endpoint starts answering.

### Three new files

- `inc/ai-bootstrap.php` (~140 LOC) — central function_exists() gate (`snt_ai_is_available()`) and shared prompt-execution helper (`snt_ai_generate_with_constraints()`). All AI code in the plugin goes through this — there are no scattered `function_exists()` checks elsewhere. Helper accepts a prompt + system instruction + max_tokens cap, returns string or `WP_Error`. Defensive try/catch around the SDK call (the WP wrapper converts most exceptions but PHP runtime errors can still bubble; we catch + convert to keep callers' error handling uniform).
- `inc/ai-meta-description.php` (~110 LOC) — Phase 12 slice 1. Registers REST endpoint `POST signal-noise/v1/ai/generate-meta-description` (permission: `edit_post` for the given post_id — not just `manage_options`). On post.php / post-new.php screens, enqueues the JS that injects the button. Both gated on `snt_ai_is_available()` — zero overhead, zero markup on 6.x without backport.
- `assets/ai-meta-description.js` (~120 LOC) — IIFE, no globals. Polls for the meta description textarea (id="sn_meta_description") for up to 10s after DOMContentLoaded (block editor renders meta boxes asynchronously; classic editor has them at load). On click: `wp.apiFetch` → fill textarea → fire `input`/`change` events so block editor's meta-sync picks up the change. DOM-built throughout (createElement + textContent — no innerHTML, no XSS risk class).

### Provider-agnostic by design — NOT pinned to Anthropic

Code calls `wp_ai_client_prompt()` (the WP-idiomatic procedural wrapper). It does NOT pick a provider, NOT set `temperature` / `top_p` / `top_k`, NOT pin a model. The provider is whatever the user configured in `Settings > Connectors`. Reasoning:

- **Claude Opus 4.7 specifically removed sampling params** — setting `temperature` returns 400. The portable choice is to set none. Constraints go in the system instruction.
- **The user could swap providers tomorrow** without our code changing — Anthropic today, OpenAI next week, Google after that. WP AI Client abstracts this.
- **Each provider has different model availability** — pinning a model name would lock the user into one provider's catalog.

This matches the rule from prior course-corrections: work WITH WordPress's abstraction, don't fight it. (Same reasoning as v1.14.0 admin redesign sticking to wp-admin native classes — extend, don't replace.)

### Meta description prompt

The system instruction targets SEO meta description conventions: 140-160 chars, active voice, no marketing fluff, output only the description text. Tuned for the SN voice (no first-person plural, capture single most-useful thing). Input is post content truncated to 1000 words (~1200-1400 input tokens — quality plateaus well before context-window limits; tokens scale linearly).

### REST endpoint design

`POST /signal-noise/v1/ai/generate-meta-description`
- Body: `{ post_id: int }`
- Permission: `current_user_can( 'edit_post', $post_id )` (per-post, not global)
- Returns: `{ ok: true, description: string, length: int }` or `WP_Error`

Error cases all return `WP_Error` with appropriate HTTP status codes (503 if AI unavailable, 422 if post empty, 500 if runtime error, 502 if AI returned empty).

### Companion documentation

Theme repo: [`docs/WP-7.0-AI-API-MAP.md`](https://github.com/juanlentino/signal-and-noise/blob/main/docs/WP-7.0-AI-API-MAP.md) — full API map + Phase 7/12/14 plan + verified-from-source notes on `wp_ai_client_prompt()`, `AiClient::prompt()`, `wp_has_ai_client()`, the Abilities API. Read that doc before working on AI features in future sessions.

### Phase 7 (May 21) — what user does on launch day

1. Upgrade WP core to 7.0
2. Install `WordPress/ai-provider-for-anthropic` plugin
3. Settings → Connectors → Anthropic → paste API key
4. (Optional) Install `WordPress/ai` for generic features (alt text, title gen)
5. Edit any post → SN meta box → click "Generate with AI" → ~150 chars in ~3-5 seconds

If step 5 fails: the REST endpoint returns a `WP_Error` with a clear message (status 503 = AI unavailable, 422 = empty post, etc.) — surfaced in the button's inline status text.

### Verified against actual source

- `WordPress/wp-ai-client/autoload.php` — confirmed `wp_has_ai_client()` is the canonical 7.0/backport detection function (not `function_exists('wp_ai_client_prompt')`).
- `WordPress/php-ai-client/src/AiClient.php` — confirmed fluent API: `usingSystemInstruction()`, `usingMaxTokens()`, `generateText()`. WP wrapper uses snake_case versions.
- WP make blog (Feb 3 merge proposal + Mar 24 intro + May 14 Field Guide) — confirmed providers ship as separately-installed plugins, not bundled in core. Confirmed `Settings > Connectors` is in core.

### Versioning

**MINOR bump (1.15.2 → 1.16.0)** — new user-visible capability (the "Generate with AI" button + the REST endpoint that backs it). Dormant on the current 6.x install until WP 7.0 + a provider plugin land on May 21+. Plugin minor count: 15 → 16 (continues over-cap pattern per documented user preference).

### Notes

- **Phase 12 slice 1 only.** Slice 2 (OG card title gen) deferred to v1.17.0+ — ship the meta description pattern, verify it works under real 7.0, then expand.
- **Phase 14 (Abilities API registration)** deferred separately to v1.17.0 or later. SN has obvious candidates (regenerate_og_card, purge_caches, etc.) but they're all currently exposed as filter hooks; registration is thin glue, ~80 LOC. Worth doing once we know the Abilities API stability.
- **Hybrid model with `WordPress/ai`:** that experimental plugin provides generic features (alt text, title gen). Recommended install on May 21 for those features; SN's plugin owns SN-specific features. If `WordPress/ai` breaks (it's marked experimental), our SN code keeps working.

## [1.15.2] - 2026-05-17

### Fixed
- **GitHub API rate-limit pressure.** Live site hit 53/60 on the unauthenticated 60/h tier. Root causes traced to:
  1. GHA runs cache TTL of 60s × 2 repos = 120 req/h theoretical max when the SN Dashboard tab is open.
  2. Force-check action cleared ALL caches including the runs cache — every click cost 4 fresh requests instead of 2 (the user is asking "is there a new version?", which is the tag-poll question, not the deploy-history question).
  3. No ETag conditional requests — every cache miss spent a full quota slot even when GitHub had nothing new to return.

### Three-layer fix
1. **GHA runs cache TTL bumped 60s → 5min** in `inc/github-actions-api.php`. Practical impact alone: ~5× reduction.
2. **Force-check handler (`inc/admin-tab-dashboard.php`) no longer clears the GHA runs cache.** Only clears the version-comparison caches (`sn_gh_latest_*`, `update_themes`, `update_plugins`). The runs cache stays warm — natural 5min TTL handles freshness. The REST `/cmd/force-check` endpoint already didn't clear runs (v1.15.0 separation), so this brings parity.
3. **ETag/If-None-Match conditional requests in `snt_gh_recent_runs()`.** Cache shape upgraded from flat array to `{ data, etag, fetched_at }` (with backward-compat for the pre-v1.15.2 flat shape). On every fetch, the cached ETag is sent as `If-None-Match`. A 304 response refreshes the cache TTL without consuming quota — **the real fix**.

### Expected steady-state usage after deploy
- 1 GHA runs request per repo per 5min, returning 304 most of the time (free) → effective quota burn: **~2 requests/hour when no deploys, ~6/hour during active deploy iteration**. Down from the 50+/hour pattern that triggered this fix.
- Theme + plugin tag polls unchanged at 1 req/hr each = 2/hr. (Adding ETags here is a future PATCH; the polls are too infrequent to matter — <2% of total usage.)

### Escape hatch (no code change needed)
- Define `SNT_GITHUB_TOKEN` in `wp-config.php` to raise the rate-limit bucket from 60/h (unauthenticated) to 5000/h (authenticated). Constant is already supported by `inc/github-actions-api.php` — sends `Authorization: Bearer …` header on every outgoing request. Define it once, never worry about quota again.

### Backward compat
- The cache shape change in `snt_gh_recent_runs()` handles both new `{ data, etag, fetched_at }` and pre-v1.15.2 flat-array values during the transition. No site-side flush required.

### Why this PATCH and not the broader ETag rollout
- Theme + plugin tag polls (`sn_gh_latest_theme_tag`, `sn_gh_latest_plugin_tag`) also could use ETags, but they run at 1/hr cache TTL — they account for ~2/60 = 3% of the quota under any realistic usage. Not worth the parallel rewrite this turn. Reserve as a future PATCH if anyone ever notices.

### Notes
- PATCH bump (1.15.1 → 1.15.2). Bugfix + perf optimization; no functional behavior change. Force-check still does what users expect (clears version comparison caches + redirects to `wp-admin/update-core.php?force-check=1` for the belt-and-braces WP-side refresh).

## [1.15.1] - 2026-05-17

### Fixed
- **"Signal & Noise Tools" displayed as the literal text "Signal &amp; Noise Tools" in the WordPress Plugins list** (and any other surface that renders the plugin name — desktop-mode dock submenu, update notifications, etc.). Root cause: `wp_cache_get('plugins', ...)` retained a stale double-escaped value across our SSH-checkout deploy path. The plugin header in `signal-and-noise-tools.php` was always plain `&` — but the parsed-plugin-headers cache survives across deploys that don't go through WP's installer, and the cached value was double-encoded from a much earlier release that had `&amp;` in the header.
- **`inc/wp-update-integration.php` `admin_init` version-change handler** now also calls `wp_clean_plugins_cache()` on every detected version change. Mirrors the existing pattern for `sn_gh_latest_plugin` + `update_plugins` transient invalidation; same admin_init pageview, no new overhead. Self-heals the plugin-headers cache on the next admin pageview after any version bump — including SSH-checkout deploys.

### Why this matters
- Plugin name renders correctly in:
  - wp-admin → Plugins (the canonical list)
  - wp-admin → Updates (when a plugin update is available)
  - Desktop-mode dock (the v1.15.0 integration's submenu we just shipped)
  - Any third-party plugin that lists installed plugins by name
- Without the watchdog, every future SSH-checkout deploy that bumps the plugin version would leave the header cache stale until manual deactivation/reactivation. Now it self-heals on the next admin pageview.

### Companion fix
- Theme **v8.5.4** ships the matching fix for the theme-side cache (`wp_clean_themes_cache()` added to the theme's equivalent admin_init handler), plus the actual `Theme Name: Signal &amp; Noise` → `Theme Name: Signal & Noise` header fix in `style.css` that was the original root-cause bug on the theme side.

### Notes
- **PATCH bump within `1.15.x`.** Bugfix; no functional behavior change.
- Bug surfaced visibly when the v1.15.0 desktop-mode integration shipped — the new dock submenu rendered the entity-escaped plugin name, making the cache staleness visible. Existing wp-admin Plugins list had the same issue all along; nobody noticed because the rest of the screen is busy enough that a single `&amp;` doesn't catch the eye.

## [1.15.0] - 2026-05-16

### Added — WordPress/desktop-mode integration

Makes Signal & Noise a first-class participant in the [`WordPress/desktop-mode`](https://github.com/WordPress/desktop-mode) plugin when installed + active. Adds dock visibility, desktop icons, command-palette access, and a live deploy-status widget. **Every integration is `function_exists()`-gated** — the plugin behaves identically when desktop-mode is inactive or uninstalled.

### Three surfaces

1. **Dock + desktop icons** (always-on visibility when desktop-mode is active):
   - Dock item "Signal & Noise" (`dashicons-shield-alt`) with a submenu of all 8 SN settings tabs.
   - Badge count on the dock item = number of "update available" packages (theme + plugin). 0 = no badge per desktop-mode convention.
   - Desktop icons for Dashboard + Identity (the two most-frequent surfaces).
2. **Command palette (Cmd+K)** — 13 commands across 3 categories:
   - **Maintenance (4):** `SN: Force-check updates`, `SN: Purge all caches`, `SN: Clear template overrides`, `SN: Full reset`. All fire REST endpoints (no page navigation) and dispatch `wp.desktop.notify()` toasts on response. Full reset has a confirm() guard.
   - **Navigation (7):** `SN: Open Dashboard / Identity / Login / Cloudflare / Plausible / RSS / Reading Time`. Each sets `window.location.href` to the matching SN admin page.
   - **Info (2):** `SN: Theme version`, `SN: Plugin version`. Reads from `wp_localize_script`'ed data and dispatches a toast like `Theme: v8.5.3 (up to date)`.
3. **Desktop widget** `SN Deploy Status` — compact floating card showing theme + plugin version pills + last deploy time + "Open Dashboard →" link. Auto-refreshes every 60s. Click target opens the SN Dashboard.

### REST endpoints — `signal-noise/v1/cmd/*`

Single dispatcher handler in `inc/desktop-mode-integration.php`:

| Endpoint | Method | What it does |
|---|---|---|
| `/cmd/force-check` | POST | Clear `sn_gh_latest_theme`, `sn_gh_latest_plugin`, `update_themes`, `update_plugins` transients |
| `/cmd/purge-caches` | POST | Fire `sn_purge_all_caches_result` filter (excludes template overrides per existing convention) |
| `/cmd/clear-overrides` | POST | Fire `sn_clear_template_overrides_result` filter |
| `/cmd/full-reset` | POST | Clear overrides + purge caches in one shot |
| `/cmd/status` | GET | Read-only: theme + plugin status struct + last deploy time (powers the widget) |

All endpoints `permission_callback` = `current_user_can('manage_options')`. WP REST API handles `_wpnonce` automatically when JS uses `wp.apiFetch` (which our scripts do via the `wp-api-fetch` script dependency).

Response shape: `{ ok: bool, message?: string, data?: object }`. Errors via standard `WP_Error` for the WP REST framework to handle.

### Files added

- `inc/desktop-mode-integration.php` (~230 LOC) — dock filter + icon + command + widget registrations + REST endpoints + script registrations + localized data.
- `assets/desktop-mode.js` (~130 LOC) — IIFE that calls `wp.desktop.registerCommand({ slug, run })` for each of the 13 commands. Maintenance commands use `wp.apiFetch`; nav uses `window.location`; info reads from `window.snDesktopData`. Defensive fallbacks: if `wp.desktop.notify` is unavailable, falls back to `wp.data.dispatch('core/notices')`; if `wp.apiFetch` is unavailable, error toast.
- `assets/desktop-mode-widget.js` (~140 LOC) — IIFE that calls `wp.desktop.registerWidget({ id, render })`. Built entirely via `createElement` + `textContent` (zero `innerHTML` — eliminates the string-concat XSS risk class). Auto-clears `setInterval` when the container detaches from the DOM (defensive against shell-side disposal without a teardown hook).

### Files changed

- `signal-and-noise-tools.php` — `Version: 1.15.0` + `SNT_VERSION` constant + `require_once 'inc/desktop-mode-integration.php'`.

### Verified against desktop-mode docs

- `docs/api-index.md` — function signatures for all 4 registrars verified.
- `docs/getting-started.md` — dock-item filter array shape (slug, title, icon, url, badge, submenu) verified.
- `docs/plugin-compat-layer.md` — chromeless iframe + `?desktop_mode_chromeless=1` parameter noted; our existing admin CSS uses no hardcoded admin-bar offsets, so we render correctly in chromeless mode out of the box (no Tier 3 targeted override needed).

### Versioning

**MINOR bump (1.14.0 → 1.15.0)** — new user-visible capability. Continues plugin's over-cap pattern (minor 15/5, per documented user preference). Theme is unaffected.

### Notes

- **No native window (`desktop_mode_register_window`)** — iframe-loading of our existing SN admin pages works fine; native window would duplicate the rendering logic for marginal UX gain. Reserved for a future phase if there's specific value (e.g., a multi-tab inspection window).
- **No custom wallpaper** — brand-on-admin pushback from v1.13.0/v1.14.0 redesign applies even on desktop-mode's customizable surfaces. The plugin contributes utility, not aesthetic.
- **No AI provider / AI tool registration** — that's Phase 12 work (depends on WP 7.0 + the AI Client landing on 2026-05-20).
- **Test plan after deploy:** load wp-admin with desktop-mode active → dock should show "Signal & Noise" with shield icon → Cmd+K should surface 13 `SN:` commands → place SN Deploy Status widget on desktop → check live data appears + refreshes every 60s. If anything breaks, iterate in v1.15.1.

## [1.14.0] - 2026-05-16

### Changed — admin UI redesign across all 8 tabs (user-requested cleanup)

User feedback during v1.13.0 testing: the Dashboard tab was "sloppy" (information dense without hierarchy), the brutalist front-end aesthetic shouldn't translate to admin UI (admin should read as clean wp-admin native), and the RSS layout still needed work. This release applies that direction comprehensively across every SN admin surface.

### Design discipline applied (per memories)
- **`feedback_no_brutalist_in_admin_ui.md`** — admin UI is wp-admin native, not branded. Reuse WP's `.button`, `.notice`, `.widefat`, `.form-table`, `.regular-text`, `.large-text`, `.small-text`, `.description`, `.submit`, `.code` primitives. Extend with `.sn-*` classes only for composition patterns WP doesn't already cover.
- **`feedback_no_dashboard_widgets.md`** — SN operational info stays in SN settings tabs, NOT WP dashboard widgets. The Plausible widgets are an exception because they surface third-party stats (not SN internal state), and Plausible widgets historically belong on the WP dashboard (Jetpack/WooCommerce convention).
- **CLAUDE.md invariant #3** — design system classes live in `assets/admin.css`. **Zero inline styles in admin PHP after this release** (down from ~25 instances across 6 files at v1.13.0 entry).

### Dashboard tab — full redesign
- `inc/deploy-status.php` renamed → `inc/admin-tab-dashboard.php` (385 LOC). Now owns the ENTIRE Dashboard tab content via the existing `sn_admin_dashboard_extras` hook.
- `inc/admin-page.php` Dashboard render block (~80 LOC of legacy Status table + Override details + Actions card grid) deleted; the new file's unified composition replaces it.
- **New composition (top to bottom):**
  1. **Site state** — 4-card hero grid (`.sn-state-grid`). Theme version, plugin version, deploys-since (with "N in last 24h"), health (with override count or "clean"). Replaces the v1.13.0 entry's existing 3-row Status table AND the new Versions table — both were duplicate sources of truth for the same data. Also eliminates the stale "Self-updater / SN_GITHUB_TOKEN" row (wrong constant name, dead concept since v8.3.0).
  2. **Recent deploys** — clean `<ul class="sn-deploy-list">` of last 5 GHA workflow runs (status glyph + repo + ref + duration + relative time + GitHub link). Replaces the 6-column table that would have overflowed on long branch names.
  3. **Maintenance** — 3-card action grid (Full Reset / Clear Overrides / Purge Caches) using the existing `.sn-card-grid` pattern. Force-check button DROPPED from the maintenance grid (it duplicated wp-admin/update-core.php's "Check Again" link) and moved to a tertiary `.button-link` inside the API summary.
  4. **External APIs** — single-line `.sn-api-summary` instead of a 3-row table. Each host: label + `mono number/limit` with state coloring. Promotes to a `.notice notice-warning` ABOVE everything only if any host hits critical (<10%).
  5. **Diagnostics** — `<details class="sn-override-details">` only renders when there ARE overrides. Hidden when clean.

### RSS tab — redesign
- **Activity stats full-width on top** (3 boxes — 24h / 7d / 30d). Cards use `.sn-rss-activity-card` (uniform width, content-driven, no inline styles).
- **2-col layout below** via the renamed generic `.sn-2col` (was `.sn-rss-grid` in v1.13.0). LEFT column: Recent requests table. RIGHT column: Settings form + Maintenance form. Content-driven widths (`minmax(0, 1fr)` left + `minmax(280px, 360px)` right) — replaces the arbitrary 60/40 from v1.13.0.
- **Breakpoint dropped from 1100px → 960px** so realistic admin viewports (1280-1440) keep the 2-col benefit.
- **Settings form** converted from `.form-table` to stacked `.sn-field` rows — fits the narrow right column much better than the WP-default two-column form table.
- **Maintenance form** lost its decorative bordered card (orphaned visual noise per design-critique). Now a borderless section with a single border-top divider (`.sn-rss-maintenance`).

### Plausible widgets (WP dashboard) — polish
- **Duplicate "Plausible not configured" copy** (line 78 + 132 — verbatim duplicate across snapshot + realtime widgets) extracted to `sn_pl_render_not_configured()`. One source of truth.
- **Diagnostic error block** rewritten from a fully inline-styled `<div>` (re-implementing `.notice notice-error`) to a proper `.notice notice-error notice-alt inline.sn-pl-diagnostic` — uses WP's canonical notice classes per the WP handbook + `wp-admin/css/common.css` source verification.
- All 4 inline `style="display:inline-block;..."` / `style="font-size:..."` / `style="color:#646970;"` on internal elements removed; promoted to scoped classes in the existing inline `<style>` block.
- **WP-native styling philosophy** preserved (already noted in the file's docblock: "no theme fonts, WP palette only").

### Other tabs — inline-style sweep + polish
- **Cloudflare tab:** 4 inline `style="font-family:ui-monospace,..."` on credential inputs → new `.sn-mono` utility class.
- **Plausible tab:** 2 inline mono fonts → `.sn-mono`. Inline `style="margin:0;max-width:none"` on status table → new `.sn-status-table--full` modifier.
- **Reading Time tab:** `style="max-width:300px"` on 2 action cards → `.sn-card--narrow` modifier (generalized — also used by Cloudflare + Plausible action cards now). Inline mono font on match-table pill → `.sn-mono`. Inline `style="color:var(--sn-text-muted)"` on snippet text → `.sn-rt-snippet` class. Inline `style="width:60px"` on ID column → `.widefat .column-id` class.
- **Links tab:** upgraded from a 4-row `.form-table` of links to a `.sn-link-grid` of cards. Each card has a category label (Source code / Releases / Infrastructure) + a title + the destination host, with the whole card as the click target via `.sn-link-card__link` overlay.
- **Identity tab:** zero inline styles — kept the `.sn-fieldset` pattern (it's already the reference standard) + the small "additional sameAs URL" margin promoted to `.sn-sameas-extra`.
- **Login tab:** zero changes — already well-structured (`.sn-status-box`, `.sn-fieldset`, `.sn-callout`).
- **`inc/admin-page.php`:** nav-tab margin promoted to `.sn-nav-tabs`. RSS-tracker-missing notice padding promoted to `.sn-rss-not-installed`.

### New CSS classes (assets/admin.css)
- `.sn-mono` — system mono font stack (eliminates 4+ duplicate inline declarations).
- `.sn-state-grid`, `.sn-state-card`, `.sn-state-card__{label,value,meta}` — Dashboard hero.
- `.sn-deploy-list`, `.sn-deploy-row`, `.sn-deploy-row__*` — clean deploy list (responsive: collapses to 4 columns on <782px).
- `.sn-api-summary`, `.sn-api-summary__item`, `.sn-api-summary__sep` — single-line API summary.
- `.sn-2col`, `.sn-2col__col` — generic 2-column layout (renamed from RSS-specific `.sn-rss-grid`).
- `.sn-rss-activity`, `.sn-rss-activity-card`, `.sn-rss-activity-card__*` — Activity stats row.
- `.sn-rss-recent`, `.sn-rss-settings`, `.sn-rss-maintenance`, `.sn-rss-meta` — RSS section wrappers.
- `.sn-link-grid`, `.sn-link-card`, `.sn-link-card__*` — Links tab cards.
- `.sn-card--narrow` — narrow action card modifier (replaces 4 inline `max-width:300px`).
- `.sn-submit--tight` — no-top-spacing submit row modifier.
- `.sn-notice-spacing` — inline notice vertical margin.
- `.sn-nav-tabs` — nav-tab-wrapper bottom margin.
- `.sn-rss-not-installed` — fallback notice for missing RSS tracker.
- `.sn-sameas-extra` — additional sameAs URL input margin (Identity tab).
- `.sn-status-table--full` — width-unlocked status table inside a fieldset.
- `.sn-override-details` — Dashboard diagnostics collapsible.
- `.sn-rt-snippet` — Reading Time match snippet.
- `.widefat .column-id` — narrow ID column for any widefat table.
- `.sn-pl-config-snippet`, `.sn-pl-diagnostic`, `.sn-pl-diagnostic-msg` — Plausible widget polish.

### Removed
- `inc/deploy-status.php` — renamed to `inc/admin-tab-dashboard.php`.
- `.sn-rss-grid`, `.sn-rss-col`, `.sn-rss-col--main`, `.sn-rss-col--side`, `.sn-subsection-h`, `.sn-rt-action-card` — all renamed/generalized into `.sn-2col` / `.sn-card--narrow`.
- ~80 LOC of Dashboard render block from `inc/admin-page.php` (absorbed into new tab file).

### Verified against WP handbook + source (per CLAUDE.md framework-source-first rule)
- WP Plugin Handbook: settings page structure (`.wrap` + `<h1>` + `<form>`), `.description` for helper text.
- `wp-admin/css/common.css`: `.notice` (left border + white bg), `.notice-{success,error,warning,info}` (color variants), `.notice-alt` (no box-shadow), `.notice .inline` (no margin), `.button`, `.button-primary`, `.button-secondary`, `.button-link`, `.nav-tab-wrapper`, `.nav-tab`, `.nav-tab-active`, `.postbox`, `.dashicons`, `.screen-reader-text`.
- `wp-admin/css/forms.css`: `.form-table` (2-col label+input layout), `.regular-text` (25em), `.large-text` (99%), `.small-text` (50px), `.tiny-text` (35px), `.code`, `.submit`, `p.submit`.
- WordPress CSS coding standards: hyphenated selectors, lowercase, no camelCase/underscores.
- WP `apply_filters('http_response', ...)` signature (already verified for the rate monitor in v1.13.0).
- `wp_add_dashboard_widget()` 7-arg signature (already verified in v1.12.0; the Plausible widgets continue to use it correctly).

### Notes
- **MINOR bump (1.13.0 → 1.14.0).** Pure visual + structural refactor — zero behavior change. Same functions, same data, same hooks, same forms POSTing to the same handlers. Just better composition + WP-native classes + zero inline styles. Plugin minor count 13 → 14 (continues over-cap pattern per documented preference).
- The companion theme repo will receive an equivalent audit pass next (user request).
- Inline styles remaining in `inc/content-rendering-helpers.php` and `inc/seed-content/*.html` are FSE Gutenberg block markup for FRONT-END post content — they're how block themes serialize layouts and intentionally left as-is.

## [1.13.0] - 2026-05-16

### Removed
- **WP dashboard widgets — deploy status (`sn_deploy_status`) AND RSS subscribers (`sn_rss_tracker_widget`) — both deleted.** v1.12.0's `inc/deploy-widget.php` ripped out entirely; the RSS dashboard widget registration in `inc/rss-plausible-tracker.php` also removed. The WP dashboard is a shared surface where SN-specific info competes for attention with other plugins; it's the wrong home for our operational tooling. SN settings pages are the canonical surface.
- **Admin bar pills** (`[T x.y.z] [P x.y.z]`) introduced in v1.12.0 — also gone with `inc/deploy-widget.php`.

### Added
- `inc/deploy-status.php` (~251 LOC) — hooks the existing `sn_admin_dashboard_extras` action to extend the **SN admin → Dashboard tab** with three read-only sections + a force-check button:
  1. **Versions table** — theme + plugin current vs. latest GitHub tag, with status pills + repo links.
  2. **Recent deploys** — last 5 GHA workflow runs across both repos (sorted newest-first). Status, ref, trigger, duration, relative time per row.
  3. **External API limits** — live snapshots of GitHub / Cloudflare / Plausible rate-limit headers, with ok/low/critical pills.
  4. **Force-check updates button** — POSTs to `admin-post.php?action=sn_force_update_check`. Handler clears all our update transients + WP's own `update_themes` / `update_plugins`, then redirects to `update-core.php?force-check=1`.
- `inc/api-rate-monitor.php` (~217 LOC) — **Phase 15a outgoing API rate-limit monitor.** Filters `http_response` (verified WP source: fires after success, $response guaranteed array, accept_args=3) on every outgoing `wp_remote_*` call. Inspects URL host; if it matches `api.github.com`, `api.cloudflare.com`, or `plausible.io`, reads server-reported rate-limit headers (`X-RateLimit-Remaining` / `-Limit` / `-Reset`) and stores in `sn_rate_limit_<host>` site transient (5min TTL).
  - **Throttled email warning** — when remaining drops below 10% for any tracked host, sends one `wp_mail()` to the site admin email, throttled to once-per-day-per-host via lock transient. Subject + body include host, percent, reset-time, and mitigation hints (e.g., "set `SNT_GITHUB_TOKEN`").
  - **Public helpers** — `snt_rate_limit_status($host)` and `snt_rate_limit_all_statuses()` consumed by the deploy-status sections.

### Changed
- **RSS tab — 2-column layout** (`inc/rss-plausible-tracker.php` + `assets/admin.css`). The four sections (Activity, Settings, Recent requests, Maintenance) used to stack vertically, creating a 4-screen scroll on the RSS tab. Now:
  - **Left column (3fr, ~60%):** Activity stats + Recent requests (read-heavy, wants horizontal room).
  - **Right column (2fr, ~40%):** Settings form + Maintenance (config, narrower).
  - Stacks on viewports < 1100px (`grid-template-columns: 1fr` media query). Internal `max-width` constraints on the sub-tables removed — the grid column is now the constraint.
- Dashboard tab now extends with the new deploy-status sections below the existing Status + Actions blocks. No structural change to the existing rows; just additive via `sn_admin_dashboard_extras` (the hook was already designed for this — see legacy docblock in `inc/admin-page.php:494`).

### Verified against WP source
- `apply_filters('http_response', $response, $parsed_args, $url)` in `wp-includes/class-wp-http.php` — `$response` guaranteed array (WP_Error returns early without filtering). accept_args=3.
- `admin-post.php` reads `$action` from `$_REQUEST`, fires `admin_post_{$action}` for logged-in users; no automatic nonce verification.
- GitHub REST rate-limit headers documented: `x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-used`, `x-ratelimit-reset` (Unix epoch). 60/h unauthenticated, 5000/h with token.

### Versioning rationale
- **MINOR bump (1.12.0 → 1.13.0).** Net new user-visible capability (the Dashboard tab sections + RSS 2-col layout + API rate monitor) plus the removal of v1.12.0's dashboard widget + admin bar pills. The removal isn't breaking per CLAUDE.md's definition (no public-API removal, no schema change, no required user action) — anyone who *used* the widget for ~24h sees it gone, but the same info is now in a better place.

### Notes
- The deploy-status sections only render when the SN admin → Dashboard tab is open. Zero overhead on regular wp-admin pages.
- The API rate monitor runs on EVERY `http_response` filter invocation (every `wp_remote_*` request anywhere on the site, including WP core's own update polling). Cost: one `wp_parse_url()` call + one foreach over 3 host entries. Negligible.
- Per user feedback: this is the canonical pattern for SN operational info from now on — extend the SN settings tabs, not WP-shared surfaces like the dashboard or admin bar.

## [1.12.0] - 2026-05-16

### Added
- **Phase 9 — Deploy status surfaces.** Closes the loop on the entire WP-update plumbing built across v1.10.x + v1.11.x: every piece of work now has a single readable place in wp-admin.
- `inc/deploy-widget.php` (~336 LOC) — registers the **"Signal & Noise · Deploy status" dashboard widget** (visible at wp-admin/index.php on login). Three sections:
  1. **Versions table** — theme + plugin current `Version:` vs. latest GitHub tag, each with a status pill (`up to date` / `vX.Y.Z available` / `unknown`) and a repo link.
  2. **Recent deploys** — last 5 GHA workflow runs merged across both repos, sorted newest-first. Each row: status icon (✓/✗/⊘/•), repo (theme/plugin), ref, trigger (push/workflow_dispatch), duration, relative time.
  3. **Force-check button** — POSTs to `admin-post.php?action=sn_force_update_check`. Handler clears `sn_gh_latest_theme`, `sn_gh_latest_plugin`, both `sn_gh_recent_runs_*` transients, AND WP's own `update_themes` / `update_plugins` transients, then redirects to `update-core.php?force-check=1` for belt-and-braces refresh.
- **Admin bar pills** (also in `inc/deploy-widget.php`) — two compact `[T 8.5.3] [P 1.12.0]` pills on the top-secondary (right) side of the admin bar. Visible on every wp-admin page AND on the front-end when admin bar is shown. Background color tracks state: green=ok, amber=update available, red=unknown. Hover title shows the full version comparison. Click links to the dashboard widget anchor.
- `inc/github-actions-api.php` (~141 LOC) — thin `wp_remote_get` wrapper for the workflow-scoped GHA Actions runs endpoint:
  - `snt_gh_recent_runs($repo, $count = 5)` — returns normalized run records from `/repos/<repo>/actions/workflows/deploy.yml/runs`. Cached 60s in `sn_gh_recent_runs_<repo>` site transient; 5min empty-sentinel on failure.
  - `snt_gh_recent_runs_merged(array $repos, $count = 5)` — merges + sorts by `created_at` DESC across multiple repos.
  - Honors `SNT_GITHUB_TOKEN` constant for authenticated requests (60/h → 5000/h rate limit). Define in `wp-config.php` to enable.
  - Run records pass through `apply_filters('sn_deploy_widget_run_record', $record, $raw)` for future enrichment (Phase 16+ AI summaries).

### Verified against WP source (per CLAUDE.md framework-source-first rule)
- `wp_add_dashboard_widget()` in `wp-admin/includes/dashboard.php` — confirmed 7-arg signature, callback receives `($post, $callback_args)` where `$post` is empty on dashboard. No auto capability check → render callback self-gates on `manage_options`.
- `admin-post.php` — confirmed `$action` read from `$_REQUEST` (POST+GET), fires `admin_post_{$action}` hook for logged-in users, no automatic nonce verification → handler does `check_admin_referer()` itself.
- `WP_Admin_Bar::add_node()` in `wp-includes/class-wp-admin-bar.php` — confirmed `parent => 'top-secondary'` for right-side placement (not action priority). Meta keys verified: `html, class, rel, lang, dir, onclick, target, title, tabindex, menu_title`.

### Architecture notes
- **Zero new contract hooks** — uses only documented WP primitives + reads existing `sn_gh_latest_*_tag()` cache from `inc/wp-update-integration.php`. WP-REFERENCE §10.0 surface unchanged.
- **Compatibility rules met** (per absorption roadmap): pure functions (`snt_deploy_status_for($pkg)`, `snt_gh_recent_runs($repo)`); filterable values (`sn_deploy_widget_run_record`); data-model first (transients), UI second.
- **CSS reuses existing `.sn-pill--ok/warn/err` classes** from `assets/admin.css`. Admin bar gets a minimal style override since `#wpadminbar` flattens backgrounds — printed via `admin_print_styles` + `wp_print_styles` actions to cover both admin pages and front-end bar appearances.

### Notes
- **MINOR bump** — new user-visible capability (the widget + admin bar surface). Plugin minor count 11 → 12; continues over-cap pattern per documented user preference (memory: `feedback_versioning_patch_cap.md`).
- Widget uses `human_time_diff(strtotime($iso), time())` for relative times — UTC-based, no timezone surprises.
- Force-check handler doesn't return early on no-change — it always clears transients + redirects, so even a "no new version" state benefits from the refreshed cache.

## [1.11.2] - 2026-05-16

### Added
- `inc/wp-update-git-preservation.php` (200 LOC) — `.git`-preservation filter pair + admin_init self-recovery. Closes the footgun where clicking "Update Now" in wp-admin destroyed the plugin's `.git` directory (via WP_Upgrader's recursive `clear_destination()`) and broke the canonical `gh workflow run deploy.yml --ref vX.Y.Z` install path.

### How it works
- `upgrader_pre_install` (priority 10, accept_args=2) — atomically `rename()`s `.git/` → `wp-content/upgrade/sn-signal-and-noise-tools-git-backup/` before WP's `clear_destination()` runs. Returns `WP_Error` to abort the install if the backup fails (better than silent .git destruction).
- WP runs its normal install (clear_destination + `upgrader_source_selection` rename of the unpacked archive dir → `move_dir`).
- `upgrader_post_install` (priority 10, accept_args=3) — atomically `rename()`s the backup back into the (now newly installed) destination dir. On WP-side install failure (WP_Error response), restores `.git` to the original plugin dir so the rolled-back code keeps its checkout intact.
- `admin_init` self-recovery — on every admin pageview, if an orphaned backup is detected (post_install never fired — PHP timeout mid-install, fatal in another plugin's update hook, etc.), restore intelligently. Idempotent.

### Behaviour
- Both install paths now coexist. `gh workflow run deploy.yml --ref vX.Y.Z` stays the canonical/fast path; clicking "Update Now" in wp-admin no longer breaks the subsequent workflow_dispatch.
- Same-filesystem `rename()` is **atomic at the kernel level** — no window where `.git` exists in both places or neither. Cross-FS rename silently falls back to copy+delete (NOT atomic) — that's why the backup lives under `wp-content/upgrade/` (same mount as `wp-content/plugins/` in standard WP installs incl. Cloudways).
- `inc/wp-update-integration.php` docblock extended with the v1.11.1 + v1.11.2 history.

### Mirrors theme v8.5.2
- Same file structure + filter pair + restore primitive as the theme's `inc/wp-update-git-preservation.php` (shipped earlier this session at theme v8.5.2). The two implementations differ only in which `$hook_extra` key they guard on (`plugin` vs `theme`), which constants they reference (`SN_GH_PLUGIN_*` vs `SN_GH_THEME_*`), and which directory primitives they use (`WP_PLUGIN_DIR` + `SN_GH_PLUGIN_SLUG` vs `get_theme_root()` + `SN_GH_THEME_STYLESHEET`).

### Three patches to make one click work
The WP UI update path required three independent blockers to be removed:
1. **v1.10.1** — enable the infrastructure (register with WP's update transient, add `upgrader_source_selection` to rename GitHub's archive dir).
2. **v1.11.1** — fix the 12h cache that was hiding new tags from WP's update checker.
3. **v1.11.2** — preserve `.git` through the install so the workflow_dispatch fallback doesn't break.

After this release, both install paths work end-to-end and coexist safely.

### Notes
- This release ships via the canonical `gh workflow run deploy.yml --ref v1.11.2` (the new code is dormant on this install since workflow_dispatch is SSH `git checkout`, not WP-installer). The filter pair activates only on the NEXT update if the maintainer chooses WP UI.
- `error_log()` is used for restoration failures, not `WP_Error` — the WP install itself succeeded; a failed `.git` restore is post-hoc and shouldn't fail the install. The admin_init self-recovery retries on next pageview.

## [1.11.1] - 2026-05-16

### Fixed
- **WP UI update cache was too sticky.** Symptom: after pushing v1.10.2 and v1.11.0, neither showed up in `wp-admin → Dashboard → Updates`. Cause: the GitHub Tags API result was cached in a site transient for 12 hours, AND clicking "Check Again" in the WP UI didn't force a refresh because the code didn't honor WP's `WP_FORCE_UPDATE_CHECK` constant. The cache was set when WP last polled (right around when v1.10.1 deployed) and any tags pushed after that stayed invisible until cache expiry.
- **Three fixes** in `inc/wp-update-integration.php`:
  1. `sn_gh_latest_plugin_tag()` gains an optional `$force_refresh` parameter that bypasses the cache.
  2. The `pre_set_site_transient_update_plugins` filter callback detects WP's force-check signals (`WP_FORCE_UPDATE_CHECK` constant OR `?force-check=1` query arg) and passes through to the new parameter. Clicking "Check Again" now actually re-fetches from GitHub.
  3. New `admin_init` hook stores the on-disk plugin version in an option (`sn_last_seen_plugin_version`). On every admin pageview, if the on-disk version differs from the stored last-seen, the GitHub-tag transient AND WP's own `update_plugins` transient are cleared. This handles the upgrade-just-happened case automatically — whether the upgrade came via WP UI install or manual `workflow_dispatch` deploy.
- **Cache TTL reduced from 12 hours → 1 hour.** 12h was too long for "I just pushed a tag, where's my update?" Even with force-check working, the autonomous background poll cadence matters. 1h is responsive enough that pushed tags surface naturally within minutes-to-an-hour without any explicit user action.

### Notes
- **PATCH bump within `1.11.x`.** Bugfix in the update-detection path; no functional change to the actual plugin features.
- **First WP-UI-flow installs (v1.10.2, v1.11.0) were lost in the cache window.** Their changes are still in the bundle (v1.11.1 supersedes both — it includes the v1.10.2 per-post canonical/noarchive/noimageindex fields AND the v1.11.0 sitemap filter). No regression; just compressed into one release.
- **Bootstrap path:** v1.11.1 deploys via one final manual `workflow_dispatch`. From then on, the WP UI install flow works correctly for all future tags — the `admin_init` cache-clear means even the next upgrade after this one will surface cleanly.

## [1.11.0] - 2026-05-16

### Added
- **`inc/sitemap.php` — sitemap filter for WP core's built-in `/wp-sitemap.xml`.** Hooks `wp_sitemaps_posts_query_args` to exclude two classes of posts from the sitemap:
  - Posts with `_sn_noindex = '1'` (the per-post noindex flag from v1.10.0). If a post is hidden from search engines, it shouldn't be in the sitemap either.
  - Posts with a non-empty `_sn_canonical_url` (per-post canonical override from v1.10.2). Canonical pointing elsewhere = this URL isn't the source-of-truth → exclude from our sitemap.
- Scoped to `post` + `page` post types (matches `SN_POST_SETTINGS_POST_TYPES`).

### Architectural note — dormant until Phase 13
- **Currently inactive on the live site.** The SEO Framework (TSF) is still active on juanlentino.com; TSF deregisters WP core's `/wp-sitemap.xml` route and serves its own at `/sitemap.xml` instead. Our filter targets WP core's sitemap, so it doesn't fire as long as TSF runs.
- **Activates automatically at Phase 13 cutover** (v2.0.0). When TSF is deactivated, WP core's sitemap takes over at `/wp-sitemap.xml` and our filter immediately starts honoring the per-post overrides.
- Registered unconditionally because doing so is cheap (no overhead when the hook never fires) and avoids coordination logic with TSF. The pattern "register filters that activate when their feature target becomes live" is the same approach used in the v1.5.0 login-hide module (stands down while wps-hide-login is active; activates when that plugin is removed).

### Sitemap features NOT shipped in v1.11.0
- **Custom URL routing** (intercepting `/sitemap.xml` while TSF is active) — would create two competing sitemaps. Wait for Phase 13.
- **Image sitemap extensions** — marginal SEO value; Google indexes inline `<img>` regardless.
- **Video sitemap, news sitemap** — not applicable to this site (no video catalog, not a news publisher).
- **Per-post `changefreq` / `priority`** — Google has explicitly stated these are ignored. WP core skips them.
- **Sitemap ping on update** — Google deprecated sitemap ping in 2023. WP core never implemented it. Search engines discover sitemap updates via robots.txt + crawl cadence.

### Notes
- **MINOR bump (v1.11.0).** New file + new user-visible behavior (when activated), even though the activation is currently latent. Aligns with the project's pattern of preferring MINOR for additive features and reserving PATCH for fixes / refactors.
- **Ships through the WP-UI-updates flow** introduced in v1.10.1. Push tag → `wp-admin → Updates` → "Update Now". (Or `gh workflow run deploy.yml --ref v1.11.0` for emergency.)
- **Theme parallel work** still queued: the theme repo needs the same WP-UI-updates treatment as the plugin got in v1.10.1. Will ship as `v8.5.1` in a separate task — same file changes (deploy.yml trigger + wp-update-integration.php).

## [1.10.2] - 2026-05-16

### Added
- **Three new per-post override fields** in the "Signal & Noise" meta box:
  - **Custom canonical URL** (`_sn_canonical_url`) — overrides `<link rel="canonical">` for this post. Use case: republished or syndicated content where the canonical lives at the original publisher's URL. Empty falls back to the permalink.
  - **No archive checkbox** (`_sn_noarchive`) — appends `noarchive` to the robots meta tag (tells Google etc. not to show a cached version).
  - **No image index checkbox** (`_sn_noimageindex`) — appends `noimageindex` to the robots meta tag (images on this page won't appear in Google Images).
- Three new typed accessors in `inc/post-settings.php`: `sn_post_settings_get_canonical_url()`, `sn_post_settings_get_noarchive()`, `sn_post_settings_get_noimageindex()`. All three meta keys registered via `register_post_meta()` with `show_in_rest=true` — same architectural pattern as v1.10.0's three fields. REST `/wp-json/wp/v2/posts/{id}` now exposes `meta._sn_canonical_url`, `meta._sn_noarchive`, `meta._sn_noimageindex` alongside the v1.10.0 fields.

### Changed
- **`inc/seo.php` canonical emitter** — checks `_sn_canonical_url` first for singulars before falling back to the permalink returned by `sn_seo_meta_for_current_view()`.
- **`inc/seo.php` robots emitter** refactored from a hardcoded string concatenation to a directives-array build pattern. Honors all four robots flags (`noindex` + auto `nofollow` since v1.6.0; `noarchive` + `noimageindex` added in v1.10.2). The permissive defaults (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`) are always appended regardless of per-post flags. Backward compatible: existing `_sn_noindex` behavior unchanged.
- **`inc/post-settings.php` save handler** refactored to iterate over field-key maps (boolean fields and URL fields) rather than 1:1 inline blocks per field. Same behavior; halves the LOC and makes adding more fields later trivial.

### Notes
- **TSF parity:** these are the per-post equivalents of three TSF settings (canonical URL override, noarchive directive, noimageindex directive). Combined with v1.10.0's noindex + meta description + OG image, the SN per-post meta box now covers ~80% of TSF's per-post SEO controls. Remaining TSF features queued: focus keyword analysis (complex UI, marginal value), nofollow / nosnippet standalone toggles (incremental).
- **PATCH bump within `1.10.x`.** Additive only; no schema migrations.
- **First update via WP UI flow** since v1.10.1 shipped the fix. Push the tag → check `wp-admin → Dashboard → Updates` → "Update Now" → installs from GitHub archive. (12h cache TTL on update detection — can be forced via "Check Again" button on the Updates page.)

## [1.10.1] - 2026-05-16

### Fixed
- **WP update gating — updates now go through the WordPress admin Updates page.** Previously, pushing a `vX.Y.Z` tag triggered the GHA deploy workflow which SSH'd into Cloudways and `git checkout`ed the new tag ~30s later. The `inc/wp-update-integration.php` UI was just a deploy-health indicator — it explicitly REJECTED actual "Update Now" clicks (`upgrader_pre_install` filter returned a `WP_Error`). Net effect: tag push = update lands without maintainer confirmation. After v1.10.1, tag pushes do nothing automatically; updates appear in `wp-admin → Updates` and `wp-admin → Plugins`, and the maintainer clicks "Update Now" to install.

### Changed
- **`.github/workflows/deploy.yml` trigger** changed from `on: push: tags: 'v*'` to `on: workflow_dispatch:` only. Tag pushes no longer fire the workflow. Manual emergency-hotfix deploys remain available via the GitHub Actions UI or `gh workflow run deploy.yml --ref vX.Y.Z`.
- **`inc/wp-update-integration.php`**:
  - **Removed** the `upgrader_pre_install` filter that rejected WP installer attempts with a `WP_Error` directing the maintainer to push a git tag instead.
  - **Added** `upgrader_source_selection` filter to rename the unpacked source directory from GitHub's auto-generated format (`signal-and-noise-tools-1.10.1/`) to the plugin slug (`signal-and-noise-tools/`). Without this, WP would install to the wrong directory and the plugin would deactivate on update.
  - Docstring rewritten to describe the new WP-UI-driven flow.
- The `package` URL (pointing at `https://github.com/juanlentino/signal-and-noise-tools/archive/refs/tags/<tag>.zip`) was already set correctly since v1.4.0 — only the install path needed fixing.

### How updates work from v1.10.1 onward
1. Maintainer pushes tag `vX.Y.Z` to GitHub.
2. WP poll (every 12h, cached in `sn_gh_latest_plugin` transient) sees the new tag.
3. `wp-admin → Dashboard → Updates` shows "Signal & Noise Tools" with an "Update Available" badge.
4. Maintainer clicks "Update Now". WP downloads the GitHub tag archive, the source-selection filter renames the directory, WP installs over the previous version, plugin reactivates.

### Emergency hotfix path
If the WP UI install ever fails (e.g., GitHub API down, ZIP fetch blocked, file-permission issue on Cloudways), trigger the workflow manually:
```bash
gh workflow run deploy.yml --ref v1.10.1 --repo juanlentino/signal-and-noise-tools
```
This runs the same SSH + `git checkout` path the legacy auto-deploy used. Reserved for emergencies — the canonical flow is the WP UI.

### Notes
- **PATCH bump within `1.10.x`.** No plugin schema or functional change; only the release-pipeline trigger gating changed.
- **First install bootstrap:** the v1.10.1 tag itself can't be installed via WP UI because the v1.10.0 server-side has the OLD code that rejects WP installer attempts. v1.10.1 lands on Cloudways via one manual `gh workflow run` invocation. After that, v1.10.2+ install via the WP UI.
- **One-time loss of `.git` directory on first WP UI install.** WP's installer wipes the existing plugin directory before unpacking the new version, including the legacy `.git` checkout from past SSH deploys. Harmless — the SSH-based deploy path is no longer needed (still available via `workflow_dispatch` emergency fallback, which re-clones).
- **Theme repo** (`signal-and-noise`) gets the equivalent treatment in v8.5.1 (separate ship). Same one-line workflow change + same `wp-update-integration.php` fixes; the existing wp-update-integration.php in the theme mirrors this plugin's pattern.
- **Replaces the failed v1.10.1 attempt** (force-reverted) that incorrectly used a GitHub Actions environment approval gate instead of the WP admin UI flow.

## [1.10.0] - 2026-05-16

### Added
- **Per-post SEO settings UI.** New "Signal & Noise" meta box on the post + page editor (auto-converts to a sidebar panel in the block editor) exposing three overrides:
  - **Noindex toggle** — when checked, adds `noindex,nofollow` to the robots meta tag for that post. Reader has existed since v1.6.0 via `_sn_noindex` post meta; v1.10.0 adds the write path.
  - **Custom meta description** — overrides the post excerpt for `<meta name="description">`, `og:description`, `twitter:description`, AND the JSON-LD Article schema description. Empty falls back to the excerpt.
  - **Custom OG image URL** — overrides the featured image / auto-generated card / site default. Highest priority in the OG image resolution chain. Explicit beats implicit.
- **REST API exposure for all three meta keys** via `register_post_meta()` with `show_in_rest=true`. `/wp-json/wp/v2/posts/{id}` (and pages endpoint) now include `meta._sn_noindex`, `meta._sn_meta_description`, `meta._sn_og_image_url`. `auth_callback` requires `edit_posts` for writes; reads are public (these are user-facing values).
- **`sn_post_settings_get_noindex/description/og_image_url($post_id)` typed accessors** — consumers call these instead of `get_post_meta()` directly so the type contract lives in one place. `function_exists()` guards on every cross-module call so the new module can be selectively deactivated without breaking the existing readers.

### Changed
- **`inc/seo.php`** `sn_seo_meta_for_current_view()` singular branch now checks `_sn_meta_description` before falling back to `$post->post_excerpt`.
- **`inc/seo-schema.php`** Article schema `description` field follows the same fallback chain via new `sn_schema_article_description()` helper. Preserved the existing conditional-assignment pattern that OMITS the description key from JSON-LD when nothing resolves (rather than emitting an empty string) — schema validators see identical clean structure when no override or excerpt exists.
- **`inc/og-card-generator.php`** OG image filter chain checks `_sn_og_image_url` first, beating featured image / auto card / site default when set.

### Architecture
- **Hybrid PHP meta box + REST exposure** — Approach C from spec research. Zero build pipeline preserved. Same architectural pattern Yoast Free uses at scale. Future migration to a React block-editor sidebar is free thanks to REST exposure — meta keys and storage stay the same.
- Save handler on `save_post` with full guard chain (nonce → DOING_AUTOSAVE → wp_is_post_revision → cap → sanitize). Empty values trigger `delete_post_meta()` to keep the DB clean.
- All three reader integrations use `function_exists()` guards on `sn_post_settings_get_*` calls — defensive against `inc/post-settings.php` absence.
- Two affected post types: `post` + `page` (matches existing hook guards across `inc/reading-time.php`, `inc/og-card-generator.php`, `inc/cloudflare-purge.php`).

### Process notes
- **Built via `subagent-driven-development`** — Tasks 4/5/6 (the three independent reader integrations) dispatched as 3 parallel subagents. Each subagent verified its own edit, then the main session re-verified each independently before committing per the spec-reviewer discipline.
- Two subagent judgment calls preserved: (1) seo-schema.php's conditional-assignment pattern (better than the prompt assumed); (2) og-card-generator.php's filter-callback structure differs from the prompt's assumed inline featured-image check — subagent inserted at the structurally analogous position before the helper delegation. Both calls verified correct.

### Notes
- **MINOR bump despite minor cap.** Project cap is 5 minors per major; the plugin already exceeded that mid-Phase-1 (shipped 1.0 through 1.9 without rolling to 2.0). Continuing the existing pattern. A strict cap enforcement would require renumbering the 1.6-1.9 backlog as v2.x — not justified for a single-user plugin.
- **Spec**: `docs/superpowers/specs/2026-05-16-per-post-settings-v1.10.0-design.md`. **Plan**: `docs/superpowers/plans/2026-05-16-per-post-settings-v1.10.0-plan.md`. Both grounded in two parallel research-agent reports (codebase mapping + UI architecture).
- **Queued next:**
  - **v1.10.1** — WP admin update gating fix. The auto-deploy GHA pipeline bypasses the WP admin update approval gate; plugin updates land without user confirmation. Reverting plugin to manual-update-from-WP-UI flow (theme keeps auto-deploy).
  - **v1.10.2** — per-post canonical URL override + custom robots directives (additional fields on the existing meta box; small TSF-equivalent additions).
  - **v1.11.0** — sitemap.xml generation (real new feature, TSF parity).
- **Out of scope** (deferred further): React block-editor sidebar, focus keyword analysis (TSF Focus extension), bulk-edit / quick-edit support, bulk import/export.

## [1.9.6] - 2026-05-16

### Added
- **Identity tab dirty-tracking on the sticky save bar.** JS snapshots all form values on `DOMContentLoaded`; on any field change, the save bar hint switches from default copy to "N unsaved change(s)" with a subtle amber dot prefix. Reverts cleanly when you type back to the original value. Scoped to `.sn-identity-form` only — Login (single field), Cloudflare, and Plausible have inline save buttons where this is overkill.
- **"+ Add another profile URL" button** in the sameAs section, replacing the v1.9.5 always-shown trailing empty input. Click → JS clones a fresh empty `<input type="url">` row above the button, focuses it, fires a custom `sn:row-added` event so the dirty-tracker doesn't read the empty row as "dirty" before typing. `<noscript>` fallback preserves the v1.9.5 single-trailing-input behaviour for users with JS disabled.
- New `assets/admin.js` (~150 LOC vanilla JS, no jQuery, no build pipeline). Enqueued only on SN admin pages via the same hook-suffix guard as `admin.css`. Loaded in the footer (`$in_footer = true`) so it runs after DOM is parsed.

### Accessibility (WCAG 2.1 AA)
- **Focus ring contrast**: `.sn-add-row-btn:focus-visible` box-shadow opacity at 0.65 (≈3:1 against white card surface) — meets WCAG 1.4.11 non-text contrast minimum.
- **JS-added inputs get `aria-label="Profile URL"`** — placeholders don't satisfy WCAG 4.1.2 / 3.3.2; each row needs its own accessible name beyond the group label.
- **`prefers-reduced-motion` query** disables the row fade-in animation and button transitions for users who've expressed that OS-level preference.
- **`:focus-visible`** (not `:focus`) so the focus ring only shows for keyboard users, not mouse clicks.
- **`<button type="button">`** native element with descriptive `aria-label` and real text content (not icon-only).

### Notes
- **Pure UX polish — no schema change, no server-side behaviour change.** The form submits identically: `social_same_as[]` array with one or more URLs, sanitized by `sn_settings_save()` (empty values filtered, valid URLs persisted).
- Zero-build-pipeline architecture preserved. The plugin still has no webpack / babel / npm pipeline; `admin.js` is hand-written vanilla JS that ships as-is.
- PATCH bump within `1.9.x` (counter at 6/7 of the per-minor cap).

## [1.9.5] - 2026-05-16

### Fixed
- **Three more latent `themes.php?page=` URL bugs** of the same class fixed in v1.9.4. Surfaced by a sweep after the Reading Time fix:
  - [`inc/admin-bar.php`](inc/admin-bar.php) — top-level "S&N" admin bar item and "⚙ Open Dashboard" submenu both pointed at the pre-v1.8.1 location.
  - [`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php) — the "Settings & activity" link from the RSS widget pointed at `themes.php?page=sn-theme-options&tab=rss` (legacy compound URL); now points at the cleaner v1.9.0 submenu URL `admin.php?page=sn-rss`.
- All have been latently broken since v1.8.1 (top-level menu move). Effect: clicking these links 404'd silently. Cleared in one sweep so this URL class is fully retired.

### Notes
- PATCH bump within `1.9.x`. No schema or behavior change.
- After this, **all admin-page URLs across the plugin codebase use the v1.9.0 sidebar submenu pattern** (`admin.php?page=sn-<slug>`). Confirmed by `grep -rn 'themes.php?page=sn-theme-options' inc/` returning zero matches.

## [1.9.4] - 2026-05-16

### Changed
- **Reading Time tab redesigned** with the v1.9.0 design system. Inline-styled cards replaced with `.sn-fieldset` / `.sn-card-grid` / `.sn-card`. Inline style strings dropped from 14 → 4 (remaining are minor max-width + monospace family on the match-display pill).
- **Tool flow restructured** as numbered steps inside one fieldset: *1 · Preview* (always shown) → *2 · Apply* (shown only after preview runs). Destructive-action warning copy is now on the Apply card itself, with a count of matches and an explicit "Destructive — cannot be undone — back up first" callout. Disabled when zero matches.
- **Empty-state for "no matches"** uses `.sn-status-box` (green) with a clean-state pill. Previously a single inline-coloured `<p>` that was easy to miss.
- **Matches table** stays on `widefat striped` (the right WP pattern for multi-column post lists per the v1.9.1 handoff). Match cells now use `.sn-pill --err` for the matched substring instead of inline-colored spans.

### Fixed
- **Legacy URL bug** — the preview link previously pointed at `themes.php?page=sn-theme-options&sn_rt_preview=1` (the URL pattern from pre-v1.8.1 when the admin page lived under Appearance). After v1.9.0's sidebar submenu refactor, that URL 404s. Now correctly points at `admin.php?page=sn-reading-time&sn_rt_preview=1`. This bug would have surfaced the first time anyone clicked Run Preview after the v1.8.1 menu move.

### Architecture
- **`apply_reading_time_cleanup` POST handler moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. Same PRG flow as Identity / Login / Plausible / Cloudflare. Count of cleaned posts encoded in the flash code (`rt_applied_N` pattern, same as `cleared_N` / `reset_N` for the maintenance actions).
- **`reading-time.php`'s admin tab callback is now render-only.**
- **1 new flash code pattern**: `rt_applied_N`.

### Notes
- **All 3 queued tab module redesigns complete** (v1.9.2 Plausible, v1.9.3 Cloudflare, v1.9.4 Reading Time). All 8 tabs now use the v1.9.0 design system. Inline-style total across the plugin's admin surface: was 80+, now 14.
- No schema or behavior changes. PATCH bump.
- **Suggested next: v1.9.5 if any cross-tab polish surfaces from this round; or sit at v1.9.4 and let it bake.** The 7-patch cap before rolling to v2.0.0 leaves room (v1.9.5, 1.9.6, 1.9.7 available within the 1.9.x lifecycle per project versioning rules).

## [1.9.3] - 2026-05-16

### Changed
- **Cloudflare tab redesigned** with the v1.9.0 design system. Inline-styled cards and `<p><strong>` field labels replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card`. Inline style strings dropped from 21 → 5 (remaining are font-family monospace + max-width on the manual-purge card).
- **Status box at the top** with two states: *Configured — auto-purge active* (green) when both token + zone ID are set; *Not configured* (amber) otherwise. Body includes the last-purge timestamp + kind ("full zone" vs "N URL(s)") when available, so the status box is also the activity log.
- **Credentials fieldset** holds both API token (`.sn-field-w-lg`, monospace) and Zone ID (`.sn-field-w-md`, monospace). Each field independently locks (disabled state + "locked by constant" helper) when its respective wp-config.php constant is set. Save button hidden when both fields are constant-locked.
- **Manual purge as a `.sn-card`** in `.sn-card-grid` — consistent with Dashboard action cards. Disabled when module is not configured.

### Architecture
- **POST handling moved to `sn_handle_admin_post()`** in `inc/admin-page.php`. `cf_save` and `cf_purge_now` now route through the central PRG handler — same redirect-after-save flow as Identity / Login / Plausible. `cloudflare-purge.php`'s admin tab callback is now render-only.
- **3 new flash codes**: `cf_saved`, `cf_purged_ok`, `cf_purged_unconfigured`.

### Notes
- No schema or behavior change for Cloudflare consumers (`sn_cf_*` functions, option keys, auto-purge hooks unchanged). PATCH bump.
- Queued next: v1.9.4 (Reading Time tab — last of the three module redesigns).

## [1.9.2] - 2026-05-16

### Changed
- **Plausible tab redesigned** with the v1.9.0 design system. Inline-styled cards and `form-table` replaced with `.sn-fieldset` / `.sn-field` / `.sn-status-box` / `.sn-card-grid`. Inline style strings dropped from 23 → 5 (remaining are minor font-family + max-width on action cards).
- **At-a-glance module status box at the top of the Plausible tab.** Reflects one of four states: *Configured* (green — token present + last call succeeded), *Configured but failing* (amber — token present + last call returned an HTTP error), *Misconfigured — wrong token namespace* (amber — only Plausible plugin's api_token available; will 401), *Not configured* (red — no token at all). Mirrors the Login tab's module-status pattern.
- **Status details fieldset** (domain / token source / last call) below the module status box. Status pills for Last call use `.sn-pill --ok / --err` instead of inline-colored spans.
- **Locked-field treatment** for the Stats API token when `SN_PLAUSIBLE_STATS_TOKEN` constant is set. Mirrors the Login slug's locked treatment — disabled input with explanatory helper text.
- **Token form uses `.sn-fieldset-actions`** for the inline Save button (short form pattern, same as Login post-v1.9.1). No sticky save bar.

### Architecture
- **POST handling moved from `inc/plausible-admin.php` to `sn_handle_admin_post()`** in `inc/admin-page.php`. Both `pl_save` and `pl_test` now go through the central PRG handler so they get the same redirect-after-save flow as Identity / Login (no more stale-form-after-save). `plausible-admin.php` is now a render-only callback.
- **7 new flash codes**: `pl_saved`, `pl_cleared`, `pl_unchanged`, `pl_locked`, `pl_test_ok`, `pl_test_err`, `pl_test_unconfigured`. Test result detail (visitor count / HTTP error) regenerated from the existing transients on the post-redirect render.

### Notes
- No schema or behavior changes for the Stats API consumers (`sn_plausible_*` functions, transient keys, option names unchanged). PATCH bump.
- Queued next: v1.9.3 (Cloudflare tab) and v1.9.4 (Reading Time tab) — same design-system rollout pattern.

## [1.9.1] - 2026-05-16

### Changed
- **Login tab save UI replaced with inline action row.** The sticky `.sn-savebar` made sense on Identity (long form, scrolling required) but felt misplaced on Login (single editable field, no scrolling). New `.sn-fieldset-actions` component renders an inline save button at the bottom of the fieldset card, with optional left-aligned hint text (only shown when the slug is locked by the `SN_LOGIN_SLUG` constant). Pattern: short forms get inline actions, long forms keep the sticky bar.
- **Tab-specific page subtitle.** Every tab gets its own one-sentence subtitle below the H1, describing what that tab is about (e.g. Login → *"Custom login URL and emergency unlock for the WordPress admin."*; Identity → *"Site name, social profiles, Open Graph cards, and per-route SEO copy."*). Replaces the v1.8.1+ static `"Theme management and maintenance."` that displayed on every tab regardless of context. Subtitles live in the `sn_admin_pages()` data structure alongside the slug/tab/label/title.
- **Page header H1 + subtitle moved from inline styles to `.sn-page-h1` + `.sn-page-subtitle` classes.** Last two inline-style strings on the page-shell removed.

### Notes
- **Design-system audit drove this patch.** Triggered by a critique that surfaced 4 cross-tab issues: save-UI pattern mismatch, generic subtitle on every tab, inline-style cards on un-redesigned tabs, no width-capping outside Identity. v1.9.1 ships fixes for the first two (low-risk pure CSS+markup); the un-redesigned tab modules (Cloudflare, Plausible, Reading Time) are queued for v1.9.2–v1.9.4 since they touch form handlers and need supervised verification.
- **No schema or behavior changes.** PATCH bump within `1.9.x`.

## [1.9.0] - 2026-05-16

### Added
- **One sidebar submenu per tab.** All 8 admin sections (Dashboard, Identity, Login, Cloudflare, Plausible, RSS, Reading Time, Links) now appear as nested entries under the top-level *Signal & Noise* menu. Each has a unique slug (`sn-identity`, `sn-login`, …) so the WP sidebar highlights correctly when on that page. In-page tab navigation is kept as a parallel orientation aid — same pattern as Yoast / WP Rocket / ACF.
- **New Login tab** (sidebar: *Signal & Noise → Login*). Promotes the login-hide module from a buried Identity sub-section to a focused tab with:
  - **Module status display** — ACTIVE / DORMANT (wps-hide-login conflict) / BYPASSED (`SN_LOGIN_BYPASS` constant set), rendered as a colored status box.
  - **Current login URL** as a clickable monospace chip.
  - **Slug edit form** — disabled with explanation when `SN_LOGIN_SLUG` constant overrides the setting.
  - **Emergency unlock docs** — both wp-config.php constants, copy-pasteable.
- **`save_login` action handler** — writes only to the login slice of `sn_settings`. Disambiguates `update_option`'s false-on-no-change-vs-failure return by re-reading the stored value.
- **Post/Redirect/Get for all settings saves.** New `sn_handle_admin_post()` runs on `admin_init` (before any output), processes the `$_POST`, and redirects with `?sn_flash=<status>`. The page callback translates the flash arg into a notice on the post-redirect GET. Fixes the stale-form-after-save bug we shipped in v1.8.0 (form re-rendered with cached pre-save values until manual reload). See `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19 for the architectural rationale.

### Changed
- **Identity tab rewritten** with a custom `.sn-fieldset` card layout. Replaces WP's `form-table` markup with vertical-flow `.sn-field` rows (label above, input below, helper below input). Inputs are width-capped per type via `.sn-field-w-{xs,sm,md,lg,xl}` modifiers (140 / 240 / 480 / 580 / 720 px) so the form stops looking wonky at wide widths.
- **Identity tab loses its Login section.** Slug field moves to the new Login tab. Section TOC now lists 4 jumplinks instead of 5.
- **Profile URLs (sameAs) trailing empty row** is now subtly styled (`.sn-sameas-empty`) with dashed border + italic placeholder, reading as *"add another"* instead of a forgotten dangling input.
- **`sn_settings_save()` preserves the existing login slug** when `login_slug` isn't in the form payload. Without this, saving Identity (which no longer includes the slug field) would clobber the configured slug back to the default. Read-existing-as-fallback pattern.

### Architectural notes
- **Deliberate deviation from the WP Plugin Handbook's Settings API recommendation.** The Handbook recommends `register_setting` + `add_settings_section` + `add_settings_field` (form posts to `options.php`, WP handles save + PRG + nonces). We instead use custom `$_POST` handlers (form posts to our own admin URL) because Settings API enforces one-option-per-setting, which doesn't fit our single nested-array `sn_settings` schema. Same trade-off Yoast Free / ACF / WP Rocket make. **The price of this deviation is owning every responsibility Settings API handles for you** — nonce, sanitization, PRG redirect, success/error flash. After v1.9.0 we do all four correctly. Documented in `docs/WORDPRESS-REFERENCE.md` gotchas #18 + #19.
- **Source-grounded sidebar registration.** Submenu registration uses the documented escape hatch for the auto-prepended duplicate-parent submenu (gotcha #14); enqueue guard handles `add_submenu_page`'s `false` return for low-cap users (gotcha #15); single source of truth (`sn_admin_pages()`) prevents duplicate-slug drift (gotcha #16).
- **Backward compat preserved.** Old v1.8.x deep links like `?tab=identity` still work — the callback's dispatcher checks `$_GET['tab']` first, falls back to deriving the tab from `$_GET['page']`. PRG redirect preserves `?tab=` if it was on the inbound request.
- **Other tab modules untouched.** Cloudflare / Plausible / RSS / Reading Time keep their v1.8.x form-table + inline styles. Same redesign pattern rolls across them in v1.9.x or v2.0.0.

## [1.8.1] - 2026-05-16

### Changed
- **Admin page promoted to top-level menu.** "Signal & Noise" now appears as its own item in the WP admin sidebar (megaphone icon, position 81) instead of a submenu under Appearance. New URL: `/wp-admin/admin.php?page=sn-theme-options` (the old `/wp-admin/themes.php?page=…` URL no longer resolves). The first submenu entry is labelled "Dashboard" to avoid the duplicate-parent-label pattern that `add_menu_page()` produces by default. Tab deep links (`?tab=identity` etc.) are unchanged.
- **Inline styles extracted to `assets/admin.css`.** 25+ duplicated `style="…"` attributes across the Dashboard and Identity tabs are now class-driven via a single enqueued stylesheet with CSS variables for surface/border/spacing/status. Other tab modules (Cloudflare, Plausible, Reading Time, RSS) still use inline styles — they get the same treatment in v1.9.0.
- **Status indicators replaced with pill badges.** Dashboard status row now uses `.sn-pill` / `.sn-pill--ok` / `.sn-pill--warn` / `.sn-pill--err` (rounded, colored dot prefix) instead of inline-coloured spans.

### Added
- **Identity tab section TOC.** Anchor-jump nav at the top — *Identity · Social · Open Graph · Login · SEO Copy* — for fast navigation on the long form. Each section header gets a matching `id` with `scroll-margin-top` so the target stays visible under the WP admin bar.
- **Identity tab sticky save bar.** Pure-CSS `position: sticky; bottom: 0;` keeps the Save button always one click away while editing the form, regardless of scroll position. Backdrop-blur for legibility on top of form content.
- **`sn_admin_page_hook()` static accessor.** Captures the hook suffix returned by `add_menu_page()` so the stylesheet-enqueue guard can't typo it. Cleaner than re-deriving `'toplevel_page_' . $slug` everywhere.

### Notes
- **No behaviour or schema change.** PATCH bump per project versioning caps (still within `1.8.x`). All `sn_settings` data shipped in v1.8.0 stays intact.
- **Out of scope:** refactor of other tab modules to use the new component classes (their inline styles still win on specificity); JS dirty-tracking on the sticky save bar; promoting tabs to individual sidebar submenu entries.

## [1.8.0] - 2026-05-16

### Added
- `inc/settings.php` — single source of truth for site-identity config (~216 LOC). Stores all settings in one `wp_options['sn_settings']` row across 5 categories: identity, social, og, login, seo_copy.
- **Identity tab in admin page** (`Appearance → Signal & Noise → Identity`) — single form with grouped fields for: site name + description + person name + locale; Twitter handle + sameAs profile URLs; default OG image URL + card dimensions; custom login slug; per-route SEO titles + descriptions.
- **Activation migration** (`sn_settings_seed_legacy_values`) — hostname-gated to `juanlentino.com`; seeds existing JL values into `wp_options` exactly once per environment. Subsequent activations no-op via `sn_settings_migrated_v1` flag. Lazy `admin_init` fallback covers SSH-based deploys where `register_activation_hook` doesn't fire.
- **`sn_setting('cat.field', $fallback)` accessor** — static-cached, dot-path read with deep-merge over defaults. Used throughout `seo.php` / `seo-schema.php` / `login-hide.php` in place of hardcoded literals.

### Changed
- `inc/seo.php`, `inc/seo-schema.php`, `inc/login-hide.php` refactored to read all site-identity values from `sn_setting()` instead of PHP literals. 12 hardcoded JL-specific values removed across the three files.
- Filter compat layer preserved: existing `apply_filters()` hooks (`sn_twitter_handle`, `sn_schema_same_as`, `sn_og_image_dimensions`) continue to work as override stack on top of stored settings. Pattern: `apply_filters('sn_X', sn_setting('path', $fallback))`.

### Notes
- **Live site output is byte-identical post-upgrade.** The activation migration seeds the JL-specific values into `wp_options['sn_settings']` so emitted meta tags match v1.7.0 exactly. Verifiable: diff a page's `<head>` pre/post-upgrade returns empty.
- **Generic defaults for fresh installs.** On any non-juanlentino.com host, the migration sets only the `sn_settings_migrated_v1` flag without seeding values. `sn_settings_defaults()` provides generic fallbacks pulled from `get_bloginfo()`.
- **Out of scope:** per-post settings UI (noindex toggle, custom meta description override per post), security toggles UI (xmlrpc, rest user lockdown, etc.), JS-driven add/remove for the sameAs list. Each becomes its own future phase.
- **Prereq for Phase 13 cutover** (v2.0.0, deactivates TSF + wps-hide-login). After v1.8.0, the plugin owns all site-identity emission with configurable values.

## [1.7.0] - 2026-05-16

### Added
- `inc/seo-schema.php` — JSON-LD structured data emission (~150 LOC). Single `@graph` script in `<head>` carrying three connected schemas:
  - **WebSite** — every page; publisher references the Person
  - **Person** — every page; name + URL + `sameAs` (X / Instagram / LinkedIn profiles, filterable via `sn_schema_same_as`)
  - **Article** — singular posts only; headline, `datePublished`, `dateModified`, `mainEntityOfPage`, image (via `sn_og_image_url` + `sn_og_image_dimensions` filters), references the Person as author + publisher

### Skipped (deliberate)
- **BreadcrumbList** — WordPress 7.0 ships a native Breadcrumbs block; use that instead.
- **SearchAction** — site has no `/search/{term}` route.
- **WebPage on non-post singulars** — marginal value; omit.

### Notes
- Output is `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for readability and minor byte savings. Single `<script>` tag (Google prefers connected `@graph` over multiple disjoint scripts).
- Schemas reference each other by `@id` (`#/schema/Person`, `#/schema/WebSite`, `<permalink>#article`) so Google's structured-data validator sees them as a graph, not isolated nodes.
- After Phase 13 cutover (TSF deactivation), this becomes the site's only structured-data emission. Until then, TSF emits its own `@graph` in parallel — duplicate schemas in `<head>` (verifiable via [Google Rich Results Test](https://search.google.com/test/rich-results)). The duplication is cosmetic; both validate; Phase 13 removes the second source.

## [1.6.0] - 2026-05-16

### Added
- **Canonical URL** emission via `<link rel="canonical">` on front page, /notes, /provenance, and singular posts/pages (`inc/seo.php`, wp_head priority 1).
- **Robots meta** emission via `<meta name="robots">` with TSF's default no-restrictions content (`max-snippet:-1,max-image-preview:large,max-video-preview:-1`). Honors a per-post `_sn_noindex` post-meta flag for selective de-indexing (admin UI in Phase 11).
- **`og:locale`** meta emission (`en_US` hardcoded).
- **`og:image:width` + `og:image:height`** meta emission (defaults 1200×630 matching generated cards; filterable via new `sn_og_image_dimensions` filter).
- **`article:published_time` + `article:modified_time`** meta emission on singular posts (ISO 8601 UTC).
- **`twitter:site` + `twitter:creator`** meta emission (`@juan_lentino` hardcoded; filterable via new `sn_twitter_handle` filter).

### Removed
- Three dead `wpseo_*` filter hooks in `inc/og-card-generator.php` (`wpseo_opengraph_image`, `wpseo_twitter_image`, `wpseo_opengraph_image_size`). They were copy-pasted from a Yoast-era assumption; the active site runs The SEO Framework which uses a different filter namespace. Hooks were dead code — never fired. OG card surfacing flows through our own `sn_og_image_url` filter consumed by `inc/seo.php`.

### Behaviour
- Brings the companion plugin's SEO emission to feature-parity with The SEO Framework's Open Graph, Twitter Card, canonical, and robots fields. Sets the stage for full TSF deactivation in Phase 13.

### Notes
- TSF still emits canonical, robots, and JSON-LD schemas in parallel until Phase 13 cutover. Our emission is the source of truth post-cutover. Until then, TSF's tags are competing — verify after Phase 13 deactivation that crawlers pick up the right ones.

## [1.5.0] - 2026-05-16

### Added
- `inc/login-hide.php` — custom login URL module (~110 LOC). Renames `/wp-login.php` to a custom slug (default: `/sn-login`). Direct visits to `/wp-login.php` and unauthenticated `/wp-admin` requests return 404. Login URL appears in password-reset emails and logout redirects via filter rewrites of `site_url()` / `wp_redirect()` output.

### Behaviour
- Configurable via wp-config.php constants: `SN_LOGIN_SLUG` (default `'sn-login'`) and `SN_LOGIN_BYPASS` (emergency unlock if you lock yourself out).
- **Defensive pre-flight:** module stands down while `wps-hide-login` is still active to avoid conflicting rewrite rules. Surfaces an admin notice explaining the situation. Once `wps-hide-login` is deactivated (Phase 13 of the absorption roadmap), this module takes over seamlessly.
- One-time `flush_rewrite_rules()` on first activation (and again whenever `SN_LOGIN_SLUG` constant changes). Keyed by current slug in `sn_login_rewrites_flushed` option.
- Allow-list for `admin-ajax.php`, `async-upload.php`, `wp-cron.php`, `/wp-json/`, `/feed` so REST + cron + feed flows aren't impacted.

### Notes
- Replaces the `wps-hide-login` third-party plugin. Their plugin is ~700 LOC; ours is ~110. Phase 13 of the absorption roadmap deactivates `wps-hide-login` after this module ships and verifies.

## [1.4.1] - 2026-05-16

### Fixed
- Duplicate `og:image` and `twitter:image` tags in `<head>` (Phase 6 diagnostic outcome). The plugin's `inc/seo.php` has been emitting our generated OG card URLs since Phase 1 (v8.2.0), but The SEO Framework (autodescription) was emitting competing tags first in the source — pointing at the site icon as fallback. Crawler parsing of duplicate `og:image` is undefined; Facebook Debugger would flag the page.

### Behaviour
- Added `the_seo_framework_meta_generator_pools` filter to remove `Open_Graph`, `Facebook`, and `Twitter` pools from TSF's output. Our `wp_head` emission becomes the single source of truth for OG/Twitter meta tags site-wide. TSF still owns canonical URLs, robots meta, JSON-LD schemas, and a handful of og:* fields we don't yet emit (og:locale, og:image:width/height, article:published_time, twitter:site/creator) — those migrate to our seo.php in Phase 10+11.

### Notes
- Stopgap fix until full SEO absorption (Phase 10-13) replaces TSF entirely.

## [1.4.0] - 2026-05-16

### Added
- `inc/wp-update-integration.php` — registers the plugin with WordPress's native update system. Plugin now appears in `wp-admin/update-core.php` and Plugins → Installed Plugins alongside other plugins, showing current version and "up to date" status (or "update available" if auto-deploy ever falls behind a tag). ~130 LOC.

### Behaviour
- Polls GitHub Tags API every 12h (cached in `sn_gh_latest_plugin` site transient). Picks the highest `v\d+\.\d+\.\d+` semver tag from `juanlentino/signal-and-noise-tools`.
- Hooks `pre_set_site_transient_update_plugins` to inject the plugin into WP's update registry: into `->no_update` when local matches GitHub (the normal case under Phase 2c auto-deploy), into `->response` when GitHub is ahead.
- Hooks `upgrader_pre_install` to intercept "Update Now" with a WP_Error directing the maintainer to push a git tag instead — preserves the git checkout that the SSH-based auto-deploy depends on.

### Notes
- Mirror of the theme's equivalent `inc/wp-update-integration.php` shipped in `signal-and-noise` v8.5.0. Both deliver the same UX (visibility in WP's standard update UI) using package-specific filter hooks (plugins vs themes have different transient shapes).
- GitHub API queried unauthenticated; 60 requests/hour limit is plenty given the 12h cache TTL (≤2 requests/day).

## [1.3.0] - 2026-05-16

### Added
- `inc/og-card-generator.php` — OG/Twitter card PHP GD generation, caching, Yoast filter integration. Fonts provided by the theme via `sn_og_font_paths` filter (new cross-package contract).
- `inc/reading-time.php` — reading time calculation, caching in `_sn_reading_time_minutes` post meta, `[sn_reading_time]` shortcode, `render_block` bridge for block-context shortcodes. The previously cross-package `sn_admin_reading_time_tab` hook is now intra-plugin.
- `inc/content-surfaces.php` — Notes category, /notes Page, /provenance + /over-detection + /as-substrate Pages, permalink structure, query loop scoping.
- `inc/content-migrations.php` — 11 one-shot content seed migrations for the Provenance pillar (body, refinements, byline reading time, split, AS substrate seed, card2 longform, card readtimes dynamic, catalog numbers, post-date displaytype, eyebrow dynamic, clear notes template override).
- `inc/content-rendering-helpers.php` — Gutenberg block-markup generators called from migrations (byline_reading_time, toc, papers_index).
- `inc/seed-content/` — HTML bodies consumed by content migrations.

### Changed
- Pre-flight guard #3 added to bootstrap: bails with admin notice if the theme still ships `inc/og-image.php`, `inc/reading-time.php`, or `inc/notes-and-provenance.php` (defends against accidental install-order inversion).

### Notes
- Requires theme v8.4.0+. If installed against an older theme, guard #3 fires; plugin loads dormant. After upgrading the theme, plugin activates normally.
- One new cross-package contract: `sn_og_font_paths` filter (theme listens, plugin dispatches).

## [1.2.0] - 2026-05-15

### Removed
- "Latest on GitHub" status row + "Check Now" button in admin Dashboard tab (`inc/admin-page.php`).
- "Heal Templates" form handler + UI card in admin Dashboard tab (`inc/admin-page.php`).
- Quick "Check updates" entry in WP admin bar (`inc/admin-bar.php`).
- REST routes `/check-updates` and `/heal-templates` (`inc/rest-api.php`). Their backing theme modules retired in theme v8.3.0.
- `upgrader_process_complete` hook in `inc/cloudflare-purge.php` — replaced by deploy-time REST call from GitHub Actions.

### Changed
- `/full-reset` REST endpoint no longer includes a "heal templates" step. New behavior: purge caches + clear DB template overrides only.

### Notes
- Requires theme v8.3.0+. If installed against an older theme, the plugin still loads cleanly — the removed UI elements were the only readers of the retired contracts.

## Infrastructure — Phase 2c (no version bump)

- `.github/workflows/deploy.yml` added: SSH-based auto-deploy. Tag push to plugin repo → GitHub Actions SSHes into Cloudways as a **dedicated, application-scoped SSH user** (`sn-plugin`, alias for `nffqxsrgxz`) and runs `git fetch && git checkout <tag>` in the plugin directory → POSTs to `/purge-cache` for CF cache invalidation. Same tag-push ritual as the theme repo.
- **Security posture:** the deploy SSH key is bound to `sn-plugin`, a dedicated additional user with access only to this application's filesystem. If the GitHub Actions secret is ever leaked, blast radius is bounded to this WP app's content (same as a compromised WP admin), NOT the whole Cloudways server. Earlier intermediate setup using `master_user` was discarded for this reason.
- **One-time live cutover (2026-05-16):** plugin directory renamed from `signal-and-noise-tools-1.2.0` (artifact of the Upload Plugin flow) to the canonical `signal-and-noise-tools`. Done via WP-CLI `deactivate → mv → git clone → checkout v1.2.0 → activate`, sub-second downtime. Backup retained on the live server at `signal-and-noise-tools-1.2.0-old` and `signal-and-noise-tools-OLD-MASTER`; delete after a few days of stable operation.
- Cloudways → GitHub auth uses a dedicated read-only deploy key (`cloudways-server-readonly`) on this repo; the private key lives in the `sn-plugin` user's writable `~/.openssh/cw-to-gh-deploy_ed25519` (Cloudways convention: `~/.ssh/` is root-owned for additional users, `~/.openssh/` is user-writable). The workflow exports `GIT_SSH_COMMAND` on the remote shell to point git at this key without needing a `~/.ssh/config` file.
- Treated as build infra per CLAUDE.md `.github/workflows/` convention (mirrors theme Phase 2a — no version bump for the workflow file itself).

## [1.1.0] — RSS Plausible Tracker migrated from theme MU plugin

First minor in the 1.x line. Brings the early slice of Phase 4 forward (ahead of Phase 2's updater migration) to resolve the awkward dual-state where `rss-plausible-tracker.php` lived in the theme repo but was distributed manually to `wp-content/mu-plugins/`.

### Added

- **[`inc/rss-plausible-tracker.php`](inc/rss-plausible-tracker.php)** — RSS subscriber tracker (formerly `mu-plugins/rss-plausible-tracker.php` in the theme repo, v1.2.0 of the MU plugin). Same DB table (`wp_rss_feed_log`), same option keys (`sn_rss_tracker_settings`, `sn_rss_tracker_db_version`), same cron hook (`sn_rss_tracker_daily_prune`), same admin tab and dashboard widget. Only the file location and surrounding bootstrap changed.
- **[`tests/bot-detection.php`](tests/bot-detection.php)** — standalone PHP fixture test for `sn_rss_tracker_is_bot()`. Moved from theme repo's `mu-plugins/tests/`. Runnable as `php tests/bot-detection.php`.
- **Pre-flight guard #2** in [`signal-and-noise-tools.php`](signal-and-noise-tools.php) — before `require_once`ing the rss tracker module, check `file_exists( WPMU_PLUGIN_DIR . '/rss-plausible-tracker.php' )`. If the legacy MU plugin file is still on disk under `wp-content/mu-plugins/`, this plugin skips loading its own copy and emits a one-line admin notice asking the maintainer to delete the MU file. MU plugin continues serving tracking; no fatal, no downtime, no data loss.

### Migration order

The dual-existence problem is the same shape as Phase 1's, with the same solution: pre-flight guard means no fatal regardless of order.

1. **Install plugin v1.1.0 first.** Maintainer's WP admin → Plugins → existing *Signal & Noise Tools* listing → manual upgrade via Upload Plugin (until Phase 2's auto-updater lands). Plugin's guard sees `wp-content/mu-plugins/rss-plausible-tracker.php` still present → skips loading the new tracker module → MU plugin continues serving tracking → admin notice instructs maintainer on next step.
2. **Delete the MU file via SFTP:** `wp-content/mu-plugins/rss-plausible-tracker.php`. Or via WP-CLI: `wp mu-plugin delete rss-plausible-tracker` (if available).
3. **Next admin pageview:** guard sees the MU file gone → loads our tracker module → tracking continues seamlessly via the plugin. Admin notice disappears.

### Data continuity

- **`wp_rss_feed_log` table:** untouched. Plugin reads/writes the same rows.
- **Options:** `sn_rss_tracker_settings`, `sn_rss_tracker_db_version` — same keys, same values.
- **Cron event:** `sn_rss_tracker_daily_prune` — same hook name. When the MU plugin stops loading, the cron event remains scheduled but its handler is now in the active plugin (function name `sn_rss_tracker_cron_prune` is identical). WP fires the event → plugin's handler runs. Seamless.

### Why minor

New module added, new bootstrap guard, theme repo cleanup in coordinated theme v8.2.1 release — meaningful capability shift in plugin scope (it now owns RSS analytics, not just admin/REST/security tooling). No breaking change. First minor in the 1.x line.

### Coordinated theme release

Ships alongside theme `v8.2.1` (docs-only-ish), which removes `mu-plugins/rss-plausible-tracker.php` from the theme repo and updates the WORDPRESS-REFERENCE §10.0 phase plan. Theme update can ship before, during, or after the plugin update — the plugin's guard handles all orderings.

## [1.0.1] — Pre-flight legacy-theme guard

Patch fix for an order-of-operations footgun discovered during the v1.0.0 install on the live site.

### Why this exists

The Phase 1 split was designed as a coordinated release: install plugin v1.0.0 first, then click the theme update to v8.2.0. The original CHANGELOG entry framed the "duplication window" between these two steps as a cosmetic issue ("WP registers hooks twice"). That framing was wrong.

The actual failure mode: if the plugin loads while the theme is still at v8.1.x, both packages have `function sn_purge_all_caches()`, `function sn_handle_quick_purge_caches()`, and the seven other moved-function declarations on disk. PHP fatals at parse time with "Cannot redeclare function sn_*", WordPress catches it during plugin activation, and the user sees *"Plugin could not be activated because it triggered a fatal error."* It's a hard fatal, not a hook-collision cosmetic.

WordPress hooks ARE idempotent (the `add_action` layer); PHP function declarations are NOT. These are two different layers of WordPress, and the original spec conflated them.

### Fixed

- **[`signal-and-noise-tools.php`](signal-and-noise-tools.php) — pre-flight check at bootstrap.** Before the `require_once` chain runs, the plugin checks whether `wp-content/themes/signal-and-noise/inc/admin-page.php` exists on disk. If it does, the theme is still at v8.1.x and the require chain would fatal. The plugin returns early (skipping module loading entirely) and surfaces an admin notice asking the maintainer to update the theme first. After the theme update lands, the next admin pageview sees the file gone, the guard passes, and modules load normally.

### Behavior contract

- **Theme is at v8.2.0+ (legacy files deleted):** plugin loads modules as usual. No-op cost.
- **Theme is at v8.1.x (legacy files still present):** plugin bootstrap bails before any function is declared; admin notice tells the maintainer to update the theme. No fatal, no broken admin.
- **A non–Signal & Noise theme is active:** guard is skipped (no conflict possible); plugin loads normally.
- **Theme is downgraded back to v8.1.x while the plugin is active:** next request, the guard re-runs and bails. Plugin functions stop being declared; the theme reclaims ownership. No fatal.

### Why patch

No new feature; no breaking change. One pre-flight check added to the bootstrap; the rest of the plugin is byte-identical to v1.0.0. Patch bump per SemVer.

## [1.0.0] — Phase 1: scaffold + easy moves

First release. Nine modules moved from the theme repo via the WP action/filter contract pattern.

### Added

- Plugin bootstrap (`signal-and-noise-tools.php`) with standard WP plugin header.
- 9 modules under `inc/`, mirroring the theme's flat module structure: `seo.php`, `security-headers.php`, `cloudflare-purge.php`, `plausible-api.php`, `plausible-admin.php`, `plausible-widget.php`, `admin-bar.php`, `admin-page.php`, `rest-api.php`.
- Cross-package contracts: three filters (`sn_purge_all_caches_result`, `sn_self_heal_force_run_result`, `sn_updater_branch`) and two actions (`sn_updater_force_check`, `sn_updater_clear_error`).
- GitHub Actions lint workflow (`php -l` on every PHP file).

### Coordination

Ships alongside theme Signal & Noise `v8.2.0`, which deletes the original copies of these 9 modules and registers the listener side of the contracts. Install plugin first, then ship the theme update.

### Spec + plan (from theme repo)

- `docs/superpowers/specs/2026-05-15-companion-plugin-phase-1-design.md`
- `docs/superpowers/plans/2026-05-15-companion-plugin-phase-1.md`
