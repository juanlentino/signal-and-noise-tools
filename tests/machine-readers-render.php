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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
