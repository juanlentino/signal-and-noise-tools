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
function sn_test_row( $tool, $calls, $first, $last, $door = 'read', $outcome = 'success', $error_code = null ) {
	return array(
		'tool_name'  => $tool,
		'door'       => $door,
		'outcome'    => $outcome,
		'error_code' => $error_code,
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

echo "\nGroup: one ability is one row, whichever door spelled it (#1587)\n";
// The MCP doors write the projected name, the direct door writes the slug core
// hands it, and a bare tail is folded too; a theme tool is not ours to fold.
$first_slug = sn_mcp_allowlist()[0];
$first_tail = substr( strrchr( $first_slug, '/' ), 1 );
$expected   = sn_mcp_telemetry_expected_tools();
ok( $first_tool === sn_mcp_telemetry_canonical_tool( $first_tool, $expected ) && $first_tool === sn_mcp_telemetry_canonical_tool( $first_slug, $expected ) && $first_tool === sn_mcp_telemetry_canonical_tool( $first_tail, $expected ), 'canonical: projected name, slug and bare tail all fold onto the projected name' );
ok( 'signal-and-noise__verify-page' === sn_mcp_telemetry_canonical_tool( 'signal-and-noise__verify-page', $expected ) && '' === sn_mcp_telemetry_canonical_tool( '', $expected ), 'canonical: a theme tool and a blank stay as recorded' );
// No row under the projected spelling on purpose: the unfixed reader saw this
// tool as zero-call while it carried three door calls under the other two.
$wpdb->rows = array(
	sn_test_row( $first_tail, 2, $ago( 4 ), $ago( 3 ), 'read' ),
	sn_test_row( $first_slug, 1, $ago( 2 ), $ago( 2 ), 'rw' ),
	sn_test_row( $first_slug, 30000, $ago( 5 ), $ago( 1 ), 'direct' ),
	sn_test_row( 'signal-and-noise__verify-page', 4, $ago( 2 ), $ago( 2 ), 'read' ),
);
$u = sn_mcp_telemetry_usage( 90 );
ok( 2 === count( $u['by_tool'] ) && isset( $u['by_tool'][ $first_tool ] ) && isset( $u['by_tool']['signal-and-noise__verify-page'] ), 'the three spellings are ONE row under the projected name; the theme tool keeps its own' );
ok( 30003 === $u['by_tool'][ $first_tool ]['calls'] && 3 === $u['by_tool'][ $first_tool ]['door_calls'] && 30000 === $u['by_tool'][ $first_tool ]['direct_calls'], 'calls is every door; door_calls leaves direct out (one rw + two read = 3 against 30,000 direct)' );
ok( array( 'read', 'rw', 'direct' ) === $u['by_tool'][ $first_tool ]['doors'] && $ago( 1 ) === $u['by_tool'][ $first_tool ]['last_seen'], 'the folded row carries every door and the latest last_seen' );
ok( array( 'read' => 6, 'rw' => 1, 'direct' => 30000 ) === $u['by_door'] && array_sum( $u['by_door'] ) === $u['total_rows'], 'by_door is door => calls and sums to total_rows' );
ok( ! in_array( $first_tool, array_column( $u['zero_call'], 'tool' ), true ), 'a tool with door calls under the other spellings is NOT a zero-call entry' );

echo "\nGroup: first-party polls are not door traffic\n";
$wpdb->rows = array(
	sn_test_row( $first_slug, 28689, $ago( 5 ), $ago( 1 ), 'direct' ),
	sn_test_row( $second_tool, 1, $ago( 2 ), $ago( 2 ), 'rw' ),
	sn_test_row( $second_tool, 30000, $ago( 5 ), $ago( 1 ), 'direct' ),
);
$u     = sn_mcp_telemetry_usage( 90 );
$entry = null;
foreach ( $u['zero_call'] as $e ) { if ( $first_tool === $e['tool'] ) { $entry = $e; } }
ok( is_array( $entry ) && 'first_party_only' === $entry['verdict'] && 28689 === $entry['calls'], 'a direct-only tool is listed as first_party_only WITH its direct count, not unused' );
ok( 'unused' !== ( $entry['verdict'] ?? null ), 'and it is never a retirement candidate for the code' );
ok( 1 === $u['by_tool'][ $second_tool ]['door_calls'] && 30000 === $u['by_tool'][ $second_tool ]['direct_calls'], 'one rw call under 30,000 direct calls is door_calls 1' );
ok( ! in_array( $second_tool, array_column( $u['zero_call'], 'tool' ), true ), 'one door call keeps a tool out of the zero-call list' );
ok( array( 'direct' => 58689, 'rw' => 1 ) === $u['by_door'] && 58690 === $u['total_rows'], 'by_door sums equal total_rows here too' );
$GLOBALS['__abilities'] = array_values( array_diff( $GLOBALS['__abilities'], array( $first_slug ) ) );
$v = array_column( sn_mcp_telemetry_usage( 90 )['zero_call'], 'verdict', 'tool' );
ok( 'unreachable' === $v[ $first_tool ], 'a bug outranks first-party polls: an unprojectable tool with direct calls is still unreachable' );
sn_test_all_reachable();

// The null state cannot be produced in this harness (mcp-tools.php is loaded, so
// sn_mcp_project_tool exists). A fresh process WITHOUT it is the real absent-API
// state, the same idiom as mcp-read-rate-window.php: no stub over projection.
$script = 'define("ABSPATH","/"); define("DAY_IN_SECONDS",86400); define("MINUTE_IN_SECONDS",60); define("ARRAY_A","ARRAY_A");'
	. ' function apply_filters($h,$v){return $v;} function add_filter(){return true;} function add_action(){return true;} function __($s,$d=null){return $s;} function get_option($k,$d=false){return $d;} function update_option(){return true;} function wp_json_encode($d,$f=0){return json_encode($d,$f);}'
	. ' class W { public $prefix="wp_"; public $rows=array(); public function prepare($q,...$a){return $q;} public function get_var($q){return "wp_".SN_MCP_TELEMETRY_TABLE;} public function get_results($q,$o=null){return $this->rows;} }'
	. ' $GLOBALS["wpdb"]=new W(); $d=' . var_export( __DIR__ . '/../inc/mcp/', true ) . '; require $d."mcp-capabilities.php"; require $d."mcp-telemetry.php"; require $d."mcp-telemetry-read.php";'
	. ' $s=sn_mcp_allowlist()[0]; $n=gmdate("Y-m-d H:i:s"); $GLOBALS["wpdb"]->rows=array(array("tool_name"=>$s,"door"=>"direct","outcome"=>"success","error_code"=>null,"calls"=>5,"first_seen"=>$n,"last_seen"=>$n));'
	. ' echo json_encode(array_column(sn_mcp_telemetry_usage(90)["zero_call"],"verdict","slug")[$s] ?? "MISSING");';
$pipes = array();
$proc  = proc_open( array( PHP_BINARY, '-r', $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
$out   = is_resource( $proc ) ? stream_get_contents( $pipes[1] ) : '';
if ( is_resource( $proc ) ) { fclose( $pipes[1] ); proc_close( $proc ); }
ok( 'undetermined' === json_decode( $out, true ), 'an unknown projection (Abilities API absent) stays undetermined under direct calls, never first_party_only' );

echo "\nGroup: error_code splits the GROUP BY without corrupting tool totals\n";
// The v11.10-line GROUP BY adds error_code, so one tool's calls arrive as
// MORE sql rows than before. by_tool and zero_call must re-sum across the
// split; by_error_code carries the per-code lines.
$wpdb->rows = array(
	sn_test_row( $first_tool, 4, $ago( 5 ), $ago( 2 ), 'read', 'schema_error', 'snt_block_migration_candidate_not_found' ),
	sn_test_row( $first_tool, 1, $ago( 4 ), $ago( 1 ), 'read', 'server_error', 'snt_helper_unavailable' ),
	sn_test_row( $first_tool, 2, $ago( 3 ), $ago( 1 ), 'read', 'success' ),
);
$u = sn_mcp_telemetry_usage( 90 );
ok( 7 === $u['by_tool'][ $first_tool ]['calls'], 'error_code split: by_tool still sums the tool\'s calls across code groups' );
ok( ! in_array( $first_tool, $u['zero_call'], true ), 'error_code split: a tool with traffic never lands in zero_call' );
$codes = array();
foreach ( $u['by_error_code'] as $line ) { $codes[ $line['error_code'] ] = $line['calls']; }
ok( 4 === ( $codes['snt_block_migration_candidate_not_found'] ?? null ) && 1 === ( $codes['snt_helper_unavailable'] ?? null ), 'error_code split: by_error_code carries each code with its own call count' );
ok( 2 === count( $u['by_error_code'] ), 'error_code split: success rows (NULL code) produce no by_error_code line' );

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
ok( array() === array_diff( $all_verdicts, array( 'unused', 'first_party_only', 'unreachable', 'undetermined' ) ), 'every verdict is one of the four known values' );
ok( count( array_filter( array_column( $u2['zero_call'], 'verdict' ) ) ) === count( $u2['zero_call'] ), 'no zero-call entry is left unclassified' );
ok( 0 === count( array_filter( array_column( $u2['zero_call'], 'calls' ) ) ), 'every zero_call entry really has zero calls when nothing polled directly' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
