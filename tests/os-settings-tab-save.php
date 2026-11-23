<?php
/**
 * OS Settings, Signal & Noise: the tab's sections, its one save path,
 * Restore defaults and the test notification (2026-11-23).
 *
 * Drives assets/os-settings-tab.js through tests/js/os-settings-tab-save.mjs
 * (a vm context with a fake shell and a fake DOM) and pins each scenario by
 * name. Run: php tests/os-settings-tab-save.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function snt_run( array $cmd ) {
	$spec = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$pipes = array();
	$proc  = @proc_open( $cmd, $spec, $pipes );
	if ( ! is_resource( $proc ) ) {
		return array( 127, '' );
	}
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( (int) proc_close( $proc ), trim( (string) $out ) );
}

$harness = __DIR__ . '/js/os-settings-tab-save.mjs';
ok( is_readable( $harness ), 'the node harness is present at tests/js/os-settings-tab-save.mjs' );
list( $node_rc ) = snt_run( array( 'node', '--version' ) );
ok( 0 === $node_rc, 'node is available to execute the harness (asserted, never skipped)' );
if ( 0 !== $node_rc ) { echo "\nResult: $pass passed, $fail failed.\n"; exit( 1 ); }
list( $code, $raw ) = snt_run( array( 'node', $harness ) );
$line = '';
foreach ( array_reverse( explode( "\n", trim( $raw ) ) ) as $l ) { if ( '' !== trim( $l ) && '{' === $l[0] ) { $line = $l; break; } }
$json = json_decode( $line, true );
if ( ! is_array( $json ) || ! isset( $json['results'] ) ) {
	ok( false, 'harness printed JSON' ); echo "harness output was:\n$raw\n"; echo "\nResult: $pass passed, $fail failed.\n"; exit( 1 );
}
$by_name = array();
foreach ( $json['results'] as $r ) { $by_name[ $r['name'] ] = $r; }
$expected = array(
	'three headed sections, each switch under its own',
	'a switch posts its one key and refreshes the menu',
	'a failed save puts the switch back and says so',
	'Restore defaults posts the server list once and repaints from the answer',
	'a second save while one is in flight is ignored',
	'keyboard focus survives the repaint: Restore defaults keeps it, a failed switch gets it back',
	'the test notification has its own tag, saves nothing, and works with the switch off',
);
foreach ( $expected as $name ) {
	$got = isset( $by_name[ $name ] ) ? $by_name[ $name ] : null;
	ok( is_array( $got ) && ! empty( $got['pass'] ), 'save: ' . $name . ( is_array( $got ) ? ' [' . $got['detail'] . ']' : ' [SCENARIO MISSING FROM HARNESS]' ) );
}
ok( count( $json['results'] ) === count( $expected ), 'the harness ran exactly the scenarios this suite pins (' . count( $expected ) . ')' );
ok( 0 === $code, 'the harness process exited 0' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
