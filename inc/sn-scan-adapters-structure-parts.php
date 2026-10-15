<?php
/**
 * Signal & Noise Tools: sn_scan adapters, adapters 1 to 4 (block_migrations, pattern_adoption, duplicate_body, near_duplicate).
 *
 * Split from inc/sn-scan-adapters.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * 1. block_migrations — structural, no ML kernel.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_block_migrations( $allowed_ids ) {
	// snt_block_migrations_compute() — NOT snt_block_migrations_run_scan() —
	// deliberately: run_scan() ends with an unconditional set_transient()
	// that clobbers the per-user admin-tab cache. sn_scan is readOnlyHint:true
	// and must never write (adversarial review, v10.29.0); compute() is the
	// identical detection + envelope build with the write extracted out.
	if ( ! function_exists( 'snt_block_migrations_compute' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Block migrations scan helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$result = snt_block_migrations_compute();
	$raw    = is_array( $result['candidates'] ?? null ) ? $result['candidates'] : array();

	// Sizing only (mirrors the detector's own get_posts() args) — never a
	// re-implementation of the heading-hierarchy walk itself.
	$posts_examined = count( get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'fields'         => 'ids',
	) ) );

	$candidates = array();
	foreach ( $raw as $c ) {
		$post_id = (int) ( $c['post_id'] ?? 0 );
		if ( ! snt_sn_scan_in_scope( $post_id, $allowed_ids ) ) {
			continue;
		}
		$post = get_post( $post_id );
		// block_path is load-bearing for multi-candidate apply: fingerprints
		// are position-bound (md5(post_id|block_path|serialize_block)), so a
		// caller applying same-post candidates top-to-bottom invalidates later
		// paths. Surface it on targets[] (apply identity) as well as evidence.
		// Same-post apply MUST be DESCENDING by block_path — mirrors
		// sn_apply payload.edits' descending-splice (inc/sn-apply/batch-edits.php).
		$block_path = (string) ( $c['block_path'] ?? '' );
		$candidates[] = array(
			'target_identity'     => (string) $post_id,
			'content_fingerprint' => (string) ( $c['block_fingerprint'] ?? '' ),
			'targets'             => array( array(
				'post_id'           => $post_id,
				'slug'              => $post ? (string) ( $post->post_name ?? '' ) : '',
				'block_fingerprint' => (string) ( $c['block_fingerprint'] ?? '' ),
				'block_path'        => $block_path,
			) ),
			'confidence'          => SNT_SN_SCAN_CONF_BLOCK_MIGRATIONS,
			'evidence'            => array(
				'migration_type' => (string) ( $c['migration_type'] ?? '' ),
				'block_path'     => $block_path,
				'current_level'  => (int) ( $c['current_level'] ?? 0 ),
				'target_level'   => (int) ( $c['target_level'] ?? 0 ),
				'permalink'      => (string) ( $c['permalink'] ?? '' ),
				'post_title'     => (string) ( $c['post_title'] ?? '' ),
			),
			// v12.0.0: block-migrations-apply was RETIRED from the rw door, so
			// naming it here would hand a caller a tool the door refuses. The
			// same operation is sn-apply's block_migration change type, which
			// IS doored. Descending-position order still applies (see this
			// tool's own description) — that is a property of the position-bound
			// fingerprints, not of which tool performs the write.
			'apply_hint'          => array(
				'tool'          => 'signal-noise/sn-apply',
				'required_args' => array( 'change.type:block_migration', 'change.fingerprint', 'payload.replacement_markup', 'payload.migration_type' ),
			),
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $posts_examined,
		'posts_skipped'  => 0, // No skip concept exposed by the underlying detector.
		'truncated'      => false, // get_posts(posts_per_page=-1) — no cap to hit.
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * 2. pattern_adoption — structural, no ML kernel.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_pattern_adoption( $allowed_ids ) {
	// snt_pattern_adoption_compute() — same reasoning as the block_migrations
	// adapter above: run_scan() writes a transient, sn_scan may not.
	if ( ! function_exists( 'snt_pattern_adoption_compute' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Pattern-adoption scan helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	// v13.2.0: the scope goes INTO the query, and scheduled ('future') posts
	// are included — adoption on a not-yet-published post is free, because no
	// signed ledger version exists for it yet. posts_examined is the ACTUAL
	// walked count from the detector (pre-13.2.0 this adapter counted ALL
	// published posts regardless of scope and the walk skipped every
	// scheduled note — the envelope claimed a corpus it never examined, and
	// corpus_fingerprint/scan_run_id inherited the lie; they derive from
	// posts_examined + ids in inc/abilities-sn-scan.php, so they become
	// scope-honest with it).
	$result = snt_pattern_adoption_compute( array(
		'statuses' => array( 'publish', 'future' ),
		'post__in' => $allowed_ids,
	) );
	$raw    = is_array( $result['candidates'] ?? null ) ? $result['candidates'] : array();

	$posts_examined = (int) ( $result['posts_examined'] ?? 0 );

	$candidates = array();
	foreach ( $raw as $c ) {
		$post_id = (int) ( $c['post_id'] ?? 0 );
		if ( ! snt_sn_scan_in_scope( $post_id, $allowed_ids ) ) {
			continue;
		}
		$post = get_post( $post_id );
		$candidates[] = array(
			'target_identity'     => (string) $post_id,
			'content_fingerprint' => (string) ( $c['block_fingerprint'] ?? '' ),
			'targets'             => array( array(
				'post_id'           => $post_id,
				'slug'              => $post ? (string) ( $post->post_name ?? '' ) : '',
				'block_fingerprint' => (string) ( $c['block_fingerprint'] ?? '' ),
			) ),
			'confidence'          => SNT_SN_SCAN_CONF_PATTERN_ADOPTION,
			'evidence'            => array(
				'pattern_type' => (string) ( $c['pattern_type'] ?? '' ),
				'block_path'   => (string) ( $c['block_path'] ?? '' ),
				'permalink'    => (string) ( $c['permalink'] ?? '' ),
				'post_title'   => (string) ( $c['post_title'] ?? '' ),
			),
			// v12.0.0: pattern-adoption-apply was RETIRED from the rw door;
			// sn-apply's pattern_adoption change type performs the same write.
			'apply_hint'          => array(
				'tool'          => 'signal-noise/sn-apply',
				'required_args' => array( 'change.type:pattern_adoption', 'change.fingerprint', 'payload.replacement_markup', 'payload.pattern_type' ),
			),
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $posts_examined,
		'posts_skipped'  => 0,
		'truncated'      => false,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * 3. duplicate_body — structural, no ML kernel. Pair/group scan: targets
 *    holds every group member (usually 2, sometimes more).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_duplicate_body( $allowed_ids ) {
	if ( ! function_exists( 'snt_corpus_duplicate_scan' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	// Fixed to post_type 'post' — the same corpus convention near_duplicate/
	// link_candidates already use ("the cousin corpus is 'post' by
	// construction"); the standalone duplicate-body-scan ability still
	// exposes a post_type parameter for callers who need it.
	$result = snt_corpus_duplicate_scan( 'post' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$groups = is_array( $result['groups'] ?? null ) ? $result['groups'] : array();

	$candidates = array();
	foreach ( $groups as $g ) {
		$members = is_array( $g['posts'] ?? null ) ? $g['posts'] : array();
		if ( null !== $allowed_ids ) {
			$member_ids = array_map( static function ( $m ) { return (int) ( $m['post_id'] ?? 0 ); }, $members );
			if ( empty( array_intersect( $member_ids, $allowed_ids ) ) ) {
				continue;
			}
		}
		// Canonicalize member order post_id ASC — snt_corpus_duplicate_scan()
		// orders posts by post_date DESC with no tie-break, so two posts
		// sharing a post_date (the bulk-import case this scan exists to
		// catch) can return in EITHER order across runs. target_identity
		// was already order-independent (built from a sorted $ids copy);
		// 'targets' itself was NOT — sorting the array MUTATED here fixes
		// byte-identical reruns (acceptance test 1) for this scan_type.
		usort( $members, static function ( $x, $y ) {
			return (int) ( $x['post_id'] ?? 0 ) <=> (int) ( $y['post_id'] ?? 0 );
		} );
		$ids = array_map( static function ( $m ) { return (int) ( $m['post_id'] ?? 0 ); }, $members );
		$candidates[] = array(
			'target_identity'     => implode( ',', $ids ),
			'content_fingerprint' => (string) ( $g['content_hash'] ?? '' ),
			'targets'             => $members,
			'confidence'          => SNT_SN_SCAN_CONF_DUPLICATE_BODY,
			'evidence'            => array(
				'content_hash' => (string) ( $g['content_hash'] ?? '' ),
				'member_count' => count( $members ),
			),
			'apply_hint'          => null, // No apply path — this is a read-only finding a human resolves manually.
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => (int) ( $result['posts_scanned'] ?? 0 ),
		// The underlying scan doesn't expose a skip breakdown (empty-body
		// posts are silently excluded from grouping, not counted) — never
		// fabricated here; documented limitation, see FINDINGS.md.
		'posts_skipped'  => 0,
		'truncated'      => ! empty( $result['truncated'] ),
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * 4. near_duplicate — ML kernel (TF-IDF cosine over inc/ml-kernel.php).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_near_duplicate( $allowed_ids ) {
	if ( ! function_exists( 'snt_ml_cousin_pairs' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Cousin-detection module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	// No threshold parameter on sn_scan — always the pipeline default. A
	// caller needing a custom threshold uses the standalone
	// near-duplicate-scan ability directly.
	$result = snt_ml_cousin_pairs( SNT_ML_COUSIN_THRESHOLD_DEFAULT );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$pairs = is_array( $result['pairs'] ?? null ) ? $result['pairs'] : array();

	$candidates = array();
	foreach ( $pairs as $pair ) {
		$a = (array) ( $pair['a'] ?? array() );
		$b = (array) ( $pair['b'] ?? array() );
		// snt_ml_cousin_pairs() already guarantees a.post_id < b.post_id
		// ("deterministic pair orientation" per inc/ml-cousins.php) — but this
		// adapter re-asserts it rather than trusting the upstream invariant to
		// hold forever (audited per the same review that caught duplicate_body's
		// unsorted targets; cheap, and makes 'targets' order self-contained).
		if ( (int) ( $a['post_id'] ?? 0 ) > (int) ( $b['post_id'] ?? 0 ) ) {
			list( $a, $b ) = array( $b, $a );
		}
		$a_id = (int) ( $a['post_id'] ?? 0 );
		$b_id = (int) ( $b['post_id'] ?? 0 );
		if ( null !== $allowed_ids && ! in_array( $a_id, $allowed_ids, true ) && ! in_array( $b_id, $allowed_ids, true ) ) {
			continue;
		}
		$post_a = get_post( $a_id );
		$post_b = get_post( $b_id );
		$hash_a = $post_a ? snt_corpus_content_hash( (string) ( $post_a->post_content ?? '' ) ) : '';
		$hash_b = $post_b ? snt_corpus_content_hash( (string) ( $post_b->post_content ?? '' ) ) : '';
		$candidates[] = array(
			'target_identity'     => $a_id . ':' . $b_id,
			'content_fingerprint' => $hash_a . ':' . $hash_b,
			'targets'             => array( $a, $b ),
			'confidence'          => (float) ( $pair['cosine'] ?? 0.0 ),
			'evidence'            => array(
				'cosine'    => (float) ( $pair['cosine'] ?? 0.0 ),
				'threshold' => (float) ( $result['threshold'] ?? SNT_ML_COUSIN_THRESHOLD_DEFAULT ),
			),
			'apply_hint'          => null, // No apply path — cousin pairs are a human review finding.
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => (int) ( $result['posts_scanned'] ?? 0 ),
		'posts_skipped'  => 0, // Not exposed by the underlying pipeline; documented limitation.
		'truncated'      => ! empty( $result['truncated'] ),
	);
}

