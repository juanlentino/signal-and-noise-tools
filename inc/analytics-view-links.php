<?php
/**
 * Signal & Noise Tools: Analytics view, Links (coded résumé links, 2026-10-31).
 *
 * Which /r/CODE link was opened (inc/link-codes.php). Per code from the
 * durable rollup over the chosen range; per visitor-day from the last 90 days
 * of Analytics Engine, with the other pages that visitor-day read
 * (inc/link-codes-read.php). The class filter does not narrow it: class is a
 * column here, because a scanner and a person on the same code are the point.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // snt_an_kv_table(), the empty-fold collector.

const SN_LINK_CODES_ALSO_SHOWN = 5;

/**
 * "New York, US", or the country alone. PURE.
 *
 * @param array $v A visitor-day row.
 * @return string
 */
function snt_analytics_links_place( array $v ) {
	return implode( ', ', array_filter( array( (string) ( $v['city'] ?? '' ), (string) ( $v['country'] ?? '' ) ), 'strlen' ) );
}

/**
 * The other pages a visitor-day read, the first few named. PURE.
 *
 * @param array $also Paths, most viewed first.
 * @return string
 */
function snt_analytics_links_also( array $also ) {
	$more = count( $also ) - SN_LINK_CODES_ALSO_SHOWN;
	/* translators: %d: how many more pages. */
	return implode( ', ', array_slice( $also, 0, SN_LINK_CODES_ALSO_SHOWN ) ) . ( $more > 0 ? ' ' . sprintf( __( '+%d more', 'signal-and-noise-tools' ), $more ) : '' );
}

/**
 * Render the Links view body (inside .sn-an-view; tabs are the dispatcher's).
 *
 * @param string $from Window start (Y-m-d).
 * @param string $to   Window end (Y-m-d).
 */
function snt_analytics_render_view_links( $from, $to ) {
	echo '<p class="sn-an-sep sn-an-sep--full">'
		. esc_html__( 'Coded résumé links: each person gets their own /r/CODE link, which shows the résumé. The code is read out of the page address; no cookie is set. A visitor-day ends at local midnight, so the rest of a visit is joined within that day only. Likely automated: a visitor-day the beacon\'s bot signals flag, such as an email scanner opening a link before its person does.', 'signal-and-noise-tools' )
		. '</p>';

	$codes = function_exists( 'sn_link_codes_summary_read' ) ? sn_link_codes_summary_read( (string) $from, (string) $to ) : null;
	if ( null === $codes ) {
		snt_an_note_empty( __( 'Links', 'signal-and-noise-tools' ), snt_an_read_failed_copy( __( 'Links', 'signal-and-noise-tools' ) ) );
	} else {
		snt_an_kv_table(
			__( 'Links', 'signal-and-noise-tools' ),
			array_map( static fn( $c ) => array( $c['code'], $c['first_seen'], $c['last_seen'], number_format_i18n( $c['views'] ), number_format_i18n( $c['human'] ), number_format_i18n( $c['suspect'] ), number_format_i18n( $c['bot'] ) ), $codes ),
			array( __( 'Code', 'signal-and-noise-tools' ), __( 'First seen', 'signal-and-noise-tools' ), __( 'Last seen', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Human', 'signal-and-noise-tools' ), __( 'Suspect', 'signal-and-noise-tools' ), __( 'Bot', 'signal-and-noise-tools' ) ),
			array(
				'empty'        => __( 'No coded link was opened in this range. Send /r/CODE, for example /r/hera, and it shows here once opened.', 'signal-and-noise-tools' ),
				'data_colname' => true,
				'footer'       => '<p class="sn-an-note">' . esc_html__( 'Human, Suspect and Bot count visitor-days, from the daily rollup (today\'s may lag an hour): an estimate, since a visitor-day that opened both /r/x and /r/x/ counts twice.', 'signal-and-noise-tools' ) . '</p>',
			)
		);
	}

	$visits = function_exists( 'sn_link_codes_visits_read' ) ? sn_link_codes_visits_read() : null;
	$title  = __( 'Opened, last 90 days', 'signal-and-noise-tools' );
	if ( null === $visits ) {
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
	} else {
		snt_an_kv_table(
			$title,
			array_map( static fn( $v ) => array( $v['code'], $v['day'], snt_analytics_links_place( $v ), $v['device'], $v['class'], number_format_i18n( $v['views'] ), snt_analytics_links_also( $v['also'] ) ), $visits ),
			array( __( 'Code', 'signal-and-noise-tools' ), __( 'Day', 'signal-and-noise-tools' ), __( 'Place', 'signal-and-noise-tools' ), __( 'Device', 'signal-and-noise-tools' ), __( 'Class', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Also read', 'signal-and-noise-tools' ) ),
			array(
				'empty'        => __( 'No coded link was opened in the last 90 days.', 'signal-and-noise-tools' ),
				'data_colname' => true,
			)
		);
	}

	snt_an_flush_empty_fold();
}
