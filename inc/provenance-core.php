<?php
/**
 * Signal & Noise Tools — Notes provenance: commit-chain core.
 *
 * Pure, offline data layer: turns a published Note into a byte-stable
 * canonical payload and appends it to a per-Note commit chain in postmeta.
 * Emits `sn_prov_committed` for the Worker webhook (Plan 3) to hook. This
 * module does NO networking and holds NO keys.
 *
 * Naming: the editorial Provenance pillar owns `sn_provenance_*`; this
 * commit-chain subsystem uses short-form `sn_prov_*`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_ALGO        = 'sn-normalize-v1';
const SN_PROV_CHAIN_META  = '_sn_prov_chain';   // serialized array of commit records
const SN_PROV_UID_META    = '_sn_prov_uid';     // per-Note UUID (ledger key)
const SN_PROV_GENESIS_META = '_sn_prov_genesis_parent'; // set by Plan 4

/**
 * Is the provenance subsystem operable? Requires ext-intl for reproducible
 * Unicode NFC normalization. If absent, the whole feature no-ops (an admin
 * notice is surfaced by Plan 5's admin module).
 */
function sn_prov_active() {
	return function_exists( 'normalizer_normalize' );
}

/**
 * Return the Note's stable provenance UUID, minting + persisting it on first
 * call. This — not the WP post ID or slug — is the ledger key, so migrations
 * and slug changes never disturb the ledger.
 *
 * @param int $post_id
 * @return string RFC 4122 v4 UUID.
 */
function sn_prov_note_uid( $post_id ) {
	$uid = get_post_meta( (int) $post_id, SN_PROV_UID_META, true );
	if ( is_string( $uid ) && '' !== $uid ) {
		return $uid;
	}
	$uid = wp_generate_uuid4();
	update_post_meta( (int) $post_id, SN_PROV_UID_META, $uid );
	return $uid;
}

/**
 * sn-normalize-v1: block/HTML markup -> deterministic plain prose.
 *
 * Ordered pipeline (mirrored byte-for-byte by the JS reference impl in the
 * ledger repo — DO NOT reorder without bumping the algo version):
 *   1. remove Gutenberg block-delimiter comments
 *   2. strip all HTML tags
 *   3. decode HTML entities exactly once (HTML5 table, UTF-8)
 *   4. Unicode NFC (ext-intl)
 *   5. line endings -> LF, strip leading BOM
 *   6. per line: collapse [space|tab|NBSP] runs to one space, trim
 *   7. collapse 2+ blank lines to one; trim overall
 *
 * @param string $post_content
 * @return string
 */
function sn_prov_normalize_v1( $post_content ) {
	$s = (string) $post_content;
	$s = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $s );            // 1
	$s = wp_strip_all_tags( $s );                                     // 2
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );   // 3
	if ( function_exists( 'normalizer_normalize' ) ) {               // 4
		$n = normalizer_normalize( $s, Normalizer::FORM_C );
		if ( is_string( $n ) ) {
			$s = $n;
		}
	}
	$s = preg_replace( '/^\x{FEFF}/u', '', $s );                      // 5
	$s = preg_replace( '/\r\n?/', "\n", $s );
	$lines = array_map(                                              // 6
		static function ( $ln ) {
			return trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $ln ) );
		},
		explode( "\n", $s )
	);
	$s = implode( "\n", $lines );
	$s = preg_replace( '/\n{3,}/', "\n\n", $s );                     // 7
	return trim( $s );
}

/**
 * Deterministic JSON: recursively sort object keys (byte order), compact,
 * UTF-8, slashes + non-ASCII emitted raw. Byte-identical to RFC 8785 for our
 * strings-and-integers payload. Object keys MUST stay ASCII and non-numeric.
 *
 * @param array $data
 * @return string
 */
function sn_prov_canonical_json( array $data ) {
	sn_prov_ksort_recursive( $data );
	return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Recursively ksort associative arrays; leave list arrays in order.
 *
 * @param array $data
 */
function sn_prov_ksort_recursive( array &$data ) {
	if ( ! sn_prov_is_list( $data ) ) {
		ksort( $data, SORT_STRING );
	}
	foreach ( $data as &$value ) {
		if ( is_array( $value ) ) {
			sn_prov_ksort_recursive( $value );
		}
	}
	unset( $value );
}

/**
 * array_is_list() polyfill (native since PHP 8.1; target is 8.0).
 *
 * @param array $arr
 * @return bool
 */
function sn_prov_is_list( array $arr ) {
	if ( function_exists( 'array_is_list' ) ) {
		return array_is_list( $arr );
	}
	$i = 0;
	foreach ( $arr as $k => $unused ) {
		if ( $k !== $i++ ) {
			return false;
		}
	}
	return true;
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
		: $post->post_date;
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $gmt . ' UTC' ) );
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
	return array(
		'algo'         => SN_PROV_ALGO,
		'author'       => (string) $author,
		'content'      => sn_prov_normalize_v1( $post->post_content ),
		'note_uid'     => sn_prov_note_uid( $post->ID ),
		'parent'       => null === $parent ? null : (string) $parent,
		'published_at' => sn_prov_published_at( $post ),
		'title'        => (string) get_the_title( $post ),
		'version'      => (int) $version,
	);
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
 * @param int $post_id
 * @return string|null hex content_hash of the newest commit, or null
 */
function sn_prov_latest_hash( $post_id ) {
	$chain = sn_prov_get_chain( $post_id );
	if ( ! $chain ) {
		return null;
	}
	$last = end( $chain );
	return isset( $last['content_hash'] ) ? (string) $last['content_hash'] : null;
}

/**
 * @param int   $post_id
 * @param array $commit
 * @return array the full chain after appending
 */
function sn_prov_append_commit( $post_id, array $commit ) {
	$chain   = sn_prov_get_chain( $post_id );
	$chain[] = $commit;
	update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, $chain );
	return $chain;
}

/**
 * Hash of the provenance-BEARING fields only (no version, no parent). Two
 * saves with identical words/title/author/date produce the same bearing
 * hash — the basis for coalescing trivial diffs.
 *
 * @param WP_Post|object $post
 * @param string         $author
 * @return string
 */
function sn_prov_bearing_hash( $post, $author ) {
	$bearing = array(
		'algo'         => SN_PROV_ALGO,
		'author'       => (string) $author,
		'content'      => sn_prov_normalize_v1( $post->post_content ),
		'note_uid'     => sn_prov_note_uid( $post->ID ),
		'published_at' => sn_prov_published_at( $post ),
		'title'        => (string) get_the_title( $post ),
	);
	return sn_prov_content_hash( sn_prov_canonical_json( $bearing ) );
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
	$chain   = sn_prov_get_chain( $post->ID );
	$bearing = sn_prov_bearing_hash( $post, $author );

	if ( $chain ) {
		$last = end( $chain );
		if ( isset( $last['bearing_hash'] ) && $last['bearing_hash'] === $bearing ) {
			return null; // coalesce: nothing provenance-bearing changed
		}
	}

	$version = count( $chain ) + 1;
	$parent  = $chain
		? ( end( $chain )['content_hash'] ?? null )
		: sn_prov_genesis_parent( $post->ID );

	$payload = sn_prov_build_payload( $post, $version, $parent, $author );
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

	$full = sn_prov_append_commit( $post->ID, $commit );

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
