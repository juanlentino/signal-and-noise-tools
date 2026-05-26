<?php
/**
 * Cross-package filter-contract SMOKE tests — plugin v4.4.0.
 *
 * Run via: `wp eval-file tests/contracts-smoke.php`
 *  (or direct: `php tests/contracts-smoke.php` from a dev SN install
 *   that has wp-load.php discoverable up 4 dirs from this file.)
 *
 * Assumes a real WP environment with signal-and-noise theme +
 * signal-and-noise-tools plugin both active. Invokes each of the 4
 * theme↔plugin filter contracts with realistic input and asserts the
 * return shape + value sanity.
 *
 * Not part of the standard `php tests/*.php` sweep — manual run only.
 *
 * @since plugin v4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
	} else {
		die( "ABSPATH not set and wp-load.php not findable 4 dirs up. Run via `wp eval-file`.\n" );
	}
}

echo "Cross-package contract SMOKE tests — plugin v4.4.0\n";
echo "Site: " . home_url() . "\n";
echo "Theme: " . wp_get_theme()->get( 'Name' ) . " v" . wp_get_theme()->get( 'Version' ) . "\n\n";

$pass = 0; $fail = 0;
function smoke_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else         { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── Contract 1: sn_purge_all_caches_result ──────────────────────────
echo "Contract 1: sn_purge_all_caches_result\n";
$count = apply_filters( 'sn_purge_all_caches_result', 0, array(
	'template_overrides' => false,
	'cloudflare'         => false,
	'reason'             => 'smoke-test',
) );
smoke_assert( is_int( $count ),  '1.1: returns int' );
smoke_assert( $count >= 0,       '1.2: count is non-negative' );

// ─── Contract 2: sn_clear_template_overrides_result ──────────────────
echo "\nContract 2: sn_clear_template_overrides_result\n";
$count = apply_filters( 'sn_clear_template_overrides_result', 0 );
smoke_assert( is_int( $count ),  '2.1: returns int' );
smoke_assert( $count >= 0,       '2.2: count is non-negative' );

// ─── Contract 3: sn_og_font_paths ────────────────────────────────────
echo "\nContract 3: sn_og_font_paths\n";
$paths = apply_filters( 'sn_og_font_paths', array() );
smoke_assert( is_array( $paths ),               '3.1: returns array' );
smoke_assert( isset( $paths['bebas'] ),         '3.2: bebas key present' );
smoke_assert( isset( $paths['dmmono'] ),        '3.3: dmmono key present' );
smoke_assert( is_string( $paths['bebas'] ?? null ),  '3.4: bebas value is string' );
smoke_assert( is_string( $paths['dmmono'] ?? null ), '3.5: dmmono value is string' );
smoke_assert( file_exists( $paths['bebas'] ?? '' ),  '3.6: bebas file exists at returned path' );
smoke_assert( file_exists( $paths['dmmono'] ?? '' ), '3.7: dmmono file exists at returned path' );

// ─── Contract 4: sn_gh_latest_theme_tag_result ───────────────────────
echo "\nContract 4: sn_gh_latest_theme_tag_result\n";
$tag = apply_filters( 'sn_gh_latest_theme_tag_result', null );
smoke_assert( is_string( $tag ) || is_null( $tag ), '4.1: returns string or null' );
if ( is_string( $tag ) ) {
	smoke_assert( preg_match( '~^v\d+\.\d+\.\d+~', $tag ), '4.2: tag matches semver prefix vX.Y.Z' );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
