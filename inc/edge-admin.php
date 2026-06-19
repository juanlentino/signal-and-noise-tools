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
		echo '<div class="postbox"><div class="inside"><p class="sn-an-empty sn-an-empty--panel">'
			. esc_html__( 'Edge analytics is not configured yet. Add the “Zone Analytics:Read” permission to your SN_CF_ANALYTICS_TOKEN in the Cloudflare dashboard — the zone ID is reused from the cache-purge settings. The first daily sync back-fills ~1 year of edge history.', 'signal-and-noise-tools' )
			. '</p></div></div>';
		return;
	}

	$t     = sn_edge_range_totals( $from, $to );
	$split = sn_edge_machine_split( $from, $to );

	// KPI headline — the reconciliation (Machine %) is the point of this view.
	$cards = array(
		array( 'l' => 'Edge requests',   'n' => number_format_i18n( (int) ( $t['requests'] ?? 0 ) ),       'promoted' => true ),
		array( 'l' => 'Human pageviews', 'n' => number_format_i18n( (int) ( $split['human'] ?? 0 ) ),       'promoted' => true ),
		array( 'l' => 'Machine traffic', 'n' => (int) ( $split['machine_pct'] ?? 0 ) . '%',                 'promoted' => true ),
		array( 'l' => 'Cache hit',       'n' => (int) ( $t['cache_hit_pct'] ?? 0 ) . '%' ),
		array( 'l' => 'Bandwidth',       'n' => snt_edge_fmt_bytes( (int) ( $t['bytes'] ?? 0 ) ) ),
		array( 'l' => 'Errors',          'n' => (int) ( $t['error_pct'] ?? 0 ) . '%' ),
		array( 'l' => 'Threats',         'n' => number_format_i18n( (int) ( $t['threats'] ?? 0 ) ) ),
	);
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html__( 'Traffic & edge', 'signal-and-noise-tools' ) . '</span></h2></div><div class="inside inside-flush">';
	echo '<p class="sn-an-sep">' . esc_html__( 'Server-side edge totals — every request, including bots / RSS / no-JS clients the front-end beacon never sees. “Machine traffic” is edge pageviews minus the beacon’s human pageviews.', 'signal-and-noise-tools' ) . '</p>';
	echo '<div class="sn-kpi-row">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
		echo '</div>';
	}
	echo '</div>';
	if ( function_exists( 'snt_analytics_render_trend' ) ) {
		snt_analytics_render_trend( sn_edge_daily_series( $from, $to ) );
	}
	echo '</div></div>';

	// Status-code breakdown (error monitoring) — built from the daily scalar buckets.
	$status_rows = array();
	foreach ( array( '2xx' => 'status_2xx', '3xx' => 'status_3xx', '4xx' => 'status_4xx', '5xx' => 'status_5xx' ) as $label => $key ) {
		$status_rows[] = array( 'value' => $label, 'requests' => (int) ( $t[ $key ] ?? 0 ), 'bytes' => 0 );
	}
	echo '<div class="sn-an-grid">';
	snt_edge_render_dim( 'Status codes', $status_rows, 'No status data yet.', false );
	snt_edge_render_dim( 'Edge locations', sn_edge_top_dim( 'colo', $from, $to, 10 ), 'No edge-location data in this range yet.' );
	snt_edge_render_dim( 'Countries (all traffic)', sn_edge_top_dim( 'country', $from, $to, 10 ), 'No country data yet.' );
	snt_edge_render_dim( 'Threats', sn_edge_top_dim( 'threat', $from, $to, 10 ), 'No threats recorded in this range.' );
	echo '</div>';
}

/**
 * A native edge breakdown table: Value | Requests | (Bandwidth). $with_bytes=false
 * drops the bandwidth column (e.g. the status table).
 *
 * @param string $title
 * @param array  $rows  [{value, requests, bytes}]
 * @param string $empty
 * @param bool   $with_bytes
 */
function snt_edge_render_dim( $title, $rows, $empty, $with_bytes = true ) {
	echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle"><span>' . esc_html( $title ) . '</span></h2></div><div class="inside sn-an-table-inside">';
	if ( empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html( $empty ) . '</p></div></div>';
		return;
	}
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th scope="col" class="manage-column column-primary">' . esc_html( $title ) . '</th>';
	echo '<th scope="col" class="manage-column num">Requests</th>';
	if ( $with_bytes ) {
		echo '<th scope="col" class="manage-column num">Bandwidth</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td class="column-primary" data-colname="' . esc_attr( $title ) . '"><strong>' . esc_html( (string) ( $r['value'] ?? '' ) ) . '</strong></td>';
		echo '<td class="num" data-colname="Requests">' . esc_html( number_format_i18n( (int) ( $r['requests'] ?? 0 ) ) ) . '</td>';
		if ( $with_bytes ) {
			echo '<td class="num" data-colname="Bandwidth">' . esc_html( snt_edge_fmt_bytes( (int) ( $r['bytes'] ?? 0 ) ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div></div>';
}
