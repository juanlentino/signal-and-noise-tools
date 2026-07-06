<?php
/**
 * Signal & Noise — Analytics settings/config partials: the read-credentials form,
 * the owner/role exclusion card, the read-only Cloudflare Worker setup reference,
 * and the one-time Plausible-CSV import panel. Native wp-admin forms; every
 * dynamic value is escaped at the point of output. Extracted from
 * analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-credentials form: Account ID + Account Analytics Read token, with
 * wp-config-constant precedence (a locked field when the constant is set) and the
 * Save / Test-connection actions. Extracted from the former composite
 * snt_analytics_render_settings() in v6.44.0 so the open-and-wide settings section
 * can place it in its own column (the active-settings card) independent of the
 * edge-worker reference column.
 *
 * @since 6.44.0 (was inline in snt_analytics_render_settings since 3.x)
 */
function snt_analytics_render_credentials() {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	$acct_opt     = (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' );
	$token_opt    = (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' );
	$configured   = (bool) ( function_exists( 'sn_analytics_config' ) && sn_analytics_config() );

	echo '<form method="post" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">Credentials</h3>';
	echo '<p class="sn-an-settings-help">Read-only Cloudflare credentials the dashboard uses to query Analytics Engine. A wp-config constant (<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code>) overrides these and locks the field.</p>';

	// Account ID.
	echo '<p><label for="sn_cf_account_id"><strong>Account ID</strong></label><br>';
	if ( $acct_locked ) {
		echo '<input type="text" id="sn_cf_account_id" value="(set in wp-config)" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ACCOUNT_ID</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_account_id" name="sn_cf_account_id" value="' . esc_attr( $acct_opt ) . '" class="regular-text" placeholder="32-char Cloudflare account ID">';
	}
	echo '</p>';

	// Read token (masked).
	echo '<p><label for="sn_cf_analytics_token"><strong>Account Analytics Read token</strong></label><br>';
	if ( $token_locked ) {
		echo '<input type="text" id="sn_cf_analytics_token" value="••••" disabled class="regular-text">';
		echo '<br><span class="sn-an-empty">Locked by the <code>SN_CF_ANALYTICS_TOKEN</code> constant.</span>';
	} else {
		echo '<input type="text" id="sn_cf_analytics_token" name="sn_cf_analytics_token" value="' . esc_attr( sn_mask_secret( $token_opt ) ) . '" class="regular-text" placeholder="Paste a fresh token; type \'clear\' to remove">';
	}
	echo '</p>';

	if ( ! ( $token_locked && $acct_locked ) ) {
		echo '<p><button type="submit" name="sn_action" value="analytics_save" class="button button-primary">Save</button> ';
		echo '<button type="submit" name="sn_action" value="analytics_test" class="button"' . ( $configured ? '' : ' disabled' ) . '>Test connection</button></p>';
	}
	echo '</form>';
}

/**
 * The Monitoring → Analytics "Exclude my own visits" card — a Plausible-style
 * role allow-list. Ticking a role stops the front-end beacon for its logged-in
 * users (the sn_beacon_enabled filter in inc/beacon-owner-exclusion.php suppresses
 * the pixel). Native wp-admin styling; one <form> POSTing analytics_exclude_save.
 *
 * @since 6.23.0
 */
function snt_analytics_render_exclusion() {
	$roles    = function_exists( 'sn_beacon_excludable_roles' ) ? sn_beacon_excludable_roles() : array();
	$excluded = (array) sn_setting( 'analytics.exclude_roles', array() );

	echo '<form method="post" class="sn-an-settings sn-an-exclude">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Exclude my own visits', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Stop counting logged-in users in the selected roles. The front-end beacon is never printed for them, so nothing reaches the collector. Cookieless and forward-only — visits already recorded are unaffected.', 'signal-and-noise-tools' ) . '</p>';

	if ( empty( $roles ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'No roles available on this site.', 'signal-and-noise-tools' ) . '</p></form>';
		return;
	}

	echo '<fieldset>';
	foreach ( $roles as $slug => $name ) {
		$id = 'sn_exclude_role_' . $slug;
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="sn_exclude_roles[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $excluded, true ), true, false ) . '> ' . esc_html( $name ) . ' <code>' . esc_html( $slug ) . '</code></label><br>';
	}
	echo '</fieldset>';

	if ( function_exists( 'sn_beacon_owner_current_user_excluded' ) && sn_beacon_owner_current_user_excluded() ) {
		echo '<p class="sn-an-status"><strong>' . esc_html__( 'You are currently excluded from analytics.', 'signal-and-noise-tools' ) . '</strong></p>';
	} else {
		echo '<p class="sn-an-status">' . esc_html__( 'You are currently counted in analytics.', 'signal-and-noise-tools' ) . '</p>';
	}

	echo '<p class="sn-an-settings-help"><strong>' . esc_html__( 'Requires a logged-in cache bypass.', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'This site serves cached HTML from the CDN, so for the exclusion to run, logged-in requests must miss the edge cache — add a Cloudflare rule to bypass cache when the request carries a wordpress_logged_in_ cookie. Otherwise a cached page still carries the beacon.', 'signal-and-noise-tools' ) . '</p>';

	echo '<p><button type="submit" name="sn_action" value="analytics_exclude_save" class="button button-primary">' . esc_html__( 'Save exclusion', 'signal-and-noise-tools' ) . '</button></p>';
	echo '</form>';
}

/**
 * Read-only Cloudflare Worker setup reference. The plugin can't run wrangler;
 * this shows the exact steps so the Cloudflare side is copy-paste, not guesswork.
 */
function snt_analytics_render_worker_setup() {
	echo '<details class="sn-an-worker"><summary>Cloudflare Worker setup (manual, one-time)</summary>';
	echo '<ol class="sn-an-steps">';
	echo '<li><strong>Read token</strong> (for the fields above): Cloudflare dashboard → My Profile → API Tokens → create a token with <code>Account · Analytics · Read</code>. The Account ID is in the dashboard URL: <code>dash.cloudflare.com/&lt;account_id&gt;</code>.</li>';
	echo '<li><strong>Deploy the edge Worker + its secrets</strong> (from the analytics-worker repo — this can\'t be done from WordPress):<pre class="sn-an-pre">wrangler secret put SN_PX_TOKEN' . "\n" . 'wrangler secret put SN_PX_SALT_SEED' . "\n" . 'wrangler deploy</pre></li>';
	echo '<li><strong>Theme beacon</strong>: set <code>SN_BEACON_TOKEN</code> in <code>wp-config.php</code> to the SAME value as the Worker\'s <code>SN_PX_TOKEN</code> so the front-end beacon is accepted.</li>';
	echo '<li>Hit <strong>Test connection</strong> above once the token + account ID are saved to confirm the read side works. Pageview data appears within ~15 minutes.</li>';
	echo '</ol></details>';
}

/**
 * One-time Plausible-history import panel (v6.0.0). A multipart form with one
 * optional file input per supported CSV export, plus a one-shot summary of the
 * last import (read from a short transient). Posts sn_action=analytics_import on
 * the page=sn-theme-options route, so the shared admin-post handler accepts it.
 */
function snt_analytics_render_import() {
	if ( ! function_exists( 'sn_analytics_import_types' ) ) {
		return;
	}

	echo '<details class="sn-an-worker"><summary>Import history from Plausible (one-time CSV)</summary>';
	echo '<p class="sn-an-settings-help">Retiring Plausible? In Plausible, export each report to CSV, then upload them here to back-fill the first-party dashboard. Import <strong>history from before the edge worker went live</strong> — days the worker already tracks are overwritten by the next data refresh, so avoid importing dates that overlap live data. Re-importing is safe (idempotent). Pages, sources, locations, devices, browsers, and operating systems map across; the hour heatmap, scroll/time distributions, and network/edge/protocol/TLS dimensions start fresh from the worker.</p>';

	// One-shot summary of the last import.
	$report = function_exists( 'get_transient' ) ? get_transient( 'sn_analytics_import_report' ) : false;
	if ( is_array( $report ) ) {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'sn_analytics_import_report' );
		}
		echo '<div class="notice notice-success notice-alt inline"><p><strong>Imported:</strong> '
			. esc_html( number_format_i18n( (int) ( $report['daily'] ?? 0 ) ) ) . ' page-day rows';
		if ( ! empty( $report['dims'] ) && is_array( $report['dims'] ) ) {
			$bits = array();
			foreach ( $report['dims'] as $dim => $n ) {
				$bits[] = (string) $dim . ': ' . number_format_i18n( (int) $n );
			}
			echo ' · ' . esc_html( implode( ' · ', $bits ) );
		}
		if ( ! empty( $report['events'] ) ) {
			echo ' · custom events: ' . esc_html( number_format_i18n( (int) $report['events'] ) );
		}
		if ( ! empty( $report['event_props'] ) ) {
			echo ' · custom props: ' . esc_html( number_format_i18n( (int) $report['event_props'] ) );
		}
		echo '.</p></div>';
	}

	echo '<form method="post" enctype="multipart/form-data" class="sn-an-settings">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( sn_analytics_import_types() as $type => $label ) {
		$id = 'sn_import_' . $type;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" accept=".csv,text/csv"></td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><button type="submit" name="sn_action" value="analytics_import" class="button button-primary">Import CSV history</button> ';
	echo '<span class="sn-an-empty">All fields optional — upload whichever reports you have.</span></p>';
	echo '</form></details>';
}
