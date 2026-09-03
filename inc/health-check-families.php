<?php
/**
 * Signal & Noise Tools -- Health check families (grouping data, pure).
 *
 * The Health tab's passing checks collapse into ONE disclosure, and 17 name
 * chips in a flat row is a wall, not a readout. Families give the collapsed
 * list a spine: a reader scanning for "is the rights surface clean" finds a
 * Provenance & rights group instead of hunting four chips out of seventeen.
 *
 * TOTALITY IS THE CONTRACT. A check key with no entry in the map does not
 * disappear -- it lands in the `other` family, which always sorts last. That
 * fallback is a safety net for the RUNTIME, never a licence to skip the map:
 * tests/health-check-families.php reads the check keys straight out of
 * sn_health_run_scan()'s source and fails if any of them resolves to `other`.
 * (This repo has been bitten by a hand-maintained list that under-covered
 * silently; a fallback plus a source-derived exhaustiveness test is the
 * shape that catches it loudly.)
 *
 * Pure data + pure projections. No rendering, no WordPress calls -- the
 * render layer lives in inc/health-render-passing.php.
 *
 * @package SignalNoiseTools
 * @since 10.83.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The families, in render order. `other` is the fallback bucket and always
 * comes last so an unmapped check reads as an afterthought, not as a peer of
 * the curated groups.
 *
 * @return array<string,string> family key => human label.
 */
function sn_health_check_families() {
	return array(
		'content'    => 'Content',
		'links'      => 'Links',
		'a11y'       => 'Accessibility',
		'ml'         => 'Machine learning',
		'provenance' => 'Provenance & rights',
		'analytics'  => 'Analytics',
		'edge'       => 'Edge & security',
		'other'      => 'Other checks',
	);
}

/**
 * check key => family key, for every check sn_health_run_scan() runs.
 *
 * Keep this in step with the scan registry in inc/health-checks.php. The
 * exhaustiveness test reads that file's source, so adding a check without
 * adding it here fails the suite rather than quietly landing in `other`.
 *
 * @return array<string,string>
 */
function sn_health_check_family_map() {
	return array(
		// Content: what the posts and media themselves say.
		'orphaned_media'       => 'content',
		'stale_posts'          => 'content',
		'stale_posts_evergreen' => 'content',
		'drift_time_phrases'   => 'content',
		'color_drift'          => 'content',
		'roadmap_drift'        => 'content',
		'tag_hygiene'          => 'content',

		// Links: the graph, internal and out.
		'broken_links'         => 'links',
		'external_links'       => 'links',
		'unlinked_mentions'    => 'links',
		'link_opportunities'   => 'links',

		// Accessibility: what a reader who needs the affordance gets.
		'missing_alt'          => 'a11y',
		'contrast_tokens'      => 'a11y',
		'motion_scan'          => 'a11y',
		// v13.89.0: the GSC snapshot producer stalling is a MEASUREMENT failure —
		// it sits with analytics, not content: nothing on the site is wrong, the
		// instrument reading it stopped being fed.
		'gsc_history_stalled'  => 'analytics',

		// Machine learning: the kernel's own watches.
		'ml_cousins'           => 'ml',
		'ml_cadence'           => 'ml',

		// Provenance & rights: the claim surface and its evidence.
		'provenance_integrity' => 'provenance',
		'rights_signals'       => 'provenance',
		'rights_anchored'      => 'provenance',
		'ledger_ci'            => 'provenance',

		// Analytics: the measurement layer's own integrity.
		'analytics_integrity'  => 'analytics',

		// Edge & security: what Cloudflare and the Workers are doing.
		'cf_security_headers'  => 'edge',
		'edge_workers'         => 'edge',
	);
}

/**
 * The family a check key belongs to, `other` when unmapped.
 *
 * @param string $key Check key.
 * @return string Family key, always one of sn_health_check_families()'s keys.
 */
function sn_health_check_family( $key ) {
	$map = sn_health_check_family_map();
	return isset( $map[ (string) $key ] ) ? $map[ (string) $key ] : 'other';
}

/**
 * Group a key => check map into families, in sn_health_check_families() order.
 * Empty families are omitted, so the caller renders only groups that exist.
 *
 * @param array<string,array> $checks key => check envelope.
 * @return array<string,array{label:string,checks:array<string,array>}>
 *         family key => label + its checks (input order preserved within).
 */
function sn_health_group_checks_by_family( $checks ) {
	$families = sn_health_check_families();
	$grouped  = array();
	foreach ( (array) $checks as $key => $check ) {
		$family = sn_health_check_family( $key );
		if ( ! isset( $grouped[ $family ] ) ) {
			$grouped[ $family ] = array(
				'label'  => isset( $families[ $family ] ) ? $families[ $family ] : $families['other'],
				'checks' => array(),
			);
		}
		$grouped[ $family ]['checks'][ $key ] = $check;
	}

	// Reorder to the canonical family order (the input map's order is scan
	// order, which is chronological-by-ship-date and means nothing to a reader).
	$ordered = array();
	foreach ( array_keys( $families ) as $family ) {
		if ( isset( $grouped[ $family ] ) ) {
			$ordered[ $family ] = $grouped[ $family ];
		}
	}
	return $ordered;
}
