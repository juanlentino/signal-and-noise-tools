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

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root  = dirname( __DIR__ );
$files = array_merge(
	glob( $root . '/inc/*.php' ) ?: array(),
	glob( $root . '/inc/admin-forms/*.php' ) ?: array()
);

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
