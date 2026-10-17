<?php
/**
 * Standalone test: each /verify check's stamp carries a lamp (2026-10-17).
 * Shape, not color alone, says the state: solid for PASS, solid blood for
 * FAIL, a dashed ring for UNREACHABLE (a network failure must never look like
 * a failed check), half filled for NOTE, a dotted ring while pending.
 *
 * Run: php tests/prov-verify-lamps.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/prov-verify.css' ) );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
$lamp = static function ( $state ) use ( $css ) {
	return preg_match( '/\.sn-verify-check\[data-state="' . $state . '"\] \.sn-verify-check-state::before\s*\{([^}]*)\}/', $css, $m ) ? $m[1] : null;
};
ok( 1 === preg_match( '/\.sn-verify-check-state::before\s*\{[^}]*content:\s*"";[^}]*border-radius:\s*50%;[^}]*border:\s*1\.5px solid currentColor/s', $css ), 'every stamp gets a round lamp, ringed in the stamp\'s own color' );
ok( null !== ( $p = $lamp( 'PASS' ) ) && false !== strpos( $p, 'background:currentColor' ), 'PASS: a solid lamp' );
ok( null !== ( $f = $lamp( 'FAIL' ) ) && false !== strpos( $f, 'background:currentColor' ), 'FAIL: a solid lamp in the stamp\'s blood' );
ok( null !== ( $u = $lamp( 'UNREACHABLE' ) ) && false !== strpos( $u, 'border-style:dashed' ) && false === strpos( $u, 'background' ), 'UNREACHABLE: a hollow dashed ring, never filled like a result' );
ok( null !== ( $n = $lamp( 'NOTE' ) ) && false !== strpos( $n, 'linear-gradient(90deg,currentColor 50%,transparent 50%)' ), 'NOTE: half filled' );
ok( null !== ( $q = $lamp( 'pending' ) ) && false !== strpos( $q, 'border-style:dotted' ), 'pending: a dotted ring (the stamp already blinks under no-preference only)' );
ok( 1 === preg_match( '/@media \(forced-colors:\s*active\)\s*\{[^@]*\.sn-verify-check\[data-state="PASS"\] \.sn-verify-check-state::before[^{]*\{[^}]*forced-color-adjust:\s*none;[^}]*background:\s*CanvasText/s', $css ), 'forced colors keep solid lamps solid (backgrounds would otherwise be dropped)' );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
