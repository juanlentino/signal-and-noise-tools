<?php
/**
 * SN Health and SN Anchors read at a glance (owner, 2026-10-04).
 *
 * The 21.8.0 accessibility pass turned hover-only titles into visible text,
 * and both cards filled up: Health printed a provider's raw error (six lines)
 * under a headline that counted a finding and a check that could not run as
 * the same "not passed"; Anchors printed three "all good" lines and a fourth
 * that repeated the two rows above it. Pins the fix by its source shape.
 * Checked by a jsdom render while building it: the old files print the
 * clutter, the new ones do not.
 *
 * Run: php tests/desktop-widget-states.php
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root    = dirname( __DIR__ );
$strip   = static fn( $s ) => (string) preg_replace( '#^\s*//.*$#m', '', (string) preg_replace( '#/\*.*?\*/#s', '', (string) $s ) );
$health  = $strip( file_get_contents( $root . '/assets/desktop-mode-widget-health.js' ) );
$anchors = $strip( file_get_contents( $root . '/assets/desktop-mode-widget-anchors.js' ) );
$kit     = $strip( file_get_contents( $root . '/inc/openstation-kit-display.php' ) );

echo "Group: SN Health\n";
ok( false !== strpos( $health, "' to look at'" ) && false !== strpos( $health, "' could not run'" ) && false === strpos( $health, "'/' + summary.total + ' checks passed'" ), 'the headline counts findings and checks that could not run apart, not one "x/y passed"' );
ok( 1 === preg_match( '/text:\s*shortReason\(\s*String\(\s*s\.reason\s*\)\s*\)/', $health ) && false !== strpos( $health, 'Full reason on the Health tab.' ), 'a check that could not run shows a glance-sized reason, the full text one click away' );

echo "\nGroup: SN Anchors\n";
ok( false !== strpos( $anchors, "'✓ All anchored: '" ) && false === strpos( $anchors, "'No anchors pending.'" ), 'all anchored is one line, not three' );
ok( 1 === preg_match( '/if\s*\(\s*archive\.line\s*&&\s*halted\s*\)/', $anchors ), 'the Archive line shows only when the run is halted; otherwise it repeats the rows' );

echo "\nGroup: notices\n";
ok( 1 === preg_match( "/'role'\s*=>\s*'danger'\s*===\s*snt_kit_tone\(\s*\\\$kind\s*\)\s*\?\s*'alert'/", $kit ), 'an error notice is role="alert"; os-notice defaults the rest to status' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
