<?php
/**
 * v5.0.0 manifest-floor guard.
 *
 * Pins the WP 7.0 hard-raise + the 5.0.0 version across the plugin header
 * AND the self-updater's mirrored values (inc/wp-update-integration.php),
 * so the "View Details" modal + the WP updates page can never drift back
 * below the enforced floor.
 *
 * @since 5.0.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function mf_check( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}

$root   = dirname( __DIR__ );
$header = (string) file_get_contents( $root . '/signal-and-noise-tools.php' );

preg_match( '/Requires at least:\s*([0-9.]+)/', $header, $m );
mf_check( isset( $m[1] ) && '7.0' === $m[1], 'plugin header "Requires at least: 7.0" (got ' . ( $m[1] ?? '?' ) . ')' );

preg_match( '/Version:\s*([0-9.]+)/', $header, $v );
mf_check( isset( $v[1] ) && version_compare( $v[1], '5.0.0', '>=' ), 'plugin Version >= 5.0.0 (got ' . ( $v[1] ?? '?' ) . ')' );

// #1615: the Update URI header. Without it every update check posts the slug
// to api.wordpress.org unmarked, and a same-name directory plugin would land
// on the Updates page. With a github.com hostname the .org API ignores the
// plugin; the transient filter in inc/wp-update-integration.php still runs.
mf_check( 1 === preg_match( '/^ \* Update URI:\s*https:\/\/github\.com\/juanlentino\/signal-and-noise-tools\s*$/m', $header ), 'plugin header carries Update URI on the github.com hostname, the repo the updater reads tags from' );

$updater = (string) file_get_contents( $root . '/inc/wp-update-integration.php' );
mf_check( false === strpos( $updater, "'6.4'" ), 'self-updater requires mirrors no longer report 6.4' );
// v14.5.1: the updater reads the header, never a literal. Two literals said
// tested 7.0 while the header said 7.1, and the Updates page read
// "Compatibility with WordPress 7.1: Not tested" on a 7.1 site.
mf_check( 0 === preg_match( "/->(tested|requires|requires_php)\s*=\s*'[0-9.]+'/", $updater ), 'self-updater assigns NO compatibility literal — every value comes from sn_gh_plugin_compat()' );
mf_check( 2 === substr_count( $updater, '= sn_gh_plugin_compat()' ), 'both surfaces (update transient, View details modal) read the header helper' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', $root . '/' ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $c => $v ) { if ( ! defined( $c ) ) { define( $c, $v ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
require_once $root . '/inc/wp-update-integration.php';
preg_match( '/Tested up to:\s*([0-9.]+)/', $header, $t );
preg_match( '/Requires PHP:\s*([0-9.]+)/', $header, $ph );
$compat = sn_gh_plugin_compat();
mf_check( ( $t[1] ?? '?' ) === $compat['tested'] && '7.1' === $compat['tested'], 'self-updater reports the header\'s Tested up to (' . $compat['tested'] . ') — the value core paints as "Compatibility with WordPress"' );
mf_check( ( $m[1] ?? '?' ) === $compat['requires'] && ( $ph[1] ?? '?' ) === $compat['requires_php'], 'self-updater requires / requires_php mirror the header (' . $compat['requires'] . ' / ' . $compat['requires_php'] . ')' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
