<?php
/**
 * Signal & Noise Tools — Block-migrations detector.
 *
 * Walks parse_blocks() output for all published AND scheduled
 * post_type=post posts, identifies core/heading blocks at level 3+ that
 * have NO preceding level-2 core/heading in the same post
 * (heading-hierarchy-skip — WCAG 1.3.1 violation).
 *
 * SCOPE (rewritten 2026-08-14; supersedes the 2026-08-08 note): the old
 * rule flagged level 3 ONLY, on the premise that H4-without-H2 was the
 * accepted house pattern. That premise was wrong — the single-note
 * template titles with H1, so the correct first-level body subhead is H2,
 * and H4-without-H2 is the same hierarchy skip as H3. The rule now flags
 * ANY first-level body subhead that is not H2 (H3 and H4 alike; level 5/6
 * too, same shape). A canonical wp:heading h2 stores NO level attr —
 * missing level means 2 here, never 0. Scheduled (status=future) posts
 * are walked too: Notes publish as permanently dated and canonical, so a
 * heading fix is free BEFORE publish and mints ledger history after —
 * the linter must surface a scheduled skip while the fix is still free.
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
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$candidates = array();

	foreach ( $posts as $post ) {
		$dismissed = (array) get_post_meta( $post->ID, '_snt_block_migrations_dismissed', true );

		$blocks = parse_blocks( (string) $post->post_content );

		// For heading-hierarchy-skip, track per-post state: once any h2
		// appears in the walk, subsequent h3/h4s are valid. This is a
		// simplification — true per-section validity is deferred (YAGNI).
		$seen_h2 = false;
		snt_block_migrations_walk_blocks( $blocks, $post, $dismissed, $candidates, '0', $seen_h2 );
	}

	return $candidates;
}

/**
 * Recursive walker. Updates $seen_h2 by reference as it encounters h2
 * blocks. Appends heading-skip candidates (any level-3+ heading before
 * the first h2) to $candidates by reference.
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
		$name = $block['blockName'] ?? '';
		// Canonical wp:heading serialization OMITS the level attr for h2
		// (the block default) — a missing level is 2, never 0.
		$level = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;

		if ( 'core/heading' === $name && 2 === $level ) {
			$seen_h2 = true;
		}

		if ( 'core/heading' === $name && $level > 2 && ! $seen_h2 ) {
			$fp        = snt_block_fp_fingerprint( $block, (int) $post->ID, $path_prefix . '/' . $idx );
			$dismiss_k = 'heading-hierarchy-skip:' . $fp;
			if ( ! in_array( $dismiss_k, $dismissed, true ) ) {
				$candidates[] = array(
					'post_id'           => (int) $post->ID,
					'migration_type'    => 'heading-hierarchy-skip',
					'block_fingerprint' => $fp,
					'block_path'        => $path_prefix . '/' . $idx,
					'post_title'        => (string) ( $post->post_title ?? '' ),
					'permalink'         => (string) get_permalink( $post->ID ),
					'current_level'     => $level,
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
 * Pure compute: detect candidates and build the scan envelope. Does NOT
 * write anything — split out of snt_block_migrations_run_scan() (v10.29.0)
 * so a caller that must not write (sn_scan's read-only contract) can get
 * the identical envelope without the side-effecting set_transient() below.
 *
 * @return array{candidates:array,counts:array{heading_hierarchy_skip:int,posts_affected:int},scanned_at:int}
 *
 * @since 10.29.0
 */
function snt_block_migrations_compute() {
	$candidates = snt_block_migrations_detect_candidates();

	$by_type  = array( 'heading-hierarchy-skip' => 0 );
	$post_ids = array();
	foreach ( $candidates as $c ) {
		$by_type[ $c['migration_type'] ]++;
		$post_ids[ $c['post_id'] ] = true;
	}

	return array(
		'candidates' => $candidates,
		'counts'     => array(
			'heading_hierarchy_skip' => $by_type['heading-hierarchy-skip'],
			'posts_affected'         => count( $post_ids ),
		),
		'scanned_at' => time(),
	);
}

/**
 * Run the scan and cache the result in a user-scoped transient. Byte-identical
 * behavior to pre-10.29.0: this is now compute() + the write, unchanged for
 * every existing caller (the block-migrations-scan ability, the admin tab).
 *
 * @return array{candidates:array,counts:array{heading_hierarchy_skip:int,posts_affected:int},scanned_at:int}
 *
 * @since 4.5.0
 */
function snt_block_migrations_run_scan() {
	$result = snt_block_migrations_compute();

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
