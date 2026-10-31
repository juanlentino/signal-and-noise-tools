<?php
/**
 * Signal & Noise Tools: Check now, the owner's live URL Inspection of the
 * marked rows and the request list (2026-10-31). Only the owner's click
 * spends quota (inc/admin-post-actions/gsc-indexing.php); every reader reads
 * the stored map.
 *
 * A press starts no new inspection after SNT_GSC_CHECK_NOW_BUDGET seconds
 * (one page can still take up to SNT_GSC_COVERAGE_TIMEOUT more) and says what
 * is left. The next press continues: a page answered since the partial press
 * began is skipped, so no page is inspected twice for one check. A complete
 * check that got at least one answer locks the button for
 * SNT_GSC_CHECK_NOW_GAP seconds.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_GSC_CHECK_NOW_LAST   = 'snt_gsc_check_now_last';
const SNT_GSC_CHECK_NOW_GAP    = 600; // seconds after a complete check before the next press.
const SNT_GSC_CHECK_NOW_BUDGET = 20;  // seconds after which a press starts no new inspection.

/**
 * Post ids Check now inspects: the marked rows Google has not indexed first
 * (the owner's own question), then the request list (7 days on), deduped.
 * $since > 0 continues a partial press: an entry inspected at or after it is
 * skipped. PURE.
 *
 * @param array $entries   Coverage map entries.
 * @param array $published post_id => publish time.
 * @param array $marks     snt_gsc_requested_marks().
 * @param int   $now
 * @param int   $since     snt_gsc_check_now_since().
 * @return int[]
 */
function snt_gsc_check_now_targets( array $entries, array $published, array $marks, $now, $since = 0 ) {
	$days  = defined( 'SNT_INDEXING_REQUEST_DAYS' ) ? SNT_INDEXING_REQUEST_DAYS : 7;
	$ids   = array();
	$fresh = array();
	foreach ( $entries as $p => $e ) {
		if ( (int) $since > 0 && (int) ( $e['inspected_at'] ?? 0 ) >= (int) $since ) {
			$fresh[ (int) ( $e['post_id'] ?? 0 ) ] = true;
		}
		if ( isset( $marks[ $p ] ) && snt_gsc_index_gap_candidate( $e ) ) {
			$ids[] = (int) $e['post_id'];
		}
	}
	$ids = array_merge( $ids, array_map( 'intval', array_column( snt_gsc_index_stuck( $entries, $published, $now, $days ), 'post_id' ) ) );
	return array_values( array_filter( array_unique( $ids ), static fn( $id ) => ! isset( $fresh[ $id ] ) ) );
}

/** When the last press stopped short, the time it began (continue from there); else 0. */
function snt_gsc_check_now_since() {
	$last = get_option( SNT_GSC_CHECK_NOW_LAST, array() );
	return is_array( $last ) && (int) ( $last['remaining'] ?? 0 ) > 0 ? (int) ( $last['at'] ?? 0 ) : 0;
}

/**
 * Inspect the targets now, forced past the weekly freshness guard, inside the
 * budget. Refused within SNT_GSC_CHECK_NOW_GAP of a complete check that got an
 * answer; a partial one can be pressed again at once for the rest.
 *
 * @param int           $now
 * @param int[]         $targets Post ids.
 * @param callable|null $inspect fn( $post_id, $days, $force ); snt_gsc_inspect_one by default.
 * @param callable|null $clock   fn(): float seconds; microtime by default.
 * @return array{at:int,checked:int,errors:int,remaining:int,budget_hit:bool}|WP_Error
 */
function snt_gsc_check_now( $now, array $targets, $inspect = null, $clock = null ) {
	if ( ! function_exists( 'snt_gsc_sync_is_ready' ) || ! snt_gsc_sync_is_ready() ) {
		return new WP_Error( 'snt_gsc_not_ready', 'Search Console is not configured.' );
	}
	$last = get_option( SNT_GSC_CHECK_NOW_LAST, array() );
	if ( is_array( $last ) && 0 === (int) ( $last['remaining'] ?? 0 ) && (int) ( $last['checked'] ?? 0 ) > (int) ( $last['errors'] ?? 0 ) && (int) $now - (int) ( $last['at'] ?? 0 ) < SNT_GSC_CHECK_NOW_GAP ) {
		return new WP_Error( 'snt_gsc_check_wait', 'Checked less than 10 minutes ago.' );
	}
	$inspect = $inspect ?? 'snt_gsc_inspect_one';
	$clock   = $clock ?? static fn() => microtime( true );
	$start   = $clock();
	$out     = array( 'at' => (int) $now, 'checked' => 0, 'errors' => 0, 'remaining' => 0, 'budget_hit' => false );
	foreach ( $targets as $id ) {
		if ( $clock() - $start >= SNT_GSC_CHECK_NOW_BUDGET ) {
			$out['budget_hit'] = true;
			++$out['remaining'];
			continue;
		}
		$entry = $inspect( (int) $id, 0, true );
		++$out['checked'];
		$out['errors'] += ( is_wp_error( $entry ) || ( is_array( $entry ) && isset( $entry['error'] ) ) ) ? 1 : 0;
	}
	update_option( SNT_GSC_CHECK_NOW_LAST, $out, false );
	return $out;
}
