<?php
/**
 * Signal & Noise Tools — Abilities API: system maintenance + deploy state.
 *
 * Five abilities covering cache/template/update housekeeping plus the
 * deploy-status surfaces:
 *   - signal-noise/purge-all-caches         (destructive, idempotent; its
 *     include_template_overrides flag replaced the removed full-reset)
 *   - signal-noise/clear-template-overrides (destructive, idempotent)
 *   - signal-noise/list-template-overrides  (readonly, idempotent)
 *   - signal-noise/get-deploy-status         (readonly, idempotent; force_refresh
 *     clears the update transients first — replaced the removed
 *     force-check-updates)
 *     recategorized diagnostics → ai-generation in 7.7.0)
 *
 * v8.0.0 removed the three 7.7.0-deprecated abilities this file carried
 * (full-reset, force-check-updates, list-abilities — the last replaced by
 * the core catalogue GET /wp-abilities/v1/abilities).
 *
 * Categories: maintenance / diagnostics. File grouping is by feature
 * (system housekeeping) rather than category so related impls stay
 * co-located.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 2.0.4 + 2.5.0 + 3.7.5)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/purge-all-caches', array(
		'label'               => 'Purge all caches',
		'description'         => 'Clears WordPress object cache, transients, Breeze page cache, Varnish, and Cloudflare edge cache. Use after deploys or when content appears stale. The response reports whether the Cloudflare zone purge was actually confirmed (v10.4.1); ok is false when the CF leg could not run or was rejected.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_purge_all_caches',
		'input_schema'        => array(
			// v2.5.4: accept null because the abilities-api REST controller
			// passes null when the GET/DELETE caller omits the `?input=`
			// query parameter (the only way to avoid the controller's
			// missing JSON-decode step rejecting URL-encoded "{}" strings).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'include_template_overrides' => array(
					'type'        => 'boolean',
					'description' => 'Also clear wp_template/wp_template_part/wp_navigation DB rows. Default false — overrides are typically intentional Site Editor changes.',
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean', 'description' => 'False when the Cloudflare edge purge could not run (not configured) or was rejected by the API; origin caches are still purged in both cases (v10.4.1).' ),
				'message' => array( 'type' => 'string' ),
				'count'   => array( 'type' => 'integer', 'description' => 'Number of overrides cleared (0 if include_template_overrides was false).' ),
				'cloudflare' => array(
					'type'        => 'object',
					'description' => 'Verdict for the CF edge leg (v10.4.1). status: confirmed = CF accepted the zone purge ({success:true}); failed = CF rejected it (see http); not_configured = no API token/zone, the purge never ran; unconfirmed = dispatched but no verified report came back (theme < 10.23.0).',
					'properties'  => array(
						'status'     => array( 'type' => 'string', 'enum' => array( 'confirmed', 'failed', 'not_configured', 'unconfirmed' ) ),
						'http'       => array( 'type' => 'integer' ),
						'edge_fresh' => array( 'type' => 'boolean', 'description' => 'Present when the theme probed routes post-purge: whether the edge served the fresh render epoch.' ),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-deploy-status', array(
		'label'               => 'Get theme + plugin deploy status',
		'description'         => 'Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available. Pass force_refresh=true to clear the GitHub-tag + update_themes/update_plugins transients first so the answer is freshly fetched (replaces the removed force-check-updates ability; clears caches only, never user data). Read-only; safe to call anytime.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_deploy_status',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment — null accepted because
			// readonly abilities (GET) receive null when caller omits ?input=.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'force_refresh' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Clear the sn_gh_latest_* + update transients before reading, forcing a fresh GitHub fetch.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'theme'  => array(
					'type'       => 'object',
					'properties' => array(
						'current' => array( 'type' => 'string' ),
						'latest'  => array( 'type' => 'string' ),
						'state'   => array( 'type' => 'string', 'enum' => array( 'ok', 'available', 'unknown' ) ),
					),
				),
				'plugin' => array(
					'type'       => 'object',
					'properties' => array(
						'current' => array( 'type' => 'string' ),
						'latest'  => array( 'type' => 'string' ),
						'state'   => array( 'type' => 'string', 'enum' => array( 'ok', 'available', 'unknown' ) ),
					),
				),
				'last_deploy' => array(
					'type'        => 'string',
					'description' => 'Relative time of the most recent deploy across both repos (e.g. "3 hours ago") from the MERGED feed — wp-admin Updates installs + deploy GHA runs, the same source as the admin Dashboard. Empty string if unknown. Added v6.55.0; reads the merged feed since v9.63.3 (GHA-only before, which froze once deploy.yml went workflow_dispatch-only).',
				),
				'last_gha_run' => array(
					'type'        => 'string',
					'description' => 'Relative time of the most recent deploy GHA workflow run across both repos — the pre-v9.63.3 last_deploy reading, kept as a clearly-labeled secondary field. deploy.yml is the workflow_dispatch-only emergency fallback, so this moves only on manual dispatches. Empty string if unknown. Added v9.63.3.',
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations' => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/clear-template-overrides', array(
		'label'               => 'Clear database template overrides',
		'description'         => 'Removes any wp_template, wp_template_part, or wp_navigation rows the Site Editor has saved that override the theme files. Returns the count cleared. Use this if Site Editor edits have introduced regressions.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_clear_template_overrides',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment — destructive abilities
			// (DELETE) also receive null when caller omits ?input=.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'count'   => array( 'type' => 'integer' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );


	wp_register_ability( 'signal-noise/list-template-overrides', array(
		'label'               => 'List database template overrides',
		'description'         => 'Returns the slugs and post types of any wp_template / wp_template_part / wp_navigation rows currently overriding theme files. Read-only inspection before the destructive clear-template-overrides.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_list_template_overrides',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'    => array( 'type' => 'boolean' ),
				'count' => array( 'type' => 'integer' ),
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
							'slug'      => array( 'type' => 'string' ),
							'id'        => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	// v9.78.0: the health scan is cached 24h with manual re-scan only — the
	// re-scan lived exclusively behind the admin page button, making it the
	// one one-shot maintenance action with no ability (and so no ⌘K mirror).
	wp_register_ability( 'signal-noise/run-health-scan', array(
		'label'               => 'Run a health scan now',
		'description'         => 'Runs the full site-health check suite immediately instead of waiting on the 24h cache, and stores the scan for the Health tab, widget, and attention badge.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_run_health_scan',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'total'   => array( 'type' => 'integer' ),
				'flagged' => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * Execute callback for signal-noise/run-health-scan.
 *
 * Returns the scan's own summary shape (total + flagged) so a ⌘K toast
 * can say something useful without a second read.
 *
 * @since 9.78.0
 * @param array $input Validated against input_schema above (empty object).
 * @return array{ok:bool,total:int,flagged:int}
 */
function snt_ability_run_health_scan( $input = array() ) {
	if ( ! function_exists( 'sn_health_run_scan' ) ) {
		return array( 'ok' => false, 'total' => 0, 'flagged' => 0 );
	}
	$scan = sn_health_run_scan();
	if ( ! is_array( $scan ) ) {
		return array( 'ok' => false, 'total' => 0, 'flagged' => 0 );
	}
	return array(
		'ok'      => true,
		'total'   => function_exists( 'sn_health_check_total' ) ? (int) sn_health_check_total( $scan ) : 0,
		'flagged' => function_exists( 'sn_health_flagged_checks' ) ? count( (array) sn_health_flagged_checks( $scan ) ) : 0,
	);
}

/**
 * Execute callback for signal-noise/purge-all-caches.
 */
function snt_ability_purge_all_caches( $input ) {
	// $input is null when the run-path is called with no ?input= (the schema
	// permits 'null'); guard before indexing so PHP 8 does not warn on null.
	$include_overrides = is_array( $input ) && ! empty( $input['include_template_overrides'] );

	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Cache helper unavailable — theme module not loaded.', array( 'status' => 500 ) );
	}

	// v10.4.1: verified => true routes the theme's CF leg through the BLOCKING
	// sn_cf_purge_everything_verified() (the wp-admin "Purge All Caches" path
	// since v8.7.0) and makes the theme write its per-leg sn_last_purge_report.
	// Before this the ability's CF purge was fire-and-forget: unconfigured or
	// rejected, it silently no-oped while this response still said "All caches
	// purged." (2026-07-29 stale-edge incident).
	$dispatched_at = time();
	$count         = (int) apply_filters( 'sn_purge_all_caches_result', 0, array(
		'template_overrides' => $include_overrides,
		'verified'           => true,
	) );

	$cf = snt_purge_cf_verdict( $dispatched_at );
	$ok = true;

	switch ( $cf['status'] ) {
		case 'confirmed':
			$message = 'All caches purged; Cloudflare zone purge confirmed.';
			if ( false === ( $cf['edge_fresh'] ?? null ) ) {
				$message .= ' Warning: the post-purge probe still saw a stale render at the edge; give it a minute or purge again.';
			}
			break;
		case 'failed':
			$ok      = false;
			$message = sprintf( 'Origin caches purged, but Cloudflare REJECTED the zone purge (HTTP %d); the edge may keep serving stale pages. Check the API token on the Cloudflare tab.', (int) $cf['http'] );
			break;
		case 'not_configured':
			$ok      = false;
			$message = 'Origin caches purged, but the Cloudflare purge could not run: no API token/zone configured (Signal & Noise, Cloudflare tab). The edge may keep serving stale pages.';
			break;
		default: // unconfirmed — dispatched, but no verified report to read back.
			$message = 'Caches purged; the Cloudflare purge was dispatched but not confirmed (no verified purge report from the theme).';
			break;
	}

	if ( $include_overrides ) {
		$message .= sprintf( ' %d template override%s cleared.', $count, 1 === $count ? '' : 's' );
	}

	return array(
		'ok'         => $ok,
		'message'    => $message,
		'count'      => $count,
		'cloudflare' => $cf,
	);
}

/**
 * Verdict for the Cloudflare leg of a just-dispatched verified purge.
 *
 * Configuration is checked plugin-locally (sn_cf_is_configured, same package:
 * inc/cloudflare-purge.php). The accept-confirmation is read back from the
 * sn_last_purge_report option the THEME writes synchronously during the
 * filter dispatch (theme inc/purge-verify.php, >= 10.23.0). The report is
 * trusted only when it is fresh (written at/after this dispatch), verified-
 * mode, and carries a cf_success leg; anything else degrades to
 * 'unconfirmed' — never a fake success (the pre-v10.4.1 behavior this
 * replaces was exactly that fake success).
 *
 * @since 10.4.1
 * @param int $since Unix time captured immediately before the dispatch.
 * @return array{status:string,http:int,edge_fresh?:bool} status is one of
 *         'confirmed' | 'failed' | 'not_configured' | 'unconfirmed'.
 */
function snt_purge_cf_verdict( $since ) {
	if ( ! function_exists( 'sn_cf_is_configured' ) || ! sn_cf_is_configured() ) {
		return array( 'status' => 'not_configured', 'http' => 0 );
	}

	$report = get_option( 'sn_last_purge_report', array() );
	$cf     = is_array( $report ) && isset( $report['legs']['cf'] ) && is_array( $report['legs']['cf'] )
		? $report['legs']['cf']
		: array();

	$confirmable = is_array( $report )
		&& (int) ( $report['time'] ?? 0 ) >= (int) $since
		&& 'verified' === ( $report['mode'] ?? '' )
		&& array_key_exists( 'cf_success', $cf );

	if ( ! $confirmable ) {
		return array( 'status' => 'unconfirmed', 'http' => 0 );
	}

	$out = array(
		'status' => ! empty( $cf['cf_success'] ) ? 'confirmed' : 'failed',
		'http'   => (int) ( $cf['http'] ?? 0 ),
	);
	if ( is_array( $report ) && array_key_exists( 'resolved', $report ) ) {
		$out['edge_fresh'] = ! empty( $report['resolved'] );
	}
	return $out;
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
	if ( is_array( $input ) && ! empty( $input['force_refresh'] ) && function_exists( 'snt_cmd_impl_force_check' ) ) {
		snt_cmd_impl_force_check();
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
	$repos        = array( 'juanlentino/signal-and-noise', 'juanlentino/signal-and-noise-tools' );
	$last_gha_run = '';
	if ( function_exists( 'snt_gh_recent_runs_merged' ) ) {
		$last_gha_run = snt_deploy_runs_age_label( snt_gh_recent_runs_merged( $repos, 1 ) );
	}

	if ( function_exists( 'snt_deploy_history_merged' ) ) {
		$last_deploy = snt_deploy_runs_age_label( snt_deploy_history_merged( $repos, 1 ) );
	} else {
		// Degraded fallback (deploy-history module absent): the pre-v9.63.3
		// GHA-only reading — stale-prone but better than nothing.
		$last_deploy = $last_gha_run;
	}

	return array(
		'theme'        => snt_deploy_status_for( 'theme' ),
		'plugin'       => snt_deploy_status_for( 'plugin' ),
		'last_deploy'  => $last_deploy,
		'last_gha_run' => $last_gha_run,
	);
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
		return new WP_Error( 'snt_helper_unavailable', 'Template override helper unavailable — theme module not loaded.', array( 'status' => 500 ) );
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

