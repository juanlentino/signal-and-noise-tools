<?php
/**
 * Signal & Noise Tools: Notes provenance commit-chain core, the commit (sn_prov_record, the Note test).
 *
 * Split from inc/provenance-core.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a commit for the current post state and append it to the chain,
 * coalescing trivial diffs. Emits `sn_prov_committed` for the Worker webhook
 * (Plan 3). Returns the full chain, or null when coalesced.
 *
 * @param WP_Post|object $post
 * @param string         $author
 * @return array|null
 */
function sn_prov_record( $post, $author ) {
	if ( ! sn_prov_active() ) {
		return null; // ext-intl required for reproducible NFC; see sn_prov_active()
	}

	$chain          = sn_prov_get_chain( $post->ID );
	$bearing_fields = sn_prov_bearing_fields( $post, $author );
	$bearing        = sn_prov_content_hash( sn_prov_canonical_json( $bearing_fields ) );

	if ( $chain ) {
		$last = end( $chain );
		if ( isset( $last['bearing_hash'] ) && $last['bearing_hash'] === $bearing ) {
			return null; // coalesce: nothing provenance-bearing changed
		}
		// v13.4.0 transition shim: the algo NAME is itself a bearing field,
		// so the v1→v2 generation bump alone would break coalescing exactly
		// once per subject — the first markup-only save after the upgrade
		// would mint a version nobody wrote. When the stored head is a v1
		// commit, recompute the bearing AS v1 (v1 algo string + v1 content)
		// and coalesce if THAT matches AND the two generations agree on this
		// content (v2 == v1 — no expandable attribute text in play). The
		// second condition is load-bearing, caught by this change's own
		// suite: without it, ADDING a sidenote to a v1-headed note is
		// invisible to the v1 comparison and would coalesce — silently
		// re-opening, for every v1-era note, the exact unsigned-text hole
		// v2 exists to close. A real edit still mints a v2 commit; a head
		// already on v2 never enters this branch.
		if ( isset( $last['bearing_hash'] ) && 'sn-normalize-v1' === (string) ( $last['payload']['algo'] ?? '' ) ) {
			$v1_content = sn_prov_normalize_v1( $post->post_content );
			if ( $v1_content === $bearing_fields['content'] ) {
				$legacy_fields            = $bearing_fields;
				$legacy_fields['algo']    = 'sn-normalize-v1';
				$legacy_fields['content'] = $v1_content;
				if ( $last['bearing_hash'] === sn_prov_content_hash( sn_prov_canonical_json( $legacy_fields ) ) ) {
					return null; // coalesce: only the algo generation moved
				}
			}
		}
	}

	// v11.10.0: one editing pass, one signed version. While the head commit is
	// provably still private — settle event pending, unsigned, never dispatched
	// — a further save REPLACES it rather than appending. See
	// inc/provenance-settle.php for why every uncertainty resolves to append.
	//
	// Superseding keeps the head's OWN version and parent, so the chain stays
	// contiguous and no link is rewritten: the reader sees one v1, not a v1
	// that changed underneath them.
	$supersede = false;
	if ( $chain && function_exists( 'sn_prov_commit_is_supersedable' ) ) {
		$dispatch_pending = function_exists( 'sn_prov_dispatch_pending' )
			? sn_prov_dispatch_pending( $post->ID )
			: false;
		$supersede = sn_prov_commit_is_supersedable( $last, $dispatch_pending );
	}

	// Genesis persists a v0 entry first, so last_version + 1 correctly yields v1
	// for the first real commit (no gap). For a chain with no genesis (starts at
	// v1, contiguous), last_version + 1 equals count( $chain ) + 1 — unchanged.
	$last_version = $chain ? (int) ( $last['version'] ?? 0 ) : 0;
	$version      = $supersede ? $last_version : $last_version + 1;
	if ( $supersede ) {
		// Inherit the head's parent: the commit being replaced never existed
		// publicly, so the chain must read as though this content was always
		// what v{$version} said.
		$parent = $last['parent'] ?? null;
	} else {
		$parent = $chain
			? ( $last['content_hash'] ?? null )
			: sn_prov_genesis_parent( $post->ID );
	}

	$payload = sn_prov_build_payload_from_fields( $bearing_fields, $version, $parent );
	$canon   = sn_prov_canonical_json( $payload );
	$hash    = sn_prov_content_hash( $canon );

	$commit = array(
		'version'      => $version,
		'parent'       => $parent,
		'content_hash' => $hash,
		'bearing_hash' => $bearing,
		'payload'      => $payload,
		'status'       => 'unanchored',
		'committed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	);

	$full = $supersede
		? sn_prov_replace_head_commit( $post->ID, $commit )
		: sn_prov_append_commit( $post->ID, $commit );

	/**
	 * Fires after a provenance commit is appended. Plan 3's Worker webhook
	 * hooks this to submit the hash for signing + OpenTimestamps anchoring.
	 *
	 * @param int    $post_id
	 * @param array  $commit
	 * @param string $canonical_json The exact bytes that were hashed.
	 */
	do_action( 'sn_prov_committed', (int) $post->ID, $commit, $canon );

	return $full;
}

/**
 * Is this post a Note? Filterable category (default 'notes'). Terms are
 * reliably present because we hook wp_after_insert_post (fires after terms
 * are saved).
 *
 * @param int $post_id
 * @return bool
 */
function sn_prov_is_note( $post_id ) {
	$category = apply_filters( 'sn_prov_note_category', 'notes' );
	return (bool) has_term( $category, 'category', $post_id );
}

