<?php
/**
 * Signal & Noise Tools — health check: tag hygiene (advisory, worklist).
 *
 * v13.24.0, built the day the 23 tag descriptions seeded (v13.23.0). The
 * vocabulary is now fully described, and this check keeps the two ways it
 * drifts visible:
 *
 *  - UNDESCRIBED: a tag with no description. Both consuming surfaces fall
 *    back cleanly (corpus dek, no meta description), so this is an
 *    opportunity, never a defect — each sentence written lights the archive
 *    hero dek and the tag's meta description at once.
 *  - UNUSED: a tag with zero posts. `wp_set_post_tags()` creates a term on
 *    any miss, so a typo while editing silently mints one; the write door's
 *    prune-unused-tags tool is the cleanup.
 *
 * Advisory TIER, worklist SURFACE, content FAMILY: neither finding is "wrong
 * on the page today" (the Health test), and new tags will keep arriving —
 * a nudge that can re-open is exactly what the advisory tier is for.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags with no description, and tags with no posts.
 *
 * @return array sn_health_pack_check envelope.
 */
function sn_health_check_tag_hygiene() {
	$label    = 'Tag hygiene';
	$fix_hint = 'Undescribed: write one sentence in wp-admin -> Posts -> Tags -> description; it becomes the archive hero dek AND the tag\'s meta description. Unused (zero posts): usually a typo-created term - wp_set_post_tags() mints one on any miss; prune it (the write door\'s prune-unused-tags tool, or delete in wp-admin). Advisory: opportunities, not problems.';

	if ( ! function_exists( 'get_terms' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Taxonomy API unavailable in this runtime.' );
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'get_terms failed; the taxonomy could not be read.' );
	}

	$findings = array();
	foreach ( $terms as $term ) {
		if ( ! is_object( $term ) ) {
			continue;
		}
		$posts = isset( $term->count ) ? (int) $term->count : 0;
		if ( 0 === $posts ) {
			// A zero-post tag reports ONCE, as unused: the fix is pruning,
			// not describing, so the undescribed branch must not double it.
			$findings[] = array(
				'type'  => 'unused',
				'name'  => (string) $term->name,
				'posts' => 0,
			);
			continue;
		}
		if ( '' === trim( (string) ( $term->description ?? '' ) ) ) {
			$findings[] = array(
				'type'  => 'undescribed',
				'name'  => (string) $term->name,
				'posts' => $posts,
			);
		}
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
