<?php
/**
 * Regression test (#1228): the analytics tab-switch forward in
 * assets/os-kit.js must carry sn_compare alongside sn_range/sn_class/
 * sn_from/sn_to — apps/sn-analytics/parts/state.php's read/write path
 * treats `compare` as a first-class piece of state (sn_compare query arg),
 * so dropping it here silently reset the active compare mode on every
 * tab switch.
 *
 * Source-scan (no JS runtime in this suite), mirroring the style already
 * used in tests/desktop-mode-integration.php for JS source assertions.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$src = (string) file_get_contents( __DIR__ . '/../assets/os-kit.js' );

// Isolate the tab-switch forward's `args` object literal, not the whole file —
// a bare `strpos($src, 'sn_compare')` would pass if the string appeared ANYWHERE,
// which is exactly the kind of vacuous pin this project's own conventions warn
// against (tests/path-join-key.php et al.).
ok( 1 === preg_match( '/var args = \{[^}]*sn_range[^}]*\};/', $src, $m ), 'fixture: the args object literal is found in the source (so this pin cannot be vacuous)' );
$args_literal = $m[0] ?? '';

ok( false !== strpos( $args_literal, 'sn_range' ) && false !== strpos( $args_literal, 'sn_class' ), 'fixture: sn_range/sn_class are in that same args literal, confirming the right object was isolated' );
ok( false !== strpos( $args_literal, 'sn_compare' ), '#1228: sn_compare is forwarded in the SAME args object as sn_range/sn_class, not dropped' );
ok( false !== strpos( $args_literal, 'state.compare' ), 'and it reads from state.compare, the same state object the other fields read from' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
