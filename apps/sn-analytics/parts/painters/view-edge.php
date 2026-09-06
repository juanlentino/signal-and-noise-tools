<?php
/**
 * S&N Analytics — view/edge (Traffic & edge).
 *
 * Classic: snt_edge_render_view() in inc/edge-admin.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_view_edge( array $ctx ) {
	$from = (string) $ctx['from'];
	$to   = (string) $ctx['to'];
	if ( ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		return \snt_kit_empty(
			__( 'Traffic & edge', 'signal-and-noise-tools' ),
			__( 'Edge analytics is not configured yet. Add the “Zone Analytics:Read” permission to your SN_CF_ANALYTICS_TOKEN in the Cloudflare dashboard: the zone ID is reused from the cache-purge settings. The first daily sync back-fills ~1 year of edge history.', 'signal-and-noise-tools' )
		);
	}
	$t     = function_exists( 'sn_edge_range_totals' ) ? sn_edge_range_totals( $from, $to ) : array();
	$split = function_exists( 'sn_edge_machine_split' ) ? sn_edge_machine_split( $from, $to ) : array();
	$bytes = function_exists( 'snt_edge_fmt_bytes' ) ? snt_edge_fmt_bytes( (int) ( $t['bytes'] ?? 0 ) ) : num( $t['bytes'] ?? 0 );
	$cards = array(
		array( 'l' => __( 'Edge requests', 'signal-and-noise-tools' ), 'n' => num( $t['requests'] ?? 0 ) ),
		array( 'l' => __( 'Human pageviews', 'signal-and-noise-tools' ), 'n' => num( $split['human'] ?? 0 ) ),
		array( 'l' => __( 'Machine traffic', 'signal-and-noise-tools' ), 'n' => (int) ( $split['machine_pct'] ?? 0 ) . '%' ),
		array( 'l' => __( 'Cache hit', 'signal-and-noise-tools' ), 'n' => (int) ( $t['cache_hit_pct'] ?? 0 ) . '%' ),
		array( 'l' => __( 'Bandwidth', 'signal-and-noise-tools' ), 'n' => (string) $bytes ),
		array( 'l' => __( 'Errors', 'signal-and-noise-tools' ), 'n' => (int) ( $t['error_pct'] ?? 0 ) . '%' ),
		array( 'l' => __( 'Threats', 'signal-and-noise-tools' ), 'n' => num( $t['threats'] ?? 0 ) ),
	);
	return \snt_kit_section(
		__( 'Traffic & edge', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'Server-side edge totals: every request, including bots / RSS / no-JS clients the front-end beacon never sees. “Machine traffic” is edge pageviews minus the beacon’s human pageviews.', 'signal-and-noise-tools' ) ) . '</p>'
		. stats( $cards )
	);
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/edge'] = __NAMESPACE__ . '\\paint_view_edge';
		return $painters;
	}
);
