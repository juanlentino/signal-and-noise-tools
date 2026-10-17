<?php
/**
 * Signal & Noise Tools: Notes provenance commit-chain core, the payload and the chain (the publish time, the bearing fields, the payload builders, the content hash, the chain reads and writes, the freshness stamp, the genesis parent).
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
 * The Note's claimed publish time as ISO-8601 UTC (Z). Uses post_date_gmt
 * when set, else post_date interpreted as UTC.
 *
 * @param WP_Post|object $post
 * @return string
 */
function sn_prov_published_at( $post ) {
	$gmt = ( ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt )
		? $post->post_date_gmt
		: get_gmt_from_date( (string) $post->post_date );
	$ts  = strtotime( $gmt . ' UTC' );
	return gmdate( 'Y-m-d\TH:i:s\Z', $ts ? $ts : 0 );
}

/**
 * The provenance-bearing fields (no version, no parent). Single source of
 * truth for both the payload and the coalescing bearing hash.
 *
 * @param WP_Post|object $post
 * @param string         $author
 * @return array
 */
function sn_prov_bearing_fields( $post, $author ) {
	return array(
		'algo'         => SN_PROV_ALGO,
		'author'       => (string) $author,
		'content'      => sn_prov_normalize_v2( $post->post_content ),
		'note_uid'     => sn_prov_note_uid( $post->ID ),
		'published_at' => sn_prov_published_at( $post ),
		'title'        => (string) get_the_title( $post ),
	);
}

/**
 * Build the canonical provenance payload (the object that gets hashed +
 * signed). Provenance-bearing fields only: title, author, published_at,
 * content. Slug and tags are deliberately excluded.
 *
 * @param WP_Post|object $post
 * @param int            $version
 * @param string|null    $parent  Hex content_hash of the parent commit, or null.
 * @param string         $author
 * @return array
 */
function sn_prov_build_payload( $post, $version, $parent, $author ) {
	return sn_prov_build_payload_from_fields( sn_prov_bearing_fields( $post, $author ), $version, $parent );
}

/**
 * Build the canonical payload from already-computed bearing fields. Lets
 * sn_prov_record() reuse the bearing fields it already normalized for the
 * bearing hash, instead of normalizing the content a second time.
 *
 * @param array       $fields  Result of sn_prov_bearing_fields().
 * @param int         $version
 * @param string|null $parent  Hex content_hash of the parent commit, or null.
 * @return array
 */
function sn_prov_build_payload_from_fields( array $fields, $version, $parent ) {
	$fields['parent']  = null === $parent ? null : (string) $parent;
	$fields['version'] = (int) $version;
	return $fields;
}

/**
 * SHA-256 (hex) of the canonical payload bytes.
 *
 * @param string $canonical_json
 * @return string
 */
function sn_prov_content_hash( $canonical_json ) {
	return hash( 'sha256', (string) $canonical_json );
}

/**
 * @param int $post_id
 * @return array ordered list of commit records
 */
function sn_prov_get_chain( $post_id ) {
	$chain = get_post_meta( (int) $post_id, SN_PROV_CHAIN_META, true );
	return is_array( $chain ) ? $chain : array();
}

/**
 * The ONE writer of the chain meta. update_post_meta() unslashes its value, so
 * an unslashed chain loses every backslash in a signed payload and the stored
 * payload stops matching its content_hash. Every write goes through here;
 * tests/provenance-chain-slash.php fails if one does not.
 *
 * @param int   $post_id
 * @param array $chain
 * @return int|bool update_post_meta()'s result.
 */
function sn_prov_put_chain( $post_id, array $chain ) {
	return update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, wp_slash( $chain ) );
}

/**
 * The chain's newest `committed_at`, as a MySQL GMT datetime ('Y-m-d H:i:s').
 *
 * FORMAT IS LOAD-BEARING, not cosmetic. Commits store ISO-8601
 * ('Y-m-d\TH:i:s\Z'), but the consumer (Check 4, inc/health-check-stale-posts.php)
 * compares this value against a cutoff built by gmdate( 'Y-m-d H:i:s', ... ) in
 * SQL — a STRING comparison. 'T' sorts above ' ', so an ISO value would compare
 * as newer than any same-day cutoff and the post would never read stale. The
 * conversion is what makes the column comparable at all.
 *
 * Scans the whole chain rather than trusting the last element's position: a
 * superseded head is replaced in place, and nothing else guarantees ordering.
 *
 * @param array $chain sn_prov_get_chain() result.
 * @return string MySQL GMT datetime, or '' when the chain commits nothing.
 */
function sn_prov_last_commit_gmt_from_chain( $chain ) {
	$newest = '';
	foreach ( (array) $chain as $entry ) {
		$at = is_array( $entry ) ? trim( (string) ( $entry['committed_at'] ?? '' ) ) : '';
		if ( '' === $at ) {
			continue;
		}
		$ts = strtotime( $at );
		if ( ! $ts ) {
			continue; // an unparseable stamp is not a fresh one
		}
		$mysql = gmdate( 'Y-m-d H:i:s', $ts );
		if ( $mysql > $newest ) {
			$newest = $mysql;
		}
	}
	return $newest;
}

/**
 * Denormalize the chain's newest commit time onto the post.
 *
 * WHY A COLUMN AND NOT A FILTER: Check 4's query is
 * `WHERE <clock> < cutoff ... ORDER BY <clock> LIMIT 200`. A post that is stale
 * by provenance but recently *touched* never enters the result set, so
 * post-filtering in PHP cannot recover a row the WHERE clause already excluded
 * — and the LIMIT would truncate on the wrong ordering besides. The right clock
 * has to be available to SQL, which means denormalized meta.
 *
 * Called from BOTH write paths: supersede replaces the head in place and must
 * still refresh the stamp, or a settled edit leaves the clock reading the
 * commit it replaced.
 *
 * @param int   $post_id
 * @param array $chain
 * @return string The value written ('' when the meta was removed instead).
 */
function sn_prov_stamp_last_commit( $post_id, $chain ) {
	$stamp = sn_prov_last_commit_gmt_from_chain( $chain );
	if ( '' === $stamp ) {
		// Never leave a stale stamp behind a chain that no longer justifies it;
		// absence makes the consumer fall back to post_modified_gmt, which is
		// the honest answer for a post provenance has nothing to say about.
		delete_post_meta( (int) $post_id, SN_PROV_LAST_COMMIT_META );
		return '';
	}
	update_post_meta( (int) $post_id, SN_PROV_LAST_COMMIT_META, $stamp );
	return $stamp;
}

/**
 * @param int   $post_id
 * @param array $commit
 * @return array the full chain after appending
 */
function sn_prov_append_commit( $post_id, array $commit ) {
	$chain   = sn_prov_get_chain( $post_id );
	$chain[] = $commit;
	sn_prov_put_chain( $post_id, $chain );
	sn_prov_stamp_last_commit( (int) $post_id, $chain );
	return $chain;
}

/**
 * Replace the head commit in place (v11.10.0, settle window).
 *
 * Only ever called for a commit sn_prov_commit_is_supersedable() has cleared —
 * still private, never dispatched, unsigned. Replacing anything the Worker has
 * seen would rewrite an append-only ledger.
 *
 * An empty chain falls through to append, so a caller that gets its bookkeeping
 * wrong still produces a valid chain rather than losing the commit.
 *
 * @param int   $post_id
 * @param array $commit  Replacement head.
 * @return array The full chain.
 */
function sn_prov_replace_head_commit( $post_id, array $commit ) {
	$chain = sn_prov_get_chain( $post_id );
	if ( ! $chain ) {
		return sn_prov_append_commit( $post_id, $commit );
	}
	array_pop( $chain );
	$chain[] = $commit;
	sn_prov_put_chain( $post_id, $chain );
	sn_prov_stamp_last_commit( (int) $post_id, $chain );
	return $chain;
}

/**
 * Genesis parent baseline for a Note's first commit. Plan 4 sets
 * SN_PROV_GENESIS_META for backlog Notes; absent it, first commits have a
 * null parent.
 *
 * @param int $post_id
 * @return string|null
 */
function sn_prov_genesis_parent( $post_id ) {
	$g = get_post_meta( (int) $post_id, SN_PROV_GENESIS_META, true );
	return ( is_string( $g ) && '' !== $g ) ? $g : null;
}

