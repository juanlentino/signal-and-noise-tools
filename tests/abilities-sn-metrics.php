<?php
/**
 * Standalone tests for the sn_metrics consolidated ability (v13.1.0):
 * signal-noise/sn-metrics. Sectioned batch over the three readership reads,
 * registered new-alongside-old.
 *
 * Stub-fidelity notes: same harness as tests/abilities-sn-status.php — the
 * REAL dispatch sequence and input gates run against source stand-ins. The
 * load-bearing pin here is arg SCOPING: forwarding `class` to
 * analytics_events would trip its additionalProperties:false at the source
 * and kill a healthy section — the R1 bug's mirror image.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data( $code = '' ) { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

class SN_Test_Fact_Ability {
	private $perm; private $result; private $calls_with = array();
	public function __construct( $perm, $result ) { $this->perm = $perm; $this->result = $result; }
	public function check_permissions( $args = null ) { return $this->perm; }
	public function execute( $args = null ) { $this->calls_with[] = $args; return $this->result; }
	public function last_call_args() { return end( $this->calls_with ); }
}

$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

$GLOBALS['__registered'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
}

require __DIR__ . '/../inc/abilities-sn-site-facts.php'; // owns snt_sn_site_facts_dispatch()
require __DIR__ . '/../inc/abilities-sn-metrics.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_metrics (consolidated) — plugin v13.1.0\n\n";

// ─── Section map ───
$map = snt_sn_metrics_map();
$expected_map = array(
	'analytics_summary' => 'signal-noise/get-analytics-summary',
	'analytics_events'  => 'signal-noise/get-analytics-events',
	'rss_stats'         => 'signal-noise/get-rss-stats',
	// v13.44.0 — ADDITIVE. Nothing renamed, nothing moved; the three original
	// sources keep their positions and this pin still asserts the WHOLE map,
	// so a silent reshuffle is as loud as a silent addition.
	'machine_readers'   => 'signal-noise/get-machine-readers-summary',
	'analytics_top_content' => 'signal-noise/get-analytics-top-content',
	'404_log'           => 'signal-noise/get-404-log',
);
ok( $expected_map === $map, 'the map matches its sources exactly, in a pinned order' );

// ─── Registration ───
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$reg = $GLOBALS['__registered']['signal-noise/sn-metrics'] ?? null;
ok( is_array( $reg ), 'sn-metrics registers on wp_abilities_api_init' );
ok( ( $reg['input_schema']['properties']['sections']['items']['enum'] ?? null ) === array_keys( $map ), 'the input enum IS the map keys' );
ok( 30 === ( $reg['input_schema']['properties']['range']['default'] ?? null ) && 'human' === ( $reg['input_schema']['properties']['class']['default'] ?? null ), 'range/class defaults mirror the sources (30, human)' );
$range_type = $reg['input_schema']['properties']['range']['type'] ?? null;
ok( is_array( $range_type ) && in_array( 'string', $range_type, true ) && in_array( 'integer', $range_type, true ), 'range accepts string|integer, matching the source union' );
ok( ! isset( $reg['input_schema']['properties']['range']['enum'] ), 'range carries NO enum — the sources own their accepted value sets' );
ok( true === ( $reg['meta']['annotations']['readonly'] ?? null ), 'annotated readonly' );

// ─── Input gates ───
$r = snt_ability_sn_metrics( array( 'sections' => array() ) );
ok( is_wp_error( $r ) && 'snt_metrics_empty' === $r->get_error_code(), 'empty sections → snt_metrics_empty' );
$r = snt_ability_sn_metrics( array( 'sections' => array( 'rss_stats', 'nope' ) ) );
ok( is_wp_error( $r ) && 'snt_metrics_unknown' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'nope' ), 'unknown section → named refusal' );

// ─── THE ARG-SCOPING PIN (R1's mirror image) ───
foreach ( $expected_map as $section => $slug ) {
	$GLOBALS['__abilities'][ $slug ] = new SN_Test_Fact_Ability( true, array( 'section' => $section ) );
}
$r = snt_ability_sn_metrics( array( 'sections' => array( 'analytics_summary', 'analytics_events', 'rss_stats' ), 'range' => 90, 'class' => 'bot' ) );
ok( ! is_wp_error( $r ) && true === $r['ok'], 'a full request succeeds' );
ok( array( 'range' => 90, 'class' => 'bot' ) === $GLOBALS['__abilities'][ $expected_map['analytics_summary'] ]->last_call_args(), 'analytics_summary receives range AND class' );
ok( array( 'range' => 90 ) === $GLOBALS['__abilities'][ $expected_map['analytics_events'] ]->last_call_args(), 'analytics_events receives range ONLY — class must never reach its additionalProperties:false schema' );
ok( array() === $GLOBALS['__abilities'][ $expected_map['rss_stats'] ]->last_call_args(), 'rss_stats receives no args at all' );

// ─── Defaults flow when args are omitted ───
$r = snt_ability_sn_metrics( array( 'sections' => array( 'analytics_summary' ) ) );
ok( array( 'range' => 30, 'class' => 'human' ) === $GLOBALS['__abilities'][ $expected_map['analytics_summary'] ]->last_call_args(), 'omitted args dispatch as the documented defaults (30, human)' );

// ─── Parity + degradation ───
$summary_payload = array( 'views' => 1234, 'pageview_visits' => 456, 'time_avg_per_view' => 38690 );
$GLOBALS['__abilities'][ $expected_map['analytics_summary'] ] = new SN_Test_Fact_Ability( true, $summary_payload );
$GLOBALS['__abilities'][ $expected_map['analytics_events'] ]  = new SN_Test_Fact_Ability( false, array( 'x' => 1 ) );
unset( $GLOBALS['__abilities'][ $expected_map['rss_stats'] ] );
$r = snt_ability_sn_metrics( array( 'sections' => array( 'analytics_summary', 'analytics_events', 'rss_stats' ) ) );
ok( $summary_payload === $r['sections']['analytics_summary'], 'parity: the summary payload rides through unreshaped (ms stay ms — presentation is the caller\'s job)' );
ok( array( 'error' => 'unavailable' ) === $r['sections']['analytics_events'], 'refused source degrades to {error:unavailable}' );
ok( array( 'error' => 'unavailable' ) === $r['sections']['rss_stats'], 'unregistered source degrades the same way' );

echo "\nGroup: v13.44.0 sections\n";
$map = snt_sn_metrics_map();
// The gap that forced a wp eval dead-end on 2026-08-30: NO MCP tool exposed
// machine reads at all, the identity fold (v13.43.0) included.
ok( 'signal-noise/get-machine-readers-summary' === ( $map['machine_readers'] ?? null ),
	'machine_readers routes to the machine-readers summary ability' );
ok( 'signal-noise/get-analytics-top-content' === ( $map['analytics_top_content'] ?? null ),
	'analytics_top_content routes to the top-content ability — its two siblings were already sections' );
ok( 'signal-noise/get-404-log' === ( $map['404_log'] ?? null ),
	'404_log routes to the 404 log ability' );

// The sensor clamps 1..90 and holds ~32 days, while range accepts
// 7|14|30|90|365|all — so 365 and all are UNREACHABLE for machine_readers. A
// caller must learn that from the schema, never from a surprising number.
$sn_rd = (string) ( $reg['input_schema']['properties']['range']['description'] ?? '' );
ok( false !== strpos( $sn_rd, 'machine_readers' ), 'the range description names machine_readers and its clamp' );
ok( false !== strpos( $sn_rd, '1-90' ), 'and states the range the sensor actually enforces' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
