<?php
/**
 * Signal & Noise Tools: sn_scan adapters, the emdash and tag_hygiene adapters.
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
		$source_ids = array_map( static function ( $p ) { return (int) $p->ID; }, snt_corpus_fetch_posts( 'any', 'post' ) );
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
