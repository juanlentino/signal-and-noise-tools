<?php
/**
 * Signal & Noise Tools: a suggested publishing slot per draft (2026-10-13,
 * queue calendar phase 2, item 2).
 *
 * A suggestion only: nothing here schedules anything. Each draft the card
 * lists gets the next open day that keeps the cadence rule (inc/
 * abilities-content-queue-cadence.php): never under 2 days from a note
 * (exactly 2 is the normal rhythm), never inside a planned gap, the earliest
 * unplanned gap over 7 days filled first, otherwise the usual spacing after
 * the last note; at the site's usual publish time.
 *
 * One PURE function builds the day and the sentence, so the SN Queue card and
 * both Connections › Scheduled twins say the same words. Read-only: titles
 * and dates only, no post body.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** What every surface says when no slot can be read for a draft. */
const SN_CONTENT_QUEUE_SLOT_UNKNOWN = 'No slot suggested: fewer than two notes to read the rhythm from.';

/**
 * The suggested slot for each draft, in the order given (the card's order:
 * newest edit first, so the newest draft gets the earliest slot). PURE.
 *
 * The walk is the cadence walk: every scheduled note plus the newest
 * published one, in whole calendar days in the site timezone. A suggestion
 * counts as taken for the next draft, so two drafts never share a day.
 *
 * Gap fill: the earliest unplanned gap (sn_content_queue_cadence()), its
 * first empty day that is after today and at least 2 days from every note,
 * walked forward to the gap's last day. Otherwise: the last note plus the
 * median spacing between notes (held between 2 and 7 days, so a suggestion
 * never makes a cluster or a gap itself), walked forward past a planned gap
 * or a taken day. The time is the median time of day of the scheduled notes
 * (the lower middle, so always a time the site really uses). Nothing
 * scheduled, or fewer than two notes to measure: no slot, and it says so.
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
	$apart = static fn( DateTimeImmutable $a, DateTimeImmutable $b ) => (int) $a->diff( $b )->format( '%a' );
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
	$known = array() !== $secs && array() !== $spacing;
	$sec   = $known ? $secs[ intdiv( count( $secs ) - 1, 2 ) ] : 0;
	$usual = $known ? $spacing[ intdiv( count( $spacing ) - 1, 2 ) ] : 0;
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
	$taken = array();
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
			'text'     => SN_CONTENT_QUEUE_SLOT_UNKNOWN,
		);
		if ( ! $known ) {
			$out[] = $row;
			continue;
		}

		$pick = null;
		foreach ( sn_content_queue_cadence( array_merge( $sched, $taken ), $published, $now, $tz, $planned ) as $w ) {
			if ( 'gap' !== $w['kind'] ) {
				continue;
			}
			for ( $c = $ymd( $w['from'] ), $end = $ymd( $w['to'] ); $c <= $end; $c = $c->modify( '+1 day' ) ) {
				if ( $open( $c ) ) {
					$pick   = $c;
					$reason = sprintf( 'fills the %s to %s gap', $name( $ymd( $w['from'] ) ), $name( $end ) );
					break 2;
				}
			}
		}
		if ( null === $pick ) {
			$last  = max( $days );
			$start = $last->modify( '+' . $step . ' days' );
			// ponytail: a bounded day-by-day walk; a year of planned gaps would need a jump.
			for ( $pick = $start, $k = 0; $k < 366 && ! $open( $pick ); $k++ ) {
				$pick = $pick->modify( '+1 day' );
			}
			$reason = ( $usual === $step
				? sprintf( 'the usual %d days after the last note on %s', $step, $name( $last ) )
				: sprintf( '%d days after the last note on %s (the usual %d would %s)', $step, $name( $last ), $usual, $usual > $step ? 'leave a gap' : 'make a cluster' ) )
				. ( $pick->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ? ', moved to the next open day' : '' );
		}
		$when    = $pick->setTime( intdiv( $sec, 3600 ), intdiv( $sec % 3600, 60 ) );
		$day     = $pick->format( 'D, ' ) . $name( $pick );
		$out[]   = array_merge(
			$row,
			array(
				'day'    => $pick->format( 'Y-m-d' ),
				'time'   => $when->format( 'g:i A' ),
				'label'  => 'suggested: ' . $day,
				'reason' => $reason,
				'text'   => sprintf( 'Suggested: %s at %s; %s. A suggestion only: nothing is scheduled.', $day, $when->format( 'g:i A' ), $reason ),
			)
		);
		$days[]  = $pick;
		$taken[] = $when->getTimestamp();
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
	return sprintf( 'A suggestion only; nothing is scheduled. Each draft gets the next open day that keeps the cadence: not under %d days from a note, not in a planned gap, the earliest gap over %d days first, else the usual spacing after the last note; at the usual publish time, site time zone.', SN_CONTENT_QUEUE_CLUSTER_DAYS, SN_CONTENT_QUEUE_GAP_DAYS );
}

/**
 * The drafts this user can edit, newest edit first, capped at
 * SN_CONTENT_QUEUE_DRAFTS (review on #2031: a contributor reads their own,
 * never a colleague's working title), and the site's draft count. Shared by
 * the content-queue ability and the Scheduled twins, so both list the same
 * drafts.
 *
 * @return array{rows:array,count:?int,more:?int}
 */
function sn_content_queue_draft_read() {
	$others = current_user_can( 'edit_others_posts' );
	$draft  = get_posts( array( 'post_type' => 'post', 'suppress_filters' => false, 'orderby' => 'modified', 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'post_status' => 'draft', 'order' => 'DESC', 'numberposts' => SN_CONTENT_QUEUE_DRAFTS ) + ( $others ? array() : array( 'author' => get_current_user_id() ) ) );
	$draft  = array_filter( is_array( $draft ) ? $draft : array(), static fn( $p ) => $p instanceof WP_Post && current_user_can( 'edit_post', $p->ID ) );
	$rows   = array_values( array_filter( array_map( 'sn_content_queue_row', $draft ) ) );
	$counts = wp_count_posts( 'post' );
	$count  = is_object( $counts ) && isset( $counts->draft ) ? (int) $counts->draft : null;
	return array(
		'rows'  => $rows,
		'count' => $count,
		// "+N more" is the site's count less the list, so it is said only to
		// someone whose list could hold any draft; otherwise not read.
		'more'  => $others && null !== $count ? max( 0, $count - count( $rows ) ) : null,
	);
}

/**
 * The slots for the Connections › Scheduled twins, read the way the queue
 * ability reads them.
 *
 * @return array{slots:array,empty:string,more:?int}
 */
function sn_content_queue_slot_read() {
	$base   = array( 'post_type' => 'post', 'suppress_filters' => false, 'orderby' => 'date', 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
	$queue  = get_posts( $base + array( 'post_status' => 'future', 'order' => 'ASC', 'numberposts' => -1 ) );
	$last   = get_posts( $base + array( 'post_status' => 'publish', 'order' => 'DESC', 'numberposts' => 1 ) );
	$future = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $queue ) ? $queue : array() ) ) );
	$done   = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $last ) ? $last : array() ) ) );
	$drafts = sn_content_queue_draft_read();
	return array(
		'slots' => sn_content_queue_slots( $drafts['rows'], array_column( $future, 'ts' ), isset( $done[0]['ts'] ) ? (int) $done[0]['ts'] : null, time(), wp_timezone(), sn_content_queue_planned_gaps() ),
		'empty' => sn_content_queue_slot_empty( $drafts['count'], count( $drafts['rows'] ) ),
		'more'  => $drafts['more'],
	);
}

/**
 * The classic Connections › Scheduled page's Suggested slots box: the same
 * rule and sentences as the native leaf (scheduled_content_slots_html()).
 * Open, never folded; read-only (the only action is the draft's edit link).
 *
 * @param array $read From sn_content_queue_slot_read().
 * @return void
 */
function sn_admin_render_queue_slots( array $read ) {
	echo '<section aria-labelledby="sn-queue-slots"><h3 id="sn-queue-slots">' . esc_html__( 'Suggested slots', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-field-helper">' . esc_html( sn_content_queue_slot_rule() ) . '</p>';
	if ( '' !== (string) ( $read['empty'] ?? '' ) ) {
		echo '<p>' . esc_html( (string) $read['empty'] ) . '</p></section>';
		return;
	}
	echo '<ul>';
	foreach ( (array) ( $read['slots'] ?? array() ) as $s ) {
		$title = (string) ( $s['title'] ?? '' );
		$url   = (string) ( $s['edit_url'] ?? '' );
		echo '<li>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ': ' . esc_html( (string) ( $s['text'] ?? '' ) ) . '</li>';
	}
	echo '</ul>';
	if ( ! empty( $read['more'] ) ) {
		echo '<p>' . esc_html( sn_content_queue_slot_more( (int) $read['more'] ) ) . '</p>';
	}
	echo '</section>';
}

/**
 * The drafts left off the list, said the same on both twins.
 *
 * @param int $more Drafts past the cap.
 * @return string
 */
function sn_content_queue_slot_more( $more ) {
	return sprintf( 1 === (int) $more ? '+%d more draft, not given a slot here.' : '+%d more drafts, not given a slot here.', (int) $more );
}
