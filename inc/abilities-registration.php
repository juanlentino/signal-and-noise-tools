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
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'include_template_overrides' => array(
					'type'        => 'boolean',
					'description' => 'Also clear wp_template/wp_template_part/wp_navigation DB rows. Default false — overrides are typically intentional Site Editor changes.',
					'default'     => false,
				),
			),
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
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID whose OG card should be regenerated.',
					'minimum'     => 1,
				),
			),
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
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
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
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
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
		'execute_callback'    => function() {
			if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'Force-check helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_cmd_impl_force_check();
		},
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(),
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
		'execute_callback'    => function() {
			if ( ! function_exists( 'snt_cmd_impl_full_reset' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'Full-reset helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_cmd_impl_full_reset();
		},
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
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
		'execute_callback'    => function() {
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
		},
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
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
		'description'         => 'Returns the most recent RSS feed request timestamp + 24h / 7d / 30d totals + unique visitor counts. Backed by the sn_rss_tracker module.',
		'category'            => 'diagnostics',
		'permission_callback' => $permission_manage_options,
		'execute_callback'    => function() {
			if ( ! function_exists( 'snt_cmd_impl_rss_stats' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'RSS-stats helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_cmd_impl_rss_stats();
		},
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment.
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'data' => array( 'type' => 'object' ),
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
		'execute_callback'    => function( $input ) {
			if ( ! function_exists( 'snt_ai_meta_desc_impl' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'Meta-desc helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_ai_meta_desc_impl( (int) $input['post_id'] );
		},
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
				),
			),
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
		'execute_callback'    => function( $input ) {
			if ( ! function_exists( 'snt_ai_og_card_title_impl' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'OG-title helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_ai_og_card_title_impl( (int) $input['post_id'] );
		},
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID.',
					'minimum'     => 1,
				),
			),
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
		'execute_callback'    => function( $input ) {
			if ( ! function_exists( 'snt_ai_excerpt_impl' ) ) {
				return new WP_Error( 'snt_helper_unavailable', 'Excerpt helper unavailable.', array( 'status' => 500 ) );
			}
			return snt_ai_excerpt_impl( (int) $input['post_id'] );
		},
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'The WordPress post ID to summarize.',
					'minimum'     => 1,
				),
			),
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
		'description'         => 'Returns all scheduled WP-Cron events with next-run, recurrence, last-fired, args, has_handler flag, and is_sn_owned flag.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => function( $input ) {
			if ( ! function_exists( 'snt_cron_get_events_impl' ) ) {
				return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
			}
			$sn_only = is_array( $input ) && ! empty( $input['sn_only'] );
			return snt_cron_get_events_impl( $sn_only );
		},
		'input_schema'        => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'sn_only' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, filter to the 3 SN-owned hooks only.',
				),
			),
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
		'description'         => 'Returns details for a single scheduled cron event identified by hook + args_signature. Returns null if no match.',
		'category'            => 'diagnostics',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'execute_callback'    => function( $input ) {
			if ( ! function_exists( 'snt_cron_get_event_impl' ) ) {
				return new WP_Error( 'snt_cron_unavailable', 'Cron dashboard module not loaded.', array( 'status' => 500 ) );
			}
			return snt_cron_get_event_impl(
				(string) $input['hook'],
				(string) $input['args_signature']
			);
		},
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'hook', 'args_signature' ),
			'properties' => array(
				'hook'           => array(
					'type'        => 'string',
					'description' => 'The cron hook name.',
					'minLength'   => 1,
				),
				'args_signature' => array(
					'type'        => 'string',
					'description' => 'The md5 args signature from list-cron-events.',
					'minLength'   => 1,
				),
			),
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
			'type'       => 'object',
			'required'   => array( 'hook' ),
			'properties' => array(
				'hook'  => array(
					'type'        => 'string',
					'description' => 'The cron hook name to look up history for.',
					'minLength'   => 1,
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum rows to return (1–100, default 10, newest first).',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
			),
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
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, bypass the 7-day cache and run a fresh AI call.',
				),
			),
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
			'type'       => array( 'object', 'null' ),
			'properties' => array(),
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
			'type'       => 'object',
			'required'   => array( 'hook' ),
			'properties' => array(
				'hook' => array(
					'type'        => 'string',
					'description' => 'The cron hook name to unschedule.',
					'minLength'   => 1,
				),
				'args' => array(
					'type'        => 'array',
					'description' => 'Optional args array — must match the scheduled signature exactly. Pass [] for events scheduled without args.',
					'default'     => array(),
				),
			),
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
} );

/* ════════════════════════════════════════════════════════════════════════
 * EXECUTE CALLBACKS
 *
 * Each delegates to the existing filter contracts that already power the
 * REST endpoints in inc/desktop-mode-integration.php. This is intentional:
 * abilities are a third dispatch surface (alongside admin UI + REST) for
 * the same underlying operations. One implementation, three callers.
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
