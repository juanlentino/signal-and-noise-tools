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

$updater = (string) file_get_contents( $root . '/inc/wp-update-integration.php' );
mf_check( false === strpos( $updater, "'6.4'" ), 'self-updater requires mirrors no longer report 6.4' );
mf_check( 2 <= substr_count( $updater, "requires" ) && false !== strpos( $updater, "= '7.0'" ), 'self-updater requires mirrors report 7.0' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
