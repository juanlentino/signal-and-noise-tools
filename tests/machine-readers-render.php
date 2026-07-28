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

echo "\nGroup: v10.0.1 — the sensor panel (identity + connection + crawler verdict)\n";
$panel = snt_mr_render_sensor_panel(
	array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28T18:02:43Z' ),
	array( 'last_check_ok' => '1', 'last_check_drift' => '', 'last_check_checked_at' => '2026-07-27T07:23:00Z' ),
	array( 'ok' => true, 'rows' => array(), 'error' => null )
);
ok( false !== strpos( $panel, '1.4.0' ), 'panel states the deployed sensor version' );
ok( false !== strpos( $panel, 'sn-mr-panel' ), 'panel uses its own scoped class (one zone, not three boxes)' );
ok( false !== stripos( $panel, 'connected' ), 'a healthy read reports the connection as connected' );
ok( false !== stripos( $panel, 'in sync' ), 'the crawler-list verdict rides inside the panel' );
ok( 1 === preg_match_all( '/<h2/', $panel ), 'exactly ONE h2 for the whole sensor zone (the hierarchy fix)' );

$panel_bad = snt_mr_render_sensor_panel( null, null, array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' ) );
ok( false !== stripos( $panel_bad, 'not configured' ), 'an unconfigured sensor says so IN the panel, next to the fields that fix it' );
ok( false === strpos( $panel_bad, '<script' ) && false === strpos( $panel_bad, '<img' ), 'no unescaped markup leaks from absent data' );
$panel_hostile = snt_mr_render_sensor_panel( array( 'version' => '1.4.0', 'deployed_at' => '<img src=x>' ), null, array( 'ok' => true ) );
ok( false === strpos( $panel_hostile, '<img' ), 'worker-supplied values stay escaped in the panel' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
