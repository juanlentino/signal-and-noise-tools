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
const SN_CF_API_BASE        = 'https://api.cloudflare.com/client/v4';

/**
 * Resolve the active token. Constant wins over option when set.
 *
 * @return string Empty string if neither configured.
 */
function sn_cf_get_token() {
	if ( defined( 'SN_CLOUDFLARE_API_TOKEN' ) && SN_CLOUDFLARE_API_TOKEN ) {
		return (string) SN_CLOUDFLARE_API_TOKEN;
	}
	return (string) get_option( SN_CF_TOKEN_OPT, '' );
}

/**
 * Resolve the active zone ID. Constant wins over option when set.
 *
 * @return string Empty string if neither configured.
 */
function sn_cf_get_zone() {
	if ( defined( 'SN_CLOUDFLARE_ZONE_ID' ) && SN_CLOUDFLARE_ZONE_ID ) {
		return (string) SN_CLOUDFLARE_ZONE_ID;
	}
	return (string) get_option( SN_CF_ZONE_OPT, '' );
}

/**
 * True if both token and zone are configured.
 *
 * @return bool
 */
function sn_cf_is_configured() {
	return '' !== sn_cf_get_token() && '' !== sn_cf_get_zone();
}

/**
 * Purge a list of specific URLs from Cloudflare's edge cache.
 *
 * Fire-and-forget (non-blocking); we don't want a slow CF API
 * response to delay an admin save. Caller doesn't get a success
 * signal — but failures are logged via the SN_CF_LAST_PURGE_OPT
 * option (timestamp + status) for the admin UI to display.
 *
 * @param string[] $urls Absolute URLs to purge. Filters out anything
 *                       that isn't a non-empty string.
 * @return bool true if request was dispatched, false if not configured
 *              or no valid URLs remain.
 */
function sn_cf_purge_urls( $urls ) {
	if ( ! sn_cf_is_configured() ) {
		return false;
	}
	$urls = array_values( array_unique( array_filter( (array) $urls, function( $u ) {
		return is_string( $u ) && '' !== $u;
	} ) ) );
	if ( empty( $urls ) ) {
		return false;
	}

	// Cloudflare's cache purge endpoint accepts up to 30 URLs per call.
	$chunks = array_chunk( $urls, 30 );
	foreach ( $chunks as $chunk ) {
		sn_cf_api_post(
			'/zones/' . sn_cf_get_zone() . '/purge_cache',
			array( 'files' => $chunk )
		);
	}

	update_option( SN_CF_LAST_PURGE_OPT, array(
		'time'  => time(),
		'kind'  => 'urls',
		'count' => count( $urls ),
	), false );

	return true;
}

/**
 * Purge the entire zone. Used on theme updates where it's hard to
 * enumerate every URL whose markup might have shifted.
 *
 * @return bool true if request was dispatched, false if not configured.
 */
function sn_cf_purge_everything() {
	if ( ! sn_cf_is_configured() ) {
		return false;
	}

	sn_cf_api_post(
		'/zones/' . sn_cf_get_zone() . '/purge_cache',
		array( 'purge_everything' => true )
	);

	update_option( SN_CF_LAST_PURGE_OPT, array(
		'time' => time(),
		'kind' => 'all',
	), false );

	return true;
}

/**
 * Internal: fire a non-blocking POST against the Cloudflare API.
 * Caller passes a path (starting with /) and a body array.
 *
 * @param string $path
 * @param array  $body
 */
function sn_cf_api_post( $path, $body ) {
	wp_remote_post( SN_CF_API_BASE . $path, array(
		'headers'  => array(
			'Authorization' => 'Bearer ' . sn_cf_get_token(),
			'Content-Type'  => 'application/json',
		),
		'body'     => wp_json_encode( $body ),
		'timeout'  => 5,
		'blocking' => false,
		'sslverify' => true,
		// v8.7.1 (CMA audit INFO-1): a Bearer credential is attached to a fixed API
		// host, so forbid following any 3xx that would re-send it — matching the
		// sn_uptime_status_api_get() outbound-hardening convention.
		'redirection' => 0,
	) );
}

/**
 * v8.7.0 (verified-purge Tier-1): fire a BLOCKING POST against the Cloudflare API
 * and read the real response. The fast auto-purge path stays non-blocking
 * (sn_cf_api_post); this variant is used only by the verified manual purge so the
 * per-leg report can carry a genuine accept-confirmation.
 *
 * @param string $path CF API path (starting with /).
 * @param array  $body Request body.
 * @return array{http:int,cf_success:bool} HTTP code + whether CF's body said {success:true}.
 */
function sn_cf_api_post_blocking( $path, $body ) {
	$res  = wp_remote_post( SN_CF_API_BASE . $path, array(
		'headers'  => array(
			'Authorization' => 'Bearer ' . sn_cf_get_token(),
			'Content-Type'  => 'application/json',
		),
		'body'      => wp_json_encode( $body ),
		'timeout'   => 8,
		'blocking'  => true,
		'sslverify' => true,
		'redirection' => 0, // v8.7.1 (CMA INFO-1): never re-send the Bearer on a 3xx.
	) );
	$http = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$data = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
	return array(
		'http'       => $http,
		'cf_success' => ( 200 === $http ) && ! empty( $data['success'] ),
	);
}

/**
 * v8.7.0 (verified-purge Tier-1): full-zone purge with a blocking
 * accept-confirmation, for the verified manual "Purge All Caches" path.
 *
 * Returns the shape the theme's report writer stashes + records:
 *   accepted   — CF took the request at the HTTP layer (200).
 *   http       — the HTTP status (0 on transport error).
 *   cf_success — CF's body confirmed {success:true}.
 * Unconfigured ⇒ no HTTP call, all-false (fail-safe like sn_cf_purge_everything).
 *
 * @return array{accepted:bool,http:int,cf_success:bool}
 */
function sn_cf_purge_everything_verified() {
	if ( ! sn_cf_is_configured() ) {
		return array( 'accepted' => false, 'http' => 0, 'cf_success' => false );
	}

	$r   = sn_cf_api_post_blocking(
		'/zones/' . sn_cf_get_zone() . '/purge_cache',
		array( 'purge_everything' => true )
	);
	$out = array(
		'accepted'   => ( 200 === $r['http'] ),
		'http'       => $r['http'],
		'cf_success' => $r['cf_success'],
	);

	update_option( SN_CF_LAST_PURGE_OPT, array(
		'time'       => time(),
		'kind'       => 'all',
		'verified'   => true,
		'cf_success' => $out['cf_success'],
	), false );

	return $out;
}

/**
 * Auto-purge: when a published post is saved, purge that post's URL
 * plus the index URLs that may list/link it (homepage, /notes/,
 * /provenance/, RSS feed). Skips revisions, autosaves, and non-
 * published statuses — only "publish" transitions trigger purges.
 *
 * Filterable: `sn_cf_purge_urls_for_post` lets future code add or
 * remove URLs from the purge list (e.g., taxonomy archives).
 */
add_action( 'wp_after_insert_post', function( $post_id, $post, $update, $post_before ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( ! sn_cf_is_configured() ) {
		return;
	}

	// v10.52.6: a Note entering publish for the FIRST time changes every page on
	// the site, not just its own. Each rendered page embeds the site-wide notes
	// index (the `{"t":…,"u":…}` list that feeds search and related-notes), so a
	// new publication makes every cached page's copy of that index incomplete.
	// The URL list below cannot express that, and the freshness probe cannot see
	// it either — it checks /, /notes/ and /provenance/, never an individual
	// Note. Measured 2026-08-05: the cached copy of a Note was 104,516 bytes
	// against 115,431 from origin, and its embedded index was missing an entry.
	//
	// So a first publication purges the zone; an ORDINARY EDIT keeps the narrow
	// list, because an edit changes that Note's page and its listings, not the
	// index every other page carries. New publications run about one a week
	// here, so this is a rare full purge, not a routine one.
	//
	// The test is a transition INTO publish, not $update: $update is true for a
	// draft that already existed as a row, which is exactly the case that adds a
	// new entry to the index.
	$was_published = ( $post_before instanceof WP_Post ) && 'publish' === $post_before->post_status;
	if ( ! $was_published ) {
		/**
		 * Filters whether a first publication purges the whole zone.
		 *
		 * @since 10.52.6
		 *
		 * @param bool    $purge_all Whether to purge everything.
		 * @param int     $post_id   Post ID.
		 * @param WP_Post $post      Post object.
		 */
		if ( apply_filters( 'sn_cf_purge_all_on_first_publish', true, $post_id, $post ) ) {
			sn_cf_purge_everything();
			return;
		}
	}

	$urls = array(
		get_permalink( $post_id ),
		home_url( '/' ),
		home_url( '/notes/' ),
		home_url( '/provenance/' ),
		home_url( '/notes/feed/' ),
	);

	// If the saved post is a child page (e.g., /provenance/over-detection/),
	// also purge the parent so its referring listings refresh.
	$parent_id = (int) $post->post_parent;
	if ( $parent_id ) {
		$urls[] = get_permalink( $parent_id );
	}

	$urls = apply_filters( 'sn_cf_purge_urls_for_post', $urls, $post_id, $post );
	sn_cf_purge_urls( $urls );

	// v11.10.0: the purge above is fire-and-forget and CANNOT report whether it
	// worked. On 2026-08-15 three of them ran against one Note and the edge kept
	// serving a 27-hour-old render for fifty minutes; the public provenance
	// ledger went red for it three times while this code path looked healthy.
	// Schedule a probe that checks the URL a reader would actually get, and
	// escalates once to a zone purge if it is still stale. Rescheduling is
	// deliberate: three saves in ten minutes should probe once, after the last.
	if ( function_exists( 'wp_schedule_single_event' ) && defined( 'SN_CF_PROBE_HOOK' ) ) {
		$args = array( $post_id );
		$next = wp_next_scheduled( SN_CF_PROBE_HOOK, $args );
		if ( $next ) {
			wp_unschedule_event( $next, SN_CF_PROBE_HOOK, $args );
		}
		wp_schedule_single_event( time() + SN_CF_PROBE_DELAY, SN_CF_PROBE_HOOK, $args );
	}
}, 30, 4 );

/**
 * Admin UI for the Cloudflare tab. Lets the user save the API token +
 * zone ID and trigger a manual full-zone purge.
 *
 * Hooked to the dedicated `sn_admin_cloudflare_tab` action emitted by
 * inc/admin-page.php when the user selects the Cloudflare tab. This UI
 * was once a card on the Dashboard tab via `sn_admin_dashboard_extras`,
 * moved to its own tab as the Dashboard grew unwieldy and each subsystem
 * earned a dedicated tab.
 */
add_action( 'sn_admin_cloudflare_tab', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// POST handling lives in sn_handle_admin_post() (admin_init, PRG).
	// This callback is render-only.
	$token            = sn_cf_get_token();
	$zone             = sn_cf_get_zone();
	$token_obscured   = sn_mask_secret( $token );
	$token_const_set  = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const_set   = defined( 'SN_CLOUDFLARE_ZONE_ID' );
	$both_locked      = $token_const_set && $zone_const_set;
	$last_purge       = get_option( SN_CF_LAST_PURGE_OPT, array() );
	$is_configured    = sn_cf_is_configured();

	echo '<p class="sn-prose">Auto-purges Cloudflare\'s edge cache when content changes. See <code>docs/CACHING.md</code> for the dashboard-side Cache Rule that turns on HTML caching to begin with: without that, this module purges nothing useful (origin pages aren\'t cached at the edge).</p>';

	// Phase 3 (v6.45.0): full-width two-column shell — credentials (the work) in
	// the main column, the module status + manual-purge action in the rail.
	sn_admin_shell_open();

	// ── MAIN: CREDENTIALS FIELDSET ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Credentials</h2>';
	echo '<p class="sn-fieldset-intro">API token + zone ID from your Cloudflare dashboard. Both required.</p>';

	// API Token
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="sn_cf_token">API token</label>';
	if ( $token_const_set ) {
		echo '<input type="text" id="sn_cf_token" value="' . esc_attr( $token_obscured ? $token_obscured : '••••' ) . '" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked.</strong> Set via <code>SN_CLOUDFLARE_API_TOKEN</code> in <code>wp-config.php</code>.</p>';
	} else {
		echo '<input type="text" id="sn_cf_token" name="sn_cf_token" value="' . esc_attr( $token_obscured ) . '" placeholder="Paste a fresh token to update; type ‘clear’ to remove" class="sn-mono">';
		echo '<p class="sn-field-helper">Cloudflare API token with <code>Cache Purge</code> permission scoped to your zone. Leave the obscured value alone to keep the existing token.</p>';
	}
	echo '</div>';

	// Zone ID
	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="sn_cf_zone">Zone ID</label>';
	if ( $zone_const_set ) {
		echo '<input type="text" id="sn_cf_zone" value="' . esc_attr( $zone ) . '" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked.</strong> Set via <code>SN_CLOUDFLARE_ZONE_ID</code> in <code>wp-config.php</code>.</p>';
	} else {
		echo '<input type="text" id="sn_cf_zone" name="sn_cf_zone" value="' . esc_attr( $zone ) . '" placeholder="Paste zone ID; type ‘clear’ to remove" class="sn-mono">';
		echo '<p class="sn-field-helper">32-char zone ID from Cloudflare dashboard → site overview → API.</p>';
	}
	echo '</div>';

	if ( ! $both_locked ) {
		echo '<div class="sn-fieldset-actions">';
		echo '<button type="submit" name="sn_action" value="cf_save" class="button button-primary">Save</button>';
		echo '</div>';
	}

	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── POST-PURGE PROBES ────────────────────────────────────────────────
	// v13.71.2. Shipped one release earlier into the RAIL, with three class
	// names (.sn-table, .sn-rail-h, .sn-rail-note) that do not exist in
	// assets/admin.css — so it rendered as an unstyled browser-default table,
	// twenty full URLs wide, inside a column the shell sizes at 1fr against the
	// main column's 1.7fr. Owner-reported: "no format at all", and the rail
	// "a bit crammed". Both were the same mistake: inventing a component for a
	// surface that already has one, and putting a twenty-row data grid in the
	// summary column.
	//
	// It lives in the MAIN column now and uses core's widefat/striped, the same
	// classes the analytics tables in this plugin already use — real formatting,
	// no new CSS, and it looks like wp-admin because it IS wp-admin. The host is
	// stripped from each row: it repeats twenty times and eats the width that
	// the path actually needs.
	//
	// Worth knowing while reading these: this detector HAS been wrong wholesale
	// before. Until v11.29.1 it compared whole documents, and Breeze injects a
	// script on the cache-busted fetch only, so every probe read stale and the
	// log showed 11 of 11 — not a stale edge, a detector that could not return
	// anything else. Rows carry the algorithm that produced them for exactly
	// that reason, and a pre-v11.29.1 row says so rather than being counted.
	$probe_log = defined( 'SN_CF_PROBE_LOG_OPT' ) ? get_option( SN_CF_PROBE_LOG_OPT, array() ) : array();
	$probe_log = is_array( $probe_log ) ? $probe_log : array();
	if ( $is_configured && $probe_log ) {
		// v13.72.1: FOLDED, and open only when it is the task. Twenty rows sat
		// expanded under a two-field credentials form nobody comes here to read
		// them from — the panel answers "did purges work", and the answer is one
		// line until it isn't. Uses the shared .sn-disclosure caret rather than
		// the browser triangle; the summary carries the counts so a collapsed
		// fold still says what it is hiding.
		$probe_stale  = 0;
		$probe_newest = '';
		foreach ( $probe_log as $r_i => $r_row ) {
			if ( ! is_array( $r_row ) ) {
				continue;
			}
			if ( '' === $probe_newest ) {
				$probe_newest = (string) ( $r_row['result'] ?? '' );
			}
			if ( 'stale' === (string) ( $r_row['result'] ?? '' ) ) {
				++$probe_stale;
			}
		}
		// Open when the NEWEST probe is stale: that is a live condition. A stale
		// count with a fresh newest is history, and history stays folded.
		echo '<details class="sn-fieldset sn-disclosure"' . ( 'stale' === $probe_newest ? ' open' : '' ) . '>';
		echo '<summary><span class="sn-fieldset-h">Post-purge probes</span> '
			. '<span class="sn-pill ' . ( 'stale' === $probe_newest ? 'sn-pill--warn' : 'sn-pill--muted' ) . '">'
			. esc_html( sprintf(
				/* translators: 1: probes retained, 2: how many of them were stale */
				_n( '%1$d retained, %2$d stale', '%1$d retained, %2$d stale', count( $probe_log ), 'signal-and-noise-tools' ),
				count( $probe_log ),
				$probe_stale
			) ) . '</span></summary>';
		echo '<p class="sn-fieldset-intro">Each row is one check of the page a reader would actually get, '
			. (int) SN_CF_PROBE_DELAY . ' seconds after its purge. A stale row escalated to a full zone purge at the time,'
			. ' so it records a purge that needed a second attempt — not a page still stale now.</p>';
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th scope="col">When</th><th scope="col">Result</th><th scope="col">Page</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $probe_log, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$r_time  = (int) ( $row['time'] ?? 0 );
			$r_res   = (string) ( $row['result'] ?? '' );
			$r_stale = 'stale' === $r_res;
			$r_pill  = $r_stale ? 'sn-pill sn-pill--warn' : ( 'fresh' === $r_res ? 'sn-pill sn-pill--ok' : 'sn-pill sn-pill--muted' );
			$r_label = '' !== $r_res ? $r_res : 'unknown';
			if ( $r_stale && ! empty( $row['escalated'] ) ) {
				$r_label .= ' → zone purge';
			}
			// The URL minus the host: twenty identical origins is twenty times
			// the width and none of the information.
			$r_url  = (string) ( $row['url'] ?? '' );
			$r_path = '' !== $r_url ? (string) wp_parse_url( $r_url, PHP_URL_PATH ) : '';
			if ( '' === $r_path ) {
				// A manual zone purge names no single page; say which purge it was.
				$r_path = '' !== (string) ( $row['source'] ?? '' ) ? (string) $row['source'] : '—';
			}
			$r_old = defined( 'SN_CF_PROBE_ALGO' ) && (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO;
			echo '<tr>';
			echo '<td>' . esc_html( $r_time ? human_time_diff( $r_time, time() ) . ' ago' : '—' ) . '</td>';
			echo '<td><span class="' . esc_attr( $r_pill ) . '">' . esc_html( $r_label ) . '</span>'
				. ( $r_old ? ' <span class="sn-pill sn-pill--muted">retired detector</span>' : '' ) . '</td>';
			echo '<td><code>' . esc_html( $r_path ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</details>';
	}

	// ── RAIL: module status + manual purge action ──
	sn_admin_shell_rail( 'Cache status' );

	if ( $is_configured ) {
		$last_line = '';
		if ( ! empty( $last_purge['time'] ) ) {
			$ago       = human_time_diff( (int) $last_purge['time'], time() );
			$kind      = ( ( $last_purge['kind'] ?? '' ) === 'all' ) ? 'full zone' : ( (int) ( $last_purge['count'] ?? 0 ) ) . ' URL(s)';
			$last_line = ' Last purge: ' . esc_html( $ago ) . ' ago (' . esc_html( $kind ) . ').';
		}
		echo '<div class="sn-status-box">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Configured: auto-purge active</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $last_line is static text plus esc_html()-escaped values.
		echo '<p class="sn-status-box-body">Cache purges fire automatically on post save, theme update, and via the REST endpoint.' . $last_line . '</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--ok">Active</span>';
		echo '</div>';

	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Not configured</p>';
		echo '<p class="sn-status-box-body">Auto-purge disabled. Set both the API token and zone ID in the main column to activate.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	// ── MANUAL PURGE ACTION CARD ──
	echo '<form method="post" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong>Purge Everything Now</strong>';
	echo '<p class="sn-helper">Clears the entire Cloudflare zone cache. Use after manual edits to global elements.</p>';
	echo '<button type="submit" name="sn_action" value="cf_purge_now" class="button"' . ( $is_configured ? '' : ' disabled' ) . '>Purge Cloudflare</button>';
	echo '</form>';

	// ── CLOUDWAYS PURGE STATUS (render hardening FIX 3b) ──
	// Cloudways purge rides this SAME purge chain (breeze_clear_varnish — see
	// inc/cloudways-purge.php); surfaced here rather than invisible, so a failed
	// purge (e.g. a Cloudways API 422 field-validation error) is visible right
	// next to the rest of the pipeline instead of a silent ok:false in an option
	// no screen ever read.
	if ( function_exists( 'sn_cloudways_is_configured' ) && sn_cloudways_is_configured() ) {
		$cw_last      = defined( 'SNT_CW_LAST_PURGE_OPT' ) ? get_option( SNT_CW_LAST_PURGE_OPT, array() ) : array();
		$cw_attempted = ! empty( $cw_last['time'] );
		$cw_ok        = ! empty( $cw_last['ok'] );
		$cw_warn      = $cw_attempted && ! $cw_ok;
		$cw_line      = '';
		if ( $cw_attempted ) {
			$cw_line = ' Last attempt: ' . esc_html( human_time_diff( (int) $cw_last['time'], time() ) ) . ' ago.';
			if ( $cw_warn ) {
				$cw_line .= ' HTTP ' . esc_html( (string) ( $cw_last['http'] ?? 0 ) );
				if ( '' !== trim( (string) ( $cw_last['error'] ?? '' ) ) ) {
					// $cw_last['error'] is already wp_strip_all_tags()+300-char bounded
					// at capture time (inc/cloudways-purge.php); esc_html() here is the
					// output-context escape, not a second sanitize pass.
					$cw_line .= ': ' . esc_html( (string) $cw_last['error'] );
				}
			}
		}
		echo '<div class="sn-status-box' . ( $cw_warn ? ' sn-status-box--warn' : '' ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Cloudways purge</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cw_line is static text plus esc_html()-escaped values.
		echo '<p class="sn-status-box-body">Rides the same purge chain (Varnish leg).' . $cw_line . '</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . ( $cw_warn ? 'warn' : 'ok' ) . '">' . ( $cw_warn ? 'Error' : ( $cw_attempted ? 'OK' : 'Active' ) ) . '</span>';
		echo '</div>';
	}

	sn_admin_shell_close();
} );
