<?php
/**
 * S&N Dashboard, Monitoring > Analytics: the credentials, collector and
 * exclusion settings, open.
 *
 * Moved out of monitoring-analytics-parts.php when the folds came out
 * (2026-10-11, owner rule: no collapsible sections). Each setting is an open
 * section: the short heading, ONE line of current state as the description
 * (the classic fold's own snapshot function, `snt_an_*_snapshot()`, called
 * as is), then the form. Readers, field names and sn_action values are the
 * classic renderer's, unchanged.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Credentials: where the read token and the account ID come from (rows on the
 * keyring since 15.2.0) and the Test-connection action. The classic form
 * re-posts its two fields with the test; here the test is a one-click action
 * with no field payload, because an `<os-form>` fires one action.
 *
 * @return string
 */
function analytics_credentials_html() {
	$acct_locked = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' );
	$acct_opt    = (string) \get_option( defined( 'SN_CF_ACCOUNT_ID_OPT' ) ? constant( 'SN_CF_ACCOUNT_ID_OPT' ) : 'sn_cf_account_id', '' );
	$configured  = function_exists( '\sn_analytics_config' ) && \sn_analytics_config();
	$override    = function_exists( '\sn_cf_analytics_override_source' ) ? (string) \sn_cf_analytics_override_source() : '';
	$central     = function_exists( '\sn_cf_get_token' ) && '' !== (string) \sn_cf_get_token();

	$body = '<p class="snt-prose">' . \snt_kit_esc( __( 'Set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'measurement' ) ) . '. ' . \snt_kit_esc( __( 'Analytics Engine reads use the Cloudflare API token (Account › Account Analytics › Read on it) unless an analytics token override is set there.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( '' !== $override ) {
		$body .= \snt_kit_notice( 'warning', \snt_kit_esc( __( 'An analytics token override is in force; the Cloudflare API token is not used for these reads. Clear the override on the keyring to use one token.', 'signal-and-noise-tools' ) ) );
	} elseif ( ! $central ) {
		$body .= \snt_kit_notice( 'error', \snt_kit_esc( __( 'No token anywhere: set the Cloudflare API token under Connections › Credentials.', 'signal-and-noise-tools' ) ) );
	}
	$body .= \snt_kit_kv( array(
		array( 'label' => __( 'Reads with', 'signal-and-noise-tools' ), 'value' => '' !== $override ? __( 'the analytics token override', 'signal-and-noise-tools' ) : ( $central ? __( 'the Cloudflare API token', 'signal-and-noise-tools' ) : __( 'nothing', 'signal-and-noise-tools' ) ), 'tone' => '' !== $override ? 'warn' : ( $central ? '' : 'err' ) ),
		array( 'label' => __( 'Account ID', 'signal-and-noise-tools' ), 'value' => $acct_locked ? __( 'locked by SN_CF_ACCOUNT_ID', 'signal-and-noise-tools' ) : ( '' !== $acct_opt ? $acct_opt : __( 'not set', 'signal-and-noise-tools' ) ) ),
	) );
	$body .= \snt_kit_action_button( __( 'Test connection', 'signal-and-noise-tools' ), 'analytics_test', array( 'disabled' => ! $configured ) );

	return \snt_kit_section( __( 'Credentials', 'signal-and-noise-tools' ), $body, \snt_an_credentials_snapshot() );
}

/**
 * Collector endpoint (v10.46.0 on the classic leaf): where every beacon posts.
 *
 * @return string
 */
function analytics_collector_html() {
	$rss       = function_exists( '\sn_rss_tracker_settings' ) ? (array) \sn_rss_tracker_settings() : array();
	$collector = (string) ( $rss['collector_url'] ?? '' );
	$default   = function_exists( '\home_url' ) ? \home_url( '/_sn/px' ) : '';

	$fields = \snt_kit_field(
		'url',
		'sn_an_collector_url',
		__( 'Endpoint URL', 'signal-and-noise-tools' ),
		$collector,
		array(
			'required' => true,
			'hint'     => __( 'Defaults to this site’s own endpoint. If WordPress cannot reach the site domain through the edge, point this at the Worker’s *.workers.dev URL instead.', 'signal-and-noise-tools' ),
		)
	);
	if ( '' !== $default && $collector !== $default ) {
		/* translators: %s: the default collector URL for this site. */
		$fields .= '<p class="snt-hint">' . sprintf( \snt_kit_esc( __( 'Site default: %s', 'signal-and-noise-tools' ) ), '<os-code>' . \snt_kit_esc( $default ) . '</os-code>' ) . '</p>';
	}

	/* translators: 1: the worker route; 2: the shared-token constant name. */
	$body  = '<p class="snt-prose">' . \snt_kit_esc( sprintf( __( 'Where every first-party beacon on this site posts: the Cloudflare Worker’s %1$s route. Authenticated with the shared %2$s constant from wp-config.php.', 'signal-and-noise-tools' ), '/_sn/px', 'SN_BEACON_TOKEN' ) ) . '</p>';
	$body .= \snt_kit_form( 'analytics_collector_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );

	return \snt_kit_section( __( 'Collector endpoint', 'signal-and-noise-tools' ), $body, \snt_an_collector_snapshot() );
}

/**
 * Exclude my own visits: a role allow-list.
 *
 * A shared `sn_exclude_roles[]` name per checkbox (the classic markup) is
 * silent by construction in the runtime: OsForm.getValues() keys by name, so
 * N controls sharing one name collapse to ONE key, and os-checkbox-label has
 * no `value` prop, so the slug is dropped and a save would clear every
 * excluded role. Each role gets its OWN indexed name instead, carrying the
 * slug as an `<os-select>` value, so the handler receives
 * `sn_exclude_roles => array( 'subscriber', ... )`, the shape
 * sn_handle_analytics_exclude_save() expects.
 *
 * @return string
 */
function analytics_exclusion_html() {
	$roles    = function_exists( '\sn_beacon_excludable_roles' ) ? \sn_beacon_excludable_roles() : array();
	$excluded = (array) ( function_exists( '\sn_setting' ) ? \sn_setting( 'analytics.exclude_roles', array() ) : array() );
	$heading  = __( 'Exclude my own visits', 'signal-and-noise-tools' );

	$intro = '<p class="snt-prose">' . \snt_kit_esc( __( 'Stop counting logged-in users in the selected roles. The front-end beacon is never printed for them, so nothing reaches the collector. Cookieless and forward-only: visits already recorded are unaffected.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( empty( $roles ) ) {
		return \snt_kit_section( $heading, $intro . '<p class="snt-hint">' . \snt_kit_esc( __( 'No roles available on this site.', 'signal-and-noise-tools' ) ) . '</p>', \snt_an_exclusion_snapshot() );
	}

	$fields = '';
	$i      = 0;
	foreach ( $roles as $slug => $name ) {
		$fields .= \snt_kit_field(
			'select',
			'sn_exclude_roles[' . $i++ . ']',
			$name . ' (' . $slug . ')',
			in_array( $slug, $excluded, true ) ? $slug : '',
			array(
				'options' => array(
					''    => __( 'Counted', 'signal-and-noise-tools' ),
					$slug => __( 'Excluded', 'signal-and-noise-tools' ),
				),
			)
		);
	}
	$status = ( function_exists( '\sn_beacon_owner_current_user_excluded' ) && \sn_beacon_owner_current_user_excluded() )
		? '<p class="snt-hint" full-width><b>' . \snt_kit_esc( __( 'You are currently excluded from analytics.', 'signal-and-noise-tools' ) ) . '</b></p>'
		: '<p class="snt-hint" full-width>' . \snt_kit_esc( __( 'You are currently counted in analytics.', 'signal-and-noise-tools' ) ) . '</p>';
	$footnote = '<p class="snt-hint" full-width>' . \snt_kit_esc( __( 'Your own visits are also dropped at the edge: the collector ignores any beacon that carries a logged-in WordPress cookie, so you are excluded even when the page was served from cache. The role list above adds per-role control on uncached requests: to extend that role filter to cached pages too, add a Cloudflare rule that bypasses cache when the request carries a wordpress_logged_in_ cookie.', 'signal-and-noise-tools' ) ) . '</p>';

	$form = \snt_kit_form( 'analytics_exclude_save', $fields . $status . $footnote, array( 'submit' => __( 'Save exclusion', 'signal-and-noise-tools' ), 'columns' => 'auto' ) );
	return \snt_kit_section( $heading, $intro . $form, \snt_an_exclusion_snapshot() );
}
