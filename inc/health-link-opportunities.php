<?php
/**
 * Signal & Noise Tools — Health check: link opportunities (v8.1.0).
 *
 * C2 semantic pairing (approach C of the interlinking arc): pairs of
 * published notes that SHOULD link to each other but don't — no link in
 * either direction AND no literal title mention. Title-mention pairs stay
 * with unlinked_mentions (inc/health-checks.php); the skip rule below
 * mirrors that check's exact trigger so the two checks PARTITION the pair
 * space — one pair can never double-report.
 *
 * Zero-AI at scan time: candidates come from shared post_tag terms plus
 * lexical overlap of top distinctive terms (a lightweight TF-IDF over
 * stripped prose, pure PHP). AI enters only on the Suggest click
 * (inc/ai-pair-suggest.php), never during the scan.
 *
 * ADVISORY tier (sn_health_advisory_checks): candidates are opportunities,
 * not rot — they surface as "N advisories" and never touch finding_total.
 *
 * @package SignalNoiseTools
 * @since 8.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Candidate-pass knobs. A pair nominates when it shares a post_tag OR at
// least SN_HEALTH_PAIRS_MIN_SHARED_TERMS distinctive terms; ranked by
// TAG_WEIGHT * shared_tags + shared_terms, best first, capped per source.
define( 'SN_HEALTH_PAIRS_MAX_PER_SOURCE', 3 );
define( 'SN_HEALTH_PAIRS_TAG_WEIGHT', 3 );
define( 'SN_HEALTH_PAIRS_MIN_SHARED_TERMS', 3 );
define( 'SN_HEALTH_PAIRS_TOP_TERMS', 20 );
define( 'SN_HEALTH_PAIRS_MIN_TOKEN_LEN', 4 );

/**
 * Stopword list for the lexical pass (>= 4 chars only — shorter tokens are
 * dropped by the length floor before this list is consulted).
 *
 * @return string[]
 */
function sn_health_pair_stopwords() {
	return array(
		'that', 'this', 'with', 'from', 'have', 'they', 'their', 'there',
		'will', 'would', 'could', 'should', 'about', 'which', 'when', 'what',
		'were', 'been', 'because', 'into', 'only', 'also', 'after', 'before',
		'more', 'most', 'some', 'such', 'than', 'then', 'them', 'these',
		'those', 'very', 'your', 'just', 'like', 'over', 'under', 'again',
		'once', 'here', 'where', 'while', 'does', 'doing', 'each', 'both',
		'between', 'through', 'during', 'above', 'below', 'same', 'other',
		'another', 'being', 'having', 'still', 'even', 'much', 'many',
		'every', 'never', 'always', 'might', 'must', 'made', 'make', 'makes',
		'making', 'thing', 'things', 'something', 'anything', 'nothing',
		'really', 'around', 'though', 'without', 'within',
	);
}

/**
 * Tokenize stripped prose into candidate terms: lowercase, length floor,
 * stopword-filtered. Repeats preserved (callers count frequency).
 *
 * @param string $stripped wp_strip_all_tags'd post_content.
 * @return string[]
 */
function sn_health_pair_tokens( $stripped ) {
	$tokens = preg_split( '/[^a-z0-9]+/', strtolower( (string) $stripped ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $tokens ) ) {
		return array();
	}
	$stop = array_flip( sn_health_pair_stopwords() );
	$out  = array();
	foreach ( $tokens as $t ) {
		if ( strlen( $t ) >= SN_HEALTH_PAIRS_MIN_TOKEN_LEN && ! isset( $stop[ $t ] ) ) {
			$out[] = $t;
		}
	}
	return $out;
}

/**
 * Top distinctive terms for one post: tf * idf, best first, capped.
 * A term present in EVERY post weighs log(1) = 0 and is dropped — ubiquity
 * carries no pairing signal.
 *
 * @param array $tf         term => count for this post.
 * @param array $df         term => number of posts containing it.
 * @param int   $total_docs Corpus size.
 * @return array term => weight, max SN_HEALTH_PAIRS_TOP_TERMS entries.
 */
function sn_health_pair_top_terms( $tf, $df, $total_docs ) {
	$weights = array();
	foreach ( $tf as $term => $count ) {
		$d = isset( $df[ $term ] ) ? (int) $df[ $term ] : 1;
		$w = $count * log( max( 1, (int) $total_docs ) / max( 1, $d ) );
		if ( $w > 0 ) {
			$weights[ $term ] = $w;
		}
	}
	arsort( $weights );
	return array_slice( $weights, 0, SN_HEALTH_PAIRS_TOP_TERMS, true );
}

/**
 * Whether a candidate pair has already been JUDGED non-actionable.
 *
 * v8.1.2 (owner rule 2026-07-02: "the ones that don't suggest anything are
 * noise"): the Suggest verdict store doubles as the scan's memory. A stored
 * skip/unsure means the AI already said no; a stored link whose nomination
 * no longer yields a usable splice contract is advice-only — both suppress
 * the pair. No entry (never judged, or either post edited since — the key
 * carries BOTH modified stamps) keeps the pair nominated. v8.4.1: the
 * memory is the DURABLE snt_ai_verdict_store (autoload=no option) — it was
 * transients, and the v10.22.0 auto-purges flush those on every update,
 * which resurrected judged pairs after every release (the owner-reported
 * "persistent entries").
 *
 * @param array $src Source row (ID, post_content, post_modified_gmt).
 * @param array $tgt Target row (ID, post_modified_gmt).
 * @return bool True when the pair should be suppressed.
 *
 * @since 8.1.2
 */
function sn_health_pair_judged_noise( $src, $tgt ) {
	if ( ! function_exists( 'snt_ai_verdict_lookup_pair' ) ) {
		return false;
	}
	// v8.4.5: ID-keyed lookup with the stamps compared in the payload (the
	// lookup also reads + migrates pre-v8.4.5 stamp-keyed rows). Apply
	// restamps its post's rows, so judged siblings survive our own splice;
	// a real edit still re-nominates.
	$cached = snt_ai_verdict_lookup_pair(
		(int) $src['ID'],
		(int) $tgt['ID'],
		(string) ( $src['post_modified_gmt'] ?? '' ),
		(string) ( $tgt['post_modified_gmt'] ?? '' )
	);
	if ( ! is_array( $cached ) || ! isset( $cached['verdict'] ) ) {
		return false;
	}
	if ( 'link' !== (string) $cached['verdict'] ) {
		return true; // Judged skip/unsure: nothing to apply.
	}
	if ( ! function_exists( 'snt_ai_pair_nomination_contract' ) ) {
		return false;
	}
	$raw      = (string) $src['post_content'];
	$stripped = wp_strip_all_tags( strip_shortcodes( $raw ) );
	return null === snt_ai_pair_nomination_contract( $raw, $stripped, (string) ( $cached['anchor'] ?? '' ) );
}

/**
 * Zero-AI link-opportunities check (v8.1.0): published note pairs that
 * should link but don't. One finding per unordered pair; the NEWER note is
 * the subject/source (the newer note references the older; the theme's
 * cited-by footer then serves readers the reverse direction).
 *
 * The pairwise pass is quadratic over the LIMIT-500 corpus like the
 * mentions check; the real site is a few dozen notes.
 *
 * @return array Packed check (sn_health_pack_check shape).
 */
function sn_health_check_link_opportunities() {
	$label    = 'Link opportunities';
	$fix_hint = 'Two notes cover related subjects but neither links the other. Suggest asks AI whether a link helps and nominates an anchor phrase already in your prose; Apply wraps it. Advisory: opportunities, not problems.';

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_name, post_content, post_modified_gmt
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish'
		   AND post_type = 'post'
		 ORDER BY post_date DESC
		 LIMIT 500",
		ARRAY_A
	);
	if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	// One prep pass: stripped prose, per-post tf, corpus df, tag ids.
	$total = count( $rows );
	$prep  = array();
	$df    = array();
	foreach ( $rows as $i => $row ) {
		$stripped = wp_strip_all_tags( strip_shortcodes( (string) $row['post_content'] ) );
		$tf       = array_count_values( sn_health_pair_tokens( $stripped ) );
		foreach ( array_keys( $tf ) as $term ) {
			$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
		}
		$tags       = function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( (int) $row['ID'], 'post_tag', array( 'fields' => 'ids' ) ) : array();
		$prep[ $i ] = array(
			'stripped' => $stripped,
			'tf'       => $tf,
			'tags'     => is_array( $tags ) ? array_map( 'intval', $tags ) : array(),
		);
	}
	foreach ( $prep as $i => $p ) {
		$prep[ $i ]['top'] = sn_health_pair_top_terms( $p['tf'], $df, $total );
	}

	// Pairwise pass. Rows are date-DESC, so for i < j rows[i] is the NEWER
	// note = the source.
	$scored = array();
	for ( $i = 0; $i < $total; $i++ ) {
		for ( $j = $i + 1; $j < $total; $j++ ) {
			$src = $rows[ $i ];
			$tgt = $rows[ $j ];

			// Connected pairs (either direction) have nothing to gain.
			if ( sn_health_contains_note_link( (string) $src['post_content'], (string) $tgt['post_name'] )
				|| sn_health_contains_note_link( (string) $tgt['post_content'], (string) $src['post_name'] ) ) {
				continue;
			}

			// Title-mention pairs belong to unlinked_mentions — mirror that
			// check's EXACT trigger (eligibility + stripped stripos) so the
			// two checks partition the pair space with no gap and no overlap.
			$tgt_title = trim( (string) $tgt['post_title'] );
			if ( sn_health_mention_target_eligible( $tgt_title ) && false !== stripos( $prep[ $i ]['stripped'], $tgt_title ) ) {
				continue;
			}

			$shared_tags  = count( array_intersect( $prep[ $i ]['tags'], $prep[ $j ]['tags'] ) );
			$shared_terms = count( array_intersect_key( $prep[ $i ]['top'], $prep[ $j ]['top'] ) );
			if ( 0 === $shared_tags && $shared_terms < SN_HEALTH_PAIRS_MIN_SHARED_TERMS ) {
				continue;
			}
			$scored[] = array(
				'i'     => $i,
				'j'     => $j,
				'score' => SN_HEALTH_PAIRS_TAG_WEIGHT * $shared_tags + $shared_terms,
			);
		}
	}

	// Best first, so the per-source cap keeps the strongest pairs.
	usort( $scored, function ( $a, $b ) {
		return $b['score'] <=> $a['score'];
	} );

	$findings   = array();
	$per_source = array();
	foreach ( $scored as $pair ) {
		$src = $rows[ $pair['i'] ];
		$tgt = $rows[ $pair['j'] ];
		$sid = (int) $src['ID'];
		if ( ( $per_source[ $sid ] ?? 0 ) >= SN_HEALTH_PAIRS_MAX_PER_SOURCE ) {
			continue;
		}
		$per_source[ $sid ] = ( $per_source[ $sid ] ?? 0 ) + 1;
		// v8.1.2 owner rule: a pair already judged non-actionable is NOISE —
		// it renders nothing. v8.4.4: but it KEEPS its cap slot. Suppression
		// used to run before the cap, so judging the rendered top-3 freed
		// their slots and every re-scan promoted the next-ranked unjudged
		// pairs — Suggest All never converged. With the slot consumed, the
		// cap means "top-N SCORED candidates per source" and one judging
		// pass reaches quiet; an edit to either post changes the verdict
		// key's modified stamps and re-nominates naturally.
		if ( sn_health_pair_judged_noise( $src, $tgt ) ) {
			continue;
		}

		$findings[] = array(
			'subject_type'  => 'post',
			'subject_id'    => $sid,
			'subject_url'   => (string) get_permalink( $sid ),
			'subject_label' => (string) $src['post_title'],
			'edit_url'      => admin_url( 'post.php?post=' . $sid . '&action=edit' ),
			'note'          => sprintf( 'Covers subjects related to "%s" (/notes/%s) without linking to it.', (string) $tgt['post_title'], (string) $tgt['post_name'] ),
			'target_id'     => (int) $tgt['ID'],
			'target_title'  => (string) $tgt['post_title'],
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
