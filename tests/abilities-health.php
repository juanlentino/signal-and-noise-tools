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
// v11.16.2: the REAL surface map. Without it sn_health_scan_for_surface() hits
// its function_exists guard and hands back an UNSCOPED scan, so every assertion
// here about a scoped total was vacuous — which is why this suite stayed green
// through the v11.16.1 regression it was best placed to catch.
require __DIR__ . '/../inc/health-check-surfaces.php';
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
// v11.16.2 pinned this at 0 and flagged it as an owner call: all three advisory
// keys live on `worklist`, so a health-scoped total was structurally always 0
// while the schema pointed callers here for advisory counts.
// v11.17.0 TOOK that call — advisory is a TIER, not a surface, so the ability
// now counts it wherever it renders. The rendering surfaces are unchanged.
ok( 2 === $s['advisory_total'], 'advisory_total carries the external-rot count from the worklist surface' );
ok( isset( $reg['output_schema']['properties']['advisory_total'] ), 'output schema declares advisory_total' );
// v11.16.2: 3, not 4 — external_links is counted by the worklist, not here.
// The INVARIANT is the load-bearing half: both sides must describe one population.
ok( 3 === $s['checks_total'] && $s['checks_total'] === $s['checks_passed'] + count( $s['flagged'] ), 'checks_total = flagged + passed (3 = 2 + 1) on the health surface' );
ok( 1 === $s['checks_passed'], 'one passing check on this surface (stale_posts); external_links passes on the worklist, not here' );
ok( 2 === count( $s['flagged'] ), 'two flagged checks (advisory tier excluded)' );
ok( 'broken_links' === $s['flagged'][0]['check'] && 7 === $s['flagged'][0]['count'], 'flagged ranked by count desc (broken_links=7 first)' );
ok( 'Broken internal links' === $s['flagged'][0]['label'] && 'fix Broken internal links' === $s['flagged'][0]['fix_hint'], 'flagged carries label + fix_hint' );
ok( 1700 === $s['scanned_at'] && 42 === $s['elapsed_ms'], 'metadata passed through' );

// ── v11.16.2 regression: the parity bug the fixture above cannot catch ──────
// Every check above is a `health`-surface check, so a scoped numerator and a
// RAW denominator agree by luck. The shipped defect needed an OFF-surface check
// carrying a real finding: v11.16.1 scoped the accessors, `checks_total` stayed
// a hand count of $scan['checks'], and get-health-scan reported "21 of 21 checks
// passed" live while ledger_ci genuinely had a finding — a success-only readout.
$GLOBALS['__scan'] = array(
	'scanned_at' => 1700,
	'elapsed_ms' => 42,
	'checks'     => array(
		'missing_alt' => mk_check( 3, 'Missing alt text' ),   // health, flagged
		'stale_posts' => mk_check( 0, 'Stale posts' ),        // health, passing
		'ledger_ci'   => mk_check( 5, 'Ledger CI' ),          // INTEGRITY surface, flagged there
	),
);
$o = snt_ability_get_health_scan( null );
ok( 2 === $o['checks_total'], 'checks_total narrows to the health surface — ledger_ci is counted by Integrity, not here' );
ok( 1 === $o['checks_passed'], 'checks_passed stays the honest one (stale_posts)' );
ok( 1 === count( $o['flagged'] ) && 'missing_alt' === $o['flagged'][0]['check'], 'flagged lists only on-surface checks' );
ok( $o['checks_total'] === $o['checks_passed'] + count( $o['flagged'] ), 'the invariant that broke: passed + flagged === total, on ONE population' );
ok( ! ( 0 === $o['finding_total'] && $o['checks_total'] === $o['checks_passed'] ), 'never an all-clear built from a scoped numerator over a raw denominator' );

// ── v11.17.0: advisory_total counts the TIER, not the health surface ────────
// The bug this pins: all three advisory-tier keys render on the `worklist`
// surface since v11.13.0, so a total scoped to `health` returned a structural
// 0 — while the schema's own finding_total description pointed callers HERE for
// advisory counts. Zero read as "measured, none found" when the truth was "not
// measured on this surface".
$GLOBALS['__scan'] = array(
	'scanned_at' => 1700,
	'elapsed_ms' => 42,
	'checks'     => array(
		'missing_alt'    => mk_check( 3, 'Missing alt text' ),   // health, fault
		'external_links' => mk_check( 2, 'External link rot' ),  // WORKLIST, advisory
	),
);
$a = snt_ability_get_health_scan( null );
ok( 2 === $a['advisory_total'], 'advisory_total sees the tier on the worklist surface (2), not a structural 0' );
ok( 3 === $a['finding_total'], 'finding_total still counts fault-tier on the health surface only' );
ok( 1 === $a['checks_total'], 'checks_total still narrows to health — the two fields answer different questions' );
// The accessor's two readings must actually DIFFER here, or this pins nothing.
ok( 0 === sn_health_advisory_total( $GLOBALS['__scan'] ), 'the health-scoped DEFAULT is still 0 — rendering callers are unchanged' );
ok( 2 === sn_health_advisory_total( $GLOBALS['__scan'], null ), 'the unscoped reading is the real count' );
ok( sn_health_advisory_total( $GLOBALS['__scan'] ) !== sn_health_advisory_total( $GLOBALS['__scan'], null ),
    'the two readings DIFFER on this fixture — without that the assertions above are vacuous' );

// ── never triggers a scan (sn_health_run_scan is not even defined here) ──
ok( ! function_exists( 'sn_health_run_scan' ), 'ability path never referenced sn_health_run_scan (read-only)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
