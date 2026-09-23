<?php
/**
 * The Edge section's 5xx rows: which paths failed, and who answered.
 *
 * 17.8.1 (#1002). The edge rollup has stored both since 13.96.3
 * (sn_edge_errors_dims()), and no surface read them back, so a steady
 * ten 503s a day stayed unexplained while the one datum that names the
 * responder sat in sn_edge_dims. Painted under the zone's 5xx figures by
 * cloudflare_edge_html(); the classic Edge panel and `cloudflare-status`
 * read the same sn_edge_errors_reading().
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Two short lists, or '' when there is nothing to show.
 *
 * Empty rather than an empty state: the 5xx stat beside it already says
 * zero, and a second "none" under it would be the same fact twice.
 *
 * @return string
 */
function cloudflare_edge_errors_html() {
	if ( ! function_exists( 'sn_edge_errors_reading' ) ) {
		return '';
	}
	$reading = sn_edge_errors_reading( 7 );
	if ( 0 === $reading['total'] ) {
		return '';
	}
	$sources = array();
	foreach ( $reading['sources'] as $row ) {
		$sources[] = array( 'label' => $row['label'], 'value' => number_format_i18n( $row['requests'] ), 'tone' => 'warn' );
	}
	$paths = array();
	foreach ( $reading['paths'] as $row ) {
		$paths[] = array( 'label' => $row['value'], 'value' => number_format_i18n( $row['requests'] ) );
	}
	return '<p class="snt-hint">' . \snt_kit_esc( __( 'Who answered with the error:', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_list( $sources )
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'Which paths failed:', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_list( $paths );
}
