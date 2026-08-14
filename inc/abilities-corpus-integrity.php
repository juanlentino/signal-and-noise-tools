<?php
/**
 * Signal & Noise Tools — corpus-integrity scan ability.
 *
 * One readonly ability over inc/corpus-integrity-scan.php. Mirrors the
 * block-migrations-scan registration (its structural sibling) — scan only,
 * deliberately: every finding is a judgement call the owner reviews, and
 * the correction path for a published Note is a signed supersede, so there
 * is no auto-apply surface here by design.
 *
 * @package SignalNoiseTools
 * @since 11.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/corpus-integrity-scan', array(
		'label'               => 'Scan the corpus for content-integrity defects',
		'description'         => 'Three independent deterministic checks over post bodies (publish, future, draft AND pending — corruption is cheapest to catch before publish makes a Note canonical), zero AI, zero writes beyond the per-user 1-hour result cache. (a) intra_post_duplication: near-duplicate paragraph/heading pairs within one post — similarity ratio > 0.80, both sides >= 40 chars; reports both block paths, both texts and the ratio. (b) splice_artifact: a lowercase word fused to a period with no space (/[a-z]{2}\\.[a-z]{3,}/) in body prose — the signature of a mid-sentence paste splice (found live on a published Note: "hostile to it.mance rights organization"); domains, URLs, file names, tokens with digits, inline <code> spans and wp:html/wp:code blocks are excluded; reports the surrounding sentence. (c) date_coherence: an in-body date LATER than post_date — severity "warning" when the sentence carries a past-tense event verb (the backdating signature: a May-dated post narrating a June announcement as history), "info" otherwise (future regulation/effective dates are normal prose). The spec\'d same-event-two-dates check is deliberately NOT shipped: it needs entity resolution, and every cheap proxy false-positives on legitimate timeline narration. EVERY finding is severity warning or info, NEVER a blocking error — these are reports for owner review, and NOTHING here writes to any post. Candidates carry post+path-bound block fingerprints (the v11.4.0 scheme shared with block-migrations/pattern-adoption) so a future apply consumer can address the exact block. Dismissals: per-post meta rows "<check>:<fingerprint>", same mechanism as the sibling scans.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_corpus_integrity_scan',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'candidates' => array( 'type' => 'array' ),
				'counts'     => array(
					'type'       => 'object',
					'properties' => array(
						'intra_post_duplication' => array( 'type' => 'integer' ),
						'splice_artifact'        => array( 'type' => 'integer' ),
						'date_coherence'         => array( 'type' => 'integer' ),
						'posts_affected'         => array( 'type' => 'integer' ),
					),
				),
				'scanned_at' => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Ability wrapper: delegates to snt_corpus_integrity_run_scan().
 *
 * @param mixed $input Validated against input_schema above (empty object).
 * @return array|WP_Error
 */
function snt_ability_corpus_integrity_scan( $input ) {
	if ( ! function_exists( 'snt_corpus_integrity_run_scan' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Corpus-integrity scan helper not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return snt_corpus_integrity_run_scan();
}
