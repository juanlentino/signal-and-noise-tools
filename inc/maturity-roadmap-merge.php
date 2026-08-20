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

if ( ! defined( 'SN_MATURITY_ROADMAP_OPTION' ) ) {
	define( 'SN_MATURITY_ROADMAP_OPTION', 'snt_maturity_roadmap_board' );
}

/**
 * The stored override, normalised to an envelope.
 *
 * @return array{v:int,board:array,base:array|null}|null Null when nothing is stored.
 */
function snt_roadmap_stored_envelope() {
	$stored = get_option( SN_MATURITY_ROADMAP_OPTION, null );
	if ( ! is_array( $stored ) || array() === $stored ) {
		return null;
	}
	// v2 envelope.
	if ( isset( $stored['v'], $stored['board'] ) && 2 === (int) $stored['v'] ) {
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
	return update_option(
		SN_MATURITY_ROADMAP_OPTION,
		array( 'v' => 2, 'board' => $board, 'base' => $base ),
		false
	);
}
