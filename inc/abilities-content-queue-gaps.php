<?php
/**
 * Signal & Noise Tools: the content queue's gaps (split from
 * inc/abilities-content-queue.php, 2026-10-12). The first hole in the
 * schedule by the publishing-cadence flag's own threshold, and the gaps the
 * owner marked planned (option sn_content_queue_planned_gaps, written by
 * POST signal-noise/v1/desktop/queue-gap-planned).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * The first hole in the schedule, or null. PURE.
 *
 * Reuses the publishing-cadence flag's own definition (inc/ml-cadence.php):
 * snt_ml_cadence_deviation() over the published history gives the expected
 * gap (EWMA) and its spread, and a gap is late at SNT_ML_CADENCE_Z_FLAG
 * sigmas. That flag scores only the current quiet spell; here the same test
 * runs on each pair of consecutive scheduled notes, so the line says where
 * the cadence flag WOULD fire if nothing new is scheduled. Honest unknowns
 * carry over: thin history or a zero-spread metronome answers null, never a
 * guessed threshold. Ceiling: an irregular history widens the spread and
 * nothing flags; that is the cadence check's posture, kept on purpose.
 *
 * The empty stretch is the whole days strictly between the two notes, in
 * the site timezone; a late pair with no whole empty day between them is
 * not a hole anyone can schedule into, so the walk moves on.
 *
 * @since 23.8.0
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int[]        $history   Published Unix times (any order).
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @param string[]     $planned   Gaps the owner marked planned, as "from|to"
 *                                (Y-m-d): skipped, so the next one shows.
 * @return array{from:string,to:string,from_label:string,to_label:string}|null
 */
function sn_content_queue_gap( $scheduled, $history, $now, DateTimeZone $tz, array $planned = array() ) {
	if ( ! function_exists( 'snt_ml_cadence_deviation' ) || ! defined( 'SNT_ML_CADENCE_Z_FLAG' ) ) {
		return null;
	}
	$dev = snt_ml_cadence_deviation( (array) $history, (int) $now );
	if ( ! is_array( $dev ) || ! ( $dev['std'] > 0.0 ) ) {
		return null;
	}
	$ts = array_map( 'intval', (array) $scheduled );
	sort( $ts );
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$count = count( $ts );
	for ( $i = 1; $i < $count; $i++ ) {
		if ( ( $ts[ $i ] - $ts[ $i - 1 ] - $dev['ewma'] ) / $dev['std'] < SNT_ML_CADENCE_Z_FLAG ) {
			continue;
		}
		$from = ( new DateTimeImmutable( '@' . $ts[ $i - 1 ] ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '+1 day' );
		$to   = ( new DateTimeImmutable( '@' . $ts[ $i ] ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '-1 day' );
		if ( $from > $to || in_array( $from->format( 'Y-m-d' ) . '|' . $to->format( 'Y-m-d' ), $planned, true ) ) {
			continue;
		}
		return array(
			'from'       => $from->format( 'Y-m-d' ),
			'to'         => $to->format( 'Y-m-d' ),
			'from_label' => sn_content_queue_day( $from, $today ),
			'to_label'   => sn_content_queue_day( $to, $today ),
		);
	}
	return null;
}

/** Gaps the owner marked planned (2026-10-10: the holidays are on purpose). */
const SN_CONTENT_QUEUE_PLANNED_OPT = 'sn_content_queue_planned_gaps';

/**
 * The planned gaps, as "from|to" (Y-m-d), newest last, at most 20.
 *
 * @return string[]
 */
function sn_content_queue_planned_gaps() {
	$v = get_option( SN_CONTENT_QUEUE_PLANNED_OPT, array() );
	return array_values( array_filter( (array) $v, static fn( $k ) => is_string( $k ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\|\d{4}-\d{2}-\d{2}\z/', $k ) ) );
}

/**
 * Marks one gap planned.
 *
 * @param string $from First empty day, Y-m-d.
 * @param string $to   Last empty day, Y-m-d.
 * @return bool False on a malformed range.
 */
function sn_content_queue_mark_planned( $from, $to ) {
	$from = (string) $from;
	$to   = (string) $to;
	$day = static function ( $d ) {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	};
	if ( ! $day( $from ) || ! $day( $to ) || $from > $to ) {
		return false;
	}
	$keep   = array_values( array_diff( sn_content_queue_planned_gaps(), array( $from . '|' . $to ) ) );
	$keep[] = $from . '|' . $to;
	update_option( SN_CONTENT_QUEUE_PLANNED_OPT, array_slice( $keep, -20 ), false );
	return true;
}

add_action( 'rest_api_init', static function () {
	register_rest_route( 'signal-noise/v1', '/desktop/queue-gap-planned', array(
		'methods'             => 'POST',
		'callback'            => static function ( WP_REST_Request $req ) {
			if ( ! sn_content_queue_mark_planned( $req->get_param( 'from' ), $req->get_param( 'to' ) ) ) {
				return new WP_Error( 'rest_invalid_param', 'from and to must be Y-m-d days, from first.', array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'ok' => true ) );
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'from' => array( 'type' => 'string', 'required' => true ),
			'to'   => array( 'type' => 'string', 'required' => true ),
		),
	) );
} );
