<?php
/**
 * Boot-order regression pin for assets/desktop-mode.js (v11.7.1).
 *
 * WHY A SECOND SUITE INSTEAD OF MORE ASSERTIONS IN
 * tests/desktop-mode-integration.php: that suite asserts the self-alias line
 * `window.wp.desktop = window.wp.desktop || window.wp.os` is PRESENT in the
 * source. It is, it passed continuously, and the commands were dead in
 * production the whole time — the line sits below an early `return` that fired
 * first when neither global existed. A source-presence assertion cannot see
 * REACHABILITY. This suite therefore EXECUTES the real file (node + vm) under
 * the hostile ordering instead of reading it.
 *
 * THE BUG (measured live 2026-08-14, OpenStation v1.1.0, plugin v11.7.0):
 * OpenStation ships `desktop.min.js` — which installs window.wp.os — with
 * `defer`; our scripts are not deferred. Deferred scripts execute after every
 * non-deferred one, so the shell appearing at DOM index 56 ran AFTER ours at
 * 63 and 89. Both gates failed, the file returned, all 22 Cmd+K commands were
 * silently unregistered. Confirmed at the palette ("No commands matching
 * sn-cmd", negative-controlled with "post", which returned many).
 *
 * Subprocesses run via proc_open with an ARRAY command, so no shell is spawned
 * and there is no interpolation to escape.
 *
 * Run: php tests/desktop-mode-boot-order.php
 * @since plugin v11.7.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/**
 * Run a command with NO shell. Returns array( exit_code, output ).
 *
 * @param string[] $cmd Argv array — passed straight to proc_open.
 * @return array{0:int,1:string}
 */
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

$harness = __DIR__ . '/js/desktop-mode-boot.mjs';
ok( is_readable( $harness ), 'the node boot-order harness is present at tests/js/desktop-mode-boot.mjs' );

// Node availability is ASSERTED, never skipped. A skip would silently delete
// the only coverage that catches this class of bug, and a suite that quietly
// stops testing is exactly the false green tests/run.sh exists to prevent. If a
// runner genuinely lacks node we want a loud red, not a vanishing assertion.
list( $node_rc ) = snt_run( array( 'node', '--version' ) );
ok( 0 === $node_rc, 'node is available to execute the harness (asserted, never skipped — a skip would delete this coverage silently)' );

if ( 0 !== $node_rc ) {
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( 1 );
}

list( $code, $raw ) = snt_run( array( 'node', $harness ) );
$json = json_decode( $raw, true );

ok( is_array( $json ) && isset( $json['results'] ), 'the harness emitted a parseable JSON verdict' );
if ( ! is_array( $json ) || ! isset( $json['results'] ) ) {
	echo "harness output was:\n$raw\n";
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( 1 );
}

// Mirror each scenario as its own assertion so a failure names the scenario
// rather than reporting one opaque "harness failed".
$by_name = array();
foreach ( $json['results'] as $r ) {
	$by_name[ $r['name'] ] = $r;
}

$expected = array(
	'deferred-shell: commands register after the shell lands',
	'deferred-shell: sn-cmd slugs present',
	'deferred-shell: window.wp.desktop is aliased for the 65 legacy call sites',
	'shell-first: commands register synchronously, no event needed',
	'pre-rename family: still registers via wp.desktop alone',
	'no-shell: registers nothing and does not throw',
);

foreach ( $expected as $name ) {
	$got = isset( $by_name[ $name ] ) ? $by_name[ $name ] : null;
	// A missing scenario must FAIL, not vanish — otherwise renaming a scenario
	// in the harness would quietly drop its assertion here and still read green.
	ok(
		is_array( $got ) && ! empty( $got['pass'] ),
		'boot scenario — ' . $name . ( is_array( $got ) ? ' [' . $got['detail'] . ']' : ' [SCENARIO MISSING FROM HARNESS]' )
	);
}

ok( count( $json['results'] ) === count( $expected ),
	'the harness ran exactly the scenarios this suite pins (' . count( $expected ) . ') — a new scenario must be mirrored here, not left silently unasserted' );
ok( 0 === $code, 'the harness process exited 0' );

// The three deferred-shell scenarios ARE the regression. Pin them as a named
// group too, so a future edit that guts the retry cannot read green on the
// strength of the controls alone — those were green throughout the outage.
$reg_ok = 0;
foreach ( array_slice( $expected, 0, 3 ) as $name ) {
	if ( ! empty( $by_name[ $name ]['pass'] ) ) { $reg_ok++; }
}
ok( 3 === $reg_ok, 'all THREE deferred-shell assertions hold — the retry path is what this suite exists for; shell-first and pre-rename stayed green while production was broken' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
