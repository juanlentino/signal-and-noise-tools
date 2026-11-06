<?php
/**
 * Tests: each stored 5xx says when it happened and from which network.
 *
 * Born from 2026-11-05: six 522s on the home page, and the daily counts could
 * not say when. Without a minute there is nothing to hold up against the
 * origin's firewall, so "a Cloudflare address was dropped" stayed a guess.
 *
 * Run: php tests/edge-errors-when.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__opts'] = array();
$GLOBALS['__rows'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'SN_EDGE_DIMS_TABLE' ) ) { define( 'SN_EDGE_DIMS_TABLE', 'sn_edge_dims' ); }
$GLOBALS['wpdb'] = new class {
	public $prefix = 'wp_';
	public function prepare( $sql, ...$args ) { $GLOBALS['__sql'] = $sql; $GLOBALS['__args'] = $args; return $sql; }
	public function get_results( $sql, $mode = null ) { return $GLOBALS['__rows']; }
};

require __DIR__ . '/../inc/edge-error-when.php';

$g = static function ( $minute, $path, $edge = 522, $colo = 'MIA', $asn = 7922, $name = 'COMCAST-7922', $src = 'eyeball', $count = 1 ) {
	return array( 'count' => $count, 'dimensions' => array(
		'datetimeMinute' => $minute, 'clientRequestPath' => $path, 'edgeResponseStatus' => $edge,
		'coloCode' => $colo, 'clientAsn' => $asn, 'clientASNDescription' => $name, 'requestSource' => $src,
	) );
};

echo "The query\n";
$q = sn_edge_errors_when_query();
foreach ( array( 'datetimeMinute', 'coloCode', 'clientAsn', 'clientASNDescription', 'clientRequestPath', 'edgeResponseStatus', 'requestSource' ) as $d ) {
	ok( false !== strpos( $q, $d ), "asks for $d" );
}
ok( false !== strpos( $q, 'edgeResponseStatus_geq:500' ), 'reads 5xx only' );
ok( false !== strpos( $q, 'requestSource_neq:"earlyHintsCache"' ) && false !== strpos( $q, 'requestSource_neq:"edgeWorkerCacheAPI"' ), 'leaves out the same non-errors the counts leave out' );
ok( false !== strpos( $q, 'datetime_geq:$from' ) && false !== strpos( $q, 'datetime_lt:$to' ), 'bounded on both ends, so a day cannot overlap the next' );
ok( false !== strpos( $q, 'limit:' . SN_EDGE_ERRORS_WHEN_LIMIT ), 'the limit in the query is the constant the cap is judged by' );
ok( 1 === substr_count( $q, 'errors:httpRequestsAdaptiveGroups' ), 'the rows arrive under errors' );

echo "The rows\n";
$rows = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/' ) ) );
ok( 1 === count( $rows ), 'one group, one row' );
ok( '2026-11-05' === $rows[0]['day'], 'filed under the day it happened' );
ok( 'err_when' === $rows[0]['dim'] && strlen( 'err_when' ) <= 16, 'a dim that fits the column' );
ok( '2026-11-05T13:47 visitor 522 MIA 7922 / COMCAST-7922' === $rows[0]['value'], 'the value reads minute, asker, status, colo, network, path, name' );
ok( 1 === $rows[0]['requests'] && 0 === $rows[0]['bytes'], 'counts the request, no bytes' );

$rows = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/', 503, 'EWR', 0, '', 'edgeWorkerFetch', 2 ) ) );
ok( '2026-11-05T13:47 worker 503 EWR 0 /' === $rows[0]['value'], 'a Worker is a worker, and no network name leaves no trailing space' );
ok( 2 === $rows[0]['requests'], 'the count is carried' );

$rows = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/' . str_repeat( 'a', 400 ), 522, 'MIA', 7922, str_repeat( 'n', 300 ) ) ) );
ok( strlen( $rows[0]['value'] ) <= 160, 'a long path and name stay inside the 160-character column' );
ok( 1 === preg_match( '/^2026-11-05T13:47 visitor 522 MIA 7922 \/a+ n+$/', $rows[0]['value'] ), 'and both survive, cut rather than dropped' );

$mb = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/', 522, 'MIA', 9121, 'a' . str_repeat( 'ü', 40 ) ) ) );
ok( 1 === preg_match( '//u', $mb[0]['value'] ), 'a non-ASCII network name cut at the limit is still valid UTF-8' );
$sp = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/a b' ) ) );
ok( '2026-11-05T13:47 visitor 522 MIA 7922 /a%20b COMCAST-7922' === $sp[0]['value'], 'a space in a path cannot split the fields' );

ok( array() === sn_edge_errors_when_rows( array( $g( '', '/' ) ) ), 'no minute, no row: a time we do not have is not invented' );
ok( array() === sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '' ) ) ), 'no path, no row' );
ok( array() === sn_edge_errors_when_rows( array( 'garbage', array() ) ), 'malformed groups are skipped' );
ok( array() === sn_edge_errors_when_rows( array() ), 'a quiet day writes nothing' );

$dup = sn_edge_errors_when_rows( array( $g( '2026-11-05T13:47:00Z', '/' ), $g( '2026-11-05T13:47:00Z', '/' ) ) );
ok( 1 === count( $dup ) && 2 === $dup[0]['requests'], 'two groups that cut to one value add up instead of overwriting' );

echo "Reading it back\n";
$GLOBALS['__rows'] = array(
	array( 'value' => '2026-11-05T13:47 visitor 522 IAD 14061 /notes/ DIGITALOCEAN', 'requests' => 2 ),
	array( 'value' => '2026-11-04T01:02 visitor 522 MIA 7922 / COMCAST-7922', 'requests' => 1 ),
	array( 'value' => 'not a when row', 'requests' => 9 ),
);
$read = sn_edge_errors_when_reading( '2026-10-31', '2026-11-06' );
ok( array( '2026-10-31', '2026-11-06', 'err_when', SN_EDGE_ERRORS_WHEN_LIMIT ) === $GLOBALS['__args'], 'reads the window, up to the limit' );
ok( false !== strpos( $GLOBALS['__sql'], 'ORDER BY value DESC' ), 'the database keeps the newest: a limit on the most requests would lose them' );
ok( false === strpos( $GLOBALS['__sql'], 'ORDER BY requests' ), 'never ordered by count' );
ok( false === $read['more'], 'under the limit, nothing older is hidden' );
ok( 2 === count( $read['rows'] ), 'an unreadable row is dropped, not shown as garbage' );
ok( '2026-11-05T13:47' === $read['rows'][0]['at'], 'in the order the database gave' );
ok( array( 'at' => '2026-11-05T13:47', 'asker' => 'visitor', 'edge' => 522, 'colo' => 'IAD', 'asn' => 14061, 'path' => '/notes/', 'network' => 'DIGITALOCEAN', 'requests' => 2 ) === $read['rows'][0], 'each field comes back typed' );
ok( null === $read['query'], 'before the first run the outcome is null, not a clean read' );
$saved             = $GLOBALS['__rows'];
$GLOBALS['__rows'] = array_fill( 0, SN_EDGE_ERRORS_WHEN_LIMIT, $saved[1] );
ok( true === sn_edge_errors_when_reading( '2026-10-31', '2026-11-06' )['more'], 'at the limit it says older rows may exist, so the cut is never silent' );
$GLOBALS['__rows'] = $saved;
$GLOBALS['__opts'][ SN_EDGE_ERRORS_WHEN_OPT ] = array( 'at' => 5, 'day' => '2026-11-05', 'error' => 'unknown field', 'capped' => false );
ok( 'unknown field' === sn_edge_errors_when_reading( '2026-10-31', '2026-11-06' )['query']['error'], 'a refused query is reported, so an empty list is never read as no errors' );

echo "Wiring\n";
$run = (string) file_get_contents( __DIR__ . '/../inc/edge-rollup-run-parts.php' );
$rng = (string) file_get_contents( __DIR__ . '/../inc/edge-rollup-errors-parts.php' );
$abl = (string) file_get_contents( __DIR__ . '/../inc/abilities-edge-errors.php' );
ok( false !== strpos( $run, 'sn_edge_query( sn_edge_errors_when_query()' ), 'the rollup runs it' );
ok( false !== strpos( $run, 'SN_EDGE_ERRORS_WHEN_OPT' ), 'and keeps the outcome' );
ok( false !== strpos( $run, 'SN_EDGE_ERRORS_WHEN_LIMIT <= count(' ), 'and says when the limit was reached' );
ok( strpos( $run, 'sn_edge_errors_when_query()' ) > strpos( $run, 'sn_edge_errors_mark_read(' ), 'after the counts, so a refusal here cannot touch them' );
ok( false !== strpos( $rng, "'when'" ), 'the local reading carries it' );
ok( false === strpos( $abl, "'when'" ), 'the remote twin does not: its contract stays where it is' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
