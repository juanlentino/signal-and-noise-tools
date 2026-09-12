<?php
/**
 * Regression test (#1229): sn_speculation_href_exclude_paths() must not
 * fatal when sn_login_get_slug() is undeclared.
 *
 * inc/login-hide.php declares sn_login_get_slug() only when it does NOT
 * return early (the SN_LOGIN_BYPASS constant, or wps-hide-login /
 * rename-wp-login still active). With perf.speculative_loading on by
 * default, an unguarded call here fataled every front-end render whenever
 * one of those conditions held.
 *
 * Separate file from tests/speculation-rules.php: that suite defines
 * sn_login_get_slug() unconditionally (a PHP function, once declared,
 * cannot be un-declared), so it can never exercise the missing-function
 * branch. This file deliberately does NOT define it.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
$GLOBALS['__setting'] = 'sn-login';
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $path, $default = null ) { return 'login.slug' === $path ? $GLOBALS['__setting'] : $default; } }

ok( ! function_exists( 'sn_login_get_slug' ), 'fixture: sn_login_get_slug() is genuinely undeclared here (so this pin cannot be vacuous)' );

require __DIR__ . '/../inc/speculation-rules.php';

$result = sn_speculation_href_exclude_paths( array(), 'prerender' );
ok( is_array( $result ), 'no fatal: the callback returns an array even with sn_login_get_slug() undeclared (#1229)' );
ok( in_array( '/sn-login', $result, true ), 'falls back to sn_setting(\'login.slug\', \'sn-login\')  for the bare path' );
ok( in_array( '/sn-login/*', $result, true ), 'and for the wildcard path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
