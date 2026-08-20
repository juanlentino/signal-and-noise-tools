<?php
/**
 * Signal & Noise — how old is the screen?
 *
 * THE PROBLEM. Every figure on the dashboard renders in the same type under
 * one present-tense headline, while the readings behind it are taken across
 * wildly different intervals: the analytics rollup is a DAILY cron, the fleet
 * probes cache for ten minutes, a purge stamp is seconds old. Measured live
 * 2026-08-19 the rollup had last fired thirteen hours earlier — so "Everything
 * is holding" was a claim about now, assembled from evidence that was mostly
 * from yesterday, with nothing on screen saying so.
 *
 * This plugin already refuses to fabricate a VALUE it does not have: "not seen
 * yet" rather than a zero, an em dash for an absent repo, null rather than a
 * measured zero. It was applying none of that discipline to TIME, and the
 * dashboard is the one surface whose entire job is to be trusted at a glance.
 *
 * TWO RULES, both of which cost something to get wrong.
 *
 * 1. NEVER-MEASURED IS NOT OLD. A source that has never reported is unknown,
 *    not infinitely stale. Folding it into the oldest-reading figure would let
 *    one untracked hook pin the subline to a permanent fake maximum, which is
 *    exactly the kind of number that teaches an operator to ignore the line.
 *
 * 2. STALENESS IS PER-SOURCE. Thirteen hours is unremarkable for a daily
 *    rollup and badly overdue for a five-minute probe. Any single global
 *    threshold gets one of those wrong — it either cries wolf about the rollup
 *    every day, or stays silent while a probe is dead. Each reading therefore
 *    declares the cadence it is late against.
 *
 * PURE MODULE. Zero WordPress calls beyond translation and human_time_diff,
 * zero I/O: the caller fetches the timestamps and this decides what they mean,
 * which is what lets the index.php widget use it on every admin login.
 *
 * @package SignalNoiseTools
 * @since 11.31.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduce a set of readings to what the screen can honestly claim.
 *
 * @since 11.31.2
 * @param array<int,array{label:string,measured_at:int|null,stale_after:int}> $readings
 * @param int|null                                                           $now Unix time; defaults to now.
 * @return array{oldest:array{label:string,age:int}|null,unmeasured:array<int,string>,stale:array<int,array{label:string,age:int,stale_after:int}>}
 */
function sn_dash_freshness( array $readings, $now = null ) {
	$now        = null === $now ? time() : (int) $now;
	$oldest     = null;
	$unmeasured = array();
	$stale      = array();

	foreach ( $readings as $reading ) {
		if ( ! is_array( $reading ) ) {
			continue;
		}
		$label = (string) ( $reading['label'] ?? '' );
		if ( '' === $label ) {
			continue;
		}

		// array_key_exists, not isset: an explicit null is the caller SAYING
		// "never measured", and isset() erases that into the same branch as a
		// missing key.
		$measured = array_key_exists( 'measured_at', $reading ) ? $reading['measured_at'] : null;
		if ( null === $measured ) {
			$unmeasured[] = $label;
			continue;
		}

		// Clock skew clamps to zero. A negative age would render as a reading
		// taken in the future, which is a stranger claim than "just now".
		$age = max( 0, $now - (int) $measured );

		$budget = (int) ( $reading['stale_after'] ?? 0 );
		if ( $budget > 0 && $age > $budget ) {
			$stale[] = array( 'label' => $label, 'age' => $age, 'stale_after' => $budget );
		}

		if ( null === $oldest || $age > $oldest['age'] ) {
			$oldest = array( 'label' => $label, 'age' => $age );
		}
	}

	return array(
		'oldest'     => $oldest,
		'unmeasured' => $unmeasured,
		'stale'      => $stale,
	);
}

/**
 * The subline fragment: what the headline is actually as-of.
 *
 * Empty when nothing has been measured — an absent fact is not rendered as
 * "oldest reading unknown ago".
 *
 * @since 11.31.2
 * @param array<string,mixed> $freshness Output of sn_dash_freshness().
 * @return string
 */
function sn_dash_freshness_label( array $freshness ) {
	$oldest = isset( $freshness['oldest'] ) && is_array( $freshness['oldest'] ) ? $freshness['oldest'] : null;
	if ( null === $oldest ) {
		return '';
	}
	return sprintf(
		/* translators: %s human-readable age of the oldest reading on the screen */
		__( 'oldest reading %s', 'signal-and-noise-tools' ),
		human_time_diff( time() - (int) $oldest['age'], time() )
	);
}

/**
 * Turn stale readings into glance cards.
 *
 * Deliberately NOT a second alarm channel. A stale reading becomes a card and
 * flows through the one shared sn_dash_verdict(), so the index.php widget and
 * the full screen raise it identically — two surfaces deriving an alarm
 * separately is how you get a green widget above a red screen.
 *
 * The pill is `warn`, never `err`: an overdue reading is UNMEASURED, not
 * proven bad, and painting it red would spend the colour that is supposed to
 * mean something is actually broken.
 *
 * @since 11.31.2
 * @param array<string,mixed> $freshness Output of sn_dash_freshness().
 * @return array<int,array<string,mixed>>
 */
function sn_dash_freshness_cards( array $freshness ) {
	$cards = array();
	$stale = isset( $freshness['stale'] ) && is_array( $freshness['stale'] ) ? $freshness['stale'] : array();

	foreach ( $stale as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$cards[] = array(
			'label' => (string) ( $item['label'] ?? '' ),
			'value' => sprintf(
				/* translators: %s human-readable age of an overdue reading */
				__( 'last measured %s ago', 'signal-and-noise-tools' ),
				human_time_diff( time() - (int) ( $item['age'] ?? 0 ), time() )
			),
			'pill'  => array(
				'kind' => 'warn',
				'text' => __( 'overdue', 'signal-and-noise-tools' ),
			),
		);
	}

	return $cards;
}
