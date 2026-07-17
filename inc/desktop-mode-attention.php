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
 * queue we have not looked at is not an empty queue.
 *
 * @package signal-and-noise-tools
 * @since   9.58.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The attention sources, each with a cached count or null.
 *
 * `count` is null when that source has never been scanned — deliberately
 * distinct from 0 ("scanned, nothing found"). Callers MUST NOT coerce.
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
		'count' => is_array( $bm ) ? count( (array) ( $bm['candidates'] ?? array() ) ) : null,
		'label' => __( 'Block migrations', 'signal-and-noise-tools' ),
		'url'   => function_exists( 'snt_desktop_admin_url' ) ? snt_desktop_admin_url( 'sn-tools' ) : '',
	);

	// Pattern adoption — cached 1h transient; array|null.
	$pa = function_exists( 'snt_pattern_adoption_last_scan' ) ? snt_pattern_adoption_last_scan() : null;
	$out['pattern_adoption'] = array(
		'count' => is_array( $pa ) ? count( (array) ( $pa['candidates'] ?? array() ) ) : null,
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
