<?php
/**
 * Signal & Noise — remote analytics door guard (R3 §3D, Increment 1 origin half).
 *
 * The remote analytics MCP surface is reached by a caller who is NOT the owner's
 * laptop. Everything in this file exists because that changes which way a missing
 * input should be read.
 *
 * THE INVERSION, and it is deliberate: `sn_mcp_read_enabled` is fail-OPEN on
 * absence — an untouched option means "the owner never turned it off", and the
 * read door stays open. `sn_mcp_remote_enabled` is fail-CLOSED on absence — an
 * untouched option means "the owner never turned it ON", and the remote door
 * stays shut. The proposal requires this for any brokered path, and it is what
 * makes this increment safe to ship with no bridge behind it: the whole remote
 * surface is inert in production the day it lands, and the tests are what prove
 * it can be turned on deliberately.
 *
 * ISOLATION: this file never calls into mcp-read-guard.php or mcp-rw-guard.php,
 * and neither calls into it. The kill-switch predicate below is MIRRORED from
 * the read door's rather than shared, exactly as the read door mirrors the write
 * door's. Sharing would couple the doors at the one layer the split exists to
 * keep apart.
 *
 * WHAT THIS FILE DOES NOT DO: it does not rate limit. The remote path's F1
 * ceiling is the edge Durable Object, which is Worker-side and belongs to the
 * increment that builds the bridge. The origin's run-route ceiling is the
 * existing fail-OPEN one in mcp-read-guard.php, extended to cover remote slugs.
 * Do not read this increment as closing F1 — it closes the kill switch.
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owner-facing switch. ABSENT MEANS OFF — see this file's header. */
const SN_MCP_REMOTE_ENABLED_OPTION = 'sn_mcp_remote_enabled';

/**
 * The capability the remote principal holds. Never `manage_options`, and never
 * implied by a role that carries it. NOTHING is granted this capability by the
 * plugin — no role gains it at activation and no migration adds it. It exists to
 * be granted deliberately by the bridge increment.
 */
const SN_MCP_REMOTE_CAPABILITY = 'sn_read_remote_analytics';

/**
 * The slugs a remote principal may reach.
 *
 * A function rather than a `const` array on purpose: a duplicate top-level const
 * across two files silently keeps whichever value loaded first, which is a
 * failure mode no test would catch. Mirrors sn_mcp_allowlist()'s shape.
 *
 * These slugs are DELIBERATELY ABSENT from sn_mcp_allowlist(): the laptop read
 * door gains nothing from this increment.
 *
 * @return string[]
 */
function sn_mcp_remote_slugs() {
	return array(
		'signal-noise/remote-get-analytics-summary',
		'signal-noise/remote-get-analytics-events',
		'signal-noise/remote-get-insights',
		'signal-noise/remote-get-narration',
		'signal-noise/remote-uptime-status',
		'signal-noise/remote-get-health-scan',
		'signal-noise/remote-get-rss-stats',
		'signal-noise/remote-get-deploy-status',
		// v13.52.0 — the three ratified twins (rulings in docs/BACKLOG.md's
		// plan section). anchor is deliberately absent: RULED LOCAL (D1).
		'signal-noise/remote-provenance-integrity-status',
		'signal-noise/remote-machine-readers-summary',
		'signal-noise/remote-cron-health-summary',
	);
}

/**
 * EVERY local section's remote verdict — the totality record (Task 4.1).
 *
 * THE PROBLEM THIS SOLVES. `sn_mcp_remote_slugs()` above is hand-curated by
 * name, and that is correct: the alternative ("the read door minus some") is an
 * exclusion list, and an exclusion list FAILS OPEN — the next person to add a
 * local section silently widens what a phone-reachable credentialed path can
 * read, and nothing goes red. An allowlist fails closed.
 *
 * But hand-curation does not lag safely, it lags SILENTLY. Before this map, a
 * new section on `sn-status` / `sn-metrics` / `sn-site-facts` simply never got
 * a remote decision, and nothing anywhere said so. `tests/mcp-remote-verdicts.php`
 * now reds until every section has one.
 *
 * THIS MAP GRANTS NOTHING. It is a record of decisions, never their
 * enforcement. Reach still requires a registered twin AND its slug in
 * `sn_mcp_remote_slugs()` — two steps, and that second step is the boundary.
 * A verdict flipped to `true` here without an allowlisted twin reds the test
 * rather than exposing anything.
 *
 * `remote => true` is used ONLY for sections already reachable through a twin
 * that shipped before this map existed. Every candidate the 2026-08-11
 * partition discussed is recorded `false` with `awaiting ratification` —
 * Precondition B is an owner decision, and a map that quietly promoted
 * candidates would be making it.
 *
 * @since 13.50.0
 * @return array<string,array{remote:bool,reason:string,twin:string|null}>
 */
function sn_mcp_remote_verdicts() {
	$out = function ( $remote, $reason, $twin = null ) {
		return array( 'remote' => (bool) $remote, 'reason' => (string) $reason, 'twin' => $twin );
	};

	return array(
		/* ── sn-status ────────────────────────────────────────────────── */
		'uptime'               => $out( true, 'Availability of the public site. No post bodies, no request-derived detail.', 'signal-noise/remote-uptime-status' ),
		'deploy'               => $out( true, 'Deploy state of public infrastructure. Shipped before this map.', 'signal-noise/remote-get-deploy-status' ),
		'health_scan'          => $out( true, 'Verdicts over public URLs. The scan itself stays un-triggerable remotely — reading a verdict is not causing one.', 'signal-noise/remote-get-health-scan' ),
		'anchor'               => $out( false, 'RULED LOCAL (D1, 2026-09-01): the payload joins ledger entries to post titles across post_status=any, so it can name UNPUBLISHED titles, and the byte-identical twin rule forbids narrowing it. The ledger itself is separately public. Do not re-propose without a new parity-rule design.' ),
		'provenance_integrity' => $out( true, 'Ratified 2026-09-01 (post-Access model: the reader is the owner, authenticated). The sweep is post_status=publish, so failing[] can only name public titles — parity-safe as a byte-identical twin.', 'signal-noise/remote-provenance-integrity-status' ),
		'ipv6_criterion'       => $out( false, 'Login-defense tuning criterion. Defence posture, not analytics: it describes what the door would block, which is recon rather than a metric.' ),
		'ai_cache_probe'       => $out( false, 'Internal cache-warm diagnostics. No decision is taken from a phone on it, so exposure buys nothing against a credentialed path.' ),
		'cadence'              => $out( false, 'Publishing cadence flags read editorial state, including scheduled and unpublished work.' ),
		'cron_scheduled'       => $out( false, 'Detail rows stay on the desktop. The model-never-levers review the partition asked for produced cron_health (v13.52.0), which IS remote — this section answers "what exists", the phone question is "is anything wrong".' ),
		'cron_history'         => $out( false, 'Same split as cron_scheduled: per-firing history is desktop detail; cron_health carries the verdict remotely.' ),
		'cron_health'          => $out( true, 'The model the partition asked for (v13.52.0): status + derived summary + overdue evidence, sharing the Site Health overdue rule. Byte-identical twin of a section designed for the phone.', 'signal-noise/remote-cron-health-summary' ),
		'collector'            => $out( false, 'Analytics collector plumbing state. Operational internals, not a number anyone reads on a phone.' ),
		'corpus_integrity'     => $out( false, 'OUT BY CONSTRUCTION: the corpus spans SNT_CORPUS_STATUSES, so its findings can name draft, pending and private posts. Not proposable.' ),

		/* ── sn-metrics ───────────────────────────────────────────────── */
		'analytics_summary'    => $out( true, 'The analytics scope the remote door exists for. Aggregate counts only.', 'signal-noise/remote-get-analytics-summary' ),
		'analytics_events'     => $out( true, 'Event counts within the same analytics scope. Shipped before this map.', 'signal-noise/remote-get-analytics-events' ),
		'rss_stats'            => $out( true, 'Feed-delivery counts over a public feed.', 'signal-noise/remote-get-rss-stats' ),
		'machine_readers'      => $out( true, 'Ratified 2026-09-01. Aggregate family/surface/purpose counts only — no post bodies, no UA samples. The closest of the candidates to the analytics scope the door was built for.', 'signal-noise/remote-machine-readers-summary' ),
		'analytics_top_content'=> $out( false, 'Deferred on UTILITY, not safety (post-Access, the reader is the owner). The dashboard covers it. One residual stays recorded: the rollup stores REQUESTED paths, so a logged-out visitor requesting an unpublished slug lands that path string via a 404; owner previews are already dropped at the worker (login-cookie beacons rejected).' ),
		'404_log'              => $out( false, 'DROPPED 2026-09-01 — weakest candidate on every axis; deferred on utility under the post-Access model. Revisit only if the owner would actually read it.' ),

		/* ── sn-site-facts ────────────────────────────────────────────── */
		'theme_version'        => $out( false, 'Build identity. Reading it remotely aids fingerprinting and answers no question a phone asks.' ),
		'latest_theme_tag'     => $out( false, 'Same fingerprinting surface as theme_version, one repo hop further out.' ),
		'design_tokens'        => $out( false, 'Design-system internals. Not analytics scope.' ),
		'block_patterns'       => $out( false, 'Authoring inventory, not a metric.' ),
		'template_overrides'   => $out( false, 'Site Editor internals — and the surface v13.49.0 gave a WRITE change type. Read and write stay on the desktop together.' ),
		'active_template'      => $out( false, 'Rendering internals for a given route. Not analytics scope.' ),
		'llms_txt'             => $out( false, 'Already public at its own URL; a credentialed path adds nothing but a second way to fetch it.' ),
		'seo_route_meta'       => $out( false, 'Per-route metadata that can describe unpublished routes.' ),
		'pillars'              => $out( false, 'Editorial structure, derived from the corpus.' ),
		'reading_time'         => $out( false, 'Per-post derived value; the corpus it derives from spans unpublished statuses.' ),
		'scan_telemetry'       => $out( false, 'Operational telemetry about scans. Owner-desktop reading.' ),
		'tool_telemetry'       => $out( false, 'The MCP layer describing its own traffic — including, since v13.48.0, error_detail prose from failed calls. Diagnostics stay on the desktop.' ),
		'configuration_drift'  => $out( false, 'Names configuration expected versus observed, which is a map of the site\'s own soft spots.' ),
		'pattern_content'      => $out( false, 'OUT BY CONSTRUCTION: returns pattern BODIES, which is content on a credentialed path. Not proposable.' ),
	);
}

/**
 * Remote kill-switch PURE predicate.
 *
 * @param bool $constant_disabled defined('SN_MCP_REMOTE_DISABLED') && SN_MCP_REMOTE_DISABLED.
 * @param bool $option_enabled    The sn_mcp_remote_enabled option's value (default FALSE).
 * @return bool True when the remote door must be treated as disabled.
 */
function sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the remote door disabled right now?
 *
 * The `false` default on get_option() is the fail-closed half. Changing it to
 * `true` to "match the read door" would silently ship the remote surface open.
 *
 * @return bool
 */
function sn_mcp_remote_kill_switch_engaged() {
	$constant_disabled = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$option_enabled    = function_exists( 'get_option' )
		? (bool) get_option( SN_MCP_REMOTE_ENABLED_OPTION, false )
		: false;
	return sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled );
}

/**
 * The remote gate: switch, then capability, then slug membership. All three.
 *
 * ORDER MATTERS. The switch is first so that "closed" beats "you hold the
 * capability" — the same precedence the read guard achieves by running its kill
 * switch at rest_pre_dispatch priority 10 and its ceiling at 11.
 *
 * The slug arrives as a literal from a per-slug callback rather than being
 * resolved from ambient request state. An ambient resolver hands a NESTED inner
 * gate the OUTER slug, so an ability executing another ability would approve the
 * inner one against a name that was never its own — and it would look healthy,
 * because the membership check passed.
 *
 * @param string $slug The calling ability's own slug, passed as a literal.
 * @return bool
 */
function sn_remote_analytics_allows( $slug ) {
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( SN_MCP_REMOTE_CAPABILITY ) ) {
		return false;
	}
	return is_string( $slug ) && '' !== $slug && in_array( $slug, sn_mcp_remote_slugs(), true );
}

/**
 * Make the remote kill switch cover the remote slugs' native run routes.
 *
 * Without this, a remote slug held off the read allowlist would reach
 * POST /wp-abilities/v1/abilities/<slug>/run with no kill switch consulted at
 * all — which is the same single-route gap F2 was closed to eliminate, rebuilt
 * one increment later on a path that matters more.
 *
 * Route parsing is duplicated from mcp-read-guard.php rather than imported, per
 * this file's isolation rule.
 *
 * @param mixed $result  Pre-dispatch result; non-null means someone answered.
 * @param mixed $server  Unused.
 * @param mixed $request The REST request.
 * @return mixed Null to continue, or WP_Error to refuse.
 */
function sn_mcp_remote_guard_run_route( $result, $server = null, $request = null ) {
	if ( null !== $result ) {
		return $result;
	}
	if ( ! sn_mcp_remote_kill_switch_engaged() ) {
		return $result;
	}
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return $result;
	}
	if ( ! in_array( (string) $m[1], sn_mcp_remote_slugs(), true ) ) {
		return $result;
	}
	return new WP_Error(
		'sn_mcp_remote_disabled',
		__( 'The remote analytics door is currently disabled.', 'signal-and-noise-tools' ),
		array( 'status' => 403 )
	);
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_remote_guard_run_route', 10, 3 );
}
