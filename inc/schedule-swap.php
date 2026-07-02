<?php
/**
 * Signal & Noise Tools: scheduled-content version swaps (Phase 3, v8.0.0).
 *
 * A "version swap" is the whole-page two-container pattern made first-class:
 * two sn/scheduled fragments on the SAME host post whose boundaries meet at
 * one instant T — the old container's `until` equals the new container's
 * `from`. At T the old gates off, the new gates on, and one edge purge
 * reveals the swap.
 *
 * Design constraints honored here:
 *   - NO schema change. Pairs are DERIVED from existing queue rows by
 *     boundary equality on the same target (sn_schedule_swap_pairs), so
 *     hand-authored pre-v8 pairs surface too. The editor-side swapId /
 *     swapRole attributes (blocks/scheduled) exist only to keep the two
 *     boundaries in lockstep while authoring; by construction the derived
 *     pairing stays reliable.
 *   - ONE boundary purge. Firing both sides goes through the per-request
 *     purge memo in inc/schedule-cache.php, which collapses the second
 *     same-URL-set (or second zone) dispatch. Correct because the render
 *     gate is pure time: once T passes, a single edge refetch sees both
 *     flips.
 *   - REUSE the fire state machine. sn_schedule_swap_run() drives the two
 *     rows through sn_schedule_fire() — status transitions, error/retry
 *     holding, and the v7.3.0 purge escalation all apply unchanged.
 *
 * The admin leaf (inc/schedule-admin.php) lists derived pairs above the
 * fragment table with a single "Run swap now" op; recurrence stays out
 * (YAGNI: no trigger evidence — see the 2026-06-25 design doc's Phase 3
 * guard).
 *
 * @package SignalNoiseTools
 * @since 8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive version-swap pairs from a set of schedule rows.
 *
 * A pair is (hide, show) where both rows are 'fragment' type on the SAME
 * target_ref and hide.ends_at === show.starts_at with a non-empty boundary.
 * Chains pair consecutively: A(until T) + B(from T until U) + C(from U)
 * yields (A,B)@T and (B,C)@U — B participates in both, which is the correct
 * reading of a three-version sequence.
 *
 * Pure and deterministic: output is ordered by swap_at, then hide id. A row
 * never pairs with itself (a degenerate from-T-until-T row is not a swap).
 *
 * @param array<int,array<string,mixed>> $rows Schedule rows (sn_schedule_all() shape).
 * @return array<int,array{swap_at:string,target_ref:string,hide:array,show:array}>
 */
function sn_schedule_swap_pairs( array $rows ) {
	$fragments = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || 'fragment' !== (string) ( $row['target_type'] ?? '' ) ) {
			continue;
		}
		$fragments[] = $row;
	}

	$pairs = array();
	foreach ( $fragments as $hide ) {
		$boundary = (string) ( $hide['ends_at'] ?? '' );
		if ( '' === $boundary ) {
			continue;
		}
		foreach ( $fragments as $show ) {
			if ( ( $show['id'] ?? null ) === ( $hide['id'] ?? null ) ) {
				continue; // never self-pair.
			}
			if ( (string) ( $show['target_ref'] ?? '' ) !== (string) ( $hide['target_ref'] ?? '' ) ) {
				continue;
			}
			if ( (string) ( $show['starts_at'] ?? '' ) !== $boundary ) {
				continue;
			}
			$pairs[] = array(
				'swap_at'    => $boundary,
				'target_ref' => (string) ( $hide['target_ref'] ?? '' ),
				'hide'       => $hide,
				'show'       => $show,
			);
		}
	}

	usort( $pairs, function ( $a, $b ) {
		if ( $a['swap_at'] !== $b['swap_at'] ) {
			return strcmp( $a['swap_at'], $b['swap_at'] );
		}
		return (int) $a['hide']['id'] - (int) $b['hide']['id'];
	} );

	return $pairs;
}

/**
 * Force-fire BOTH sides of a version swap as one operation.
 *
 * Validates that the two rows exist and actually form a pair (defense in
 * depth — the admin op posts ids, and ids are attacker-shaped input even
 * behind the cap+nonce gate), then drives each through the real fire state
 * machine. The per-request purge memo makes the second fire's purge a no-op
 * dispatch-wise, so the whole swap costs one Cloudflare call.
 *
 * @param int $hide_id Row id of the old-version container (ends at T).
 * @param int $show_id Row id of the new-version container (starts at T).
 * @return bool True when both fires ran; false when the ids do not resolve
 *              to a valid pair (nothing is fired in that case).
 */
function sn_schedule_swap_run( $hide_id, $show_id ) {
	$hide_id = (int) $hide_id;
	$show_id = (int) $show_id;
	if ( $hide_id <= 0 || $show_id <= 0 || $hide_id === $show_id ) {
		return false;
	}

	$hide = sn_schedule_get( $hide_id );
	$show = sn_schedule_get( $show_id );
	if ( null === $hide || null === $show ) {
		return false;
	}

	// The pair predicate, re-checked on the live rows.
	$pairs = sn_schedule_swap_pairs( array( $hide, $show ) );
	if ( 1 !== count( $pairs )
		|| (int) $pairs[0]['hide']['id'] !== $hide_id
		|| (int) $pairs[0]['show']['id'] !== $show_id
	) {
		return false;
	}

	// Hide first, then show — same order the boundary instant implies. Each
	// fire re-reads its row and applies the due-branch state machine; the
	// purge memo collapses the duplicate edge dispatch.
	sn_schedule_fire( $hide_id );
	sn_schedule_fire( $show_id );

	return true;
}
