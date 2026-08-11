<?php
/**
 * Standalone test: Health check FAMILIES (inc/health-check-families.php) and the
 * report-only accessors (inc/health-summary.php), v10.83.0.
 *
 * THE LOAD-BEARING TEST IS THE EXHAUSTIVENESS ONE. The family map is
 * hand-maintained, and this repo's standing lesson is that a hand-maintained
 * list under-covers SILENTLY — a check added to sn_health_run_scan() without a
 * family entry would land in the `other` bucket and look deliberate. So this
 * suite reads the check keys straight out of inc/health-checks.php's source and
 * fails if any of them resolves to the fallback. The fallback stays in the
 * runtime (a live scan must never drop a check on the floor); the test is what
 * makes it loud.
 *
 * Run: php tests/health-check-families.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

require_once __DIR__ . '/../inc/health-check-families.php';
require_once __DIR__ . '/../inc/health-summary.php';

$pass = 0;
$fail = 0;
function hcf_ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "health-check-families suite — plugin v10.83.0\n";

// ─── Families ───────────────────────────────────────────────────────────────
echo "\n-- sn_health_check_families() --\n";
$families = sn_health_check_families();
hcf_ok( isset( $families['other'] ), 'an `other` fallback family exists' );
$keys = array_keys( $families );
hcf_ok( 'other' === end( $keys ), '`other` sorts LAST so an unmapped check never reads as a peer group' );
hcf_ok(
	isset( $families['content'], $families['links'], $families['a11y'], $families['ml'], $families['provenance'], $families['analytics'] ),
	'the six briefed families all exist'
);

// ─── EXHAUSTIVENESS: every scanned check key has an explicit family ─────────
echo "\n-- exhaustiveness against sn_health_run_scan()'s registry --\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' );
// The registry lines look like: 'missing_alt' => sn_health_check_missing_alt(),
$matched = preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*(?:sn|snt)_health_check_[a-z0-9_]+\(\s*\)/", $src, $m );
hcf_ok( $matched >= 15, "parsed the scan registry from source (found {$matched} check keys — a parse that found ~0 would make every assertion below vacuous)" );

$map     = sn_health_check_family_map();
$unmapped = array();
foreach ( $m[1] as $key ) {
	if ( ! isset( $map[ $key ] ) ) {
		$unmapped[] = $key;
	}
}
hcf_ok( empty( $unmapped ), 'every check in the scan registry has an explicit family: ' . ( empty( $unmapped ) ? 'all mapped' : 'UNMAPPED → ' . implode( ', ', $unmapped ) ) );

// And the reverse: no stale entries pointing at checks that no longer run.
$stale = array();
foreach ( array_keys( $map ) as $key ) {
	if ( ! in_array( $key, $m[1], true ) ) {
		$stale[] = $key;
	}
}
hcf_ok( empty( $stale ), 'no family entry names a check the scan no longer runs: ' . ( empty( $stale ) ? 'none stale' : 'STALE → ' . implode( ', ', $stale ) ) );

// Every family VALUE is a real family key.
$bad_family = array();
foreach ( $map as $key => $family ) {
	if ( ! isset( $families[ $family ] ) ) {
		$bad_family[] = "$key => $family";
	}
}
hcf_ok( empty( $bad_family ), 'every mapped family is a declared family: ' . ( empty( $bad_family ) ? 'all valid' : implode( ', ', $bad_family ) ) );

// ─── sn_health_check_family() fallback ──────────────────────────────────────
echo "\n-- sn_health_check_family() --\n";
hcf_ok( 'a11y' === sn_health_check_family( 'contrast_tokens' ), 'contrast_tokens is an accessibility check' );
hcf_ok( 'a11y' === sn_health_check_family( 'missing_alt' ), 'missing_alt is an accessibility check' );
hcf_ok( 'provenance' === sn_health_check_family( 'ledger_ci' ), 'ledger_ci sits with provenance & rights' );
hcf_ok( 'other' === sn_health_check_family( 'a_check_from_the_future' ), 'an unknown key falls back to `other` rather than vanishing' );

// ─── Grouping ───────────────────────────────────────────────────────────────
echo "\n-- sn_health_group_checks_by_family() --\n";
$grouped = sn_health_group_checks_by_family( array(
	'ledger_ci'     => array( 'label' => 'Ledger CI' ),
	'missing_alt'   => array( 'label' => 'Missing alt text' ),
	'broken_links'  => array( 'label' => 'Broken internal links' ),
	'mystery_check' => array( 'label' => 'Mystery' ),
) );
hcf_ok( array_keys( $grouped ) === array( 'links', 'a11y', 'provenance', 'other' ), 'groups come back in canonical family order, not input order' );
hcf_ok( 'Provenance & rights' === $grouped['provenance']['label'], 'each group carries its human label' );
hcf_ok( array_keys( $grouped['other']['checks'] ) === array( 'mystery_check' ), 'the unmapped check is PRESENT, in `other` — never dropped' );
hcf_ok( ! isset( $grouped['content'] ), 'empty families are omitted entirely' );

$total_out = 0;
foreach ( $grouped as $g ) { $total_out += count( $g['checks'] ); }
hcf_ok( 4 === $total_out, 'grouping is lossless: 4 in, 4 out' );

hcf_ok( array() === sn_health_group_checks_by_family( array() ), 'empty input → empty output' );

// ─── Report-only accessors (inc/health-summary.php) ─────────────────────────
echo "\n-- report-only tier accessors --\n";
$scan = array(
	'checks' => array(
		'missing_alt'     => array( 'label' => 'Missing alt text', 'count' => 3, 'findings' => array( 1, 2, 3 ) ),
		'broken_links'    => array( 'label' => 'Broken internal links', 'count' => 0, 'findings' => array() ),
		'ledger_ci'       => array( 'label' => 'Ledger CI', 'count' => 0, 'findings' => array() ),
		'contrast_tokens' => array(
			'label'    => 'Contrast (token arithmetic, report only)',
			'count'    => 0,
			'findings' => array(),
			'report'   => array( 'coverage' => 'Arithmetic tier only.', 'pairs' => array( array( 'pair' => 'a / b', 'ratio' => 2.1 ) ) ),
		),
	),
);

hcf_ok( true === sn_health_check_has_report( $scan['checks']['contrast_tokens'] ), 'has_report: a check carrying a report payload' );
hcf_ok( false === sn_health_check_has_report( $scan['checks']['broken_links'] ), 'has_report: a plain passing check does not' );
hcf_ok( false === sn_health_check_has_report( array( 'report' => array() ) ), 'has_report: an EMPTY report array is not a report (no payload = nothing to render)' );
hcf_ok( false === sn_health_check_has_report( array( 'report' => 'nope' ) ), 'has_report: a non-array report is not a report' );
hcf_ok( false === sn_health_check_has_report( null ), 'has_report: null is safe' );

hcf_ok( array_keys( sn_health_report_checks( $scan ) ) === array( 'contrast_tokens' ), 'report_checks: exactly the report-only check' );
hcf_ok( array() === sn_health_report_checks( null ), 'report_checks: null scan → empty' );

$passing = sn_health_passing_checks( $scan );
hcf_ok( array_keys( $passing ) === array( 'broken_links', 'ledger_ci' ), 'passing_checks: zero-finding checks WITHOUT a report' );
hcf_ok( ! isset( $passing['contrast_tokens'] ), 'passing_checks: the report-only check is NOT counted as passing — it cannot earn that verdict' );
hcf_ok( ! isset( $passing['missing_alt'] ), 'passing_checks: a flagged check is not passing' );
hcf_ok( array() === sn_health_passing_checks( null ), 'passing_checks: null scan → empty' );

// The denominator contract: check_total still counts EVERY check, so no other
// surface has to be re-derived. 2 passing + 1 flagged + 1 report = 4.
hcf_ok( 4 === sn_health_check_total( $scan ), 'check_total is UNCHANGED — it still counts every check the scan ran' );
hcf_ok(
	count( $passing ) + count( sn_health_flagged_checks( $scan ) ) + count( sn_health_report_checks( $scan ) ) === sn_health_check_total( $scan ),
	'the three-way split is exhaustive and non-overlapping: passing + flagged + reports === total'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
