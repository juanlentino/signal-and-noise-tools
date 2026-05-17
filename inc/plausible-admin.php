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

	// ── STATE ──
	// POST handling lives in sn_handle_admin_post() (admin_init, PRG).
	// This callback is render-only.
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

	echo '<p class="sn-prose">Powers the four Plausible widgets on the WP dashboard. The site domain is read from the Plausible plugin&rsquo;s settings; <strong>this tab manages a separate Stats API key</strong> (created at <em>Plausible &rarr; Settings &rarr; API Keys</em> with <code>stats:read</code> scope). The Plausible plugin&rsquo;s wizard creates a <em>Plugin Token</em> in a different namespace, which the Stats API rejects with HTTP 401.</p>';

	// ── MODULE STATUS BOX ──
	// At-a-glance: is the API likely to work or not?
	$has_sn_token = $constant_set || '' !== $option_token;
	if ( $has_sn_token && ! $err ) {
		echo '<div class="sn-status-box">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Configured</p>';
		echo '<p class="sn-status-box-body">Stats API key present. Widgets on the WP dashboard will use it for visitor data.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--ok">Configured</span>';
		echo '</div>';
	} elseif ( $has_sn_token && $err ) {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Configured but failing</p>';
		echo '<p class="sn-status-box-body">Token present, but last API call returned HTTP ' . (int) $err['code'] . '. Run Test below for fresh diagnostic.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Failing</span>';
		echo '</div>';
	} elseif ( '' !== $plugin_token ) {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Misconfigured — wrong token namespace</p>';
		echo '<p class="sn-status-box-body">Only the Plausible plugin&rsquo;s <code>api_token</code> is available. That&rsquo;s a Plugin Token, not a Stats API key — the Stats API rejects it with HTTP 401. Paste a Stats API key below.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Wrong key</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--err">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Not configured</p>';
		echo '<p class="sn-status-box-body">No Stats API key. Dashboard widgets show "no data" until a key is provided.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--err">Missing</span>';
		echo '</div>';
	}

	// ── STATUS DETAILS FIELDSET ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Status details</h2>';
	echo '<table class="form-table sn-status-table sn-status-table--full"><tbody>';
	echo '<tr><th>Domain</th><td>' . ( '' !== $plugin_domain ? '<code>' . esc_html( $plugin_domain ) . '</code>' : '<em>not set in Plausible plugin</em>' ) . '</td></tr>';
	echo '<tr><th>Token source</th><td>' . wp_kses_post( $source_label ) . '</td></tr>';
	echo '<tr><th>Last call</th><td>';
	if ( $err ) {
		$ago = human_time_diff( (int) $err['when'], time() );
		echo '<span class="sn-pill sn-pill--err">HTTP ' . (int) $err['code'] . ' &middot; ' . esc_html( $ago ) . ' ago</span>';
	} else {
		$cached = get_transient( SN_PLAUSIBLE_BATCH_KEY );
		if ( is_array( $cached ) && isset( $cached['fetched'] ) ) {
			$ago = human_time_diff( (int) $cached['fetched'], time() );
			echo '<span class="sn-pill sn-pill--ok">succeeded ' . esc_html( $ago ) . ' ago</span>';
		} else {
			echo '<em>no recent activity</em>';
		}
	}
	echo '</td></tr>';
	echo '</tbody></table>';
	echo '</div>';

	// ── TOKEN FORM ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Stats API Key</h2>';

	if ( $constant_set ) {
		echo '<p class="sn-fieldset-intro">A Plausible Stats API Key with <code>stats:read</code> scope on the configured site.</p>';
		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label">Token</label>';
		echo '<input type="text" value="••••" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked.</strong> Set via <code>SN_PLAUSIBLE_STATS_TOKEN</code> in <code>wp-config.php</code>. Remove the constant to edit here.</p>';
		echo '</div>';
	} else {
		echo '<p class="sn-fieldset-intro">Stored as a non-autoloaded option, so the token isn&rsquo;t in memory on every request.</p>';
		$token_obscured = '' === $option_token ? '' : '••••' . esc_attr( substr( $option_token, -4 ) );
		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label" for="sn_pl_token">Token</label>';
		echo '<input type="text" id="sn_pl_token" name="sn_pl_token" value="' . esc_attr( $token_obscured ) . '" placeholder="Paste a fresh key to update; type ‘clear’ to remove" class="sn-mono">';
		echo '<p class="sn-field-helper">A Plausible Stats API Key with <code>stats:read</code> scope. Leave the obscured value alone to keep the existing token; type <code>clear</code> to remove.</p>';
		echo '</div>';

		echo '<div class="sn-fieldset-actions">';
		echo '<button type="submit" name="sn_action" value="pl_save" class="button button-primary">Save</button>';
		echo '</div>';
	}

	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── ACTION CARDS (Test + Embedded) ──
	echo '<div class="sn-card-grid">';

	echo '<form method="post" class="sn-card sn-card--narrow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong>Test Connection</strong>';
	echo '<p class="sn-helper">Fires a synchronous 7-day aggregate call and reports the outcome above.</p>';
	echo '<button type="submit" name="sn_action" value="pl_test" class="button"' . ( $cfg ? '' : ' disabled' ) . '>Run Test</button>';
	echo '</form>';

	if ( '' !== $plugin_domain ) {
		echo '<div class="sn-card sn-card--narrow">';
		echo '<strong>Embedded Stats</strong>';
		echo '<p class="sn-helper">Open the Plausible plugin&rsquo;s in-admin dashboard.</p>';
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
