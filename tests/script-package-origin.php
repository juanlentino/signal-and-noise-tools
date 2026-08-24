<?php
/**
 * Standalone test: core script-package origin detection.
 *
 * Locks snt_script_package_overrides() and snt_script_package_override_summary()
 * — the detector that names which plugin is SERVING core's `wp-*` script
 * handles.
 *
 * Motivated by a measured incident (2026-08-23): the Gutenberg plugin
 * re-registers every `wp-*` handle, so `window.wp.components` came from
 * `plugins/gutenberg/build/…` rather than `wp-includes/`. That build had
 * dropped four `Validated*Control` private exports core still ships, the `ai`
 * plugin destructured one of them at module scope, and `Settings → AI` died
 * with React error #130. Nothing on the site could answer "who is serving
 * wp.components?" — the answer took a browser session to obtain. This makes it
 * a field in Site Health → Info.
 *
 * Drives the REAL producer. Only the WP script-registry seam is stubbed, per
 * the standing rule that a fixture-fed value survives a rename and a
 * producer-calling test does not.
 *
 * Standalone — no PHPUnit. Run: php tests/script-package-origin.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP seams ────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'content_url' ) ) { function content_url( $p = '' ) { return 'https://example.test/wp-content' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }

/** The stubbed script registry. Tests rewrite $GLOBALS['__registered'] per case. */
if ( ! function_exists( 'wp_scripts' ) ) {
	function wp_scripts() {
		$o             = new stdClass();
		$o->registered = array();
		foreach ( (array) $GLOBALS['__registered'] as $handle => $src ) {
			$dep          = new stdClass();
			$dep->src     = $src;
			$o->registered[ $handle ] = $dep;
		}
		return $o;
	}
}

require_once __DIR__ . '/../inc/script-package-origin.php';

function t_ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

// ─── 1. A clean site: every wp-* handle served by core ────────────────
$GLOBALS['__registered'] = array(
	'wp-components' => '/wp-includes/js/dist/components.min.js',
	'wp-element'    => '/wp-includes/js/dist/element.min.js',
	'jquery'        => '/wp-includes/js/jquery/jquery.min.js',
);
$overrides = snt_script_package_overrides();
t_ok( array() === $overrides, 'core-served wp-* handles produce NO overrides' );
$summary = snt_script_package_override_summary();
t_ok( false !== strpos( $summary, 'core' ), 'clean summary names core as the server' );
t_ok( false === strpos( $summary, 'plugin:' ), 'clean summary names no plugin' );

// ─── 2. The measured case: Gutenberg overriding core packages ─────────
$GLOBALS['__registered'] = array(
	'wp-components'   => 'https://example.test/wp-content/plugins/gutenberg/build/scripts/components/index.min.js?ver=13d6ffd',
	'wp-element'      => 'https://example.test/wp-content/plugins/gutenberg/build/scripts/element/index.min.js',
	'wp-block-editor' => 'https://example.test/wp-content/plugins/gutenberg/build/scripts/block-editor/index.min.js',
	'wp-a11y'         => '/wp-includes/js/dist/a11y.min.js',
	'jquery'          => '/wp-includes/js/jquery/jquery.min.js',
);
$overrides = snt_script_package_overrides();
t_ok( isset( $overrides['plugin:gutenberg'] ), 'the overriding plugin is named by folder' );
t_ok( 3 === count( $overrides['plugin:gutenberg'] ), 'every overridden wp-* handle is counted' );
t_ok( in_array( 'wp-components', $overrides['plugin:gutenberg'], true ), 'wp-components is reported as overridden' );
t_ok( ! isset( $overrides['core'] ), 'core-served handles never appear as an override' );
$summary = snt_script_package_override_summary();
t_ok( false !== strpos( $summary, 'gutenberg' ), 'summary names the plugin' );
t_ok( false !== strpos( $summary, 'wp-components' ), 'summary names the handle that matters most' );
t_ok( false !== strpos( $summary, '3' ), 'summary carries the override count' );

// ─── 3. Handles are sorted, so the readout is stable across requests ──
$first = array_values( $overrides['plugin:gutenberg'] );
$sorted = $first;
sort( $sorted );
t_ok( $first === $sorted, 'handles are sorted — the field does not churn between requests' );

// ─── 4. A theme can override too ──────────────────────────────────────
$GLOBALS['__registered'] = array(
	'wp-components' => 'https://example.test/wp-content/themes/some-theme/js/components.js',
);
$overrides = snt_script_package_overrides();
t_ok( isset( $overrides['theme:some-theme'] ), 'a theme override is attributed to the theme' );

// ─── 5. Alias handles (registered with no src) are not overrides ──────
$GLOBALS['__registered'] = array(
	'wp-edit-post' => '',
	'wp-polyfill'  => false,
);
t_ok( array() === snt_script_package_overrides(), 'srcless alias handles are not counted as overrides' );

// ─── 6. Non-core handles are out of scope entirely ────────────────────
$GLOBALS['__registered'] = array(
	'snt-command-palette' => 'https://example.test/wp-content/plugins/signal-and-noise-tools/assets/command-palette.js',
	'wp-components'       => '/wp-includes/js/dist/components.min.js',
);
t_ok( array() === snt_script_package_overrides(), "our own plugin's own handles are not core packages" );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
