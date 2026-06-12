<?php
/**
 * v5.0.0 guard: the gen-2 (`@deprecated since 4.6.0`) REST handlers now fire a
 * RUNTIME `_deprecated_function()` warning, not just a docblock `@deprecated`.
 *
 * These routes keep working (their Ability replacements are the preferred
 * path); promoting them to runtime warnings schedules removal. The 3
 * Plausible Stats-API handlers were removed in v6.0.0, leaving cron-run +
 * pattern-adoption scan/dismiss. Static source check (no WP load).
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

$handlers = array(
	array( 'inc/rest-api.php',                'snt_rest_cron_run' ),
	array( 'inc/pattern-adoption-detect.php', 'snt_rest_pattern_adoption_scan' ),
	array( 'inc/pattern-adoption-admin.php',  'snt_rest_pattern_adoption_dismiss' ),
);

foreach ( $handlers as $h ) {
	$src = (string) file_get_contents( $root . '/' . $h[0] );
	$pos = strpos( $src, 'function ' . $h[1] . '(' );
	$body = false === $pos ? '' : substr( $src, $pos, 500 );
	$fires = false !== strpos( $body, '_deprecated_function(' );
	if ( $fires ) { $pass++; echo "PASS: $h[1] fires _deprecated_function()\n"; }
	else { $fail++; echo "FAIL: $h[1] in $h[0] does not fire _deprecated_function()\n"; }
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
