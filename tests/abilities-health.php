<?php
/**
 * Behavioral tests for the signal-noise/get-health-scan ability (plugin v7.0.0).
 *
 * Read-only agent exposure of the cached Content-Health scan, projected through
 * the shared summary accessors (inc/health-summary.php, loaded FOR REAL) so it
 * matches the S&N Health widget + the Health tab exactly. Asserts registration
 * shape + the summary projection (finding_total, ranked flagged checks,
 * passed/total tally, null when no scan). Never triggers a scan.
 *
 * @since plugin v7.0.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }

$GLOBALS['__acts'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $t ][] = $cb; return true; } }
$GLOBALS['__ab'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; return true; } }

// Data boundary: the cached scan. The summary accessors are loaded FOR REAL.
$GLOBALS['__scan'] = null;
function sn_health_last_scan() { return is_array( $GLOBALS['__scan'] ) ? $GLOBALS['__scan'] : null; }

require __DIR__ . '/../inc/health-summary.php'; // real sn_health_finding_total + sn_health_flagged_checks
require __DIR__ . '/../inc/abilities-health.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function mk_check( $count, $label ) { return array( 'count' => $count, 'findings' => array(), 'label' => $label, 'fix_hint' => 'fix ' . $label ); }

echo "get-health-scan ability — v7.0.0\n";

// ── registration ──
$reg = $GLOBALS['__ab']['signal-noise/get-health-scan'] ?? null;
ok( is_array( $reg ), 'get-health-scan registered' );
ok( 'snt_ability_get_health_scan' === ( $reg['execute_callback'] ?? '' ), 'execute_callback wired' );
ok( 'snt_ability_perm_manage_options' === ( $reg['permission_callback'] ?? '' ), 'gated on manage_options' );
ok( true === ( $reg['meta']['annotations']['readonly'] ?? null ) && true === ( $reg['meta']['annotations']['idempotent'] ?? null ), 'readonly + idempotent' );

// ── no scan → null ──
$GLOBALS['__scan'] = null;
ok( null === snt_ability_get_health_scan( null ), 'returns null when no scan cached' );

// ── scan with findings → correct summary ──
$GLOBALS['__scan'] = array(
	'scanned_at' => 1700,
	'elapsed_ms' => 42,
	'checks'     => array(
		'missing_alt'    => mk_check( 3, 'Missing alt text' ),
		'broken_links'   => mk_check( 7, 'Broken internal links' ),
		'external_links' => mk_check( 2, 'External link rot' ),
		'stale_posts'    => mk_check( 0, 'Stale posts' ),
	),
);
$s = snt_ability_get_health_scan( null );
ok( is_array( $s ), 'returns a summary object for a cached scan' );
// v8.0.4 owner re-tier: external rot is an ADVISORY — excluded from
// finding_total/flagged, surfaced additively as advisory_total.
ok( 10 === $s['finding_total'], 'finding_total sums non-advisory checks (3+7=10; external rot re-tiered)' );
ok( 2 === $s['advisory_total'], 'advisory_total carries the external-rot count additively' );
ok( isset( $reg['output_schema']['properties']['advisory_total'] ), 'output schema declares advisory_total' );
ok( 4 === $s['checks_total'] && $s['checks_total'] === $s['checks_passed'] + count( $s['flagged'] ), 'checks_total = flagged + passed (4 = 2 + 2)' );
ok( 2 === $s['checks_passed'], 'two passing checks by the flagged split (stale_posts + advisory-tier external_links)' );
ok( 2 === count( $s['flagged'] ), 'two flagged checks (advisory tier excluded)' );
ok( 'broken_links' === $s['flagged'][0]['check'] && 7 === $s['flagged'][0]['count'], 'flagged ranked by count desc (broken_links=7 first)' );
ok( 'Broken internal links' === $s['flagged'][0]['label'] && 'fix Broken internal links' === $s['flagged'][0]['fix_hint'], 'flagged carries label + fix_hint' );
ok( 1700 === $s['scanned_at'] && 42 === $s['elapsed_ms'], 'metadata passed through' );

// ── never triggers a scan (sn_health_run_scan is not even defined here) ──
ok( ! function_exists( 'sn_health_run_scan' ), 'ability path never referenced sn_health_run_scan (read-only)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
