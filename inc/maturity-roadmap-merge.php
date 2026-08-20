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
