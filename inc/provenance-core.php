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
const SN_PROV_LAST_COMMIT_META = '_sn_prov_last_commit_gmt'; // v11.11.8: denormalized freshness clock (MySQL datetime, GMT)

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
 * Callers outside sn_prov_record() must check sn_prov_active() first — NFC
 * (step 4) is only reproducible when ext-intl is loaded.
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
		'content'      => sn_prov_normalize_v1( $post->post_content ),
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
	update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, $chain );
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
	update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, $chain );
	sn_prov_stamp_last_commit( (int) $post_id, $chain );
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
	return sn_prov_content_hash( sn_prov_canonical_json( sn_prov_bearing_fields( $post, $author ) ) );
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

/**
 * Per-page opt-in meta. A page is signed ONLY when this is set.
 *
 * @since 10.84.0
 */
const SN_PROV_SIGN_META = '_sn_prov_sign';

/**
 * The provenance subject kind for a post: 'note', 'page', or '' for "not a
 * subject". The single place that decides what gets signed.
 *
 * WHY PAGES ARE OPT-IN, AND MUST STAY THAT WAY. The ledger is public,
 * append-only and Bitcoin-anchored: every signed version is permanent. Signing
 * pages wholesale would ledger /verify, /stats and the maturity pages —
 * surfaces whose text changes because a number moved, not because anyone wrote
 * anything. Each of those changes would mint a new anchored version of a
 * document nobody intended to publish as a record, forever. A note is an
 * editorial artifact; a page is often a rendering. Only the author knows which
 * pages are the first kind, so only the author turns them on.
 *
 * NOTE THE SECOND GATE, which is easy to miss and was the real reason the
 * post-type line alone could never have extended this: sn_prov_is_note() asks
 * has_term( 'notes', 'category', … ), and `category` is a POST-ONLY taxonomy in
 * WordPress. A page can never satisfy it. Widening the post_type check without
 * this resolver would have looked like shipping the feature and changed
 * nothing at all.
 *
 * @param WP_Post|object $post
 * @return string 'note' | 'page' | ''
 *
 * @since 10.84.0
 */
function sn_prov_subject_kind( $post ) {
	if ( ! is_object( $post ) ) {
		return '';
	}
	$type    = (string) ( $post->post_type ?? '' );
	$post_id = (int) ( $post->ID ?? 0 );

	if ( 'post' === $type ) {
		return sn_prov_is_note( $post_id ) ? 'note' : '';
	}

	if ( 'page' === $type ) {
		$opted_in = (bool) get_post_meta( $post_id, SN_PROV_SIGN_META, true );
		/**
		 * Whether this page is a provenance subject. Default is the per-page
		 * opt-in meta; the filter exists for programmatic control, never to
		 * turn the whole post type on by returning a bare true.
		 */
		return apply_filters( 'sn_prov_sign_page', $opted_in, $post ) ? 'page' : '';
	}

	return '';
}

/**
 * The public ledger directory for a subject kind.
 *
 * Mirrors the Worker's SUBJECT_KINDS map (src/index.mjs), which is the thing
 * that actually writes these paths. Kept as a map from a validated kind to a
 * FIXED literal for the same reason the Worker does it that way: the directory
 * must never be built from a caller's string.
 *
 * RETURNS '' FOR AN UNKNOWN KIND, AND CALLERS MUST REFUSE RATHER THAN GUESS.
 * There is deliberately no 'notes' default. Defaulting is what filed the About
 * page's v2 under notes/ on 2026-08-19 (fixed in v12.6.5) — in an append-only,
 * Bitcoin-anchored ledger a guessed directory is not a recoverable mistake.
 *
 * @since 12.6.6
 * @param string $kind 'note' | 'page'
 * @return string 'notes' | 'pages' | '' when the kind is not a subject kind.
 */
function sn_prov_ledger_dir( $kind ) {
	$map = array(
		'note' => 'notes',
		'page' => 'pages',
	);
	$kind = (string) $kind;
	return isset( $map[ $kind ] ) ? $map[ $kind ] : '';
}

/**
 * Every post type that can hold a provenance subject.
 *
 * Used by the UID resolver and the reconcile sweep so a widened subject set
 * cannot leave either of them looking at half the corpus. ONE UUID namespace
 * spans them: the ledger path carries the kind, so the resolver stays total.
 *
 * @return string[]
 *
 * @since 10.84.0
 */
function sn_prov_subject_post_types() {
	return (array) apply_filters( 'sn_prov_subject_post_types', array( 'post', 'page' ) );
}

/**
 * The provenance author string for a post (the post author's display name,
 * filterable).
 *
 * @param WP_Post|object $post
 * @return string
 */
function sn_prov_author( $post ) {
	$author = get_the_author_meta( 'display_name', (int) $post->post_author );
	return (string) apply_filters( 'sn_prov_author', $author, $post );
}

/**
 * wp_after_insert_post handler: record a provenance commit when a Note
 * reaches/updates the 'publish' state. Fires once for classic + block
 * editors, after terms + meta are saved. Keeps to update_post_meta only
 * (never re-saves the post), so it cannot re-trigger itself.
 *
 * @param int          $post_id
 * @param WP_Post       $post
 * @param bool          $update
 * @param WP_Post|null  $post_before
 */
function sn_prov_on_after_insert( $post_id, $post, $update, $post_before ) {
	if ( ! sn_prov_active() ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	// v10.84.0: one resolver decides what is a subject and what kind it is.
	// Empty means "not a subject" — a post outside the notes category, or a
	// page the author has not opted in. See sn_prov_subject_kind().
	if ( '' === sn_prov_subject_kind( $post ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	// v9.88.0: a password-protected post IS status=publish, and the commit
	// payload carries the entire normalized post_content to a PUBLIC,
	// append-only ledger (irreversible: git history + a Bitcoin anchor).
	// sn_prov_credential() already gates on this; the recording leg did not.
	if ( '' !== (string) ( $post->post_password ?? '' ) ) {
		return;
	}
	// (The notes-category check now lives inside sn_prov_subject_kind() above,
	// which runs before the status and password gates. Keeping a second copy
	// here would silently re-exclude pages, which have no category at all.)
	sn_prov_record( $post, sn_prov_author( $post ) );
}
add_action( 'wp_after_insert_post', 'sn_prov_on_after_insert', 20, 4 );
