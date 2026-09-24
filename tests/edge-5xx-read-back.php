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
foreach ( array( 'sn_edge_errors_reading', 'sn_edge_errors_range', 'sn_edge_error_source_label', 'sn_edge_error_asker', 'sn_edge_errors_days_shape', 'sn_edge_errors_asked_by_totals' ) as $name ) {
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

echo "\nGroup 4: Early Hints lookups are not errors (17.9.3)\n";
$ana_src = (string) file_get_contents( $root . '/inc/edge-analytics.php' );
$q_start = strpos( $ana_src, 'function sn_edge_errors_query' );
$q_body  = substr( $ana_src, $q_start, strpos( $ana_src, "\n}\n", $q_start ) - $q_start );
ok( false !== strpos( $q_body, 'requestSource_neq:"earlyHintsCache"' ), 'the 5xx query excludes Cloudflare\'s own Early Hints lookups (98% of stored 5xx on 2026-09-23)' );
ok( false !== strpos( $q_body, 'requestSource}' ), 'and asks for the request source, so what remains says who asked' );
ok( '520 from Cloudflare itself (the origin never answered), asked by a visitor' === sn_edge_error_source_label( 'src=eyeball edge=520 origin=- cache=none' ), 'a visitor\'s 5xx says so' );
ok( '503 from the origin, asked by a Worker' === sn_edge_error_source_label( 'src=edgeWorkerFetch edge=503 origin=503' ), 'a Worker\'s subrequest says so' );
ok( '504 from Cloudflare itself (the origin never answered)' === sn_edge_error_source_label( 'edge=504 origin=- cache=miss' ), 'a row stored before 17.9.3 (no src) reads as it did' );
$roll_src = (string) file_get_contents( $root . '/inc/edge-rollup.php' );
ok( false !== strpos( $roll_src, 'update_option( SN_EDGE_ERRORS_QUERY_OPT' ) && false !== strpos( $roll_src, "'query'       => function_exists( 'get_option' ) ? get_option( SN_EDGE_ERRORS_QUERY_OPT" ), 'the query\'s outcome is kept and read back, so a refused query is "not read", never "no errors"' );

echo "\nGroup 5: one row per day, and who asked (18.2.0)\n";
ok( 'visitor' === sn_edge_error_asker( 'src=eyeball edge=520 origin=- cache=none' ), 'eyeball is a visitor' );
ok( 'worker' === sn_edge_error_asker( 'src=edgeWorkerFetch edge=503 origin=503' ), 'edgeWorker* is a Worker' );
ok( 'other' === sn_edge_error_asker( 'src=somethingNew edge=500 origin=500' ), 'any other source is other, never guessed into a bucket' );
ok( 'unrecorded' === sn_edge_error_asker( 'edge=504 origin=- cache=miss' ), 'no src (stored before 17.9.3) is unrecorded: the pre-filter leftover' );
$shape = sn_edge_errors_days_shape( array(
	array( 'day' => '2026-09-22', 'value' => 'edge=504 origin=- cache=miss', 'requests' => 5000 ),
	array( 'day' => '2026-09-24', 'value' => 'src=eyeball edge=520 origin=- cache=none', 'requests' => 3 ),
	array( 'day' => '2026-09-24', 'value' => 'src=edgeWorkerFetch edge=503 origin=503', 'requests' => 2 ),
	array( 'day' => '2026-09-30', 'value' => 'src=eyeball edge=500 origin=500', 'requests' => 99 ),
), '2026-09-22', '2026-09-24' );
ok( 3 === count( $shape ), 'every day in the range is present' );
ok( array( '2026-09-22', '2026-09-23', '2026-09-24' ) === array_column( $shape, 'day' ), 'oldest first' );
ok( 0 === $shape[1]['total'], 'a day with nothing stored reads 0 instead of vanishing' );
ok( 5000 === $shape[0]['unrecorded'] && 5000 === $shape[0]['total'], 'the pre-filter day is all unrecorded' );
ok( 5 === $shape[2]['total'] && 3 === $shape[2]['visitor'] && 2 === $shape[2]['worker'], 'the post-filter day splits by who asked' );
ok( 5005 === array_sum( array_column( $shape, 'total' ) ), 'a row outside the range is dropped, not folded into an edge day' );
ok( array() === sn_edge_errors_days_shape( array(), '2026-09-24', '2026-09-22' ), 'an inverted range is empty, never a runaway loop' );
ok( array( 'visitor' => 3, 'worker' => 2, 'other' => 0, 'unrecorded' => 5000 ) === sn_edge_errors_asked_by_totals( $shape ), 'the window totals sum the days' );

$r = sn_edge_errors_range( '2026-09-17', '2026-09-23' );
ok( array() === $r['days'] && 10 === $r['total'], 'without the day reader, total falls back to the responders (unchanged)' );
if ( ! function_exists( 'sn_edge_errors_days' ) ) { // Conditional, so it is NOT hoisted above Group 2's no-reader assertions.
	function sn_edge_errors_days( $from, $to ) {
		return sn_edge_errors_days_shape( array(
			array( 'day' => $from, 'value' => 'edge=504 origin=- cache=miss', 'requests' => 40 ),
			array( 'day' => $to, 'value' => 'src=eyeball edge=520 origin=- cache=none', 'requests' => 2 ),
		), $from, $to );
	}
}
$r = sn_edge_errors_range( '2026-09-17', '2026-09-23' );
ok( 7 === count( $r['days'] ), 'the reading carries one row per day of the window' );
ok( 42 === $r['total'], 'total is the full sum of the days, not the top ten responders\' share' );
ok( 2 === $r['asked_by']['visitor'] && 40 === $r['asked_by']['unrecorded'], 'and says who asked over the window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
