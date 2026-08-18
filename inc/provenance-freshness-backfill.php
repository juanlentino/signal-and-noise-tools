<?php
/**
 * Signal & Noise Tools: one-shot backfill of the denormalized freshness clock.
 *
 * v11.11.8. `_sn_prov_last_commit_gmt` is written going forward by
 * sn_prov_stamp_last_commit() on both provenance write paths, but every chain
 * that already exists predates that write — so Check 4 would COALESCE them all
 * back to post_modified_gmt and the clock swap would change nothing for the
 * catalogue it was built for. This walks the existing chains once and stamps
 * them from their own newest `committed_at`.
 *
 * NOT REGISTERED IN sn_content_migrations_registry(). That registry sits behind
 * SN_CONTENT_MIGRATIONS_MASTER_OPT, and sn_run_content_migrations() returns
 * EARLY once that master flag is set — which it is on any install whose seed
 * migrations have finished. A new entry appended there would look registered,
 * pass the registry's own pinning test, and never execute in production. The
 * master sentinel is an optimisation over a CLOSED set; this is a new one, so
 * it carries its own flag and its own hook.
 *
 * Purely local: reads chain meta, writes stamp meta. No network, no content
 * writes, so it runs automatically rather than behind an operator button like
 * inc/provenance-chain-backfill.php (which fetches the public ledger).
 *
 * Idempotent and resumable: it only ever writes a stamp derived from the chain
 * itself, and the flag is set only when a full pass finds nothing left to do.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_FRESHNESS_BACKFILL_OPT = 'sn_prov_freshness_backfill_v1';

// Bounded per run so a large catalogue cannot turn one admin_init into a
// timeout. ~35 chains exist today; the cap only guards the future.
const SN_PROV_FRESHNESS_BACKFILL_CAP = 100;

/**
 * Published posts/pages carrying a provenance chain but no freshness stamp.
 *
 * The NOT EXISTS is what makes the run resumable: a stamped post drops out of
 * the candidate set permanently, so a capped pass always makes progress.
 *
 * @return int[] Post IDs, capped.
 */
function sn_prov_freshness_backfill_candidates() {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT c.post_id
		 FROM {$wpdb->postmeta} c
		 WHERE c.meta_key = %s
		   AND NOT EXISTS (
		       SELECT 1 FROM {$wpdb->postmeta} s
		       WHERE s.post_id = c.post_id
		         AND s.meta_key = %s
		         AND s.meta_value <> ''
		   )
		 LIMIT %d",
		SN_PROV_CHAIN_META,
		SN_PROV_LAST_COMMIT_META,
		SN_PROV_FRESHNESS_BACKFILL_CAP
	) );
	return array_map( 'intval', (array) $ids );
}

/**
 * Stamp every candidate from its own chain.
 *
 * A chain that commits nothing parsable yields '' and is counted as `empty`
 * rather than stamped with a guess — an unknown commit time must stay unknown,
 * so the consumer falls back to post_modified_gmt instead of inheriting a
 * fabricated one.
 *
 * @return array{stamped:int,empty:int,remaining:int}
 */
function sn_prov_freshness_backfill_run() {
	$stamped = 0;
	$empty   = 0;
	foreach ( sn_prov_freshness_backfill_candidates() as $post_id ) {
		$chain = sn_prov_get_chain( $post_id );
		if ( '' === sn_prov_stamp_last_commit( $post_id, $chain ) ) {
			$empty++;
			continue;
		}
		$stamped++;
	}
	return array(
		'stamped'   => $stamped,
		'empty'     => $empty,
		'remaining' => count( sn_prov_freshness_backfill_candidates() ),
	);
}

/**
 * One-shot entry point.
 *
 * The flag is set when a pass leaves nothing stampable behind — `remaining`
 * counts only chains that still lack a stamp, and a chain whose commits carry
 * no usable time is re-counted every pass, so the flag would never set if
 * `empty` blocked it. Hence: done when nothing REMAINING is stampable, which a
 * second run of the same candidates settles.
 */
function sn_prov_freshness_backfill_maybe_run() {
	if ( get_option( SN_PROV_FRESHNESS_BACKFILL_OPT ) ) {
		return;
	}
	$res = sn_prov_freshness_backfill_run();
	if ( 0 === (int) $res['remaining'] || 0 === (int) $res['stamped'] ) {
		// Nothing left, or a pass that stamped nothing new (only unparsable
		// chains remain) — either way, stop scanning every admin_init.
		update_option( SN_PROV_FRESHNESS_BACKFILL_OPT, time(), false );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'sn_prov_freshness_backfill_maybe_run' );
}
