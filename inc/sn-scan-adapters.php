<?php
/**
 * Signal & Noise Tools — sn_scan per-scan_type adapters.
 *
 * Six adapters, each a thin reshape over an EXISTING internal function —
 * never a re-implementation of detection logic, never wp_get_ability().
 * Every adapter takes $allowed_ids (int[]|null; null = scope "all") and
 * returns either a WP_Error or:
 *
 *   array{
 *     candidates: list<array{
 *       target_identity:string, content_fingerprint:string, targets:array,
 *       confidence:float, evidence:array, apply_hint:array|null,
 *     }>,
 *     posts_examined: int,
 *     posts_skipped: int,
 *     truncated: bool,
 *   }
 *
 * inc/abilities-sn-scan.php turns each raw candidate into the final
 * envelope row (computing candidate_id, rounding confidence, forcing
 * dismissed:false — see that file for why).
 *
 * @package SignalNoiseTools
 * @since 10.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// No native score for these four sources — documented constants, per the
// design contract ("where a scan has no native score, use a documented
// constant"). Values reflect relative certainty: an exact byte-match is
// definite (1.0); a WCAG structural violation is a clear rule match (0.9);
// a pure-SQL orphan finding is a strong but not certain signal (0.85,
// "conservative by design" per the detector's own docblock — false
// positives are possible for edge-case references); a pattern-adoption
// upgrade is a stylistic opportunity, not a defect (0.7).
const SNT_SN_SCAN_CONF_DUPLICATE_BODY     = 1.0;
const SNT_SN_SCAN_CONF_BLOCK_MIGRATIONS   = 0.9;
const SNT_SN_SCAN_CONF_ORPHAN_MEDIA       = 0.85;
// A tag either has an empty description / zero posts or it does not — the
// detector is a fact check, not an inference, so it sits with duplicate_body.
const SNT_SN_SCAN_CONF_TAG_HYGIENE        = 1.0;
// Prose classification by snt_emdash_scan_content() is a strong structural
// call, not a byte-exact match — same tier as orphan_media (v10.58.0, part
// of the emdash envelope fix in sn-scan-adapters-emdash-tags-parts.php).
const SNT_SN_SCAN_CONF_EMDASH             = 0.85;
const SNT_SN_SCAN_CONF_PATTERN_ADOPTION   = 0.7;

/**
 * Registry: scan_type => adapter callable. Mirrors inc/ml-pipelines.php's
 * snt_ml_pipelines() registry pattern for house-style consistency.
 *
 * @return array<string,callable>
 */
function snt_sn_scan_adapters() {
	$adapters = array(
		'block_migrations' => 'snt_sn_scan_adapter_block_migrations',
		'pattern_adoption' => 'snt_sn_scan_adapter_pattern_adoption',
		'duplicate_body'   => 'snt_sn_scan_adapter_duplicate_body',
		'near_duplicate'   => 'snt_sn_scan_adapter_near_duplicate',
		'link_candidates'  => 'snt_sn_scan_adapter_link_candidates',
		'emdash'           => 'snt_sn_scan_adapter_emdash',
		'orphan_media'     => 'snt_sn_scan_adapter_orphan_media',
		// v10.58.0 — detector + adapter live in inc/sn-scan-anchor-violations.php
		// (own file, the emdash-scanner precedent).
		'anchor_violations' => 'snt_sn_scan_adapter_anchor_violations',
		// v13.27.0: the vocabulary source — wraps the tag_hygiene health
		// check (the same real producer the advisory tier reads).
		'tag_hygiene'       => 'snt_sn_scan_adapter_tag_hygiene',
		// v13.57.0 — inc/sn-scan-search-disagreement.php (own file).
		'search_disagreement' => 'snt_sn_scan_adapter_search_disagreement',
		// v14.7.0 — house-convention drift over the corpus (own file, like the two above).
		'editorial_conventions' => 'snt_sn_scan_adapter_editorial_conventions',
	);
	return apply_filters( 'sn_scan_adapters', $adapters );
}

/**
 * Shared: whether $post_id passes the resolved scope filter.
 *
 * @param int        $post_id
 * @param array|null $allowed_ids
 * @return bool
 */
function snt_sn_scan_in_scope( $post_id, $allowed_ids ) {
	return null === $allowed_ids || in_array( (int) $post_id, $allowed_ids, true );
}

// The adapters live in three parts files (house line cap, 2026-10-15), loaded
// in the order they always ran.
require_once __DIR__ . '/sn-scan-adapters-structure-parts.php';
require_once __DIR__ . '/sn-scan-adapters-links-media-parts.php';
require_once __DIR__ . '/sn-scan-adapters-emdash-tags-parts.php';
