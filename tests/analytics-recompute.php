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
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['o'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['t'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t'][ $k ] ); return true; }
function wp_schedule_single_event( $ts, $h ) { $GLOBALS['sched'][] = $h; return true; }
function wp_clear_scheduled_hook() {} function wp_next_scheduled() { return false; }
function home_url() { return 'https://example.test/'; } function wp_parse_url( $u, $c ) { return parse_url( $u, $c ); }
function is_wp_error() { return false; }
function wp_remote_retrieve_response_code( $r ) { return 200; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_post( $url, $args ) {
	$sql = $args['body'];
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

foreach ( array( 'api', 'human-rule', 'rollup', 'dims', 'utm', 'buckets', 'pageroles', 'events-rollup', 'session-rollup', 'recompute' ) as $f ) {
	require_once dirname( __DIR__ ) . "/inc/analytics-{$f}.php";
}

// ── The action refuses without nonce / capability (the shared dispatcher). ──
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/admin-post-handler.php' );
$fn  = strstr( $src, 'function sn_handle_admin_post(' );
ok( false !== strpos( $src, "'analytics_recompute'        => 'sn_handle_analytics_recompute'" ), 'dispatcher maps analytics_recompute to its handler' );
ok( strpos( $fn, "check_admin_referer( 'sn_' . \$action )" ) < strpos( $fn, 'call_user_func' ) && strpos( $fn, "current_user_can( 'manage_options' )" ) < strpos( $fn, 'call_user_func' ), 'dispatcher checks the nonce and manage_options before the handler runs' );
$rsrc = (string) file_get_contents( dirname( __DIR__ ) . '/inc/analytics-recompute.php' );
ok( false !== strpos( $rsrc, "wp_nonce_field( 'sn_analytics_recompute' )" ), 'the button mints the sn_analytics_recompute nonce' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/analytics-render-overview.php' ), 'snt_analytics_render_recompute();' ), 'the shared human-rule renderer prints the button (every surface that shows the note)' );

// ── Batches: 90 days oldest first, 7 at a time, last one unbounded. ──
ok( array( 'days' => 90, 'until' => 83 ) === sn_analytics_recompute_batch( 0, 90 ), 'first batch is days 90..84 ago' );
ok( array( 'days' => 6, 'until' => 0 ) === sn_analytics_recompute_batch( 84, 90 ), 'last batch reaches today' );

// ── A full run. ──
ok( 'analytics_recompute_started' === sn_analytics_recompute_start(), 'start schedules a run' );
ok( 'analytics_recompute_busy' === sn_analytics_recompute_start(), 'a second click while running is refused' );
$ticks = 0;
while ( 'running' === sn_analytics_recompute_status()['state'] && $ticks < 20 ) { sn_analytics_recompute_tick(); ++$ticks; }
$st = sn_analytics_recompute_status();
ok( 13 === $ticks && 'done' === $st['state'] && 90 === $st['done'], "13 batches cover 90 days (ticks={$ticks}, done={$st['done']})" );
ok( array( 'state', 'done', 'total', 'through', 'error', 'started', 'last_tick' ) === array_keys( $st ), 'progress option shape' );
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
sn_analytics_recompute_tick();
$st = sn_analytics_recompute_status();
ok( 'partial' === $st['state'] && 0 === $st['done'] && false !== strpos( $st['error'], 'truncated' ), 'truncation marks the run partial: ' . $st['error'] );
$after = array_slice( $GLOBALS['sql'], array_search( true, array_map( function ( $s ) { return false !== strpos( $s, 'blob20 AS packed' ); }, $GLOBALS['sql'] ), true ) + 1 );
ok( array() === $after, 'no AE read (so no write) happens after the truncated one' );
ok( array() === preg_grep( '/utm/', $GLOBALS['wpdb']->tables ), 'the truncated UTM result was not written (tables written: ' . implode( ',', array_unique( $GLOBALS['wpdb']->tables ) ) . ')' );
$GLOBALS['truncate'] = '';
ok( false === strpos( sn_analytics_recompute_line( $st ), 'Recomputed through' ) && false !== strpos( sn_analytics_recompute_line( $st ), 'Run it again' ), 'status line names the failure' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
