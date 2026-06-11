<?php
/**
 * v5.0.0 guard: the gen-1 `@deprecated since 2.5.0` REST routes are REMOVED.
 *
 * Static source check that each removed route's `register_rest_route(...)`
 * call string is gone from its file. The live Ability replacements
 * (signal-noise/ai-generate-*, the abilities run surface for /cmd) stay and
 * are covered by tests/abilities-integration.php. The harness has no live
 * REST-route test to extend, so this mirrors the static-grep style of
 * tests/legacy-deprecation.php.
 *
 * @since 5.0.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function rr_check( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}

// Each entry: [ source file, the register_rest_route route-path string that must be ABSENT ].
$removed = array(
	array( 'inc/ai-excerpt.php', "'/ai/generate-excerpt'" ),
	array( 'inc/ai-meta-description.php', "'/ai/generate-meta-description'" ),
);

foreach ( $removed as $r ) {
	$src = (string) file_get_contents( $root . '/' . $r[0] );
	rr_check( false === strpos( $src, $r[1] ), $r[0] . ' no longer registers ' . $r[1] );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
