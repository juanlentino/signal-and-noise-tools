<?php
/**
 * Signal & Noise — the remote set widens 1 -> 8 (R3 §3D, Increment 2, origin half).
 *
 * Seven twin registrations + seven per-slug permission callbacks, siblings to
 * `signal-noise/remote-get-analytics-summary` (inc/abilities-remote-analytics.php,
 * Increment 1). That file recorded the pattern; this one applies it honestly at
 * scale — seven more admin registrations, seven more duplicated schemas, seven
 * more parity pins (tests/abilities-remote-set.php). The alternative (a
 * shared-schema refactor across six admin files) is a fine future janitor task;
 * it must not ride a surface-widening increment (spec §2).
 *
 * ISOLATION, unchanged from Increment 1: every admin registration this file's
 * twins shadow stays byte-identical. Each twin is a SEPARATE ability slug
 * gated ONLY by its own remote callback — never a union callback on the admin
 * ability — so widening the remote branch later can never widen an admin
 * surface with it.
 *
 * THE LITERAL, unchanged from Increment 1: each permission callback passes its
 * OWN slug to sn_remote_analytics_allows() as a literal. A permission callback
 * receives only the ability's arguments, never its own name, so a shared
 * callback would have to infer the slug from ambient request state — and would
 * infer wrongly whenever one ability executes another. With one remote slug
 * (Increment 1) a callback carrying the WRONG literal was untestable: every
 * wrong literal was either the same string or a non-member, so it still passed
 * every assertion. With eight members that gap is live — tests/abilities-
 * remote-set.php's matrix is what makes a sibling-literal bug visible.
 *
 * `show_in_rest => false` FROM BIRTH, not patched after. #641 found that
 * `show_in_rest => true` registers POST /wp-abilities/v1/abilities/<slug>/run
 * on every install, and an unauthenticated caller learns the switch state from
 * the error code alone (sn_mcp_remote_disabled vs ability_invalid_permissions)
 * — a switch-state oracle present the day the plugin is installed. These seven
 * twins never carry that surface in the first place.
 *
 * `force_refresh` IS STRIPPED, at both layers, for the two twins whose admin
 * originals accept it (remote-uptime-status, remote-get-deploy-status). A
 * phone caller must not be able to spend the origin's UPSTREAM quotas (a
 * fresh Uptime API / GitHub fetch) — the 2am case this door exists for needs
 * the last known answer, not a fresh probe, and a brokered caller triggering
 * third-party fetches on a shared host is a cost amplifier the aggregate rate
 * cap does not model. The second layer is a DEPENDENCY, not a property this
 * repo proves: the twin schemas declare additionalProperties:false, and
 * refusing a smuggled key before execute_callback is WP_Ability::execute()'s
 * validate_input step — external code this harness cannot pin (an
 * adversarial trace of core trunk on 2026-08-14 confirmed the order
 * normalize→validate→permissions→execute, with unknown keys failing schema;
 * re-verify against the PINNED Abilities API version before citing this
 * layer as a control). The layer this repo DOES prove is the edge gate,
 * mutation-verified in the Worker suite, plus the strip pins here proving
 * the twins never declare the key. Spec: remote-mcp-increments-2-and-4.md
 * §3. Each stripped twin below carries the strip comment inline.
 *
 * @package SignalNoiseTools
 * @since 11.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callback for `signal-noise/remote-get-analytics-events`.
 *
 * @return bool
 */
function snt_ability_perm_remote_analytics_events() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-analytics-events' );
}

/**
 * Permission callback for `signal-noise/remote-get-insights`.
 *
 * @return bool
 */
function snt_ability_perm_remote_insights() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-insights' );
}

/**
 * Permission callback for `signal-noise/remote-get-narration`.
 *
 * @return bool
 */
function snt_ability_perm_remote_narration() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-narration' );
}

/**
 * Permission callback for `signal-noise/remote-uptime-status`.
 *
 * @return bool
 */
function snt_ability_perm_remote_uptime_status() {
	return sn_remote_analytics_allows( 'signal-noise/remote-uptime-status' );
}

/**
 * Permission callback for `signal-noise/remote-get-health-scan`.
 *
 * @return bool
 */
function snt_ability_perm_remote_health_scan() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-health-scan' );
}

/**
 * Permission callback for `signal-noise/remote-get-rss-stats`.
 *
 * @return bool
 */
function snt_ability_perm_remote_rss_stats() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-rss-stats' );
}

/**
 * Execute callback for `signal-noise/remote-get-deploy-status`: the admin
 * payload without `runtime`. PHP version and register_argc_argv are exploit
 * selection data (argc/argv on is half of the pearcmd include-to-RCE chain, the
 * exact class CVE-2026-87902 belongs to); the remote door relays through a
 * Cloudflare worker to devices, so it gets the version rows and not the runtime.
 *
 * @param mixed $input Ignored; the twin accepts no arguments.
 * @return array|WP_Error
 */
function snt_ability_remote_get_deploy_status( $input = null ) {
	$out = snt_ability_get_deploy_status( null );
	if ( is_array( $out ) ) {
		// last_worker_deploy stays on the local door: adding it here would change
		// the remote contract (and its pinned shape hash) for a field no device needs.
		unset( $out['runtime'], $out['last_worker_deploy'] );
	}
	return $out;
}

/**
 * Permission callback for `signal-noise/remote-get-deploy-status`.
 *
 * @return bool
 */
function snt_ability_perm_remote_deploy_status() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-deploy-status' );
}


// The registrations live in four parts files (house line cap, 2026-10-13), loaded
// in the order they always ran so every wp_abilities_api_init callback keeps its place.
require_once __DIR__ . '/abilities-remote-set-parts.php';
require_once __DIR__ . '/abilities-remote-set-search-parts.php';
require_once __DIR__ . '/abilities-remote-set-ratified-parts.php';
require_once __DIR__ . '/abilities-remote-set-contract-parts.php';
