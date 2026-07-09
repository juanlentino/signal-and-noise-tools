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

/**
 * Build the genesis structure for a set of backlog posts:
 *   { root, date, leaves: [ { post_id, note_uid, leaf, proof } ] }
 *
 * @param array  $posts  ordered WP_Post|object list
 * @param string $author
 * @return array
 */
function sn_prov_genesis_build( array $posts, $author ) {
	$leaves = array();
	$data   = array();
	foreach ( $posts as $post ) {
		$leaf   = sn_prov_genesis_leaf( $post, $author );
		$data[] = $leaf;
		$leaves[] = array(
			'post_id'  => (int) $post->ID,
			'note_uid' => sn_prov_note_uid( (int) $post->ID ),
			'leaf'     => $leaf,
		);
	}
	$root = sn_prov_merkle_root( $data );
	foreach ( $leaves as $i => $entry ) {
		$leaves[ $i ]['proof'] = sn_prov_merkle_proof( $data, $i );
	}
	return array(
		'root'   => $root,
		'date'   => gmdate( 'Y-m-d' ),
		'leaves' => $leaves,
	);
}

/**
 * Persist genesis to each Note: the root as its parent baseline, its inclusion
 * proof, and a v0 chain entry (status 'genesis', flagged). Does NOT anchor —
 * anchoring is Task 4.
 *
 * @param array $genesis
 */
function sn_prov_genesis_persist( array $genesis ) {
	foreach ( $genesis['leaves'] as $entry ) {
		$post_id = (int) $entry['post_id'];
		update_post_meta( $post_id, SN_PROV_GENESIS_META, $genesis['root'] );
		update_post_meta( $post_id, SN_PROV_PROOF_META, $entry['proof'] );
		$chain = sn_prov_get_chain( $post_id );
		if ( ! $chain ) {
			sn_prov_append_commit( $post_id, array(
				'version'      => 0,
				'parent'       => $genesis['root'],
				// Merkle LEAF hash SHA256(0x00‖canonical) — NOT the plain SHA256(canonical) that Plan-1 v1+ commits use; never recompute a v0 hash with sn_prov_content_hash().
				'content_hash' => bin2hex( sn_prov_leaf_hash( $entry['leaf'] ) ),
				'status'       => 'genesis',
				'genesis'      => true,
				'payload'      => json_decode( $entry['leaf'], true ),
				'committed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			) );
		}
	}
}

/**
 * Anchor the genesis root through the Worker (one Bitcoin anchor for the whole
 * backlog). Reuses the webhook dispatch contract with a synthetic commit.
 *
 * @param array $genesis
 */
function sn_prov_genesis_anchor( array $genesis ) {
	$manifest = wp_json_encode( array(
		'kind'  => 'genesis',
		'root'  => $genesis['root'],
		'date'  => $genesis['date'],
		'count' => count( $genesis['leaves'] ),
		'notes' => array_map(
			static function ( $e ) {
				return array( 'note_uid' => $e['note_uid'], 'leaf_hash' => bin2hex( sn_prov_leaf_hash( $e['leaf'] ) ) );
			},
			$genesis['leaves']
		),
	) );
	sn_prov_dispatch_manifest( $genesis['root'], $manifest, $genesis['date'] );

	update_option( SN_PROV_GENESIS_OPT, array(
		'root'   => $genesis['root'],
		'date'   => $genesis['date'],
		'status' => 'pending',
	), false );
}

/** Dispatch a raw manifest to the Worker (genesis has no post_id). */
function sn_prov_dispatch_manifest( $root, $manifest, $date ) {
	$url    = sn_prov_worker_url();
	$secret = sn_prov_hmac_secret();
	if ( '' === $url || '' === $secret ) {
		return;
	}
	$body = wp_json_encode( array(
		'canonical'    => $manifest,
		'content_hash' => $root,
		'note_uid'     => 'genesis',
		'version'      => 0,
	) );
	wp_remote_post( $url, array(
		'timeout' => 20,
		'headers' => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'    => $body,
	) );
}

/**
 * One-shot migration: snapshot the whole backlog, persist proofs, anchor the
 * root. Gated by SN_PROV_GENESIS_MIGR_OPT — runs at most once per site.
 */
function sn_prov_genesis_migrate() {
	if ( get_option( SN_PROV_GENESIS_MIGR_OPT ) ) {
		return;
	}
	if ( ! function_exists( 'sn_prov_active' ) || ! sn_prov_active() ) {
		return; // ext-intl gate — retry next admin_init
	}
	$ids = sn_prov_genesis_backlog();
	if ( ! $ids ) {
		update_option( SN_PROV_GENESIS_MIGR_OPT, time(), true ); // nothing to snapshot
		return;
	}
	$posts = array_map( 'get_post', $ids );
	$genesis = sn_prov_genesis_build( $posts, sn_prov_genesis_author() );
	sn_prov_genesis_persist( $genesis );
	sn_prov_genesis_anchor( $genesis );
	update_option( SN_PROV_GENESIS_MIGR_OPT, time(), true );
}
add_action( 'admin_init', 'sn_prov_genesis_migrate' );

/** Author string for genesis leaves (filterable; defaults to the blog owner). */
function sn_prov_genesis_author() {
	return (string) apply_filters( 'sn_prov_genesis_author', get_bloginfo( 'name' ) );
}

/**
 * Refresh genesis status from the ledger's genesis record. Flips
 * SN_PROV_GENESIS_OPT to 'confirmed' once the root's .ots is anchored.
 */
function sn_prov_genesis_refresh() {
	$state = get_option( SN_PROV_GENESIS_OPT, array() );
	if ( ! is_array( $state ) || 'pending' !== ( $state['status'] ?? '' ) ) {
		return;
	}
	$url = sn_prov_ledger_raw_url( 'genesis/' . $state['date'] . '-root.json' );
	if ( '' === $url ) {
		return;
	}
	$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return;
	}
	$record = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( is_array( $record ) && 'confirmed' === ( $record['ots']['status'] ?? '' ) ) {
		$state['status']        = 'confirmed';
		$state['bitcoin_block'] = (int) ( $record['ots']['bitcoin_block'] ?? 0 );
		update_option( SN_PROV_GENESIS_OPT, $state, false );
	}
}
add_action( SN_PROV_CONFIRM_HOOK, 'sn_prov_genesis_refresh' );

/** Raw ledger URL for a path (filterable; default GitHub raw). */
function sn_prov_ledger_raw_url( $path ) {
	$owner = (string) apply_filters( 'sn_prov_ledger_owner', 'juanlentino' );
	$repo  = (string) apply_filters( 'sn_prov_ledger_repo', 'signal-and-noise-provenance' );
	if ( '' === $owner || '' === $repo ) {
		return '';
	}
	return "https://raw.githubusercontent.com/{$owner}/{$repo}/main/{$path}";
}
