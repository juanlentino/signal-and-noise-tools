<?php
/**
 * Signal & Noise Tools — sn_scan per-scan_type adapters.
 *
 * Six adapters, each a thin reshape over an EXISTING internal function —
 * never a re-implementation of detection logic, never wp_get_ability().
 * Every adapter takes $allowed_ids (int[]|null; null = scope "all") and
 * returns either a WP_Error or:
 *
 *   array{
 *     candidates: list<array{
 *       target_identity:string, content_fingerprint:string, targets:array,
 *       confidence:float, evidence:array, apply_hint:array|null,
 *     }>,
 *     posts_examined: int,
 *     posts_skipped: int,
 *     truncated: bool,
 *   }
 *
 * inc/abilities-sn-scan.php turns each raw candidate into the final
 * envelope row (computing candidate_id, rounding confidence, forcing
 * dismissed:false — see that file for why).
 *
 * @package SignalNoiseTools
 * @since 10.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// No native score for these four sources — documented constants, per the
// design contract ("where a scan has no native score, use a documented
// constant"). Values reflect relative certainty: an exact byte-match is
// definite (1.0); a WCAG structural violation is a clear rule match (0.9);
// a pure-SQL orphan finding is a strong but not certain signal (0.85,
// "conservative by design" per the detector's own docblock — false
// positives are possible for edge-case references); a pattern-adoption
// upgrade is a stylistic opportunity, not a defect (0.7).
const SNT_SN_SCAN_CONF_DUPLICATE_BODY     = 1.0;
const SNT_SN_SCAN_CONF_BLOCK_MIGRATIONS   = 0.9;
const SNT_SN_SCAN_CONF_ORPHAN_MEDIA       = 0.85;
// A tag either has an empty description / zero posts or it does not — the
// detector is a fact check, not an inference, so it sits with duplicate_body.
const SNT_SN_SCAN_CONF_TAG_HYGIENE        = 1.0;
// Prose classification by snt_emdash_scan_content() is a strong structural
// call, not a byte-exact match — same tier as orphan_media (v10.58.0, part
// of the emdash envelope fix below).
const SNT_SN_SCAN_CONF_EMDASH             = 0.85;
const SNT_SN_SCAN_CONF_PATTERN_ADOPTION   = 0.7;

/**
 * Registry: scan_type => adapter callable. Mirrors inc/ml-pipelines.php's
 * snt_ml_pipelines() registry pattern for house-style consistency.
 *
 * @return array<string,callable>
 */
function snt_sn_scan_adapters() {
	$adapters = array(
		'block_migrations' => 'snt_sn_scan_adapter_block_migrations',
		'pattern_adoption' => 'snt_sn_scan_adapter_pattern_adoption',
		'duplicate_body'   => 'snt_sn_scan_adapter_duplicate_body',
		'near_duplicate'   => 'snt_sn_scan_adapter_near_duplicate',
		'link_candidates'  => 'snt_sn_scan_adapter_link_candidates',
		'emdash'           => 'snt_sn_scan_adapter_emdash',
		'orphan_media'     => 'snt_sn_scan_adapter_orphan_media',
		// v10.58.0 — detector + adapter live in inc/sn-scan-anchor-violations.php
		// (own file, the emdash-scanner precedent).
		'anchor_violations' => 'snt_sn_scan_adapter_anchor_violations',
		// v13.27.0: the vocabulary source — wraps the tag_hygiene health
		// check (the same real producer the advisory tier reads).
		'tag_hygiene'       => 'snt_sn_scan_adapter_tag_hygiene',
		// v13.57.0 — inc/sn-scan-search-disagreement.php (own file).
		'search_disagreement' => 'snt_sn_scan_adapter_search_disagreement',
	);
	return apply_filters( 'sn_scan_adapters', $adapters );
}

/**
 * Shared: whether $post_id passes the resolved scope filter.
 *
 * @param int        $post_id
 * @param array|null $allowed_ids
 * @return bool
 */
function snt_sn_scan_in_scope( $post_id, $allowed_ids ) {
	return null === $allowed_ids || in_array( (int) $post_id, $allowed_ids, true );
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

/* ════════════════════════════════════════════════════════════════════════
 * 5. link_candidates — ML kernel, fanned out across the scope's source
 *    posts (the absorbed ability is single-post; sn_scan calls it once
 *    per source post to build a corpus-wide view).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_link_candidates( $allowed_ids ) {
	if ( ! function_exists( 'snt_ml_link_candidates' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Candidate-generation module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}

	if ( null !== $allowed_ids ) {
		$source_ids = $allowed_ids;
	} else {
		if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
			return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
		}
		$source_ids = array_map( static function ( $p ) { return (int) $p->ID; }, snt_corpus_fetch_posts( 'any', 'post' ) );
	}

	$posts_examined = 0;
	$posts_skipped  = 0;
	$candidates     = array();

	foreach ( $source_ids as $pid ) {
		$pid    = (int) $pid;
		$result = snt_ml_link_candidates( $pid, SNT_ML_LINK_LIMIT_MAX );
		if ( is_wp_error( $result ) ) {
			if ( 'snt_ml_not_built' === $result->get_error_code() ) {
				return $result; // Whole artifact unbuilt — propagate the 503 verbatim.
			}
			$posts_skipped++; // e.g. snt_ml_no_post for an explicit scope ID outside the corpus.
			continue;
		}
		$posts_examined++;

		$src_post = get_post( $pid );
		$src_hash = $src_post ? snt_corpus_content_hash( (string) ( $src_post->post_content ?? '' ) ) : '';
		$src_row  = array(
			'post_id' => $pid,
			'title'   => $src_post ? (string) ( $src_post->post_title ?? '' ) : '',
			'slug'    => $src_post ? (string) ( $src_post->post_name ?? '' ) : '',
			'url'     => $src_post ? (string) get_permalink( $pid ) : '',
		);

		$targets = is_array( $result['candidates'] ?? null ) ? $result['candidates'] : array();
		foreach ( $targets as $t ) {
			$tgt_id   = (int) ( $t['post_id'] ?? 0 );
			$tgt_slug = (string) ( $t['slug'] ?? '' );
			$candidates[] = array(
				'target_identity'     => $pid . ':' . $tgt_id,
				// Changes when the SOURCE body changes (a new link could
				// appear/disappear) or the target's identity changes.
				'content_fingerprint' => $src_hash . ':' . md5( $tgt_slug . '|' . $tgt_id ),
				// targets order is SEMANTIC (source, then target), not
				// post_id-sorted — unlike duplicate_body's interchangeable
				// group members, each link_candidates pair has a fixed,
				// asymmetric role. It's already order-independent of any
				// unstable DB ordering: each candidate is built from exactly
				// one (source_id, target_id) pair per loop iteration, never
				// from a same-rank group whose DB order could vary.
				'targets'             => array( $src_row, array(
					'post_id' => $tgt_id,
					'title'   => (string) ( $t['title'] ?? '' ),
					'slug'    => $tgt_slug,
					'url'     => (string) ( $t['url'] ?? '' ),
				) ),
				'confidence'          => max( 0.0, min( 1.0, (float) ( $t['score'] ?? 0.0 ) ) ),
				'evidence'            => array(
					'score'      => (float) ( $t['score'] ?? 0.0 ),
					'source_url' => $src_row['url'],
				),
				// Deliberately null — see the ability description + FINDINGS.md:
				// ai-link-apply validates a positional fingerprint that only
				// an AI-mediated suggest call (ai-link-suggest/ai-pair-suggest)
				// can produce; this deterministic scan has no anchor/position
				// to offer, so wiring apply_hint here would fail acceptance
				// test 5 (fingerprint parity) by construction.
				'apply_hint'          => null,
			);
		}
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $posts_examined,
		'posts_skipped'  => $posts_skipped,
		'truncated'      => false,
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * 6. orphan_media — structural, no ML kernel. NEW absorption: wraps the
 *    pure-SQL detector (sn_health_check_orphaned_media), never the
 *    AI-gated ai-orphan-suggest path. See FINDINGS.md session-4 for the
 *    verdict on why this qualified.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param int[]|null $allowed_ids Attachment IDs when scope is restricted.
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_orphan_media( $allowed_ids ) {
	if ( ! function_exists( 'sn_health_check_orphaned_media' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Orphaned-media health check not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$result   = sn_health_check_orphaned_media();
	$findings = is_array( $result['findings'] ?? null ) ? $result['findings'] : array();

	// Sizing only (mirrors the detector's own WHERE clause) — never a
	// re-implementation of sn_health_attachment_is_referenced()'s
	// reference-detection logic.
	global $wpdb;
	$one_week_ago   = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	$posts_examined = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND post_date_gmt < %s",
		$one_week_ago
	) );

	$candidates = array();
	foreach ( $findings as $f ) {
		$aid = (int) ( $f['subject_id'] ?? 0 );
		if ( ! snt_sn_scan_in_scope( $aid, $allowed_ids ) ) {
			continue;
		}
		$candidates[] = array(
			'target_identity'     => (string) $aid,
			'content_fingerprint' => md5( (string) ( $f['subject_url'] ?? '' ) ),
			'targets'             => array( array(
				'attachment_id' => $aid,
				'title'         => (string) ( $f['subject_label'] ?? '' ),
				'url'           => (string) ( $f['subject_url'] ?? '' ),
			) ),
			'confidence'          => SNT_SN_SCAN_CONF_ORPHAN_MEDIA,
			'evidence'            => array(
				'note'      => (string) ( $f['note'] ?? '' ),
				'edit_url'  => (string) ( $f['edit_url'] ?? '' ),
			),
			// ai-orphan-apply requires only attachment_id — no fingerprint
			// gate, so there's no scanner/applier drift to fail here, unlike
			// link_candidates' apply_hint above. Apply-time safety: since
			// v10.28.1 snt_ai_orphan_apply_impl() re-verifies orphanhood
			// before the force-delete via
			// sn_health_attachment_is_referenced_now() and refuses with
			// WP_Error snt_orphan_no_longer (409) when the attachment became
			// referenced after the scan — the same scan-time signal battery,
			// shared through sn_health_reference_sets(). (An earlier draft of
			// this comment wrongly called the pre-v10.28.1 impl
			// "TOCTOU-checked"; the adversarial review corrected it, and the
			// gap itself was then closed in v10.28.1 — see FINDINGS.md.)
			// This apply_hint still bypasses the optional AI verdict step
			// (ai-orphan-suggest); that is a product judgment call for the
			// caller, not something apply_hint's own contract (its
			// required_args pass apply's OWN validation) forbids.
			// v12.0.0: NULL, joining duplicate_body, near_duplicate and
			// link_candidates. ai-orphan-apply is not on either door and has not
			// been — this hint has been a dead pointer since the tool was doored,
			// predating the v12.0.0 retirements rather than caused by them. There
			// is deliberately no sn-apply equivalent: sn-apply's change types are
			// post-content operations, and force-deleting an attachment is not
			// one. Orphan media is actioned in wp-admin.
			'apply_hint'          => null,
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $posts_examined,
		'posts_skipped'  => 0,
		'truncated'      => false, // The detector's own LIMIT 500 is a memory guard, not reported by it as truncation.
	);
}

/**
 * sn-scan scope: em-dashes in PROSE.
 *
 * Reports only candidates the classifier calls prose; structural uses (an
 * attribution lead, the no-value glyph, code/preformatted text, markup) are counted
 * in `structural_skipped` so a skip is visible rather than silent. Each candidate
 * carries everything sn-apply's `emdash_replace` needs to splice it: phrase,
 * raw-content position, replacement, context snippet, and the drift fingerprint the
 * apply gate checks.
 *
 * @param array|null $allowed_ids Restrict to these post ids, or null for the corpus.
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_emdash( $allowed_ids ) {
	if ( ! function_exists( 'snt_emdash_scan_content' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Em-dash scanner not loaded.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}
	if ( null !== $allowed_ids ) {
		$source_ids = $allowed_ids;
	} else {
		if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
			return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
		}
		$source_ids = array_map( static function ( $p ) { return (int) $p->ID; }, snt_corpus_fetch_posts( 'any', 'any' ) );
	}

	$candidates = array();
	$examined   = 0;
	$skipped    = 0;
	foreach ( $source_ids as $pid ) {
		$post = get_post( (int) $pid );
		if ( ! $post ) {
			continue;
		}
		++$examined;
		$content = (string) $post->post_content;
		foreach ( snt_emdash_scan_content( $content ) as $row ) {
			// v10.66.0: a parenthetical arrives as ONE row carrying both splices
			// (classification 'prose_pair') and is emitted as a single candidate
			// whose payload feeds change.payload.edits, so the pair lands in one
			// write and one provenance version. Without this branch it would fail
			// the 'prose' test below and be counted as SKIPPED — a real candidate
			// silently reported as structural.
			if ( 'prose_pair' === $row['classification'] ) {
				$pair_edits = array();
				foreach ( $row['edits'] as $edit ) {
					$pair_edits[] = array(
						'phrase'          => $edit['phrase'],
						'position'        => (int) $edit['position'],
						'replacement'     => $edit['replacement'],
						'context_snippet' => $edit['context_snippet'],
						'fingerprint'     => function_exists( 'snt_ai_drift_fingerprint' )
							? snt_ai_drift_fingerprint( $content, $edit['phrase'], (int) $edit['position'] )
							: '',
					);
				}
				$pair_fps     = array();
				foreach ( $pair_edits as $pe ) {
					$pair_fps[] = $pe['fingerprint'];
				}
				$candidates[] = array(
					'target_identity'     => $post->ID . ':' . (int) $row['position'] . ':pair',
					'content_fingerprint' => md5( implode( '|', $pair_fps ) ),
					'targets'             => array( array(
						'post_id' => (int) $post->ID,
						'slug'    => (string) ( $post->post_name ?? '' ),
					) ),
					'confidence'          => SNT_SN_SCAN_CONF_EMDASH,
					'evidence'            => array(
						'position'        => (int) $row['position'],
						'context_snippet' => $row['context_snippet'],
						'pair'            => $row['pair'],
						'reason'          => $row['reason'],
						// No top-level phrase/replacement: this candidate is only
						// appliable whole, and half a parenthetical is not an edit.
						'edits'           => $pair_edits,
					),
					'apply_hint'          => array(
						'tool'          => 'signal-noise/sn-apply',
						'required_args' => array( 'change.type:emdash_replace', 'payload.edits' ),
						'note'          => 'Apply BOTH edits in ONE call via change.payload.edits: one write, one provenance version. Each edit carries its own fingerprint, so change.fingerprint is unused for a batch.',
					),
				);
				continue;
			}
			if ( 'prose' !== $row['classification'] ) {
				++$skipped;
				continue;
			}
			$fingerprint = function_exists( 'snt_ai_drift_fingerprint' )
				? snt_ai_drift_fingerprint( $content, $row['phrase'], $row['position'] )
				: '';
			// AUDIT FIX (2026-08-08): this adapter shipped (v10.51.0) emitting
			// raw scanner rows WITHOUT the envelope keys inc/abilities-sn-scan.php
			// reads (target_identity/content_fingerprint/targets/confidence) —
			// confirmed live: every emdash candidate collapsed to the SAME
			// candidate_id (sha256 of "emdash||"), empty targets, empty
			// evidence, confidence 0, and every payload field emdash_replace
			// needs was silently dropped by the assembler. The determinism
			// test masked it (identical garbage IS byte-identical across runs).
			$candidates[] = array(
				'target_identity'     => $post->ID . ':' . (int) $row['position'],
				'content_fingerprint' => '' !== $fingerprint ? $fingerprint : md5( $row['phrase'] . '|' . (int) $row['position'] ),
				'targets'             => array( array(
					'post_id' => (int) $post->ID,
					'slug'    => (string) ( $post->post_name ?? '' ),
				) ),
				'confidence'          => SNT_SN_SCAN_CONF_EMDASH,
				'evidence'            => array(
					'phrase'          => $row['phrase'],
					'position'        => (int) $row['position'],
					'replacement'     => $row['replacement'],
					'context_snippet' => $row['context_snippet'],
					'pair'            => $row['pair'],
					'fingerprint'     => $fingerprint,
				),
				'apply_hint'          => array(
					'tool'          => 'signal-noise/sn-apply',
					'required_args' => array( 'change.type:emdash_replace', 'change.fingerprint', 'payload.phrase', 'payload.position', 'payload.replacement', 'payload.context_snippet' ),
				),
			);
		}
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $examined,
		'posts_skipped'  => $skipped,
		'truncated'      => false,
	);
}

/**
 * sn-scan source: tag hygiene — the vocabulary's two drift modes, itemized.
 *
 * Wraps sn_health_check_tag_hygiene() (v13.24.0), the SAME real producer the
 * advisory health tier reads — this adapter exists because that tier renders a
 * COUNT with no names (worklist checks have no findings table by IA design),
 * so "Tag hygiene (3)" was a number nobody could act on without wp-admin
 * spelunking. Candidates here carry the names.
 *
 * Term-level: $allowed_ids is always null by the time this runs — the
 * dispatcher rejects any non-"all" scope for this scan_type before dispatch
 * (snt_sn_scan_resolve_scope), because a post-id scope silently ignored would
 * report "scoped" results that were never scoped.
 *
 * apply_hint per detector, both targets on the rw door (pinned reachable by
 * tests/sn-scan-apply-hint-reachable.php): undescribed -> describe-tags (the
 * AI suggest whose output feeds apply-tag-description, the only-if-empty
 * writer); unused -> prune-unused-tags. `posts_examined` counts TERMS — the
 * envelope key is shared across sources and renaming it per source would
 * break the pinned output shape for one label's sake.
 *
 * @param int[]|null $allowed_ids Always null (scope "all"); kept for the
 *                                shared adapter signature.
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_tag_hygiene( $allowed_ids ) {
	if ( ! function_exists( 'sn_health_check_tag_hygiene' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Tag-hygiene health check not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$result = sn_health_check_tag_hygiene();
	if ( is_string( $result['skipped'] ?? null ) && '' !== $result['skipped'] ) {
		// The check could not measure (taxonomy unreadable). A skip must not
		// become an empty candidate list — empty means "measured, clean".
		return new WP_Error( 'snt_tag_hygiene_skipped', (string) $result['skipped'], array( 'status' => 503 ) );
	}
	$findings = is_array( $result['findings'] ?? null ) ? $result['findings'] : array();

	$terms_examined = 0;
	if ( function_exists( 'get_terms' ) ) {
		$all = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
		$terms_examined = is_array( $all ) ? count( $all ) : 0;
	}

	$candidates = array();
	foreach ( $findings as $f ) {
		$type = (string) ( $f['type'] ?? '' );
		$name = (string) ( $f['name'] ?? '' );
		if ( '' === $name || ! in_array( $type, array( 'undescribed', 'unused' ), true ) ) {
			continue;
		}
		$candidates[] = array(
			'target_identity'     => $name,
			// The fingerprint is the STATE, not just the name: a tag that
			// gains posts (unused -> undescribed) or a description (gone)
			// must produce a different candidate_id, not resurrect the old.
			'content_fingerprint' => md5( $type . '|' . $name ),
			'targets'             => array( array(
				'name'  => $name,
				'posts' => (int) ( $f['posts'] ?? 0 ),
			) ),
			'confidence'          => SNT_SN_SCAN_CONF_TAG_HYGIENE,
			'evidence'            => array(
				'detector' => 'undescribed' === $type ? 'undescribed_tag' : 'unused_tag',
				'note'     => 'undescribed' === $type
					? 'In-use tag with no description: the archive hero dek and meta description both fall back.'
					: 'Zero-post tag, usually typo-minted (wp_set_post_tags creates on any miss).',
			),
			'apply_hint'          => 'undescribed' === $type
				? array(
					'tool'          => 'signal-noise/describe-tags',
					'required_args' => array( 'tags:[' . $name . ']' ),
				)
				: array(
					'tool'          => 'signal-noise/prune-unused-tags',
					'required_args' => array(),
				),
		);
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $terms_examined, // TERMS — see the docblock.
		'posts_skipped'  => 0,
		'truncated'      => false,
	);
}
