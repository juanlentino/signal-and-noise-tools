<?php
/**
 * Tests: no `\uXXXX` escape survives into rendered PHP output.
 *
 * Run: php tests/no-literal-unicode-escapes.php
 *
 * WHY THIS EXISTS: PHP single-quoted strings do NOT interpret `\u`. Writing
 * '·' produces the six literal characters, and v11.24.0 shipped exactly
 * that into an admin table — the separator rendered as "·" between every
 * title, and the scope sentence carried a literal "—".
 *
 * It is an easy mistake to repeat because the escape LOOKS right in source and
 * only reveals itself on screen. A grep is a cheap permanent guard.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

require_once __DIR__ . '/lib/inc-population.php'; // #987: inc/ is walked, not top-level-globbed.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root  = dirname( __DIR__ );
// Was `inc/*.php` merged with a HAND-LISTED `inc/admin-forms/*.php`. That list
// was correct when written and admin-forms was the only package; five more were
// created since and 71 files silently left this guard's reach (#987). The size
// check below never noticed - 443 files clears "> 100" as easily as 514 does,
// which is why the population is now walked instead of listed.
$files = snt_test_inc_files();

echo "Group: the scan is not vacuous\n";
ok( count( $files ) > 100, 'scanning ' . count( $files ) . ' PHP files' );
// Control: the pattern must be able to MATCH something, or a clean result
// proves nothing. (memory: negative-control your own instruments)
ok( 1 === preg_match( '/\\\\u[0-9a-fA-F]{4}/', "a \\u00b7 b" ), 'the pattern detects a literal \\uXXXX when one is present' );

echo "\nGroup: no literal escapes in shipped strings\n";
$hits = array();
foreach ( $files as $file ) {
	foreach ( file( $file ) as $n => $line ) {
		// Only quoted strings matter; a \u inside a regex or a comment about
		// this very problem is not a rendering bug.
		if ( false !== strpos( $line, '\u' ) && preg_match( '/[\'"][^\'"]*\\\\u[0-9a-fA-F]{4}/', $line ) ) {
			$hits[] = basename( $file ) . ':' . ( $n + 1 );
		}
	}
}
ok( array() === $hits, 'no \\uXXXX escapes inside quoted strings' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
