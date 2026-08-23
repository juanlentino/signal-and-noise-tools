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
