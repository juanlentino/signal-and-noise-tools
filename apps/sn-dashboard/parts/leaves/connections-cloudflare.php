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
 * of wp-admin's, on two rows of paired boxes since 17.4.1 (#1573).
 *
 * 2026-10-16 (leaf anatomy, docs/kit-leaf-anatomy.md): a Status card leads
 * (one status line; the analytics-token warning hoisted out of Credentials),
 * then Cache beside Token, then the probes ledger beside one Setup card whose
 * fold holds the keys' sources and the grant list.
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
 * The keys, read-only since 15.2.0: where each of the three comes from, the
 * grant list, and the door to Connections › Credentials, where every key is
 * set. A Setup fold since 2026-10-16: the closed summary says which source is
 * set (never a value, not even masked), and it opens while the token or the
 * zone is missing. The analytics-token warning is the Status card's.
 *
 * @param array<string,mixed> $d From cloudflare_data().
 * @return array<string,mixed> A Setup fold.
 */
function cloudflare_keys_fold( array $d ) {
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
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Set with every other key under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'connections' ) ) . '.</p>';
	// 14.10.0: the grant list, one place to compare against the token summary.
	if ( function_exists( 'sn_cf_required_grants' ) ) {
		$rows = array();
		foreach ( sn_cf_required_grants() as $g ) {
			$rows[] = array( 'label' => $g['scope'] . ' › ' . $g['grant'] . ' · ' . $g['for'], 'value' => $g['status'], 'tone' => 'candidate' === $g['status'] ? 'warn' : '' );
		}
		$inner .= '<h4 class="snt-h">' . \snt_kit_esc( __( 'Grants this token needs', 'signal-and-noise-tools' ) ) . '</h4>' . \snt_kit_list( $rows );
	}
	$words = array();
	foreach ( array(
		__( 'API token', 'signal-and-noise-tools' )  => array( $d['token_const_set'], $d['token_obscured'] ),
		__( 'Zone ID', 'signal-and-noise-tools' )    => array( $d['zone_const_set'], $d['zone'] ),
		__( 'Account ID', 'signal-and-noise-tools' ) => array( ! empty( $d['account_const_set'] ), (string) ( $d['account'] ?? '' ) ),
	) as $label => $src ) {
		$words[] = $label . ' ' . ( $src[0] ? __( 'locked', 'signal-and-noise-tools' ) : ( '' !== (string) $src[1] ? __( 'set', 'signal-and-noise-tools' ) : __( 'not set', 'signal-and-noise-tools' ) ) );
	}
	return array(
		'title'   => __( 'Keys and grants', 'signal-and-noise-tools' ),
		'summary' => implode( ' · ', $words ),
		'body'    => '<p class="snt-prose">' . \snt_kit_esc( __( 'The one Cloudflare token, the zone and the account, for every Cloudflare call this plugin makes; set on the keyring.', 'signal-and-noise-tools' ) ) . '</p>' . $inner,
		'open'    => empty( $d['is_configured'] ),
	);
}

/**
 * 17.4.1: two boxes on one row, the tags_pair() idiom (#1573). A side that
 * painted nothing leaves the other alone at full width, never beside a hole.
 *
 * @param string $left  Painted section HTML, or ''.
 * @param string $right Painted section HTML, or ''.
 * @return string
 */
function cloudflare_pair( $left, $right ) {
	return \snt_kit_grid( array( $left, $right ) );
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
	$caching = function_exists( 'sn_cf_caching_sentence' ) ? \snt_kit_esc( \sn_cf_caching_sentence() ) : '';
	$cache = \snt_kit_section(
		__( 'Cache', 'signal-and-noise-tools' ),
		cloudflare_status_html( $d ) . '<p class="snt-hint">' . $caching . '</p>',
		'',
		array( 'stack' => true )
	);
	// 17.4.1 (#1573): boxes on rows of comparable height, measured live at
	// 1581px. Credentials (518) beside Cache (449); under them Token (300)
	// beside the probes ledger, which left the Cache box's fold to stand as a
	// capped table. Before: Credentials + Token stacked to 860 beside Cache
	// alone. 15.3.0 moved Edge and Firewall to the tabs that ask their questions.
	$token = function_exists( 'sn_cf_monitor_read' ) ? cloudflare_token_html( $d ) : '';
	$setup = \snt_kit_setup_card( __( 'Setup', 'signal-and-noise-tools' ), array( cloudflare_keys_fold( $d ) ), __( 'Set on the keyring; open it to read the sources and the grants.', 'signal-and-noise-tools' ) );
	// The boxes that paint, two to a row in this order: Cache beside Token,
	// the probes beside Setup; one that is absent moves the next one up, so
	// no box stands beside a hole and none is alone when it has a partner.
	$out = cloudflare_status_card_html( $d );
	foreach ( array_chunk( array_values( array_filter( array( $cache, $token, cloudflare_probes_html( $d ), $setup ), 'strlen' ) ), 2 ) as $pair ) {
		$out .= cloudflare_pair( $pair[0], $pair[1] ?? '' );
	}
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/cloudflare'] = __NAMESPACE__ . '\\paint_connections_cloudflare';
		return $painters;
	}
);
