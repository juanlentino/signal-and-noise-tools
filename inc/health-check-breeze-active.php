<?php
/**
 * Health check: a second page cache in front of the origin (Breeze).
 *
 * The Cloudflare edge is the only page cache (owner, 2026-10-13). Breeze is
 * deactivated and its advanced-cache.php drop-in is gone. Cloudways can switch
 * Breeze back on, and a Breeze page cache serves pages without the Cache-Tag
 * header, so the tag purges (sn-render, sn-note-<ID>, sn-lists) cannot clear
 * them. This goes red when Breeze is active, or when WP_CACHE is on with a
 * wp-content/advanced-cache.php drop-in present (any page cache WordPress
 * loads before the theme). No network: one option read and one file stat.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: the findings for one reading.
 *
 * @param mixed $active_plugins The active_plugins option.
 * @param bool  $wp_cache       Whether WP_CACHE is true.
 * @param bool  $dropin         Whether wp-content/advanced-cache.php exists.
 * @return array<int,array<string,mixed>>
 */
function sn_health_breeze_findings( $active_plugins, $wp_cache, $dropin ) {
	$findings = array();
	if ( is_array( $active_plugins ) && in_array( 'breeze/breeze.php', $active_plugins, true ) ) {
		$findings[] = array(
			'subject_type'  => 'plugin',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'breeze/breeze.php',
			'edit_url'      => '',
			'note'          => 'Breeze is active. Its page cache serves pages without Cache-Tag, so the tag purges cannot clear them.',
		);
	}
	if ( $wp_cache && $dropin ) {
		$findings[] = array(
			'subject_type'  => 'dropin',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'advanced-cache.php',
			'edit_url'      => '',
			'note'          => 'WP_CACHE is true and wp-content/advanced-cache.php is present, so WordPress loads a page cache before the theme.',
		);
	}
	return $findings;
}

/**
 * Report a page cache other than the Cloudflare edge.
 *
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_breeze_active() {
	$fix_hint = 'Deactivate Breeze in Plugins (Cloudways can switch it back on). The Cloudflare edge is the only page cache, and only its tag purges clear pages. Deactivating Breeze removes its advanced-cache.php drop-in.';
	$dropin   = defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	return sn_health_pack_check(
		'Second page cache (Breeze)',
		sn_health_breeze_findings( get_option( 'active_plugins', array() ), defined( 'WP_CACHE' ) && WP_CACHE, $dropin ),
		$fix_hint
	);
}
