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
 * Permission callback for `signal-noise/remote-get-deploy-status`.
 *
 * @return bool
 */
function snt_ability_perm_remote_deploy_status() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-deploy-status' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/remote-get-analytics-events', array(
		'label'               => 'Get custom events (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-analytics-events. '
			. 'Returns top custom events (name → events/visitors) for a window '
			. '(range: 7|14|30|90|365|all; values validated by the origin). Read-only. '
			. 'NOTE: takes range ONLY — no class argument exists on this ability. '
			. 'Reachable only by a principal holding the sn_read_remote_analytics '
			. 'capability, and only while the remote door is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_analytics_events',
		'execute_callback'    => 'sn_ability_get_analytics_events',
		'input_schema'        => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
			),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-analytics.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-insights', array(
		'label'               => 'Get content insights (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-insights. '
			. 'Returns the cached result of the last synthesis scan (open-questions '
			. 'array + metadata); the recommendations array may be empty. Returns '
			. 'null when no scan has run yet. Read-only, served from the origin\'s '
			. 'cache. Returns operational commentary in the owner\'s voice '
			. '(R-3D-d accepted). Reachable only by a principal holding the '
			. 'sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_insights',
		'execute_callback'    => 'snt_ability_get_insights',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-insights.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'scanned_at'      => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'      => array( 'type' => array( 'integer', 'null' ) ),
				'signal_summary'  => array( 'type' => array( 'object', 'null' ) ),
				'recommendations' => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-narration', array(
		'label'               => 'Get analytics narration (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-narration. '
			. 'Returns the cached weekly analytics digest (headline + paragraphs + '
			. 'highlights + metadata), or null when none has been generated yet. '
			. 'Read-only, served from the origin\'s cache — never triggers an AI '
			. 'call. Returns operational commentary in the owner\'s voice, which '
			. 'may reference content titles (R-3D-d accepted). Reachable only by a '
			. 'principal holding the sn_read_remote_analytics capability, and only '
			. 'while the remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_narration',
		'execute_callback'    => 'snt_ability_get_narration',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-narration.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'generated_at' => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'   => array( 'type' => array( 'integer', 'null' ) ),
				'headline'     => array( 'type' => array( 'string', 'null' ) ),
				'paragraphs'   => array( 'type' => 'array' ),
				'highlights'   => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-uptime-status', array(
		'label'               => 'Get Better Stack uptime status (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/uptime-status. '
			. 'Returns the Better Stack monitor + heartbeat states (name, status, '
			. 'level) from the origin\'s cache. Served from the origin\'s cache; '
			. 'there is no force_refresh remotely — no arguments are accepted. '
			. 'configured=false means no API token is saved yet (not an error). '
			. 'Read-only. Reachable only by a principal holding the '
			. 'sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_uptime_status',
		'execute_callback'    => 'snt_ability_uptime_status',
		'input_schema'        => array(
			// The admin ability accepts force_refresh (a deliberate cache bypass
			// that hits an upstream API fresh). The twin DOES NOT CARRY THE KEY:
			// a phone caller must not spend the origin's upstream quotas, and the
			// 2am case needs the last known answer, not a fresh probe. The edge
			// gate refuses the key too — two layers, deliberately redundant.
			// Spec: remote-mcp-increments-2-and-4.md §3.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/uptime-status.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'configured' => array( 'type' => 'boolean' ),
				'fetched_at' => array( 'type' => 'integer' ),
				'rows'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'kind'          => array( 'type' => 'string', 'enum' => array( 'monitor', 'heartbeat' ) ),
							'id'            => array( 'type' => 'string' ),
							'name'          => array( 'type' => 'string' ),
							'status'        => array( 'type' => 'string' ),
							'level'         => array( 'type' => 'string', 'enum' => array( 'ok', 'warn', 'alert' ) ),
							'checked_at'    => array( 'type' => array( 'string', 'null' ) ),
							'availability'  => array( 'type' => array( 'number', 'null' ), 'description' => '30-day availability percentage; null on the light tier or when the summary endpoint was unavailable.' ),
							'incidents_30d' => array( 'type' => array( 'integer', 'null' ) ),
							'availability_90d' => array( 'type' => array( 'number', 'null' ) ),
							'response_ms'   => array( 'type' => array( 'integer', 'null' ), 'description' => 'Average response time over the last 24h in ms (monitors only, detail tier).' ),
						),
					),
				),
				'incidents'  => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Recent incidents, newest first (detail tier only; null on the light tier or when the incidents endpoint was unavailable).',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'cause'       => array( 'type' => 'string' ),
							'started_at'  => array( 'type' => 'string' ),
							'resolved_at' => array( 'type' => array( 'string', 'null' ) ),
							'ongoing'     => array( 'type' => 'boolean' ),
							'duration_s'  => array( 'type' => array( 'integer', 'null' ) ),
						),
					),
				),
				'error'      => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-health-scan', array(
		'label'               => 'Get content-health scan summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-health-scan. '
			. 'Returns a compact summary of the last cached Content-Health scan: '
			. 'the total finding count, the flagged checks ranked by count (each '
			. 'with its label, count, and fix hint), and the passed/total check '
			. 'tally. Returns null when no scan has run yet. Read-only — never '
			. 'triggers a scan. Reachable only by a principal holding the '
			. 'sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_health_scan',
		'execute_callback'    => 'snt_ability_get_health_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-health.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'scanned_at'    => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'    => array( 'type' => array( 'integer', 'null' ) ),
				'finding_total' => array(
					'type'        => 'integer',
					'description' => 'Fault-tier findings only, narrowed to the health surface. Advisory-tier counts live in advisory_total.',
				),
				'advisory_total' => array(
					'type'        => 'integer',
					'description' => 'Advisory-tier findings (external link rot, link opportunities, evergreen stale posts) counted across EVERY surface. These render on the worklist, not the Health tab, so this number is deliberately not the one the Health surface shows.',
				),
				'checks_total'  => array( 'type' => 'integer' ),
				'checks_passed' => array(
					'type'        => 'integer',
					'description' => 'Checks that RAN and found nothing. A check that could not run (absent AI provider, unavailable theme palette, non-Cloudflare hosting) is NOT counted here — it is in checks_skipped. passed + skipped + flagged === total.',
				),
				'checks_skipped' => array(
					'type'        => 'integer',
					'description' => 'Checks that could not run this scan, so produced no evidence either way. Never folded into checks_passed: zero findings from a check that never executed is not a pass.',
				),
				'flagged'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'check'    => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'count'    => array( 'type' => 'integer' ),
							'fix_hint' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-rss-stats', array(
		'label'               => 'Get RSS feed activity statistics (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-rss-stats. '
			. 'Returns the most recent RSS feed request timestamp + 24h / 7d / 30d '
			. 'totals + unique visitor counts. Read-only. Reachable only by a '
			. 'principal holding the sn_read_remote_analytics capability, and only '
			. 'while the remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_rss_stats',
		'execute_callback'    => 'snt_ability_get_rss_stats',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-content.php — the parity pin in tests enforces ===.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'data' => array(
					'type'        => 'object',
					'description' => 'Stats payload from sn_rss_tracker_window_stats_multi().',
					'properties'  => array(
						'last_request'          => array(
							'type'        => array( 'string', 'null' ),
							'description' => 'UTC timestamp of the most recent RSS feed request (Y-m-d H:i:s), or null if no requests recorded.',
						),
						'last_request_relative' => array(
							'type'        => 'string',
							'description' => 'Human-readable relative time (e.g. "3 hours ago"). Empty string when no last_request.',
						),
						'windows'               => array(
							'type'                 => 'object',
							'description'          => 'Per-window aggregate counts. Keys are day-counts (1, 7, 30). Each value has total request count + count of distinct ua_hash values.',
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'total'   => array(
										'type'        => 'integer',
										'description' => 'Total RSS feed requests in this window.',
										'minimum'     => 0,
									),
									'uniques' => array(
										'type'        => 'integer',
										'description' => 'Distinct ua_hash count in this window (proxy for unique subscriber clients).',
										'minimum'     => 0,
									),
								),
							),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-deploy-status', array(
		'label'               => 'Get theme + plugin deploy status (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-deploy-status. '
			. 'Returns current theme version, current plugin version, latest '
			. 'available versions from GitHub, and whether updates are available. '
			. 'Served from the origin\'s cache; there is no force_refresh remotely '
			. '(see sn_remote_uptime_status) — no arguments are accepted. '
			. 'Read-only. Reachable only by a principal holding the '
			. 'sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_deploy_status',
		'execute_callback'    => 'snt_ability_get_deploy_status',
		'input_schema'        => array(
			// The admin ability accepts force_refresh (a deliberate cache bypass
			// that hits an upstream API fresh). The twin DOES NOT CARRY THE KEY:
			// a phone caller must not spend the origin's upstream quotas, and the
			// 2am case needs the last known answer, not a fresh probe. The edge
			// gate refuses the key too — two layers, deliberately redundant.
			// Spec: remote-mcp-increments-2-and-4.md §3.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from the admin registration in
		// inc/abilities-system.php — the parity pin in tests enforces ===.
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
				'last_deploy_component' => array(
					'type'        => 'string',
					'description' => 'Which package last_deploy refers to: "Theme", "Plugin", or "" when unknown. Added v12.13.0 because last_deploy is an age with no subject, and the Deploy Status card renders it under seven independently versioned rows — theme, plugin and five workers — so a bare age read as though it covered all of them. It never did: the workers do not install through the WP upgrader and have no records in this feed at all, so this field is the scope disclosure as much as the name.',
				),
				'last_gha_run' => array(
					'type'        => 'string',
					'description' => 'Relative time of the most recent deploy GHA workflow run across both repos — the pre-v9.63.3 last_deploy reading, kept as a clearly-labeled secondary field. deploy.yml is the workflow_dispatch-only emergency fallback, so this moves only on manual dispatches. Empty string if unknown. Added v9.63.3.',
				),
				// Additive: theme/plugin keys stay byte-stable for morning-brief + desktop widget.
				'workers' => array(
					'type'        => 'array',
					'description' => 'Deploy status for the five owned Cloudflare workers (analytics, provenance, login-guard, remote-mcp, rights-signals). Each row: id, label, live (probed version or "unprobeable"), latest (highest GitHub tag), state (ok|behind|unknown), repo. Rows with no probe route or a failed probe stay present as unknown — never omitted. Added with the Deploy Status worker surface.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'string' ),
							'label'  => array( 'type' => 'string' ),
							'live'   => array( 'type' => 'string' ),
							'latest' => array( 'type' => 'string' ),
							'state'  => array( 'type' => 'string', 'enum' => array( 'ok', 'behind', 'unknown' ) ),
							'repo'   => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );
} );

/**
 * Permission callbacks for the v13.52.0 twins. Each passes its own slug as a
 * LITERAL — same reasoning as every callback above: a permission callback is
 * handed only the ability's arguments, never its own name.
 */
function snt_ability_perm_remote_provenance_integrity() {
	return sn_remote_analytics_allows( 'signal-noise/remote-provenance-integrity-status' );
}
function snt_ability_perm_remote_machine_readers() {
	return sn_remote_analytics_allows( 'signal-noise/remote-machine-readers-summary' );
}
function snt_ability_perm_remote_cron_health() {
	return sn_remote_analytics_allows( 'signal-noise/remote-cron-health-summary' );
}
// v13.61.0 — weave Phase 4.
function snt_ability_perm_remote_search_performance() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-performance' );
}
function snt_ability_perm_remote_search_drift() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-drift' );
}
// v13.67.0 — owner ruling 2026-09-01: the cross-exam joins the door.
function snt_ability_perm_remote_search_crossexam() {
	return sn_remote_analytics_allows( 'signal-noise/remote-search-crossexam' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'snt_search_console_abilities' ) ) {
		return;
	}
	/* ── v13.61.0 — measurement weave Phase 4: two Search Console twins.
	 * The output_schema is READ FROM THE SAME TABLE the admin registration
	 * reads (snt_search_console_abilities()), so byte-identity is by
	 * construction, not by copy; tests/abilities-remote-set.php still pins
	 * the pair with ===. search_crossexam joined at v13.67.0 by owner ruling
	 * (2026-09-01): a WINDOW verdict over two public-facing instruments, no
	 * paths in its payload, and the ledger side is counts only.
	 * ───────────────────────────────────────────────────────────────── */
	$table = snt_search_console_abilities();
	$twins = array(
		'signal-noise/remote-search-performance' => array(
			'admin' => 'signal-noise/search-performance',
			'label' => 'Search Console: the stored window (remote)',
			'perm'  => 'snt_ability_perm_remote_search_performance',
			'exec'  => 'snt_ability_search_performance',
		),
		'signal-noise/remote-search-drift'       => array(
			'admin' => 'signal-noise/search-drift',
			'label' => 'Search Console: position drift (remote)',
			'perm'  => 'snt_ability_perm_remote_search_drift',
			'exec'  => 'snt_ability_search_drift',
		),
		'signal-noise/remote-search-crossexam'   => array(
			'admin' => 'signal-noise/search-crossexam',
			'label' => 'Search Console x crawler ledger: do the instruments agree? (remote)',
			'perm'  => 'snt_ability_perm_remote_search_crossexam',
			'exec'  => 'snt_ability_search_crossexam',
		),
	);
	foreach ( $twins as $slug => $t ) {
		if ( ! isset( $table[ $t['admin'] ] ) ) {
			continue;
		}
		wp_register_ability( $slug, array(
			'label'               => $t['label'],
			'description'         => 'Remote-scoped twin of ' . $t['admin'] . '. ' . $table[ $t['admin'] ]['description']
				. ' Reachable only by a principal holding the sn_read_remote_analytics capability, and only while the remote door is explicitly enabled.',
			'category'            => 'diagnostics',
			'permission_callback' => $t['perm'],
			'execute_callback'    => $t['exec'],
			'input_schema'        => array(
				'type'                 => array( 'object', 'null' ),
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => $table[ $t['admin'] ]['output'],
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
			),
		) );
	}
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	/* ── v13.52.0 — the three ratified twins (D-rulings in docs/BACKLOG.md).
	 * Each output_schema is copied BYTE-IDENTICALLY from its admin
	 * registration; tests/abilities-remote-set.php enforces === on the pair.
	 * anchor is deliberately ABSENT: RULED LOCAL (D1) — its payload can name
	 * unpublished post titles and the parity rule forbids narrowing.
	 * ────────────────────────────────────────────────────────────────── */

	wp_register_ability( 'signal-noise/remote-provenance-integrity-status', array(
		'label'               => 'Get provenance integrity status (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/provenance-integrity-status. '
			. 'Latest stored integrity sweep: fleet/checked/clean/failed/unreachable '
			. 'counts plus the failing list. The sweep is post_status=publish, so '
			. 'failing[] can only name public titles. Served from the stored sweep; '
			. 'nothing is probed on request. Read-only. Reachable only by a principal '
			. 'holding the sn_read_remote_analytics capability, and only while the '
			. 'remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_provenance_integrity',
		'execute_callback'    => 'snt_ability_provenance_integrity_status',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/provenance-integrity.php.
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'swept_at'    => array( 'type' => 'integer' ),
				'fleet'       => array( 'type' => 'integer' ),
				'checked'     => array( 'type' => 'integer' ),
				'clean'       => array( 'type' => 'integer' ),
				'failed'      => array( 'type' => 'integer' ),
				'unreachable' => array( 'type' => 'integer' ),
				'keys'        => array( 'type' => 'string' ),
				'failing'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'      => array( 'type' => 'integer' ),
							'uid'          => array( 'type' => 'string' ),
							'title'        => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string' ),
							'version'      => array( 'type' => 'integer' ),
							'failures'     => array( 'type' => 'array' ),
							'last_checked' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-machine-readers-summary', array(
		'label'               => 'Get machine readers summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-machine-readers-summary. '
			. 'Aggregate crawler reads over a window: totals, top families, the '
			. 'AI-training slice and its per-surface breakdown. Counts only — no post '
			. 'bodies, no UA samples. Read-only. Reachable only by a principal holding '
			. 'the sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_machine_readers',
		'execute_callback'    => 'snt_ability_get_machine_readers_summary',
		'input_schema'        => array(
			// The admin ability accepts days (1-90). The twin carries it too —
			// a window is a read parameter, not a lever.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 30 ),
			),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/abilities-machine-readers.php.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'             => array( 'type' => 'boolean' ),
				'days'           => array( 'type' => 'integer' ),
				'total'          => array( 'type' => 'integer' ),
				// v13.34.0. Declared at the same time as the payload gains them —
				// the purposes lesson: a field the schema does not mention is a field
				// no agent can know exists.
				'truncated'              => array(
					'type'        => 'boolean',
					'description' => 'True when the edge reports the aggregate read hit its row cap. The BREAKDOWNS below may then be partial even when `total` is exact.',
				),
				'total_exact'            => array(
					'type'        => 'boolean',
					'description' => 'True when `total` came from the edge\'s day-only totals view, which cannot truncate. False means it was summed from the aggregate and is a floor, not a count.',
				),
				'days_covered'           => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Days the sensor actually holds data for in this window, counted from the day-rows the totals view returned. LESS than `days` means the window reaches past the start of the data, and any period-over-period comparison across it is an artifact of when the sensor started rather than a change in traffic. null when the edge cannot answer.',
				),
				// v13.43.0. Declared in the same change that adds it to the
				// payload — the v13.33.0 lesson, where four fields were returned
				// but undeclared since v10.79.0 and no agent could know the axis
				// existed. This is the axis that answers CONCENTRATION.
				'identity'               => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'Signature verification folded against the dimensions it shares a row with. '
						. '`measured` is reads carrying a real state; `valid` / `invalid` / `unknown_key` / `unsigned` split it. '
						. '`by_agent` and `by_surface` list VERIFIED reads only, `{agent|surface, hits}` descending — an agent whose signature failed never appears there, because listing it beside one that passed would read as proof of the opposite. '
						. 'null, NOT a block of zeros, when no read in the window carried a signature state at all: that is "never measured", which is a different claim from "measured, none verified" (which reports valid 0 with empty leaderboards). '
						. 'Use by_agent to tell an ecosystem from a single signer, and by_surface to tell genuine adoption from traffic to a newly-served endpoint.',
				),
				'families'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'family' => array( 'type' => 'string' ),
							'hits'   => array( 'type' => 'integer' ),
						),
					),
				),
				'ai_training'    => array( 'type' => 'integer' ),
				'ai_rights'      => array( 'type' => 'integer' ),
				'ai_surfaces'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'surface' => array( 'type' => 'string' ),
							'hits'    => array( 'type' => 'integer' ),
						),
					),
				),
				// ADDITIVE (v13.33.0). These four have been in the payload since
				// v10.79.0 (snt_mr_summary_payload()) but were never declared, so an
				// agent reading this schema could not know the purpose axis exists —
				// the axis the surface's own docs call the defensible one. Declaring
				// them changes no value; it stops the contract understating the payload.
				'purposes'               => array(
					'type'        => array( 'array', 'null' ),
					'description' => 'Reads per declared purpose, highest first. null when the taxonomy is unavailable — never an empty array, which would read as "measured zero".',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'purpose' => array( 'type' => 'string' ),
							'hits'    => array( 'type' => 'integer' ),
						),
					),
				),
				'ai_training_by_purpose' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'AI-training reads counted on the PURPOSE axis. Reported beside ai_training rather than replacing it: the frozen families over-count, and the gap between the two is the over-count made visible.',
				),
				'first_party'            => array(
					'type'        => array( 'integer', 'null' ),
					'description' => 'Reads from this site\'s own tooling. Not readership; excluded from headline totals.',
				),
				'taxonomy'               => array(
					'type'        => array( 'string', 'null' ),
					'description' => 'Version of the published cohort definition the counts were derived under.',
				),
				'sensor_version' => array( 'type' => array( 'string', 'null' ) ),
				'crawler_list'   => array( 'type' => array( 'string', 'null' ) ),
				'error'          => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-cron-health-summary', array(
		'label'               => 'Cron health, summarized (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/cron-health-summary — the '
			. 'model-never-levers product of the 2026-08-11 partition review. Status '
			. '(good|recommended|critical), a summary sentence derived from the same '
			. 'counts, and the overdue/missing evidence. No run levers exist on this '
			. 'surface: reading a verdict is not causing one. Read-only. Reachable '
			. 'only by a principal holding the sn_read_remote_analytics capability, '
			. 'and only while the remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_cron_health',
		'execute_callback'    => 'snt_ability_cron_health_summary',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		// output_schema: copied BYTE-IDENTICAL from inc/abilities-cron.php.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'                     => array( 'type' => 'boolean' ),
				'status'                 => array( 'type' => 'string', 'enum' => array( 'good', 'recommended', 'critical' ) ),
				'checked_at'             => array( 'type' => 'integer' ),
				'recurring'              => array( 'type' => 'integer', 'description' => 'Recurring jobs the dashboard expects to fire.' ),
				'on_schedule'            => array( 'type' => 'integer' ),
				'overdue'                => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'job'        => array( 'type' => 'string' ),
							'cadence'    => array( 'type' => 'integer', 'description' => 'Seconds between expected firings.' ),
							'overdue_by' => array( 'type' => 'integer', 'description' => 'Seconds past the expected firing.' ),
						),
					),
				),
				'missing'                => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Expected recurring jobs with no schedule at all.' ),
				'cron_disabled_constant' => array( 'type' => 'boolean' ),
				'summary'                => array( 'type' => 'string', 'description' => 'Derived from the counts above in the same function; cannot drift from them.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
		),
	) );
} );
