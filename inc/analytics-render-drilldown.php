<?php
/**
 * Signal & Noise — Analytics cross-tab drill-down panel: "Top pages · <Dim> =
 * <value>" with a Clear link, populated by sn_analytics_drilldown(). Native
 * wp-admin markup via the panel primitive. Extracted from
 * analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // panel chrome + empty-fold collector

/**
 * Render the cross-tab drill-down panel: "Top pages · <DimLabel> = <value>" with
 * a Clear link, populated by sn_analytics_drilldown(). $rows null/empty → empty
 * state (rejected value / no data / unconfigured AE). Native wp-admin, no brutalist.
 *
 * D4 §4 carve-out: this panel only ever renders when ?sn_drill is active — an
 * ALWAYS-filtered state. It deliberately does NOT fold on empty (see the
 * convention in snt_an_note_empty()'s docblock): folding would strand the user
 * with no in-UI way back, so the open panel keeps the "Clear drill-down"
 * escape hatch visible.
 *
 * @param string                                                    $dim   A SN_ANALYTICS_DIM_COLUMNS key (for the label).
 * @param string                                                    $value The drilled value.
 * @param array<int,array{path:string,views:int,visits:int}>|null   $rows  Top pages, or null.
 * @param string                                                    $note  Optional footnote (e.g. retention caveat).
 * @return void
 */
function snt_analytics_render_drilldown_panel( $dim, $value, $rows, $note = '' ) {
	$labels = array(
		'referrer' => 'Referrer', 'country' => 'Country', 'device' => 'Device', 'browser' => 'Browser',
		'os' => 'OS', 'region' => 'Region', 'city' => 'City', 'network' => 'Network',
		'colo' => 'Edge location', 'protocol' => 'Protocol', 'tls' => 'TLS',
	);
	$label = isset( $labels[ $dim ] ) ? $labels[ $dim ] : ucfirst( (string) $dim );
	$clear = remove_query_arg( 'sn_drill', add_query_arg( array() ) );

	snt_an_panel_open( 'Top pages · ' . $label . ' = ' . (string) $value, array(
		'panel_class'  => 'sn-an-drill',
		'inside_class' => 'inside sn-an-table-inside',
	) );
	echo '<p class="sn-an-subh sn-an-subh--panel"><a href="' . esc_url( $clear ) . '">&larr; Clear drill-down</a>'
		. ( '' !== $note ? ' · <span class="sn-an-foot">' . esc_html( $note ) . '</span>' : '' ) . '</p>';

	if ( ! is_array( $rows ) || empty( $rows ) ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">No pages for this segment in this range (or it needs live Analytics Engine data).</p>';
		snt_an_panel_close();
		return;
	}

	echo '<table class="wp-list-table widefat striped"><thead><tr>'
		. '<th scope="col" class="manage-column column-primary">Page</th>'
		. '<th scope="col" class="manage-column num">Views</th>'
		. '<th scope="col" class="manage-column num">Visits</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td class="column-primary" data-colname="Page">' . esc_html( (string) ( $r['path'] ?? '' ) ) . '</td>'
			. '<td class="num" data-colname="Views">' . esc_html( number_format_i18n( (int) ( $r['views'] ?? 0 ) ) ) . '</td>'
			. '<td class="num" data-colname="Visits">' . esc_html( number_format_i18n( (int) ( $r['visits'] ?? 0 ) ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	snt_an_panel_close();
}
