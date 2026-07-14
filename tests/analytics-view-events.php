<?php
/**
 * Standalone fixture tests for the v8.5.0 Events view extraction
 * (inc/analytics-view-events.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * D5 §6 adds: the "not segmented by traffic class" caveat must fold away (not
 * orphan) when both the events leaderboard and the property breakdown are
 * dataless, and stay present the moment either panel has rows — including the
 * D4 filtered ?sn_event_prop carve-out, which must keep working unchanged.
 *
 * Run: php tests/analytics-view-events.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }

$GLOBALS['__ev_events'] = array();
$GLOBALS['__ev_props']  = array();
function sn_analytics_top_events( $f, $t, $l = 25 ) { return $GLOBALS['__ev_events']; }
function sn_analytics_top_event_props( $f, $t, $p = '', $l = 50 ) { $GLOBALS['__ev_prop'] = $p; return $GLOBALS['__ev_props']; }
function snt_analytics_render_events_table( $rows ) { echo '<!--EVENTS:' . count( (array) $rows ) . '-->'; }
function snt_analytics_render_event_props_table( $rows, $prop = '' ) { echo '<!--PROPS:' . $prop . ':' . count( (array) $rows ) . '-->'; }

require_once __DIR__ . '/../inc/analytics-view-events.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-events suite - plugin v8.5.0\n\nTest: D5 §6 — both panels empty folds the caveat instead of orphaning it\n";
$GLOBALS['__ev_events'] = array();
$GLOBALS['__ev_props']  = array();
unset( $_GET['sn_event_prop'] );
ob_start();
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$empty_html = (string) ob_get_clean();
ok( false === strpos( $empty_html, 'not segmented by traffic class' ), 'all-empty: class-inert caveat is ABSENT (no orphan)' );
ok( false !== strpos( $empty_html, '<!--EVENTS:0-->' ) && false !== strpos( $empty_html, '<!--PROPS::0-->' ), 'all-empty: both panels still render (folding is the panels'."'".' own concern)' );

echo "\nTest: D5 §6 — events data present keeps the caveat\n";
$GLOBALS['__ev_events'] = array( array( 'name' => 'signup', 'events' => 5, 'visitors' => 4 ) );
$GLOBALS['__ev_props']  = array();
ob_start();
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$events_html = (string) ob_get_clean();
ok( false !== strpos( $events_html, 'not segmented by traffic class' ), 'events data present: caveat PRESENT' );

echo "\nTest: D5 §6 — event-props data present keeps the caveat\n";
$GLOBALS['__ev_events'] = array();
$GLOBALS['__ev_props']  = array( array( 'property' => 'utm_source', 'value' => 'hn', 'events' => 5, 'visitors' => 4 ) );
ob_start();
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$props_html = (string) ob_get_clean();
ok( false !== strpos( $props_html, 'not segmented by traffic class' ), 'event-props data present: caveat PRESENT' );

echo "\nTest: T6 review — filtered-empty keeps the caveat (an OPEN carve-out panel sits below it)\n";
$GLOBALS['__ev_events']  = array();
$GLOBALS['__ev_props']   = array();
$_GET['sn_event_prop'] = 'utm_source';
ob_start();
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$filtered_html = (string) ob_get_clean();
ok( false !== strpos( $filtered_html, 'not segmented by traffic class' ), 'filtered-empty: caveat PRESENT (the carve-out panel below it is open)' );
unset( $_GET['sn_event_prop'] );

echo "\nTest: the D4 filtered ?sn_event_prop carve-out still reaches the props accessor unchanged\n";
$GLOBALS['__ev_events'] = array();
$GLOBALS['__ev_props']  = array();
$_GET['sn_event_prop']  = 'plan';
ob_start();
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, '<!--EVENTS:0-->' ) && false !== strpos( $html, '<!--PROPS:plan:0-->' ), 'both tables render; the sn_event_prop GET filter reaches the props table' );
ok( 'plan' === ( $GLOBALS['__ev_prop'] ?? '' ), 'prop filter passed to the accessor' );
unset( $_GET['sn_event_prop'] );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
