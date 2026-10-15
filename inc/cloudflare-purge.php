<?php
/**
 * Signal & Noise — Cloudflare cache purge integration.
 *
 * Cloudflare's default cache profile only caches static assets
 * (.css, .js, .jpg, .png, .pdf, etc.) and explicitly NOT HTML.
 * To get HTML caching at the edge — which dramatically reduces
 * origin load and improves global TTFB — you have to opt in via
 * a Cache Rule in the Cloudflare dashboard (or pay for APO).
 *
 * Once HTML is cached, you also need an event-driven invalidation
 * mechanism so visitors see fresh content after edits. This module
 * provides that: API-driven purges of specific URLs on post saves
 * and a full zone purge on theme updates.
 *
 * Configuration: see docs/CACHING.md.
 *
 * Either configure via wp-config.php constants:
 *
 *   define( 'SN_CLOUDFLARE_API_TOKEN', 'cf-api-token-with-cache-purge-scope' );
 *   define( 'SN_CLOUDFLARE_ZONE_ID',   '32-char-zone-id' );
 *
 * Or via Appearance → Signal & Noise → Cloudflare. Constants take
 * precedence over options when both are set, so wp-config can lock
 * the value against accidental admin edits.
 *
 * Without both a token and a zone ID, all hooks no-op silently —
 * the module is fail-safe: if Cloudflare integration isn't set up,
 * the rest of the theme still works exactly as before.
 *
 * Security:
 *   - The token is stored as a non-autoloaded option so it isn't
 *     loaded into memory on every request, only when needed.
 *   - The settings UI obscures the saved value (shows last 4 chars).
 *   - All admin POST actions are nonce-protected.
 *   - The token never appears in error messages or logs from this
 *     module — only in raw `wp_remote_post` traffic to Cloudflare's
 *     API endpoint.
 *
 * @package SignalNoise
 * @since 6.5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CF_TOKEN_OPT       = 'sn_cf_api_token';
const SN_CF_ZONE_OPT        = 'sn_cf_zone_id';
const SN_CF_LAST_PURGE_OPT  = 'sn_cf_last_purge';
// 19.2.0: the last FULL zone purge, kept apart because every per-post purge
// overwrites SN_CF_LAST_PURGE_OPT. Attention reads it to retire a stale edge
// verdict that a later zone purge superseded.
const SN_CF_LAST_ZONE_PURGE_OPT = 'sn_cf_last_zone_purge';
const SN_CF_API_BASE        = 'https://api.cloudflare.com/client/v4';

require_once __DIR__ . '/cloudflare-purge-send.php'; // blocking send, retry, failure record (20.9.0).
require_once __DIR__ . '/cloudflare-purge-save.php'; // a note save that changed no list purges that note's tags (2026-10-13).
require_once __DIR__ . '/cloudflare-purge-words.php'; // the Cache box's shared phrases (2026-10-13).
require_once __DIR__ . '/cloudflare-purge-site-editor.php'; // a menu, template or template part save purges the whole-site tag (2026-10-13).

// The rest of the module lives in three parts files (house line cap, 2026-10-15),
// loaded in the order it always ran so every hook registration keeps its place.
require_once __DIR__ . '/cloudflare-purge-api-parts.php';
require_once __DIR__ . '/cloudflare-purge-post-parts.php';
require_once __DIR__ . '/cloudflare-purge-tab-parts.php';
