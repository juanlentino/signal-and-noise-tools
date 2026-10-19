<?php
/**
 * Tests: scanner probes are not site failures (inc/edge-error-probes.php).
 *
 * 2026-10-19: 26 of the week's 177 stored 5xx were direct requests to core
 * library files (/wp-admin/includes/schema.php, /wp-includes/customize/
 * class-wp-customize-cropped-image-control.php) that fatal when opened on
 * their own. WordPress never routes a request to them, so a 500 there is a
 * scanner, not the site failing. They are counted apart as `probes`, still
 * visible with their paths, and left out of `total`.
 *
 * Run: php tests/edge-error-probes.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$root = dirname( __DIR__ );
require_once __DIR__ . '/lib/inc-population.php';

$GLOBALS['ep_dims'] = array(
	'err_path'   => array(
		array( 'value' => '/wp-admin/includes/schema.php', 'requests' => 11, 'bytes' => 0 ),
		array( 'value' => '/wp-admin/customize.php', 'requests' => 8, 'bytes' => 0 ),
		array( 'value' => '/wp-includes/customize/class-wp-customize-cropped-image-control.php', 'requests' => 7, 'bytes' => 0 ),
		array( 'value' => '/', 'requests' => 4, 'bytes' => 0 ),
	),
	'err_source' => array( array( 'value' => 'edge=500 origin=500', 'requests' => 30, 'bytes' => 0 ) ),
);
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) { return array_slice( $GLOBALS['ep_dims'][ $dim ] ?? array(), 0, $limit ); }
function sn_edge_error_source_label( $v ) { return $v; }

$probes_file = $root . '/inc/edge-error-probes.php';
ok( is_file( $probes_file ), 'inc/edge-error-probes.php exists; if not, every assertion below is vacuous' );
if ( is_file( $probes_file ) ) {
	require_once $probes_file;
}
/** Extract ONE function by balanced braces. */
function ep_extract( $src, $name ) {
	if ( ! preg_match( '/\nfunction ' . preg_quote( $name, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) { return ''; }
	$start = $m[0][1]; $depth = 0; $began = false;
	for ( $i = strpos( $src, '{', $start ), $n = strlen( $src ); $i < $n; $i++ ) {
		if ( '{' === $src[ $i ] ) { $depth++; $began = true; } elseif ( '}' === $src[ $i ] ) { $depth--; }
		if ( $began && 0 === $depth ) { return substr( $src, $start, $i - $start + 1 ); }
	}
	return '';
}
$fn = ep_extract( snt_test_edge_rollup_src(), 'sn_edge_errors_range' );
ok( '' !== $fn, 'sn_edge_errors_range() was extracted' );
eval( $fn ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only extraction.
function sn_edge_errors_days_annotate( $d ) { return $d; }
function sn_edge_errors_asked_by_totals( $d ) { return array(); }
function sn_edge_errors_paths_by_day( $f, $t ) { return array(); }

echo "\nGroup 1: what is a probe\n";
$is = function_exists( 'sn_edge_error_is_probe' ) ? 'sn_edge_error_is_probe' : static fn() => null;
foreach ( array( '/wp-admin/includes/schema.php', '/wp-includes/customize/class-wp-customize-cropped-image-control.php', '/wp-includes/rss-functions.php', '/WP-Includes/x.PHP' ) as $p ) {
	ok( true === $is( $p ), "$p is a probe: a core library file, never a direct request" );
}
foreach ( array( '/wp-admin/customize.php', '/wp-admin/edit.php', '/wp-admin/admin-ajax.php', '/wp-login.php', '/wp-cron.php', '/wp-includes/js/jquery/jquery.min.js', '/wp-includes/', '/notes/wp-includes/a.php', '/', '/wp-includes/x.php.bak' ) as $p ) {
	ok( false === $is( $p ), "$p is not a probe" );
}

echo "\nGroup 2: the window splits\n";
$r = sn_edge_errors_range( '2026-10-13', '2026-10-19' );
ok( 18 === ( $r['probes']['total'] ?? null ), 'probes total the two core-library paths (11 + 7)' );
ok( array( '/wp-admin/includes/schema.php', '/wp-includes/customize/class-wp-customize-cropped-image-control.php' ) === array_column( $r['probes']['paths'] ?? array(), 'value' ), 'probes name their paths, most first' );
ok( 12 === $r['total'], 'total is the 30 stored less the 18 probes: what means the site failed' );
ok( array( '/wp-admin/customize.php', '/' ) === array_column( $r['paths'], 'value' ), 'paths keep customize.php (a routed admin screen) and drop the probes' );

echo "\nGroup 3: each day says its probes, and its total is net of them\n";
$days = sn_edge_errors_days_probes(
	array( array( 'day' => '2026-10-18', 'total' => 5 ), array( 'day' => '2026-10-19', 'total' => 2 ), array( 'day' => '2026-10-17', 'total' => 0 ) ),
	array( array( 'day' => '2026-10-18', 'value' => '/wp-admin/includes/schema.php', 'requests' => 3 ), array( 'day' => '2026-10-18', 'value' => '/', 'requests' => 2 ), array( 'day' => '2026-10-19', 'value' => '/wp-includes/a.php', 'requests' => 9 ) )
);
ok( 3 === $days[0]['probes'] && 2 === $days[0]['total'], 'a day with 5 stored, 3 of them probes, reads total 2 probes 3' );
ok( 0 === $days[1]['total'] && 9 === $days[1]['probes'], 'more probes than the source total clamps at 0, never negative' );
ok( 0 === $days[2]['probes'] && 0 === $days[2]['total'], 'a day with no rows reads 0 probes; its `read` still says whether 0 means anything' );

echo "\nGroup 4: an install day is judged without its probes\n";
require_once $root . '/inc/desktop-mode-status-extra-edge-parts.php';
$row = static fn( $v, $n ) => array( 'value' => $v, 'requests' => $n );
ok( true === snt_desktop_edge_install_explained( 2, array( $row( 'visitor 503 - dynamic /wp-admin/admin.php', 2 ), $row( 'unrecorded 500 500 dynamic /wp-admin/includes/schema.php', 4 ) ), 1 ), 'a probe 500 beside two maintenance 503s does not veto the install verdict' );
ok( false === snt_desktop_edge_install_explained( 2, array( $row( 'visitor 503 - dynamic /wp-admin/admin.php', 1 ), $row( 'visitor 500 500 dynamic /wp-admin/includes/schema.php', 4 ) ), 1 ), 'and a probe never counts toward explaining a real 5xx' );

echo "\nGroup 5: the ability says so\n";
$schema = (string) file_get_contents( $root . '/inc/abilities-edge-errors.php' );
ok( false !== strpos( $schema, "'probes'" ), 'the shared output schema declares probes' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
