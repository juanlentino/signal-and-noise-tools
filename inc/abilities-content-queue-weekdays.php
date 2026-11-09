<?php
/**
 * Signal & Noise Tools: the content queue's weekday rules (2026-11-09, queue
 * calendar phase 2, item 4).
 *
 * The owner's rule (2026-11-09): from Jan 4, 2027, notes go out on Mondays
 * and Thursdays. Rules live in the option sn_content_queue_weekday_rules as
 * "from|to|days" (Y-m-d; to empty when the rule runs on; days the ISO
 * weekdays 1 to 7, Monday first), set from the Weekday rules box on both
 * Connections › Scheduled twins. A scheduled note on a day its rule leaves
 * out is an "Off day" line among the cadence warnings, and a suggested slot
 * lands only on a day its rule allows. Where two rules cover a day, the one
 * that starts later wins. The boxes live in
 * abilities-content-queue-weekdays-admin.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The option the rules live in. */
const SN_CONTENT_QUEUE_WEEKDAY_OPT = 'sn_content_queue_weekday_rules';

/** How many rules are kept; the oldest saved drops off. */
const SN_CONTENT_QUEUE_WEEKDAY_MAX = 20;

/** One stored rule: from, an optional to, and the allowed ISO weekdays. */
const SN_CONTENT_QUEUE_WEEKDAY_RE = '/^(\d{4}-\d{2}-\d{2})\|((?:\d{4}-\d{2}-\d{2})?)\|([1-7]{1,7})\z/';

/**
 * The stored rules, as "from|to|days", malformed ones dropped.
 *
 * @return string[]
 */
function sn_content_queue_weekday_rules() {
	$v = get_option( SN_CONTENT_QUEUE_WEEKDAY_OPT, array() );
	return array_values( array_filter( (array) $v, static fn( $k ) => is_string( $k ) && 1 === preg_match( SN_CONTENT_QUEUE_WEEKDAY_RE, $k ) ) );
}

/**
 * The rule that governs one day: the latest-starting rule that covers it,
 * or null when none does (any weekday is fine). PURE.
 *
 * @param string   $ymd   The day, Y-m-d.
 * @param string[] $rules Stored rules.
 * @return array{from:string,to:string,days:int[]}|null
 */
function sn_content_queue_weekday_rule_for( $ymd, array $rules ) {
	$best = null;
	foreach ( $rules as $k ) {
		if ( ! is_string( $k ) || 1 !== preg_match( SN_CONTENT_QUEUE_WEEKDAY_RE, $k, $m ) ) {
			continue;
		}
		if ( $m[1] <= $ymd && ( '' === $m[2] || $ymd <= $m[2] ) && ( null === $best || $m[1] >= $best['from'] ) ) {
			$best = array( 'from' => $m[1], 'to' => $m[2], 'days' => array_values( array_unique( array_map( 'intval', str_split( $m[3] ) ) ) ) );
		}
	}
	return $best;
}

/**
 * Whether a day may carry a note under the rules. PURE.
 *
 * @param DateTimeInterface $day   The day, in the site timezone.
 * @param string[]          $rules Stored rules.
 * @return bool
 */
function sn_content_queue_weekday_allows( DateTimeInterface $day, array $rules ) {
	$r = sn_content_queue_weekday_rule_for( $day->format( 'Y-m-d' ), $rules );
	return null === $r || in_array( (int) $day->format( 'N' ), $r['days'], true );
}

/**
 * One rule in words: "from Jan 4, 2027, notes go out on Mon and Thu". PURE.
 *
 * @param array{from:string,to:string,days:int[]} $rule  A governing rule.
 * @param DateTimeImmutable                       $today Today, in the site timezone.
 * @return string
 */
function sn_content_queue_weekday_rule_words( array $rule, DateTimeImmutable $today ) {
	$names = array( 1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' );
	$days  = $rule['days'];
	sort( $days );
	$n    = array_map( static fn( $d ) => $names[ $d ], $days );
	$last = array_pop( $n );
	$on   = array() === $n ? $last : implode( ', ', $n ) . ' and ' . $last;
	$day  = static fn( $ymd ) => sn_content_queue_day( new DateTimeImmutable( $ymd, $today->getTimezone() ), $today );
	$span = '' === $rule['to'] ? 'from ' . $day( $rule['from'] ) : $day( $rule['from'] ) . ' to ' . $day( $rule['to'] );
	return sprintf( '%s, notes go out on %s', $span, $on );
}

/**
 * The off-day warnings: each day a scheduled note falls on a weekday its
 * rule leaves out, soonest first, in the cadence warnings' shape. PURE.
 *
 * @param int[]        $scheduled Scheduled Unix times (any order).
 * @param int          $now       Unix time now (for the labels' year).
 * @param DateTimeZone $tz        Site timezone.
 * @param string[]     $rules     Stored rules.
 * @return array<int,array{kind:string,from:string,to:string,days:int,count:int,text:string}>
 */
function sn_content_queue_offdays( $scheduled, $now, DateTimeZone $tz, array $rules ) {
	if ( array() === $rules ) {
		return array();
	}
	$today = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
	$off   = array(); // Y-m-d => [day, notes, rule].
	foreach ( array_map( 'intval', (array) $scheduled ) as $t ) {
		$d = ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz );
		$k = $d->format( 'Y-m-d' );
		$r = sn_content_queue_weekday_rule_for( $k, $rules );
		if ( null !== $r && ! in_array( (int) $d->format( 'N' ), $r['days'], true ) ) {
			$off[ $k ] = array( $d, ( $off[ $k ][1] ?? 0 ) + 1, $r );
		}
	}
	ksort( $off );
	$out = array();
	foreach ( $off as $k => $o ) {
		$out[] = array(
			'kind'  => 'offday',
			'from'  => $k,
			'to'    => $k,
			'days'  => 0,
			'count' => $o[1],
			'text'  => sprintf( 'Off day: %s on %s, %s (%s)', 1 === $o[1] ? 'a note' : $o[1] . ' notes', $o[0]->format( 'D' ), sn_content_queue_day( $o[0], $today ), sn_content_queue_weekday_rule_words( $o[2], $today ) ),
		);
	}
	return $out;
}

/**
 * Saves one rule, or removes it. A start day that already has a rule is
 * replaced; no weekday removes the rule that starts that day.
 *
 * @param string $from  First day, Y-m-d.
 * @param string $to    Last day, Y-m-d, or '' to run on.
 * @param int[]  $days  ISO weekdays (1 = Monday).
 * @return string An sn_flash code.
 */
function sn_content_queue_weekday_save( $from, $to, array $days ) {
	$from = (string) $from;
	$to   = (string) $to;
	$ok   = static fn( $d ) => 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	if ( ! $ok( $from ) || ( '' !== $to && ( ! $ok( $to ) || $to < $from ) ) ) {
		return 'queue_weekday_invalid';
	}
	$days = array_values( array_unique( array_filter( array_map( 'intval', $days ), static fn( $d ) => $d >= 1 && $d <= 7 ) ) );
	sort( $days );
	$rules = sn_content_queue_weekday_rules();
	$keep  = array_values( array_filter( $rules, static fn( $k ) => 0 !== strpos( $k, $from . '|' ) ) );
	if ( array() === $days ) {
		if ( count( $keep ) === count( $rules ) ) {
			return 'queue_weekday_invalid';
		}
		update_option( SN_CONTENT_QUEUE_WEEKDAY_OPT, $keep, false );
		return 'queue_weekday_removed';
	}
	$keep[] = $from . '|' . $to . '|' . implode( '', $days );
	update_option( SN_CONTENT_QUEUE_WEEKDAY_OPT, array_slice( $keep, -SN_CONTENT_QUEUE_WEEKDAY_MAX ), false );
	return 'queue_weekday_saved';
}

// 2026-11-09, the owner's pick: from Jan 4, 2027, Mondays and Thursdays.
// Seeded once into the option; a list the owner empties stays empty.
add_action(
	'admin_init',
	static function () {
		if ( false === get_option( SN_CONTENT_QUEUE_WEEKDAY_OPT ) ) {
			add_option( SN_CONTENT_QUEUE_WEEKDAY_OPT, array( '2027-01-04||14' ), '', false );
		}
	}
);

require_once __DIR__ . '/abilities-content-queue-weekdays-admin.php';
