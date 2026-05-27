<?php
/**
 * Signal & Noise Tools — Block-migrations detector.
 *
 * Walks parse_blocks() output for all published post_type=post posts,
 * identifies core/heading blocks with attrs.level === 3 that have NO
 * preceding core/heading with attrs.level === 2 in the same post
 * (heading-hierarchy-skip — WCAG 1.3.1 violation).
 *
 * Each candidate gets a fingerprint = md5(serialize_block($node)) for
 * concurrency-safe apply later. Candidates whose fingerprints appear in
 * the post's _snt_block_migrations_dismissed meta (as
 * "<migration_type>:<fingerprint>" strings) are excluded.
 *
 * The scan result is cached in a user-scoped transient
 * (snt_block_migrations_candidates_<user_id>) with a 1-hour TTL so the
 * admin UI can render without re-running the whole sweep.
 *
 * Mirrors inc/pattern-adoption-detect.php structurally. Both are pure
 * structural detectors — zero AI.
 *
 * @package SignalNoiseTools
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_BLOCK_MIGRATIONS_TRANSIENT_TTL = HOUR_IN_SECONDS;
const SNT_BLOCK_MIGRATIONS_VALID_TYPES   = array( 'heading-hierarchy-skip' );

/**
 * Walk all published posts and return the list of block-migration
 * candidates with their fingerprints, filtered against per-post dismiss
 * state.
 *
 * @return array<int,array{post_id:int,migration_type:string,block_fingerprint:string,block_path:string,post_title:string,permalink:string,current_level:int,target_level:int}>
 *
 * @since 4.5.0
 */
function snt_block_migrations_detect_candidates() {
	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$candidates = array();

	foreach ( $posts as $post ) {
		$dismissed = (array) get_post_meta( $post->ID, '_snt_block_migrations_dismissed', true );

		$blocks = parse_blocks( (string) $post->post_content );

		// For heading-hierarchy-skip, track per-post state: once any h2
		// appears in the walk, subsequent h3s are valid. This is a
		// simplification — true per-section validity is deferred (YAGNI).
		$seen_h2 = false;
		snt_block_migrations_walk_blocks( $blocks, $post, $dismissed, $candidates, '0', $seen_h2 );
	}

	return $candidates;
}

/**
 * Recursive walker. Updates $seen_h2 by reference as it encounters h2
 * blocks. Appends h3-skip candidates to $candidates by reference.
 *
 * @param array  $tree
 * @param object $post
 * @param array  $dismissed
 * @param array  $candidates  Accumulator (by ref).
 * @param string $path_prefix
 * @param bool   $seen_h2     By reference.
 * @return void
 *
 * @since 4.5.0
 */
function snt_block_migrations_walk_blocks( $tree, $post, $dismissed, &$candidates, $path_prefix, &$seen_h2 ) {
	foreach ( $tree as $idx => $block ) {
		$name  = $block['blockName'] ?? '';
		$level = (int) ( $block['attrs']['level'] ?? 0 );

		if ( 'core/heading' === $name && 2 === $level ) {
			$seen_h2 = true;
		}

		if ( 'core/heading' === $name && 3 === $level && ! $seen_h2 ) {
			$fp        = md5( serialize_block( $block ) );
			$dismiss_k = 'heading-hierarchy-skip:' . $fp;
			if ( ! in_array( $dismiss_k, $dismissed, true ) ) {
				$candidates[] = array(
					'post_id'           => (int) $post->ID,
					'migration_type'    => 'heading-hierarchy-skip',
					'block_fingerprint' => $fp,
					'block_path'        => $path_prefix . '/' . $idx,
					'post_title'        => (string) ( $post->post_title ?? '' ),
					'permalink'         => (string) get_permalink( $post->ID ),
					'current_level'     => 3,
					'target_level'      => 2,
				);
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_block_migrations_walk_blocks(
				$block['innerBlocks'],
				$post,
				$dismissed,
				$candidates,
				$path_prefix . '/' . $idx . '/innerBlocks',
				$seen_h2
			);
		}
	}
}

/**
 * Run the scan and cache the result in a user-scoped transient.
 *
 * @return array{candidates:array,counts:array{heading_hierarchy_skip:int,posts_affected:int},scanned_at:int}
 *
 * @since 4.5.0
 */
function snt_block_migrations_run_scan() {
	$candidates = snt_block_migrations_detect_candidates();

	$by_type  = array( 'heading-hierarchy-skip' => 0 );
	$post_ids = array();
	foreach ( $candidates as $c ) {
		$by_type[ $c['migration_type'] ]++;
		$post_ids[ $c['post_id'] ] = true;
	}

	$result = array(
		'candidates' => $candidates,
		'counts'     => array(
			'heading_hierarchy_skip' => $by_type['heading-hierarchy-skip'],
			'posts_affected'         => count( $post_ids ),
		),
		'scanned_at' => time(),
	);

	$key = 'snt_block_migrations_candidates_' . (int) get_current_user_id();
	set_transient( $key, $result, SNT_BLOCK_MIGRATIONS_TRANSIENT_TTL );

	return $result;
}

/**
 * Read the cached scan result for the current user, or null if none.
 *
 * @return array|null
 *
 * @since 4.5.0
 */
function snt_block_migrations_last_scan() {
	$key = 'snt_block_migrations_candidates_' . (int) get_current_user_id();
	$val = get_transient( $key );
	return is_array( $val ) ? $val : null;
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — scan trigger.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/tools/block-migrations-scan', array(
		'methods'             => 'POST',
		'callback'            => function() {
			$result = snt_block_migrations_run_scan();
			return rest_ensure_response( $result );
		},
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
} );
