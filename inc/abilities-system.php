<?php
/**
 * Signal & Noise Tools — Abilities API: system maintenance + deploy state.
 *
 * Eight abilities covering cache/template/update housekeeping plus the
 * deploy-status + release-notes surfaces:
 *   - signal-noise/purge-all-caches         (destructive, idempotent; its
 *     include_template_overrides flag is the full-reset replacement)
 *   - signal-noise/clear-template-overrides (destructive, idempotent)
 *   - signal-noise/list-template-overrides  (readonly, idempotent)
 *   - signal-noise/full-reset                (DEPRECATED 7.7.0 → purge-all-caches
 *     with include_template_overrides=true; removal v8.0.0)
 *   - signal-noise/force-check-updates       (DEPRECATED 7.7.0 → get-deploy-status
 *     with force_refresh=true; removal v8.0.0)
 *   - signal-noise/get-deploy-status         (readonly, idempotent; force_refresh
 *     clears the update transients first — subsumes force-check-updates)
 *   - signal-noise/list-abilities            (DEPRECATED 7.7.0 → core catalogue
 *     GET /wp-abilities/v1/abilities; removal v8.0.0)
 *   - signal-noise/draft-release-notes       (readonly, NOT idempotent — AI draft;
 *     recategorized diagnostics → ai-generation in 7.7.0)
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
		'description'         => 'Returns current theme version, current plugin version, latest available versions from GitHub, and whether updates are available. Pass force_refresh=true to clear the GitHub-tag + update_themes/update_plugins transients first so the answer is freshly fetched (replaces the deprecated force-check-updates ability; clears caches only, never user data). Read-only; safe to call anytime.',
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
					'description' => 'Relative time of the most recent deploy GHA run across both repos (e.g. "3 hours ago"); empty string if unknown. Added v6.55.0.',
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
		'description'         => 'DEPRECATED since 7.7.0 — use signal-noise/get-deploy-status with force_refresh=true instead (clears the same transients, then returns the fresh status in one call). Clears the sn_gh_latest_* + update_themes + update_plugins site transients so the next admin page-load refetches fresh data from GitHub. No user data deleted.',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'signal-noise/get-deploy-status with force_refresh=true',
			),
			'annotations'  => array(
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/full-reset', array(
		'label'               => 'Full reset (clear overrides + purge all caches)',
		'description'         => 'DEPRECATED since 7.7.0 — use signal-noise/purge-all-caches with include_template_overrides=true instead (identical behavior: clears the template overrides AND purges every cache). Clears wp_template / wp_template_part / wp_navigation DB overrides AND purges every cache (object cache, Breeze, Varnish, Cloudflare).',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'signal-noise/purge-all-caches with include_template_overrides=true',
			),
			'annotations'  => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/list-abilities', array(
		'label'               => 'List registered abilities',
		'description'         => 'DEPRECATED since 7.7.0 — use the WordPress core catalogue endpoint GET /wp-abilities/v1/abilities instead (same data plus schemas, with namespace/category filters and pagination). Returns the catalogue of every ability registered on this site (name, label, description, category, namespace, annotations). Optionally filter by namespace.',
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
			'deprecated'   => array(
				'since' => '7.7.0',
				'use'   => 'the core catalogue endpoint GET /wp-abilities/v1/abilities',
			),
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	// v4.11.0 (T4): AI release-notes drafter. readonly (writes nothing) but
	// NOT idempotent - a generative call returns different prose each time, so
	// do not copy `idempotent => true` from the neighbors above.
	wp_register_ability( 'signal-noise/draft-release-notes', array(
		'label'               => 'Draft release notes from a change log',
		'description'         => 'Turns a pasted CHANGELOG delta (or a few bullets of what changed) into Mimestream-style, human-readable release notes (New / Improvements / Fixed sections) via the WP AI Client. Returns markdown; writes nothing. One on-demand AI call; input is hard-capped at ~4000 chars.',
		// v7.7.0: recategorized diagnostics → ai-generation. It is a generative
		// AI call, and agents discovering tools by category should find it with
		// its AI siblings, not among the read-only diagnostics.
		'category'            => 'ai-generation',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_draft_release_notes',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'changelog_delta' ),
			'properties'           => array(
				'changelog_delta' => array(
					'type'        => 'string',
					'description' => 'Raw change log delta / notes describing what changed in this version.',
					'minLength'   => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'markdown' => array( 'type' => 'string', 'description' => 'The drafted release notes in GitHub-flavored markdown.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => false,
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
	// $input is null when the run-path is called with no ?input= (the schema
	// permits 'null'); guard before indexing so PHP 8 does not warn on null.
	$include_overrides = is_array( $input ) && ! empty( $input['include_template_overrides'] );

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
function snt_ability_get_deploy_status( $input = null ) {
	if ( ! function_exists( 'snt_deploy_status_for' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Deploy status helper unavailable.', array( 'status' => 500 ) );
	}

	// v7.7.0: force_refresh clears the GitHub-tag + WP update transients first
	// (the deprecated force-check-updates ability's whole job), so one call
	// both busts the caches and returns the freshly-fetched status.
	if ( is_array( $input ) && ! empty( $input['force_refresh'] ) && function_exists( 'snt_cmd_impl_force_check' ) ) {
		snt_cmd_impl_force_check();
	}

	// v6.55.0: fold in last_deploy (relative time of the most recent merged GHA
	// run across both repos) so the desktop-mode deploy-status widget keeps its
	// "Last deploy: … ago" line after migrating off the legacy /cmd/status route.
	// snt_gh_recent_runs_merged is cache-backed (the widget's 60s cadence matches
	// its TTL), so this stays cheap. Empty string when the runs helper is
	// unavailable or has no data — same fallback the legacy handler used.
	$last_deploy = '';
	if ( function_exists( 'snt_gh_recent_runs_merged' ) ) {
		$runs = snt_gh_recent_runs_merged( array( 'juanlentino/signal-and-noise', 'juanlentino/signal-and-noise-tools' ), 1 );
		if ( ! empty( $runs[0]['created_at'] ) ) {
			$t = strtotime( $runs[0]['created_at'] );
			if ( $t ) {
				$last_deploy = human_time_diff( $t, time() ) . ' ago';
			}
		}
	}

	return array(
		'theme'       => snt_deploy_status_for( 'theme' ),
		'plugin'      => snt_deploy_status_for( 'plugin' ),
		'last_deploy' => $last_deploy,
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
 * @deprecated 7.7.0 Use signal-noise/get-deploy-status with force_refresh=true.
 */
function snt_ability_force_check_updates() {
	snt_ability_deprecated_notice( 'signal-noise/force-check-updates', 'signal-noise/get-deploy-status with force_refresh=true' );
	if ( ! function_exists( 'snt_cmd_impl_force_check' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Force-check helper unavailable.', array( 'status' => 500 ) );
	}
	return snt_cmd_impl_force_check();
}

/**
 * Ability execute callback: signal-noise/full-reset.
 * Thin wrapper around snt_cmd_impl_full_reset().
 * @since 3.7.5
 * @deprecated 7.7.0 Use signal-noise/purge-all-caches with include_template_overrides=true.
 */
function snt_ability_full_reset() {
	snt_ability_deprecated_notice( 'signal-noise/full-reset', 'signal-noise/purge-all-caches with include_template_overrides=true' );
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
 * @deprecated 7.7.0 Use the core catalogue endpoint GET /wp-abilities/v1/abilities.
 * @param array|null $input Optional. { namespace?: string }.
 * @return array|WP_Error
 */
function snt_ability_list_abilities( $input = null ) {
	snt_ability_deprecated_notice( 'signal-noise/list-abilities', 'the core catalogue endpoint GET /wp-abilities/v1/abilities' );
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

/**
 * Ability execute callback: signal-noise/draft-release-notes.
 * Thin wrapper around snt_release_notes_draft_impl(); wraps the markdown string
 * into the { ok, markdown } output shape (or passes a WP_Error through).
 * @since 4.11.0
 */
function snt_ability_draft_release_notes( $input ) {
	if ( ! function_exists( 'snt_release_notes_draft_impl' ) ) {
		return new WP_Error( 'snt_helper_unavailable', 'Release-notes drafter helper unavailable.', array( 'status' => 500 ) );
	}
	$delta  = is_array( $input ) && isset( $input['changelog_delta'] ) ? (string) $input['changelog_delta'] : '';
	$result = snt_release_notes_draft_impl( $delta );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'ok'       => true,
		'markdown' => (string) $result,
	);
}
