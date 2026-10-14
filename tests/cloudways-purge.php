<?php
/**
 * Standalone test: what is left of the Cloudways app purge (retired 2026-10-14).
 * Run: php tests/cloudways-purge.php
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

require_once __DIR__ . '/../inc/cloudways-purge.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: the credential readers the Connections leaf uses\n";
define( 'SN_CLOUDWAYS_EMAIL', 'me@example.test' );
define( 'SN_CLOUDWAYS_API_KEY', 'SECRETKEY123' );
define( 'SN_CLOUDWAYS_SERVER_ID', '111' );
ok( false === sn_cloudways_is_configured(), 'is_configured false when a constant is missing' );
define( 'SN_CLOUDWAYS_APP_ID', '222' );
ok( true === sn_cloudways_is_configured(), 'is_configured true with all four constants' );
ok( 'sn_cloudways_last_purge' === SNT_CW_LAST_PURGE_OPT, 'the historical record keeps its option name' );

echo "\nGroup: retired\n";
ok( 'retired: Varnish is off for this app' === sn_cloudways_not_run_label(), 'the ledger names it in words' );
ok( ! function_exists( 'sn_cloudways_purge_app' ) && false === sn_cloudways_app_purge_on(), 'no purge call, and the leaves read it as off' );
define( 'SN_CLOUDWAYS_APP_PURGE', true );
ok( false === sn_cloudways_app_purge_on(), 'the old wp-config switch turns nothing back on' );
$src = (string) file_get_contents( __DIR__ . '/../inc/cloudways-purge.php' );
ok( false === strpos( $src, "add_action( 'breeze_clear_varnish'" ) && false === strpos( $src, 'wp_remote_post' ), 'nothing hooks breeze_clear_varnish and nothing calls the API' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
