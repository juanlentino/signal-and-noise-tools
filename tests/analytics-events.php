<?php
/**
 * Tests for inc/analytics-events.php — durable custom-events + event-props tables,
 * install, upsert, and read accessors.
 * Run: php tests/analytics-events.php
 * @since plugin v6.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) {} }

class EV_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		// Count rows from VALUES clauses for upsert counting.
		preg_match_all( '/\(%s,\s*%s,\s*%d,\s*%d\)/', $sql, $m );
		return true;
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		// Determine table from FROM clause.
		if ( ! preg_match( '/FROM\s+(\S+)/i', $sql, $tm ) ) { return array(); }
		$table = $tm[1];
		$rows  = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();

		// GROUP BY name (events table).
		if ( stripos( $sql, 'GROUP BY name' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$key = (string) $r['name'];
				if ( ! isset( $agg[ $key ] ) ) { $agg[ $key ] = array( 'name' => $key, 'events' => 0, 'visitors' => 0 ); }
				$agg[ $key ]['events']   += (int) $r['events'];
				$agg[ $key ]['visitors'] += (int) $r['visitors'];
			}
			usort( $agg, function ( $a, $b ) { return (int) $b['events'] - (int) $a['events']; } );
			return array_values( $agg );
		}

		// GROUP BY property, value (event_props table).
		if ( stripos( $sql, 'GROUP BY property, value' ) !== false ) {
			$agg = array();
			// Filter by property if AND property = '...' present.
			if ( preg_match( "/AND property = '([^']*)'/i", $sql, $pm ) ) {
				$prop = $pm[1];
				$rows = array_values( array_filter( $rows, function ( $r ) use ( $prop ) { return (string) $r['property'] === $prop; } ) );
			}
			foreach ( $rows as $r ) {
				$key = (string) $r['property'] . '||' . (string) $r['value'];
				if ( ! isset( $agg[ $key ] ) ) { $agg[ $key ] = array( 'property' => (string) $r['property'], 'value' => (string) $r['value'], 'events' => 0, 'visitors' => 0 ); }
				$agg[ $key ]['events']   += (int) $r['events'];
				$agg[ $key ]['visitors'] += (int) $r['visitors'];
			}
			usort( $agg, function ( $a, $b ) { return (int) $b['events'] - (int) $a['events']; } );
			return array_values( $agg );
		}

		return $rows;
	}
}
$GLOBALS['wpdb'] = new EV_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-events.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "analytics-events: durable tables + accessors\n\n";

// ── Schema SQL ────────────────────────────────────────────────────────────────
echo "Group: schema_sql\n";
$sql = sn_analytics_events_schema_sql();
ok( is_array( $sql ) && isset( $sql['events'], $sql['event_props'] ), 'schema_sql: returns array with events + event_props keys' );
ok( strpos( $sql['events'], 'UNIQUE KEY day_name' ) !== false, 'schema_sql[events]: contains UNIQUE KEY day_name' );
ok( strpos( $sql['event_props'], 'UNIQUE KEY day_prop_value' ) !== false, 'schema_sql[event_props]: contains UNIQUE KEY day_prop_value' );
ok( strpos( $sql['events'], 'sn_analytics_events' ) !== false, 'schema_sql[events]: references events table name' );
ok( strpos( $sql['event_props'], 'sn_analytics_event_props' ) !== false, 'schema_sql[event_props]: references event_props table name' );
ok( strpos( $sql['events'], 'name VARCHAR(120)' ) !== false, 'schema_sql[events]: name VARCHAR(120)' );
ok( strpos( $sql['event_props'], 'property VARCHAR(60)' ) !== false, 'schema_sql[event_props]: property VARCHAR(60)' );
ok( strpos( $sql['event_props'], 'value VARCHAR(180)' ) !== false, 'schema_sql[event_props]: value VARCHAR(180)' );

// ── Constants ─────────────────────────────────────────────────────────────────
echo "\nGroup: constants\n";
ok( defined( 'SN_ANALYTICS_EVENTS_TABLE' ) && SN_ANALYTICS_EVENTS_TABLE === 'sn_analytics_events', 'SN_ANALYTICS_EVENTS_TABLE constant defined' );
ok( defined( 'SN_ANALYTICS_EVENT_PROPS_TABLE' ) && SN_ANALYTICS_EVENT_PROPS_TABLE === 'sn_analytics_event_props', 'SN_ANALYTICS_EVENT_PROPS_TABLE constant defined' );
ok( defined( 'SN_ANALYTICS_EVENTS_DB_VERSION' ), 'SN_ANALYTICS_EVENTS_DB_VERSION constant defined' );
ok( defined( 'SN_ANALYTICS_EVENT_PROPS_DB_VERSION' ), 'SN_ANALYTICS_EVENT_PROPS_DB_VERSION constant defined' );
ok( defined( 'SN_ANALYTICS_EVENTS_DB_VERSION_OPT' ), 'SN_ANALYTICS_EVENTS_DB_VERSION_OPT constant defined' );
ok( defined( 'SN_ANALYTICS_EVENT_PROPS_DB_VERSION_OPT' ), 'SN_ANALYTICS_EVENT_PROPS_DB_VERSION_OPT constant defined' );

// ── events_upsert ─────────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_events_upsert\n";
$GLOBALS['wpdb']->queries = array();
$rows_ev = array(
	array( 'day' => '2026-05-10', 'name' => 'click', 'visitors' => 5, 'events' => 10 ),
	array( 'day' => '2026-05-11', 'name' => 'scroll', 'visitors' => 3, 'events' => 6 ),
);
$written = sn_analytics_events_upsert( $rows_ev );
ok( $written === 2, 'events_upsert: returns count of rows written' );
$last_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $last_sql, 'ON DUPLICATE KEY UPDATE' ) !== false, 'events_upsert: SQL contains ON DUPLICATE KEY UPDATE' );
ok( strpos( $last_sql, 'wp_sn_analytics_events' ) !== false, 'events_upsert: targets correct table' );
ok( strpos( $last_sql, "INSERT INTO" ) !== false, 'events_upsert: uses INSERT INTO' );
// Verify prepare was called (quotes around string placeholders).
ok( strpos( $last_sql, "'2026-05-" ) !== false, 'events_upsert: day bound via prepare (quoted string)' );
ok( sn_analytics_events_upsert( array() ) === 0, 'events_upsert: empty input returns 0' );
ok( sn_analytics_events_upsert( 'not-array' ) === 0, 'events_upsert: non-array input returns 0' );

// ── event_props_upsert ────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_event_props_upsert\n";
$GLOBALS['wpdb']->queries = array();
$rows_pr = array(
	array( 'day' => '2026-05-10', 'property' => 'plan', 'value' => 'pro', 'visitors' => 2, 'events' => 4 ),
	array( 'day' => '2026-05-10', 'property' => 'plan', 'value' => 'free', 'visitors' => 8, 'events' => 20 ),
);
$written2 = sn_analytics_event_props_upsert( $rows_pr );
ok( $written2 === 2, 'event_props_upsert: returns count of rows written' );
$last_sql2 = end( $GLOBALS['wpdb']->queries );
ok( strpos( $last_sql2, 'ON DUPLICATE KEY UPDATE' ) !== false, 'event_props_upsert: SQL contains ON DUPLICATE KEY UPDATE' );
ok( strpos( $last_sql2, 'wp_sn_analytics_event_props' ) !== false, 'event_props_upsert: targets correct table' );
ok( strpos( $last_sql2, "'pro'" ) !== false || strpos( $last_sql2, "'free'" ) !== false, 'event_props_upsert: value bound via prepare' );

// ── top_events read accessor ──────────────────────────────────────────────────
echo "\nGroup: sn_analytics_top_events\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_events'] = array(
	array( 'name' => 'click',      'day' => '2026-05-10', 'visitors' => 5,  'events' => 10 ),
	array( 'name' => 'click',      'day' => '2026-05-11', 'visitors' => 3,  'events' => 8  ),
	array( 'name' => 'engagement', 'day' => '2026-05-10', 'visitors' => 20, 'events' => 50 ),
);
$GLOBALS['wpdb']->queries = array();
$top = sn_analytics_top_events( '2026-05-01', '2026-05-31' );
$top_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $top_sql, 'GROUP BY name' ) !== false, 'top_events: SQL contains GROUP BY name' );
ok( strpos( $top_sql, 'ORDER BY events DESC' ) !== false, 'top_events: SQL contains ORDER BY events DESC' );
ok( strpos( $top_sql, 'SUM(events)' ) !== false, 'top_events: SQL uses SUM(events)' );
ok( strpos( $top_sql, 'SUM(visitors)' ) !== false, 'top_events: SQL uses SUM(visitors)' );
ok( is_array( $top ) && count( $top ) === 2, 'top_events: returns 2 grouped rows' );
ok( $top[0]['name'] === 'engagement' && (int) $top[0]['events'] === 50, 'top_events: engagement first (50 events)' );
ok( (int) $top[1]['events'] === 18, 'top_events: click aggregated to 18 events' );
ok( isset( $top[0]['visitors'] ) && is_int( $top[0]['visitors'] ), 'top_events: visitors is int' );
// Limit clamping: verify %d in SQL for LIMIT.
$GLOBALS['wpdb']->queries = array();
sn_analytics_top_events( '2026-05-01', '2026-05-31', 5 );
$lsql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $lsql, 'LIMIT 5' ) !== false, 'top_events: LIMIT passed via prepare' );

// ── top_event_props read accessor ─────────────────────────────────────────────
echo "\nGroup: sn_analytics_top_event_props\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_event_props'] = array(
	array( 'property' => 'plan', 'value' => 'pro',  'day' => '2026-05-10', 'visitors' => 2, 'events' => 4  ),
	array( 'property' => 'plan', 'value' => 'free', 'day' => '2026-05-10', 'visitors' => 8, 'events' => 20 ),
	array( 'property' => 'color', 'value' => 'red', 'day' => '2026-05-10', 'visitors' => 1, 'events' => 3  ),
);
$GLOBALS['wpdb']->queries = array();
$all_props = sn_analytics_top_event_props( '2026-05-01', '2026-05-31' );
$props_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $props_sql, 'GROUP BY property, value' ) !== false, 'top_event_props: SQL contains GROUP BY property, value' );
ok( strpos( $props_sql, 'ORDER BY events DESC' ) !== false, 'top_event_props: SQL contains ORDER BY events DESC' );
ok( strpos( $props_sql, 'AND property =' ) === false, 'top_event_props: no property filter when empty string passed' );
ok( is_array( $all_props ) && count( $all_props ) === 3, 'top_event_props: returns all 3 rows without filter' );

// With property filter.
$GLOBALS['wpdb']->queries = array();
$plan_props = sn_analytics_top_event_props( '2026-05-01', '2026-05-31', 'plan' );
$plan_sql   = end( $GLOBALS['wpdb']->queries );
ok( strpos( $plan_sql, 'AND property =' ) !== false, 'top_event_props: adds AND property = when non-empty property passed' );
ok( is_array( $plan_props ) && count( $plan_props ) === 2, 'top_event_props: filters to plan property rows only' );
ok( $plan_props[0]['value'] === 'free' && (int) $plan_props[0]['events'] === 20, 'top_event_props: ordered by events desc (free=20 first)' );
ok( isset( $plan_props[0]['property'] ) && $plan_props[0]['property'] === 'plan', 'top_event_props: property field present' );
ok( isset( $plan_props[0]['events'] ) && is_int( $plan_props[0]['events'] ), 'top_event_props: events is int' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
