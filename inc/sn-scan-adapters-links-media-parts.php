<?php
/**
 * Signal & Noise Tools: sn_scan adapters, adapters 5 and 6 (link_candidates, orphan_media).
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

