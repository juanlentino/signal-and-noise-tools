<?php
/**
 * sn-status forwards the single tools' inputs (2026-10-24, #2275). Its own
 * tests stub the sources, so nothing tied snt_sn_status_takes() to the real
 * registrations: a source that renamed `detail` would leave uptime degrading
 * to {error:"unavailable"} only when a caller set it. This loads the real
 * source registrations and checks every forwarded input against them.
 *
 * Run: php tests/sn-status-inputs-drift.php
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );

function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { return true; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
function __( $s, $d = null ) { return $s; }

$root = dirname( __DIR__ );
require $root . '/inc/abilities-sn-status.php';
require $root . '/inc/abilities-cron.php';
require $root . '/inc/abilities-system-register-parts.php';
require $root . '/inc/uptime-status.php';
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() as $cb ) {
	$cb();
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "sn-status inputs vs the real source schemas\n\n";
$reg   = $GLOBALS['__registered'];
$own   = $reg['signal-noise/sn-status']['input_schema']['properties'] ?? array();
$map   = snt_sn_status_map();
$takes = snt_sn_status_takes();
ok( array( 'deploy', 'uptime', 'cron_scheduled', 'cron_history' ) === array_keys( $takes ), 'four sections take optional inputs' );

foreach ( $takes as $section => $keys ) {
	$slug = $map[ $section ];
	$src  = $reg[ $slug ]['input_schema']['properties'] ?? null;
	ok( is_array( $src ), "$section: $slug registers an input schema" );
	foreach ( $keys as $k ) {
		ok( isset( $src[ $k ] ), "$section: $slug declares $k" );
		ok( isset( $own[ $k ] ) && ( $own[ $k ]['type'] ?? '' ) === ( $src[ $k ]['type'] ?? null ), "$section: sn-status's $k has the source's type" );
		foreach ( array( 'minimum', 'maximum' ) as $b ) {
			if ( isset( $src[ $k ][ $b ] ) ) {
				ok( ( $own[ $k ][ $b ] ?? null ) === $src[ $k ][ $b ], "$section: sn-status's $k $b matches the source" );
			}
		}
	}
}
foreach ( array_keys( $own ) as $k ) {
	if ( 'sections' !== $k ) {
		ok( array() !== array_filter( $takes, static fn( $keys ) => in_array( $k, $keys, true ) ), "sn-status's $k reaches at least one section" );
	}
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
