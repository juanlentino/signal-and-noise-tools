<?php
/**
 * Signal & Noise Tools -- Content Health check: search titles.
 *
 * Check 28 (15.9.0): published notes whose <title> tag is the aphorism alone.
 *
 * A note's H1 is its voice ("The pen is not the notary") and stays. The title
 * tag is a separate field: the per-post `_sn_seo_title` override, which the
 * three notes that ranked for anything already used in the shape
 * "Aphorism: plain words a reader would search". The 2026-09-17 pressure test
 * against Search Console found 40 of 43 notes without one, 21 never shown to
 * anyone, and half of all impressions on an accidental match. Nobody types
 * the aphorism into Google.
 *
 * A DEFECT, not an advisory: it can reach zero (one line per note) and stay
 * there, and no other surface owns it. It is here so the gap cannot silently
 * reopen with the next scheduled note.
 *
 * The judge is PURE (rows in, findings out) so the rule is testable without
 * WordPress; the query is the thin part.
 *
 * @package SignalNoiseTools
 * @since 15.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The separator that makes a title query-shaped: "Aphorism: subtitle". */
const SN_HEALTH_SEARCH_TITLE_SEPARATOR = ': ';

/**
 * Is this override a query-shaped title? PURE.
 *
 * A subtitle after ": " with at least two words. An override that merely
 * repeats the aphorism, or carries a colon with nothing useful after it, is
 * not one.
 *
 * @since 15.9.0
 * @param string $seo_title The `_sn_seo_title` override (may be empty).
 * @return bool
 */
function sn_health_search_title_is_shaped( $seo_title ) {
	$seo_title = trim( (string) $seo_title );
	$at        = strpos( $seo_title, SN_HEALTH_SEARCH_TITLE_SEPARATOR );
	if ( false === $at ) {
		return false;
	}
	$subtitle = trim( substr( $seo_title, $at + strlen( SN_HEALTH_SEARCH_TITLE_SEPARATOR ) ) );
	return '' !== $subtitle && str_word_count( $subtitle ) >= 2;
}

/**
 * Judge the rows. PURE.
 *
 * @since 15.9.0
 * @param array $rows [{ID, post_title, seo_title, permalink, edit_url}].
 * @return array Findings in the health row shape.
 */
function sn_health_search_titles_judge( $rows ) {
	$findings = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) || sn_health_search_title_is_shaped( $r['seo_title'] ?? '' ) ) {
			continue;
		}
		$has_override = '' !== trim( (string) ( $r['seo_title'] ?? '' ) );
		$findings[]   = array(
			'subject_type'  => 'post',
			'subject_id'    => (int) ( $r['ID'] ?? 0 ),
			'subject_url'   => (string) ( $r['permalink'] ?? '' ),
			'subject_label' => (string) ( $r['post_title'] ?? '' ),
			'edit_url'      => (string) ( $r['edit_url'] ?? '' ),
			'note'          => $has_override
				? 'The SEO title carries no subtitle after ": ". Keep the aphorism, add the plain words a reader would search.'
				: 'No SEO title: the title tag is the aphorism alone. Add "Aphorism: plain words a reader would search" in the post\'s Signal & Noise box.',
		);
	}
	return $findings;
}

/**
 * The rows: every published AND scheduled note with its override. Scheduled
 * on the owner's word (2026-09-17): a note should carry its title before it
 * goes out, not be caught by the check the morning after.
 *
 * @since 15.9.0
 * @return array|null Rows, or null when the query could not run.
 */
function sn_health_search_titles_rows() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return null;
	}
	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, COALESCE( pm.meta_value, '' ) AS seo_title
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm
		        ON pm.post_id = p.ID
		       AND pm.meta_key = '_sn_seo_title'
		 WHERE p.post_status IN ( 'publish', 'future' )
		   AND p.post_type = 'post'
		 ORDER BY p.post_date_gmt DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return null;
	}
	foreach ( $rows as &$r ) {
		$r['permalink'] = (string) get_permalink( (int) $r['ID'] );
		$r['edit_url']  = admin_url( 'post.php?post=' . (int) $r['ID'] . '&action=edit' );
	}
	unset( $r );
	return $rows;
}

/**
 * CHECK 28: notes without a query-shaped title tag.
 *
 * @since 15.9.0
 * @return array
 */
function sn_health_check_search_titles() {
	$rows = sn_health_search_titles_rows();
	return sn_health_pack_check(
		'Notes without a query-shaped title (published and scheduled)',
		null === $rows ? array() : sn_health_search_titles_judge( $rows ),
		'The H1 stays the aphorism. Set the SEO title in the post\'s Signal & Noise box as "Aphorism: plain words a reader would search" (the shape the ranking notes already use); it changes only the title tag.',
		null === $rows ? 'The posts table could not be read.' : null
	);
}
