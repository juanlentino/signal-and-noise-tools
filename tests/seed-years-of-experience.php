<?php
/**
 * Seed copy states 15+ years, never another figure.
 *
 * The owner's figure is 15+ (2026-10-15). The live /services meta carried
 * "Twenty-plus years" while the page body said 15+; the seed files still said
 * "20+ years" and "Twenty years in", so a reseed would have put the wrong
 * number back. resume-integrity.php pins the résumé document; this pins every
 * seed file and the résumé content migration.
 *
 * Run: php tests/seed-years-of-experience.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$root  = dirname( __DIR__ );
$files = array_merge( glob( $root . '/inc/seed-content/*.html' ), array( $root . '/inc/content-migrations/resume.php' ) );
ok( count( $files ) > 5, 'the seed files are found (' . count( $files ) . ')' );

$wrong = '/\b(?!15\+)\d{2}\+? ?years\b|\b\d{2}-year\b|\b(?:twenty|thirty)(?:[- ]plus)? years\b/i';
$bad   = array();
foreach ( $files as $f ) {
	if ( preg_match( $wrong, (string) file_get_contents( $f ), $m ) ) {
		$bad[] = basename( $f ) . ': "' . $m[0] . '"';
	}
}
ok( array() === $bad, 'no seed file states a years figure other than 15+' . ( $bad ? ' - ' . implode( '; ', $bad ) : '' ) );
ok( 1 === preg_match( $wrong, 'Twenty years in' ) && 1 === preg_match( $wrong, '20+ years' ) && 0 === preg_match( $wrong, '15+ years' ), 'the pattern catches "Twenty years in" and "20+ years" and passes "15+ years"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
