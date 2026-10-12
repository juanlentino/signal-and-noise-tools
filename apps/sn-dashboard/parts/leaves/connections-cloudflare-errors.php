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
 * 2026-10-12 audit: the lists come from the edge rollup (seven UTC days
 * through today), not from the zone total above them (seven days to the
 * monitor's last read), so they say their window and need not add up to it;
 * a failed errors query says "not read" instead of vanishing under a
 * non-zero tile; each list stops at CF_EDGE_ERRORS_ROWS with "+N more", and
 * the three lists (with the status codes) share one row.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** Each 5xx list shows this many rows, then "+N more". */
const CF_EDGE_ERRORS_ROWS = 5;

/**
 * The rollup's window, in the site's date format.
 *
 * @param string $from First day (YYYY-MM-DD, UTC).
 * @param string $to   Last day (YYYY-MM-DD, UTC).
 * @return string
 */
function cloudflare_edge_errors_window( $from, $to ) {
	$fmt = static fn( $day ) => wp_date( (string) get_option( 'date_format', 'F j, Y' ), (int) strtotime( $day . ' 12:00:00 UTC' ) );
	/* translators: 1: first day, 2: last day. */
	return sprintf( __( 'These lists cover the UTC days %1$s to %2$s, from the daily edge rollup; the five figures are the zone\'s own seven days to its last read, so the two need not add up.', 'signal-and-noise-tools' ), $fmt( $from ), $fmt( $to ) );
}

/**
 * One capped list under its heading.
 *
 * @param string                         $heading The list's heading.
 * @param array<int,array<string,mixed>> $rows    snt_kit_list() rows.
 * @return string
 */
function cloudflare_edge_errors_list( $heading, array $rows ) {
	return '<div class="snt-edge-list"><h4 class="snt-h">' . \snt_kit_esc( $heading ) . '</h4>'
		. \snt_kit_list( array_slice( $rows, 0, CF_EDGE_ERRORS_ROWS ) )
		. \snt_kit_more( count( $rows ) - CF_EDGE_ERRORS_ROWS ) . '</div>';
}

/**
 * The 5xx lists on one row (the status codes the zone reported, then who
 * answered and which paths failed, from the rollup), with the rollup's
 * window under them; a "not read" notice beside the codes when the errors
 * query failed; the codes alone when the rollup holds no 5xx.
 *
 * Empty lists stay unpainted: the 5xx stat already says zero, and a second
 * "none" under it would be the same fact twice.
 *
 * @param string $code_list The status-code list (cloudflare_edge_errors_list()), '' when none.
 * @return string
 */
function cloudflare_edge_errors_html( $code_list = '' ) {
	if ( ! function_exists( 'sn_edge_errors_reading' ) ) {
		return (string) $code_list;
	}
	$reading = sn_edge_errors_reading( 7 );
	$query   = is_array( $reading['query'] ?? null ) ? $reading['query'] : array();
	if ( '' !== (string) ( $query['error'] ?? '' ) ) {
		/* translators: %s: why the errors query failed. */
		return $code_list . \snt_kit_notice( 'warning', \snt_kit_esc( sprintf( __( 'Paths and responders not read: %s', 'signal-and-noise-tools' ), (string) $query['error'] ) ) );
	}
	if ( 0 === (int) $reading['total'] ) {
		return (string) $code_list;
	}
	$sources = array();
	foreach ( $reading['sources'] as $row ) {
		$sources[] = array( 'label' => $row['label'], 'value' => number_format_i18n( $row['requests'] ), 'tone' => 'warn' );
	}
	$paths = array();
	foreach ( $reading['paths'] as $row ) {
		$paths[] = array( 'label' => $row['value'], 'value' => number_format_i18n( $row['requests'] ) );
	}
	return \snt_kit_grid(
		array(
			(string) $code_list,
			cloudflare_edge_errors_list( __( 'Who answered with the error', 'signal-and-noise-tools' ), $sources ),
			cloudflare_edge_errors_list( __( 'Which paths failed', 'signal-and-noise-tools' ), $paths ),
		),
		240,
		18
	) . '<p class="snt-hint">' . \snt_kit_esc( cloudflare_edge_errors_window( (string) $reading['from'], (string) $reading['to'] ) ) . '</p>';
}
