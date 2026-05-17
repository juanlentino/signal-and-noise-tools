<?php
/**
 * Signal & Noise Tools — Deploy status sections (Dashboard tab).
 *
 * Hooks the `sn_admin_dashboard_extras` action (fired at the end of
 * the Dashboard tab in inc/admin-page.php) to add three read-only
 * status sections + a force-check action button:
 *
 *   1. Theme + plugin version comparison (vs. latest GitHub tag).
 *   2. Recent deploys (last 5 GHA workflow runs across both repos).
 *   3. External API limits (live rate-limit snapshots — see
 *      inc/api-rate-monitor.php).
 *   4. Force-check updates button (admin-post.php handler).
 *
 * Why this lives here and not as a WP dashboard widget: dashboard
 * widgets were tried in v1.12.0 and rejected as cluttering the WP
 * dashboard surface. The SN admin pages are the canonical home for
 * operational info; this file just extends the existing Dashboard tab.
 * (See memory: feedback_no_dashboard_widgets.md)
 *
 * Verified against WP source:
 *   - admin-post.php reads $action from $_REQUEST and fires
 *     admin_post_{$action} for logged-in users; no automatic nonce.
 *
 * Added in v1.13.0 (2026-05-16). Replaces inc/deploy-widget.php which
 * shipped briefly in v1.12.0 and was removed in v1.13.0.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DEPLOY_REPOS = array(
	'theme'  => 'juanlentino/signal-and-noise',
	'plugin' => 'juanlentino/signal-and-noise-tools',
);

/**
 * Compute a normalized status struct for one package.
 *
 * @param string $package 'theme' | 'plugin'
 * @return array { current, latest, state ('ok'|'available'|'unknown'), repo }
 */
function snt_deploy_status_for( $package ) {
	if ( 'theme' === $package ) {
		$current = (string) wp_get_theme( 'signal-and-noise' )->get( 'Version' );
		$latest  = function_exists( 'sn_gh_latest_theme_tag' ) ? sn_gh_latest_theme_tag() : null;
	} else {
		$current = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
		$latest  = function_exists( 'sn_gh_latest_plugin_tag' ) ? sn_gh_latest_plugin_tag() : null;
	}
	$latest_version = $latest ? ltrim( $latest, 'v' ) : '';
	$state          = 'unknown';
	if ( $current && $latest_version ) {
		$state = version_compare( $latest_version, $current, '>' ) ? 'available' : 'ok';
	}
	return array(
		'current' => $current,
		'latest'  => $latest_version,
		'state'   => $state,
		'repo'    => SNT_DEPLOY_REPOS[ $package ] ?? '',
	);
}

/**
 * Render — hooked late on sn_admin_dashboard_extras so we sit beneath
 * the existing Status + Actions sections on the Dashboard tab.
 */
add_action( 'sn_admin_dashboard_extras', 'snt_deploy_status_render', 20 );

function snt_deploy_status_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$theme  = snt_deploy_status_for( 'theme' );
	$plugin = snt_deploy_status_for( 'plugin' );

	echo '<hr style="margin:1.5em 0;">';
	echo '<h2 class="sn-section-h">Deploy status</h2>';

	// ── VERSIONS TABLE ──
	echo '<table class="form-table sn-status-table" style="max-width:680px;">';
	foreach ( array( 'Theme' => $theme, 'Plugin' => $plugin ) as $label => $pkg ) {
		echo '<tr>';
		echo '<th>' . esc_html( $label ) . ' version</th>';
		echo '<td><code>' . esc_html( $pkg['current'] ?: '—' ) . '</code></td>';
		echo '<td>' . snt_deploy_state_pill_html( $pkg ) . '</td>';
		echo '<td style="text-align:right;">';
		if ( $pkg['repo'] ) {
			echo '<a href="' . esc_url( 'https://github.com/' . $pkg['repo'] ) . '" target="_blank" rel="noopener noreferrer">repo &#x2197;</a>';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</table>';

	// ── RECENT DEPLOYS ──
	$runs = function_exists( 'snt_gh_recent_runs_merged' )
		? snt_gh_recent_runs_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();
	echo '<h3 class="sn-subsection-h" style="margin-top:1.5em;">Recent deploys</h3>';
	if ( empty( $runs ) ) {
		echo '<p class="sn-helper"><em>No recent runs (or GitHub API unreachable).</em></p>';
	} else {
		echo '<table class="widefat striped" style="max-width:760px;">';
		echo '<thead><tr><th style="width:60px;">Repo</th><th>Ref</th><th>Trigger</th><th>Status</th><th>Duration</th><th>When</th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( snt_deploy_short_repo( $run['repo'] ?? '' ) ) . '</code></td>';
			echo '<td><code>' . esc_html( $run['ref'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $run['trigger'] ?? '' ) . '</td>';
			echo '<td>' . snt_deploy_run_status_html( $run ) . '</td>';
			echo '<td>' . esc_html( snt_deploy_duration_label( $run['duration_s'] ?? null ) ) . '</td>';
			echo '<td><span class="sn-helper" style="margin:0;">' . esc_html( snt_deploy_relative_time( $run['created_at'] ?? '' ) ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// ── API LIMITS ──
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		$statuses = snt_rate_limit_all_statuses();
		echo '<h3 class="sn-subsection-h" style="margin-top:1.5em;">External API limits</h3>';
		echo '<table class="form-table sn-status-table" style="max-width:680px;">';
		foreach ( $statuses as $host => $info ) {
			$snap  = $info['snapshot'];
			$label = $info['label'];
			echo '<tr>';
			echo '<th>' . esc_html( $label ) . '</th>';
			echo '<td><code>' . esc_html( $host ) . '</code></td>';
			echo '<td>';
			if ( $snap ) {
				$pct = (int) round( ( $snap['remaining'] / max( 1, $snap['limit'] ) ) * 100 );
				echo esc_html( number_format_i18n( $snap['remaining'] ) . ' / ' . number_format_i18n( $snap['limit'] ) . ' (' . $pct . '%)' );
			} else {
				echo '<span class="sn-helper" style="margin:0;"><em>no data yet</em></span>';
			}
			echo '</td>';
			echo '<td>' . snt_deploy_rate_pill_html( $snap ) . '</td>';
			echo '</tr>';
		}
		echo '</table>';
		echo '<p class="sn-helper">Snapshots are updated whenever this site makes an outgoing request to one of these hosts. <code>SNT_GITHUB_TOKEN</code> raises the GitHub limit from 60/h to 5000/h.</p>';
	}

	// ── FORCE-CHECK BUTTON ──
	echo '<p style="margin-top:1.5em;">';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
	echo '<input type="hidden" name="action" value="sn_force_update_check">';
	wp_nonce_field( 'sn_force_update_check', 'sn_force_update_check_nonce' );
	echo '<button type="submit" class="button button-secondary">Force-check updates</button>';
	echo '</form>';
	echo ' <span class="sn-helper">Clears the GitHub + WordPress update caches and redirects to <code>update-core.php?force-check=1</code>.</span>';
	echo '</p>';
}

function snt_deploy_state_pill_html( $pkg ) {
	switch ( $pkg['state'] ) {
		case 'ok':
			return '<span class="sn-pill sn-pill--ok">up to date</span>';
		case 'available':
			return '<span class="sn-pill sn-pill--warn">v' . esc_html( $pkg['latest'] ) . ' available</span>';
		default:
			return '<span class="sn-pill sn-pill--err">unknown</span>';
	}
}

function snt_deploy_run_status_html( $run ) {
	$status     = (string) ( $run['status'] ?? '' );
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	if ( 'in_progress' === $status || 'queued' === $status ) {
		return '<span class="sn-pill sn-pill--warn">running</span>';
	}
	if ( 'success' === $conclusion ) {
		return '<span class="sn-pill sn-pill--ok">success</span>';
	}
	if ( 'cancelled' === $conclusion || 'skipped' === $conclusion ) {
		return '<span class="sn-pill sn-pill--warn">' . esc_html( $conclusion ) . '</span>';
	}
	if ( $conclusion ) {
		return '<span class="sn-pill sn-pill--err">' . esc_html( $conclusion ) . '</span>';
	}
	return '<span class="sn-pill sn-pill--err">unknown</span>';
}

function snt_deploy_rate_pill_html( $snap ) {
	if ( ! is_array( $snap ) || empty( $snap['limit'] ) ) {
		return '<span class="sn-pill sn-pill--err">no data</span>';
	}
	$pct = $snap['remaining'] / $snap['limit'];
	if ( $pct < 0.10 ) {
		return '<span class="sn-pill sn-pill--err">critical</span>';
	}
	if ( $pct < 0.25 ) {
		return '<span class="sn-pill sn-pill--warn">low</span>';
	}
	return '<span class="sn-pill sn-pill--ok">ok</span>';
}

function snt_deploy_short_repo( $repo ) {
	if ( str_ends_with( $repo, '-tools' ) ) {
		return 'plugin';
	}
	return $repo ? 'theme' : '';
}

function snt_deploy_duration_label( $seconds ) {
	if ( ! is_int( $seconds ) ) {
		return '—';
	}
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	return sprintf( '%dm %ds', intdiv( $seconds, 60 ), $seconds % 60 );
}

function snt_deploy_relative_time( $iso ) {
	if ( ! $iso ) {
		return '';
	}
	$t = strtotime( $iso );
	return $t ? human_time_diff( $t, time() ) . ' ago' : '';
}

/**
 * Force-check handler. POST target for the Dashboard tab's button.
 *
 * Per WP convention: admin-post.php reads $action from $_REQUEST and
 * fires admin_post_{$action} for logged-in users. No automatic nonce
 * verification — we do it ourselves below.
 */
add_action( 'admin_post_sn_force_update_check', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_force_update_check', 'sn_force_update_check_nonce' );

	delete_site_transient( 'sn_gh_latest_theme' );
	delete_site_transient( 'sn_gh_latest_plugin' );
	foreach ( array_values( SNT_DEPLOY_REPOS ) as $repo ) {
		delete_site_transient( 'sn_gh_recent_runs_' . sanitize_key( str_replace( '/', '-', $repo ) ) );
	}
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );
