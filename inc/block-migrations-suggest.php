<?php
/**
 * Signal & Noise Tools — Block-migrations suggest impl.
 *
 * Given (post_id, block_fingerprint, migration_type), locates the
 * candidate block in the post's parse_blocks() tree and synthesizes
 * replacement markup via deterministic block-tree mutation + serialize_block.
 *
 * NO AI. Shares the fingerprint locate primitive with pattern-adoption via
 * inc/block-fingerprint-engine.php (v7.7.1).
 *
 * Migration types (extensible via SNT_BLOCK_MIGRATIONS_VALID_TYPES in
 * inc/block-migrations-detect.php):
 *   - heading-hierarchy-skip  ← any non-h2 first-level subhead (h3/h4) → h2
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure impl: generate the replacement markup for a block-migration candidate.
 *
 * @param int    $post_id
 * @param string $block_fingerprint  md5 from the scan.
 * @param string $migration_type     'heading-hierarchy-skip' (currently the only type).
 * @return array{ok:bool,suggestion_markup:string,fingerprint:string,post_id:int,migration_type:string}|WP_Error
 *
 * WP_Error codes:
 *   snt_block_migration_invalid_type           (422)
 *   snt_block_migration_post_not_found         (404)
 *   snt_block_migration_candidate_not_found    (404)
 *
 * @since 4.5.0
 */
function snt_block_migrations_suggest_impl( $post_id, $block_fingerprint, $migration_type ) {
	$post_id           = (int) $post_id;
	$block_fingerprint = (string) $block_fingerprint;
	$migration_type    = (string) $migration_type;

	// Defensive: detect module may not have loaded in test-isolation runs.
	if ( ! defined( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES' ) ) {
		define( 'SNT_BLOCK_MIGRATIONS_VALID_TYPES', array( 'heading-hierarchy-skip' ) );
	}

	if ( ! in_array( $migration_type, SNT_BLOCK_MIGRATIONS_VALID_TYPES, true ) ) {
		return new WP_Error(
			'snt_block_migration_invalid_type',
			__( 'migration_type must be one of: heading-hierarchy-skip.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'snt_block_migration_post_not_found',
			__( 'Post not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$blocks = parse_blocks( (string) $post->post_content );
	$match  = snt_block_fp_find( $blocks, $block_fingerprint );

	if ( null === $match ) {
		return new WP_Error(
			'snt_block_migration_candidate_not_found',
			__( 'Candidate block not found in current post content. Re-run scan.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	if ( 'heading-hierarchy-skip' === $migration_type ) {
		$markup = snt_block_migrations_build_heading_promotion( $match );
	} else {
		// Defensive — invalid type was already gated above. Defense-in-depth.
		return new WP_Error(
			'snt_block_migration_invalid_type',
			__( 'Unknown migration_type.', 'signal-and-noise-tools' ),
			array( 'status' => 422 )
		);
	}

	return array(
		'ok'                => true,
		'suggestion_markup' => $markup,
		'fingerprint'       => $block_fingerprint,
		'post_id'           => $post_id,
		'migration_type'    => $migration_type,
	);
}

/**
 * Build the heading-promotion replacement markup.
 *
 * Mutates the heading block's level attribute (unsets it — omitting the
 * JSON key is the canonical wp:heading representation for h2 per
 * wp-includes/blocks.php serialize_block behavior).
 *
 * Also rewrites innerContent (what serialize_block actually USES for
 * reconstruction — not innerHTML). innerHTML is updated for consistency
 * with downstream code that inspects parse_blocks output.
 *
 * @param array $heading_block
 * @return string  serialize_block output.
 *
 * @since 4.5.0
 */
function snt_block_migrations_build_heading_promotion( $heading_block ) {
	// Defensive guard: only mutate genuine core/heading blocks. A forged
	// fingerprint matching a non-heading block (paragraph, list, etc.)
	// would otherwise get its innerHTML regex-processed and return mangled
	// markup. Pass through unchanged via serialize_block instead.
	if ( 'core/heading' !== ( $heading_block['blockName'] ?? '' ) ) {
		return serialize_block( $heading_block );
	}

	// The source tag comes from the block's OWN level attr (2026-08-14 rule
	// rewrite: candidates are ANY non-h2 first-level subhead — h3 AND h4).
	// A hardcoded h3 regex here would leave an h4 candidate's markup as
	// <h4> while attrs claimed h2 — a Gutenberg block-validation mismatch
	// on next editor load. Missing level = 2 (the attrs default).
	$level = isset( $heading_block['attrs']['level'] ) ? (int) $heading_block['attrs']['level'] : 2;

	// Unset level: serialize_block omits the JSON key when attrs is empty,
	// which is canonical for h2 (default level).
	unset( $heading_block['attrs']['level'] );

	if ( 2 === $level || $level < 1 || $level > 6 ) {
		// Already h2 (nothing to rewrite) or a level no core/heading can
		// carry — serialize as-is rather than regex-guessing a tag name.
		return serialize_block( $heading_block );
	}

	$patterns     = array( '/<h' . $level . '(?=[\s>])([^>]*)>/', '/<\/h' . $level . '>/' );
	$replacements = array( '<h2$1>', '</h2>' );

	// Mutate innerContent — what serialize_block uses for reconstruction.
	foreach ( $heading_block['innerContent'] ?? array() as $i => $content ) {
		if ( is_string( $content ) ) {
			$heading_block['innerContent'][ $i ] = preg_replace( $patterns, $replacements, $content );
		}
	}

	// innerHTML mutation is informational — serialize_block ignores it for
	// reconstruction. Update for consistency.
	if ( isset( $heading_block['innerHTML'] ) ) {
		$heading_block['innerHTML'] = preg_replace( $patterns, $replacements, $heading_block['innerHTML'] );
	}

	return serialize_block( $heading_block );
}
