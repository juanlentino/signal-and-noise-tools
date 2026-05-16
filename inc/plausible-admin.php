<?php
/**
 * Signal & Noise — Plausible admin tab.
 *
 * Manages the Stats API key used by inc/plausible-api.php to power the
 * four dashboard widgets in inc/plausible-widget.php. Storage mirrors
 * the Cloudflare-token pattern in inc/cloudflare-purge.php:
 *
 *   - Constant SN_PLAUSIBLE_STATS_TOKEN in wp-config.php  (preferred)
 *   - Option   sn_plausible_stats_token (this tab)        (admin-saved)
 *   - Plugin's plausible_analytics_settings.api_token     (fallback)
 *
 * The constant takes precedence over the option, so wp-config can lock
 * the value against accidental admin edits when desired. Non-autoloaded
 * option so the token isn't in memory on every request.
 *
 * UI surfaces:
 *   - Status card  — domain, current token source, last-call result
 *   - Token form   — paste/update/clear (hidden when constant is set)
 *   - Test button  — fires a synchronous aggregate call, reports outcome
 *   - Embedded     — link to the Plausible plugin's in-admin stats page
 *
 * @package SignalNoise
 * @since 7.2.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_plausible_tab', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notices       = array();
	$posted_action = isset( $_POST['sn_action'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_action'] ) ) : '';

	// ── SAVE ──
	if ( 'pl_save' === $posted_action
		&& check_admin_referer( 'sn_theme_options_nonce', '_wpnonce', false )
		&& ! defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) ) {

		$new_token = isset( $_POST['sn_pl_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_pl_token'] ) ) : '';
		// Empty submission with the obscured placeholder = leave existing
		// alone. Explicit clear: user types literal "clear".
		if ( 'clear' === $new_token ) {
			delete_option( SN_PLAUSIBLE_TOKEN_OPT );
			sn_pl_admin_invalidate_caches();
			$notices[] = array( 'success', 'Stats API key cleared. Caches purged.' );
		} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
			update_option( SN_PLAUSIBLE_TOKEN_OPT, $new_token, false ); // not autoloaded
			sn_pl_admin_invalidate_caches();
			$notices[] = array( 'success', 'Stats API key saved. Caches purged — widgets refresh on next dashboard view.' );
		}
	}

	// ── TEST ──
	if ( 'pl_test' === $posted_action
		&& check_admin_referer( 'sn_theme_options_nonce', '_wpnonce', false ) ) {

		$cfg = sn_plausible_config();
		if ( ! $cfg ) {
			$notices[] = array( 'error', 'Plausible not fully configured (missing domain or token).' );
		} else {
			delete_transient( SN_PLAUSIBLE_ERR_KEY ); // force-fresh
			$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
			if ( is_array( $result ) ) {
				$visitors  = (int) ( $result['visitors']['value'] ?? 0 );
				$notices[] = array( 'success', '&#10003; API call succeeded — ' . number_format_i18n( $visitors ) . ' visitor(s) in last 7 days.' );
			} else {
				$err       = sn_plausible_last_error();
				$detail    = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
				$notices[] = array( 'error', '&#10005; API call failed &mdash; ' . $detail );
			}
		}
	}

	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible" style="margin:1em 0;"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
	}

	// ── STATE ──
	$constant_set    = defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN;
	$option_token    = (string) get_option( SN_PLAUSIBLE_TOKEN_OPT, '' );
	$plugin_settings = get_option( 'plausible_analytics_settings', array() );
	$plugin_domain   = is_array( $plugin_settings ) ? trim( (string) ( $plugin_settings['domain_name'] ?? '' ) ) : '';
	$plugin_token    = is_array( $plugin_settings ) ? trim( (string) ( $plugin_settings['api_token']   ?? '' ) ) : '';
	$cfg             = sn_plausible_config();
	$err             = sn_plausible_last_error();

	if ( $constant_set ) {
		$source_label = 'wp-config constant <code>SN_PLAUSIBLE_STATS_TOKEN</code>';
	} elseif ( '' !== $option_token ) {
		$source_label = 'this tab (option <code>' . SN_PLAUSIBLE_TOKEN_OPT . '</code>)';
	} elseif ( '' !== $plugin_token ) {
		$source_label = 'Plausible plugin <code>api_token</code> &mdash; <em>likely 401 on Stats API; this is a Plugin Token, not a Stats API key</em>';
	} else {
		$source_label = '<em>not configured</em>';
	}

	echo '<p style="color:#666;font-size:0.95em;max-width:680px;margin-top:0;">Powers the four Plausible widgets on the WP dashboard. The site domain is read from the Plausible plugin&rsquo;s settings; <strong>this tab manages a separate Stats API key</strong> (created at <em>Plausible &rarr; Settings &rarr; API Keys</em> with <code>stats:read</code> scope). The Plausible plugin&rsquo;s wizard creates a <em>Plugin Token</em> in a different namespace, which the Stats API rejects with HTTP 401.</p>';

	// ── STATUS ──
	echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:680px;margin-top:1em;">';
	echo '<strong style="display:block;margin-bottom:8px;">Status</strong>';
	echo '<table class="form-table" style="margin:0;"><tbody>';
	echo '<tr><th scope="row" style="width:140px;padding:6px 10px 6px 0;font-weight:400;color:#646970;">Domain</th><td style="padding:6px 0;">' . ( '' !== $plugin_domain ? '<code>' . esc_html( $plugin_domain ) . '</code>' : '<em>not set in plugin</em>' ) . '</td></tr>';
	echo '<tr><th scope="row" style="padding:6px 10px 6px 0;font-weight:400;color:#646970;">Token source</th><td style="padding:6px 0;">' . wp_kses_post( $source_label ) . '</td></tr>';
	echo '<tr><th scope="row" style="padding:6px 10px 6px 0;font-weight:400;color:#646970;">Last call</th><td style="padding:6px 0;">';
	if ( $err ) {
		$ago = human_time_diff( (int) $err['when'], time() );
		echo '<span style="color:#d63638;">&#10005; HTTP ' . (int) $err['code'] . ' &middot; ' . esc_html( $ago ) . ' ago</span>';
	} else {
		$cached = get_transient( SN_PLAUSIBLE_BATCH_KEY );
		if ( is_array( $cached ) && isset( $cached['fetched'] ) ) {
			$ago = human_time_diff( (int) $cached['fetched'], time() );
			echo '<span style="color:#00a32a;">&#10003; succeeded ' . esc_html( $ago ) . ' ago</span>';
		} else {
			echo '<em>no recent activity</em>';
		}
	}
	echo '</td></tr>';
	echo '</tbody></table>';
	echo '</div>';

	// ── TOKEN FORM ──
	echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:680px;margin-top:1em;">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<p style="margin:0 0 0.4em;"><strong>Stats API Key</strong></p>';
	if ( $constant_set ) {
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 0.4em;">Set via <code>SN_PLAUSIBLE_STATS_TOKEN</code> in <code>wp-config.php</code>. The constant takes precedence over this option, so this field is locked.</p>';
	} else {
		$token_obscured = '' === $option_token ? '' : '&bull;&bull;&bull;&bull;' . esc_attr( substr( $option_token, -4 ) );
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 0.4em;">A Plausible Stats API Key with <code>stats:read</code> scope on the configured site. Stored as a non-autoloaded option, so the token isn&rsquo;t in memory on every request.</p>';
		echo '<input type="text" name="sn_pl_token" value="' . $token_obscured . '" placeholder="Paste a fresh key to update; type &lsquo;clear&rsquo; to remove" style="width:100%;font-family:monospace;font-size:0.85em;margin-bottom:1em;">';
		echo '<p style="margin:0.4em 0 0;"><button type="submit" name="sn_action" value="pl_save" class="button button-primary">Save Stats API Key</button></p>';
	}
	echo '</form>';

	// ── TEST + EMBEDDED LINK ──
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;margin-top:1em;">';

	echo '<form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:300px;">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong style="display:block;margin-bottom:4px;">Test Connection</strong>';
	echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Fires a synchronous 7-day aggregate call and reports the outcome above.</p>';
	echo '<button type="submit" name="sn_action" value="pl_test" class="button"' . ( $cfg ? '' : ' disabled' ) . '>Run Test</button>';
	echo '</form>';

	if ( '' !== $plugin_domain ) {
		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:300px;">';
		echo '<strong style="display:block;margin-bottom:4px;">Embedded Stats</strong>';
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Open the Plausible plugin&rsquo;s in-admin dashboard.</p>';
		echo '<a href="' . esc_url( admin_url( 'index.php?page=plausible_analytics_statistics' ) ) . '" class="button">Open dashboard</a>';
		echo '</div>';
	}
	echo '</div>';
} );

/**
 * Clear all Plausible-related caches so the next widget render fires
 * fresh API calls. Called after token changes — without this, users
 * paste a new key and still see cached 401 errors for up to 5 min.
 */
function sn_pl_admin_invalidate_caches() {
	delete_transient( SN_PLAUSIBLE_BATCH_KEY );
	delete_transient( SN_PLAUSIBLE_REALTIME_KEY );
	delete_transient( SN_PLAUSIBLE_ERR_KEY );
}
