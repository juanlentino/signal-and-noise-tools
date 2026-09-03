<?php
/**
 * Shape ledger — does a payload's STRUCTURE hold still?
 *
 * Built to answer one recurring question without anyone having to remember to
 * look: is a payload stable enough to freeze into a contract? A remote MCP twin
 * copies its origin's `output_schema` BYTE-IDENTICALLY, so shipping one freezes
 * the local shape on that day; changing it afterwards costs a contract bump plus
 * a worker release. The honest precondition is "this structure has not moved in
 * N readings over M days", and until now that was a memory, not a measurement.
 *
 * GENERIC BY CONSTRUCTION. Nothing here knows what any particular payload is;
 * a subject is a string and its open paths are declared by the caller. The next
 * twin candidate reuses it unchanged.
 *
 * STRUCTURE, NOT CONTENT — the whole difficulty. `reader-anomalies` carries an
 * `excluded` map keyed by FAMILY NAME, so a family crossing the eligibility floor
 * removes a key. That is normal data variation, and an instrument that reports it
 * as a shape change is one nobody reads by the second month. Declared open paths
 * collapse to a wildcard key; everything else is compared key by key.
 *
 * @package Signal_And_Noise_Tools
 * @since   13.84.0
 */

defined( 'ABSPATH' ) || exit;

/** Where the ledger lives. autoload=no: read on demand, never on every request. */
const SN_SHAPE_LEDGER_OPTION = 'sn_shape_ledger_v1';

/** Changes retained per subject. A ring, not a log — this is a verdict input. */
const SN_SHAPE_LEDGER_MAX_CHANGES = 10;

/** Wall-clock span a structure must hold before it counts as settled. */
const SN_SHAPE_STABLE_DAYS = 7;

/**
 * Readings a structure must hold across, independent of span.
 *
 * BOTH gates, deliberately. A span alone passes on two readings a week apart,
 * which measures nothing; a count alone passes on twenty readings in an hour.
 * The cadence detector learned exactly this in v10.32.0 — a fixed-COUNT window
 * needs a wall-clock gate before its statistics mean anything, and the converse
 * holds too.
 */
const SN_SHAPE_STABLE_READINGS = 24;

/**
 * A canonical fingerprint of a value's STRUCTURE.
 *
 * Deterministic and order-independent: map keys sort, and a list folds to the
 * sorted UNIQUE set of its elements' fingerprints, so neither key order nor row
 * order can move the result. A list's union (rather than its first element)
 * means an optional field appearing on one row is visible, and row ordering is
 * not.
 *
 * @param mixed    $value
 * @param string[] $open  Dot paths whose MAP KEYS are data, not structure
 *                        (e.g. 'excluded'), collapsed to a single '*' key.
 * @param string   $path  Internal: the current dot path.
 * @return string
 */
function sn_shape_fingerprint( $value, array $open = array(), $path = '' ) {
	if ( is_array( $value ) ) {
		$is_list = ( array() === $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			$parts = array();
			foreach ( $value as $v ) {
				$parts[] = sn_shape_fingerprint( $v, $open, $path . '[]' );
			}
			$parts = array_values( array_unique( $parts ) );
			sort( $parts );
			return '[' . implode( '|', $parts ) . ']';
		}
		if ( in_array( $path, $open, true ) ) {
			// Keys here are DATA. Collapse them, keep the union of value shapes.
			$parts = array();
			foreach ( $value as $v ) {
				$parts[] = sn_shape_fingerprint( $v, $open, $path . '.*' );
			}
			$parts = array_values( array_unique( $parts ) );
			sort( $parts );
			return '{*:' . implode( '|', $parts ) . '}';
		}
		$keys = array_keys( $value );
		sort( $keys );
		$parts = array();
		foreach ( $keys as $k ) {
			$child   = ( '' === $path ) ? (string) $k : $path . '.' . $k;
			$parts[] = $k . ':' . sn_shape_fingerprint( $value[ $k ], $open, $child );
		}
		return '{' . implode( ',', $parts ) . '}';
	}
	if ( is_bool( $value ) ) {
		return 'bool';
	}
	if ( is_int( $value ) ) {
		return 'int';
	}
	if ( is_float( $value ) ) {
		return 'float';
	}
	if ( null === $value ) {
		return 'null';
	}
	return 'string';
}

/**
 * Record one reading. Returns the subject's ledger entry after recording.
 *
 * Cheap enough to call on every real invocation of the thing being watched,
 * which is why it is NOT wired to a new cron: an extra scheduled fetch would add
 * outbound load to observe a payload that is already being produced. Irregular
 * cadence is fine — the verdict gates on span AND count, so gaps cannot fake
 * stability.
 *
 * @param string $subject Stable id, e.g. 'reader-anomalies'.
 * @param string $fp      Fingerprint from sn_shape_fingerprint().
 * @param int    $now     Unix time.
 * @return array
 */
function sn_shape_ledger_record( $subject, $fp, $now ) {
	$all = get_option( SN_SHAPE_LEDGER_OPTION, array() );
	if ( ! is_array( $all ) ) {
		$all = array();
	}
	$e = isset( $all[ $subject ] ) && is_array( $all[ $subject ] ) ? $all[ $subject ] : null;

	if ( null === $e || ( $e['fp'] ?? null ) !== $fp ) {
		$changes = ( null === $e ) ? array() : (array) ( $e['changes'] ?? array() );
		if ( null !== $e ) {
			$changes[] = array( 'at' => (int) $now, 'from' => (string) ( $e['fp'] ?? '' ), 'to' => (string) $fp );
			$changes   = array_slice( $changes, -SN_SHAPE_LEDGER_MAX_CHANGES );
		}
		$e = array(
			'fp'       => (string) $fp,
			'since'    => (int) $now,
			'readings' => 1,
			'last'     => (int) $now,
			'changes'  => $changes,
		);
	} else {
		$e['readings'] = (int) $e['readings'] + 1;
		$e['last']     = (int) $now;
	}

	$all[ $subject ] = $e;
	update_option( SN_SHAPE_LEDGER_OPTION, $all, false );
	return $e;
}

/** One subject's entry, or null when never recorded. */
function sn_shape_ledger_get( $subject ) {
	$all = get_option( SN_SHAPE_LEDGER_OPTION, array() );
	return ( is_array( $all ) && isset( $all[ $subject ] ) && is_array( $all[ $subject ] ) ) ? $all[ $subject ] : null;
}

/**
 * Every subject the ledger has recorded.
 *
 * Added in v13.88.0 with the first reader. The module had a writer, a
 * per-subject getter and a per-subject verdict, but no way to ASK WHAT IT
 * HOLDS — so a caller had to know a subject's name in advance, or reach past
 * this module to SN_SHAPE_LEDGER_OPTION and re-derive the storage shape.
 *
 * @return string[] Subject names, sorted for a stable readout.
 */
function sn_shape_ledger_subjects() {
	$all = get_option( SN_SHAPE_LEDGER_OPTION, array() );
	if ( ! is_array( $all ) ) {
		return array();
	}
	$subjects = array();
	foreach ( $all as $subject => $entry ) {
		if ( is_string( $subject ) && '' !== $subject && is_array( $entry ) ) {
			$subjects[] = $subject;
		}
	}
	sort( $subjects );
	return $subjects;
}

/**
 * Has this subject's structure settled?
 *
 * Never recorded is UNKNOWN, not unstable — an absent instrument does not get to
 * vote either way.
 *
 * @return array{state:string,readings:int,days:float,since:?int,reason:string}
 */
function sn_shape_stability( $subject, $now ) {
	$e = sn_shape_ledger_get( $subject );
	if ( null === $e ) {
		return array( 'state' => 'unknown', 'readings' => 0, 'days' => 0.0, 'since' => null, 'reason' => 'never recorded' );
	}
	$readings = (int) ( $e['readings'] ?? 0 );
	$since    = (int) ( $e['since'] ?? 0 );
	$days     = ( $since > 0 ) ? ( (int) $now - $since ) / DAY_IN_SECONDS : 0.0;
	$ok_days  = $days >= SN_SHAPE_STABLE_DAYS;
	$ok_reads = $readings >= SN_SHAPE_STABLE_READINGS;
	if ( $ok_days && $ok_reads ) {
		return array(
			'state'    => 'settled',
			'readings' => $readings,
			'days'     => round( $days, 1 ),
			'since'    => $since,
			'reason'   => sprintf( 'unchanged across %d readings over %.1f days', $readings, $days ),
		);
	}
	$missing = array();
	if ( ! $ok_days ) {
		$missing[] = sprintf( '%.1f of %d days', $days, SN_SHAPE_STABLE_DAYS );
	}
	if ( ! $ok_reads ) {
		$missing[] = sprintf( '%d of %d readings', $readings, SN_SHAPE_STABLE_READINGS );
	}
	return array(
		'state'    => 'settling',
		'readings' => $readings,
		'days'     => round( $days, 1 ),
		'since'    => $since,
		'reason'   => implode( ', ', $missing ),
	);
}
