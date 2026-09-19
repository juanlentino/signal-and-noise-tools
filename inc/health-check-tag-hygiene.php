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
 *  - OVER THE CEILING (16.9.2): a note carrying more than SN_TAG_CEILING
 *    tags. Read off the corpus 2026-09-19: 32 of 44 published notes sit at
 *    3 or 4, and 25 tags over 44 notes means an archive holds 3 to 14 notes;
 *    at 5 and up the peripheral notes fill the archives and every archive
 *    starts to read as the whole corpus. The pre-publish gate warns at the
 *    same line before it happens; Content › Tags lists the notes over it.
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

/** The most tags a note carries: the ONE line the gate, this check and the Tags leaf read. */
const SN_TAG_CEILING = 4;

/**
 * Published and scheduled notes carrying more than SN_TAG_CEILING tags.
 * PURE given the two WordPress reads.
 *
 * @return array<int,array{post_id:int,title:string,tags:int}> Most tags first.
 */
function sn_tag_notes_over_ceiling() {
	$ids = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	$out = array();
	foreach ( is_array( $ids ) ? $ids : array() as $id ) {
		$n = count( (array) wp_get_post_tags( (int) $id, array( 'fields' => 'ids' ) ) );
		if ( $n > SN_TAG_CEILING ) {
			$out[] = array( 'post_id' => (int) $id, 'title' => (string) get_the_title( (int) $id ), 'tags' => $n );
		}
	}
	usort( $out, static fn( $a, $b ) => $b['tags'] <=> $a['tags'] ?: $a['post_id'] <=> $b['post_id'] );
	return $out;
}

/**
 * Tags with no description, and tags with no posts.
 *
 * @return array sn_health_pack_check envelope.
 */
function sn_health_check_tag_hygiene() {
	$label    = 'Tag hygiene';
	$fix_hint = 'Undescribed: write one sentence in wp-admin -> Posts -> Tags -> description; it becomes the archive hero dek AND the tag\'s meta description. Unused (zero posts): usually a typo-created term - wp_set_post_tags() mints one on any miss; prune it (the write door\'s prune-unused-tags tool, or delete in wp-admin). Over the ceiling: a note carrying more than ' . SN_TAG_CEILING . ' tags; keep the facets it is about (Content › Tags lists them, the pre-publish gate warns before it happens). Advisory: opportunities, not problems.';

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
		// #1178: term->count is publish-only; a tag held only by scheduled or
		// draft notes reads 0. Relationships across every status decide "unused".
		$posts = count( (array) get_objects_in_term( (int) ( $term->term_id ?? 0 ), 'post_tag' ) );
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

	foreach ( sn_tag_notes_over_ceiling() as $row ) {
		$findings[] = array(
			'type'    => 'over_ceiling',
			'name'    => $row['title'],
			'post_id' => $row['post_id'],
			'tags'    => $row['tags'],
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
