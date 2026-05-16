<?php
/**
 * Signal & Noise — REST API surface.
 *
 * Wraps the theme's existing maintenance actions (currently exposed
 * only via the Appearance → Signal & Noise admin tab as classic
 * admin-post forms) behind a `signal-noise/v1` REST namespace so
 * they're scriptable from outside the WP UI: WP-CLI via
 * `wp signal-noise <action>` (TBD), CI/automation via curl with an
 * Application Password, or future AI agents via standard REST.
 *
 * Why REST and not the Abilities API: the Abilities API (WP 6.9 PHP,
 * 7.0 JS) is designed for plugins exposing capabilities to external
 * agents and a discovery layer. A single-author personal site has no
 * external agents to expose to and a REST surface is a strict superset
 * of what the admin UI buttons need today (per the analysis in
 * docs/WP-API-MAP.md).
 *
 * Endpoint inventory:
 *
 *   POST signal-noise/v1/purge-cache             — full cache purge
 *                                                  (object cache,
 *                                                  transients, Breeze,
 *                                                  Cloudflare). DOES
 *                                                  NOT touch DB
 *                                                  template overrides.
 *   POST signal-noise/v1/clear-overrides         — clear DB template
 *                                                  / template-part /
 *                                                  navigation overrides.
 *   POST signal-noise/v1/heal-templates          — force re-fetch every
 *                                                  monitored .html file
 *                                                  from GitHub main.
 *                                                  Bypasses rate limit.
 *   POST signal-noise/v1/full-reset              — both above + every
 *                                                  cache. The "after a
 *                                                  bad deploy" panic
 *                                                  button.
 *   POST signal-noise/v1/check-updates           — clear updater
 *                                                  caches + force a
 *                                                  fresh GitHub poll.
 *
 *   GET  signal-noise/v1/plausible/stats         — 7-day batched cache
 *                                                  (visitors, pageviews,
 *                                                  bounce, duration,
 *                                                  top pages, top
 *                                                  sources). Read-only.
 *   GET  signal-noise/v1/plausible/realtime      — current visitor count.
 *   POST signal-noise/v1/plausible/test          — fire a synchronous
 *                                                  Stats API call and
 *                                                  return the outcome.
 *
 * Auth model: every endpoint's permission_callback gates on
 * current_user_can( 'manage_options' ). Cookie-authenticated admins
 * pass automatically; external clients need a WordPress Application
 * Password attached to a manage_options-capable user. Never use
 * __return_true here — these are state-mutating admin endpoints, not
 * public data.
 *
 * Response shape: every endpoint returns either a WP_REST_Response
 * with `{ ok: true, message: '...', data: {...} }` (HTTP 200) or a
 * WP_Error with an appropriate status code that core's REST handler
 * will serialize to JSON automatically.
 *
 * @package SignalNoise
 * @since 7.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_REST_NAMESPACE = 'signal-noise/v1';

/**
 * Shared permission callback. Must be a real check (not __return_true)
 * because these endpoints all mutate site state. Cookie auth + Application
 * Passwords both flow through current_user_can() correctly.
 */
function sn_rest_can_manage() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'sn_rest_forbidden',
			'You do not have permission to perform this action.',
			array( 'status' => rest_authorization_required_code() )
		);
	}
	return true;
}

/**
 * Standardized success response. All endpoints return through this so
 * the shape is consistent: `{ ok: true, message: string, data: object }`
 */
function sn_rest_ok( $message, $data = array(), $status = 200 ) {
	return new WP_REST_Response(
		array(
			'ok'      => true,
			'message' => (string) $message,
			'data'    => is_array( $data ) ? $data : array(),
		),
		$status
	);
}

add_action( 'rest_api_init', function() {

	// ── Maintenance actions (POST, mutating) ─────────────────────────

	register_rest_route( SN_REST_NAMESPACE, '/purge-cache', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_purge_cache',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/clear-overrides', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_clear_overrides',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/heal-templates', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_heal_templates',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/full-reset', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_full_reset',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/check-updates', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_check_updates',
	) );

	// ── Plausible read endpoints (GET, idempotent) ───────────────────

	register_rest_route( SN_REST_NAMESPACE, '/plausible/stats', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_plausible_stats',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/plausible/realtime', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_plausible_realtime',
	) );

	register_rest_route( SN_REST_NAMESPACE, '/plausible/test', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'sn_rest_can_manage',
		'callback'            => 'sn_rest_plausible_test',
	) );
} );

// ── Callbacks ────────────────────────────────────────────────────────

/**
 * POST /purge-cache — full cache purge minus DB template overrides.
 * Mirrors the "Purge All Caches" button in the admin Dashboard tab.
 */
function sn_rest_purge_cache( WP_REST_Request $request ) {
	// Dispatched via sn_purge_all_caches_result filter contract — theme
	// module template-maintenance.php owns the implementation.
	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Cache purge module not loaded.', array( 'status' => 500 ) );
	}
	$cleared = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
	return sn_rest_ok( 'All caches purged.', array( 'cleared' => $cleared ) );
}

/**
 * POST /clear-overrides — clear DB template/template-part/navigation
 * overrides. Site reverts to reading templates from theme files.
 */
function sn_rest_clear_overrides( WP_REST_Request $request ) {
	// Dispatched via sn_clear_template_overrides_result filter contract
	// — theme module template-maintenance.php owns the implementation.
	if ( ! has_filter( 'sn_clear_template_overrides_result' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Template override module not loaded.', array( 'status' => 500 ) );
	}
	$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
	return sn_rest_ok(
		/* translators: %d: number of database overrides cleared. */
		sprintf( '%d database override(s) cleared.', $count ),
		array( 'cleared' => $count )
	);
}

/**
 * POST /heal-templates — force re-fetch every monitored .html file
 * from the tracked GitHub branch. Bypasses the 5-min ambient rate
 * limit and clears per-file failure cooldowns.
 */
function sn_rest_heal_templates( WP_REST_Request $request ) {
	// Dispatched via sn_self_heal_force_run_result filter contract —
	// theme module template-self-heal.php owns the implementation;
	// returns null when not loaded.
	$result = apply_filters( 'sn_self_heal_force_run_result', null );
	if ( ! is_array( $result ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Self-heal module not loaded.', array( 'status' => 500 ) );
	}
	$fixed_n   = isset( $result['fixed'] ) ? count( (array) $result['fixed'] ) : 0;
	$failed_n  = isset( $result['failed'] ) ? count( (array) $result['failed'] ) : 0;
	$message   = $fixed_n > 0
		/* translators: %d: number of files re-synced from GitHub. */
		? sprintf( 'Self-heal: re-synced %d template file(s) from GitHub.', $fixed_n )
		: 'Self-heal: all monitored files already match GitHub.';

	if ( $failed_n > 0 ) {
		return new WP_Error(
			'sn_heal_partial',
			/* translators: %d: number of files that failed to write. */
			sprintf( 'Self-heal: drift detected but write failed for %d file(s).', $failed_n ),
			array(
				'status' => 500,
				'fixed'  => $result['fixed'] ?? array(),
				'failed' => $result['failed'] ?? array(),
			)
		);
	}
	return sn_rest_ok(
		$message,
		array(
			'fixed'  => $result['fixed'] ?? array(),
			'failed' => $result['failed'] ?? array(),
		)
	);
}

/**
 * POST /full-reset — purge all caches AND clear DB overrides. The
 * "I just deployed and something's wrong" panic button.
 */
function sn_rest_full_reset( WP_REST_Request $request ) {
	// Updater error dismissed via sn_updater_clear_error action; purge
	// dispatched via sn_purge_all_caches_result filter (default args
	// include template_overrides).
	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Cache purge module not loaded.', array( 'status' => 500 ) );
	}
	do_action( 'sn_updater_clear_error' );
	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array() );
	return sn_rest_ok(
		/* translators: %d: number of overrides cleared as part of a full reset. */
		sprintf( 'Full reset: %d override(s) cleared and all caches purged.', $count ),
		array( 'cleared' => $count )
	);
}

/**
 * POST /check-updates — clear updater caches + force a fresh GitHub
 * poll. Mirrors the "Check Now" button. Returns the post-poll
 * synthetic version label for sanity-checking from CI.
 */
function sn_rest_check_updates( WP_REST_Request $request ) {
	// Force-check dispatched via sn_updater_force_check action contract —
	// theme module updater.php owns the full cache-clear sequence AND
	// calls wp_update_themes() to re-prime WP's transient. Branch sourced
	// via filter contract for the response payload.
	if ( ! has_action( 'sn_updater_force_check' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Self-updater module not loaded.', array( 'status' => 500 ) );
	}

	$branch = sanitize_key( (string) apply_filters( 'sn_updater_branch', 'main' ) );

	do_action( 'sn_updater_force_check' );

	// After the dispatch, the theme listener has re-run wp_update_themes()
	// which populated this site transient via the theme's
	// pre_set_site_transient_update_themes filter. Read the offered
	// update slice for the response payload.
	$transient = get_site_transient( 'update_themes' );
	$slug      = defined( 'SN_THEME_SLUG' ) ? SN_THEME_SLUG : 'signal-and-noise';
	$offered   = ( is_object( $transient ) && isset( $transient->response[ $slug ] ) )
		? $transient->response[ $slug ]
		: null;

	return sn_rest_ok(
		'Update check complete.',
		array(
			'branch'           => $branch,
			'update_available' => null !== $offered,
			'offered'          => $offered,
		)
	);
}

/**
 * GET /plausible/stats — read-only accessor for the 7-day batched
 * cache. Returns whatever the SWR layer has (possibly stale, possibly
 * empty if the very first cron warmup hasn't landed yet). Never
 * triggers a network call.
 */
function sn_rest_plausible_stats( WP_REST_Request $request ) {
	if ( ! function_exists( 'sn_plausible_dashboard_data' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Plausible module not loaded.', array( 'status' => 500 ) );
	}
	$data = sn_plausible_dashboard_data();
	if ( null === $data ) {
		return new WP_Error( 'sn_plausible_unconfigured', 'Plausible is not configured (missing domain or token).', array( 'status' => 503 ) );
	}
	return sn_rest_ok(
		'Plausible 7-day stats.',
		$data
	);
}

/**
 * GET /plausible/realtime — read-only accessor for the realtime cache.
 * Same SWR semantics as /stats.
 */
function sn_rest_plausible_realtime( WP_REST_Request $request ) {
	if ( ! function_exists( 'sn_plausible_realtime' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Plausible module not loaded.', array( 'status' => 500 ) );
	}
	$value = sn_plausible_realtime();
	return sn_rest_ok(
		'Plausible realtime visitors.',
		array( 'visitors' => $value )
	);
}

/**
 * POST /plausible/test — fire a synchronous 7-day aggregate call to
 * the Stats API and return the outcome. Mirrors the "Test Connection"
 * button in the Plausible admin tab. The synchronous-by-design
 * exception to the SWR-everywhere rule: an admin clicked "test",
 * they're waiting on a real-network result, not a cached one.
 */
function sn_rest_plausible_test( WP_REST_Request $request ) {
	if ( ! function_exists( 'sn_plausible_config' ) || ! function_exists( 'sn_plausible_api' ) ) {
		return new WP_Error( 'sn_rest_unavailable', 'Plausible module not loaded.', array( 'status' => 500 ) );
	}
	$cfg = sn_plausible_config();
	if ( ! $cfg ) {
		return new WP_Error( 'sn_plausible_unconfigured', 'Plausible is not configured (missing domain or token).', array( 'status' => 503 ) );
	}
	delete_transient( SN_PLAUSIBLE_ERR_KEY );
	$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
	if ( is_array( $result ) ) {
		$visitors = (int) ( $result['visitors']['value'] ?? 0 );
		return sn_rest_ok(
			/* translators: %d: number of visitors in the last 7 days. */
			sprintf( 'Plausible API call succeeded — %d visitor(s) in last 7 days.', $visitors ),
			array( 'visitors_7d' => $visitors )
		);
	}
	$err     = function_exists( 'sn_plausible_last_error' ) ? sn_plausible_last_error() : null;
	$status  = $err && isset( $err['code'] ) && (int) $err['code'] >= 400 ? (int) $err['code'] : 502;
	return new WP_Error(
		'sn_plausible_test_failed',
		'Plausible API call failed.',
		array(
			'status'      => $status,
			'last_error'  => $err,
		)
	);
}
