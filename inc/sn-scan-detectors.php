<?php
/**
 * Signal & Noise Tools — sn_scan detector registry.
 *
 * ── Why this exists (v13.6.0) ──
 *
 * sn_scan reported WHAT it found and never WHAT IT LOOKED FOR. An empty
 * `candidates` array was therefore ambiguous in the one direction that
 * matters: it could mean "the corpus conforms" or "nothing was registered
 * to detect against", and a caller had no way to tell those apart.
 *
 * The ambiguity was live. scan_type "pattern_adoption" returns zero
 * candidates across the whole corpus, and the reason is not that nothing
 * is registered — TWO detectors are (see below). It is that both key on
 * block types the corpus does not contain: a census of 55 posts on
 * 2026-08-26 found core/paragraph, core/heading and core/html and nothing
 * else. Zero core/quote, zero core/list. The detectors are correct and
 * simply have no material.
 *
 * Naming the rules in the envelope turns that zero into a finding:
 *   "2 detectors ran over 57 posts, 0 candidates" says the corpus holds
 *   no quote or list blocks, which is true and useful. "0 candidates"
 *   alone says nothing.
 *
 * ── Contract ──
 *
 * Every scan_type returns a non-empty list. Each entry is
 * {id, triggers_on}: `id` is the identifier the detector's own code uses
 * (pattern_type, migration_type, rule, …) so a caller can correlate an
 * entry with the candidates it produced; `triggers_on` states the literal
 * condition, derived from the detector source, never a paraphrase of
 * intent.
 *
 * This registry DESCRIBES the detectors; it never drives them. Adding an
 * entry here does not make a rule run, and tests/abilities-sn-scan.php
 * pins the key set against SNT_SN_SCAN_TYPES in both directions so a new
 * scan_type cannot ship without its rules being named.
 *
 * @package SignalNoiseTools
 * @since 13.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The detector rules behind each scan_type.
 *
 * Sources, so a future edit can re-derive rather than trust this file:
 *   block_migrations   inc/block-migrations-apply.php (migration_type enum)
 *   pattern_adoption   snt_pattern_adoption_match_block_type()
 *   duplicate_body     snt_corpus_duplicate_scan() (snt_corpus_content_hash)
 *   emdash             SNT_EMDASH_PATTERN
 *   anchor_violations  inc/sn-scan-anchor-violations.php (rule keys)
 *
 * @return array<string,array<int,array{id:string,triggers_on:string}>>
 */
function snt_sn_scan_detector_registry() {
	// v13.57.0: the search_disagreement rows print thresholds that live beside
	// their detectors. Resolve them from their homes (never restate a literal),
	// and load those homes when a harness has not — both files are pure at load.
	if ( ! defined( 'SNT_GSC_DRIFT_MIN_IMPRESSIONS' ) ) {
		require_once __DIR__ . '/search-console-derive.php';
	}
	if ( ! defined( 'SNT_SEARCH_THIN_WORDS' ) ) {
		require_once __DIR__ . '/sn-scan-search-disagreement.php';
	}
	$registry = array(
		'block_migrations'  => array(
			array(
				'id'          => 'heading-hierarchy-skip',
				'triggers_on' => 'a first-level body subhead below h2 (the single migration_type the apply path accepts)',
			),
		),
		'pattern_adoption'  => array(
			array(
				'id'          => 'pull-quote',
				'triggers_on' => 'a core/quote block',
			),
			array(
				'id'          => 'steps-enumerated',
				'triggers_on' => 'a core/list block with attrs.ordered set',
			),
		),
		'duplicate_body'    => array(
			array(
				'id'          => 'identical-body',
				'triggers_on' => 'two or more posts sharing one normalized post_content hash',
			),
		),
		'near_duplicate'    => array(
			array(
				'id'          => 'cosine-pair',
				'triggers_on' => 'a post pair whose TF-IDF cosine similarity clears the ML kernel threshold',
			),
		),
		'link_candidates'   => array(
			array(
				'id'          => 'link-candidate',
				'triggers_on' => 'an entry in the prebuilt link artifact (read-only; this scan never recomputes it)',
			),
		),
		'orphan_media'      => array(
			array(
				'id'          => 'orphaned-media',
				'triggers_on' => 'an attachment the SQL detector finds unreferenced by any post',
			),
		),
		'emdash'            => array(
			array(
				'id'          => 'emdash',
				'triggers_on' => 'U+2014 or its HTML entity forms in post_content',
			),
		),
		'tag_hygiene'       => array(
			array(
				'id'          => 'undescribed_tag',
				'triggers_on' => 'an in-use post_tag term whose description is empty (both consuming surfaces fall back)',
			),
			array(
				'id'          => 'unused_tag',
				'triggers_on' => 'a zero-post post_tag term (usually typo-minted; reports ONCE even when also undescribed)',
			),
		),
		'anchor_violations' => array(
			array(
				'id'          => 'anchor_equals_sentence',
				'triggers_on' => "an <a>'s anchor text equal to its whole containing sentence, terminal punctuation ignored",
			),
			array(
				'id'          => 'heading_contains_link',
				'triggers_on' => 'any <a> inside an h1-h6 block',
			),
		),
		// v13.57.0: three readings of one disagreement, three different problems.
		'search_disagreement' => array(
			array(
				'id'          => 'no_impressions',
				'triggers_on' => 'a published post of ' . SNT_SEARCH_THIN_WORDS . '+ words with ZERO Google impressions in the synced window ("about X, found for nothing")',
			),
			array(
				'id'          => 'thin_but_found',
				'triggers_on' => 'a post under ' . SNT_SEARCH_THIN_WORDS . ' words earning ' . SNT_GSC_DRIFT_MIN_IMPRESSIONS . '+ impressions ("found for X, about nothing" — the best refresh candidate)',
			),
			array(
				'id'          => 'query_unclaimed',
				'triggers_on' => 'a SITE-LEVEL query with ' . SNT_GSC_DRIFT_MIN_IMPRESSIONS . '+ impressions none of whose words (4+ chars) appear in any post\'s TF-IDF keyword candidates; page-level "about X, found for Y" is NOT derivable — the sync stores page and query dimensions separately',
			),
		),
	);

	return apply_filters( 'sn_scan_detector_registry', $registry );
}

/**
 * Detectors for one scan_type. Never null: an unknown type returns an
 * empty array rather than a silent absence, and the dispatcher rejects
 * unknown types before this is ever reached.
 *
 * @param string $scan_type
 * @return array<int,array{id:string,triggers_on:string}>
 */
function snt_sn_scan_detectors_for( $scan_type ) {
	$registry = snt_sn_scan_detector_registry();
	$entries  = $registry[ (string) $scan_type ] ?? array();
	return is_array( $entries ) ? array_values( $entries ) : array();
}
