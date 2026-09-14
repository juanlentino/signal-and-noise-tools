<?php
/**
 * Signal & Noise Tools — sn-scan scan_type "editorial_conventions". (v14.7.0)
 *
 * The same drift the sn-validate check flags on PROPOSED markup, over the
 * EXISTING corpus (scheduled posts included, like anchor_violations): each
 * finding is a candidate with block_path and a position-bound fingerprint,
 * so a class fix applies through sn-apply's block_replace + block_path and,
 * because it changes no prose, coalesces on the ledger. Detect and report;
 * applying is a separate editorial decision.
 *
 * Own file per the emdash / anchor_violations precedent: the detector it
 * wraps is inc/editorial-conventions-detect.php, shared with sn-validate.
 *
 * @package SignalNoiseTools
 * @since 14.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A shape test is deterministic; the only uncertainty is whether the author meant it. */
const SNT_SN_SCAN_CONF_EDITORIAL_CONVENTIONS = 0.9;

/**
 * Adapter: one candidate per finding, in document order per post.
 *
 * @param int[]|null $allowed_ids
 * @return array|WP_Error
 */
function snt_sn_scan_adapter_editorial_conventions( $allowed_ids ) {
	if ( ! function_exists( 'snt_editorial_conventions_detect' ) || ! function_exists( 'parse_blocks' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Editorial conventions detector not loaded.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}
	if ( null === snt_editorial_conventions_registry() ) {
		// The theme owns the registry. Without it there is nothing to test
		// against, and an empty candidate list would read as "all clean".
		return new WP_Error( 'snt_registry_unavailable', __( 'The editorial convention registry is unavailable: the active theme does not provide sn_theme_editorial_conventions().', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}
	if ( ! function_exists( 'snt_corpus_fetch_posts' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus inspect helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 503 ) );
	}

	$source_ids = null !== $allowed_ids
		? $allowed_ids
		: array_map( static function ( $p ) { return (int) $p->ID; }, snt_corpus_fetch_posts( 'any', 'post' ) );

	$candidates = array();
	$examined   = 0;
	foreach ( $source_ids as $pid ) {
		$post = get_post( (int) $pid );
		if ( ! $post ) {
			continue;
		}
		$examined++;
		$content = (string) $post->post_content;
		$tree    = parse_blocks( $content );
		$found   = snt_editorial_conventions_detect( $tree );
		if ( ! is_array( $found ) ) {
			continue;
		}
		$content_hash = function_exists( 'snt_corpus_content_hash' ) ? (string) snt_corpus_content_hash( $content ) : md5( $content );
		foreach ( $found as $f ) {
			$idx   = (int) substr( (string) $f['block_path'], 2 );
			$block = $tree[ $idx ] ?? null;
			$fp    = is_array( $block ) && function_exists( 'snt_block_fp_fingerprint' ) ? snt_block_fp_fingerprint( $block, (int) $post->ID, (string) $f['block_path'] ) : md5( (int) $post->ID . '|' . $f['block_path'] . '|' . $f['id'] );
			$is_class = 'class' === (string) $f['fix'];
			$candidates[] = array(
				'target_identity'     => (string) $post->ID,
				'content_fingerprint' => $fp,
				'targets'             => array( array(
					'post_id'           => (int) $post->ID,
					'slug'              => (string) $post->post_name,
					'block_fingerprint' => $fp,
					'block_path'        => (string) $f['block_path'],
					// block_replace binds on the LIVE content_hash, not the
					// block fingerprint; carry it so the apply is one call.
					'content_hash'      => $content_hash,
				) ),
				'confidence'          => SNT_SN_SCAN_CONF_EDITORIAL_CONVENTIONS,
				'evidence'            => array(
					'convention_id' => (string) $f['id'],
					'label'         => (string) $f['label'],
					'detector'      => (string) $f['id'],
					'block_name'    => (string) $f['block_name'],
					'block_path'    => (string) $f['block_path'],
					'fix'           => (string) $f['fix'],
					'message'       => (string) $f['message'],
					'replacement'   => (string) $f['replacement'],
					'detail'        => (array) $f['evidence'],
					'post_title'    => (string) get_the_title( $post ),
					'permalink'     => (string) get_permalink( $post ),
					'post_status'   => (string) $post->post_status,
					'read'          => 'sn-site-facts{editorial_conventions}',
				),
				// A class fix is a pure attribute change: block_replace at the
				// same block_path with evidence.replacement; the dry run's
				// ledger_impact reads "coalesces" because the normalized prose
				// is byte-identical. A form fix (a wrap, a move, an SVG
				// rewrite) is the author's: no apply path is named.
				'apply_hint'          => $is_class ? array(
					'tool'          => 'signal-noise/sn-apply',
					'required_args' => array( 'change.type:block_replace', 'change.fingerprint:targets[0].content_hash', 'payload.block_path:targets[0].block_path', 'payload.blocks:evidence.replacement', 'dry_run:true first (expect diff.ledger_impact "coalesces")' ),
				) : null,
			);
		}
	}

	return array(
		'candidates'     => $candidates,
		'posts_examined' => $examined,
		'posts_skipped'  => 0,
		'truncated'      => false,
	);
}
