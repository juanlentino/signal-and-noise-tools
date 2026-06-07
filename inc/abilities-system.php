<?php
/**
 * Signal & Noise Tools — Abilities API: system maintenance + deploy state.
 *
 * Seven abilities covering cache/template/update housekeeping plus the
 * deploy-status + ability-catalogue diagnostics:
 *   - signal-noise/purge-all-caches         (destructive, idempotent)
 *   - signal-noise/clear-template-overrides (destructive, idempotent)
 *   - signal-noise/list-template-overrides  (readonly, idempotent)
 *   - signal-noise/full-reset                (destructive, idempotent — overrides + caches)
 *   - signal-noise/force-check-updates       (idempotent — clears update transients)
 *   - signal-noise/get-deploy-status         (readonly, idempotent)
 *   - signal-noise/list-abilities            (readonly, idempotent — registry catalogue)
 *
 * Categories: maintenance / diagnostics / updates. File grouping is by
 * feature (system housekeeping) rather than category so related impls
 * stay co-located.
 *
 * Extracted from inc/abilities-registration.php by the v4.1.3 split (B-11).
 *
 * @package SignalNoiseTools
 * @since 4.1.3 (registrations from 2.0.4 + 2.5.0 + 3.7.5)
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
		'description'         => 'Clears WordPress object cache, transients, Breeze page cache, Varnish, and Cloudflare edge cache. Use after deploys or when content appears stale.',
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

	wp_register_ability( 'signal-noise/get-deploy-status', array(
		'label'               => 'Get theme + plugin deploy status',
		'description'         => 'Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available. Read-only; safe to call anytime.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
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
			'annotations' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/force-check-updates', array(
		'label'               => 'Force-check theme + plugin updates',
		'description'         => 'Clears the sn_gh_latest_* + update_themes + update_plugins site transients so the next admin page-load refetches fresh data from GitHub. No user data deleted.',
		'category'            => 'updates',
		'permission_callback' => 'snt_ability_perm_manage_options',
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
		'permission_callback' => 'snt_ability_perm_manage_options',
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

	wp_register_ability( 'signal-noise/list-abilities', array(
		'label'               => 'List registered abilities',
		'description'         => 'Returns the catalogue of every ability registered on this site (name, label, description, category, namespace, annotations). Optionally filter by namespace. Read-only self-discovery for AI callers — useful for "what can you do here?".',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_list_abilities',
		'input_schema'        => array(
			// v2.5.4: see purge-all-caches comment — null accepted because
			// readonly abilities (GET) receive null when caller omits ?input=.
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'namespace' => array(
					'type'        => 'string',
					'description' => 'Restrict the catalogue to abilities whose name is prefixed with this namespace (the segment before the slash, e.g. "signal-noise").',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'         => array( 'type' => 'boolean' ),
				'count'      => array( 'type' => 'integer' ),
				'namespaces' => array(
					'type'        => 'object',
					'description' => 'Per-namespace ability tally.',
				),
				'items'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'category'    => array( 'type' => 'string' ),
							'namespace'   => array( 'type' => 'string' ),
							'annotations' => array( 'type' => 'object' ),
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

	wp_register_ability( 'signal-noise/list-template-overrides', array(
		'label'               => 'List database template overrides',
		'description'         => 'Returns the slugs and post types of any wp_template / wp_template_part / wp_navigation rows currently overriding theme files. Read-only inspection before the destructive clear-template-overrides.',
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
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );

/**
 * Execute callback for signal-noise/purge-all-caches.
 */
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

/**
 * Execute callback for signal-noise/get-deploy-status.
 */
function snt_ability_get_deploy_status() {
	if ( ! function_exists( 'snt_deploy_status_for' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Deploy status helper unavailable.', array( 'status' => 500 ) );
	}

	return array(
		'theme'  => snt_deploy_status_for( 'theme' ),
		'plugin' => snt_deploy_status_for( 'plugin' ),
	);
}

/**
 * Execute callback for signal-noise/clear-template-overrides.
 */
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
 * Ability execute callback: signal-noise/list-abilities.
 *
 * Iterates the GLOBAL abilities registry (every plugin/theme, not just S&N)
 * and returns a self-discovery catalogue. Namespace is derived from the
 * segment before the first slash in each ability name; annotation flags come
 * straight from the ability's get_meta()['annotations'].
 *
 * @since 4.10.0
 * @param array|null $input Optional. { namespace?: string }.
 * @return array|WP_Error
 */
function snt_ability_list_abilities( $input = null ) {
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		return new WP_Error( 'snt_abilities_unavailable', 'Abilities API not available (WordPress 7.0+ required).', array( 'status' => 500 ) );
	}

	$filter_namespace = '';
	if ( is_array( $input ) && isset( $input['namespace'] ) ) {
		$filter_namespace = (string) $input['namespace'];
	}

	$items      = array();
	$namespaces = array();

	foreach ( wp_get_abilities() as $ability ) {
		$name      = $ability->get_name();
		$slash     = strpos( $name, '/' );
		$namespace = false === $slash ? '' : substr( $name, 0, $slash );

		if ( '' !== $filter_namespace && $namespace !== $filter_namespace ) {
			continue;
		}

		$meta        = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

		$items[] = array(
			'name'        => $name,
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'category'    => $ability->get_category(),
			'namespace'   => $namespace,
			'annotations' => array(
				'readonly'    => isset( $annotations['readonly'] ) ? $annotations['readonly'] : null,
				'destructive' => isset( $annotations['destructive'] ) ? $annotations['destructive'] : null,
				'idempotent'  => isset( $annotations['idempotent'] ) ? $annotations['idempotent'] : null,
			),
		);

		$namespaces[ $namespace ] = ( $namespaces[ $namespace ] ?? 0 ) + 1;
	}

	return array(
		'ok'         => true,
		'count'      => count( $items ),
		'namespaces' => $namespaces,
		'items'      => $items,
	);
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
