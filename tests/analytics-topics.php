<?php
/**
 * Tests for inc/analytics-topics.php — sn_analytics_topic_totals() (v10.21.0).
 * The topic-level analytics join: ML topic clusters (post groupings from the
 * corpus artifact — NEVER reader data) × the path-keyed local rollup table.
 * What this fixture pins:
 *   - one bounded rollup query aggregating every member path, grouped and
 *     summed per topic, rows {label, notes, paths, views, visits} views-desc;
 *   - the zero-vs-null line both directions: topics artifact null (never
 *     built) → null; a FAILED rollup read (wpdb last_error) → null, never [];
 *     an empty window → [] (a real answer); topics [] → [] (a real answer);
 *   - unmapped members (no permalink → no path) shrink the topic honestly,
 *     never fabricate a path;
 *   - callers own the window: the function does zero date math.
 * Run: php tests/analytics-topics.php
 * @since plugin v10.21.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__topics'] = null;
function snt_ml_topics_get() { return $GLOBALS['__topics']; }
$GLOBALS['__paths'] = array();
function sn_analytics_post_path( $post_id ) { return $GLOBALS['__paths'][ (int) $post_id ] ?? null; }

// wpdb stub modeling the real transforms this accessor rides: prepare()
// interpolates, get_results(ARRAY_A) returns [] on a FAILED query (never
// null) with the failure only visible in last_error — the exact shape that
// bit this repo before (see memory: get_results returns [] on failure).
class TT_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $rows       = array();
	public $queries    = array();
	public $fail       = false;
	public function prepare( $sql, ...$args ) {
		$flat = array();
		foreach ( $args as $a ) { foreach ( (array) $a as $v ) { $flat[] = $v; } }
		foreach ( $flat as $v ) {
			$sql = preg_replace( '/%[sdf]/', is_numeric( $v ) ? (string) $v : "'" . $v . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_results( $sql, $output = OBJECT ) {
		$this->queries[] = $sql;
		if ( $this->fail ) {
			$this->last_error = 'table gone';
			return array(); // Real wpdb: [] on failure, NOT null.
		}
		$this->last_error = '';
		return $this->rows;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new TT_Stub_wpdb();

require __DIR__ . '/../inc/analytics-topics.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: null vs empty, both directions\n";
$GLOBALS['__topics'] = null;
ok( null === sn_analytics_topic_totals( '2026-07-01', '2026-07-30' ), 'topics artifact never built → null (unknown, not zero)' );
$GLOBALS['__topics'] = array();
ok( array() === sn_analytics_topic_totals( '2026-07-01', '2026-07-30' ), 'a built-but-clusterless corpus → [] (a real answer)' );

echo "\nGroup: aggregation\n";
$GLOBALS['__topics'] = array(
	array( 'members' => array( 11, 12 ), 'label' => 'provenance · signature' ),
	array( 'members' => array( 13, 14, 15 ), 'label' => 'metadata · royalties' ),
);
$GLOBALS['__paths'] = array(
	11 => '/notes/a/',
	12 => '/notes/b/',
	13 => '/notes/c/',
	14 => '/notes/d/',
	// 15 has NO path (unpublished/permalink failure) — honest shrink.
);
$GLOBALS['wpdb']->fail = false;
$GLOBALS['wpdb']->rows = array(
	(object) array( 'path' => '/notes/a/', 'views' => '30', 'visits' => '10' ),
	(object) array( 'path' => '/notes/b/', 'views' => '20', 'visits' => '5' ),
	(object) array( 'path' => '/notes/c/', 'views' => '90', 'visits' => '40' ),
);
$GLOBALS['wpdb']->queries = array();
$rows = sn_analytics_topic_totals( '2026-07-01', '2026-07-30' );
ok( 1 === count( $GLOBALS['wpdb']->queries ), 'ONE bounded query covers every member path' );
$q = $GLOBALS['wpdb']->queries[0];
ok( false !== strpos( $q, "'2026-07-01'" ) && false !== strpos( $q, "'2026-07-30'" ) && false !== strpos( $q, "'human'" ), 'query is window-bounded and human-classed' );
ok( is_array( $rows ) && 2 === count( $rows ), 'two topics return' );
ok( 'metadata · royalties' === $rows[0]['label'] && 90 === $rows[0]['views'] && 40 === $rows[0]['visits'], 'views-descending: the 90-view topic leads' );
ok( 3 === $rows[0]['notes'] && 2 === $rows[0]['paths'], 'notes counts MEMBERS; paths counts only the mapped ones (member 15 shrank honestly)' );
ok( 'provenance · signature' === $rows[1]['label'] && 50 === $rows[1]['views'] && 15 === $rows[1]['visits'], 'member paths sum per topic (30+20 / 10+5)' );
ok( isset( $rows[0]['member_paths'] ) && array( '/notes/c/', '/notes/d/' ) === $rows[0]['member_paths'], 'member_paths carried for the annotation pass' );

echo "\nGroup: failure is null, never a confident zero\n";
$GLOBALS['wpdb']->fail = true;
ok( null === sn_analytics_topic_totals( '2026-07-01', '2026-07-30' ), 'a FAILED rollup read (last_error set, [] returned) → null' );
$GLOBALS['wpdb']->fail = false;

echo "\nGroup: no mapped paths at all\n";
$GLOBALS['__topics'] = array( array( 'members' => array( 99 ), 'label' => 'orphan' ) );
$GLOBALS['__paths']  = array();
$GLOBALS['wpdb']->queries = array();
ok( array() === sn_analytics_topic_totals( '2026-07-01', '2026-07-30' ), 'zero mapped paths → [] WITHOUT touching the database' );
ok( array() === $GLOBALS['wpdb']->queries, '…and no query ran' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
