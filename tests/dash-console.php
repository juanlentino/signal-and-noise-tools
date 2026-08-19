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

// ── registration ────────────────────────────────────────────────────────────
$GLOBALS['__boxes']  = array();
$GLOBALS['__screen'] = array();
function add_meta_box( $id, $title, $cb, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
	$GLOBALS['__boxes'][] = array( 'id' => $id, 'screen' => $screen, 'context' => $context, 'cb' => $cb );
}
function add_screen_option( $opt, $args = array() ) { $GLOBALS['__screen'][ $opt ] = $args; }

$GLOBALS['__tab'] = 'dashboard';
function sn_admin_page_active_tab() { return $GLOBALS['__tab']; }

snt_dash_boxes_register( 'toplevel_page_sn-theme-options' );
$ids = array_column( $GLOBALS['__boxes'], 'id' );
ok( in_array( 'sn-dash-systems', $ids, true ), 'the Systems box registers' );
ok( in_array( 'sn-dash-fleet', $ids, true ), 'the Fleet box registers' );
ok( in_array( 'sn-dash-maintenance', $ids, true ), 'the Maintenance box registers' );
ok( 6 === count( $ids ), 'all six boxes register, no more and no fewer' );

$ctx = array_column( $GLOBALS['__boxes'], 'context', 'id' );
ok( 'normal' === $ctx['sn-dash-systems'], 'Systems is a main-column box' );
ok( 'side' === $ctx['sn-dash-maintenance'], 'Maintenance is a side-column box' );
ok( isset( $GLOBALS['__screen']['layout_columns'] ), 'the two-column layout option is offered' );

// Every registered callback must actually be callable once the box files load.
// A typo here renders an empty box with no error at all.
foreach ( $GLOBALS['__boxes'] as $b ) {
	ok( is_string( $b['cb'] ) && '' !== $b['cb'], "box {$b['id']} names a callback" );
}

// Registration must target the screen it was given, not a hardcoded one.
$screens = array_unique( array_column( $GLOBALS['__boxes'], 'screen' ) );
ok( array( 'toplevel_page_sn-theme-options' ) === array_values( $screens ), 'every box registers against the screen it was handed' );

// THE GATE. Screen Options is per-SCREEN, not per-tab — every tab shares
// toplevel_page_sn-theme-options. Registering anywhere else would put our
// checkboxes in Screen Options on the Security tab.
$GLOBALS['__boxes'] = array();
$GLOBALS['__tab']   = 'security';
snt_dash_boxes_register( 'toplevel_page_sn-theme-options' );
ok( array() === $GLOBALS['__boxes'], 'NO BOXES REGISTER ON ANOTHER TAB' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
