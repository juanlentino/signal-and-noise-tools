<?php
/**
 * Signal & Noise Tools — Notes provenance: genesis snapshot.
 *
 * One-shot backlog migration: RFC 6962 Merkle snapshot of every already-
 * published Note, one anchored root, per-Note inclusion proofs. Domain-
 * separated hashing with promotion (never Bitcoin duplication).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_GENESIS_MIGR_OPT = 'sn_prov_genesis_migrated';
const SN_PROV_GENESIS_OPT      = 'sn_prov_genesis';        // {root, ledger_path, status, date}
const SN_PROV_PROOF_META       = '_sn_prov_genesis_proof'; // per-Note inclusion proof

/** Leaf hash: SHA-256(0x00 || data). Raw bytes. */
function sn_prov_leaf_hash( $data ) {
	return hash( 'sha256', "\x00" . $data, true );
}
/** Internal node: SHA-256(0x01 || left || right). Raw bytes. */
function sn_prov_node_hash( $left, $right ) {
	return hash( 'sha256', "\x01" . $left . $right, true );
}

/**
 * RFC 6962 root from N ordered leaves (hex). Odd nodes promoted unchanged.
 *
 * @param array $leaves list of leaf data strings
 * @return string hex root
 */
function sn_prov_merkle_root( array $leaves ) {
	if ( 0 === count( $leaves ) ) {
		return bin2hex( hash( 'sha256', '', true ) );
	}
	$level = array_map( 'sn_prov_leaf_hash', $leaves );
	while ( count( $level ) > 1 ) {
		$next = array();
		$n    = count( $level );
		for ( $i = 0; $i < $n; $i += 2 ) {
			$next[] = ( $i + 1 < $n )
				? sn_prov_node_hash( $level[ $i ], $level[ $i + 1 ] )
				: $level[ $i ]; // promote lone node unchanged
		}
		$level = $next;
	}
	return bin2hex( $level[0] );
}

/**
 * Inclusion proof for leaf $index: ordered [{sibling_hash(hex), side}].
 *
 * @param array $leaves
 * @param int   $index
 * @return array
 */
function sn_prov_merkle_proof( array $leaves, $index ) {
	$level = array_map( 'sn_prov_leaf_hash', $leaves );
	$proof = array();
	$idx   = (int) $index;
	while ( count( $level ) > 1 ) {
		$n = count( $level );
		if ( 1 === $idx % 2 ) {
			$proof[] = array( 'sibling_hash' => bin2hex( $level[ $idx - 1 ] ), 'side' => 'left' );
		} elseif ( $idx + 1 < $n ) {
			$proof[] = array( 'sibling_hash' => bin2hex( $level[ $idx + 1 ] ), 'side' => 'right' );
		}
		$next = array();
		for ( $i = 0; $i < $n; $i += 2 ) {
			$next[] = ( $i + 1 < $n )
				? sn_prov_node_hash( $level[ $i ], $level[ $i + 1 ] )
				: $level[ $i ];
		}
		$idx   = intdiv( $idx, 2 );
		$level = $next;
	}
	return $proof;
}

/**
 * Verify an inclusion proof against a known hex root.
 *
 * @param string $leaf_data
 * @param array  $proof
 * @param string $root_hex
 * @return bool
 */
function sn_prov_merkle_verify( $leaf_data, array $proof, $root_hex ) {
	$h = sn_prov_leaf_hash( $leaf_data );
	foreach ( $proof as $step ) {
		$sib = hex2bin( $step['sibling_hash'] );
		$h   = ( 'left' === $step['side'] )
			? sn_prov_node_hash( $sib, $h )
			: sn_prov_node_hash( $h, $sib );
	}
	return hash_equals( $root_hex, bin2hex( $h ) );
}

/**
 * The genesis (v0) canonical payload bytes for a Note — the Merkle leaf data.
 *
 * @param WP_Post|object $post
 * @param string         $author
 * @return string canonical JSON
 */
function sn_prov_genesis_leaf( $post, $author ) {
	return sn_prov_canonical_json( sn_prov_build_payload( $post, 0, null, $author ) );
}

/**
 * Published Notes with no commit chain yet — the backlog to snapshot.
 * Ordered by post_date ASC so the leaf order is deterministic + reproducible.
 *
 * @return int[] post IDs
 */
function sn_prov_genesis_backlog() {
	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => -1,
		'orderby'     => 'date',
		'order'       => 'ASC',
		'fields'      => 'ids',
		'category_name' => apply_filters( 'sn_prov_note_category', 'notes' ),
	) );
	$backlog = array();
	foreach ( $ids as $id ) {
		if ( ! sn_prov_get_chain( (int) $id ) ) {
			$backlog[] = (int) $id;
		}
	}
	return $backlog;
}
