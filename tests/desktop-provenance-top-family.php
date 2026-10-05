<?php
/**
 * SN Provenance's "Top crawler family" row (inc/desktop-mode-payloads.php):
 * the share is left out on a capped current read (its family hits are partial
 * while the total is exact), and the change against the prior 30 days is set
 * only when the 60-day read covers every prior day.
 *
 * Run: php tests/desktop-provenance-top-family.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
function add_action() {}
function snt_os_compat_add_filter() {}
require __DIR__ . '/../inc/desktop-mode-payloads.php';
require __DIR__ . '/../inc/machine-readers-insights.php'; // the real snt_mr_split_windows()

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function snt_mr_summary_payload( $days ) { return array( 'ok' => true, 'days' => 30, 'total' => 200, 'families' => array( array( 'family' => 'unclassified-machine', 'hits' => 88 ) ) ); }
$GLOBALS['__reads'] = array();
function snt_mr_fetch( $days ) { return $GLOBALS['__reads'][ (int) $days ]; }

/** Rows for the prior 30 days (days 31..60 back), the first $n of them. */
function prior_rows( $n ) {
	$rows = array();
	$end  = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' );
	for ( $i = 30; $i < 30 + $n; $i++ ) {
		$day    = gmdate( 'Y-m-d', $end - $i * DAY_IN_SECONDS );
		$rows[] = array( 'day' => $day, 'family' => 'unclassified-machine', 'hits' => 1 );
		$rows[] = array( 'day' => $day, 'family' => 'GPTBot', 'hits' => 3 );
	}
	return $rows;
}

$current = array( 'ok' => true, 'truncated' => false, 'rows' => array() );
$GLOBALS['__reads'] = array( 30 => $current, 60 => array( 'ok' => true, 'truncated' => false, 'rows' => prior_rows( 30 ) ) );
$p = snt_desktop_machine_readers_payload();
ok( array( 'family' => 'unclassified-machine', 'share' => 44, 'prior_share' => 25 ) === ( $p['top_family'] ?? null ), 'every prior day covered: the share and the prior share' );

$GLOBALS['__reads'][60] = array( 'ok' => true, 'truncated' => false, 'rows' => prior_rows( 5 ) );
$p = snt_desktop_machine_readers_payload();
ok( 44 === ( $p['top_family']['share'] ?? null ) && null === $p['top_family']['prior_share'], 'only five prior days in the 60-day read: no prior share, never one built on a partial window' );

$GLOBALS['__reads'][60] = array( 'ok' => true, 'truncated' => false, 'rows' => array_map( static fn( $r ) => array_diff_key( $r, array( 'day' => 1 ) ), prior_rows( 30 ) ) );
ok( null === snt_desktop_machine_readers_top_family( array( 'families' => array( array( 'family' => 'x', 'hits' => 1 ) ), 'total' => 2 ), $GLOBALS['__reads'][60]['rows'] )['prior_share'], 'rows with no day: coverage cannot be established, so no prior share' );

$GLOBALS['__reads'] = array( 30 => array( 'ok' => true, 'truncated' => true, 'rows' => array() ), 60 => array( 'ok' => true, 'truncated' => false, 'rows' => prior_rows( 30 ) ) );
$p = snt_desktop_machine_readers_payload();
ok( ! isset( $p['top_family'] ), 'a capped current read: no top family at all (partial hits over an exact total)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
