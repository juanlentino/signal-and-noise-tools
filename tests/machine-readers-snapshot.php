<?php
/**
 * Tests: Machine Readers — the durable crawler snapshot (R3 gate 3A).
 *
 * The load-bearing assertion in this file is the NEGATIVE one: the read path
 * makes no outbound call under ANY state (never written / fresh / stale /
 * failing). wp_remote_get() below does not return a canned failure — it records
 * that it was reached at all, and every read-path test asserts the counter did
 * not move. A stub that quietly returns an error would let a regression pass
 * as "the fetch failed" instead of failing as "the fetch happened".
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__settings'] = array(
	'machine_readers.worker_url' => 'https://juanlentino.com/_sn/rights-signals/machine-readers',
	'machine_readers.read_token' => 'test-token',
);
function sn_setting( $key, $default = null ) { return $GLOBALS['__settings'][ $key ] ?? $default; }

// THE TRIPWIRE. Every call is counted; the read-path tests assert it never moves.
$GLOBALS['__http_calls'] = 0;
$GLOBALS['__response']   = array( 'code' => 200, 'body' => '' );
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__http_calls']++;
	return array( 'response' => array( 'code' => $GLOBALS['__response']['code'] ), 'body' => $GLOBALS['__response']['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function is_wp_error( $x ) { return false; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
$GLOBALS['__autoload'] = array();
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_http_validate_url( $url ) { return false !== filter_var( (string) $url, FILTER_VALIDATE_URL ) ? $url : false; }
function sn_ssrf_host_blocked( $host ) { return '' === (string) $host; }
$GLOBALS['__scheduled'] = array();
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__scheduled'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['__scheduled'][ $hook ] = array( 'ts' => $ts, 'recurrence' => $rec ); return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

// Real dependencies, not stubs: api.php's declarations are unguarded, so a stub
// would fatal on redeclare and a hand-modelled shape would drift from the callee.
require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-api.php';
require __DIR__ . '/../inc/machine-readers-snapshot.php';

$sensor_body = json_encode( array(
	'worker' => 'sn-rights-signals',
	'days'   => 30,
	'data'   => array(
		array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-08-10', 'hits' => 12 ),
		array( 'family' => 'openai',    'surface' => 'robots', 'day' => '2026-08-11', 'hits' => 3 ),
		array( 'family' => 'anthropic', 'surface' => 'rights', 'day' => '2026-08-10', 'hits' => 5 ),
	),
) );

echo "Group: the never-written snapshot is UNKNOWN, never zero\n";
$GLOBALS['__options']    = array();
$GLOBALS['__http_calls'] = 0;
$snap = snt_mr_snapshot();
ok( null === $snap, 'no option written yet → snapshot() is null (absent, not an empty record)' );
ok( false === snt_mr_snapshot_has_measurement( $snap ), 'a null snapshot has no measurement' );
ok( null === snt_mr_snapshot_total( $snap ), 'total is NULL on a never-written snapshot — never 0' );
ok( null === snt_mr_snapshot_age( $snap ), 'age is NULL, not 0 seconds' );
ok( 0 === $GLOBALS['__http_calls'], 'THE GATE: reading a never-written snapshot made no outbound call' );

echo "\nGroup: the cron refresh is the only writer, and the only fetcher\n";
$GLOBALS['__response']   = array( 'code' => 200, 'body' => $sensor_body );
$GLOBALS['__http_calls'] = 0;
$written = snt_mr_snapshot_refresh();
ok( 1 === $GLOBALS['__http_calls'], 'refresh() performs exactly one sensor fetch' );
ok( true === $written, 'refresh() reports that it captured a measurement' );
ok( isset( $GLOBALS['__options'][ SN_MR_SNAPSHOT_KEY ] ), 'refresh() wrote the durable option' );
ok( false === $GLOBALS['__autoload'][ SN_MR_SNAPSHOT_KEY ], 'the option is autoload=no (stays out of alloptions)' );

echo "\nGroup: reading a written snapshot still makes no outbound call\n";
$GLOBALS['__http_calls'] = 0;
$snap = snt_mr_snapshot();
ok( is_array( $snap ), 'snapshot() returns the stored record' );
ok( true === snt_mr_snapshot_has_measurement( $snap ), 'a captured snapshot has a measurement' );
ok( 20 === snt_mr_snapshot_total( $snap ), 'total sums every row\'s hits (12+3+5)' );
ok( 15 === ( $snap['by_family']['openai'] ?? null ), 'by_family sums across surfaces for one family' );
ok( 5 === ( $snap['by_surface']['rights'] ?? null ), 'by_surface carries the rights surface (3B\'s numerator source)' );
ok( ! isset( $snap['by_family']['perplexity'] ), 'a family with no rows is ABSENT from the map, not present as 0' );
// v13.98.0: the per-day series the liveness check reads. Same convention as
// by_family: a day with no rows is absent, never 0; keys sorted ascending.
ok( is_array( $snap['by_day'] ?? null ) && array_sum( $snap['by_day'] ) === $snap['total'], 'by_day sums to the same total as the window' );
ok( array_keys( $snap['by_day'] ) === array_values( array_unique( array_keys( $snap['by_day'] ) ) ) && $snap['by_day'] === ( function ( $m ) { ksort( $m ); return $m; } )( $snap['by_day'] ), 'by_day is keyed by day, ascending' );
ok( 0 === $GLOBALS['__http_calls'], 'THE GATE: reading a fresh snapshot made no outbound call' );

echo "\nGroup: a stale snapshot states its own age and still never fetches\n";
$stale = $GLOBALS['__options'][ SN_MR_SNAPSHOT_KEY ];
$stale['captured_at'] = time() - ( 3 * DAY_IN_SECONDS );
$GLOBALS['__options'][ SN_MR_SNAPSHOT_KEY ] = $stale;
$GLOBALS['__http_calls'] = 0;
$snap = snt_mr_snapshot();
$age  = snt_mr_snapshot_age( $snap );
ok( is_int( $age ) && $age >= 3 * DAY_IN_SECONDS, 'age reports ~3 days, in seconds' );
ok( true === snt_mr_snapshot_is_stale( $snap ), 'past the display threshold it reads stale' );
ok( 20 === snt_mr_snapshot_total( $snap ), 'a stale snapshot still reports its (old) measurement' );
ok( 0 === $GLOBALS['__http_calls'], 'THE GATE: a STALE snapshot does not trigger a refetch on read' );

echo "\nGroup: a failed refresh never destroys the last good measurement\n";
$GLOBALS['__response']   = array( 'code' => 503, 'body' => '' );
$GLOBALS['__http_calls'] = 0;
$written = snt_mr_snapshot_refresh();
ok( false === $written, 'a failing sensor read reports no capture' );
$snap = snt_mr_snapshot();
ok( 20 === snt_mr_snapshot_total( $snap ), 'the last good measurement survives a failed refresh' );
ok( is_int( $snap['captured_at'] ?? null ) && $snap['captured_at'] < time() - DAY_IN_SECONDS, 'captured_at still points at the OLD capture, not now' );
ok( 'http_503' === ( $snap['last_error'] ?? '' ), 'the failure is recorded beside the measurement, not swallowed' );
ok( is_int( $snap['last_attempt_at'] ?? null ), 'the attempt is timestamped separately from the capture' );

echo "\nGroup: a failure BEFORE any capture stays unknown — never a confident zero\n";
$GLOBALS['__options'] = array();
$GLOBALS['__response'] = array( 'code' => 503, 'body' => '' );
$written = snt_mr_snapshot_refresh();
ok( false === $written, 'first-ever refresh fails' );
$snap = snt_mr_snapshot();
ok( is_array( $snap ), 'the failed attempt is still recorded (so the surface can say when it last tried)' );
ok( false === snt_mr_snapshot_has_measurement( $snap ), 'but it carries NO measurement' );
ok( null === snt_mr_snapshot_total( $snap ), 'total stays NULL — a sensor that never answered is not a site nobody crawled' );
ok( null === snt_mr_snapshot_age( $snap ), 'age stays NULL when nothing was ever captured' );

echo "\nGroup: an unconfigured sensor is loud, and the empty answer is real\n";
$GLOBALS['__options'] = array();
$GLOBALS['__settings']['machine_readers.read_token'] = '';
ok( false === snt_mr_snapshot_refresh(), 'no token → no capture' );
ok( 'not_configured' === ( snt_mr_snapshot()['last_error'] ?? '' ), 'the reason is carried through verbatim from snt_mr_fetch()' );
$GLOBALS['__settings']['machine_readers.read_token'] = 'test-token';
// A sensor that answers 200 with zero rows is a MEASURED zero — the one case
// where 0 is the honest answer, and it must not collapse into "unknown".
$GLOBALS['__options'] = array();
$GLOBALS['__response'] = array( 'code' => 200, 'body' => json_encode( array( 'worker' => 'sn-rights-signals', 'days' => 30, 'data' => array() ) ) );
ok( true === snt_mr_snapshot_refresh(), 'an empty-but-successful read IS a capture' );
$snap = snt_mr_snapshot();
ok( true === snt_mr_snapshot_has_measurement( $snap ), 'zero rows still counts as measured' );
ok( 0 === snt_mr_snapshot_total( $snap ), 'a MEASURED zero is 0, not null (the other half of absent-vs-zero)' );

echo "\nGroup: the referral side rides the SAME capture, over the SAME window\n";
// The give-back ratio divides one side by the other. Two separately-timed
// captures would compare windows that ended on different days — wrong in a way
// nothing downstream can detect.
require_once __DIR__ . '/../inc/machine-readers-operators.php';
$GLOBALS['__src_window'] = array();
$GLOBALS['__src_rows']   = array( 'ChatGPT' => array( 'visits' => 4 ), 'Bing' => array( 'visits' => 99 ) );
function sn_analytics_top_sources( $from, $to, $class = 'human', $limit = 10 ) {
	$GLOBALS['__src_window'] = array( $from, $to, $class );
	return $GLOBALS['__src_rows'];
}
$GLOBALS['__options'] = array();
$GLOBALS['__response'] = array( 'code' => 200, 'body' => $sensor_body );
ok( true === snt_mr_snapshot_refresh(), 'a refresh with both sides available captures' );
$snap = snt_mr_snapshot();
$refs = snt_mr_snapshot_referrals( $snap );
ok( is_array( $refs ), 'the referral map is stored beside the crawl counts' );
ok( 4 === ( $refs['ChatGPT'] ?? null ), 'a label the query returned carries its visits' );
ok( 0 === ( $refs['Claude'] ?? null ), 'a label the query did NOT return is a measured zero, not absent' );
ok( ! isset( $refs['Bing'] ), 'a non-AI label is not carried into the AI referral map' );
list( $from, $to, $class ) = $GLOBALS['__src_window'];
ok( 'human' === $class, 'the referral read asks for HUMAN traffic — the crawler side is the other half of the ratio' );
ok( $to === gmdate( 'Y-m-d' ), 'the window ends today in UTC (analytics "today" is UTC)' );
ok( $from === gmdate( 'Y-m-d', time() - ( SN_MR_SNAPSHOT_DAYS - 1 ) * DAY_IN_SECONDS ), 'and spans exactly the snapshot window, so the ratio divides like-for-like' );
ok( SN_MR_SNAPSHOT_DAYS === ( $snap['days'] ?? null ), 'the record states that one window for both sides' );

echo "\nGroup: a FAILED referral read is unknown, never a measured zero\n";
$GLOBALS['__src_rows'] = null; // the accessor's failed-read verdict
$GLOBALS['__options'] = array();
ok( true === snt_mr_snapshot_refresh(), 'the crawl side still captures' );
$snap = snt_mr_snapshot();
ok( null === snt_mr_snapshot_referrals( $snap ), 'referrals read as UNMEASURED — otherwise every operator renders as never repaying' );
ok( is_int( $snap['captured_at'] ?? null ), 'and the crawl measurement is unaffected' );
$GLOBALS['__src_rows'] = array();
$GLOBALS['__options'] = array();
snt_mr_snapshot_refresh();
$refs = snt_mr_snapshot_referrals( snt_mr_snapshot() );
ok( is_array( $refs ) && 0 === $refs['ChatGPT'], 'an EMPTY result is a measured zero for every label — the other half of absent-vs-zero' );

echo "\nGroup: scheduling\n";
$GLOBALS['__scheduled'] = array();
snt_mr_snapshot_schedule();
ok( isset( $GLOBALS['__scheduled'][ SN_MR_SNAPSHOT_HOOK ] ), 'the refresh event is scheduled' );
$first = $GLOBALS['__scheduled'][ SN_MR_SNAPSHOT_HOOK ];
snt_mr_snapshot_schedule();
ok( $GLOBALS['__scheduled'][ SN_MR_SNAPSHOT_HOOK ] === $first, 'scheduling is idempotent (no event stacking)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
