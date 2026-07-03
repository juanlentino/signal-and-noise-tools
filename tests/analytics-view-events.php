<?php
/**
 * Standalone fixture tests for the v8.5.0 Events view extraction
 * (inc/analytics-view-events.php): the case body moved verbatim from the
 * dispatcher — this fixture pins the panel set + wrappers via recorders.
 *
 * Run: php tests/analytics-view-events.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( (string) $v ); } }

function sn_analytics_top_events( $f, $t, $l = 25 ) { return array(); }
function sn_analytics_top_event_props( $f, $t, $p = '', $l = 50 ) { $GLOBALS['__ev_prop'] = $p; return array(); }
function snt_analytics_render_events_table( $rows ) { echo '<!--EVENTS-->'; }
function snt_analytics_render_event_props_table( $rows, $prop = '' ) { echo '<!--PROPS:' . $prop . '-->'; }

require_once __DIR__ . '/../inc/analytics-view-events.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "analytics-view-events suite - plugin v8.5.0\n\nTest: extracted composition\n";
ob_start();
$_GET['sn_event_prop'] = 'plan';
snt_analytics_render_view_events( '2026-07-01', '2026-07-07' );
$html = (string) ob_get_clean();
ok( false !== strpos( $html, 'not segmented by traffic class' ), 'class-inert note kept' );
ok( false !== strpos( $html, '<!--EVENTS-->' ) && false !== strpos( $html, '<!--PROPS:plan-->' ), 'both tables render; the sn_event_prop GET filter reaches the props table' );
ok( 'plan' === ( $GLOBALS['__ev_prop'] ?? '' ), 'prop filter passed to the accessor' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
