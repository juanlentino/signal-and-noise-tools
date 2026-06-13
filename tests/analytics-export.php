<?php
/**
 * Tests for inc/analytics-export.php — pure CSV/JSON export formatters.
 * Run: php tests/analytics-export.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

// wp_json_encode stub — matches WP's signature.
function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); }

require __DIR__ . '/../inc/analytics-export.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "\nGroup: export formatters\n";
$rows = array(
	array( 'path' => '/a', 'views' => 10, 'visits' => 8 ),
	array( 'path' => '/b,c', 'views' => 5, 'visits' => 5 ),
);
$csv = sn_analytics_export_csv( $rows );
ok( strpos( $csv, "path,views,visits" ) === 0, 'CSV header from first row keys' );
ok( strpos( $csv, '"/b,c"' ) !== false, 'RFC-4180 quoting of embedded comma' );
ok( substr_count( $csv, "\n" ) >= 3, 'header + 2 rows present' );
$json = sn_analytics_export_json( $rows );
ok( json_decode( $json, true ) === $rows, 'JSON round-trips the rows' );
ok( sn_analytics_export_csv( array() ) === '', 'empty rows → empty CSV' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
