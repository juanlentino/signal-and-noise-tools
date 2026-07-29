<?php
/**
 * Signal & Noise Tools — Content Health check: public ledger CI status.
 *
 * v10.4.0, born from a live incident: the provenance ledger's own daily
 * verification (signal-and-noise-provenance verify.yml) ran RED for three
 * days (2026-07-25..28) while two index rows and a dead verifier fallback
 * accumulated — detection existed the whole time, but workflow failures live
 * where nobody looks. This check reads the repo's latest completed verify
 * run from the public GitHub API (no auth, fixed URL, never configurable)
 * and raises the existing Health attention chip when it is not green.
 *
 * The pure evaluator (tests/health-check-ledger-ci.php) is the tested
 * surface; the fetch wrapper stays thin. An unreachable API is an ADVISORY
 * with zero findings — an outage is a gap in evidence, never evidence of a
 * red ledger (the rights-drift check's own convention).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LEDGER_CI_RUNS_URL = 'https://api.github.com/repos/juanlentino/signal-and-noise-provenance/actions/workflows/verify.yml/runs?status=completed&per_page=1';

/**
 * Pure evaluator: the decoded GitHub workflow-runs response in, one verdict
 * out. Reads only; calls no WordPress function.
 *
 * @param mixed $decoded Decoded JSON from the runs endpoint, or null.
 * @return array{state:string,detail:string,run_url:string}
 *               state: ok | red | unknown.
 */
function snt_ledger_ci_evaluate( $decoded ) {
	if ( ! is_array( $decoded ) || ! isset( $decoded['workflow_runs'] ) || ! is_array( $decoded['workflow_runs'] ) ) {
		return array(
			'state'   => 'unknown',
			'detail'  => 'The GitHub response did not carry a workflow_runs list.',
			'run_url' => '',
		);
	}
	if ( array() === $decoded['workflow_runs'] ) {
		// A repo with no completed runs yet: nothing to judge, say so.
		return array(
			'state'   => 'unknown',
			'detail'  => 'No completed verification runs exist yet.',
			'run_url' => '',
		);
	}
	$run        = (array) $decoded['workflow_runs'][0];
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	$when       = (string) ( $run['updated_at'] ?? ( $run['created_at'] ?? '' ) );
	$url        = (string) ( $run['html_url'] ?? '' );
	if ( 'success' === $conclusion ) {
		return array(
			'state'   => 'ok',
			'detail'  => 'Latest completed verification run succeeded' . ( '' !== $when ? ' (' . $when . ')' : '' ) . '.',
			'run_url' => $url,
		);
	}
	return array(
		'state'   => 'red',
		'detail'  => 'The public ledger\'s latest completed verification run concluded "' . ( '' !== $conclusion ? $conclusion : 'unknown' ) . '"' . ( '' !== $when ? ' at ' . $when : '' ) . ' — the trust repo is reporting a problem nobody may have seen.',
		'run_url' => $url,
	);
}

/**
 * The check: fetch the latest completed verify run, evaluate, pack.
 *
 * @return array sn_health_pack_check envelope.
 */
function snt_health_check_ledger_ci() {
	$label    = 'Public ledger CI is green';
	$fix_hint = 'Open the run, read the failing step, and fix in the signal-and-noise-provenance repo — its CHANGELOG and verify-*.mjs headers name each check\'s intent.';

	$resp = wp_remote_get( SN_LEDGER_CI_RUNS_URL, array(
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' ledger-ci-check',
		),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return sn_health_pack_check( $label, array(), 'Could not reach the GitHub API for the ledger repo (an outage is a gap in evidence, not a red ledger). The check retries on the next scan.' );
	}

	$verdict = snt_ledger_ci_evaluate( json_decode( (string) wp_remote_retrieve_body( $resp ), true ) );
	if ( 'red' !== $verdict['state'] ) {
		// ok AND unknown both pack zero findings; unknown carries its note as
		// the hint so the scan is honest about what it could not judge.
		return sn_health_pack_check( $label, array(), 'ok' === $verdict['state'] ? '' : $verdict['detail'] );
	}

	return sn_health_pack_check( $label, array(
		array(
			'subject_type'  => 'ledger_ci',
			'subject_id'    => 0,
			'subject_url'   => '' !== $verdict['run_url'] ? $verdict['run_url'] : 'https://github.com/juanlentino/signal-and-noise-provenance/actions',
			'subject_label' => 'verify.yml',
			'edit_url'      => '',
			'note'          => $verdict['detail'],
		),
	), $fix_hint );
}
