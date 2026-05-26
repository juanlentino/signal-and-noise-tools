<?php
/**
 * Signal & Noise Tools — Pattern-adoption detector.
 *
 * Walks parse_blocks() output for all published post_type=post posts,
 * identifies blocks that are upgrade candidates for the v9.2.0 theme
 * patterns:
 *   - core/quote                                → pull-quote candidate
 *   - core/list with attrs.ordered === true     → steps-enumerated candidate
 *
 * Each candidate gets a fingerprint = md5(serialize_block($node)) for
 * concurrency-safe apply later. Candidates whose fingerprints appear
 * in the post's _snt_pattern_adoption_dismissed meta (as
 * "<pattern_type>:<fingerprint>" strings) are excluded.
 *
 * The scan result is cached in a user-scoped transient
 * (snt_pattern_adoption_candidates_<user_id>) with a 1-hour TTL so
 * the admin UI can render without re-running the whole sweep.
 *
 * Zero AI: this entire module is pure structural detection.
 *
 * @package SignalNoiseTools
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PATTERN_ADOPTION_TRANSIENT_TTL = HOUR_IN_SECONDS;

/**
 * Walk all published posts and return the list of pattern-adoption
 * candidates with their fingerprints, filtered against per-post
 * dismiss state.
 *
 * @return array<int,array{post_id:int,pattern_type:string,block_fingerprint:string,block_path:string,post_title:string,permalink:string}>
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_detect_candidates() {
	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$candidates = array();

	foreach ( $posts as $post ) {
		// (array) cast on get_post_meta's $single=true return guarantees an array.
		// Edge case: missing meta returns "" → (array)"" → array(""); the empty-string
		// entry never matches a "<type>:<fp>" key in in_array(), so it's benign.
		$dismissed = (array) get_post_meta( $post->ID, '_snt_pattern_adoption_dismissed', true );

		$blocks = parse_blocks( (string) $post->post_content );
		snt_pattern_adoption_walk_blocks( $blocks, $post, $dismissed, $candidates, '0' );
	}

	return $candidates;
}

/**
 * Recursive walker — appends to $candidates by reference. $block_path
 * encodes the position within the tree (e.g. "0/0", "0/0/innerBlocks/2")
 * for diagnostic display in the admin UI. The leading "0" is the initial
 * $path_prefix seeded by the caller; each $idx is appended after it.
 *
 * @param array  $tree
 * @param object $post
 * @param array  $dismissed   Array of "<pattern_type>:<fingerprint>" strings.
 * @param array  $candidates  Accumulator (by ref).
 * @param string $path_prefix
 * @return void
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_walk_blocks( $tree, $post, $dismissed, &$candidates, $path_prefix ) {
	foreach ( $tree as $idx => $block ) {
		$pattern_type = snt_pattern_adoption_match_block_type( $block );
		if ( null !== $pattern_type ) {
			$fp        = md5( serialize_block( $block ) );
			$dismiss_k = $pattern_type . ':' . $fp;
			if ( ! in_array( $dismiss_k, $dismissed, true ) ) {
				$candidates[] = array(
					'post_id'           => (int) $post->ID,
					'pattern_type'      => $pattern_type,
					'block_fingerprint' => $fp,
					'block_path'        => $path_prefix . '/' . $idx,
					'post_title'        => (string) ( $post->post_title ?? '' ),
					'permalink'         => (string) get_permalink( $post->ID ),
				);
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			snt_pattern_adoption_walk_blocks(
				$block['innerBlocks'],
				$post,
				$dismissed,
				$candidates,
				$path_prefix . '/' . $idx . '/innerBlocks'
			);
		}
	}
}

/**
 * Map a parsed block to the v4.3.0 pattern type it's a candidate for,
 * or null if it's not a candidate.
 *
 * @param array $block Parsed block from parse_blocks().
 * @return string|null One of 'pull-quote', 'steps-enumerated', or null.
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_match_block_type( $block ) {
	$name = $block['blockName'] ?? '';
	if ( 'core/quote' === $name ) {
		return 'pull-quote';
	}
	if ( 'core/list' === $name && ! empty( $block['attrs']['ordered'] ) ) {
		return 'steps-enumerated';
	}
	return null;
}

/**
 * Run the scan and cache the result in a user-scoped transient.
 *
 * @return array{candidates:array,counts:array{pull_quote:int,steps_enumerated:int,posts_affected:int},scanned_at:int}
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_run_scan() {
	$candidates = snt_pattern_adoption_detect_candidates();

	$by_type   = array( 'pull-quote' => 0, 'steps-enumerated' => 0 );
	$post_ids  = array();
	foreach ( $candidates as $c ) {
		$by_type[ $c['pattern_type'] ]++;
		$post_ids[ $c['post_id'] ] = true;
	}

	$result = array(
		'candidates' => $candidates,
		'counts'     => array(
			'pull_quote'       => $by_type['pull-quote'],
			'steps_enumerated' => $by_type['steps-enumerated'],
			'posts_affected'   => count( $post_ids ),
		),
		'scanned_at' => time(),
	);

	$key = 'snt_pattern_adoption_candidates_' . (int) get_current_user_id();
	set_transient( $key, $result, SNT_PATTERN_ADOPTION_TRANSIENT_TTL );

	return $result;
}

/**
 * Read the cached scan result for the current user, or null if none.
 *
 * @return array|null
 *
 * @since 4.3.0
 */
function snt_pattern_adoption_last_scan() {
	$key = 'snt_pattern_adoption_candidates_' . (int) get_current_user_id();
	$val = get_transient( $key );
	return is_array( $val ) ? $val : null;
}

/* ════════════════════════════════════════════════════════════════════════
 * REST endpoint — scan trigger.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/health/pattern-adoption-scan', array(
		'methods'             => 'POST',
		'callback'            => function() {
			$result = snt_pattern_adoption_run_scan();
			return rest_ensure_response( $result );
		},
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
} );
