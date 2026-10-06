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
