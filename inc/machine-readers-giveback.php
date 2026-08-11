<?php
/**
 * Signal & Noise Tools — the give-back ratio: readers returned per crawl.
 *
 * The board row: "the ledger's crawl counts set against that operator's referred
 * human visits — so the page that says who reads by machine also says which
 * machines ever send a reader back". Its gate was the operator map
 * (inc/machine-readers-operators.php), which is what makes the two sides
 * comparable; this is the division built on top of it.
 *
 * PURE BY CONSTRUCTION. It is handed a snapshot and a referral map and fetches
 * nothing — no sensor call, no query, no transient. That is 3A's gate restated:
 * a reader's page must never wait on a sensor, so anything that might render
 * takes its inputs as arguments. It is also what lets this be tested without a
 * database.
 *
 * THE WHOLE DIFFICULTY IS THE ZEROES, and there are three:
 *
 *   crawled 400, referred 0  →  0.0        REAL, and the most interesting answer
 *                                          the row exists to publish.
 *   crawled 0,   referred 0  →  undefined  Nothing to divide by. NOT zero.
 *   no crawl data            →  unknown    Either the sensor never answered, or
 *                                          this operator has no crawler family
 *                                          here at all.
 *
 * Every possible collapse between them runs in the flattering direction — it
 * makes the site look more crawled, or more repaid, than the data says. So the
 * status is carried explicitly and the ratio is null wherever it is not earned.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sum an operator's referred visits across every label it owns.
 *
 * A label MISSING from a measured map is a measured zero: the analytics side
 * counts every visit in the window, so a label nobody arrived from simply has no
 * row. That is the opposite of the map itself being absent, which is unknown and
 * returns null — the same absent-vs-zero distinction the snapshot draws one
 * layer down.
 *
 * @param string[]   $labels    The operator's source labels.
 * @param array|null $referrals label => visits, or null when not measured.
 * @return int|null Visits, or null when the referral side was never measured.
 */
function snt_mr_giveback_referrals_for( $labels, $referrals ) {
	if ( ! is_array( $referrals ) ) {
		return null;
	}
	$sum = 0;
	foreach ( (array) $labels as $label ) {
		$sum += max( 0, (int) ( $referrals[ $label ] ?? 0 ) );
	}
	return $sum;
}

/**
 * The give-back reading for one operator.
 *
 * @param string     $key       Operator key from snt_mr_operators().
 * @param array|null $snap      A snt_mr_snapshot() record, or null.
 * @param array|null $referrals label => visits, or null when not measured.
 * @return array{operator:string,label:string,crawls:?int,referrals:?int,ratio:?float,status:string}|null
 *         Null only when the operator itself is unknown — never a fabricated row.
 */
function snt_mr_giveback_for_operator( $key, $snap, $referrals ) {
	$ops = snt_mr_operators();
	if ( ! isset( $ops[ $key ] ) ) {
		return null;
	}
	$op  = $ops[ $key ];
	$row = array(
		'operator'  => $key,
		'label'     => $op['label'],
		'crawls'    => null,
		'referrals' => null,
		'ratio'     => null,
		'status'    => 'unmeasured',
	);

	$refs            = snt_mr_giveback_referrals_for( $op['sources'], $referrals );
	$row['referrals'] = $refs;

	// This operator has no crawler family the sensor can distinguish, so a
	// denominator will never exist for it however long the window runs. That is
	// a permanent property of the map, not a gap in today's data, and it is
	// reported before the snapshot is even consulted — the referral count above
	// is still real and still worth showing.
	if ( ! snt_mr_operator_is_measurable( $key ) ) {
		$row['status'] = 'not_measurable';
		return $row;
	}

	// Either side unmeasured makes the ratio unknown. A sensor that never
	// answered is not a site nobody crawled, and an unmeasured referral side
	// would otherwise render every operator as never having repaid.
	if ( ! snt_mr_snapshot_has_measurement( $snap ) || null === $refs ) {
		$row['status'] = 'unmeasured';
		$row['crawls'] = null;
		return $row;
	}

	$by_family = isset( $snap['by_family'] ) && is_array( $snap['by_family'] ) ? $snap['by_family'] : array();
	$crawls    = 0;
	foreach ( $op['families'] as $family ) {
		// Absent from a measured window means this family read nothing — a zero
		// contribution, not an unknown one. The unknown case was settled above.
		$crawls += max( 0, (int) ( $by_family[ $family ] ?? 0 ) );
	}
	$row['crawls'] = $crawls;

	if ( 0 === $crawls ) {
		// Measured, and this operator did not read the site. The ratio is
		// UNDEFINED — there is no denominator — which is a different statement
		// from "read the site and sent nobody back".
		$row['status'] = 'no_crawls';
		return $row;
	}

	// (float) on purpose: PHP's / returns int when the division is exact, so a
	// ratio of 0 or 1 would arrive as int and every other as float. A caller
	// comparing strictly, or a renderer formatting decimals, should not have to
	// know which case it got.
	$row['ratio']  = (float) $refs / $crawls;
	$row['status'] = $refs > 0 ? 'ok' : 'none_returned';
	return $row;
}

/**
 * Every operator's reading, in map order.
 *
 * All operators appear, including the ones with nothing to say. An operator
 * dropped from the table because it has no data reads as "no such crawler",
 * which is a stronger claim than the absence it is standing in for.
 *
 * @param array|null $snap      A snt_mr_snapshot() record, or null.
 * @param array|null $referrals label => visits, or null when not measured.
 * @return array<int,array> Rows as returned by snt_mr_giveback_for_operator().
 */
function snt_mr_giveback_table( $snap, $referrals ) {
	$rows = array();
	foreach ( array_keys( snt_mr_operators() ) as $key ) {
		$row = snt_mr_giveback_for_operator( $key, $snap, $referrals );
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}
	return $rows;
}
