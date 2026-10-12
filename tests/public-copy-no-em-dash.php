<?php
/**
 * 2026.10 live audit (owner-approved): the public /stats and /maturity copy
 * the plugin paints carries no em or en dash, and /stats states what the
 * Privacy Policy states (the measurement sets no cookies; embedded players
 * may set their own, so "nothing to consent to" was not true of the site).
 *
 * Every string literal in the painters is scanned (comments are not copy).
 * The roadmap board's sn_apply override lives in an option, not here: this
 * pins the shipped default.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "PASS: $m\n";
	} else {
		$fail++;
		echo "FAIL: $m\n";
	}
}

$root  = dirname( __DIR__ ) . '/inc/';
$files = array_merge(
	glob( $root . 'public-stats*.php' ),
	glob( $root . '*maturity-page.php' ),
	array( $root . 'maturity-index-page.php', $root . 'maturity-roadmap-shortcode.php', $root . 'maturity-layout.php' )
);
ok( count( $files ) >= 12, 'the painters are found (' . count( $files ) . ' files)' );

foreach ( $files as $file ) {
	$hits = array();
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true )
			&& preg_match( '/\x{2014}|\x{2013}|&mdash;|&ndash;/u', $t[1] ) ) {
			$hits[] = $t[2];
		}
	}
	ok( array() === $hits, basename( $file ) . ': no em or en dash in its strings' . ( $hits ? ' (lines ' . implode( ', ', $hits ) . ')' : '' ) );
}

$stats = (string) file_get_contents( $root . 'public-stats.php' );
ok( false === strpos( $stats, 'nothing to consent to' ), '/stats no longer says there is nothing to consent to' );
ok( false !== strpos( $stats, 'No consent banner: the measurement sets no cookies and uses no local storage.' ), '/stats says what the Privacy Policy says of the measurement' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
