<?php
/**
 * Signal & Noise Tools: the content queue's cadence warnings (2026-10-12,
 * queue calendar phase 2, item 1).
 *
 * The owner's rule: notes under 2 days apart are a cluster, notes over 7
 * days apart a gap. A gap the owner marked planned (option
 * sn_content_queue_planned_gaps, the SN Queue card's Planned button; the
 * holiday pause is stored there) is deliberate and left out. Clusters are
 * never marked deliberate.
 *
 * One sentence per warning, built here, so the SN Queue card and both
 * Connections › Scheduled twins say the same words. Read-only: titles and
 * dates only, no post body.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Notes fewer than this many calendar days apart are a cluster. */
const SN_CONTENT_QUEUE_CLUSTER_DAYS = 2;

/** Notes more than this many calendar days apart leave a gap. */
const SN_CONTENT_QUEUE_GAP_DAYS = 7;

/**
 * The cadence warnings, soonest first. PURE.
 *
 * Distances are whole calendar days in the site timezone. The newest
 * published note joins the walk when there is one, so a quiet stretch between
 * it and the next scheduled note is said too; with nothing scheduled there is
 * no pair and no line. A run of notes each under 2 days from the last is one
 * cluster. A gap is the empty days between two notes; it is left out when a
 * planned range ("from|to", Y-m-d) contains it.
 *
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int|null     $published The newest published Unix time, or null.
 * @param int          $now       Unix time now (for the labels' year).
 * @param DateTimeZone $tz        Site timezone.
 * @param string[]     $planned   Gaps marked planned, as "from|to".
 * @return array<int,array{kind:string,from:string,to:string,days:int,count:int,text:string}>
 */
function sn_content_queue_cadence( $scheduled, $published, $now, DateTimeZone $tz, array $planned = array() ) {
	$ts = array_map( 'intval', (array) $scheduled );
	// The newest published note always joins the walk, in date order: an
	// overdue scheduled note (its date passed, cron not yet fired) sorts
	// before it rather than hiding it (review on #2040: a gap across a note
	// published Oct 5 claimed no note from Oct 2).
	if ( null !== $published ) {
		$ts[] = (int) $published;
	}
	sort( $ts );
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$days  = array_map( static fn( $t ) => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->setTime( 0, 0, 0 ), $ts );
	$label = static fn( DateTimeImmutable $d ) => sn_content_queue_day( $d, $today );
	$apart = 'sn_content_queue_days_apart';

	$out = array();
	$run = null; // The open cluster: [first day, last day, notes].
	$n   = count( $days );
	for ( $i = 1; $i <= $n; $i++ ) {
		$d = $i < $n ? $apart( $days[ $i - 1 ], $days[ $i ] ) : null;
		if ( null !== $d && $d < SN_CONTENT_QUEUE_CLUSTER_DAYS ) {
			$run = $run ? array( $run[0], $days[ $i ], $run[2] + 1 ) : array( $days[ $i - 1 ], $days[ $i ], 2 );
			continue;
		}
		if ( $run ) {
			$same  = $run[0] == $run[1]; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- DateTimeImmutable compares by value.
			$out[] = array(
				'kind'  => 'cluster',
				'from'  => $run[0]->format( 'Y-m-d' ),
				'to'    => $run[1]->format( 'Y-m-d' ),
				'days'  => $apart( $run[0], $run[1] ),
				'count' => $run[2],
				'text'  => $same
					? sprintf( 'Cluster: %d notes on %s', $run[2], $label( $run[0] ) )
					: sprintf( 'Cluster: %d notes %s %s %s', $run[2], $label( $run[0] ), 2 === $run[2] ? 'and' : 'to', $label( $run[1] ) ),
			);
			$run   = null;
		}
		if ( null === $d || $d <= SN_CONTENT_QUEUE_GAP_DAYS ) {
			continue;
		}
		$from = $days[ $i - 1 ]->modify( '+1 day' );
		$to   = $days[ $i ]->modify( '-1 day' );
		$f    = $from->format( 'Y-m-d' );
		$t    = $to->format( 'Y-m-d' );
		foreach ( $planned as $p ) {
			$r = is_string( $p ) ? explode( '|', $p ) : array();
			if ( 2 === count( $r ) && $r[0] <= $f && $t <= $r[1] ) {
				continue 2;
			}
		}
		$out[] = array(
			'kind'  => 'gap',
			'from'  => $f,
			'to'    => $t,
			'days'  => $d,
			'count' => 0,
			'text'  => sprintf( 'Gap: no note %s to %s (%d days between notes)', $label( $from ), $label( $to ), $d ),
		);
	}
	return $out;
}

/**
 * The rule in one sentence, said over the warnings on the Scheduled twins.
 *
 * @return string
 */
function sn_content_queue_cadence_rule() {
	return sprintf( 'From the latest published note through the scheduled ones, notes under %d days apart are a cluster; over %d days apart, a gap. Gaps marked Planned on the SN Queue card are left out.', SN_CONTENT_QUEUE_CLUSTER_DAYS, SN_CONTENT_QUEUE_GAP_DAYS );
}

/** What the twins say when the schedule has neither. */
function sn_content_queue_cadence_clear() {
	return 'No clusters or gaps from the latest published note through the scheduled ones.';
}

/**
 * The warnings for the Connections › Scheduled twins: every scheduled note
 * and the newest published one, read the way the queue ability reads them.
 *
 * @param array|null $schedule From sn_content_queue_schedule_read(), or null to read it.
 * @return array<int,array<string,mixed>>
 */
function sn_content_queue_cadence_read( $schedule = null ) {
	$schedule = is_array( $schedule ) ? $schedule : sn_content_queue_schedule_read();
	return sn_content_queue_cadence( $schedule['future'], $schedule['published'], time(), wp_timezone(), sn_content_queue_planned_gaps() );
}

/**
 * The classic Connections › Scheduled page's Cadence box: the same rule and
 * sentences as the native leaf (scheduled_content_cadence_html()). Open, never
 * folded: it is status.
 *
 * @param array<int,array<string,mixed>> $warnings From sn_content_queue_cadence().
 * @return void
 */
function sn_admin_render_queue_cadence( array $warnings ) {
	echo '<section aria-labelledby="sn-queue-cadence"><h3 id="sn-queue-cadence">' . esc_html__( 'Cadence', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-field-helper">' . esc_html( sn_content_queue_cadence_rule() ) . '</p>';
	if ( array() === $warnings ) {
		echo '<p>' . esc_html( sn_content_queue_cadence_clear() ) . '</p></section>';
		return;
	}
	echo '<ul>';
	foreach ( $warnings as $w ) {
		echo '<li>' . esc_html( (string) ( $w['text'] ?? '' ) ) . '</li>';
	}
	echo '</ul></section>';
}
