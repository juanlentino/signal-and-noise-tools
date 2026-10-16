<?php
/**
 * Signal & Noise Tools: abilities-system.php, the deploy-status callback,
 * its labels, and the template-override callbacks.
 *
 * Split from inc/abilities-system.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for signal-noise/get-deploy-status.
 */
function snt_ability_get_deploy_status( $input = null ) {
	if ( ! function_exists( 'snt_deploy_status_for' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Deploy status helper unavailable.', array( 'status' => 500 ) );
	}

	// v7.7.0: force_refresh clears the GitHub-tag + WP update transients first
	// (the removed force-check-updates ability's whole job), so one call
	// both busts the caches and returns the freshly-fetched status.
	// Worker live/tag caches (inc/deploy-workers.php) clear alongside so the
	// additive `workers` rows re-probe rather than serving a stale unknown.
	if ( is_array( $input ) && ! empty( $input['force_refresh'] ) ) {
		if ( function_exists( 'snt_cmd_impl_force_check' ) ) {
			snt_cmd_impl_force_check();
		}
		if ( function_exists( 'snt_deploy_workers_flush_caches' ) ) {
			snt_deploy_workers_flush_caches();
		}
	}

	// v6.55.0: fold in last_deploy so the desktop-mode deploy-status widget
	// keeps its "Last deploy: … ago" line after migrating off /cmd/status.
	//
	// v9.63.3: last_deploy now reads the MERGED deploy feed (wp-admin Updates
	// installs recorded by inc/deploy-history.php + GHA runs) — the same source
	// the admin Dashboard switched to in v4.1.4. The GHA-only reading froze
	// forever once deploy.yml went workflow_dispatch-only (v1.10.1; emergency
	// fallback), because real releases land via wp-admin → Updates and never
	// fire a workflow run. The GHA-only datum is NOT dropped: it ships
	// additively as last_gha_run (consumers pin last_deploy; its meaning —
	// "when did the last deploy happen" — is unchanged, only the broken source
	// is fixed). snt_gh_recent_runs_merged is cache-backed (the widget's 60s
	// cadence matches its TTL), so both reads stay cheap.
	// v12.13.0: each reading keeps the ROWS it came from, not just the age
	// string. The newest record already knows its repo; discarding that is what
	// left the widget saying "Last deploy: 40 minutes ago" under seven
	// independently versioned rows, with no way to tell which one it meant.
	// Reading the feed a second time to recover the name would risk labelling a
	// different record than the age describes, if a deploy landed in between.
	$repos         = array( 'juanlentino/signal-and-noise', 'juanlentino/signal-and-noise-tools' );
	$last_gha_run  = '';
	$last_gha_rows = array();
	if ( function_exists( 'snt_gh_recent_runs_merged' ) ) {
		$last_gha_rows = snt_gh_recent_runs_merged( $repos, 1 );
		$last_gha_run  = snt_deploy_runs_age_label( $last_gha_rows );
	}

	if ( function_exists( 'snt_deploy_history_merged' ) ) {
		$newest                = snt_deploy_history_merged( $repos, 1 );
		$last_deploy           = snt_deploy_runs_age_label( $newest );
		$last_deploy_component = snt_deploy_runs_source_label( $newest );
	} else {
		// Degraded fallback (deploy-history module absent): the pre-v9.63.3
		// GHA-only reading — stale-prone but better than nothing. It is labelled
		// from its own rows, so the degraded path is named as precisely as the
		// healthy one rather than falling back to an anonymous age.
		$last_deploy           = $last_gha_run;
		$last_deploy_component = snt_deploy_runs_source_label( $last_gha_rows );
	}

	$workers = function_exists( 'snt_deploy_workers_status' )
		? snt_deploy_workers_status( array(
			'force'        => is_array( $input ) && ! empty( $input['force_refresh'] ),
			'probe_budget' => 5,
		) )
		: array();

	return array(
		'theme'                 => snt_deploy_status_for( 'theme' ),
		'plugin'                => snt_deploy_status_for( 'plugin' ),
		'last_deploy'           => $last_deploy,
		'last_deploy_component' => $last_deploy_component,
		'last_gha_run'          => $last_gha_run,
		'workers'               => $workers,
		// When the version probe last saw a worker change (inc/deploy-workers-seen.php):
		// { label, version, at } or { since } until a change is seen; null before any probe.
		'last_worker_deploy'    => function_exists( 'snt_deploy_workers_seen_last' ) ? snt_deploy_workers_seen_last( get_option( SNT_DEPLOY_WORKERS_SEEN_OPT, array() ), array_column( $workers, 'label', 'id' ) ) : null,
		// Contract 13: inc/deploy-core-status.php. Guarded so a harness that
		// loads only this file still gets a payload.
		'core'                  => function_exists( 'snt_core_status' ) ? snt_core_status() : array( 'current' => '', 'latest' => '', 'state' => 'unknown', 'offer' => '', 'auto_updates' => 'off', 'reason' => 'core status module not loaded' ),
		'runtime'               => function_exists( 'snt_runtime_status' ) ? snt_runtime_status() : array( 'php' => PHP_VERSION, 'register_argc_argv' => false ),
	);
}

/**
 * Which package the newest record in a deploy-runs list belongs to.
 *
 * snt_deploy_runs_age_label() answers "when"; this answers "what". The record
 * carries its own repo and the age label throws it away, which is precisely
 * what made the Deploy Status footer unreadable: seven independently versioned
 * rows above a bare "Last deploy: 40 minutes ago".
 *
 * Theme and plugin only — and that is not a gap to close here. The five
 * Cloudflare workers never install through the WP upgrader, so they have no
 * history rows at all (see SNT_DEPLOY_HISTORY_PACKAGES, which says so and says
 * not to add them). Naming the package is therefore the scope disclosure too:
 * "Plugin" cannot be misread as covering Rights signals the way a bare age
 * could, and did.
 *
 * @since 12.13.0
 * @param array $runs Records in the snt_gh_recent_runs_merged() shape, newest first.
 * @return string Capitalised package handle ('Theme', 'Plugin'), or '' when the
 *                row carries no repo or the repo maps to no known package.
 */
function snt_deploy_runs_source_label( $runs ) {
	if ( ! is_array( $runs ) || empty( $runs[0]['repo'] ) ) {
		return '';
	}
	$repo = (string) $runs[0]['repo'];
	$map  = defined( 'SNT_DEPLOY_HISTORY_PACKAGES' ) ? SNT_DEPLOY_HISTORY_PACKAGES : array();
	foreach ( $map as $key => $meta ) {
		if ( isset( $meta['repo'] ) && (string) $meta['repo'] === $repo ) {
			return ucfirst( (string) $key );
		}
	}
	return '';
}

/**
 * "X ago" label for the newest record in a deploy-runs list, or '' if the
 * list is empty / carries no parseable created_at. Shared by the merged-feed
 * and GHA-only readings in snt_ability_get_deploy_status().
 *
 * @since 9.63.3
 * @param array $runs Records in the snt_gh_recent_runs_merged() shape, newest first.
 * @return string
 */
function snt_deploy_runs_age_label( $runs ) {
	if ( is_array( $runs ) && ! empty( $runs[0]['created_at'] ) ) {
		$t = strtotime( (string) $runs[0]['created_at'] );
		if ( $t ) {
			return human_time_diff( $t, time() ) . ' ago';
		}
	}
	return '';
}

/**
 * Execute callback for signal-noise/clear-template-overrides.
 */
function snt_ability_clear_template_overrides() {
	if ( ! has_filter( 'sn_clear_template_overrides_result' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Template override helper unavailable: theme module not loaded.', array( 'status' => 500 ) );
	}

	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );

	return array(
		'ok'      => true,
		'count'   => $count,
		'message' => sprintf( '%d database template override%s cleared.', $count, 1 === $count ? '' : 's' ),
	);
}

/**
 * Ability execute callback: signal-noise/list-template-overrides.
 * Read-only inspection of wp_template / wp_template_part / wp_navigation rows.
 * @since 3.7.5
 */
function snt_ability_list_template_overrides() {
	$rows = get_posts( array(
		'post_type'      => array( 'wp_template', 'wp_template_part', 'wp_navigation' ),
		'posts_per_page' => -1,
		'post_status'    => 'any',
	) );
	$items = array();
	foreach ( $rows as $row ) {
		$items[] = array(
			'post_type' => $row->post_type,
			'slug'      => $row->post_name,
			'id'        => (int) $row->ID,
		);
	}
	return array(
		'ok'    => true,
		'count' => count( $items ),
		'items' => $items,
	);
}

