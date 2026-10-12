<?php
/**
 * Signal & Noise — Dashboard deploy-run rows.
 *
 * Formatting helpers for GitHub Actions / wp-admin install runs: the last-deploy
 * label, the recent-run count, the repo short name and the relative time. The
 * per-run row renderer and its glyph and duration helpers were removed once
 * nothing rendered a deploy row. Split out of
 * admin-tab-dashboard.php in v11.28.0 — the orchestrator composes, it does not
 * format.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** "14m ago" / "—" for the Deploys card. */
function snt_dashboard_last_deploy_label( $runs ) {
	foreach ( $runs as $run ) {
		if ( ! empty( $run['created_at'] ) ) {
			$t = strtotime( $run['created_at'] );
			if ( $t ) {
				return human_time_diff( $t, time() ) . ' ago';
			}
		}
	}
	return '—';
}

/** Count runs within $window seconds of now. */
function snt_dashboard_count_recent_runs( $runs, $window ) {
	$cutoff = time() - $window;
	$n      = 0;
	foreach ( $runs as $run ) {
		if ( empty( $run['created_at'] ) ) {
			continue;
		}
		$t = strtotime( $run['created_at'] );
		if ( $t && $t >= $cutoff ) {
			++$n;
		}
	}
	return $n;
}

/**
 * The repos whose NEWEST run failed, one entry each. Only the newest run per
 * repo counts, so a failure a later success fixed clears at once (2026.10.4
 * audit: any failure among the last six runs held Home red until six more
 * deploys landed), and a run still going raises nothing yet. The link is the
 * run's own page; a wp-admin install row has none, so its repo's Actions list
 * stands in.
 *
 * @param array $runs Merged runs, newest first (snt_deploy_history_merged()).
 * @return array<int,array{repo:string,conclusion:string,href:string}>
 */
function snt_dashboard_failed_deploys( $runs ) {
	$seen = array();
	$out  = array();
	foreach ( (array) $runs as $run ) {
		if ( ! is_array( $run ) ) {
			continue;
		}
		$repo = (string) ( $run['repo'] ?? '' );
		if ( isset( $seen[ $repo ] ) ) {
			continue;
		}
		$seen[ $repo ] = true;
		$conclusion    = (string) ( $run['conclusion'] ?? '' );
		if ( 'completed' !== (string) ( $run['status'] ?? '' ) || '' === $conclusion || in_array( $conclusion, array( 'success', 'cancelled', 'skipped' ), true ) ) {
			continue;
		}
		$url   = (string) ( $run['html_url'] ?? '' );
		$out[] = array(
			'repo'       => $repo,
			'conclusion' => $conclusion,
			'href'       => '' !== $url ? $url : ( '' !== $repo ? 'https://github.com/' . $repo . '/actions' : '' ),
		);
	}
	return $out;
}

function snt_dashboard_short_repo( $repo ) {
	if ( str_ends_with( $repo, '-tools' ) ) {
		return 'plugin';
	}
	return $repo ? 'theme' : '';
}

function snt_dashboard_relative_time( $iso ) {
	if ( ! $iso ) {
		return '';
	}
	$t = strtotime( $iso );
	return $t ? human_time_diff( $t, time() ) . ' ago' : '';
}
