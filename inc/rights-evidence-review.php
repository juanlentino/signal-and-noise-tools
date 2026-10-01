<?php
/**
 * Signal & Noise Tools: rights evidence, the review window and the holds.
 *
 * The first pass of a month composes and stores its records with
 * `review_until` (compose time + SN_RIGHTS_EVIDENCE_REVIEW) and posts
 * nothing; a later pass posts a composed month at or after review_until when
 * the month is not held. The hold list is the opt-out. Two rules hold a month
 * on their own, each with a stored reason: a compose or ledger-walk error,
 * and a per-family crawling.train that moved more than
 * SN_RIGHTS_EVIDENCE_JUMP_FACTOR times either way against the previous
 * month's posted value. The worker's 422 refusal holds too
 * (inc/rights-evidence-post-now.php). Nothing here fetches or posts.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a composed month waits before a pass may post it. */
const SN_RIGHTS_EVIDENCE_REVIEW = 3 * DAY_IN_SECONDS;

/** A train count that moved more than this factor either way holds the month. */
const SN_RIGHTS_EVIDENCE_JUMP_FACTOR = 3;

/** Both counts must be at least this for the jump rule to apply. */
const SN_RIGHTS_EVIDENCE_JUMP_FLOOR = 50;

/** A stored hold reason is cut to this many characters. */
const SN_RIGHTS_EVIDENCE_REASON_MAX = 300;

/**
 * Why each month was held automatically: month => reasons. A month the owner
 * held by hand has none.
 *
 * @return array<string,string[]>
 */
function sn_rights_evidence_hold_reasons() {
	$r = get_option( 'sn_rights_evidence_hold_reasons', array() );
	return is_array( $r ) ? $r : array();
}

/**
 * Put a month on the hold list, with a reason when a rule placed it, and
 * queue it in the backlog so a lift is worked even after the calendar moves on.
 *
 * @param string $ym     YYYY-MM.
 * @param string $reason Why ('' for an owner's hold).
 */
function sn_rights_evidence_hold_month( $ym, $reason = '' ) {
	$held = sn_rights_evidence_held();
	if ( ! in_array( $ym, $held, true ) ) {
		$held[] = $ym;
		update_option( 'sn_rights_evidence_hold', array_values( $held ), false );
	}
	$reason = substr( trim( (string) $reason ), 0, SN_RIGHTS_EVIDENCE_REASON_MAX );
	if ( '' !== $reason ) {
		$all        = sn_rights_evidence_hold_reasons();
		$all[ $ym ] = array_values( array_unique( array_merge( (array) ( $all[ $ym ] ?? array() ), array( $reason ) ) ) );
		update_option( 'sn_rights_evidence_hold_reasons', $all, false );
	}
	sn_rights_evidence_backlog_set( array_merge( sn_rights_evidence_backlog(), array( $ym ) ) );
}

/**
 * Whether a month waits in its review window: something of it is composed and
 * unposted, and every such entry's review_until is still ahead. An entry with
 * no review_until (stored before the window existed) is due.
 *
 * @param string $ym  YYYY-MM.
 * @param int    $now Unix time.
 * @return bool
 */
function sn_rights_evidence_in_review( $ym, $now ) {
	$waiting = 0;
	foreach ( (array) ( sn_rights_evidence_data()[ $ym ] ?? array() ) as $e ) {
		if ( ! is_array( $e ) || '' === (string) ( $e['canonical'] ?? '' ) || '' !== (string) ( $e['ledger_path'] ?? '' ) ) {
			continue;
		}
		if ( (int) ( $e['review_until'] ?? 0 ) <= $now ) {
			return false;
		}
		$waiting++;
	}
	return $waiting > 0;
}

/**
 * Months with composed, unposted bytes that are not held: month => the latest
 * review_until among its unposted entries (0 when none carries one).
 *
 * @return array<string,int> Oldest month first.
 */
function sn_rights_evidence_pending() {
	$held = sn_rights_evidence_held( false );
	$out  = array();
	foreach ( sn_rights_evidence_data() as $ym => $families ) {
		if ( in_array( (string) $ym, $held, true ) ) {
			continue;
		}
		foreach ( (array) $families as $e ) {
			if ( is_array( $e ) && '' !== (string) ( $e['canonical'] ?? '' ) && '' === (string) ( $e['ledger_path'] ?? '' ) ) {
				$out[ (string) $ym ] = max( (int) ( $out[ (string) $ym ] ?? 0 ), (int) ( $e['review_until'] ?? 0 ) );
			}
		}
	}
	ksort( $out );
	return $out;
}

/**
 * When a pending month posts, in words.
 *
 * @param int $until review_until.
 * @param int $now   Unix time.
 * @return string
 */
function sn_rights_evidence_window_words( $until, $now ) {
	if ( $until <= $now ) {
		return 'window passed, posts at the next pass';
	}
	return sprintf( 'posts after %s UTC (%d h left)', gmdate( 'Y-m-d H:i', $until ), (int) ceil( ( $until - $now ) / 3600 ) );
}

/**
 * The counts the jump rule compares, kept after the bytes leave for the ledger.
 *
 * @param array $payload A composed record.
 * @return array{reads:int,train:int}
 */
function sn_rights_evidence_summary( array $payload ) {
	return array(
		'reads' => (int) ( $payload['crawling']['reads'] ?? 0 ),
		'train' => (int) ( $payload['crawling']['train'] ?? 0 ),
	);
}

/**
 * PURE. Did a train count move more than the factor either way, with both at
 * or above the floor? Exactly the factor is not a jump.
 *
 * @param int $prev The previous month's posted value.
 * @param int $cur  This month's.
 * @return bool
 */
function sn_rights_evidence_train_jump( $prev, $cur ) {
	$prev = (int) $prev;
	$cur  = (int) $cur;
	return $prev >= SN_RIGHTS_EVIDENCE_JUMP_FLOOR && $cur >= SN_RIGHTS_EVIDENCE_JUMP_FLOOR
		&& ( $cur > SN_RIGHTS_EVIDENCE_JUMP_FACTOR * $prev || $prev > SN_RIGHTS_EVIDENCE_JUMP_FACTOR * $cur );
}

/**
 * The jump reason for one family's freshly composed summary, '' when none:
 * only against a previous-month entry that was posted (a ledger path, not a
 * conflict) and kept a summary.
 *
 * @param string $ym      YYYY-MM being composed.
 * @param string $family  Sensor family slug.
 * @param array  $summary {reads, train}.
 * @return string
 */
function sn_rights_evidence_jump_reason( $ym, $family, array $summary ) {
	$start = strtotime( $ym . '-01T00:00:00Z' );
	if ( false === $start ) {
		return '';
	}
	$prev_ym = gmdate( 'Y-m', gmmktime( 0, 0, 0, (int) gmdate( 'n', $start ) - 1, 1, (int) gmdate( 'Y', $start ) ) );
	$prev    = sn_rights_evidence_data()[ $prev_ym ][ $family ] ?? null;
	if ( ! is_array( $prev ) || '' === (string) ( $prev['ledger_path'] ?? '' ) || 'conflict' === (string) ( $prev['status'] ?? '' ) || ! isset( $prev['summary']['train'] ) ) {
		return '';
	}
	$was = (int) $prev['summary']['train'];
	$now = (int) ( $summary['train'] ?? 0 );
	if ( ! sn_rights_evidence_train_jump( $was, $now ) ) {
		return '';
	}
	return sprintf( '%s crawling.train moved from %d in %s to %d, more than %dx', $family, $was, $prev_ym, $now, SN_RIGHTS_EVIDENCE_JUMP_FACTOR );
}

/**
 * A worker refusal's divergences as one line.
 *
 * @param string $family    Sensor family slug.
 * @param mixed  $divergences [[block, message], ...].
 * @return string
 */
function sn_rights_evidence_refusal_reason( $family, $divergences ) {
	$parts = array();
	foreach ( (array) $divergences as $d ) {
		$d       = array_values( (array) $d );
		$parts[] = trim( (string) ( $d[0] ?? '' ) . ': ' . (string) ( $d[1] ?? '' ), ': ' );
	}
	return sprintf( 'the worker refused %s: %s', $family, $parts ? implode( '; ', $parts ) : 'no divergences given' );
}
