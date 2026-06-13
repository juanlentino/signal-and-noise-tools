<?php
/**
 * Tests for inc/abilities-analytics.php — read-only analytics Abilities.
 * Self-contained: stubs the Abilities API seam, fires the captured
 * wp_abilities_api_init closure.
 *
 * @package SignalNoiseTools
 * @since   6.1.0
 */
define( 'ABSPATH', '/' );
$GLOBALS['__ab'] = array();
function wp_register_ability( $id, $args ) { $GLOBALS['__ab'][ $id ] = $args; }
$GLOBALS['__ab_cb'] = null;
function add_action( $h, $c = null, $p = 10, $a = 1 ) { if ( 'wp_abilities_api_init' === $h ) { $GLOBALS['__ab_cb'] = $c; } }
$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
require __DIR__ . '/../inc/abilities-analytics.php';
call_user_func( $GLOBALS['__ab_cb'] );
echo "\nGroup: abilities\n";
ok( isset( $GLOBALS['__ab']['signal-noise/get-analytics-summary'] ), 'summary ability registered' );
$a = $GLOBALS['__ab']['signal-noise/get-analytics-summary'];
ok( ! empty( $a['meta']['show_in_rest'] ), 'exposed in REST' );
ok( empty( $a['meta']['annotations']['destructive'] ), 'read-only: not destructive' );
ok( ! empty( $a['meta']['annotations']['idempotent'] ), 'marked idempotent' );
ok( is_string( $a['permission_callback'] ) && $a['permission_callback'] !== '', 'has a permission callback' );
ok( isset( $a['execute_callback'] ), 'has an execute callback' );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
