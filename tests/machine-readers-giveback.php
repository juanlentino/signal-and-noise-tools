<?php
/**
 * Tests: the give-back ratio — readers returned per crawl, per operator.
 *
 * The board row: "the ledger's crawl counts set against that operator's referred
 * human visits — so the page that says who reads by machine also says which
 * machines ever send a reader back".
 *
 * THE WHOLE DIFFICULTY IS THE ZEROES, and there are three different ones:
 *
 *   crawled 400, referred 0   → 0.0. A REAL answer, and the most interesting
 *                               one the row exists to publish.
 *   crawled 0,   referred 0   → UNDEFINED. Nothing to divide by. Not 0.
 *   no crawl data at all      → UNKNOWN. Either the sensor never answered, or
 *                               this operator has no crawler family here
 *                               (Copilot's crawler is bingbot, classified
 *                               `search`), so a denominator will never exist.
 *
 * Collapsing any of those into another publishes a claim the data does not
 * support — and every collapse runs in the flattering direction, making the site
 * look either more crawled or more repaid than it is.
 *
 * Pure by construction: it is handed a snapshot and a referral map, and fetches
 * nothing. That is what lets it render (3A's gate), and what lets this fixture
 * exist without a database.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
function wp_next_scheduled( $h, $a = array() ) { return false; }
function wp_schedule_event( $t, $r, $h, $a = array() ) { return true; }
function get_option( $k, $d = false ) { return $d; }

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-snapshot.php';
require __DIR__ . '/../inc/machine-readers-operators.php';
require __DIR__ . '/../inc/machine-readers-giveback.php';

/** A captured snapshot with the given per-family crawl counts. */
function gb_snap( $by_family ) {
	$total = 0;
	foreach ( $by_family as $n ) { $total += $n; }
	return array(
		'captured_at' => time() - 60,
		'days'        => 30,
		'total'       => $total,
		'by_family'   => $by_family,
		'by_surface'  => array(),
	);
}

echo "Group: the three zeroes are three different answers\n";
$snap = gb_snap( array( 'openai' => 400, 'anthropic' => 50, 'commoncrawl' => 900 ) );
$refs = array( 'ChatGPT' => 0, 'Claude' => 5 );

$r = snt_mr_giveback_for_operator( 'openai', $snap, $refs );
ok( 'none_returned' === $r['status'], 'crawled 400, referred 0 → status none_returned' );
ok( 0.0 === $r['ratio'], 'and the ratio is a REAL 0.0, not null — this is the answer the row exists to publish' );
ok( 400 === $r['crawls'] && 0 === $r['referrals'], 'both sides reported' );

$r = snt_mr_giveback_for_operator( 'anthropic', $snap, $refs );
ok( 'ok' === $r['status'], 'crawled 50, referred 5 → status ok' );
ok( abs( $r['ratio'] - 0.1 ) < 0.0001, 'ratio is referrals / crawls = 0.1 (readers returned per crawl)' );

$r = snt_mr_giveback_for_operator( 'perplexity', $snap, $refs );
ok( 'no_crawls' === $r['status'], 'measured window, this operator crawled 0 → no_crawls' );
ok( null === $r['ratio'], 'a zero denominator is UNDEFINED, never 0.0 — nothing to divide by' );
ok( 0 === $r['crawls'], 'and the measured zero crawls IS reported as 0, not null' );

$r = snt_mr_giveback_for_operator( 'microsoft', $snap, $refs );
ok( 'not_measurable' === $r['status'], 'an operator with no crawler family can never have a denominator' );
ok( null === $r['ratio'] && null === $r['crawls'], 'ratio and crawls are NULL — absent, not zero' );

echo "\nGroup: an unmeasured side makes the whole answer unknown\n";
$r = snt_mr_giveback_for_operator( 'openai', null, $refs );
ok( 'unmeasured' === $r['status'], 'no snapshot at all → unmeasured' );
ok( null === $r['ratio'] && null === $r['crawls'], 'crawls NULL — a sensor that never answered is not a site nobody crawled' );
$r = snt_mr_giveback_for_operator( 'openai', array( 'captured_at' => null, 'last_error' => 'http_503' ), $refs );
ok( 'unmeasured' === $r['status'], 'a failed-attempt record carries no measurement either' );
// The referral side has the same absent-vs-zero problem, from the other end.
$r = snt_mr_giveback_for_operator( 'openai', $snap, null );
ok( 'unmeasured' === $r['status'], 'referrals NOT measured → unknown, even with crawl data in hand' );
ok( null === $r['referrals'], 'and referrals is NULL, not 0 — otherwise every operator reads as never repaying' );

echo "\nGroup: a measured referral map treats a missing label as a measured zero\n";
// The analytics side counts every visit in the window; a label absent from that
// result means nobody arrived from it, which is a real zero. That is the OPPOSITE
// of the map itself being absent, tested above.
$r = snt_mr_giveback_for_operator( 'openai', $snap, array( 'Claude' => 5 ) );
ok( 0 === $r['referrals'], 'ChatGPT missing from a measured map = 0 referrals' );
ok( 'none_returned' === $r['status'], 'so openai reads as never having sent a reader back' );

echo "\nGroup: an operator's referrals sum across ALL its labels\n";
// No operator ships two AI labels today, so this is proved through the seam
// rather than through today's data — otherwise the summing code is unexercised
// and the first multi-label operator silently under-counts.
$multi = snt_mr_giveback_for_operator( 'openai', gb_snap( array( 'openai' => 100 ) ), array( 'ChatGPT' => 3 ) );
ok( 3 === $multi['referrals'], 'single-label operator sums to its one label' );
ok( function_exists( 'snt_mr_giveback_referrals_for' ), 'the summing step is its own function, so it can be tested directly' );
ok( 7 === snt_mr_giveback_referrals_for( array( 'ChatGPT', 'Claude' ), array( 'ChatGPT' => 3, 'Claude' => 4 ) ), 'two labels sum to 7 — the multi-label case works before an operator needs it' );
ok( null === snt_mr_giveback_referrals_for( array( 'ChatGPT' ), null ), 'an unmeasured map sums to NULL, not 0' );

echo "\nGroup: the table covers every operator and states its own limits\n";
$table = snt_mr_giveback_table( $snap, $refs );
ok( count( $table ) === count( snt_mr_operators() ), 'every operator appears — a missing row would read as "no such crawler"' );
$statuses = array();
foreach ( $table as $row ) { $statuses[ $row['status'] ] = true; }
ok( isset( $statuses['none_returned'] ) && isset( $statuses['ok'] ) && isset( $statuses['not_measurable'] ), 'the table carries the distinct statuses rather than flattening them' );
foreach ( $table as $row ) {
	ok( isset( $row['operator'], $row['label'], $row['status'] ), 'row ' . $row['operator'] . ' is well-formed' );
	ok( in_array( $row['status'], array( 'ok', 'none_returned', 'no_crawls', 'not_measurable', 'unmeasured' ), true ), 'row ' . $row['operator'] . ' carries a known status' );
	if ( 'ok' !== $row['status'] && 'none_returned' !== $row['status'] ) {
		ok( null === $row['ratio'], 'row ' . $row['operator'] . ' publishes no ratio it cannot support' );
	}
}

echo "\nGroup: unknown operator, and no division ever happens on a zero\n";
ok( null === snt_mr_giveback_for_operator( 'nope', $snap, $refs ), 'an unknown operator returns null, not a fabricated row' );
// Belt and braces: drive every operator through a snapshot of all-zero crawls
// and assert nothing produced INF or NAN.
$zeros = array();
foreach ( snt_mr_valid_families() as $f ) { $zeros[ $f ] = 0; }
$clean = true;
foreach ( snt_mr_giveback_table( gb_snap( $zeros ), $refs ) as $row ) {
	if ( null !== $row['ratio'] && ( is_nan( (float) $row['ratio'] ) || is_infinite( (float) $row['ratio'] ) ) ) { $clean = false; }
}
ok( $clean, 'an all-zero-crawl window produces no INF and no NAN anywhere' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
