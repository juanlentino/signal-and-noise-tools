<?php
/**
 * Signal & Noise Tools: the remote set, Increment 2: the seven original twins.
 *
 * Split from inc/abilities-remote-set.php (house line cap, 2026-10-13); a pure
 * move, no code changed. The file header there carries the isolation rules every
 * twin here follows. Loaded by require_once at the end of that file, in the
 * order the registrations always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
				'state'        => array( 'type' => 'string', 'enum' => array( 'ready', 'unavailable' ) ),
				'reason'       => array( 'type' => array( 'string', 'null' ) ),
				'message'      => array( 'type' => array( 'string', 'null' ) ),
				'failed_at'    => array( 'type' => array( 'integer', 'null' ) ),
				'generated_at' => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'   => array( 'type' => array( 'integer', 'null' ) ),
				'headline'     => array( 'type' => array( 'string', 'null' ) ),
				'paragraphs'   => array( 'type' => 'array' ),
				'highlights'   => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-uptime-status', array(
		'label'               => 'Get Better Stack uptime status (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/uptime-status. '
			. 'Returns the Better Stack monitor + heartbeat states (name, status, '
			. 'level) from the origin\'s cache, plus 30-day availability, incident counts '
			. 'and (18.2.0) 90-day availability from the warmed caches (null when cold). '
			. 'The 24h response keys (response_ms, median_ms, max_ms, max_region, max_at) '
			. 'are always null here: only the admin detail tier reads them. '
			. 'Served from the origin\'s cache; '
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
							'availability'  => array( 'type' => array( 'number', 'null' ), 'description' => '30-day availability percentage; on the light tier read from the hourly-warmed cache (null when cold); null whenever the summary endpoint was unavailable.' ),
							'incidents_30d' => array( 'type' => array( 'integer', 'null' ) ),
							'availability_90d' => array( 'type' => array( 'number', 'null' ) ),
							'response_ms'   => array( 'type' => array( 'integer', 'null' ), 'description' => 'MEDIAN response time over the last 24h in ms (monitors only, detail tier; null when not read). Until 2026-10-13 this was the mean, which a few checks near the timeout inflated; same value as median_ms.' ),
							'median_ms'     => array( 'type' => array( 'integer', 'null' ), 'description' => 'Median response time over the last 24h in ms, across every region (monitors only, detail tier; null when not read).' ),
							'max_ms'        => array( 'type' => array( 'integer', 'null' ), 'description' => 'The slowest single check over the last 24h in ms (monitors only, detail tier; null when not read).' ),
							'max_region'    => array( 'type' => array( 'string', 'null' ), 'description' => 'Where the slowest check ran, in words (the US, Europe, Asia, Australia; an undocumented code as given); null when not read.' ),
							'max_at'        => array( 'type' => array( 'string', 'null' ), 'description' => 'When the slowest check ran (ISO 8601, UTC, as Better Stack reports it); null when not read.' ),
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-health-scan', array(
		'label'               => 'Get content-health scan summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-health-scan. '
			. 'Returns a compact summary of the last cached Content-Health scan: '
			. 'the total finding count, the flagged checks ranked by count (each '
			. 'with its label, count, and fix hint), the passed/total check '
			. 'tally, and every skipped check with the reason it could not run. '
			. 'Returns null when no scan has run yet. Read-only — never '
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
							'partial'  => array(
								'type'        => 'string',
								'description' => 'Why part of this check was not judged this scan, naming the posts (drift: failed AI calls); empty when every post was judged.',
							),
							'failed'   => array(
								'type'        => 'array',
								'description' => 'The posts behind `partial`, one row each: post_id, title, reason in words. Capped at 20. Absent or empty when not tracked (scans before 2026-10-14).',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'post_id' => array( 'type' => 'integer' ),
										'title'   => array( 'type' => 'string' ),
										'reason'  => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
				'skipped'       => array(
					'type'        => 'array',
					'description' => 'The checks counted in checks_skipped, each with the reason it could not run (a missing token, a sandbox environment, failed AI calls). One entry per skipped check, so count( skipped ) === checks_skipped.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'check'  => array( 'type' => 'string' ),
							'label'  => array( 'type' => 'string' ),
							'reason' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-get-rss-stats', array(
		'label'               => 'Get RSS feed activity statistics (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-rss-stats. '
			. 'Returns the most recent RSS feed request timestamp + 24h / 7d / 30d '
			. 'totals + unique visitor counts + feed opens (a floor). Read-only. Reachable only by a '
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
		// output_schema: the admin registration in inc/abilities-content.php
		// minus the local-door-only data.counts_304_since (2026-10-24); the
		// strip pin in tests/abilities-remote-set.php enforces it.
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
						'subscribers'           => array(
							'type'        => array( 'object', 'null' ),
							'description' => 'Subscribers as reported by the readers (inc/feed-subscribers.php). A CEILING: per day, each aggregator fetcher\'s max self-reported count per feed, plus 1 per direct reader (distinct UA with no count); estimate_7d is the busiest day in the last 7. Never add it to feed_opens or feed clicks. Null when the module is not loaded.',
							'properties'  => array(
								'basis'          => array( 'type' => 'string', 'enum' => array( 'ceiling' ) ),
								'window'         => array( 'type' => 'string', 'enum' => array( 'max_day_7d' ) ),
								'note'           => array( 'type' => 'string' ),
								'estimate_7d'    => array( 'type' => 'integer', 'minimum' => 0 ),
								'peak_day'       => array( 'type' => array( 'string', 'null' ) ),
								'aggregators'    => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'fetcher'     => array( 'type' => 'string' ),
											'subscribers' => array( 'type' => 'integer', 'minimum' => 0 ),
										),
									),
								),
								'direct_readers' => array( 'type' => 'integer', 'minimum' => 0 ),
								'flagged'        => array(
									'type'        => 'array',
									'description' => 'Fetchers whose claim exceeded 1,000,000 and was capped: likely spoofed.',
									'items'       => array( 'type' => 'string' ),
								),
							),
						),
						'feed_opens'            => array(
							'type'        => array( 'object', 'null' ),
							'description' => 'Feed opens from the pixel in each full-text feed item (inc/feed-opens.php). A FLOOR, read as "at least": readers that block images never load it. Null when the module is not loaded.',
							'properties'  => array(
								'basis'     => array( 'type' => 'string', 'enum' => array( 'floor' ) ),
								'note'      => array( 'type' => 'string' ),
								'total_7d'  => array( 'type' => 'integer', 'minimum' => 0 ),
								'total_30d' => array( 'type' => 'integer', 'minimum' => 0 ),
								'top_30d'   => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'post_id' => array( 'type' => 'integer' ),
											'title'   => array( 'type' => 'string' ),
											'opens'   => array( 'type' => 'integer', 'minimum' => 0 ),
										),
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
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
		'execute_callback'    => 'snt_ability_remote_get_deploy_status',
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
		// inc/abilities-system.php, MINUS `runtime` (contract 13),
		// `last_worker_deploy` (local door only, 2026-10-08) and
		// `last_deploy_at` (local door only, 2026-10-25): the parity
		// pin in tests enforces === against the admin schema with runtime removed.
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
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
