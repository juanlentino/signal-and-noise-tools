<?php
/**
 * Signal & Noise — the "Traffic & edge" analytics view (edge-analytics presenter).
 *
 * Renders the edge GraphQL rollup as a native wp-admin view inside Monitoring →
 * Analytics: a KPI headline (incl. the beacon-reconciliation — what the JS beacon
 * never saw), the daily request trend (reusing the shared trend chart), an error/
 * status breakdown, and per-colo / per-country / threat tables. Reuses the existing
 * .sn-kpi-row / postbox / wp-list-table treatments — no new visual vocabulary.
 *
 * @package SignalNoiseTools
 * @since 6.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/** Humanize a byte count (reuses WP's size_format when available). */
function snt_edge_fmt_bytes( $bytes ) {
	$bytes = (int) $bytes;
	if ( function_exists( 'size_format' ) ) {
		$out = size_format( $bytes, $bytes >= 1073741824 ? 1 : 0 );
		if ( $out ) {
			return (string) $out;
		}
	}
	return number_format_i18n( $bytes ) . ' B';
}

/**
 * The full "Traffic & edge" view for an inclusive [$from,$to] window. Dormant
 * (configure note) until the GraphQL client is configured.
 */
function snt_edge_render_view( $from, $to ) {
	if ( ! function_exists( 'sn_edge_config' ) || ! sn_edge_config() ) {
		snt_an_gate(
			__( 'Traffic & edge', 'signal-and-noise-tools' ),
			__( 'Edge analytics is not configured yet. Add the “Zone Analytics:Read” permission to your SN_CF_ANALYTICS_TOKEN in the Cloudflare dashboard — the zone ID is reused from the cache-purge settings. The first daily sync back-fills ~1 year of edge history.', 'signal-and-noise-tools' )
		);
		return;
	}

	$t     = sn_edge_range_totals( $from, $to );
	$split = sn_edge_machine_split( $from, $to );

	// KPI headline — the reconciliation (Machine %) is the point of this view.
	$cards = array(
		array( 'l' => __( 'Edge requests', 'signal-and-noise-tools' ),   'n' => number_format_i18n( (int) ( $t['requests'] ?? 0 ) ),       'promoted' => true ),
		array( 'l' => __( 'Human pageviews', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) ( $split['human'] ?? 0 ) ),       'promoted' => true ),
		array( 'l' => __( 'Machine traffic', 'signal-and-noise-tools' ), 'n' => (int) ( $split['machine_pct'] ?? 0 ) . '%',                 'promoted' => true ),
		array( 'l' => __( 'Cache hit', 'signal-and-noise-tools' ),       'n' => (int) ( $t['cache_hit_pct'] ?? 0 ) . '%' ),
		array( 'l' => __( 'Bandwidth', 'signal-and-noise-tools' ),       'n' => snt_edge_fmt_bytes( (int) ( $t['bytes'] ?? 0 ) ) ),
		array( 'l' => __( 'Errors', 'signal-and-noise-tools' ),          'n' => (int) ( $t['error_pct'] ?? 0 ) . '%' ),
		array( 'l' => __( 'Threats', 'signal-and-noise-tools' ),         'n' => number_format_i18n( (int) ( $t['threats'] ?? 0 ) ) ),
	);
	snt_an_panel_open( __( 'Traffic & edge', 'signal-and-noise-tools' ), array( 'inside_class' => 'inside inside-flush' ) );
	echo '<p class="sn-an-sep">' . esc_html__( 'Server-side edge totals — every request, including bots / RSS / no-JS clients the front-end beacon never sees. “Machine traffic” is edge pageviews minus the beacon’s human pageviews.', 'signal-and-noise-tools' ) . '</p>';
	// Surface the REAL adaptive-dataset retention (discovered from the settings node,
	// not the old "24h on Free" guess). Omitted entirely until the probe knows it.
	$ret_days = function_exists( 'sn_edge_adaptive_retention_days' ) ? (int) sn_edge_adaptive_retention_days() : 0;
	if ( $ret_days > 0 ) {
		echo '<p class="sn-an-sep">' . esc_html( sprintf(
			/* translators: %d: days Cloudflare retains the sampled adaptive dataset, discovered from the GraphQL settings node. */
			_n(
				'Adaptive snapshots (edge locations, threats) reflect a trailing 24h; Cloudflare retains this node for %d day on the current plan.',
				'Adaptive snapshots (edge locations, threats) reflect a trailing 24h; Cloudflare retains this node for %d days on the current plan.',
				$ret_days,
				'signal-and-noise-tools'
			),
			$ret_days
		) ) . '</p>';
	}
	// v9.40.0 D4: no card here carries live/delta/sub, so empty_slot=omit
	// reproduces the old loop's silence (label + value only, no third line).
	snt_an_kpi_row( $cards, array( 'empty_slot' => 'omit', 'row_class' => 'sn-kpi-row--edge' ) );
	if ( function_exists( 'snt_analytics_render_trend' ) ) {
		snt_analytics_render_trend( sn_edge_daily_series( $from, $to ) );
	}
	snt_an_panel_close();

	// Status-code breakdown (error monitoring) — built from the daily scalar buckets.
	$status_rows = array();
	foreach ( array( '2xx' => 'status_2xx', '3xx' => 'status_3xx', '4xx' => 'status_4xx', '5xx' => 'status_5xx' ) as $label => $key ) {
		$status_rows[] = array( 'value' => $label, 'requests' => (int) ( $t[ $key ] ?? 0 ), 'bytes' => 0 );
	}
	echo '<div class="sn-an-grid">';
	snt_edge_render_dim( __( 'Status codes', 'signal-and-noise-tools' ), $status_rows, 'No status data yet.', false );
	snt_edge_render_dim( __( 'Edge locations', 'signal-and-noise-tools' ), sn_edge_top_dim( 'colo', $from, $to, 10 ), 'No edge-location data in this range yet.' );
	snt_edge_render_dim( __( 'Countries (all traffic)', 'signal-and-noise-tools' ), sn_edge_top_dim( 'country', $from, $to, 10 ), 'No country data yet.' );
	snt_edge_render_dim( __( 'Threats', 'signal-and-noise-tools' ), sn_edge_top_dim( 'threat', $from, $to, 10 ), 'No threats recorded in this range.' );
	echo '</div>';

	// Attack-surface pressure — the loud doors (/wp-login.php, /xmlrpc.php) + the
	// generic 4xx probe surface, from the edge GraphQL (the masked-login worker never
	// sees these). All atk_* rows store bytes=0, so render with $with_bytes=false.
	// Full-width labelled divider (matches the other sections in this view, e.g. the
	// engagement CWV intro) instead of wrapping the dim grid in an extra .postbox. The
	// nested postbox header rendered oversized next to the un-nested dim-card headers.
	echo '<p class="sn-an-sep sn-an-sep--full"><strong>' . esc_html__( 'Attack-surface pressure', 'signal-and-noise-tools' ) . '</strong> ' . esc_html__( 'Door-knock pressure against the WordPress attack surface (sampling-corrected, last ~24h per daily sync). These hit /wp-login.php directly — the masked-login worker never sees them.', 'signal-and-noise-tools' ) . '</p>';
	echo '<div class="sn-an-grid">';
	snt_edge_render_dim( __( 'Login doors', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_door', $from, $to, 10 ), 'No login-door hits in this range yet.', false );
	snt_edge_render_dim( __( 'Door status codes', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_status', $from, $to, 10 ), 'No door status data yet.', false );
	snt_edge_render_dim( __( 'Door methods', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_method', $from, $to, 10 ), 'No door method data yet.', false );
	snt_edge_render_dim( __( 'Attacker countries', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_country', $from, $to, 10 ), 'No attacker-country data yet.', false );
	snt_edge_render_dim( __( 'Attacker networks', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_asn', $from, $to, 10 ), 'No attacker-network data yet.', false );
	snt_edge_render_dim( __( 'Top probed paths (4xx)', 'signal-and-noise-tools' ), sn_edge_top_dim( 'atk_path', $from, $to, 25 ), 'No probe scans recorded yet.', false );
	echo '</div>';
	snt_an_flush_empty_fold();
}

/**
 * A native edge breakdown table: Value | Requests | (Bandwidth). $with_bytes=false
 * drops the bandwidth column (e.g. the status table). Thin wrapper over the
 * shared snt_an_kv_table() primitive (D5 §4, inc/analytics-panels.php): does
 * its own number_format_i18n()/snt_edge_fmt_bytes() formatting (the primitive
 * takes pre-formatted strings) and forwards $empty into the fold's why-text —
 * this used to be silently dropped, the last dead diagnostic param in the
 * plugin (D4 §4 convention, finally completed here).
 *
 * @param string $title
 * @param array  $rows  [{value, requests, bytes}]
 * @param string $empty
 * @param bool   $with_bytes
 */
function snt_edge_render_dim( $title, $rows, $empty, $with_bytes = true ) {
	$cols = array( $title, 'Requests' );
	if ( $with_bytes ) {
		$cols[] = 'Bandwidth';
	}

	$kv_rows = array();
	foreach ( (array) $rows as $r ) {
		$row = array(
			(string) ( $r['value'] ?? '' ),
			number_format_i18n( (int) ( $r['requests'] ?? 0 ) ),
		);
		if ( $with_bytes ) {
			$row[] = snt_edge_fmt_bytes( (int) ( $r['bytes'] ?? 0 ) );
		}
		$kv_rows[] = $row;
	}

	snt_an_kv_table(
		$title,
		$kv_rows,
		$cols,
		array(
			'empty'        => $empty,
			'data_colname' => true,
		)
	);
}
