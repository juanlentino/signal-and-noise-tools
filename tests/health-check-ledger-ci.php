<?php
/**
 * Tests: public-ledger CI health check (v10.4.0).
 *
 * Born from the 2026-07-25..28 incident: the ledger's daily verification ran
 * red for three days, unseen. The pure evaluator is driven through every
 * shape the GitHub runs endpoint can answer with; the wiring pins keep the
 * orchestrator and loader honest.
 *
 * Run: php tests/health-check-ledger-ci.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// Mirror the REAL envelope builder (inc/health-checks.php), as the sibling
// rights-signals fixture does.
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array(
		'count'    => count( $findings ),
		'findings' => $findings,
		'label'    => $label,
		'fix_hint' => $fix_hint,
	);
}
$GLOBALS['__resp'] = array( 'code' => 200, 'body' => '' );
function wp_remote_get( $url, $args = array() ) { $GLOBALS['__req'] = array( 'url' => $url, 'args' => $args ); return array(); }
function wp_remote_retrieve_response_code( $r ) { return $GLOBALS['__resp']['code']; }
function wp_remote_retrieve_body( $r ) { return $GLOBALS['__resp']['body']; }
function is_wp_error( $x ) { return false; }

require __DIR__ . '/../inc/health-check-ledger-ci.php';

function run_body( $conclusion, $when = '2026-07-29T01:32:00Z' ) {
	return array( 'workflow_runs' => array( array(
		'conclusion' => $conclusion,
		'updated_at' => $when,
		'html_url'   => 'https://github.com/juanlentino/signal-and-noise-provenance/actions/runs/1',
	) ) );
}

echo "Group: pure evaluator\n";
$v = snt_ledger_ci_evaluate( run_body( 'success' ) );
ok( 'ok' === $v['state'], 'a green latest run evaluates ok' );
$v = snt_ledger_ci_evaluate( run_body( 'failure', '2026-07-28T09:04:00Z' ) );
ok( 'red' === $v['state'] && false !== strpos( $v['detail'], 'failure' ) && false !== strpos( $v['detail'], '2026-07-28T09:04:00Z' ), 'a red run names the conclusion and WHEN — three unseen days was the incident' );
ok( '' !== $v['run_url'], 'the red verdict carries the run URL' );
$v = snt_ledger_ci_evaluate( run_body( 'cancelled' ) );
ok( 'red' === $v['state'], 'cancelled is not green (never silently passes)' );
$v = snt_ledger_ci_evaluate( array( 'workflow_runs' => array() ) );
ok( 'unknown' === $v['state'], 'no completed runs is unknown, not red' );
$v = snt_ledger_ci_evaluate( null );
ok( 'unknown' === $v['state'], 'garbage decodes to unknown, never a fatal' );

echo "\nGroup: pack envelope\n";
$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( run_body( 'success' ) ) );
$pack = snt_health_check_ledger_ci();
ok( 0 === $pack['count'] && '' === $pack['fix_hint'], 'green ledger packs zero findings, no hint' );
ok( false !== strpos( (string) ( $GLOBALS['__req']['args']['headers']['User-Agent'] ?? '' ), 'ledger-ci-check' ), 'the probe identifies itself to the GitHub API' );
$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( run_body( 'failure' ) ) );
$pack = snt_health_check_ledger_ci();
ok( 1 === $pack['count'] && 'ledger_ci' === $pack['findings'][0]['subject_type'], 'red ledger raises exactly one finding' );
ok( false !== strpos( $pack['findings'][0]['subject_url'], 'actions' ), 'the finding links to the run' );
$GLOBALS['__resp'] = array( 'code' => 403, 'body' => '' );
$pack = snt_health_check_ledger_ci();
ok( 0 === $pack['count'] && false !== stripos( $pack['fix_hint'], 'gap in evidence' ), 'an unreachable API is an ADVISORY, never a red finding (outage is not drift)' );
$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array( 'workflow_runs' => array() ) ) );
$pack = snt_health_check_ledger_ci();
ok( 0 === $pack['count'] && '' !== $pack['fix_hint'], 'no-runs-yet packs zero findings with the honest note as hint' );

echo "\nGroup: wiring\n";
$orch = (string) file_get_contents( __DIR__ . '/../inc/health-checks.php' );
ok( false !== strpos( $orch, "'ledger_ci'" ) && false !== strpos( $orch, 'snt_health_check_ledger_ci()' ), 'orchestrator runs the check' );
ok( false !== strpos( $orch, "health-check-ledger-ci.php" ), 'orchestrator requires the module' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
