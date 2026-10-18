<?php
/**
 * Tests: the WordPress AI client's cache group is non-prefetchable under
 * Object Cache Pro (2026-10-17).
 *
 * OCP prefetches a request's keys before plugins load, so a cached AI client
 * model object unserializes as __PHP_Incomplete_Class; OCP drops it and logs
 * "objectcache.notice: The cache key `ai_client_...` is incompatible with
 * prefetching" on every request. About 980 of the app error log's 1000 lines
 * were that notice, so the log held about 4 hours.
 *
 * Run: php tests/object-cache-groups.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$file = $root . '/inc/object-cache-groups.php';

echo "Group: without Object Cache Pro\n";
// No wp_cache_add_non_prefetchable_groups() yet: loading must not fatal.
ok( is_file( $file ), 'inc/object-cache-groups.php exists' );
if ( is_file( $file ) ) { require $file; }
ok( ! function_exists( 'wp_cache_add_non_prefetchable_groups' ), 'loading without OCP does not fatal (the OCP function is absent here)' );

echo "\nGroup: with Object Cache Pro\n";
$GLOBALS['__np'] = array();
// Declared conditionally so PHP does not hoist it above the no-OCP group.
if ( true ) { function wp_cache_add_non_prefetchable_groups( $groups ) { $GLOBALS['__np'] = array_merge( $GLOBALS['__np'], (array) $groups ); } }
if ( function_exists( 'snt_object_cache_groups' ) ) { snt_object_cache_groups(); }
ok( in_array( 'wp_ai_client', $GLOBALS['__np'], true ), 'wp_ai_client is registered as non-prefetchable' );
ok( array( 'wp_ai_client' ) === $GLOBALS['__np'], 'and nothing else: only the group the notice named' );

echo "\nGroup: wired at load time\n";
$main = (string) file_get_contents( $root . '/signal-and-noise-tools.php' );
ok( 1 === preg_match( "#require_once __DIR__ \. '/inc/object-cache-groups\.php';#", $main ), 'the main file requires it' );
$src = is_file( $file ) ? (string) file_get_contents( $file ) : '';
ok( 1 === preg_match( '#^snt_object_cache_groups\(\);#m', $src ), 'it runs as a bare call at load, before any AI client cache write (storePrefetches filters at shutdown)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
