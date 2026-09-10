<?php
/**
 * S&N Dashboard -- the two-up card row shared by the card-editing leaves.
 *
 * Required by content-now.php and content-uses.php.
 *
 * @package SignalNoiseTools
 * @since 13.109.12
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Pair repeated sibling cards into two-up rows.
 *
 * These cards are SIBLINGS, not steps: each is one independent section/group,
 * and nothing about card 2 depends on card 1. A stack is the right shape for a
 * sequence and the wrong one for a set -- the same distinction that turned AI ->
 * MCP Clients from a 2,852px essay into a 2,136px grid in v13.109.11.
 *
 * Measured live 2026-09-10 on Content -> Now Page: four cards stacked to
 * 1,386px at 728px wide; paired, the leaf is 831px and each card measures
 * 826px. Shorter AND wider, because `.snt-cols` also trips the width-cap
 * escape hatch and releases the leaf from 820px.
 *
 * @param string[] $cards Rendered card markup, in order.
 * @return string Rows of at most two.
 */
function snt_pair_cards( array $cards ) {
	$out = '';
	for ( $i = 0, $n = count( $cards ); $i < $n; $i += 2 ) {
		$row  = $cards[ $i ] . ( isset( $cards[ $i + 1 ] ) ? $cards[ $i + 1 ] : '' );
		$out .= \snt_kit_tag( 'div', array( 'class' => 'snt-cols' ), $row );
	}
	return $out;
}
