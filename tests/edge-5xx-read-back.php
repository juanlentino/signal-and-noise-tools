<?php
/**
 * Tests: the stored 5xx rows are read back (issue #1002, 17.8.1).
 *
 * sn_edge_errors_dims() has written `err_path` (which URLs failed) and
 * `err_source` (who answered) into sn_edge_dims since 13.96.3, and nothing
 * read them: sn_edge_top_dim() was only ever asked for country, colo and
 * threat. So a steady ten 503s a day stayed unexplained while the datum that
 * names the responder sat unread in the table.
 *
 * Run: php tests/edge-5xx-read-back.php
 * @since 17.8.1
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );

// Stubs for what the extracted functions call.
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s ) { return $s; }
$GLOBALS['e5r_calls'] = array();
function sn_edge_top_dim( $dim, $from, $to, $limit = 10 ) {
	$GLOBALS['e5r_calls'][] = array( $dim, $from, $to, $limit );
	$fx = array(
		'err_path'   => array( array( 'value' => '/wp-cron.php', 'requests' => 7, 'bytes' => 0 ), array( 'value' => '/feed/', 'requests' => 3, 'bytes' => 0 ) ),
		'err_source' => array( array( 'value' => 'edge=503 origin=503 cache=miss', 'requests' => 8, 'bytes' => 0 ), array( 'value' => 'edge=520 origin=- cache=miss', 'requests' => 2, 'bytes' => 0 ) ),
	);
	return $fx[ $dim ] ?? array();
}

/** Extract ONE function by balanced braces (needs no WordPress boot). */
function e5r_extract( $src, $name ) {
	$toks = token_get_all( $src );
	for ( $i = 0, $n = count( $toks ); $i < $n; $i++ ) {
		if ( ! is_array( $toks[ $i ] ) || T_FUNCTION !== $toks[ $i ][0] ) { continue; }
		$j = $i + 1;
		while ( $j < $n && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) { $j++; }
		if ( ! is_array( $toks[ $j ] ) || $name !== $toks[ $j ][1] ) { continue; }
		$depth = 0; $started = false; $buf = '';
		for ( $k = $i; $k < $n; $k++ ) {
			$piece = is_array( $toks[ $k ] ) ? $toks[ $k ][1] : $toks[ $k ];
			$buf  .= $piece;
			if ( '{' === $piece ) { $depth++; $started = true; }
			elseif ( '}' === $piece ) { $depth--; if ( $started && 0 === $depth ) { break; } }
		}
		return $buf;
	}
	return '';
}

$roll = (string) file_get_contents( $root . '/inc/edge-rollup.php' );
foreach ( array( 'sn_edge_errors_reading', 'sn_edge_errors_range', 'sn_edge_error_source_label' ) as $name ) {
	$fn = e5r_extract( $roll, $name );
	ok( '' !== $fn, "$name() was extracted; if empty, every assertion below is vacuous" );
	eval( $fn ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only extraction.
}

echo "\nGroup 1: the responder, in words\n";
ok( '503 from the origin' === sn_edge_error_source_label( 'edge=503 origin=503 cache=miss' ), 'edge and origin agree: the origin failed' );
ok( false !== strpos( sn_edge_error_source_label( 'edge=520 origin=- cache=miss' ), 'Cloudflare itself' ), '`origin=-`: nothing upstream answered, Cloudflare did' );
ok( '502 at the edge, origin said 503' === sn_edge_error_source_label( 'edge=502 origin=503' ), 'a disagreement names both' );
ok( 'garbage' === sn_edge_error_source_label( 'garbage' ), 'an unrecognised value passes through rather than being guessed at' );

echo "\nGroup 2: the reading\n";
$GLOBALS['e5r_calls'] = array();
$r = sn_edge_errors_reading( 7, '2026-09-23' );
ok( '2026-09-17' === $r['from'] && '2026-09-23' === $r['to'], 'seven days ending today is 17..23 inclusive, not 16..23' );
ok( 10 === $r['total'], 'the total sums the responders (8 + 2)' );
ok( '/wp-cron.php' === $r['paths'][0]['value'] && 7 === $r['paths'][0]['requests'], 'paths come through with their counts' );
ok( '503 from the origin' === $r['sources'][0]['label'], 'each responder carries its plain-words label' );
$dims = array_column( $GLOBALS['e5r_calls'], 0 );
ok( in_array( 'err_path', $dims, true ) && in_array( 'err_source', $dims, true ), 'both stored dims are read, which is the whole fix' );
$r = sn_edge_errors_range( '2026-09-01', '2026-09-23' );
ok( '2026-09-01' === $r['from'], 'a panel can pass its own range straight through' );

echo "\nGroup 3: every surface reads it\n";
$native  = (string) file_get_contents( $root . '/apps/sn-dashboard/parts/leaves/connections-cloudflare-errors.php' );
$monitor = (string) file_get_contents( $root . '/apps/sn-dashboard/parts/leaves/connections-cloudflare-monitor.php' );
$classic = (string) file_get_contents( $root . '/inc/edge-admin.php' );
$ability = (string) file_get_contents( $root . '/inc/abilities-cloudflare-status.php' );
ok( false !== strpos( $native, 'sn_edge_errors_reading(' ) && false !== strpos( $monitor, 'cloudflare_edge_errors_html()' ), 'the native Edge section paints it' );
ok( false !== strpos( $classic, 'sn_edge_errors_range( $from, $to )' ), 'the classic Edge panel paints it, over its own range' );
ok( false !== strpos( $ability, "'errors_5xx' => \$errors" ) && 2 === substr_count( $ability, "'errors_5xx' => \$errors" ), 'cloudflare-status carries it on BOTH returns, never_run included (the rollup does not depend on the monitor)' );
ok( false !== strpos( $ability, "'errors_5xx' => array( 'type'" ), 'and declares it in the output schema' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
