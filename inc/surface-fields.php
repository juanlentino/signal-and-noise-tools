<?php
/**
 * Signal & Noise Tools: the search surfaces' storage, read in one place.
 *
 * The surfaces change type writes four postmeta fields. Their meta keys used
 * to be spelled out twice inside sn-apply (the dry-run diff and the revision
 * write), and sn-posts needed a third copy to read them back. Those three now
 * read this map. The publish-mode write (snt_ability_update_post_surfaces()),
 * create_draft and the front-end readers in post-settings.php still spell the
 * keys themselves; tests/abilities-sn-posts.php pins this map to the publish
 * write's keys. No hooks: loaded by require_once from
 * inc/sn-apply/executors-diff-parts.php, inc/sn-apply/validation-gate2-parts.php
 * and inc/abilities-sn-posts.php. The length check reads two later-loaded
 * files' thresholds at call time.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * surfaces' meta fields and the keys they are stored under.
 *
 * @return array<string,string> field => meta key
 */
function snt_surface_meta_keys() {
	return array(
		'meta_description' => '_sn_meta_description',
		'og_card_title'    => '_sn_og_card_title',
		'seo_title'        => '_sn_seo_title',
		'focus_keyword'    => '_sn_focus_keyword',
	);
}

/**
 * The stored value of one surfaces meta field. Unset is '' (never null), the
 * value the surfaces diff has always reported as `before`.
 *
 * @param int    $post_id
 * @param string $field A key of snt_surface_meta_keys().
 * @return string
 */
function snt_surface_value( $post_id, $field ) {
	return (string) get_post_meta( (int) $post_id, snt_surface_meta_keys()[ $field ], true );
}

/**
 * Length status of a stored meta_description or seo_title, graded by the
 * thresholds the plugin already enforces, never restated here: sn-validate's
 * guideline range for the description, the search-title health check's
 * ceiling for the title (no floor exists for it, so it is never "short").
 * Each field is measured the way its own source measures it. Informational
 * only; nothing reads this to block a write. PURE.
 *
 * @param string $field 'meta_description' or 'seo_title'.
 * @param string $value
 * @return array{length:int,status:string} status: ok | short | long | empty
 */
function snt_surface_length_check( $field, $value ) {
	$value = (string) $value;
	if ( 'seo_title' === $field ) {
		$len = sn_health_search_title_length( $value );
		$min = 0;
		$max = SN_HEALTH_SEARCH_TITLE_MAX;
	} else {
		$len = mb_strlen( $value );
		$min = SNT_SN_VALIDATE_META_DESC_SOFT_MIN;
		$max = SNT_SN_VALIDATE_META_DESC_SOFT_MAX;
	}
	if ( '' === trim( $value ) ) {
		$status = 'empty';
	} elseif ( $len < $min ) {
		$status = 'short';
	} elseif ( $len > $max ) {
		$status = 'long';
	} else {
		$status = 'ok';
	}
	return array( 'length' => $len, 'status' => $status );
}

/**
 * A drafting label left in a surfaces value, such as "FOCUS KEYWORD:" or
 * "_sn_meta_description:", or '' when there is none. A drafting note once
 * reached a live description this way (post 2467, 2026-10-29). A label counts
 * only where a label sits: at the start of the value, a line or a sentence,
 * followed by a colon. Prose that uses the words mid-sentence ("An excerpt:
 * the first bars") passes, since any finding refuses the write. PURE.
 *
 * @param string $value
 * @return string The label as written, without its colon.
 */
function snt_surface_leaked_label( $value ) {
	$s     = '[\s_-]*';
	$label = 'focus' . $s . 'key(?:word|phrase)|(?:meta|seo)' . $s . '(?:description|title)|(?:og|open' . $s . 'graph)' . $s . '(?:card' . $s . ')?title|excerpt';
	$re    = '/(?:^|[.!?])\s*(?:_?sn_|post_)?(' . $label . ')\s*:/im';
	return 1 === preg_match( $re, (string) $value, $m ) ? $m[1] : '';
}
