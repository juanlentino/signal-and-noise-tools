<?php
/**
 * Tests: the roadmap-drift Health check WRAPPER (inc/health-check-roadmap-drift.php).
 *
 * tests/maturity-roadmap-merge.php pins snt_roadmap_drift_findings() (pure) hard,
 * but nothing drove sn_health_check_roadmap_drift() itself — the function that
 * actually calls the real sn_maturity_roadmap_effective_report() and packs the
 * result through sn_health_pack_check(). An untested wrapper is how a check ends
 * up registered and never actually running.
 *
 * inc/health-checks.php (sn_health_pack_check's real home) is not loadable
 * standalone — it declares 20 sibling checks and WP-heavy code — so this suite
 * mirrors it locally, exactly matching the real 4-field envelope (v11.33.0's
 * `skipped` field included), the same seam-stub shape tests/health-analytics-
 * integrity.php uses for the same reason. Everything else is REAL: the merge
 * (inc/maturity-roadmap-merge.php), the effective-report computation and
 * validation (inc/maturity-roadmap-shortcode.php), and the check itself.
 *
 * Run: php tests/health-check-roadmap-drift.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }

$GLOBALS['snt_options'] = array();
function get_option( $k, $d = null ) { return $GLOBALS['snt_options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['snt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['snt_options'][ $k ] ); return true; }
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }
// Only exercised because inc/maturity-roadmap-shortcode.php registers the
// shortcode at require time (a top-level call at the bottom of the file);
// the render path itself is never invoked by this suite.
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }

// The seam: sn_health_pack_check() mirrored exactly (inc/health-checks.php:229-251).
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
		'skipped'  => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null,
	);
}

require_once dirname( __DIR__ ) . '/inc/maturity-roadmap-merge.php';
require_once dirname( __DIR__ ) . '/inc/maturity-roadmap-shortcode.php';
require_once dirname( __DIR__ ) . '/inc/health-check-roadmap-drift.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "the roadmap-drift Health check wrapper\n\n";

echo "Group: no option stored -> clean check\n";
$GLOBALS['snt_options'] = array();
$c = sn_health_check_roadmap_drift();
ok( 0 === $c['count'], 'zero findings when nothing overrides the static board' );
ok( array() === $c['findings'], 'findings is an empty array' );
ok( null === $c['skipped'], 'the check RAN — never reports itself skipped (no external dependency to lack)' );
ok( 'Roadmap board drift' === $c['label'], 'carries its label' );

echo "\nGroup: a real cell conflict, driven through the real merge\n";
$GLOBALS['snt_options'] = array();
$real_static = sn_maturity_roadmap_static_board();
$family      = array_key_first( $real_static );
// A deliberately stale `base`: differs from the CURRENT real static board at
// this cell, so the merge sees code as having moved it since the override was
// taken. `ours` differs again, from that stale base — both writers moved the
// same cell, which is the conflict shape.
$stale_base                       = $real_static;
$stale_base[ $family ]['planned'] = array( 'STALE PLANNED TEXT — not what static() says now' );
$ours                             = $stale_base;
$ours[ $family ]['planned']       = array( 'OVERRIDE PLANNED TEXT' );
snt_roadmap_store_envelope( $ours, $stale_base );

$report = sn_maturity_roadmap_effective_report();
ok( 1 === count( $report['conflicts'] ), 'sanity: the fixture actually produces one conflict before the check runs' );

$c = sn_health_check_roadmap_drift();
ok( 1 === $c['count'], 'the check reports exactly the one real conflict' );
ok( false !== strpos( $c['findings'][0], (string) $family ), 'the finding names the real family' );
ok( false !== strpos( $c['findings'][0], 'planned' ), 'and the real column' );
ok( false !== strpos( $c['fix_hint'], 'Reconcile the cell' ), 'a cell conflict gets the cell-reconciliation hint' );
ok( false === strpos( $c['fix_hint'], 'validation' ), 'not the invalid-board hint — the two fixes stay distinct' );

echo "\nGroup: a merge that fails validation, driven through the real validator\n";
$GLOBALS['snt_options'] = array();
$real_static = sn_maturity_roadmap_static_board();
$family      = array_key_first( $real_static );
// ours === base at every OTHER cell (no spurious conflicts); at this one cell
// ours carries a banned token, which sn_maturity_roadmap_board_problems()
// (the real validator) rejects — the merged board fails validation wholesale.
$base                          = $real_static;
$ours                          = $base;
$ours[ $family ]['considering'] = array( 'a sentence that leaks sn_mcp, a banned internal token' );
snt_roadmap_store_envelope( $ours, $base );

$report = sn_maturity_roadmap_effective_report();
ok( true === ( $report['invalid'] ?? false ), 'sanity: the fixture actually produces an invalid merge before the check runs' );
ok( array() === $report['conflicts'], 'and reports zero conflicts — the exact silent shape this check exists to end' );

$c = sn_health_check_roadmap_drift();
ok( 1 === $c['count'], 'the check reports the invalid merge as its own finding, not as zero' );
ok( false !== strpos( $c['findings'][0], 'validation' ), 'the finding says the merge failed validation' );
ok( false !== strpos( $c['fix_hint'], 'validation' ), 'an invalid board gets the validation-repair hint' );
ok( false === strpos( $c['fix_hint'], 'Reconcile the cell' ), 'not the cell-conflict hint — nothing here names a cell' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
