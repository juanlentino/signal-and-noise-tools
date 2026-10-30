<?php
/**
 * A scheduled-post fixture for the queue twin suites (queue-cadence-twins,
 * queue-runway-twins, queue-slot-twins): local noon, $days whole calendar days
 * from today in the site timezone (wp_timezone(), which each suite stubs).
 *
 * WHY CALENDAR DAYS, NOT time() + $days * 86400. Across a DST change a day is
 * 23 or 25 hours, so two notes 86400 seconds apart can land on the same day
 * (Nov 1 2026, New York) or two days apart (Mar 8). The cadence reader counts
 * calendar days, so the old fixture failed queue-cadence-twins at some hours
 * of the week before each change (24 of 3,456 ten-minute instants across
 * the 2026 and 2027 change weeks; this fixture: none).
 * Noon is never near midnight, so the day is the one asked for.
 *
 * @param int         $id    Post ID.
 * @param float       $days  Days from today; the fraction is dropped.
 * @param string|null $title Title, or "Note $id".
 * @param int|null    $now   Unix time standing in for now (the DST proof), or time().
 * @return WP_Post
 */
function snt_test_queue_post( $id, $days, $title = null, $now = null ) {
	$noon             = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( wp_timezone() )->setTime( 12, 0 )->modify( sprintf( '%+d days', (int) floor( $days ) ) );
	$p                = new WP_Post();
	$p->ID            = $id;
	$p->post_title    = $title ?? "Note $id";
	$p->post_date_gmt = gmdate( 'Y-m-d H:i:s', $noon->getTimestamp() );
	return $p;
}
