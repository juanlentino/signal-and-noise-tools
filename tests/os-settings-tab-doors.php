<?php
/**
 * Doors to this plugin's own pages open the NATIVE window (14.7.6).
 *
 * Drives assets/os-settings-tab.js through tests/js/os-settings-tab-doors.mjs
 * (a vm context with a fake shell) and pins each scenario by name. Run:
 * php tests/os-settings-tab-doors.php
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

$harness = __DIR__ . '/js/os-settings-tab-doors.mjs';
ok( is_readable( $harness ), 'the node harness is present at tests/js/os-settings-tab-doors.mjs' );
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
	'dashboard page: native window with tab/sub/anchor params',
	'analytics page: native window, sn_* params only',
	'preference off: not taken, no window opened',
	'another admin page, another origin, an empty url: not taken',
	'shell refuses (unregistered window): not taken, so the caller falls back',
	'a kit door click to our page is claimed in the capture phase and stopped',
	'a kit door click to another admin page is left to the framework',
	'a modified click (cmd/ctrl/middle) is never claimed',
);
foreach ( $expected as $name ) {
	$got = isset( $by_name[ $name ] ) ? $by_name[ $name ] : null;
	ok( is_array( $got ) && ! empty( $got['pass'] ), 'doors — ' . $name . ( is_array( $got ) ? ' [' . $got['detail'] . ']' : ' [SCENARIO MISSING FROM HARNESS]' ) );
}
ok( count( $json['results'] ) === count( $expected ), 'the harness ran exactly the scenarios this suite pins (' . count( $expected ) . ')' );
ok( 0 === $code, 'the harness process exited 0' );

// The app client asks the same resolver before the host's openUrl.
$client = preg_replace( '~/\*.*?\*/~s', '', (string) file_get_contents( __DIR__ . '/../apps/signal-noise/signal-noise-client.js' ) );
$client = preg_replace( '~^\s*//.*$~m', '', (string) $client );
ok( false !== strpos( (string) $client, 'prefs.tryNativeRemap( url )' ) && strpos( (string) $client, 'prefs.tryNativeRemap( url )' ) < strpos( (string) $client, 'ctx.host.openUrl(' ), 'the app client tries the native remap BEFORE ctx.host.openUrl' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
