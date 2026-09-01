<?php
/**
 * Standalone tests for the sn_status consolidated ability (v13.1.0):
 * signal-noise/sn-status. Sectioned batch over the ten narrow status reads,
 * registered new-alongside-old.
 *
 * Stub-fidelity notes: source abilities are stand-ins exposing
 * check_permissions()/execute() (the tests/abilities-sn-site-facts.php
 * SN_Test_Fact_Ability shape) — this file exercises the REAL dispatch
 * sequence and the REAL input gates in snt_ability_sn_status(), never a
 * re-implementation of them.
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

// Registration capture: wp_register_ability records; the captured
// wp_abilities_api_init callbacks run against it below.
$GLOBALS['__registered'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
}

require __DIR__ . '/../inc/abilities-sn-site-facts.php'; // owns snt_sn_site_facts_dispatch()
require __DIR__ . '/../inc/abilities-sn-status.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_status (consolidated) — plugin v13.1.0\n\n";

// ─── Section map: ten sections, all plugin-namespace sources ───
$map = snt_sn_status_map();
ok( 19 === count( $map ), 'the section map has exactly 19 entries (v13.68.0 ADDED inbound_pass; v13.63.0 ADDED search_coverage; v13.62.0 ADDED family_drift — weave Phase 5; v13.57.0 ADDED search_performance/search_drift/search_crossexam — weave Phase 1; v13.52.0 ADDED cron_health, the model over cron_scheduled/cron_history)' );
$expected_map = array(
	'uptime'               => 'signal-noise/uptime-status',
	'deploy'               => 'signal-noise/get-deploy-status',
	'health_scan'          => 'signal-noise/get-health-scan',
	'anchor'               => 'signal-noise/anchor-status',
	'provenance_integrity' => 'signal-noise/provenance-integrity-status',
	'ipv6_criterion'       => 'signal-noise/login-defense-ipv6-criterion',
	'ai_cache_probe'       => 'signal-noise/ai-cache-probe-status',
	'cadence'              => 'signal-noise/cadence-flags',
	'cron_scheduled'       => 'signal-noise/list-cron-events',
	'cron_history'         => 'signal-noise/get-cron-history',
	// v13.44.0 — ADDITIVE. corpus_integrity sits here and NOT on sn-validate:
	// it is manage_options, sn-validate is read_corpus, and that placement
	// would cross permission tiers.
	'cron_health'          => 'signal-noise/cron-health-summary',
	'collector'            => 'signal-noise/get-collector-status',
	'corpus_integrity'     => 'signal-noise/corpus-integrity-scan',
	// v13.57.0 — weave Phase 1: Search Console on the read door as sections.
	'search_performance'   => 'signal-noise/search-performance',
	'search_drift'         => 'signal-noise/search-drift',
	'search_crossexam'     => 'signal-noise/search-crossexam',
	'family_drift'         => 'signal-noise/family-drift', // v13.62.0
	'search_coverage'      => 'signal-noise/search-coverage', // v13.63.0
	'inbound_pass'         => 'signal-noise/inbound-pass', // v13.68.0
);
ok( $expected_map === $map, 'the map matches its sources exactly, in a pinned order' );
ok( array() === array_filter( $map, static fn( $s ) => strpos( $s, 'signal-noise/' ) !== 0 ), 'every source is a PLUGIN slug — no section crosses into the theme' );

// ─── Registration: schema enum derives from the map; readonly annotations ───
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$reg = $GLOBALS['__registered']['signal-noise/sn-status'] ?? null;
ok( is_array( $reg ), 'sn-status registers on wp_abilities_api_init' );
ok( ( $reg['input_schema']['properties']['sections']['items']['enum'] ?? null ) === array_keys( $map ), 'the input enum IS the map keys — one source of truth' );
ok( in_array( 'sections', $reg['input_schema']['required'] ?? array(), true ), 'sections is required input' );
ok( 'object' === ( $reg['input_schema']['type'] ?? '' ), 'input root is a strict object (no null union — sections is required)' );
ok( true === ( $reg['meta']['annotations']['readonly'] ?? null ) && false === ( $reg['meta']['annotations']['destructive'] ?? null ), 'annotated readonly + non-destructive' );
ok( 'snt_ability_perm_manage_options' === ( $reg['permission_callback'] ?? '' ), 'permission callback is manage_options' );

// ─── Input gates (the REAL execute callback) ───
$r = snt_ability_sn_status( array( 'sections' => array() ) );
ok( is_wp_error( $r ) && 'snt_status_empty' === $r->get_error_code() && 422 === ( $r->get_error_data()['status'] ?? 0 ), 'empty sections → 422 snt_status_empty' );
$r = snt_ability_sn_status( null );
ok( is_wp_error( $r ) && 'snt_status_empty' === $r->get_error_code(), 'null input → the same empty-sections refusal, never a PHP notice' );
$r = snt_ability_sn_status( array( 'sections' => array( 'uptime', 'bogus', 'also_bogus' ) ) );
ok( is_wp_error( $r ) && 'snt_status_unknown' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'bogus' ), 'unknown sections → 422 naming the offenders' );

// ─── THE R1 PIN: cron_history requires hook (source schema requires it —
//     a no-args dispatch would be a permanently dead section) ───
$r = snt_ability_sn_status( array( 'sections' => array( 'uptime', 'cron_history' ) ) );
ok( is_wp_error( $r ) && 'snt_status_missing_hook' === $r->get_error_code() && 400 === ( $r->get_error_data()['status'] ?? 0 ), 'cron_history without hook → 400, never a silent unavailable' );
$GLOBALS['__abilities']['signal-noise/uptime-status'] = new SN_Test_Fact_Ability( true, array( 'up' => true ) );
$r = snt_ability_sn_status( array( 'sections' => array( 'uptime' ) ) );
ok( ! is_wp_error( $r ), 'hook is NOT demanded when cron_history is not requested' );

// ─── Dispatch parity: each section carries the source payload BYTE-IDENTICAL ───
$payloads = array(
	'uptime'               => array( 'up' => true, 'monitors' => array( array( 'id' => 1 ) ) ),
	'deploy'               => array( 'workers' => array( 'sn-provenance' => 'v1.12.2' ) ),
	'health_scan'          => null, // no scan yet — null is the source's honest answer and must survive
	'cron_history'         => array( array( 'hook' => 'sn_daily', 'ran_at' => '2026-08-25' ) ),
);
foreach ( $payloads as $section => $payload ) {
	$GLOBALS['__abilities'][ $expected_map[ $section ] ] = new SN_Test_Fact_Ability( true, $payload );
}
$r = snt_ability_sn_status( array( 'sections' => array_keys( $payloads ), 'hook' => 'sn_daily' ) );
ok( ! is_wp_error( $r ) && true === $r['ok'], 'a mixed request succeeds' );
foreach ( $payloads as $section => $payload ) {
	ok( $r['sections'][ $section ] === $payload, "parity: $section carries the source payload unreshaped" );
}
ok( array_keys( $r['sections'] ) === array_keys( $payloads ), 'sections come back keyed by request, nothing extra' );

// ─── Arg forwarding: cron_history gets {hook}; no-arg sections get array() ───
$hist = $GLOBALS['__abilities'][ $expected_map['cron_history'] ];
ok( array( 'hook' => 'sn_daily' ) === $hist->last_call_args(), 'cron_history dispatches with exactly {hook}' );
$up = $GLOBALS['__abilities'][ $expected_map['uptime'] ];
ok( array() === $up->last_call_args(), 'no-arg sections dispatch with an empty args array — hook never leaks into them' );

// ─── Degradation: one refused source degrades ONE section, the rest return ───
$GLOBALS['__abilities'][ $expected_map['anchor'] ] = new SN_Test_Fact_Ability( false, array( 'x' => 1 ) );
$GLOBALS['__abilities'][ $expected_map['cadence'] ] = new SN_Test_Fact_Ability( true, new WP_Error( 'boom', 'db gone' ) );
unset( $GLOBALS['__abilities'][ $expected_map['ipv6_criterion'] ] ); // unregistered source
$r = snt_ability_sn_status( array( 'sections' => array( 'uptime', 'anchor', 'cadence', 'ipv6_criterion' ) ) );
ok( array( 'error' => 'unavailable' ) === $r['sections']['anchor'], 'permission-refused source → {error:unavailable}' );
ok( array( 'error' => 'unavailable' ) === $r['sections']['cadence'], 'execute-WP_Error source → {error:unavailable} (never error forwarding)' );
ok( array( 'error' => 'unavailable' ) === $r['sections']['ipv6_criterion'], 'unregistered source → {error:unavailable}' );
ok( array( 'up' => true, 'monitors' => array( array( 'id' => 1 ) ) ) === $r['sections']['uptime'], '...while a healthy section still returns' );

// ─── Dedup: repeated section names collapse to one entry ───
$r = snt_ability_sn_status( array( 'sections' => array( 'uptime', 'uptime', 'uptime' ) ) );
ok( ! is_wp_error( $r ) && 1 === count( $r['sections'] ), 'duplicate section names dedupe to one entry' );

echo "\nGroup: v13.44.0 sections\n";
$smap = snt_sn_status_map();
ok( 'signal-noise/get-collector-status' === ( $smap['collector'] ?? null ),
	'collector routes to the collector-status ability' );
// corpus-integrity-scan is manage_options, so it belongs HERE and not on
// sn-validate, which is read_corpus — that placement would cross permission
// tiers, which is a scope change wearing a refactor's clothes.
ok( 'signal-noise/corpus-integrity-scan' === ( $smap['corpus_integrity'] ?? null ),
	'corpus_integrity routes to the corpus-integrity scan' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
