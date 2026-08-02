<?php
/**
 * Signal & Noise Tools — sn_validate per-surface deterministic checks
 * (MCP consolidation, session 5).
 *
 * Every constant here that expresses a LIMIT reuses an existing constant
 * from the ability/generator it inverts, rather than re-declaring the
 * number — that is the whole anti-drift point of this tool (see
 * ~/.claude/session-data/SN-MCP-new/sn-validate-spec.md). Where the
 * source-of-truth is only prose inside an AI system-instruction constant
 * (no existing machine-readable constant), the number/list is extracted
 * here ONCE with an inline citation of the exact constant it was read
 * from — never invented, never duplicated a SECOND time elsewhere.
 *
 * ZERO MODEL CALLS. This file must never reference snt_ai_generate_with_
 * constraints(), wp_ai_client_prompt(), or any wp_remote_* transport —
 * pinned structurally by tests/abilities-sn-validate.php (acceptance
 * test 6). ZERO WRITES — every function here is a pure read + compute.
 *
 * @package SignalNoiseTools
 * @since 10.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ════════════════════════════════════════════════════════════════════════
 * Soft-guideline constants. These are NOT hard caps (the hard caps are
 * reused directly from SNT_SURFACES_FIELD_CAPS / SNT_AI_ALT_APPLY_MAX_LENGTH
 * at call time below, never copied). Each soft window is lifted from the
 * corresponding ai-generate-* system-instruction prose — the only place
 * these numbers exist today — cited inline.
 * ════════════════════════════════════════════════════════════════════════ */

// Sourced from SNT_AI_EXCERPT_SYSTEM (inc/ai-excerpt.php): "50-75 words, 2-3 sentences".
const SNT_SN_VALIDATE_EXCERPT_WORDS_MIN     = 50;
const SNT_SN_VALIDATE_EXCERPT_WORDS_MAX     = 75;
const SNT_SN_VALIDATE_EXCERPT_SENTENCES_MIN = 2;
const SNT_SN_VALIDATE_EXCERPT_SENTENCES_MAX = 3;

// Sourced from SNT_AI_META_DESC_SYSTEM (inc/ai-meta-description.php): "140-160 characters".
const SNT_SN_VALIDATE_META_DESC_SOFT_MIN = 140;
const SNT_SN_VALIDATE_META_DESC_SOFT_MAX = 160;

// Sourced from SNT_AI_OG_CARD_TITLE_SYSTEM (inc/ai-og-card-title.php): "60-90 characters total".
const SNT_SN_VALIDATE_OG_TITLE_SOFT_MIN = 60;
const SNT_SN_VALIDATE_OG_TITLE_SOFT_MAX = 90;

// Sourced from the THEME's signal-and-noise/ai-generate-page-note-summary input
// schema (~/Projects/signal-and-noise/inc/abilities-ai-generation.php:56-57):
// max_words minimum 10, maximum 60, default 30. Cross-repo constant that
// cannot be `require`d directly (separate plugin/theme codebases, same
// caution FINDINGS.md already flags for get-seo-route-meta) — mirrored here
// deliberately, not silently assumed; see FINDINGS.md session-5.
const SNT_SN_VALIDATE_NOTE_SUMMARY_SOFT_WORDS = 30;
const SNT_SN_VALIDATE_NOTE_SUMMARY_MAX_WORDS  = 60;

// Sourced from SNT_AI_ALT_SUGGEST_SYSTEM (inc/ai-alt-text-suggest.php): "80-125 characters".
const SNT_SN_VALIDATE_ALT_SOFT_MIN = 80;
const SNT_SN_VALIDATE_ALT_SOFT_MAX = 125;

// Sourced from SNT_AI_ALT_BASE_RULES (inc/ai-alt-text-suggest.php): 'No "image
// of" / "picture of" / "photo of" preamble.' — the base rule shared by both
// alt-suggest abilities; this is the first place it becomes a checkable list.
const SNT_SN_VALIDATE_ALT_REDUNDANT_PREFIXES = array( 'image of', 'picture of', 'photo of' );

// A generic filename shape (basename + common image extension), used to
// reject alt text that is literally a filename rather than a description.
const SNT_SN_VALIDATE_ALT_FILENAME_PATTERN = '/^[\w\-]+\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i';

// Extracted from SNT_AI_EXCERPT_SYSTEM's banned-phrase prose (inc/ai-excerpt.php,
// the "Banned outright" / "Never refer to" sentences) — evidence-only lexicon,
// never a gate. This is the first machine-readable form of that prose; the
// prompt constant itself is untouched (still the generator's own contract).
const SNT_SN_VALIDATE_BANNED_PHRASES = array(
	'this piece', 'this note', 'this article', 'this essay',
	'we explore', 'the author argues', 'offers a test', 'explains why', 'unpacks how',
	"in today's world", "it's worth noting", 'at its core',
	'not just', 'delve', 'landscape', 'crucial', 'leverage',
);

/* ════════════════════════════════════════════════════════════════════════
 * Shared primitives.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $surface
 * @param string $check
 * @param string $severity 'error'|'warning'|'info'
 * @param string $message
 * @param mixed  $observed
 * @param mixed  $expected
 * @param array  $evidence
 * @param string $content_identity Content-derived, not random — same discipline
 *                                 as sn_scan's candidate_id.
 * @return array
 */
function snt_sn_validate_finding( $surface, $check, $severity, $message, $observed, $expected, array $evidence, $content_identity ) {
	return array(
		'finding_id' => hash( 'sha256', $surface . '|' . $check . '|' . (string) $content_identity ),
		'surface'    => $surface,
		'check'      => $check,
		'severity'   => $severity,
		'message'    => $message,
		'observed'   => $observed,
		'expected'   => $expected,
		'evidence'   => $evidence,
	);
}

/**
 * Sentence count via terminal punctuation runs. Deliberately simple (no
 * abbreviation-aware tokenizer) — false positives on "e.g." are acceptable
 * for a WARNING-only heuristic, same posture as sn_health_drift_time_patterns().
 *
 * @param string $text
 * @return int
 */
function snt_sn_validate_sentence_count( $text ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return 0;
	}
	$parts = preg_split( '/[.!?]+(?:\s+|$)/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $parts ) ? count( $parts ) : 0;
}

/* ════════════════════════════════════════════════════════════════════════
 * excerpt — word_count, sentence_count. Inverts ai-generate-excerpt.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $value
 * @param int    $post_id
 * @return array
 */
function snt_sn_validate_check_excerpt( $value, $post_id ) {
	$findings = array();
	$identity = $post_id . '|' . $value;
	$words    = function_exists( 'snt_word_count' ) ? snt_word_count( $value ) : str_word_count( (string) $value );

	if ( $words < SNT_SN_VALIDATE_EXCERPT_WORDS_MIN || $words > SNT_SN_VALIDATE_EXCERPT_WORDS_MAX ) {
		$findings[] = snt_sn_validate_finding(
			'excerpt', 'word_count', 'warning',
			__( 'Excerpt word count is outside the guideline range.', 'signal-and-noise-tools' ),
			$words,
			SNT_SN_VALIDATE_EXCERPT_WORDS_MIN . '-' . SNT_SN_VALIDATE_EXCERPT_WORDS_MAX,
			array(), $identity
		);
	}

	$sentences = snt_sn_validate_sentence_count( $value );
	if ( $sentences < SNT_SN_VALIDATE_EXCERPT_SENTENCES_MIN || $sentences > SNT_SN_VALIDATE_EXCERPT_SENTENCES_MAX ) {
		$findings[] = snt_sn_validate_finding(
			'excerpt', 'sentence_count', 'warning',
			__( 'Excerpt sentence count is outside the guideline range.', 'signal-and-noise-tools' ),
			$sentences,
			SNT_SN_VALIDATE_EXCERPT_SENTENCES_MIN . '-' . SNT_SN_VALIDATE_EXCERPT_SENTENCES_MAX,
			array(), $identity
		);
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * meta_description — char_range, corpus_collision. Inverts ai-generate-meta-description.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $value
 * @param int    $post_id
 * @return array
 */
function snt_sn_validate_check_meta_description( $value, $post_id ) {
	$findings = array();
	$identity = $post_id . '|' . $value;
	$len      = mb_strlen( (string) $value );
	// Hard cap REUSED, never re-declared — SNT_SURFACES_FIELD_CAPS is the
	// same constant update-post-surfaces enforces at write time.
	$cap = defined( 'SNT_SURFACES_FIELD_CAPS' ) ? SNT_SURFACES_FIELD_CAPS['meta_description'] : 300;

	if ( 0 === $len || $len > $cap ) {
		$findings[] = snt_sn_validate_finding(
			'meta_description', 'char_range', 'error',
			__( 'Meta description is empty or exceeds the hard character cap.', 'signal-and-noise-tools' ),
			$len, '1-' . $cap, array(), $identity
		);
	} elseif ( $len < SNT_SN_VALIDATE_META_DESC_SOFT_MIN || $len > SNT_SN_VALIDATE_META_DESC_SOFT_MAX ) {
		$findings[] = snt_sn_validate_finding(
			'meta_description', 'char_range', 'warning',
			__( 'Meta description length is outside the guideline range.', 'signal-and-noise-tools' ),
			$len,
			SNT_SN_VALIDATE_META_DESC_SOFT_MIN . '-' . SNT_SN_VALIDATE_META_DESC_SOFT_MAX,
			array(), $identity
		);
	}

	if ( '' !== trim( (string) $value ) ) {
		global $wpdb;
		$colliding = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sn_meta_description' AND meta_value = %s AND post_id != %d",
			$value, $post_id
		) );
		$colliding = array_map( 'intval', (array) $colliding );
		if ( ! empty( $colliding ) ) {
			$findings[] = snt_sn_validate_finding(
				'meta_description', 'corpus_collision', 'error',
				sprintf(
					/* translators: %d: number of other posts sharing this exact meta description. */
					__( 'Identical meta description on %d other post(s).', 'signal-and-noise-tools' ),
					count( $colliding )
				),
				count( $colliding ), 0,
				array( 'colliding_post_ids' => $colliding ), $identity
			);
		}
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * og_card_title — char_range, title_divergence. Inverts ai-generate-og-card-title.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $value
 * @param int    $post_id
 * @param string $post_title
 * @return array
 */
function snt_sn_validate_check_og_card_title( $value, $post_id, $post_title ) {
	$findings = array();
	$identity = $post_id . '|' . $value;
	$len      = mb_strlen( (string) $value );
	$cap      = defined( 'SNT_SURFACES_FIELD_CAPS' ) ? SNT_SURFACES_FIELD_CAPS['og_card_title'] : 150;

	if ( 0 === $len || $len > $cap ) {
		$findings[] = snt_sn_validate_finding(
			'og_card_title', 'char_range', 'error',
			__( 'OG card title is empty or exceeds the hard character cap.', 'signal-and-noise-tools' ),
			$len, '1-' . $cap, array(), $identity
		);
	} elseif ( $len < SNT_SN_VALIDATE_OG_TITLE_SOFT_MIN || $len > SNT_SN_VALIDATE_OG_TITLE_SOFT_MAX ) {
		$findings[] = snt_sn_validate_finding(
			'og_card_title', 'char_range', 'warning',
			__( 'OG card title length is outside the guideline range.', 'signal-and-noise-tools' ),
			$len,
			SNT_SN_VALIDATE_OG_TITLE_SOFT_MIN . '-' . SNT_SN_VALIDATE_OG_TITLE_SOFT_MAX,
			array(), $identity
		);
	}

	if ( trim( strtolower( (string) $value ) ) === trim( strtolower( (string) $post_title ) ) && '' !== trim( (string) $value ) ) {
		$findings[] = snt_sn_validate_finding(
			'og_card_title', 'title_divergence', 'warning',
			__( 'OG card title is identical to the post title — it should restate the claim in different words.', 'signal-and-noise-tools' ),
			$value, $post_title, array(), $identity
		);
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * note_summary — single_sentence. Inverts ai-generate-page-note-summary.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param string $value
 * @param int    $post_id
 * @return array
 */
function snt_sn_validate_check_note_summary( $value, $post_id ) {
	$findings  = array();
	$identity  = $post_id . '|' . $value;
	$sentences = snt_sn_validate_sentence_count( $value );

	if ( 1 !== $sentences ) {
		$findings[] = snt_sn_validate_finding(
			'note_summary', 'single_sentence', 'error',
			__( 'Note summary must be exactly one sentence.', 'signal-and-noise-tools' ),
			$sentences, 1, array(), $identity
		);
	}

	$words = function_exists( 'snt_word_count' ) ? snt_word_count( $value ) : str_word_count( (string) $value );
	if ( $words > SNT_SN_VALIDATE_NOTE_SUMMARY_MAX_WORDS ) {
		$findings[] = snt_sn_validate_finding(
			'note_summary', 'word_count', 'error',
			__( 'Note summary exceeds the theme ability\'s hard word cap.', 'signal-and-noise-tools' ),
			$words, '<=' . SNT_SN_VALIDATE_NOTE_SUMMARY_MAX_WORDS, array(), $identity
		);
	} elseif ( $words > SNT_SN_VALIDATE_NOTE_SUMMARY_SOFT_WORDS ) {
		$findings[] = snt_sn_validate_finding(
			'note_summary', 'word_count', 'warning',
			__( 'Note summary is longer than the default guideline.', 'signal-and-noise-tools' ),
			$words, '<=' . SNT_SN_VALIDATE_NOTE_SUMMARY_SOFT_WORDS, array(), $identity
		);
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * tags — tag_vocabulary. Inverts suggest-tags (existing-vocabulary-only).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * @param array $tags     Proposed OR published tag names.
 * @param int   $post_id
 * @return array
 */
function snt_sn_validate_check_tags( array $tags, $post_id ) {
	$findings = array();
	$terms    = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
	$terms    = is_array( $terms ) ? $terms : array();

	$vocab = array();
	foreach ( $terms as $t ) {
		$key = function_exists( 'sn_tag_normalize_key' ) ? sn_tag_normalize_key( $t->name ) : strtolower( trim( (string) $t->name ) );
		$vocab[ $key ] = true;
	}

	foreach ( $tags as $tag ) {
		$tag = (string) $tag;
		$key = function_exists( 'sn_tag_normalize_key' ) ? sn_tag_normalize_key( $tag ) : strtolower( trim( $tag ) );
		if ( ! isset( $vocab[ $key ] ) ) {
			$findings[] = snt_sn_validate_finding(
				'tags', 'tag_vocabulary', 'error',
				sprintf(
					/* translators: %s: the proposed tag name not found in the existing vocabulary. */
					__( 'Tag "%s" is not in the existing post_tag vocabulary.', 'signal-and-noise-tools' ),
					$tag
				),
				$tag, null, array(), $post_id . '|' . $tag
			);
		}
	}

	return $findings;
}

/* ════════════════════════════════════════════════════════════════════════
 * alt_text, links, body, and brand_voice checks continue in
 * inc/sn-validate-checks-media.php (same file family, split for the
 * <=450-line house convention — see that file's header).
 * ════════════════════════════════════════════════════════════════════════ */
