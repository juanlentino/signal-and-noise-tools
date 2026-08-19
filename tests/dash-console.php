<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// Count every call so we can prove the probe fires ONCE.
$GLOBALS['__probe_calls'] = 0;
$GLOBALS['__card_calls']  = 0;
function snt_deploy_workers_status( $opts = array() ) {
	$GLOBALS['__probe_calls']++;
	return array(
		array( 'label' => 'Analytics', 'live' => '1.20.0', 'state' => 'ok', 'reason' => '' ),
		array( 'label' => 'Remote MCP', 'live' => '', 'state' => 'unknown', 'reason' => 'warming' ),
	);
}
function snt_deploy_status_for( $pkg ) { return array( 'current' => '11.28.0', 'state' => 'ok' ); }
function snt_dashboard_glance_cards( $t, $p, $r, $l ) { $GLOBALS['__card_calls']++; return array( array( 'label' => 'Health', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ) ) ); }
function snt_dashboard_last_deploy_label( $runs ) { return '1 minute ago'; }
function snt_dashboard_override_post_types() { return array( 'wp_template' ); }
function get_posts( $args = array() ) { return array(); }
function snt_dashboard_measurement_data() { return array( 'views_7d' => 103 ); }

require __DIR__ . '/../inc/dash-console.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard console — snapshot\n\n";

$a = snt_dashboard_snapshot();
ok( is_array( $a ) && isset( $a['workers'], $a['cards'], $a['theme'], $a['measurement'] ), 'the snapshot carries everything a box could need' );
ok( 1 === $GLOBALS['__probe_calls'], 'the worker probe fired once' );

// Six boxes asking six times must not mean six probes. This is the property:
// BEHAVIOUR MUST NOT DEPEND ON LAYOUT, and box order is user-controlled.
for ( $i = 0; $i < 6; $i++ ) { snt_dashboard_snapshot(); }
ok( 1 === $GLOBALS['__probe_calls'], 'SIX BOXES, ONE PROBE — box order cannot change behaviour' );
ok( 1 === $GLOBALS['__card_calls'], 'and the glance cards are built once' );

// The snapshot is a plain array, so a box cannot mutate another box's view.
$b = snt_dashboard_snapshot();
$b['cards'] = array();
ok( count( snt_dashboard_snapshot()['cards'] ) === 1, 'a box mutating its copy does not affect the next box' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
