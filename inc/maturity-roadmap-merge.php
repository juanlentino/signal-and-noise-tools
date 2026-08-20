<?php
/**
 * Signal & Noise Tools — the roadmap board's three-way merge.
 *
 * The board has two writers: code (sn_maturity_roadmap_static_board) and MCP
 * (sn_apply's roadmap_board option write). Until v12.6.0 the override shadowed
 * code totally and recorded nothing about what it was derived from, so the
 * first MCP write silently retired the code path — a later edit to the static
 * board rendered nothing, with no error, until someone called reset:true.
 *
 * The envelope records the static board AT THE MOMENT OF THE WRITE. That is
 * the whole mechanism: it lets the read path tell "the override changed this
 * cell" from "code changed this cell".
 *
 * @since 12.6.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The stored override, normalised to an envelope.
 *
 * @return array{v:int,board:array,base:array|null}|null Null when nothing is stored.
 */
function snt_roadmap_stored_envelope() {
	// Literal, not the SN_MATURITY_ROADMAP_OPTION const from
	// maturity-roadmap-shortcode.php: this module must stay loadable without
	// the shortcode (that's what makes the merge testable without a renderer),
	// and a top-level `const` can't be re-declared behind a defined() guard.
	$stored = get_option( 'snt_maturity_roadmap_board', null );
	if ( ! is_array( $stored ) || array() === $stored ) {
		return null;
	}

	// An explicit int 'v' key marks intent to be a v2+ envelope, not a bare
	// v1 board. is_int() — rather than leaning on `2 === (int) $stored['v']`
	// below being false for anything that isn't already an int — is the
	// deliberate guard against a v1 board that happens to have a family
	// literally named "v": a non-empty array cast to int is always 1, never
	// a version number, so that collision is theoretical today, but relying
	// on the cast's quirk is luck, not design. is_int() makes the split
	// explicit so a later edit to the version check can't reopen it.
	if ( isset( $stored['v'] ) && is_int( $stored['v'] ) ) {
		// Envelope-shaped (an int 'v'), but either a version this code
		// doesn't understand, or a 'board' that isn't itself an array
		// (e.g. corrupted storage). Either way it's unreadable — treat it
		// as no override rather than handing callers the wrapper's own
		// {v, board, base} keys as if they were roadmap family data.
		if ( 2 !== $stored['v'] || ! isset( $stored['board'] ) || ! is_array( $stored['board'] ) ) {
			return null;
		}
		return array(
			'v'     => 2,
			'board' => (array) $stored['board'],
			'base'  => isset( $stored['base'] ) && is_array( $stored['base'] ) ? (array) $stored['base'] : null,
		);
	}

	// v1: a BARE board. Unknown provenance, so base is null and every cell
	// counts as override-owned — no code edit may land through it.
	return array( 'v' => 1, 'board' => $stored, 'base' => null );
}

/**
 * Write the override plus the static board it was derived from.
 *
 * @param array $board The new override board.
 * @param array $base  The static board at this moment.
 * @return bool
 */
function snt_roadmap_store_envelope( array $board, array $base ) {
	// Literal here too, for the same reason as the read side above.
	return update_option(
		'snt_maturity_roadmap_board',
		array( 'v' => 2, 'board' => $board, 'base' => $base ),
		false
	);
}

/**
 * Three-way merge of a roadmap board, one (family, column) CELL at a time.
 *
 * ABSENT AND PRESENT ARE DIFFERENT VALUES. A cell in one board and not another
 * counts as changed, which is what makes deletions merge like any other edit.
 *
 * A CONFLICT RENDERS THE OVERRIDE. The public page must not change under the
 * owner because of a plugin update they did not review — code winning would
 * mean an install silently reverting authored copy.
 *
 * The merge unit is deliberately a whole cell, not a sentence. A code edit to
 * one sentence of a cell the override rewrote is a conflict, not a merge:
 * auto-merging inside a cell is how a board nobody authored gets published.
 *
 * DEGRADES RATHER THAN THROWS. A family value in $ours or $theirs that isn't
 * itself an array (corrupted option storage, a caller that skipped
 * validation) is coerced to array() and read as absent from that writer,
 * rather than raising — callers may rely on this, notably a page render that
 * must show a degraded board instead of fataling on a corrupted option.
 *
 * @param array|null $base   Static board at the time of the override write; null = unknown (v1).
 * @param array      $ours   The override board.
 * @param array      $theirs The static board now.
 * @return array{merged:array,conflicts:array,code_landed:array,override_held:array}
 */
function snt_roadmap_merge( $base, array $ours, array $theirs ) {
	$report = array( 'merged' => array(), 'conflicts' => array(), 'code_landed' => array(), 'override_held' => array() );

	// Unknown provenance: the override owns everything, nothing lands from code.
	if ( ! is_array( $base ) ) {
		$report['merged'] = $ours;
		return $report;
	}

	// $ours and $theirs are always FULL board snapshots, never diffs: if a
	// writer didn't touch a family, its stored copy still carries that
	// family's data unchanged from $base. So a family present ONLY in $base
	// implies both writers already agreed to drop it, and array_keys( $ours
	// + $theirs ) alone would enumerate everything that still needs a
	// decision — the + $base term below is inert under that invariant (any
	// key it alone contributes picks null cells throughout and is dropped
	// before reaching $report['merged']). It's kept anyway, for both this
	// union and the column-level one just below, as cheap insurance against
	// a future caller that passes a diff instead of a snapshot.
	$families = array_keys( $ours + $theirs + $base );
	foreach ( $families as $family ) {
		// `??` only substitutes on a missing/null key — a family value that
		// IS present but isn't an array (corrupted option storage, or a
		// caller that skipped validation) would survive into the `+` union
		// below and throw a TypeError. Mirror snt_roadmap_stored_envelope()
		// twenty lines up, which treats a non-array 'board' as unreadable
		// rather than fatal: coerce a non-array family to array() so it
		// reads as absent from that writer, degrading the board instead of
		// crashing the whole merge.
		$b = $base[ $family ]   ?? array();
		$o = $ours[ $family ]   ?? array();
		$t = $theirs[ $family ] ?? array();
		if ( ! is_array( $b ) ) { $b = array(); }
		if ( ! is_array( $o ) ) { $o = array(); }
		if ( ! is_array( $t ) ) { $t = array(); }

		$columns = array_keys( $o + $t + $b );
		$cells   = array();
		foreach ( $columns as $column ) {
			$bc = $b[ $column ] ?? null;
			$oc = $o[ $column ] ?? null;
			$tc = $t[ $column ] ?? null;

			$ours_moved   = $oc !== $bc;
			$theirs_moved = $tc !== $bc;

			if ( $ours_moved && $theirs_moved && $oc !== $tc ) {
				$pick = $oc;
				$list = 'conflicts';
			} elseif ( $ours_moved ) {
				// Also absorbs the case where both writers changed the cell
				// but landed on the SAME value ($oc === $tc, so the conflict
				// guard above didn't fire): deliberately filed as
				// override_held rather than a fourth "converged" list. The
				// merged value is correct either way; only the audit trail
				// calls it the override holding its ground when code
				// independently landed on the same value. Accepted as a
				// known nuance rather than a fourth report list, which would
				// break "every moved cell appears in exactly one list".
				$pick = $oc;
				$list = 'override_held';
			} elseif ( $theirs_moved ) {
				$pick = $tc;
				$list = 'code_landed';
			} else {
				// Neither writer moved this cell: $bc, $oc and $tc are all
				// equal here, so the VALUE is the same whichever we pick.
				// $tc (code's current copy) is the clearer statement of
				// intent — an unmoved cell tracks the live source of truth,
				// not a stale snapshot from whenever the override was last
				// written — so prefer it over $bc even though it can't
				// change the result.
				$pick = $tc;
				$list = null;
			}

			// A cell that resolves to null does not exist in the merged
			// board — it must not be recorded as moved either. This is what
			// a base-only column/family hits: both writers agree it's gone
			// ($oc and $tc both null), $bc still has a value, so the "moved"
			// flags fire on both sides and it would otherwise be misfiled as
			// override_held. Deciding $pick first and gating the report on
			// it, rather than recording the entry inline with the branch
			// that chose $pick, is what keeps that phantom out of the report.
			if ( null !== $pick ) {
				$cells[ $column ] = $pick;
				if ( null !== $list ) {
					$report[ $list ][] = array( 'family' => $family, 'column' => $column );
				}
			}
		}
		if ( array() !== $cells ) {
			$report['merged'][ $family ] = $cells;
		}
	}
	return $report;
}
