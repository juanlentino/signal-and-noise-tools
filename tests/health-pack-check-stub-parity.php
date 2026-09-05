<?php
/**
 * Tests: test-local `sn_health_pack_check` stubs match the real one (#1039).
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * `$skipped` was added to the real `sn_health_pack_check()` in v11.33.0 for a
 * specific reason, recorded in its own docblock: four Health checks bailed out
 * when a dependency was absent, and every one of them was counted as a PASS,
 * so the tab could report 7/7 on a day when three of the seven had not run.
 *
 * Twenty suites define their own stub of that function. Fourteen of them still
 * had the pre-v11.33.0 shape — three parameters, no `skipped` key — so they
 * silently discarded the fourth argument. **A suite whose stub cannot carry
 * the field cannot fail when a check forgets it**, which is exactly what
 * happened: seven checks were passing a bail-out reason as `fix_hint`, the
 * tally counted all seven as passes, and no test noticed for two minor
 * versions.
 *
 * The stubs exist for a good reason — requiring inc/health-checks.php drags in
 * the whole scan layer — so the answer is not to delete them. It is to make
 * their drift from the real signature a failing test.
 *
 * Run: php tests/health-pack-check-stub-parity.php
 * @since 13.97.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

// ── The real signature, read from source rather than remembered ────────────
$real_src = (string) file_get_contents( $root . '/inc/health-checks.php' );
$m        = array();
ok(
	1 === preg_match( '/function sn_health_pack_check\s*\(([^)]*)\)/', $real_src, $m ),
	'found the real sn_health_pack_check() signature in inc/health-checks.php'
);
$real_params = array_values( array_filter( array_map( 'trim', explode( ',', $m[1] ?? '' ) ) ) );
ok( 4 === count( $real_params ), sprintf( 'the real function takes %d parameters', count( $real_params ) ) );

// The keys it returns. Anchored on the function body, not the whole file.
$body_at   = strpos( $real_src, 'function sn_health_pack_check' );
$real_body = substr( $real_src, $body_at, 1400 );
$real_keys = array();
if ( preg_match_all( "/'([a-z_]+)'\s*=>/", $real_body, $km ) ) {
	$real_keys = array_values( array_unique( $km[1] ) );
}
sort( $real_keys );
ok( in_array( 'skipped', $real_keys, true ), 'and returns a `skipped` key: ' . implode( ', ', $real_keys ) );

echo "\nGroup 2: every test-local stub carries the same shape\n";

// A stub may legitimately return something else entirely — one suite returns a
// positional pair because it only ever reads findings. Those are exempt BY
// SHAPE, not by name: a stub that returns the real envelope must return all of
// it. Naming files here would rot the moment one is renamed.
$stubs = 0; $exempt = 0; $bad = array();
foreach ( (array) glob( $root . '/tests/*.php' ) as $file ) {
	// This file's own negative control below is a literal stub definition, and
	// the scanner cannot tell it from a real one — it caught itself on the
	// first run, which is the clearest evidence the matcher works. Excluded by
	// path, not by pattern, so no real stub can hide behind the same trick.
	if ( realpath( $file ) === realpath( __FILE__ ) ) {
		continue;
	}
	$src = (string) file_get_contents( $file );
	if ( ! preg_match( '/function sn_health_pack_check\s*\(([^)]*)\)\s*\{(.*?)\n?\s*\}/s', $src, $sm ) ) {
		continue;
	}
	++$stubs;
	if ( false === strpos( $sm[2], "'count'" ) ) {
		++$exempt;   // not the real envelope; it never claimed to be
		continue;
	}
	$params = count( array_filter( array_map( 'trim', explode( ',', $sm[1] ) ) ) );
	if ( $params < count( $real_params ) || false === strpos( $sm[2], "'skipped'" ) ) {
		$bad[] = sprintf( '%s (params=%d, skipped_key=%s)', basename( $file ), $params, false !== strpos( $sm[2], "'skipped'" ) ? 'yes' : 'NO' );
	}
}

ok( $stubs >= 15, sprintf( 'VACUITY: found %d stubs to check — a scan that matched none would pass silently', $stubs ) );
ok( $exempt >= 1, sprintf( '%d stub(s) return a different shape on purpose and are exempt by SHAPE, not by filename', $exempt ) );
ok(
	array() === $bad,
	'every envelope-shaped stub matches the real parameter count and returns `skipped`: ' . ( $bad ? implode( '; ', $bad ) : 'all match' )
);

echo "\nGroup 3: negative control\n";
// Prove the comparison can fail: a synthetic pre-v11.33.0 stub must be caught.
$old_stub = "function sn_health_pack_check( \$label, \$findings, \$fix_hint = '' ) {\n\treturn array( 'count' => count( \$findings ), 'fix_hint' => \$fix_hint );\n}";
preg_match( '/function sn_health_pack_check\s*\(([^)]*)\)\s*\{(.*?)\n?\s*\}/s', $old_stub, $om );
$old_params = count( array_filter( array_map( 'trim', explode( ',', $om[1] ) ) ) );
ok( $old_params < count( $real_params ), 'a 3-parameter stub is detected as short' );
ok( false === strpos( $om[2], "'skipped'" ), 'and its missing `skipped` key is detected' );

echo sprintf( "\nResult: %d passed, %d failed.\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
