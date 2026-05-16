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
	) );
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
}, 30, 4 );

/**
 * Auto-purge: when this theme is updated via the WP upgrader, purge
 * the entire zone. Theme updates can change global elements (header,
 * footer, navigation, design tokens) so per-URL purges aren't
 * sufficient.
 */
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
	if ( ( $options['type'] ?? '' ) !== 'theme' ) {
		return;
	}
	$theme_slug = get_option( 'stylesheet' );
	$updated    = $options['themes'] ?? ( isset( $options['theme'] ) ? array( $options['theme'] ) : array() );
	if ( ! in_array( $theme_slug, $updated, true ) ) {
		return;
	}
	if ( ! sn_cf_is_configured() ) {
		return;
	}
	sn_cf_purge_everything();
}, 30, 2 );

/**
 * Admin UI for the Cloudflare tab. Lets the user save the API token +
 * zone ID and trigger a manual full-zone purge.
 *
 * Hooked to the dedicated `sn_admin_cloudflare_tab` action emitted by
 * inc/admin-page.php when the user selects the Cloudflare tab. Pre-
 * v7.0.x this UI was on the Dashboard tab via `sn_admin_dashboard_extras`,
 * but the Dashboard grew unwieldy as features accumulated; splitting
 * subsystems into their own tabs is the v7.0.x reorg.
 */
add_action( 'sn_admin_cloudflare_tab', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notices = array();

	// Handle save action.
	$posted_action = isset( $_POST['sn_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_action'] ) ) : '';
	if ( 'cf_save' === $posted_action
		&& check_admin_referer( 'sn_theme_options_nonce', '_wpnonce', false ) ) {

		$token_constant_set = defined( 'SN_CLOUDFLARE_API_TOKEN' );
		$zone_constant_set  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

		if ( ! $token_constant_set ) {
			$new_token = isset( $_POST['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_token'] ) ) : '';
			// Empty submission with non-empty placeholder = leave existing alone.
			// Explicit clear: user types literal "clear".
			if ( 'clear' === $new_token ) {
				delete_option( SN_CF_TOKEN_OPT );
			} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
				update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
			}
		}
		if ( ! $zone_constant_set ) {
			$new_zone = isset( $_POST['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_zone'] ) ) : '';
			if ( 'clear' === $new_zone ) {
				delete_option( SN_CF_ZONE_OPT );
			} elseif ( '' !== $new_zone ) {
				update_option( SN_CF_ZONE_OPT, $new_zone, true );
			}
		}
		$notices[] = array( 'success', 'Cloudflare settings saved.' );
	}

	// Handle manual purge.
	if ( 'cf_purge_now' === $posted_action
		&& check_admin_referer( 'sn_theme_options_nonce', '_wpnonce', false ) ) {
		if ( sn_cf_purge_everything() ) {
			$notices[] = array( 'success', 'Cloudflare zone purge dispatched.' );
		} else {
			$notices[] = array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' );
		}
	}

	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible" style="margin:1em 0;"><p>' . esc_html( $n[1] ) . '</p></div>';
	}

	$token            = sn_cf_get_token();
	$zone             = sn_cf_get_zone();
	$token_obscured   = '' === $token ? '' : '••••' . substr( $token, -4 );
	$token_const_set  = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const_set   = defined( 'SN_CLOUDFLARE_ZONE_ID' );
	$last_purge       = get_option( SN_CF_LAST_PURGE_OPT, array() );
	$is_configured    = sn_cf_is_configured();

	// No top-level heading here — the "Cloudflare" tab name in the
	// nav above is sufficient label. Just an intro paragraph.
	echo '<p style="color:#666;font-size:0.95em;max-width:680px;margin-top:0;">Auto-purges Cloudflare\'s edge cache when content changes. See <code>docs/CACHING.md</code> for the dashboard-side Cache Rule that turns on HTML caching to begin with — without that, this module purges nothing useful (origin pages aren\'t cached at the edge).</p>';

	echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:680px;margin-top:1em;">';
	wp_nonce_field( 'sn_theme_options_nonce' );

	// Token input
	echo '<p style="margin:0 0 0.4em;"><strong>API Token</strong></p>';
	if ( $token_const_set ) {
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 1em;">Set via <code>SN_CLOUDFLARE_API_TOKEN</code> in wp-config.php (constant takes precedence over this option).</p>';
	} else {
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 0.4em;">A Cloudflare API token with <code>Cache Purge</code> permission scoped to your zone.</p>';
		echo '<input type="text" name="sn_cf_token" value="' . esc_attr( $token_obscured ) . '" placeholder="Paste a fresh token to update; type \'clear\' to remove" style="width:100%;font-family:monospace;font-size:0.85em;margin-bottom:1em;">';
	}

	// Zone input
	echo '<p style="margin:0 0 0.4em;"><strong>Zone ID</strong></p>';
	if ( $zone_const_set ) {
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 1em;">Set via <code>SN_CLOUDFLARE_ZONE_ID</code> in wp-config.php.</p>';
	} else {
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 0.4em;">32-char zone ID from Cloudflare dashboard → site overview → API.</p>';
		echo '<input type="text" name="sn_cf_zone" value="' . esc_attr( $zone ) . '" placeholder="Paste zone ID; type \'clear\' to remove" style="width:100%;font-family:monospace;font-size:0.85em;margin-bottom:1em;">';
	}

	if ( ! ( $token_const_set && $zone_const_set ) ) {
		echo '<p style="margin:0.8em 0 0;"><button type="submit" name="sn_action" value="cf_save" class="button button-primary">Save Cloudflare Settings</button></p>';
	}

	echo '</form>';

	// Status & manual purge
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;margin-top:1em;">';
	echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:300px;">';
	echo '<strong style="display:block;margin-bottom:4px;">Status</strong>';
	if ( $is_configured ) {
		echo '<p style="color:#00a32a;font-size:0.85em;margin:0 0 8px;">&#10003; Configured — auto-purge active</p>';
	} else {
		echo '<p style="color:#dba617;font-size:0.85em;margin:0 0 8px;">Not configured — auto-purge disabled</p>';
	}
	if ( ! empty( $last_purge['time'] ) ) {
		$ago = human_time_diff( (int) $last_purge['time'], time() );
		$kind = ( ( $last_purge['kind'] ?? '' ) === 'all' ) ? 'full zone' : ( (int) ( $last_purge['count'] ?? 0 ) ) . ' URL(s)';
		echo '<p style="color:#666;font-size:0.8em;margin:0;">Last purge: ' . esc_html( $ago ) . ' ago (' . esc_html( $kind ) . ')</p>';
	}
	echo '</div>';

	echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:300px;">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong style="display:block;margin-bottom:4px;">Purge Everything Now</strong>';
	echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Clears the entire Cloudflare zone cache. Use after manual edits to global elements.</p>';
	echo '<button type="submit" name="sn_action" value="cf_purge_now" class="button"' . ( $is_configured ? '' : ' disabled' ) . '>Purge Cloudflare</button>';
	echo '</form>';
	echo '</div>';
} );
