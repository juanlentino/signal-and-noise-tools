<?php
/**
 * Tests: Machine Readers pure renderers (Session 3 lane 2).
 * SCAFFOLD-RED: written against the shells on purpose; lane 2 turns it green.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }

require __DIR__ . '/../inc/machine-readers-render.php';

$rows = array(
	array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-07-27', 'hits' => 12 ),
	array( 'family' => 'openai',    'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 3 ),
	array( 'family' => 'anthropic', 'surface' => 'html',   'day' => '2026-07-28', 'hits' => 5 ),
	array( 'family' => 'uptime',    'surface' => 'html',   'day' => '2026-07-28', 'hits' => 40 ),
);

echo "Group: static compliance map pins\n";
$ai = snt_mr_ai_training_families();
ok( in_array( 'openai', $ai, true ) && in_array( 'anthropic', $ai, true ), 'AI-training class includes openai + anthropic' );
ok( ! in_array( 'search', $ai, true ) && ! in_array( 'uptime', $ai, true ), 'search + uptime are NOT AI-training class' );

echo "\nGroup: family table\n";
$html = snt_mr_render_family_table( $rows, 30 );
ok( '' !== $html && false !== strpos( $html, 'openai' ), 'renders and names families' );
ok( false !== strpos( $html, '15' ) || false !== strpos( $html, '15' ), 'openai hits aggregate across surfaces (12+3=15)' );
$hostile = snt_mr_render_family_table( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '<img src=x>', 'hits' => 1 ) ), 7 );
ok( false === strpos( $hostile, '<img' ), 'defense in depth: even normalized-shape fields render escaped' );

echo "\nGroup: surface table\n";
$html = snt_mr_render_surface_table( $rows );
ok( '' !== $html && false !== strpos( $html, 'llms' ) && false !== strpos( $html, 'rights' ), 'renders surface classes' );

echo "\nGroup: compliance read: observed-vs-declared, never verified\n";
$html = snt_mr_render_compliance( $rows );
ok( '' !== $html && false !== strpos( $html, 'openai' ), 'AI-training families appear' );
ok( false !== stripos( $html, 'observed' ) && false !== stripos( $html, 'declared' ), 'labeled observed-vs-declared' );
ok( false === stripos( $html, 'verified' ), 'never claims verified identity (UAs are self-reported)' );
ok( false === strpos( $html, 'uptime' ), 'non-AI families stay out of the compliance table' );

echo "\nGroup: sensor card, deployed version vs the contract minimum\n";
$html = snt_mr_render_sensor_card( array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T17:12:22Z' ) );
ok( '' !== $html && false !== strpos( $html, '1.4.0' ), 'card shows the deployed version' );
ok( false === stripos( $html, 'outdated' ), 'contract-satisfying version raises no warning' );
$html = snt_mr_render_sensor_card( array( 'version' => '1.3.0', 'deployed_at' => '2026-07-23T23:01:27Z' ) );
ok( false !== stripos( $html, 'outdated' ) && false !== strpos( $html, '1.4.0' ), 'below-minimum version warns and names the required 1.4.0' );
$html = snt_mr_render_sensor_card( null );
ok( '' !== $html && false === stripos( $html, 'outdated' ), 'null info renders the quiet dash card, not a warning or a fatal' );

echo "\nGroup: v9.86.0 — summary stat strip\n";
$html = snt_mr_render_summary_chips( $rows, 30 );
ok( '' !== $html && false !== strpos( $html, '60' ), 'total machine reads stated (12+3+5+40=60)' );
ok( false !== strpos( $html, 'uptime' ), 'top family named (uptime, 40)' );
ok( false !== strpos( $html, '20' ), 'AI-training reads counted (openai 15 + anthropic 5)' );
$hostile = snt_mr_render_summary_chips( array( array( 'family' => 'openai', 'surface' => 'llms', 'day' => '<svg onload=x>', 'hits' => 2 ) ), 7 );
ok( false === strpos( $hostile, '<svg' ), 'chips escape like every other sink' );
ok( '' !== snt_mr_render_summary_chips( array(), 30 ), 'empty rows still render a strip, not a fatal' );

echo "\nGroup: v10.1.1 — the sensor block copies the Analytics idiom\n";
$pills = snt_mr_sensor_pills(
	array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:07:56Z' ),
	array( 'last_check_ok' => '1', 'last_check_drift' => '' ),
	array( 'ok' => true )
);
ok( is_array( $pills ) && count( $pills ) >= 3, 'a pill per pipeline stage, like Analytics' );
foreach ( $pills as $p ) {
	ok( in_array( $p[0], array( 'ok', 'warn', 'unknown' ), true ), 'pill state is the Analytics vocabulary: ' . $p[0] );
}
$html = snt_mr_render_sensor_status( $pills );
ok( false !== strpos( $html, 'sn-an-pipeline-pills' ), 'uses the Analytics pill container class' );
ok( false !== strpos( $html, 'sn-an-pill sn-an-pill--ok' ), 'uses the Analytics pill classes, not a bespoke one' );
ok( false !== strpos( $html, 'sn-an-pill-mark' ), 'carries the Analytics check mark span' );
ok( false === strpos( $html, 'sn-mr-panel' ), 'the invented v10.0.1 panel class is gone' );

$warn = snt_mr_sensor_pills( null, null, array( 'ok' => false, 'error' => 'not_configured' ) );
$states = array();
foreach ( $warn as $p ) { $states[] = $p[0]; }
ok( in_array( 'warn', $states, true ), 'an unconfigured sensor shows a warn pill' );
$whtml = snt_mr_render_sensor_status( $warn );
ok( false !== strpos( $whtml, 'sn-an-pipeline-warn' ), 'and its explanation renders in the Analytics warn line, below the pills' );

echo "\nGroup: v10.2.1 R4 — the feed-fetcher column (rss-feed-tracker stays the source)\n";
$feed = snt_mr_render_feed_table( array( 'most_recent' => '2026-07-28 12:00:00', 'windows' => array( 7 => array( 'total' => 42, 'uniques' => 9 ), 30 => array( 'total' => 130, 'uniques' => 21 ) ) ) );
ok( false !== strpos( $feed, 'sn-an-table' ), 'uses the central table class, like the surface table beside it' );
ok( false !== strpos( $feed, '130' ) && false !== strpos( $feed, '21' ), 'renders the tracker window rows' );
ok( false !== stripos( $feed, 'fetch' ), 'names them fetches, not reads or visits (a feed fetcher is not a crawler read)' );
$hostile = snt_mr_render_feed_table( array( 'most_recent' => '<img src=x>', 'windows' => array( 7 => array( 'total' => 1, 'uniques' => 1 ) ) ) );
ok( false === strpos( $hostile, '<img' ), 'tracker values are escaped at the sink' );
ok( false !== strpos( snt_mr_render_feed_table( array() ), 'sn-an-empty' ), 'no tracker data renders the central empty state, not a fabricated zero' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
