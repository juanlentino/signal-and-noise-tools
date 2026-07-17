<?php
/**
 * Signal & Noise Tools — the desktop attention badge.
 *
 * desktop-mode's setBadge() is Stable since 0.6.0 and we have never used it,
 * while the plugin produces real queues nobody sees until they open a tab. This
 * module closes that: one integer, on the icon we already register.
 *
 * THE RULE: IT READS, IT NEVER COMPUTES. Every source below is already cached
 * (a 24h health scan, two 1h transients). The badge must never trigger a scan —
 * that would run a full post sweep on every shell load. It costs zero tokens,
 * zero queries, and zero network calls: one int on a localize we already ship.
 *
 * null IS NOT 0. All three accessors return array|null, null meaning NEVER
 * SCANNED. An unmeasured queue is EXCLUDED from the total, not zero-filled — a
 * queue we have not looked at is not an empty queue. A MALFORMED envelope is
 * unmeasured too: we do not know its count, and a module that must never invent
 * a number cannot answer 0 to a question it did not measure.
 *
 * @package signal-and-noise-tools
 * @since   9.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Count the candidates in a detect-module envelope, or null if we cannot know.
 *
 * Both detect modules return an ENVELOPE:
 * array( 'candidates' => [...], 'counts' => [...], 'scanned_at' => int ).
 *
 * THREE cases, and the third is the reason this helper exists:
 *   - not an array           → null. Never scanned.
 *   - candidates is an array → count(). The real answer, 0 included.
 *   - anything else          → null. MALFORMED: we do not know the count.
 *
 * The malformed branch must NOT fall back to 0. `0` asserts "scanned, found
 * nothing" — a measurement we did not make. null says "we do not know", which
 * is the honest answer and the only one this module is allowed to give: its
 * entire purpose is to never invent a number.
 *
 * It must ALSO not `(array)` cast the value, which is what the first draft did.
 * `(array) $scalar` WRAPS rather than empties — `(array) false === array( false )`
 * — so a malformed envelope counted as 1 and badged one nonexistent thing
 * needing attention. Verified: false / 0 / "" each fabricated a count of 1.
 *
 * The isset() + is_array() shape matches the house guard for this exact key at
 * inc/admin-bar.php:231 and inc/abilities-pattern-adoption.php:76, which differ
 * only in falling back to 0 — correct there (they report a fresh scan's result,
 * so there is no "never measured" state to confuse it with).
 *
 * Shared by both detect sources deliberately: the logic was duplicated, and
 * duplicated logic drifts.
 *
 * @since 9.58.0
 * @param mixed $envelope A *_last_scan() return value.
 * @return int|null Candidate count, or null when unscanned or malformed.
 */
function snt_desktop_attention_candidate_count( $envelope ) {
	if ( ! is_array( $envelope ) || ! isset( $envelope['candidates'] ) || ! is_array( $envelope['candidates'] ) ) {
		return null;
	}
	return count( $envelope['candidates'] );
}

/**
 * The attention sources, each with a cached count or null.
 *
 * `count` is null when that source has never been scanned OR its envelope is
 * malformed — deliberately distinct from 0 ("scanned, nothing found"). Callers
 * MUST NOT coerce.
 *
 * @since 9.58.0
 * @return array<string,array{count:int|null,label:string,url:string}>
 */
function snt_desktop_attention_sources() {
	$out = array();

	// Health — cached 24h, manual re-scan only. sn_health_flagged_checks() is
	// pure array logic from inc/health-summary.php.
	$health = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	$out['health'] = array(
		'count' => is_array( $health ) && function_exists( 'sn_health_flagged_checks' )
			? count( (array) sn_health_flagged_checks( $health ) )
			: null,
		'label' => __( 'Health findings', 'signal-and-noise-tools' ),
		'url'   => function_exists( 'snt_desktop_admin_url' ) ? snt_desktop_admin_url( 'sn-monitoring' ) : '',
	);

	// Block migrations — cached 1h transient; array|null.
	$bm = function_exists( 'snt_block_migrations_last_scan' ) ? snt_block_migrations_last_scan() : null;
	$out['block_migrations'] = array(
		'count' => snt_desktop_attention_candidate_count( $bm ),
		'label' => __( 'Block migrations', 'signal-and-noise-tools' ),
		'url'   => function_exists( 'snt_desktop_admin_url' ) ? snt_desktop_admin_url( 'sn-tools' ) : '',
	);

	// Pattern adoption — cached 1h transient; array|null.
	$pa = function_exists( 'snt_pattern_adoption_last_scan' ) ? snt_pattern_adoption_last_scan() : null;
	$out['pattern_adoption'] = array(
		'count' => snt_desktop_attention_candidate_count( $pa ),
		'label' => __( 'Pattern adoption', 'signal-and-noise-tools' ),
		'url'   => function_exists( 'snt_desktop_admin_url' ) ? snt_desktop_admin_url( 'sn-tools' ) : '',
	);

	return $out;
}

/**
 * Total things needing attention.
 *
 * Sums only the sources that have actually been measured. A null source is
 * SKIPPED, never zero-filled: we do not know its count, and claiming 0 would be
 * a number we did not measure.
 *
 * @since 9.58.0
 * @return int
 */
function snt_desktop_attention_total() {
	$total = 0;
	foreach ( snt_desktop_attention_sources() as $src ) {
		if ( null !== $src['count'] ) {
			$total += (int) $src['count'];
		}
	}
	return $total;
}
