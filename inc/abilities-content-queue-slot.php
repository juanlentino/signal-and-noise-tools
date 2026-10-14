<?php
/**
 * Signal & Noise Tools: a suggested publishing slot per draft (2026-10-13,
 * queue calendar phase 2, item 2).
 *
 * A suggestion only: nothing here schedules anything. Each draft the card
 * lists gets the next open day that keeps the cadence rule (inc/
 * abilities-content-queue-cadence.php): never under 2 days from a note
 * (exactly 2 is the normal rhythm), never inside a planned gap, the earliest
 * unplanned gap over 7 days split first, otherwise the usual spacing after
 * the last note; at the site's usual publish time.
 *
 * One PURE function builds the day and the sentence, so the SN Queue card and
 * both Connections › Scheduled twins say the same words. Read-only: titles
 * and dates only, no post body. The reads and the classic twin's box live in
 * abilities-content-queue-slot-read.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** What every surface says when the spacing cannot be read for a draft. */
const SN_CONTENT_QUEUE_SLOT_UNKNOWN = 'No slot suggested: fewer than two notes to read the rhythm from.';

/** What every surface says when nothing is scheduled to read a time from. */
const SN_CONTENT_QUEUE_SLOT_NOTHING = 'No slot suggested: nothing scheduled to read the rhythm from.';

/** What every surface says when no open day turns up within a year. */
const SN_CONTENT_QUEUE_SLOT_NONE = 'No slot suggested: no open day in the next year.';

/** How far the walk for an open day looks, in days. */
const SN_CONTENT_QUEUE_SLOT_HORIZON = 366;

/**
 * The suggested slot for each draft, in the order given (the card's order:
 * newest edit first, so the newest draft gets the earliest slot). PURE.
 *
 * The walk is the cadence walk: every scheduled note plus the newest
 * published one, in whole calendar days in the site timezone. A suggestion
 * counts as taken for the next draft (in the walk and in the gaps), so two
 * drafts never share a day.
 *
 * Gap split: the earliest unplanned gap (sn_content_queue_cadence()) is cut
 * into the fewest pieces of 7 days or less, as even as they can be; a draft
 * takes the first cut (the open day nearest it, at least 2 days from every
 * note and after today), so drafts aimed at one hole spread across it rather
 * than packing at its start. Otherwise: the last note or suggestion plus the
 * median spacing between notes (held between 2 and 7 days, so a suggestion
 * never makes a cluster or a gap itself), walked forward past a planned gap
 * or a taken day. The time is the median time of day of the scheduled notes
 * (the lower middle, so always a time the site really uses). Nothing
 * scheduled, fewer than two notes to measure, or no open day within a year:
 * no slot, and it says which.
 *
 * @param array        $drafts    Draft rows {id, title, edit_url}.
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int|null     $published The newest published Unix time, or null.
 * @param int          $now       Unix time now.
 * @param DateTimeZone $tz        Site timezone.
 * @param string[]     $planned   Gaps marked planned, as "from|to".
 * @return array<int,array{id:int,title:string,edit_url:string,day:?string,time:?string,label:string,reason:string,text:string}>
 */
function sn_content_queue_slots( $drafts, $scheduled, $published, $now, DateTimeZone $tz, array $planned = array() ) {
	$sched = array_map( 'intval', (array) $scheduled );
	$at    = static fn( $t ) => ( new DateTimeImmutable( '@' . (int) $t ) )->setTimezone( $tz );
	$today = $at( $now )->setTime( 0, 0, 0 );
	$apart = 'sn_content_queue_days_apart';
	$ymd   = static fn( $s ) => new DateTimeImmutable( $s, $tz );
	$name  = static fn( DateTimeImmutable $d ) => sn_content_queue_day( $d, $today );

	$walk = $sched;
	if ( null !== $published ) {
		$walk[] = (int) $published;
	}
	sort( $walk );
	$days    = array_map( static fn( $t ) => $at( $t )->setTime( 0, 0, 0 ), $walk );
	$spacing = array();
	for ( $i = 1, $n = count( $days ); $i < $n; $i++ ) {
		$spacing[] = $apart( $days[ $i - 1 ], $days[ $i ] );
	}
	$secs = array_map( static fn( $t ) => (int) $at( $t )->format( 'G' ) * 3600 + (int) $at( $t )->format( 'i' ) * 60, $sched );
	sort( $spacing );
	sort( $secs );
	$why   = array() === $secs ? SN_CONTENT_QUEUE_SLOT_NOTHING : ( array() === $spacing ? SN_CONTENT_QUEUE_SLOT_UNKNOWN : '' );
	$sec   = '' === $why ? $secs[ intdiv( count( $secs ) - 1, 2 ) ] : 0;
	$usual = '' === $why ? $spacing[ intdiv( count( $spacing ) - 1, 2 ) ] : 0;
	$step  = max( SN_CONTENT_QUEUE_CLUSTER_DAYS, min( SN_CONTENT_QUEUE_GAP_DAYS, $usual ) );

	$open = static function ( DateTimeImmutable $c ) use ( &$days, $today, $planned, $apart ) {
		if ( $c <= $today ) {
			return false;
		}
		foreach ( $days as $o ) {
			if ( $apart( $c, $o ) < SN_CONTENT_QUEUE_CLUSTER_DAYS ) {
				return false;
			}
		}
		$k = $c->format( 'Y-m-d' );
		foreach ( $planned as $p ) {
			$r = is_string( $p ) ? explode( '|', $p ) : array();
			if ( 2 === count( $r ) && $r[0] <= $k && $k <= $r[1] ) {
				return false;
			}
		}
		return true;
	};

	$out   = array();
	$taken = array(); // Unix times of earlier suggestions, for the gaps.
	$mine  = array(); // Their days (Y-m-d), so a reason can say "suggested".
	foreach ( is_array( $drafts ) ? $drafts : array() as $d ) {
		if ( ! is_array( $d ) ) {
			continue;
		}
		$title = trim( (string) ( $d['title'] ?? '' ) );
		$row   = array(
			'id'       => (int) ( $d['id'] ?? 0 ),
			'title'    => '' !== $title ? $title : '(untitled)',
			'edit_url' => (string) ( $d['edit_url'] ?? '' ),
			'day'      => null,
			'time'     => null,
			'label'    => 'no slot suggested',
			'reason'   => '',
			'text'     => '' !== $why ? $why : SN_CONTENT_QUEUE_SLOT_NONE,
		);
		if ( '' !== $why ) {
			$out[] = $row;
			continue;
		}

		$pick   = null;
		$reason = '';
		foreach ( sn_content_queue_cadence( array_merge( $sched, $taken ), $published, $now, $tz, $planned ) as $w ) {
			if ( 'gap' !== $w['kind'] ) {
				continue;
			}
			$from   = $ymd( $w['from'] );
			$end    = $ymd( $w['to'] );
			$before = $from->modify( '-1 day' );
			$span   = (int) $w['days'];
			// The fewest cuts that leave no piece over 7 days; aim at the first.
			$cut    = (int) round( $span / ( (int) ceil( $span / SN_CONTENT_QUEUE_GAP_DAYS ) ) );
			$target = $before->modify( '+' . $cut . ' days' );
			for ( $k = 0; $k <= $span && null === $pick; $k++ ) {
				foreach ( array_unique( array( -$k, $k ) ) as $off ) {
					$c = $target->modify( ( $off < 0 ? '' : '+' ) . $off . ' days' );
					if ( $c >= $from && $c <= $end && $open( $c ) ) {
						$pick = $c;
						break;
					}
				}
			}
			if ( null !== $pick ) {
				$left   = $apart( $before, $pick );
				$reason = sprintf( 'splits the %s to %s gap into %d and %d days', $name( $from ), $name( $end ), $left, $span - $left );
				break;
			}
		}
		if ( null === $pick ) {
			$last  = max( $days );
			$start = $last->modify( '+' . $step . ' days' );
			for ( $pick = $start, $k = 0; $k < SN_CONTENT_QUEUE_SLOT_HORIZON && ! $open( $pick ); $k++ ) {
				$pick = $pick->modify( '+1 day' );
			}
			if ( ! $open( $pick ) ) {
				// Review on #2054: a walk that runs out never hands back a day
				// inside a planned gap.
				$out[] = $row;
				continue;
			}
			$after  = isset( $mine[ $last->format( 'Y-m-d' ) ] ) ? 'the slot suggested for ' . $name( $last ) : 'the last note on ' . $name( $last );
			$reason = ( $usual === $step
				? sprintf( 'the usual %d days after %s', $step, $after )
				: sprintf( '%d days after %s (the usual %d would %s)', $step, $after, $usual, $usual > $step ? 'leave a gap' : 'make a cluster' ) )
				. ( $pick->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ? ', moved to the next open day' : '' );
		}
		$when  = $pick->setTime( intdiv( $sec, 3600 ), intdiv( $sec % 3600, 60 ) );
		$day   = $pick->format( 'D, ' ) . $name( $pick );
		$out[] = array_merge(
			$row,
			array(
				'day'    => $pick->format( 'Y-m-d' ),
				'time'   => $when->format( 'g:i A' ),
				'label'  => 'suggested: ' . $day,
				'reason' => $reason,
				'text'   => sprintf( 'Suggested: %s at %s; %s. A suggestion only: nothing is scheduled.', $day, $when->format( 'g:i A' ), $reason ),
			)
		);
		$days[]                           = $pick;
		$taken[]                          = $when->getTimestamp();
		$mine[ $pick->format( 'Y-m-d' ) ] = true;
	}
	return $out;
}

/**
 * What the twins say instead of a list, or '' when there are drafts to list.
 *
 * @param int|null $drafts The site's draft count, or null when not read.
 * @param int      $listed How many drafts this user's list holds.
 * @return string
 */
function sn_content_queue_slot_empty( $drafts, $listed ) {
	if ( $listed > 0 ) {
		return '';
	}
	if ( null === $drafts ) {
		return 'Drafts not read, so no slot is suggested.';
	}
	return 0 === (int) $drafts ? 'No drafts, so no slot to suggest.' : 'None of the drafts is yours to edit, so no slot is suggested.';
}

/** The rule in one sentence, said over the slots on the Scheduled twins. */
function sn_content_queue_slot_rule() {
	return sprintf( 'A suggestion only; nothing is scheduled. Each draft gets the next open day that keeps the cadence: not under %d days from a note, not in a planned gap, the earliest gap over %d days split first, else the usual spacing after the last note; at the usual publish time, in the site timezone.', SN_CONTENT_QUEUE_CLUSTER_DAYS, SN_CONTENT_QUEUE_GAP_DAYS );
}

require_once __DIR__ . '/abilities-content-queue-slot-read.php';
