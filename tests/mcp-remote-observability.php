<?php
/**
 * Tests: the remote door's observability store.
 *
 * THE PROPERTY THAT MATTERS MOST across this suite is that recording is
 * OBSERVATIONAL. The door must work identically with this module absent, so
 * nothing here may become a dependency of the bridge. That pin lives in
 * tests/mcp-bridge-route.php, because it is a property of the BRIDGE.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
// The module follows the WordPress timezone setting; these suites do not load
// WordPress, so wp_date() is stubbed to the server's. What matters is that the
// module CALLS wp_date and not gmdate — pinned below.
$GLOBALS['__wp_date_calls'] = array();
function wp_date( $format, $ts = null ) {
	$GLOBALS['__wp_date_calls'][] = $format;
	return date( $format, null === $ts ? time() : $ts );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
$GLOBALS['__autoload'] = array();
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}

$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['__ttls'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-observability.php';

echo "Group: the outcome list is a closed set\n";
ok( in_array( 'dispatched', SN_MCP_REMOTE_OUTCOMES, true ), 'dispatched is an outcome' );
ok( in_array( 'refused_shut', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_shut is an outcome' );
ok( in_array( 'refused_auth', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_auth is an outcome' );
ok( in_array( 'refused_slug', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_slug is an outcome' );
ok( in_array( 'refused_request', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_request is an outcome' );
ok( 5 === count( SN_MCP_REMOTE_OUTCOMES ), 'and there are exactly five — a new one must be added deliberately, with a counter and a label' );

echo "Group: the day key follows the WordPress timezone setting\n";
// PINNED BY CALL, NOT BY VALUE, and deliberately. On a UTC server wp_date() and
// gmdate() return the SAME STRING, so a value comparison would pass against
// either and prove nothing — it would be green on CI and green on a mutation
// that swapped the call. Recording that wp_date was asked for 'Y-m-d' is the
// only assertion here that a swap to gmdate() can fail.
$GLOBALS['__wp_date_calls'] = array();
$key = sn_mcp_remote_log_day_key();
ok( in_array( 'Y-m-d', $GLOBALS['__wp_date_calls'], true ), 'THE TIMEZONE PIN: the day key is produced by wp_date, so it agrees with snt_audit_today_key()' );
ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $key ), 'and it is shaped Y-m-d' );

echo "Group: the blob lazy-initialises to a valid shape\n";
$GLOBALS['__options'] = array();
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'], 'a missing option yields schema 1' );
ok( null === $blob['last_used'], 'with no last_used' );
ok( array() === $blob['counters'], 'no counters' );
ok( array() === $blob['recent'], 'and no recent rows' );

echo "Group: a corrupt option does not poison the reader\n";
// An option can be hand-edited, half-written, or restored from an older schema.
// Returning garbage here would propagate into every caller.
$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = 'not an array';
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'] && array() === $blob['counters'], 'a non-array option falls back to the empty shape rather than propagating garbage' );

$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = array( 'schema' => 1 );
$blob = sn_mcp_remote_log_get_blob();
ok( array() === $blob['counters'] && array() === $blob['recent'] && null === $blob['last_used'], 'and a partial blob gains its missing keys instead of returning undefined ones' );

echo "Group: saving does NOT autoload\n";
// This option is read by the admin panel and by nothing on a front-end request.
// Autoloading it would tax every page view for one screen's data.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_save_blob( sn_mcp_remote_log_get_blob() );
ok( false === $GLOBALS['__autoload'][ SN_MCP_REMOTE_LOG_OPTION ], 'THE ONE THAT MATTERS FOR EVERY PAGE LOAD: the log option is saved with autoload FALSE' );

echo "Group: a persisted outcome moves a counter, the ring, and last_used\n";
$GLOBALS['__options'] = array();
$today = sn_mcp_remote_log_day_key();

sn_mcp_remote_log_apply( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['counters'][ $today ]['dispatched'], 'a dispatch increments today\'s dispatched counter' );
ok( 1 === count( $blob['recent'] ), 'and appends one ring row' );
ok( 'signal-noise/remote-get-analytics-summary' === $blob['recent'][0]['slug'], 'carrying the slug' );
ok( 'dispatched' === $blob['recent'][0]['outcome'], 'and the outcome' );
ok( is_string( $blob['last_used'] ) && '' !== $blob['last_used'], 'and last_used is now set' );

echo "Group: the recorded timestamp also comes from wp_date\n";
// Task 1 pinned the DAY KEY by call; this pins the TIMESTAMP the same way. A
// gmdate swap in sn_mcp_remote_log_now() would otherwise be invisible — on a
// UTC server the strings are identical, so only the call log can tell.
$GLOBALS['__wp_date_calls'] = array();
sn_mcp_remote_log_apply( 'dispatched', 'slug-x' );
ok( in_array( 'Y-m-d H:i:s', $GLOBALS['__wp_date_calls'], true ), 'THE OTHER TIMEZONE PIN: the ring timestamp is produced by wp_date too' );

echo "Group: only a DISPATCH sets last_used — a refusal is not a use\n";
// Without this, last_used answers "was this endpoint touched", which is a
// different and much less alarming question than "did someone get data out".
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'refused_auth', '' );
$blob = sn_mcp_remote_log_get_blob();
ok( null === $blob['last_used'], 'THE ONE THAT MATTERS: a refusal leaves last_used null' );
ok( 1 === $blob['counters'][ $today ]['refused_auth'], 'while still counting the refusal' );

echo "Group: an unknown outcome is dropped, not stored\n";
// Mirrors SN_AUDIT_COUNTER_TYPES' guard. A typo'd outcome silently creating a
// key would produce a counter no label ever reads.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'not_a_real_outcome', '' );
$blob = sn_mcp_remote_log_get_blob();
ok( array() === $blob['counters'], 'an unknown outcome creates no counter' );
ok( array() === $blob['recent'], 'and no ring row' );

echo "Group: the ring is capped, and NEWEST FIRST\n";
// Asserting only "count <= cap" cannot tell a working cap from a ring that
// never filled. Overfill it, then assert the oldest is GONE and the newest is
// at index 0 — those two together are what pin the behaviour.
$GLOBALS['__options'] = array();
for ( $i = 0; $i < SN_MCP_REMOTE_LOG_RING_CAP + 5; $i++ ) {
	sn_mcp_remote_log_apply( 'dispatched', 'slug-' . $i );
}
$blob = sn_mcp_remote_log_get_blob();
ok( SN_MCP_REMOTE_LOG_RING_CAP === count( $blob['recent'] ), 'the ring stops at the cap' );
ok( 'slug-' . ( SN_MCP_REMOTE_LOG_RING_CAP + 4 ) === $blob['recent'][0]['slug'], 'THE ORDER PIN: the newest entry is at index 0' );
$slugs = array();
foreach ( $blob['recent'] as $row ) { $slugs[] = $row['slug']; }
ok( ! in_array( 'slug-0', $slugs, true ), 'THE EVICTION PIN: the oldest entry is gone, so the cap evicts rather than refusing to append' );
ok( SN_MCP_REMOTE_LOG_RING_CAP + 5 === $blob['counters'][ $today ]['dispatched'], 'and the COUNTER kept counting past the ring cap — the ring is a display aid, the counter is the record' );
ok( is_string( $blob['last_used'] ) && '' !== $blob['last_used'], 'THE OTHER DENORMALISATION PIN: last_used survives the ring rolling over — it is stored outside the ring precisely so it can' );

echo "Group: old day-buckets are dropped on write, and recent ones are KEPT\n";
// A prune asserted only by "the old bucket is gone" is satisfied by a prune
// that deletes everything. The keep-assertion is the discriminator.
$GLOBALS['__options'] = array();
$old    = wp_date( 'Y-m-d', time() - ( ( SN_MCP_REMOTE_LOG_RETENTION_DAYS + 5 ) * DAY_IN_SECONDS ) );
$recent = wp_date( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) );
$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = array(
	'schema'    => 1,
	'last_used' => '2020-01-01 00:00:00',
	'counters'  => array(
		$old    => array( 'dispatched' => 7 ),
		$recent => array( 'dispatched' => 2 ),
	),
	'recent'    => array(),
);
sn_mcp_remote_log_apply( 'dispatched', 'slug' );
$blob = sn_mcp_remote_log_get_blob();
ok( ! array_key_exists( $old, $blob['counters'] ), 'a bucket past retention is dropped' );
ok( array_key_exists( $recent, $blob['counters'] ), 'THE DISCRIMINATOR: a bucket inside retention survives' );
ok( 2 === $blob['counters'][ $recent ]['dispatched'], 'with its count intact' );

echo "Group: last_used survives a prune that removes its own day\n";
// last_used is denormalised out of the ring and the counters precisely so it
// can outlive both. Nothing else proves it does.
ok( is_string( $blob['last_used'] ), 'THE DENORMALISATION PIN: last_used is still set after pruning' );

echo "Group: the pure prune predicate is exhaustive on the boundary\n";
// Testing the predicate directly rather than only through a write, so the
// off-by-one at the cutoff has its own witness.
ok( true  === sn_mcp_remote_log_is_expired( '2026-01-01', '2026-01-02' ), 'a day strictly before the cutoff is expired' );
ok( false === sn_mcp_remote_log_is_expired( '2026-01-02', '2026-01-02' ), 'THE BOUNDARY: the cutoff day itself is NOT expired' );
ok( false === sn_mcp_remote_log_is_expired( '2026-01-03', '2026-01-02' ), 'a day after the cutoff is not expired' );
ok( false === sn_mcp_remote_log_is_expired( 'garbage', '2026-01-02' ), 'and an unparseable key is KEPT, not silently deleted' );

echo "Group: a stored slug is BOUNDED, whatever the caller passes\n";
// From Task 6 on the slug originates in an unauthenticated request body. The
// bound lives HERE, in the module, because caller-ordering guarantees are
// invisible to this file and its header promises isolation.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'dispatched', str_repeat( 'a', 5000 ) );
$blob = sn_mcp_remote_log_get_blob();
ok( 191 === strlen( $blob['recent'][0]['slug'] ), 'THE BOUND PIN: a 5000-char slug is stored truncated to 191' );
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'dispatched', array( 'not', 'a', 'string' ) );
$blob = sn_mcp_remote_log_get_blob();
ok( '' === $blob['recent'][0]['slug'], 'and a non-scalar slug stores as the empty string, not "Array" plus a PHP warning' );

echo "Group: the flush predicate is pure and covers all four combinations\n";
// Truth table, exhaustively. A predicate tested on two of four combinations is
// satisfied by `return $is_dispatch;` — which would never flush a pure-refusal
// probe at all, the exact case this buffer exists for.
ok( true  === sn_mcp_remote_should_flush( 999, false ), 'stale buffer, no dispatch -> flush' );
ok( true  === sn_mcp_remote_should_flush( 0,   true  ), 'fresh buffer, dispatch -> flush (it is writing anyway)' );
ok( true  === sn_mcp_remote_should_flush( 999, true  ), 'stale buffer, dispatch -> flush' );
ok( false === sn_mcp_remote_should_flush( 0,   false ), 'THE ONE THAT MAKES IT A BUFFER: fresh buffer, no dispatch -> hold' );

echo "Group: a refusal buffers instead of writing the option\n";
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'refused_auth', '' );
ok( ! array_key_exists( SN_MCP_REMOTE_LOG_OPTION, $GLOBALS['__options'] ), 'THE ONE THAT MATTERS FOR A FLOOD: a single refusal writes NO option' );
ok( array_key_exists( SN_MCP_REMOTE_PENDING_TRANSIENT, $GLOBALS['__transients'] ), 'it lands in the pending transient instead' );
ok( 1 === $GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ]['counts']['refused_auth'], 'with a count of one' );

sn_mcp_remote_record( 'refused_auth', '' );
sn_mcp_remote_record( 'refused_auth', '' );
ok( 3 === $GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ]['counts']['refused_auth'], 'and further refusals accumulate there — three requests, still zero option writes' );
ok( ! array_key_exists( SN_MCP_REMOTE_LOG_OPTION, $GLOBALS['__options'] ), 'confirmed: still no option write after three refusals' );

echo "Group: the pending TTL is far longer than the flush window\n";
// Nothing SCHEDULES a flush. If a probe stops, the tail sits here until an
// admin read collects it. A TTL near the flush window would discard exactly
// the counts most worth having.
ok( $GLOBALS['__ttls'][ SN_MCP_REMOTE_PENDING_TRANSIENT ] > SN_MCP_REMOTE_FLUSH_SECONDS * 10, 'THE TAIL-LOSS PIN: the pending TTL is more than ten flush windows' );

echo "Group: a dispatch flushes the buffer along with itself\n";
$today = sn_mcp_remote_log_day_key();
sn_mcp_remote_record( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
$blob = sn_mcp_remote_log_get_blob();
ok( 3 === $blob['counters'][ $today ]['refused_auth'], 'the three buffered refusals landed in the persisted counters' );
ok( 1 === $blob['counters'][ $today ]['dispatched'], 'alongside the dispatch that flushed them' );
ok( ! array_key_exists( SN_MCP_REMOTE_PENDING_TRANSIENT, $GLOBALS['__transients'] ), 'and the buffer was cleared, so nothing double-counts' );

echo "Group: a pending set files under the day it was RECORDED, not flushed\n";
// The midnight bug. A set recorded at 23:59:58 and flushed at 00:00:05 belongs
// to the day it was recorded; recomputing the key at flush time would file it
// under the wrong date and understate the busy day.
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ] = array(
	'day'        => $yesterday,
	'first_seen' => time() - 3600,
	'counts'     => array( 'refused_auth' => 4 ),
);
sn_mcp_remote_record( 'dispatched', 'slug' );
$blob = sn_mcp_remote_log_get_blob();
ok( 4 === $blob['counters'][ $yesterday ]['refused_auth'], 'THE MIDNIGHT PIN: buffered counts land in YESTERDAY\'s bucket' );
ok( ! isset( $blob['counters'][ sn_mcp_remote_log_day_key() ]['refused_auth'] ), 'and not in today\'s' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-remote-observability.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-remote-observability.php\n";
exit( $fail > 0 ? 1 : 0 );
