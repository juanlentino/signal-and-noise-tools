<?php
/**
 * Health check: a second page cache in front of the origin (Breeze, or any
 * advanced-cache.php drop-in).
 *
 * The Cloudflare edge is the only page cache (owner, 2026-10-13). Breeze is
 * deactivated and its advanced-cache.php drop-in is gone. Cloudways can switch
 * Breeze back on, and a Breeze page cache can serve pages without the
 * Cache-Tag header, so the tag purges (sn-render, sn-note-<ID>, sn-lists) may
 * not clear them. This goes red when Breeze is active, or when WP_CACHE is on
 * with a wp-content/advanced-cache.php drop-in present (any page cache
 * WordPress loads before the theme). No network: one option read, one file
 * stat and, when the drop-in exists, a read of its first 2 KB to name its owner.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: which plugin wrote a drop-in, from the start of the file ('' when
 * the header names none we know).
 *
 * @param string $head The file's first bytes.
 * @return string
 */
function sn_health_dropin_owner( $head ) {
	$known = array( 'Breeze', 'WP Rocket', 'W3 Total Cache', 'WP Super Cache', 'LiteSpeed', 'WP Fastest Cache', 'Cache Enabler', 'SiteGround', 'Hummingbird', 'Comet Cache', 'Object Cache Pro' );
	foreach ( $known as $name ) {
		if ( false !== stripos( (string) $head, $name ) ) {
			return $name;
		}
	}
	return '';
}

/**
 * PURE: the findings for one reading.
 *
 * @param mixed  $active_plugins The active_plugins option.
 * @param bool   $wp_cache       Whether WP_CACHE is true.
 * @param bool   $dropin         Whether wp-content/advanced-cache.php exists.
 * @param string $owner          The drop-in's owner (sn_health_dropin_owner()), '' when unknown.
 * @return array<int,array<string,mixed>>
 */
function sn_health_breeze_findings( $active_plugins, $wp_cache, $dropin, $owner = '' ) {
	$findings = array();
	if ( is_array( $active_plugins ) && in_array( 'breeze/breeze.php', $active_plugins, true ) ) {
		$findings[] = array(
			'subject_type'  => 'plugin',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'breeze/breeze.php',
			'edit_url'      => '',
			'note'          => 'Breeze is active. Its page cache can serve pages without Cache-Tag, which the tag purges cannot clear.',
		);
	}
	if ( $wp_cache && $dropin ) {
		$findings[] = array(
			'subject_type'  => 'dropin',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'advanced-cache.php',
			'edit_url'      => '',
			'note'          => 'WP_CACHE is true and wp-content/advanced-cache.php is present (' . ( '' !== $owner ? 'its header names ' . $owner : 'its header names no known plugin' ) . '), so WordPress loads a page cache before the theme.',
		);
	}
	return $findings;
}

/**
 * PURE: the fix hint for those findings: Breeze when Breeze is the cause,
 * otherwise the drop-in and, when known, its owner.
 *
 * @param array<int,array<string,mixed>> $findings Findings.
 * @param string                         $owner    The drop-in's owner, '' when unknown.
 * @return string
 */
function sn_health_breeze_fix_hint( array $findings, $owner = '' ) {
	$edge = ' The Cloudflare edge is the only page cache, and only its tag purges clear pages.';
	$breeze = 'breeze/breeze.php' === ( $findings[0]['subject_label'] ?? '' ) || 'Breeze' === $owner;
	if ( $breeze || array() === $findings ) {
		return 'Deactivate Breeze in Plugins (Cloudways can switch it back on). Deactivating it removes its advanced-cache.php drop-in.' . $edge;
	}
	return 'Turn off the page cache in ' . ( '' !== $owner ? $owner . ', which wrote' : 'the plugin that wrote' ) . ' wp-content/advanced-cache.php, or set WP_CACHE to false in wp-config.php and delete the drop-in.' . $edge;
}

/**
 * Report a page cache other than the Cloudflare edge.
 *
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_breeze_active() {
	$path   = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/advanced-cache.php' : '';
	$dropin = '' !== $path && file_exists( $path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local drop-in header, 2 KB.
	$owner    = $dropin ? sn_health_dropin_owner( (string) file_get_contents( $path, false, null, 0, 2048 ) ) : '';
	$findings = sn_health_breeze_findings( get_option( 'active_plugins', array() ), defined( 'WP_CACHE' ) && WP_CACHE, $dropin, $owner );
	return sn_health_pack_check( 'Second page cache (Breeze)', $findings, sn_health_breeze_fix_hint( $findings, $owner ) );
}
