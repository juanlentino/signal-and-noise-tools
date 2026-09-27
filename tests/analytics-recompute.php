<?php
/**
 * The owner-run history recompute: refuses without nonce/capability, chunks 90
 * days into 7-day batches that run the nightly's own builders (every AE read
 * carries the over-cap exclusion and a bounded window), stops a truncated
 * batch as partial without writing, and keeps the progress option's shape.
 * Real modules; only WP and the AE transport are stubbed.
 * Run: php tests/analytics-recompute.php
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/tmp/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_CF_ANALYTICS_TOKEN', 't' ); define( 'SN_CF_ACCOUNT_ID', 'a' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; $c ? ++$pass : ++$fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; }

$GLOBALS['o'] = array(); $GLOBALS['t'] = array(); $GLOBALS['sched'] = array(); $GLOBALS['sql'] = array(); $GLOBALS['truncate'] = '';
function add_action() {} function __( $s ) { return $s; } function esc_html__( $s ) { return $s; }
function esc_html( $s ) { return $s; } function esc_attr( $s ) { return $s; } function esc_url( $s ) { return $s; } function current_user_can() { return true; }
function sn_admin_post_url( $a ) { return '/wp-admin/admin-post.php'; } function wp_nonce_field( $n ) { echo '<nonce ' . $n . '>'; }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['o'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t'][ $k ] ); return true; }
function wp_schedule_single_event( $ts, $h ) { $GLOBALS['sched'][] = $h; return true; }
function wp_clear_scheduled_hook() {} function wp_next_scheduled( $h ) { return in_array( $h, $GLOBALS['sched'], true ) ? 1 : false; }
function home_url() { return 'https://example.test/'; } function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code( $r ) { return 200; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_post( $url, $args ) {
	$sql = $args['body'];
	if ( ! empty( $GLOBALS['explode'] ) && false !== strpos( $sql, $GLOBALS['explode'] ) ) { throw new Error( 'simulated death inside the unit' ); }
	if ( false !== strpos( $sql, sn_analytics_overcap_sql() ) ) {
		return array( 'body' => json_encode( array( 'data' => array( array( 'vid' => 'abcdef0123' ) ), 'rows' => 1 ) ) );
	}
	$GLOBALS['sql'][] = $sql;
	$cut = '' !== $GLOBALS['truncate'] && false !== strpos( $sql, $GLOBALS['truncate'] );
	$row = array( 'day' => '2026-07-01', 'path' => '/a', 'class' => 'human', 'views' => 3, 'visits' => 1, 'value' => 'x', 'packed' => 'g|c|n', 'bucket' => '01', 'name' => 'e', 'events' => 1, 'visitors' => 1, 'property' => 'p' );
	return array( 'body' => json_encode( array( 'data' => array( $row ), 'rows' => $cut ? 10000 : 1, 'rows_before_limit_at_least' => $cut ? 20000 : 1 ) ) );
}
class Wpdb { public $prefix = 'wp_'; public $last_error = ''; public $writes = 0; public $tables = array();
	function prepare( $q ) { return $q; } function query( $q ) { if ( 0 === stripos( ltrim( $q ), 'INSERT' ) ) { ++$this->writes; $this->tables[] = strtok( substr( ltrim( $q ), 12 ), ' ' ); } return 1; }
	function get_var() { return 0; } function get_results() { return array(); } function esc_like( $s ) { return $s; } }
$GLOBALS['wpdb'] = new Wpdb();
// Session events live in inc/analytics-sessions.php; stub the fetch but keep it an AE read through the rule.
function sn_analytics_fetch_session_events( $from, $to, $class ) {
	$rows = sn_analytics_query( "SELECT * FROM sn_pageviews WHERE " . sn_analytics_class_where( $class ) . " AND day = '{$from}'" );
	return is_array( $rows ) ? array( 'summaries' => array(), 'visits' => array(), 'capped' => false, 'configured' => true ) : array( 'configured' => false );
}
function sn_session_metrics() { return array( 'visits' => 1, 'bounce_rate' => 0, 'pages_per_visit' => 1, 'median_duration' => 1 ); }

foreach ( array( 'api', 'human-rule', 'rollup', 'dims', 'utm', 'buckets', 'pageroles', 'events-rollup', 'session-rollup', 'recompute', 'recompute-status' ) as $f ) {
	require_once dirname( __DIR__ ) . "/inc/analytics-{$f}.php";
}
// Fake clock: time moves only while a unit runs, unit_secs per unit. 41 s keeps
// the legacy sections at one unit per tick (41 + 41 > the 40 s budget).
$GLOBALS['unit_secs'] = 41; $GLOBALS['clock'] = 0.0;
sn_analytics_recompute_clock( function () { if ( '' !== sn_analytics_recompute_in_unit() ) { $GLOBALS['clock'] += $GLOBALS['unit_secs']; } return $GLOBALS['clock']; } );

// ── The action refuses without nonce / capability (the shared dispatcher). ──
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/admin-post-handler.php' );
$fn  = strstr( $src, 'function sn_handle_admin_post(' );
ok( false !== strpos( $src, "'analytics_recompute'        => 'sn_handle_analytics_recompute'" ), 'dispatcher maps analytics_recompute to its handler' );
ok( strpos( $fn, "check_admin_referer( 'sn_' . \$action )" ) < strpos( $fn, 'call_user_func' ) && strpos( $fn, "current_user_can( 'manage_options' )" ) < strpos( $fn, 'call_user_func' ), 'dispatcher checks the nonce and manage_options before the handler runs' );
$rsrc = (string) file_get_contents( dirname( __DIR__ ) . '/inc/analytics-recompute-status.php' );
ok( false !== strpos( $rsrc, "wp_nonce_field( 'sn_analytics_recompute' )" ), 'the button mints the sn_analytics_recompute nonce' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/analytics-render-overview.php' ), 'snt_analytics_render_recompute();' ), 'the shared human-rule renderer prints the button (every surface that shows the note)' );

// ── Batches: 90 days oldest first, 7 at a time, last one unbounded. ──
ok( array( 'days' => 90, 'until' => 83 ) === sn_analytics_recompute_batch( 0, 90 ), 'first batch is days 90..84 ago' );
ok( array( 'days' => 6, 'until' => 0 ) === sn_analytics_recompute_batch( 84, 90 ), 'last batch reaches today' );

// ── A full run. ──
ok( 'analytics_recompute_started' === sn_analytics_recompute_start(), 'start schedules a run' );
ok( 'analytics_recompute_busy' === sn_analytics_recompute_start(), 'a second click while running is refused' );
// One tick = one unit, and it schedules the next.
$GLOBALS['sched'] = array(); $n0 = count( $GLOBALS['sql'] );
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
$first = array_slice( $GLOBALS['sql'], $n0 );
ok( 'pageviews' === $st['unit'] && 1 === $st['step'] && 0 === $st['done'] && 'running' === $st['state'], 'the first tick ran the pageviews unit only and moved the cursor to step 1' );
ok( array() === array_filter( $first, function ( $s ) { return false !== strpos( $s, 'blob20 AS packed' ) || false !== strpos( $s, 'blob16 AS name' ) || false !== strpos( $s, "AND day = '" ); } ), 'no other family or session day was read in that tick (' . count( $first ) . ' reads)' );
ok( array( SNT_ANALYTICS_RECOMPUTE_HOOK ) === $GLOBALS['sched'], 'the tick scheduled exactly one next tick' );
ok( '' === sn_analytics_recompute_in_unit() && false === sn_analytics_strict()['on'], 'the tick reached its end marker and put strict mode back' );
// The cursor walks every unit of every batch, in order.
$seen = array( $st['unit'] ); $ticks = 1;
while ( 'running' === sn_analytics_recompute_status()['state'] && $ticks < 400 ) { sn_analytics_recompute_tick(); $seen[] = sn_analytics_recompute_status()['unit']; ++$ticks; }
$want = array();
for ( $d = 0; $d < 90; ) { $b = sn_analytics_recompute_batch( $d, 90 ); $want = array_merge( $want, sn_analytics_recompute_units( $b ) ); $d += $b['days'] - $b['until']; }
$st = sn_analytics_recompute_status();
ok( 168 === count( $want ) && $want === $seen, 'the cursor ran all ' . count( $want ) . ' units of the 13 batches, once each, in order (ticks=' . $ticks . ')' );
ok( 'done' === $st['state'] && 90 === $st['done'] && 0 === $st['step'], "13 batches cover 90 days (done={$st['done']})" );
ok( array( 'state', 'done', 'total', 'through', 'error', 'started', 'last_tick', 'step', 'unit' ) === array_keys( $st ), 'progress option shape' );
ok( 'Recomputed through ' . gmdate( 'Y-m-d' ) . ', 90 of 90 days.' === sn_analytics_recompute_line( $st ), 'status line: ' . sn_analytics_recompute_line( $st ) );
$sqls = $GLOBALS['sql'];
$no_rule = array_filter( $sqls, function ( $s ) { return false === strpos( $s, "index1 NOT IN ('abcdef0123')" ) && false === strpos( $s, "OR index1 IN ('abcdef0123')" ); } );
ok( count( $sqls ) > 0 && array() === $no_rule, 'every rollup read applies the over-cap rule (human/suspect exclude it, bot adds it; ' . count( $sqls ) . ' reads)' );
foreach ( array( 'sn_analytics_rollup_sql' => 'sumIf(_sample_interval, blob1 = \'pv\') AS views', 'gated' => 'AS pageview_visits', 'dims' => 'AS value,', 'utm' => 'blob20 AS packed', 'hour' => "'%H') AS bucket", 'pageroles' => "blob3 NOT IN", 'events' => 'blob16 AS name', 'props' => 'blob17 AS property', 'sessions' => "AND day = '" ) as $k => $needle ) {
	$hits = array_filter( $sqls, function ( $s ) use ( $needle ) { return false !== strpos( $s, $needle ); } );
	ok( count( $hits ) >= 13, "builder {$k} ran in every batch (" . count( $hits ) . ')' );
	if ( 'sessions' !== $k ) {
		$b = array_filter( $hits, function ( $s ) { return false !== strpos( $s, 'AND timestamp < toStartOf' ); } );
		ok( count( $b ) >= 12, "builder {$k} is bounded in the 12 non-final batches (" . count( $b ) . ')' );
	}
}
$bounded = array_filter( $sqls, function ( $s ) { return false !== strpos( $s, "AND timestamp < toStartOf" ); } );
ok( count( $bounded ) > 0 && false !== strpos( implode( ' ', $sqls ), "INTERVAL '90' DAY" ), 'batches are bounded windows reaching 90 days back' );
ok( '' === sn_analytics_window_upper() && false === strpos( sn_analytics_rollup_sql( 7 ), 'timestamp <' ), 'the nightly SQL is unbounded again after the run' );

// ── A truncated result stops the run as partial, before any write. ──
$GLOBALS['o'] = array(); $GLOBALS['sql'] = array(); $GLOBALS['truncate'] = 'blob20 AS packed';
sn_analytics_recompute_start();
$GLOBALS['wpdb']->tables = array();
for ( $i = 0; $i < 10 && 'running' === sn_analytics_recompute_status()['state']; $i++ ) { sn_analytics_recompute_tick(); }
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && 0 === $st['done'] && 'utm' === $st['unit'] && 2 === $st['step'] && false !== strpos( $st['error'], 'truncated' ), 'truncation marks the run partial at the utm unit without advancing: ' . $st['error'] );
$after = array_slice( $GLOBALS['sql'], array_search( true, array_map( function ( $s ) { return false !== strpos( $s, 'blob20 AS packed' ); }, $GLOBALS['sql'] ), true ) + 1 );
ok( array() === $after, 'no AE read (so no write) happens after the truncated one' );
ok( array() === preg_grep( '/utm/', $GLOBALS['wpdb']->tables ), 'the truncated UTM result was not written (tables written: ' . implode( ',', array_unique( $GLOBALS['wpdb']->tables ) ) . ')' );
$GLOBALS['truncate'] = '';
ok( false === strpos( sn_analytics_recompute_line( $st ), 'Recomputed through' ) && false !== strpos( sn_analytics_recompute_line( $st ), 'Resume' ), 'status line names the failure' );
ok( 'analytics_recompute_resumed' === sn_handle_analytics_recompute( array( 'mode' => 'resume' ) ) && 2 === sn_analytics_recompute_status()['step'], 'Resume after a partial keeps the cursor (step 2)' );
sn_analytics_recompute_tick();
ok( 'utm' === sn_analytics_recompute_status()['unit'] && 3 === sn_analytics_recompute_status()['step'], 'the resumed run retried the utm unit and moved on' );

// ── A fatal caught at shutdown is written down as partial, naming the unit. ──
sn_analytics_recompute_in_unit( 'dims' ); sn_analytics_strict( 'on' ); sn_analytics_rollup_window( array( 'days' => 90, 'until' => 83 ) );
sn_analytics_recompute_on_shutdown( array( 'type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x/inc/analytics-dims.php', 'line' => 42 ) );
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && false !== strpos( $st['error'], 'died in dims' ) && false !== strpos( $st['error'], 'Allowed memory size exhausted (analytics-dims.php:42)' ), 'a fatal at shutdown writes partial with the unit and message: ' . $st['error'] );
ok( false === sn_analytics_strict()['on'] && 0 === sn_analytics_rollup_window()['until'], 'the shutdown path puts strict mode and the window back' );
$before = sn_analytics_recompute_status();
sn_analytics_recompute_on_shutdown( array( 'type' => E_ERROR, 'message' => 'x' ) );
ok( $before === sn_analytics_recompute_status(), 'a shutdown after the end marker writes nothing' );

// ── A tick that dies before its end marker (simulated timeout mid-unit). ──
sn_analytics_recompute_start();
$GLOBALS['explode'] = 'blob20 AS packed';
for ( $i = 0; $i < 5; $i++ ) { try { sn_analytics_recompute_tick(); } catch ( Error $e ) { break; } }
$GLOBALS['explode'] = '';
ok( 'running' === sn_analytics_recompute_status()['state'] && 'utm' === sn_analytics_recompute_status()['unit'], 'the option names the unit the request was inside when it died' );
sn_analytics_recompute_on_shutdown( array( 'type' => E_WARNING, 'message' => 'not fatal' ) );
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && false !== strpos( $st['error'], 'died in utm' ) && false !== strpos( $st['error'], 'timeout or kill' ), 'an unfinished tick is recorded as partial: ' . $st['error'] );

// ── Stall: running with an old last_tick re-enables the button; Resume continues. ──
update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array( 'state' => 'running', 'done' => 14, 'total' => 90, 'through' => '', 'error' => '', 'started' => 1, 'last_tick' => time() - 1000, 'step' => 3, 'unit' => 'buckets' ) );
$st = sn_analytics_recompute_status();
ok( sn_analytics_recompute_stalled( $st ) && 0 === strpos( sn_analytics_recompute_line( $st ), 'Recompute stalled at buckets since ' ), 'status line: ' . sn_analytics_recompute_line( $st ) );
ob_start(); snt_analytics_render_recompute(); $html = ob_get_clean();
ok( false === strpos( $html, 'disabled' ) && false !== strpos( $html, 'Resume recompute' ) && false !== strpos( $html, 'name="mode" value="resume"' ), 'a stalled run renders an enabled Resume button' );
ok( 'analytics_recompute_resumed' === sn_handle_analytics_recompute( array( 'mode' => 'resume' ) ), 'Resume is accepted on a stalled run' );
$st = sn_analytics_recompute_status();
ok( 14 === $st['done'] && 3 === $st['step'] && 'running' === $st['state'] && ! sn_analytics_recompute_stalled( $st ), 'Resume keeps the cursor (14 days, step 3)' );
sn_analytics_recompute_tick();
ok( 'buckets' === sn_analytics_recompute_status()['unit'] && 4 === sn_analytics_recompute_status()['step'], 'the resumed tick ran the unit it stalled at' );
ob_start(); snt_analytics_render_recompute(); $html = ob_get_clean();
ok( false !== strpos( $html, 'disabled' ) && false === strpos( $html, 'Resume' ), 'a live run disables the button again' );
ok( 'analytics_recompute_busy' === sn_analytics_recompute_start( true ), 'a live run refuses a second start' );

// ── The sn-status{recompute} source. ──
$a = snt_ability_analytics_recompute_status();
ok( array( 'state', 'stalled', 'done', 'total', 'step', 'unit', 'through', 'error', 'started', 'last_tick', 'line' ) === array_keys( $a ) && 'buckets' === $a['unit'] && false === $a['stalled'] && is_int( $a['last_tick'] ), 'the recompute status payload shape' );

// ── The tick budget: units loop until ~40 s would be spent, then ONE reschedule. ──
$GLOBALS['unit_secs'] = 5;
$GLOBALS['o'] = array(); sn_analytics_recompute_start();
$GLOBALS['sched'] = array();
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
ok( 8 === $st['step'] && 0 === $st['done'] && 'session:89' === $st['unit'], "5 s units: one tick ran 8 units (step={$st['step']}, unit={$st['unit']})" );
ok( array( SNT_ANALYTICS_RECOMPUTE_HOOK ) === $GLOBALS['sched'], 'the tick rescheduled exactly once (' . count( $GLOBALS['sched'] ) . ')' );
sn_analytics_recompute_tick();
ok( array( SNT_ANALYTICS_RECOMPUTE_HOOK ) === $GLOBALS['sched'], 'a tick with a tick already pending adds no second one' );
// Resume an in-progress cursor mid-batch (the live run's shape).
update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array( 'state' => 'running', 'done' => 37, 'total' => 90, 'through' => '', 'error' => '', 'started' => 1, 'last_tick' => time(), 'step' => 3, 'unit' => 'utm' ) );
$GLOBALS['sched'] = array(); $n0 = count( $GLOBALS['sql'] );
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
$ran = array_slice( $GLOBALS['sql'], $n0 );
ok( 37 === $st['done'] && 11 === $st['step'] && 'session:49' === $st['unit'], "done=37 step=3 resumes at buckets and runs 8 units (done={$st['done']} step={$st['step']} unit={$st['unit']})" );
ok( array() === array_filter( $ran, function ( $s ) { return false !== strpos( $s, 'sumIf(_sample_interval, blob1 = \'pv\') AS views' ) || false !== strpos( $s, 'blob20 AS packed' ); } ), 'the resumed tick did not re-run the units before its cursor' );
// Crossing a batch boundary inside a tick advances done and keeps going.
update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array_merge( sn_analytics_recompute_status(), array( 'done' => 0, 'step' => 10 ) ) );
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
ok( 7 === $st['done'] && 5 === $st['step'], "a tick crosses a batch boundary (done={$st['done']} step={$st['step']})" );
// Strict mode trips on the 3rd unit of a tick: stop there, cursor at it, no reschedule.
update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array_merge( sn_analytics_recompute_status(), array( 'done' => 0, 'step' => 0 ) ) );
$GLOBALS['truncate'] = 'blob20 AS packed'; $GLOBALS['sched'] = array();
sn_analytics_recompute_tick();
$GLOBALS['truncate'] = '';
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && 'utm' === $st['unit'] && 2 === $st['step'] && 0 === $st['done'] && array() === $GLOBALS['sched'], "a strict trip mid-tick stops the loop at its unit (unit={$st['unit']} step={$st['step']}, scheduled=" . count( $GLOBALS['sched'] ) . ')' );
// A death on the 3rd unit of a tick names that unit.
sn_analytics_recompute_start( true );
$GLOBALS['explode'] = 'blob20 AS packed';
try { sn_analytics_recompute_tick(); } catch ( Error $e ) {}
$GLOBALS['explode'] = '';
sn_analytics_recompute_on_shutdown( null );
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && false !== strpos( $st['error'], 'died in utm' ) && 2 === $st['step'], 'a death on the 3rd unit of a tick is recorded against it: ' . $st['error'] );
// State leaves running mid-tick: the loop stops and does not overwrite it.
sn_analytics_recompute_start( true ); $GLOBALS['sched'] = array();
sn_analytics_recompute_clock( function () { if ( '' !== sn_analytics_recompute_in_unit() ) { $GLOBALS['clock'] += 5; update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array_merge( sn_analytics_recompute_status(), array( 'state' => 'partial', 'error' => 'owner stop' ) ) ); } return $GLOBALS['clock']; } );
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && 'owner stop' === $st['error'] && array() === $GLOBALS['sched'], 'a state change mid-tick stops the loop, is kept, and schedules nothing' );

// ── Partial writers re-read the option: units 1 and 2 of a tick stay done. ──
$snap_case = function ( $how ) {
	$GLOBALS['unit_secs'] = 5; $GLOBALS['clock'] = 0.0;
	sn_analytics_recompute_clock( function () { if ( '' !== sn_analytics_recompute_in_unit() ) { $GLOBALS['clock'] += $GLOBALS['unit_secs']; } return $GLOBALS['clock']; } );
	update_option( SNT_ANALYTICS_RECOMPUTE_OPT, array( 'state' => 'running', 'done' => 7, 'total' => 90, 'through' => '', 'error' => '', 'started' => 1, 'last_tick' => time(), 'step' => 0, 'unit' => '' ) );
	// Tick starts at step 0 (pageviews); units 1-2 succeed; unit 3 (utm) fails.
	if ( 'trip' === $how ) {
		$GLOBALS['truncate'] = 'blob20 AS packed'; sn_analytics_recompute_tick(); $GLOBALS['truncate'] = '';
	} else {
		$GLOBALS['explode'] = 'blob20 AS packed';
		try { sn_analytics_recompute_tick(); } catch ( Error $e ) {}
		$GLOBALS['explode'] = '';
		sn_analytics_recompute_on_shutdown( null );
	}
	return sn_analytics_recompute_status();
};
foreach ( array( 'trip' => 'a strict trip', 'death' => 'a death at shutdown' ) as $how => $label ) {
	$st = $snap_case( $how );
	ok( 'partial' === $st['state'] && 7 === $st['done'] && 2 === $st['step'] && 'utm' === $st['unit'], "{$label} on unit 3 keeps units 1-2 done (done={$st['done']} step={$st['step']} unit={$st['unit']}), not the tick's start cursor" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
