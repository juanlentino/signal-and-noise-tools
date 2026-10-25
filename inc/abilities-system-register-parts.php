<?php
/**
 * Signal & Noise Tools: abilities-system.php, the wp_abilities_api_init
 * registrations.
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

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/purge-all-caches', array(
		'label'               => 'Purge all caches',
		'description'         => 'Emergency refresh of the page caches: transients and the Cloudflare edge. NOT needed after a deploy or a post save; both refresh the caches on their own (20.9.0), and a failed refresh is mailed by the alert run. Use it only when a page is visibly wrong and stays wrong. Redis (the object cache) is left alone unless flush_object_cache is true. The response reports whether the Cloudflare zone purge was actually confirmed (v10.4.1); ok is false when the CF leg could not run or was rejected.',
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
					'description' => 'Also clear this theme\'s wp_template/wp_template_part DB rows (menus are kept). Default false — overrides are typically intentional Site Editor changes.',
					'default'     => false,
				),
				'flush_object_cache' => array(
					'type'        => 'boolean',
					'description' => 'Also empty Redis (the whole object cache). The Cloudways app purge is retired (2026-10-14): Varnish is off for this app. Default false since 20.9.0: emptying Redis drops WordPress\'s update checks and every cached reading, and a stale page never needs it. include_template_overrides implies it (a full reset).',
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-deploy-status', array(
		'label'               => 'Get theme + plugin deploy status',
		'description'         => 'Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available — plus a `workers` array for the five owned Cloudflare workers (live probe vs latest GitHub tag; unprobeable/unknown rows stay visible). Pass force_refresh=true to clear the GitHub-tag + update_themes/update_plugins + worker probe transients first so the answer is freshly fetched (replaces the removed force-check-updates ability; clears caches only, never user data). Read-only; safe to call anytime.',
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
				'last_deploy_component' => array(
					'type'        => 'string',
					'description' => 'Which package last_deploy refers to: "Theme", "Plugin", or "" when unknown. Added v12.13.0 because last_deploy is an age with no subject, and the Deploy Status card renders it under seven independently versioned rows — theme, plugin and five workers — so a bare age read as though it covered all of them. It never did: the workers do not install through the WP upgrader and have no records in this feed at all, so this field is the scope disclosure as much as the name.',
				),
				'last_deploy_at' => array(
					'type'        => 'string',
					'description' => 'When last_deploy happened, ISO 8601 UTC, or "" when unknown. Added 2026-10-25 so a client ages the line itself instead of repainting a frozen relative string.',
				),
				'last_gha_run' => array(
					'type'        => 'string',
					'description' => 'Relative time of the most recent deploy GHA workflow run across both repos — the pre-v9.63.3 last_deploy reading, kept as a clearly-labeled secondary field. deploy.yml is the workflow_dispatch-only emergency fallback, so this moves only on manual dispatches. Empty string if unknown. Added v9.63.3.',
				),
				// Additive: the worker deploy line (inc/deploy-workers-seen.php).
				'last_worker_deploy' => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'When the five-minute version probe first read a worker\'s new live version: { label, version, at (ISO 8601) }, accurate to five minutes. Until any worker changes after the log started: { since (ISO 8601) }. Null before any probe. The first version read for a worker is a baseline, never a deploy.',
					'properties'  => array(
						'label'   => array( 'type' => 'string' ),
						'version' => array( 'type' => 'string' ),
						'at'      => array( 'type' => 'string' ),
						'since'   => array( 'type' => 'string' ),
					),
				),
				// Additive (contract 13, 2026-09-29): WordPress core beside theme and plugin.
				'core' => array(
					'type'        => 'object',
					'description' => 'WordPress core. current is $wp_version; latest is the highest version the CACHED update_core transient offers (never a live check); state ok|behind|unknown; offer says what kind of release is waiting: point (a same-branch point release, response autoupdate; WordPress ships security fixes as point releases but does not flag them), major (only an upgrade offer), or empty when ok or unknown; point wins when both exist; auto_updates is WordPress\'s own updater (minor|all|off); reason says why when not ok.',
					'properties'  => array(
						'current'      => array( 'type' => 'string' ),
						'latest'       => array( 'type' => 'string' ),
						'state'        => array( 'type' => 'string', 'enum' => array( 'ok', 'behind', 'unknown' ) ),
						'offer'        => array( 'type' => 'string', 'enum' => array( 'point', 'major', '' ) ),
						'auto_updates' => array( 'type' => 'string', 'enum' => array( 'minor', 'all', 'off' ) ),
						'reason'       => array( 'type' => 'string' ),
					),
				),
				// Local door only: the remote twin drops it (disclosure, see docs/SECURITY.md).
				'runtime' => array(
					'type'       => 'object',
					'properties' => array(
						'php'                => array( 'type' => 'string' ),
						'register_argc_argv' => array( 'type' => 'boolean' ),
					),
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
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/clear-template-overrides', array(
		'label'               => 'Clear database template overrides',
		'description'         => 'Removes the active theme\'s wp_template and wp_template_part rows the Site Editor has saved that override the theme files (navigation menus and other themes\' rows are kept). Returns the count cleared. Use this if Site Editor edits have introduced regressions.',
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );


	wp_register_ability( 'signal-noise/list-template-overrides', array(
		'label'               => 'List database template overrides',
		'description'         => 'Returns the slugs and post types of the active theme\'s wp_template / wp_template_part rows currently overriding theme files (the set clear-template-overrides removes). Read-only inspection before the destructive clear-template-overrides.',
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

