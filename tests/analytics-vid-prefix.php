<?php
/**
 * Guard: every Analytics Engine read takes the visitor-day from SN_AE_VID.
 *
 * index1 is about to carry a suffix on events other than page views
 * (`<hash>:sc50`), so a read that names index1 directly would count one
 * visitor-day once per event type. inc/analytics-vid.php defines
 * SN_AE_VID = 'substring(index1, 1, 16)'; this guard fails if any PHP string
 * in inc/ (any depth) names index1 outside that file. Comments are not string
 * tokens, so prose about index1 is fine.
 *
 * Run: php tests/analytics-vid-prefix.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

require_once __DIR__ . '/lib/inc-population.php';

$pass = 0; $fail = 0;
function vp_ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$files   = snt_test_inc_files();
$helper  = null;
$readers = 0;
$bare    = array();
foreach ( $files as $f ) {
	$src = (string) file_get_contents( $f );
	if ( 'analytics-vid.php' === basename( $f ) ) {
		$helper = $src;
		continue;
	}
	if ( false !== strpos( $src, 'SN_AE_VID' ) ) {
		++$readers;
	}
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && preg_match( '/\bindex1\b/', $t[1] ) ) {
			$bare[] = substr( $f, strlen( dirname( __DIR__ ) ) + 1 ) . ':' . $t[2];
		}
	}
}

vp_ok( count( $files ) > 100, 'walked inc/ at every depth (' . count( $files ) . ' files)' );
vp_ok( null !== $helper && false !== strpos( $helper, "const SN_AE_VID = 'substring(index1, 1, 16)';" ), 'inc/analytics-vid.php defines SN_AE_VID as the 16-character prefix' );
vp_ok( $readers >= 25, "SN_AE_VID is used by the readers ($readers files)" );
vp_ok( array() === $bare, 'no SQL string in inc/ names index1 outside the helper' . ( $bare ? ': ' . implode( ', ', $bare ) : '' ) );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
