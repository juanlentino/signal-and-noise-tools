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
			'type'       => 'object',
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
			'type'       => 'object',
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
			'type'       => 'object',
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
			'annotations' => array(
				'destructive' => true,
				'idempotent'  => true,
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
