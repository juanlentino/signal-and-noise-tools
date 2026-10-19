<?php
/**
 * Guard: the desktop pollers never fire together (2026-10-19). Edge errors
 * showed 503s on desktop/pulse, desktop/machine-readers and the deploy and
 * anchor ability runs: on reveal or focus return every stale widget re-armed
 * at 0 ms in one tick, and the pulse woke its subscribers in one call, so a
 * small PHP pool took the whole desktop at once. Each wait now passes through
 * sntPollCadence.jitter (0 to SPREAD_MS added), and SN Queue backs off after
 * a failed read as its siblings do.
 *
 * Run: php tests/poll-jitter.php
 */
if ( PHP_SAPI !== 'cli' ) { exit; }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$out = (string) shell_exec( 'node ' . escapeshellarg( __DIR__ . '/js/poll-jitter.cjs' ) . ' 2>&1' );
echo $out;
ok( false !== strpos( $out, 'PASS: poll jitter' ), 'tests/js/poll-jitter.cjs passes (node is required; never silently skipped)' );

$a = __DIR__ . '/../assets/';
// Each poller's re-arm goes through the jitter, guarded so the widget keeps
// its fixed rate when the helper is absent.
foreach ( array( 'desktop-mode-widget.js', 'desktop-mode-widget-health.js', 'desktop-mode-widget-views.js', 'desktop-mode-widget-groups.js', 'desktop-mode-widget-anchors.js', 'desktop-mode-widget-queue.js', 'snt-pulse.js' ) as $f ) {
	$js = (string) file_get_contents( $a . $f );
	ok( false !== strpos( $js, 'C && C.jitter ? C.jitter(' ), "$f routes its wait through sntPollCadence.jitter, and keeps it as is when the helper is absent" );
}
$pulse = (string) file_get_contents( $a . 'snt-pulse.js' );
ok( 1 === preg_match( '/function fire[\s\S]*?setTimeout\([\s\S]*?jitter/', $pulse ), 'the pulse wakes each subscriber after its own jitter, not all in one call' );

echo "\nThe interval poller backs off\n";
$out = (string) shell_exec( 'node ' . escapeshellarg( __DIR__ . '/desktop-status-resilience.cjs' ) . ' 2>&1' );
ok( 1 === preg_match( '/^PASS/m', $out ) && false === stripos( $out, 'AssertionError' ), 'tests/desktop-status-resilience.cjs passes (it drives SN Queue through a doubled wait)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
