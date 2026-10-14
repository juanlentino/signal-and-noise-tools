<?php
/**
 * Signal & Noise Tools: the content queue's runway (2026-10-14, queue
 * calendar phase 2, item 6). How far ahead the schedule covers, as one
 * sentence: "Covered through Jan 25, 2027 (103 days)". Days are calendar days
 * from today to the last scheduled note's day, both in the SITE timezone. The
 * scheduled count is the secondary text. An unknown is said in words.
 *
 * Built here so the SN Queue card and both Connections › Scheduled twins say
 * the same words. Read-only: dates only, no post body.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The runway. PURE.
 *
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @return array{through:?string,days:?int,text:string}
 */
function sn_content_queue_runway( $scheduled, $now, DateTimeZone $tz ) {
	$ts = array_map( 'intval', (array) $scheduled );
	if ( array() === $ts ) {
		return array( 'through' => null, 'days' => null, 'text' => 'Not covered: nothing is scheduled.' );
	}
	$last  = max( $ts );
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$day   = ( new DateTimeImmutable( '@' . $last ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$label = sn_content_queue_day( $day, $today );
	// The last note's time passed and cron has not published it: every
	// scheduled note is overdue, so nothing ahead is covered.
	if ( $last < (int) $now ) {
		return array( 'through' => null, 'days' => null, 'text' => sprintf( 'Not covered: the last scheduled note, %s, is overdue.', $label ) );
	}
	$days = (int) $today->diff( $day )->format( '%a' );
	return array(
		'through' => $day->format( 'Y-m-d' ),
		'days'    => $days,
		'text'    => sprintf( 'Covered through %s (%s)', $label, 0 === $days ? 'today' : ( 1 === $days ? '1 day' : $days . ' days' ) ),
	);
}

/**
 * The secondary text: the scheduled count, or that it was not read.
 *
 * @param int|null $count Scheduled notes, or null when not read.
 * @return string
 */
function sn_content_queue_runway_count( $count ) {
	return null === $count ? 'Scheduled count not read' : max( 0, (int) $count ) . ' scheduled';
}

/**
 * The runway for the Connections › Scheduled twins, from the schedule both
 * twin boxes read, with its count.
 *
 * @param array|null $schedule From sn_content_queue_schedule_read(), or null to read it.
 * @return array{through:?string,days:?int,text:string,count:string}
 */
function sn_content_queue_runway_read( $schedule = null ) {
	$schedule = is_array( $schedule ) ? $schedule : sn_content_queue_schedule_read();
	$future   = (array) $schedule['future'];
	return sn_content_queue_runway( $future, time(), wp_timezone() ) + array( 'count' => sn_content_queue_runway_count( count( $future ) ) );
}

/**
 * The classic Connections › Scheduled page's Runway box: the same sentence
 * and count as the native leaf (scheduled_content_runway_html()). Open, never
 * folded: it is status.
 *
 * @param array $read From sn_content_queue_runway_read().
 * @return void
 */
function sn_admin_render_queue_runway( array $read ) {
	echo '<section aria-labelledby="sn-queue-runway"><h3 id="sn-queue-runway">' . esc_html__( 'Runway', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p>' . esc_html( (string) ( $read['text'] ?? '' ) ) . '</p>';
	echo '<p class="sn-field-helper">' . esc_html( (string) ( $read['count'] ?? '' ) ) . '</p></section>';
}
