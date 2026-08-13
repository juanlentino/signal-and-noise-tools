<?php
/**
 * Standalone tests for the MCP telemetry READER (inc/mcp/mcp-telemetry-read.php).
 *
 * Run: php tests/mcp-telemetry-read.php
 *
 * The reader's whole job is refusing to collapse states that look alike:
 * absent table vs empty table vs failed query; unused vs unreachable; a
 * nominal window vs a measured one. Every group below pins one of those seams.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
// Real WP defines ARRAY_A as its own name; the reader passes it to get_results.
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }

/**
 * wpdb stand-in.
 *
 * Models the FAILURE shapes deliberately: `get_results()` returns false on a
 * query error, never an empty array, and `get_var()` returns null when SHOW
 * TABLES matches nothing. A stub that models only the success shape would let
 * the reader claim an unused corpus on a database error and the suite would
 * never notice.
 */
class SN_Test_Wpdb_Read {
	public $prefix       = 'wp_';
	public $rows         = array();
	public $table_exists = true;
	public $fail_query   = false;
	public $queries      = array();
	public function get_charset_collate() { return 'utf8mb4'; }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%s|%d/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i ] ?? ''; $i++;
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return $this->table_exists ? $this->prefix . SN_MCP_TELEMETRY_TABLE : null;
	}
	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		return $this->fail_query ? false : $this->rows;
	}
}
$GLOBALS['wpdb'] = new SN_Test_Wpdb_Read();
$wpdb            = $GLOBALS['wpdb'];

/** Abilities the fixture says resolve. Everything else is unresolvable. */
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $slug ) {
		if ( ! in_array( $slug, $GLOBALS['__abilities'], true ) ) { return null; }
		return new class( $slug ) {
			private $slug;
			public function __construct( $slug ) { $this->slug = $slug; }
			public function get_name() { return $this->slug; }
			public function get_label() { return 'Label'; }
			public function get_description() { return 'Desc'; }
			public function get_input_schema() { return array( 'type' => 'object' ); }
			public function get_output_schema() { return array(); }
		};
	}
}

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-telemetry.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-telemetry-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/** All allowlisted slugs resolve, unless a test says otherwise. */
function sn_test_all_reachable() {
	$GLOBALS['__abilities'] = array_merge( sn_mcp_allowlist(), sn_mcp_rw_allowlist() );
}
function sn_test_row( $tool, $calls, $first, $last, $door = 'read', $outcome = 'success' ) {
	return array(
		'tool_name'  => $tool,
		'door'       => $door,
		'outcome'    => $outcome,
		'calls'      => $calls,
		'first_seen' => $first,
		'last_seen'  => $last,
	);
}
$ago = function ( $days ) { return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ); };

sn_test_all_reachable();

echo "\nGroup: three ways to have no data, three different answers\n";
$wpdb->table_exists = false;
ok( null === sn_mcp_telemetry_usage(), 'missing table returns null, never an empty report' );

$wpdb->table_exists = true;
$wpdb->fail_query   = true;
ok( null === sn_mcp_telemetry_usage(), 'a failed wpdb query (false, not []) returns null' );

$wpdb->fail_query = false;
$wpdb->rows       = array();
$empty            = sn_mcp_telemetry_usage();
ok( is_array( $empty ), 'an installed but empty table returns a report, not null' );
ok( 0 === $empty['total_rows'], 'empty table reports total_rows 0' );
ok( null === $empty['measured_since'], 'empty table reports measured_since null, never a date' );
ok( null === $empty['measured_days'], 'empty table reports measured_days null, never 0' );

echo "\nGroup: the measured window is not the nominal one\n";
$first_tool = sn_mcp_tool_name_from_slug( sn_mcp_allowlist()[0] );
$wpdb->rows = array( sn_test_row( $first_tool, 4, $ago( 11 ), $ago( 1 ) ) );
$u          = sn_mcp_telemetry_usage( 90 );
ok( 11 === $u['measured_days'], 'measured_days comes from MIN(ts), not the retention constant' );
ok( 90 === $u['window_days'], 'window_days reports what was ASKED for, separately' );
ok( false === $u['complete'], '11 days of data over a 90-day window is NOT complete' );
ok( $u['measured_since'] === $wpdb->rows[0]['first_seen'], 'measured_since is the earliest row' );

$wpdb->rows = array( sn_test_row( $first_tool, 2, $ago( 100 ), $ago( 1 ) ) );
ok( true === sn_mcp_telemetry_usage( 90 )['complete'], 'a window fully covered by data reports complete' );

echo "\nGroup: MIN(ts) is taken across ALL groups, not the first row\n";
$second_tool = sn_mcp_tool_name_from_slug( sn_mcp_allowlist()[1] );
$wpdb->rows  = array(
	sn_test_row( $first_tool, 1, $ago( 5 ), $ago( 5 ) ),
	sn_test_row( $second_tool, 1, $ago( 30 ), $ago( 2 ) ),
);
ok( 30 === sn_mcp_telemetry_usage( 90 )['measured_days'], 'the earliest first_seen across groups wins' );

echo "\nGroup: grouping, doors and outcomes\n";
$wpdb->rows = array(
	sn_test_row( $first_tool, 3, $ago( 4 ), $ago( 1 ), 'read', 'success' ),
	sn_test_row( $first_tool, 2, $ago( 3 ), $ago( 2 ), 'rw', 'refused' ),
);
$u = sn_mcp_telemetry_usage( 90 );
ok( 5 === $u['by_tool'][ $first_tool ]['calls'], 'calls sum across door/outcome groups' );
ok( 5 === $u['total_rows'], 'total_rows sums every group' );
ok( array( 'read', 'rw' ) === $u['by_tool'][ $first_tool ]['doors'], 'both doors are recorded, de-duplicated' );
ok( 3 === $u['by_tool'][ $first_tool ]['outcomes']['success'] && 2 === $u['by_tool'][ $first_tool ]['outcomes']['refused'], 'outcomes are counted separately' );
ok( $u['by_tool'][ $first_tool ]['last_seen'] === $ago( 1 ), 'last_seen is the LATEST across groups' );

echo "\nGroup: schema-error rows carry an empty tool_name\n";
// mcp-tools.php:436 records schema errors with tool_name '' — the call never
// resolved to a tool. They are real traffic, but attributing them to a tool
// would invent a caller.
$wpdb->rows = array(
	sn_test_row( '', 7, $ago( 6 ), $ago( 1 ), 'read', 'schema_error' ),
	sn_test_row( $first_tool, 1, $ago( 2 ), $ago( 2 ) ),
);
$u = sn_mcp_telemetry_usage( 90 );
ok( 8 === $u['total_rows'], 'empty-tool_name rows still count toward total_rows' );
ok( ! isset( $u['by_tool'][''] ), 'empty tool_name creates no by_tool entry' );
ok( 6 === $u['measured_days'], 'empty-tool_name rows still date the measured window' );

echo "\nGroup: zero-call is diffed on PROJECTED tool names, not slugs\n";
$wpdb->rows = array( sn_test_row( $first_tool, 1, $ago( 3 ), $ago( 1 ) ) );
$u          = sn_mcp_telemetry_usage( 90 );
$zero_tools = array_column( $u['zero_call'], 'tool' );
ok( ! in_array( $first_tool, $zero_tools, true ), 'a tool WITH rows is absent from zero_call' );
ok( in_array( $second_tool, $zero_tools, true ), 'an allowlisted tool with no rows lands in zero_call' );
// If the diff compared slugs against stored tool_names, EVERY tool would look
// zero-call — the whole corpus, confidently and wrongly.
ok( count( $zero_tools ) === count( sn_mcp_telemetry_expected_tools() ) - 1, 'exactly one tool leaves the zero-call set' );
ok( false === strpos( implode( ',', $zero_tools ), '/' ), 'zero_call reports projected tool names (__), never raw slugs' );

echo "\nGroup: THE SPLIT — zero rows is not 'unused'\n";
$verdicts = array_column( $u['zero_call'], 'verdict', 'slug' );
ok( 'unused' === $verdicts[ sn_mcp_allowlist()[1] ], 'a reachable tool with no rows is verdict unused' );

// Same evidence — zero rows — opposite conclusion, because the tool cannot be
// reached at all. Retiring this one would delete the evidence of a defect.
$unreachable_slug       = sn_mcp_allowlist()[1];
$GLOBALS['__abilities'] = array_values( array_diff( $GLOBALS['__abilities'], array( $unreachable_slug ) ) );
$u2                     = sn_mcp_telemetry_usage( 90 );
$verdicts2              = array_column( $u2['zero_call'], 'verdict', 'slug' );
$reach2                 = array_column( $u2['zero_call'], 'reachable', 'slug' );
ok( 'unreachable' === $verdicts2[ $unreachable_slug ], 'an unresolvable tool with no rows is verdict unreachable, NOT unused' );
ok( false === $reach2[ $unreachable_slug ], 'and it reports reachable false' );
ok( 'unused' === $verdicts2[ sn_mcp_allowlist()[2] ], 'its neighbours are unaffected' );
sn_test_all_reachable();

echo "\nGroup: the verdict vocabulary is closed\n";
// NOT TESTED HERE, deliberately: the `undetermined` branch fires only when the
// Abilities API is absent entirely (function_exists false). This harness
// requires mcp-tools.php, so both functions exist and the branch is
// unreachable from inside it. Faking it would mean stubbing over the real
// projection — testing the stub, not the reader. What IS pinned is that no
// fourth verdict can appear and that no zero-call entry escapes classification.
$all_verdicts = array_unique( array_column( $u2['zero_call'], 'verdict' ) );
ok( array() === array_diff( $all_verdicts, array( 'unused', 'unreachable', 'undetermined' ) ), 'every verdict is one of the three known values' );
ok( count( array_filter( array_column( $u2['zero_call'], 'verdict' ) ) ) === count( $u2['zero_call'] ), 'no zero-call entry is left unclassified' );
ok( 0 === count( array_filter( array_column( $u2['zero_call'], 'calls' ) ) ), 'every zero_call entry really has zero calls' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
