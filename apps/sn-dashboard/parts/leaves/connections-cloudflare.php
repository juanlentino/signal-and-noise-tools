<?php
/**
 * S&N Dashboard — Connections → Cloudflare, painted from the kit.
 *
 * The classic leaf (inc/cloudflare-purge.php, the `sn_admin_cloudflare_tab`
 * closure behind `sn_admin_render_cloudflare_section()`) paints, in the
 * two-column shell: the intro, the Credentials form (`sn_action=cf_save`,
 * fields `sn_cf_token` / `sn_cf_zone`, each locked to a disabled input by its
 * wp-config constant), the folded Post-purge probes table, and in the rail the
 * cache status box, the Purge Everything Now card (`sn_action=cf_purge_now`,
 * disabled until configured) and the Cloudways purge status. Same readings
 * (the token, the zone, SN_CF_LAST_PURGE_OPT, SN_CF_PROBE_LOG_OPT,
 * SNT_CW_LAST_PURGE_OPT), same forms, same handlers; the kit's parts instead
 * of wp-admin's, stacked in the shell's DOM order (main, then rail).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/connections-cloudflare-parts.php';
require_once __DIR__ . '/connections-cloudflare-monitor.php'; // 14.9.0

/**
 * The leaf's readings, taken the way the classic closure takes them.
 *
 * @return array<string,mixed>
 */
function cloudflare_data() {
	$token      = function_exists( 'sn_cf_get_token' ) ? (string) sn_cf_get_token() : '';
	$zone       = function_exists( 'sn_cf_get_zone' ) ? (string) sn_cf_get_zone() : '';
	$last_purge = defined( 'SN_CF_LAST_PURGE_OPT' ) ? get_option( SN_CF_LAST_PURGE_OPT, array() ) : array();
	$probe_log  = defined( 'SN_CF_PROBE_LOG_OPT' ) ? get_option( SN_CF_PROBE_LOG_OPT, array() ) : array();
	$cloudways  = null;
	if ( function_exists( 'sn_cloudways_is_configured' ) && sn_cloudways_is_configured() ) {
		$cloudways = defined( 'SNT_CW_LAST_PURGE_OPT' ) ? get_option( SNT_CW_LAST_PURGE_OPT, array() ) : array();
		$cloudways = is_array( $cloudways ) ? $cloudways : array();
	}
	// The mask never degrades to the raw token: without the masker, four dots.
	$obscured = function_exists( 'sn_mask_secret' ) ? (string) sn_mask_secret( $token ) : ( '' === $token ? '' : '••••' );
	return array(
		'token_obscured'  => $obscured,
		'zone'            => $zone,
		// 14.10.0: the account id is central too (Analytics Engine reads and
		// the Account-token verify route); the constant locks it like the others.
		'account'         => function_exists( 'sn_cf_get_account_id' ) ? (string) sn_cf_get_account_id() : '',
		'token_const_set' => defined( 'SN_CLOUDFLARE_API_TOKEN' ),
		'zone_const_set'  => defined( 'SN_CLOUDFLARE_ZONE_ID' ),
		'account_const_set' => defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' ),
		'analytics_override' => function_exists( 'sn_cf_analytics_override_source' ) ? (string) sn_cf_analytics_override_source() : '',
		'last_purge'      => is_array( $last_purge ) ? $last_purge : array(),
		'is_configured'   => function_exists( 'sn_cf_is_configured' ) && sn_cf_is_configured(),
		'probe_log'       => is_array( $probe_log ) ? $probe_log : array(),
		'cloudways'       => $cloudways,
	);
}

/**
 * The Credentials section, read-only since 15.2.0: where each of the three
 * comes from, the grant list, and the door to Connections › Credentials,
 * where every key is set. This leaf never carries a field again.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return string
 */
function cloudflare_credentials_html( array $d ) {
	$source = static function ( $const_set, $const, $value, $shown ) {
		if ( $const_set ) {
			/* translators: %s: constant name. */
			return sprintf( __( 'locked by %s', 'signal-and-noise-tools' ), $const );
		}
		return '' !== (string) $value ? (string) $shown : __( 'not set', 'signal-and-noise-tools' );
	};
	$inner = \snt_kit_kv( array(
		array( 'label' => __( 'API token', 'signal-and-noise-tools' ), 'value' => $source( $d['token_const_set'], 'SN_CLOUDFLARE_API_TOKEN', $d['token_obscured'], $d['token_obscured'] ) ),
		array( 'label' => __( 'Zone ID', 'signal-and-noise-tools' ), 'value' => $source( $d['zone_const_set'], 'SN_CLOUDFLARE_ZONE_ID', $d['zone'], $d['zone'] ) ),
		array( 'label' => __( 'Account ID', 'signal-and-noise-tools' ), 'value' => $source( ! empty( $d['account_const_set'] ), 'SN_CF_ACCOUNT_ID', (string) ( $d['account'] ?? '' ), (string) ( $d['account'] ?? '' ) ) ),
	) );
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'connections' ) ) . '. ' . \snt_kit_esc( __( 'This leaf only reads them.', 'signal-and-noise-tools' ) ) . '</p>';
	// 14.10.0: the grant list, one place to compare against the token summary.
	if ( function_exists( 'sn_cf_required_grants' ) ) {
		$rows = array();
		foreach ( sn_cf_required_grants() as $g ) {
			$rows[] = array( 'label' => $g['scope'] . ' › ' . $g['grant'] . ' · ' . $g['for'], 'value' => $g['status'], 'tone' => 'candidate' === $g['status'] ? 'warn' : '' );
		}
		$inner .= '<h4 class="snt-h">' . \snt_kit_esc( __( 'Grants this token needs', 'signal-and-noise-tools' ) ) . '</h4>' . \snt_kit_list( $rows );
	}
	if ( '' !== (string) ( $d['analytics_override'] ?? '' ) ) {
		$inner .= \snt_kit_notice( 'warning', \snt_kit_esc( 'constant' === $d['analytics_override']
			? __( 'Analytics still reads with the SN_CF_ANALYTICS_TOKEN constant, not this token. Remove the constant from wp-config.php to use one token.', 'signal-and-noise-tools' )
			: __( 'Analytics still reads with a separate token saved under Measurement › Analytics › Credentials. Drop it there to use this one.', 'signal-and-noise-tools' ) ) );
	}
	return \snt_kit_section(
		__( 'Credentials', 'signal-and-noise-tools' ),
		$inner,
		__( 'The one Cloudflare token, the zone and the account, for every Cloudflare call this plugin makes; set on the keyring.', 'signal-and-noise-tools' )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_cloudflare( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$d = cloudflare_data();
	// 15.1.0: two columns, by the question a reader brings. Left: is
	// Cloudflare connected and allowed (Credentials, the token's health, the
	// monitor's Refresh). Right: the cache. 15.3.0 moved Edge and Firewall to
	// the tabs that ask their questions.
	$left  = cloudflare_credentials_html( $d );
	$left .= function_exists( 'sn_cf_monitor_read' ) ? cloudflare_token_html( $d ) : '';

	$caching = \snt_kit_esc( __( "Auto-purges Cloudflare's edge cache when content changes. See", 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'docs/CACHING.md', false ) . ' '
		. \snt_kit_esc( __( "for the dashboard-side Cache Rule that turns on HTML caching to begin with: without that, purging clears nothing useful (origin pages aren't cached at the edge).", 'signal-and-noise-tools' ) );
	$right = \snt_kit_section(
		__( 'Cache', 'signal-and-noise-tools' ),
		cloudflare_status_html( $d ) . cloudflare_purge_html( $d ) . cloudflare_probes_html( $d ) . '<p class="snt-hint">' . $caching . '</p>',
		'',
		array( 'stack' => true )
	);
	// 15.3.0: Edge, 7 days lives on Measurement › Analytics and Firewall, 24
	// hours on Security › Firewall; this leaf is wiring and cache.

	return '<div class="snt-2up">'
		. '<div class="snt-2up-col">' . $left . '</div>'
		. '<div class="snt-2up-col">' . $right . '</div>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cloudflare'] = __NAMESPACE__ . '\\paint_connections_cloudflare';
		return $painters;
	}
);
