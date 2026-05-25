<?php
/**
 * Signal & Noise Tools — Abilities API registration (Phase 14).
 *
 * Registers four SN operations as WordPress 7.0 Abilities so the AI
 * Client (and any future ability-consuming integrations like the
 * Command Palette or AI Copilot) can discover and invoke them with
 * typed input/output schemas + behavioral annotations.
 *
 * Abilities registered:
 *   - signal-noise/purge-all-caches      — destructive, idempotent
 *   - signal-noise/regenerate-og-card    — idempotent
 *   - signal-noise/get-deploy-status     — readonly, idempotent
 *   - signal-noise/clear-template-overrides — destructive, idempotent
 *
 * Activation:
 *   - On WP 7.0+: `wp_abilities_api_categories_init` fires first → we
 *     register our 3 categories; then `wp_abilities_api_init` fires →
 *     we register the 4 abilities (each cites a registered category).
 *   - On WP 6.x: neither action exists; both hooks are inert. No
 *     `function_exists()` guard needed at the top level — hooking on
 *     a non-existent action costs nothing.
 *
 * Capability gating: every ability has a permission_callback. Permission
 * checks ONLY auth (no input-shape validation — the input_schema does
 * that automatically before permission_callback fires).
 *
 * Schemas: input_schema + output_schema documented as WP-REST JSON-Schema
 * subset (type, enum, required, minLength, properties + the commonly
 * accepted extensions: minimum, default, format).
 *
 * Verified against WordPress/abilities-api `includes/abilities-api.php`
 * on trunk (2026-05-17 audit): categories MUST be pre-registered via
 * `wp_register_ability_category()` during `wp_abilities_api_categories_init`,
 * otherwise `wp_register_ability()` calls return null and _doing_it_wrong
 * fires. Same audit confirmed namespace pattern `^[a-z0-9-]+/[a-z0-9-]+$`
 * and the bool|WP_Error return type of permission_callback.
 *
 * Phase 14 of the plugin absorption roadmap. Ships with plugin v2.0.4
 * (rolled into hotfix patch after the v2.1.0 attempt surfaced multiple
 * blockers in v2.0.3 staging — the audit caught everything pre-launch).
 *
 * @package SignalNoiseTools
 * @since 2.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 1: register our three categories BEFORE abilities try to cite them.
 *
 * Per upstream source, the registry checks `wp_has_ability_category()` and
 * silently bails on `wp_register_ability()` if the category isn't found.
 * Skipping this step means all four ability registrations below are no-ops.
 */
add_action( 'wp_abilities_api_categories_init', function() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category( 'maintenance', array(
		'label'       => 'Maintenance',
		'description' => 'Cache + template-override + update-detection housekeeping operations.',
	) );

	wp_register_ability_category( 'content', array(
		'label'       => 'Content',
		'description' => 'Per-post content artifacts (OG cards, schema, etc.).',
	) );

	wp_register_ability_category( 'diagnostics', array(
		'label'       => 'Diagnostics',
		'description' => 'Read-only inspection of the theme + plugin pair\'s state.',
	) );

	// v2.5.0: 2 new categories ahead of registering 7 new abilities.
	wp_register_ability_category( 'updates', array(
		'label'       => 'Updates',
		'description' => 'Theme + plugin update detection + force-check.',
	) );

	wp_register_ability_category( 'ai-generation', array(
		'label'       => 'AI Generation',
		'description' => 'AI Client-backed content generation (meta descriptions, OG card titles, excerpts).',
	) );
} );

/**
 * Step 2: register the abilities themselves.
 */
add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$permission_manage_options = function() {
		return current_user_can( 'manage_options' );
	};

	wp_register_ability( 'signal-noise/purge-all-caches', array(
		'label'               => 'Purge all caches',
		'description'         => 'Clears WordPress object cache, transients, Breeze page cache, Varnish, and Cloudflare edge cache. Use after deploys or when content appears stale.',
		'category'            => 'maintenance',
		'permission_callback' => $permission_manage_options,
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
				'ok'      => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'count'   => array( 'type' => 'integer', 'description' => 'Number of overrides cleared (0 if include_template_overrides was false).' ),
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

	wp_register_ability( 'signal-noise/regenerate-og-card', array(
		'label'               => 'Regenerate Open Graph card image',
		'description'         => 'Rebuilds the social-share card image (/wp-content/uploads/sn-og/post-{ID}.png) for a single post. Use after editing post title or featured image.',
		'category'            => 'content',
		'permission_callback' => function( $input ) {
			// input_schema's `required: ['post_id']` handles missing-field
			// validation before this fires; here we only check auth.
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			return current_user_can( 'edit_post', $post_id );
		},
		'execute_callback'    => 'snt_ability_regenerate_og_card',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID whose OG card should be regenerated.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'        => array( 'type' => 'boolean' ),
				'image_url' => array( 'type' => 'string', 'format' => 'uri' ),
				'message'   => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations' => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-deploy-status', array(
		'label'               => 'Get theme + plugin deploy status',
		'description'         => 'Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available. Read-only; safe to call anytime.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_deploy_status',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment — null accepted because
			// readonly abilities (GET) receive null when caller omits ?input=.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
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
		'permission_callback' => $permission_manage_options,
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

	/* ════════════════════════════════════════════════════════════════════
	 * v2.5.0 additions — 7 new abilities consolidating the entire SN
	 * canonical action surface onto the Abilities API.
	 * ════════════════════════════════════════════════════════════════════ */

	$permission_edit_post = function( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		return current_user_can( 'edit_post', $post_id );
	};

	wp_register_ability( 'signal-noise/force-check-updates', array(
		'label'               => 'Force-check theme + plugin updates',
		'description'         => 'Clears the sn_gh_latest_* + update_themes + update_plugins site transients so the next admin page-load refetches fresh data from GitHub. No user data deleted.',
		'category'            => 'updates',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_force_check_updates',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/full-reset', array(
		'label'               => 'Full reset (clear overrides + purge all caches)',
		'description'         => 'Clears wp_template / wp_template_part / wp_navigation DB overrides AND purges every cache (object cache, Breeze, Varnish, Cloudflare). Use after a theme/plugin update or when content appears stale.',
		'category'            => 'maintenance',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_full_reset',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'count' => array( 'type' => 'integer' ),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/list-template-overrides', array(
		'label'               => 'List database template overrides',
		'description'         => 'Returns the slugs and post types of any wp_template / wp_template_part / wp_navigation rows currently overriding theme files. Read-only inspection before the destructive clear-template-overrides.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
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

	wp_register_ability( 'signal-noise/get-rss-stats', array(
		'label'               => 'Get RSS feed activity statistics',
		'description'         => 'Returns the most recent RSS feed request timestamp + 24h / 7d / 30d totals + unique visitor counts. Backed by the sn_rss_tracker module. Use to verify RSS feed traffic before changing feed structure or auditing crawler activity.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_rss_stats',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
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
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-generate-meta-description', array(
		'label'               => 'Generate SEO meta description with AI',
		'description'         => 'Generates a 140-160 character meta description from post content via the WP AI Client. Writes to the _sn_meta_description post meta override.',
		'category'            => 'ai-generation',
		'permission_callback' => $permission_edit_post,
		'execute_callback'    => 'snt_ability_ai_generate_meta_description',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'          => array( 'type' => 'boolean' ),
				'description' => array( 'type' => 'string' ),
				'length'      => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-generate-og-card-title', array(
		'label'               => 'Generate OG card title with AI',
		'description'         => 'Generates a 60-90 character punchy variant of the post title via the WP AI Client, writes to _sn_og_card_title post meta, AND re-runs sn_generate_og_card so the social-share PNG reflects the new title immediately.',
		'category'            => 'ai-generation',
		'permission_callback' => $permission_edit_post,
		'execute_callback'    => 'snt_ability_ai_generate_og_card_title',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'               => array( 'type' => 'boolean' ),
				'title'            => array( 'type' => 'string' ),
				'length'           => array( 'type' => 'integer' ),
				'card_regenerated' => array( 'type' => 'boolean' ),
				'card_url'         => array( 'type' => 'string', 'format' => 'uri' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-generate-excerpt', array(
		'label'               => 'Generate post excerpt with AI',
		'description'         => 'Generates a 50-75 word, 2-3 sentence excerpt from post content via the WP AI Client. Returns the text; the caller writes it to WP\'s native post_excerpt field.',
		'category'            => 'ai-generation',
		'permission_callback' => $permission_edit_post,
		'execute_callback'    => 'snt_ability_ai_generate_excerpt',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
					'examples'    => array( 42, 1023 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'excerpt' => array( 'type' => 'string' ),
				'length'  => array( 'type' => 'integer' ),
				'words'   => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	// ── Cron Dashboard abilities (v3.0.0) ─────────────────────────────

	wp_register_ability( 'signal-noise/list-cron-events', array(
		'label'               => 'List Cron Events',
		'description'         => 'Returns all scheduled WP-Cron events with next-run, recurrence, last-fired, args, has_handler flag, and is_sn_owned flag. Pass sn_only=true to filter to the 3 SN-owned hooks (Plausible refresh, RSS prune, deploy webhook).',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_list_cron_events',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'sn_only' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, filter to the 3 SN-owned hooks only.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'hook'           => array( 'type' => 'string' ),
					'args_signature' => array( 'type' => 'string' ),
					'next_run_ts'    => array( 'type' => 'integer' ),
					'schedule'       => array( 'type' => array( 'string', 'boolean' ) ),
					'interval_s'     => array( 'type' => array( 'integer', 'null' ) ),
					'args'           => array( 'type' => 'array' ),
					'last_fired_ts'  => array( 'type' => array( 'integer', 'null' ) ),
					'has_handler'    => array( 'type' => 'boolean' ),
					'is_sn_owned'    => array( 'type' => 'boolean' ),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-cron-event', array(
		'label'               => 'Get Cron Event Details',
		'description'         => 'Returns details for a single scheduled cron event identified by hook + args_signature. Returns null if no match. `args_signature` is the md5 hash returned by signal-noise/list-cron-events. Use that ability first to discover signatures.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_get_cron_event',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook', 'args_signature' ),
			'properties'           => array(
				'hook'           => array(
					'type'        => 'string',
					'description' => 'The cron hook name.',
					'minLength'   => 1,
					'examples'    => array( 'sn_plausible_refresh_dashboard', 'sn_rss_tracker_daily_prune', 'wp_scheduled_delete' ),
				),
				'args_signature' => array(
					'type'        => 'string',
					'description' => 'The md5 args signature from list-cron-events.',
					'minLength'   => 1,
					'examples'    => array( '', '3a8e965b27c4c1d4b8a9' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'hook'           => array( 'type' => 'string' ),
				'args_signature' => array( 'type' => 'string' ),
				'next_run_ts'    => array( 'type' => 'integer' ),
				'schedule'       => array( 'type' => array( 'string', 'boolean' ) ),
				'interval_s'     => array( 'type' => array( 'integer', 'null' ) ),
				'args'           => array( 'type' => 'array' ),
				'last_fired_ts'  => array( 'type' => array( 'integer', 'null' ) ),
				'has_handler'    => array( 'type' => 'boolean' ),
				'is_sn_owned'    => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-cron-history', array(
		'label'               => 'Get Cron Firing History',
		'description'         => 'Returns the most recent N firings of a cron hook with elapsed time, success/failure, and any error message. Backed by the snt_cron_history table populated since plugin v3.2.0; retention is a rolling 30 days OR 1000 rows per hook, whichever is shorter.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_get_cron_history',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook' ),
			'properties'           => array(
				'hook'  => array(
					'type'        => 'string',
					'description' => 'The cron hook name to look up history for.',
					'minLength'   => 1,
					'examples'    => array( 'sn_plausible_refresh_dashboard', 'sn_rss_tracker_daily_prune' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum rows to return (1–100, default 10, newest first).',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'examples'    => array( 20, 50 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'hook'           => array( 'type' => 'string' ),
					'args_signature' => array( 'type' => 'string' ),
					'fired_at'       => array( 'type' => 'string', 'description' => 'UTC datetime "Y-m-d H:i:s".' ),
					'fired_at_ts'    => array( 'type' => 'integer', 'description' => 'Unix timestamp of fired_at.' ),
					'elapsed_ms'     => array( 'type' => array( 'integer', 'null' ) ),
					'success'        => array( 'type' => 'boolean' ),
					'error_message'  => array( 'type' => array( 'string', 'null' ) ),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/run-insights-scan', array(
		'label'               => 'Run Insights Synthesis Scan',
		'description'         => 'Triggers a cross-system synthesis scan that combines Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable content recommendations. Cached for 7 days. Pass force=true to bypass the cache.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_run_insights_scan',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, bypass the 7-day cache and run a fresh AI call.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'scanned_at'      => array( 'type' => 'integer' ),
				'elapsed_ms'      => array( 'type' => 'integer' ),
				'recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array( 'type' => 'string' ),
							'type'           => array( 'type' => 'string', 'enum' => array( 'write_about', 'update_post', 'cadence_change', 'topic_double_down', 'topic_pivot' ) ),
							'title'          => array( 'type' => 'string' ),
							'rationale'      => array( 'type' => 'string' ),
							'evidence_pills' => array( 'type' => 'array' ),
							'target'         => array( 'type' => array( 'object', 'null' ) ),
						),
					),
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-insights', array(
		'label'               => 'Get Last Insights Scan',
		'description'         => 'Returns the cached result of the last synthesis scan (recommendations array + metadata). Returns null when no scan has run yet.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_get_insights',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'scanned_at'      => array( 'type' => array( 'integer', 'null' ) ),
				'elapsed_ms'      => array( 'type' => array( 'integer', 'null' ) ),
				'recommendations' => array( 'type' => 'array' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	wp_register_ability( 'signal-noise/unschedule-cron-event', array(
		'label'               => 'Unschedule cron event',
		'description'         => 'Permanently removes a scheduled WP-Cron event (single OR recurring) by hook + args. SN-owned hooks (Plausible refresh, RSS prune) are refused with a clear error. The matching event is identified by exact args match — pass [] for events scheduled without args. Returns the count cleared (0 if no match). Useful for pruning orphaned cron events left by uninstalled plugins.',
		'category'            => 'maintenance',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => 'snt_ability_unschedule_cron_event',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'hook' ),
			'properties'           => array(
				'hook' => array(
					'type'        => 'string',
					'description' => 'The cron hook name to unschedule.',
					'minLength'   => 1,
					'examples'    => array( 'orphaned_plugin_cron_hook', 'wp_scheduled_delete' ),
				),
				'args' => array(
					'type'        => 'array',
					'description' => 'Optional args array — must match the scheduled signature exactly. Pass [] for events scheduled without args.',
					'default'     => array(),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'hook'    => array( 'type' => 'string' ),
				'args'    => array( 'type' => 'array' ),
				'cleared' => array( 'type' => 'integer', 'description' => 'Number of events removed; 0 if no match.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive'     => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );

	// v3.8.3: 4 audit log abilities (3 read + 1 maintenance).
	wp_register_ability( 'signal-noise/get-audit-summary', array(
		'label'               => 'Get audit log hero summary',
		'description'         => 'Returns last-24h totals, 7-day trend vs. prior, unique attackers in 24h, and LLA lockout status. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_summary',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'last_24h'             => array( 'type' => 'object' ),
				'last_7d_vs_prior'     => array( 'type' => 'object' ),
				'unique_attackers_24h' => array( 'type' => 'integer' ),
				'lla'                  => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-counters', array(
		'label'               => 'Get audit log counter timeline',
		'description'         => 'Returns per-day event counters for the last N days (default 30, max 90). Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_counters',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-audit-login-successes', array(
		'label'               => 'Get recent successful logins',
		'description'         => 'Returns recent per-event successful login records for the last N days (default 30, max 90). Each row: timestamp + username. No IP info. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_get_audit_login_successes',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 90,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/run-audit-prune', array(
		'label'               => 'Run audit log prune now',
		'description'         => 'Manually drops counter buckets and login_success rows older than 90 days. Also polls LLA for new lockouts. Destructive of historical data — NOT exposed to AI.',
		'category'            => 'maintenance',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => 'snt_ability_run_audit_prune',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'counter_buckets_dropped' => array( 'type' => 'integer' ),
				'login_rows_dropped'      => array( 'type' => 'integer' ),
				'lla_delta'               => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );

	// ── v4.0.0: AI health suggest+apply (4 abilities — 2 suggest, 2 apply) ──

	wp_register_ability( 'signal-noise/ai-alt-suggest', array(
		'label'               => 'Suggest alt text for an attachment',
		'description'         => 'Generate descriptive 80-125 character alt text for an image attachment via the WP AI Client, using attachment title + caption + filename + first referencing post as context. Does NOT write — returns the suggestion for review.',
		'category'            => 'ai-generation',
		'permission_callback' => function( $input ) {
			$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			return current_user_can( 'edit_post', $attachment_id );
		},
		'execute_callback'    => 'snt_ability_ai_alt_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to generate alt text for.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'suggestion'    => array( 'type' => 'string' ),
				'attachment_id' => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-alt-apply', array(
		'label'               => 'Apply alt text to an attachment',
		'description'         => 'Writes a (possibly user-edited) alt text string to an image attachment\'s _wp_attachment_image_alt meta. Destructive — requires edit_post on the attachment.',
		'category'            => 'ai-generation',
		'permission_callback' => function( $input ) {
			$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			return current_user_can( 'edit_post', $attachment_id );
		},
		'execute_callback'    => 'snt_ability_ai_alt_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'attachment_id', 'alt_text' ),
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'Image-attachment post ID to update.',
					'minimum'     => 1,
					'examples'    => array( 1234 ),
				),
				'alt_text' => array(
					'type'        => 'string',
					'description' => 'The alt text to write. Trimmed, non-empty, max 250 chars.',
					'minLength'   => 1,
					'maxLength'   => 250,
					'examples'    => array( 'A red barn at dusk with two figures walking toward it.' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'            => array( 'type' => 'boolean' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'written_alt'   => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-drift-suggest', array(
		'label'               => 'Suggest replacement for a drifted time-phrase',
		'description'         => 'Generate a temporally-explicit replacement for a stale time-relative phrase (e.g., "recently" → "in early 2025") via the WP AI Client. Includes a fingerprint that the apply call must echo back to detect post_content drift since the suggest. Does NOT write — returns the suggestion + fingerprint for review.',
		'category'            => 'ai-generation',
		'permission_callback' => function( $input ) {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			return current_user_can( 'edit_post', $post_id );
		},
		'execute_callback'    => 'snt_ability_ai_drift_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'phrase', 'position', 'context_snippet' ),
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Post that owns the phrase.', 'examples' => array( 42 ) ),
				'phrase'          => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Original phrase as flagged by drift detection.', 'examples' => array( 'recently' ) ),
				'position'        => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Byte offset of phrase in post_content (from scan).', 'examples' => array( 145 ) ),
				'context_snippet' => array( 'type' => 'string', 'description' => '~200 chars around phrase (from scan; helps AI judge replacement appropriateness).', 'examples' => array( 'we recently shipped a new feature that' ) ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'          => array( 'type' => 'boolean' ),
				'suggestion'  => array( 'type' => 'string' ),
				'fingerprint' => array( 'type' => 'string', 'description' => 'md5 hash to echo back on apply.' ),
				'post_id'     => array( 'type' => 'integer' ),
				'position'    => array( 'type' => 'integer' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-drift-apply', array(
		'label'               => 'Apply replacement for a drifted time-phrase',
		'description'         => 'Replaces a drifted phrase in post_content at a known position with a (possibly user-edited) replacement string. Gated on a fingerprint match against current post_content to detect concurrent edits since the suggest call. Destructive — writes via wp_update_post().',
		'category'            => 'ai-generation',
		'permission_callback' => function( $input ) {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			return current_user_can( 'edit_post', $post_id );
		},
		'execute_callback'    => 'snt_ability_ai_drift_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'phrase', 'position', 'replacement', 'fingerprint' ),
			'properties'           => array(
				'post_id'     => array( 'type' => 'integer', 'minimum' => 1, 'examples' => array( 42 ) ),
				'phrase'      => array( 'type' => 'string', 'minLength' => 1, 'examples' => array( 'recently' ) ),
				'position'    => array( 'type' => 'integer', 'minimum' => 0, 'examples' => array( 145 ) ),
				'replacement' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'examples' => array( 'in early 2025' ) ),
				'fingerprint' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'description' => 'md5 hash from the matching suggest call.', 'examples' => array( 'a1b2c3d4e5f6789012345678901234ab' ) ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'post_id'  => array( 'type' => 'integer' ),
				'replaced' => array( 'type' => 'string' ),
				'with'     => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/ai-alt-inline-suggest', array(
		'label'               => 'Suggest alt text for an inline <img> in a post body',
		'description'         => 'Generate descriptive 80-125 character alt text for an <img> tag found in a post\'s post_content. Uses post title + image filename + ~500 chars of surrounding paragraph context. Does NOT write — returns the suggestion for the user to copy + paste into the editor. Inline-img Apply is deferred indefinitely per block-serialization risk.',
		'category'            => 'ai-generation',
		'permission_callback' => function( $input ) {
			$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			return current_user_can( 'edit_post', $post_id );
		},
		'execute_callback'    => 'snt_ability_ai_alt_inline_suggest',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'image_src' ),
			'properties'           => array(
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Post that contains the inline <img> tag.',
					'minimum'     => 1,
					'examples'    => array( 42 ),
				),
				'image_src' => array(
					'type'        => 'string',
					'description' => 'The <img src="..."> URL as it appears in post_content. Must match byte-for-byte.',
					'minLength'   => 1,
					'examples'    => array( 'https://juanlentino.com/wp-content/uploads/2026/05/example.png' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'suggestion' => array( 'type' => 'string' ),
				'post_id'    => array( 'type' => 'integer' ),
				'image_src'  => array( 'type' => 'string' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * Ability execute callbacks
 *
 * Extracted from inline closures to top-level named functions per the
 * v3.7.5 audit P4 follow-up. Enables unit testing in isolation; matches
 * the theme's pattern (sn_theme_ability_*).
 *
 * Each delegates to the existing filter contracts and impl helpers that
 * already power the REST endpoints in inc/desktop-mode-integration.php.
 * Abilities are a third dispatch surface (alongside admin UI + REST) for
 * the same underlying operations — one implementation, three callers.
 * ════════════════════════════════════════════════════════════════════════ */

function snt_ability_purge_all_caches( $input ) {
	$include_overrides = ! empty( $input['include_template_overrides'] );

	if ( ! has_filter( 'sn_purge_all_caches_result' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Cache helper unavailable — theme module not loaded.', array( 'status' => 500 ) );
	}

	$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => $include_overrides ) );

	return array(
		'ok'      => true,
		'message' => $include_overrides
			? sprintf( 'All caches purged; %d template override%s cleared.', $count, 1 === $count ? '' : 's' )
			: 'All caches purged.',
		'count'   => $count,
	);
}

function snt_ability_regenerate_og_card( $input ) {
	$post_id = (int) $input['post_id'];
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'snt_post_not_found', sprintf( 'Post %d not found.', $post_id ), array( 'status' => 404 ) );
	}

	if ( ! function_exists( 'sn_generate_og_card' ) ) {
		return new WP_Error( 'snt_og_unavailable', 'OG card generator not available.', array( 'status' => 500 ) );
	}

	// sn_generate_og_card() returns bool — true on PNG write, false on
	// any failure (missing GD/font/upload-dir). Build the URL ourselves
	// from sn_og_image_url_for_post() on success.
	$ok = sn_generate_og_card( $post_id );
	if ( ! $ok ) {
		return new WP_Error( 'snt_og_failed', 'OG card regeneration failed (check that GD + theme fonts are available).', array( 'status' => 500 ) );
	}

	$image_url = function_exists( 'sn_og_image_url_for_post' ) ? sn_og_image_url_for_post( $post ) : '';

	return array(
		'ok'        => true,
		'image_url' => $image_url,
		'message'   => sprintf( 'OG card regenerated for "%s".', wp_strip_all_tags( get_the_title( $post ) ) ),
	);
}

function snt_ability_get_deploy_status() {
	if ( ! function_exists( 'snt_deploy_status_for' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Deploy status helper unavailable.', array( 'status' => 500 ) );
	}

	return array(
		'theme'  => snt_deploy_status_for( 'theme' ),
		'plugin' => snt_deploy_status_for( 'plugin' ),
	);
}

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
 * Ability execute callback for signal-noise/unschedule-cron-event.
 * Thin wrapper around snt_cron_unschedule_event_impl() so the dispatch
 * layer (ability) doesn't duplicate the impl's safety checks.
 *
 * @since 3.1.0
 */
function snt_ability_unschedule_cron_event( $input ) {
	if ( ! function_exists( 'snt_cron_unschedule_event_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	$hook = isset( $input['hook'] ) ? (string) $input['hook'] : '';
	$args = isset( $input['args'] ) && is_array( $input['args'] ) ? $input['args'] : array();
	return snt_cron_unschedule_event_impl( $hook, $args );
}

/**
 * Ability execute callback for signal-noise/get-cron-history.
 * Thin wrapper around snt_cron_history_for_hook().
 *
 * @since 3.2.0
 */
function snt_ability_get_cron_history( $input ) {
	if ( ! function_exists( 'snt_cron_history_for_hook' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron history module not loaded.', array( 'status' => 500 ) );
	}
	$hook  = isset( $input['hook'] ) ? (string) $input['hook'] : '';
	$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
	return snt_cron_history_for_hook( $hook, $limit );
}

/**
 * Ability execute callback: signal-noise/run-insights-scan.
 * Thin wrapper around snt_insights_run_scan().
 * @since 3.6.0
 */
function snt_ability_run_insights_scan( $input ) {
	if ( ! function_exists( 'snt_insights_run_scan' ) ) {
		return new WP_Error( 'snt_insights_unavailable', 'Insights module not loaded.', array( 'status' => 500 ) );
	}
	$force = is_array( $input ) && ! empty( $input['force'] );
	return snt_insights_run_scan( $force );
}

/**
 * Ability execute callback: signal-noise/get-insights.
 * Thin wrapper around snt_insights_last_scan().
 * @since 3.6.0
 */
function snt_ability_get_insights( $input ) {
	if ( ! function_exists( 'snt_insights_last_scan' ) ) {
		return new WP_Error( 'snt_insights_unavailable', 'Insights module not loaded.', array( 'status' => 500 ) );
	}
	return snt_insights_last_scan();
}

/**
 * Ability execute callback: signal-noise/force-check-updates.
 * Thin wrapper around snt_cmd_impl_force_check().
 * @since 3.7.5
 */
function snt_ability_force_check_updates() {
	if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Force-check helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_cmd_impl_force_check();
}

/**
 * Ability execute callback: signal-noise/full-reset.
 * Thin wrapper around snt_cmd_impl_full_reset().
 * @since 3.7.5
 */
function snt_ability_full_reset() {
	if ( ! function_exists( 'snt_cmd_impl_full_reset' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Full-reset helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_cmd_impl_full_reset();
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

/**
 * Ability execute callback: signal-noise/get-rss-stats.
 * Thin wrapper around snt_cmd_impl_rss_stats().
 * @since 3.7.5
 */
function snt_ability_get_rss_stats() {
	if ( ! function_exists( 'snt_cmd_impl_rss_stats' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'RSS-stats helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_cmd_impl_rss_stats();
}

/**
 * Ability execute callback: signal-noise/ai-generate-meta-description.
 * Thin wrapper around snt_ai_meta_desc_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_meta_description( $input ) {
	if ( ! function_exists( 'snt_ai_meta_desc_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Meta-desc helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_meta_desc_impl( (int) $input['post_id'] );
}

/**
 * Ability execute callback: signal-noise/ai-generate-og-card-title.
 * Thin wrapper around snt_ai_og_card_title_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_og_card_title( $input ) {
	if ( ! function_exists( 'snt_ai_og_card_title_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'OG-title helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_og_card_title_impl( (int) $input['post_id'] );
}

/**
 * Ability execute callback: signal-noise/ai-generate-excerpt.
 * Thin wrapper around snt_ai_excerpt_impl().
 * @since 3.7.5
 */
function snt_ability_ai_generate_excerpt( $input ) {
	if ( ! function_exists( 'snt_ai_excerpt_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Excerpt helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_excerpt_impl( (int) $input['post_id'] );
}

/**
 * Ability execute callback: signal-noise/list-cron-events.
 * Thin wrapper around snt_cron_get_events_impl() with sn_only filter passthrough.
 * @since 3.7.5
 */
function snt_ability_list_cron_events( $input ) {
	if ( ! function_exists( 'snt_cron_get_events_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	$sn_only = is_array( $input ) && ! empty( $input['sn_only'] );
	return snt_cron_get_events_impl( $sn_only );
}

/**
 * Ability execute callback: signal-noise/get-cron-event.
 * Thin wrapper around snt_cron_get_event_impl().
 * @since 3.7.5
 */
function snt_ability_get_cron_event( $input ) {
	if ( ! function_exists( 'snt_cron_get_event_impl' ) ) {
		return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
	}
	return snt_cron_get_event_impl(
		(string) $input['hook'],
		(string) $input['args_signature']
	);
}

/**
 * Execute callback for signal-noise/get-audit-summary.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_summary() {
	if ( ! function_exists( 'snt_audit_get_summary_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_get_summary_impl();
}

/**
 * Execute callback for signal-noise/get-audit-counters.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_counters( $input ) {
	if ( ! function_exists( 'snt_audit_get_counters_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_counters_impl( $days );
}

/**
 * Execute callback for signal-noise/get-audit-login-successes.
 *
 * @since 3.8.3
 */
function snt_ability_get_audit_login_successes( $input ) {
	if ( ! function_exists( 'snt_audit_get_login_successes_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	$days = isset( $input['days'] ) ? (int) $input['days'] : 30;
	return snt_audit_get_login_successes_impl( $days );
}

/**
 * Execute callback for signal-noise/run-audit-prune.
 *
 * @since 3.8.3
 */
function snt_ability_run_audit_prune() {
	if ( ! function_exists( 'snt_audit_prune_impl' ) ) {
		return new WP_Error( 'snt_audit_unavailable', 'Audit log module not loaded.', array( 'status' => 500 ) );
	}
	return snt_audit_prune_impl();
}

/**
 * Execute callback for signal-noise/ai-alt-suggest.
 * Thin wrapper around snt_ai_alt_suggest_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_alt_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_alt_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Alt-suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_suggest_impl( (int) $input['attachment_id'] );
}

/**
 * Execute callback for signal-noise/ai-alt-apply.
 * Thin wrapper around snt_ai_alt_apply_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_alt_apply( $input ) {
	if ( ! function_exists( 'snt_ai_alt_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Alt-apply helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_apply_impl(
		(int) $input['attachment_id'],
		(string) $input['alt_text']
	);
}

/**
 * Execute callback for signal-noise/ai-drift-suggest.
 * Thin wrapper around snt_ai_drift_suggest_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_drift_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_drift_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Drift-suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_drift_suggest_impl(
		(int) $input['post_id'],
		(string) $input['phrase'],
		(int) $input['position'],
		(string) $input['context_snippet']
	);
}

/**
 * Execute callback for signal-noise/ai-drift-apply.
 * Thin wrapper around snt_ai_drift_apply_impl().
 *
 * @since 4.0.0
 */
function snt_ability_ai_drift_apply( $input ) {
	if ( ! function_exists( 'snt_ai_drift_apply_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Drift-apply helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_drift_apply_impl(
		(int) $input['post_id'],
		(string) $input['phrase'],
		(int) $input['position'],
		(string) $input['replacement'],
		(string) $input['fingerprint']
	);
}

/**
 * Execute callback for signal-noise/ai-alt-inline-suggest.
 * Thin wrapper around snt_ai_alt_inline_suggest_impl().
 *
 * @since 4.0.2
 */
function snt_ability_ai_alt_inline_suggest( $input ) {
	if ( ! function_exists( 'snt_ai_alt_inline_suggest_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Inline-alt suggest helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_ai_alt_inline_suggest_impl(
		(int) $input['post_id'],
		(string) $input['image_src']
	);
}
