<?php
/**
 * Signal & Noise Tools — WordPress/desktop-mode integration.
 *
 * Makes the SN plugin a first-class participant in desktop-mode (when
 * installed + active). Adds:
 *
 *   1. A dock icon "Signal & Noise" with a submenu of all 8 SN settings
 *      tabs (via the desktop_mode_dock_items filter).
 *   2. Two desktop icons (Dashboard + Identity — the most-frequent
 *      surfaces) via desktop_mode_register_icon().
 *   3. Thirteen Cmd+K command-palette commands via
 *      desktop_mode_register_command() — 4 maintenance actions (REST-
 *      backed AJAX), 7 navigation shortcuts, 2 info commands.
 *   4. One desktop widget "SN Deploy Status" via
 *      desktop_mode_register_widget() — small floating card showing
 *      theme + plugin version + last deploy time.
 *
 * EVERY integration is gated on function_exists() — the plugin behaves
 * identically when desktop-mode is inactive or uninstalled.
 *
 * REST endpoints (under signal-noise/v1/cmd/) back the maintenance
 * commands so they fire without page navigation:
 *   POST /cmd/force-check     — clear update transients
 *   POST /cmd/purge-caches    — fire sn_purge_all_caches_result filter
 *   POST /cmd/clear-overrides — fire sn_clear_template_overrides_result
 *   POST /cmd/full-reset      — clear overrides + purge in one shot
 *   GET  /cmd/status          — read-only: version + last deploy (widget)
 *
 * All require manage_options. WP REST API handles _wpnonce verification
 * automatically when JS uses wp.apiFetch (which our scripts do via the
 * wp-api-fetch dependency).
 *
 * @package SignalNoiseTools
 * @since 1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the two JS files that desktop-mode loads when our commands +
 * widget activate. Registration (not enqueue) — desktop-mode enqueues
 * them on demand by handle.
 *
 * wp-api-fetch dep gives us wp.apiFetch() with auto-nonce handling for
 * the REST endpoints below.
 *
 * sn_desktop_data is localized so the JS can read current version +
 * latest tag without an extra REST call for the "Info" commands.
 */
add_action( 'admin_enqueue_scripts', function() {
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
		return;
	}

	wp_register_script(
		'sn-desktop-mode',
		plugins_url( 'assets/desktop-mode.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget',
		plugins_url( 'assets/desktop-mode-widget.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);

	// v2.1.0: two new widget scripts — Quick Actions + RSS Subscribers.
	wp_register_script(
		'sn-desktop-mode-widget-actions',
		plugins_url( 'assets/desktop-mode-widget-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);

	wp_register_script(
		'sn-desktop-mode-widget-rss',
		plugins_url( 'assets/desktop-mode-widget-rss.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch' ),
		SNT_VERSION,
		true
	);


	// Shared data — both scripts read from window.snDesktopData.
	$theme  = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'theme' ) : array();
	$plugin = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();
	$shared = array(
		'restNamespace' => 'signal-noise/v1',
		'theme'         => $theme,
		'plugin'        => $plugin,
		'pages'         => array(
			'dashboard'    => admin_url( 'admin.php?page=sn-theme-options' ),
			'identity'     => admin_url( 'admin.php?page=sn-identity' ),
			'login'        => admin_url( 'admin.php?page=sn-login' ),
			'cloudflare'   => admin_url( 'admin.php?page=sn-cloudflare' ),
			'plausible'    => admin_url( 'admin.php?page=sn-plausible' ),
			'rss'          => admin_url( 'admin.php?page=sn-rss' ),
			'reading_time' => admin_url( 'admin.php?page=sn-reading-time' ),
		),
	);
	wp_localize_script( 'sn-desktop-mode', 'snDesktopData', $shared );
	wp_localize_script( 'sn-desktop-mode-widget', 'snDesktopData', $shared );
} );

/**
 * Dock item — single entry "Signal & Noise" with submenu of all 8 tabs.
 *
 * Filter shape per desktop-mode docs/getting-started.md:
 *   slug, title, icon (dashicons-*), url, badge?, submenu? (array of items
 *   with the same shape, recursively)
 */
/**
 * Suppress desktop-mode's automatic dock import of our menu page.
 *
 * Per WordPress/desktop-mode core/payload.php, every entry registered via
 * add_menu_page() / add_submenu_page() is auto-imported as a dock item
 * by default. Our admin-page.php registers "Signal & Noise" as a top-
 * level menu, so without this filter we end up with TWO dock entries:
 *
 *   1. Auto-imported "Signal & Noise" from add_menu_page (generic icon
 *      because desktop-mode falls back when the menu doesn't specify a
 *      dashicon explicitly — looks like a megaphone glyph on small
 *      screens, which is what surfaced the bug).
 *   2. Our explicit "Signal & Noise" with shield icon registered in the
 *      desktop_mode_dock_items filter below (richer: 8-tab submenu +
 *      update-available badge).
 *
 * Returning 'hidden' for the SN menu slug suppresses the auto-import.
 * Our explicit entry remains. Single dock item, shield icon, full
 * submenu.
 *
 * Verified against WordPress/desktop-mode includes/core/payload.php:
 *   apply_filters( 'desktop_mode_dock_placement', 'dock', $menu_slug );
 *
 * Added in v2.0.1 (post-v1.15.0 desktop-mode bug fix).
 */
add_filter( 'desktop_mode_dock_placement', function( $placement, $menu_slug ) {
	if ( 'sn-theme-options' === $menu_slug ) {
		return 'hidden';
	}
	return $placement;
}, 10, 2 );

add_filter( 'desktop_mode_dock_items', function( $items ) {
	if ( ! is_array( $items ) ) {
		$items = array();
	}

	/**
	 * v2.1.0 dock fix:
	 *   - Key is 'id' not 'slug' (the desktop-mode docs/hooks-reference.md
	 *     says 'slug' but the actual code at includes/core/payload.php:163
	 *     uses 'id'. Verified against test fixture at tests/phpunit/tests/
	 *     desktopModeBuildDockItems.php:394 which uses 'id' => 'replaced').
	 *     Wrong key meant item.id was undefined in JS, crashing dock.ts:1711
	 *     with TypeError on every click of the SN tile — silent breakage
	 *     since v1.15.0, only surfaced post-Phase-13 when our auto-import
	 *     suppression removed the parallel working entry.
	 *   - Submenu entries only honor 'title' + 'url' per src/dock.ts:89
	 *     SubmenuItem type — 'icon' and 'slug' on submenu items are
	 *     silently dropped. Removed the noise.
	 *   - Icon is dashicons-megaphone (matches the icon passed to
	 *     add_menu_page() in admin-page.php:121, which is what was
	 *     rendering on the auto-imported entry before suppression).
	 *
	 * Click behavior (verified via src/dock.ts:911-913 + 1703-1765):
	 *   - Single click on parent tile → window opens to item.url
	 *   - Submenu rides into the opened window as an in-window tab strip
	 *     (the "submenu chevron" on the dock tile is documented future work)
	 */
	$items[] = array(
		'id'      => 'signal-noise',
		'title'   => 'Signal & Noise',
		'icon'    => 'dashicons-megaphone',
		'url'     => admin_url( 'admin.php?page=sn-theme-options' ),
		'badge'   => snt_desktop_dock_badge(),
		'submenu' => array(
			array( 'title' => 'Dashboard',    'url' => admin_url( 'admin.php?page=sn-theme-options' ) ),
			array( 'title' => 'Identity',     'url' => admin_url( 'admin.php?page=sn-identity' ) ),
			array( 'title' => 'Login',        'url' => admin_url( 'admin.php?page=sn-login' ) ),
			array( 'title' => 'Cloudflare',   'url' => admin_url( 'admin.php?page=sn-cloudflare' ) ),
			array( 'title' => 'Plausible',    'url' => admin_url( 'admin.php?page=sn-plausible' ) ),
			array( 'title' => 'RSS',          'url' => admin_url( 'admin.php?page=sn-rss' ) ),
			array( 'title' => 'Reading Time', 'url' => admin_url( 'admin.php?page=sn-reading-time' ) ),
			array( 'title' => 'Links',        'url' => admin_url( 'admin.php?page=sn-links' ) ),
		),
	);

	return $items;
} );

/**
 * Badge count for the dock — total "update available" count for theme +
 * plugin. 0 = no badge (desktop-mode convention).
 */
function snt_desktop_dock_badge() {
	$badge = 0;
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		if ( 'available' === ( snt_deploy_status_for( 'theme' )['state']  ?? '' ) ) { $badge++; }
		if ( 'available' === ( snt_deploy_status_for( 'plugin' )['state'] ?? '' ) ) { $badge++; }
	}
	return $badge;
}

/**
 * Desktop icons — Dashboard + Identity (the two most-frequent surfaces).
 */
add_action( 'init', function() {
	if ( ! function_exists( 'desktop_mode_register_icon' ) ) {
		return;
	}

	desktop_mode_register_icon( 'sn-icon-dashboard', array(
		'title' => 'SN Dashboard',
		'icon'  => 'dashicons-shield-alt',
		'url'   => admin_url( 'admin.php?page=sn-theme-options' ),
	) );

	desktop_mode_register_icon( 'sn-icon-identity', array(
		'title' => 'SN Identity',
		'icon'  => 'dashicons-id',
		'url'   => admin_url( 'admin.php?page=sn-identity' ),
	) );
} );

/**
 * Command-palette commands — 13 total.
 *
 * Each command's run callback lives in assets/desktop-mode.js. The PHP
 * registration here gives desktop-mode the metadata (slug, label, icon)
 * + the script handle that contains the JS callback.
 */
add_action( 'admin_enqueue_scripts', function() {
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
		return;
	}

	$commands = array(
		// Maintenance (REST → toast).
		array( 'slug' => 'sn-cmd-force-check',     'label' => 'SN: Force-check updates',       'description' => 'Clear all GitHub + WordPress update transients.',           'icon' => 'dashicons-update' ),
		array( 'slug' => 'sn-cmd-purge-caches',    'label' => 'SN: Purge all caches',          'description' => 'Object cache + Breeze + Varnish + Cloudflare.',           'icon' => 'dashicons-trash' ),
		array( 'slug' => 'sn-cmd-clear-overrides', 'label' => 'SN: Clear template overrides',  'description' => 'Remove wp_template / wp_template_part / wp_navigation DB rows.', 'icon' => 'dashicons-editor-removeformatting' ),
		array( 'slug' => 'sn-cmd-full-reset',      'label' => 'SN: Full reset',                'description' => 'Clear overrides AND purge every cache.',                  'icon' => 'dashicons-controls-repeat' ),

		// Navigation (window.location).
		array( 'slug' => 'sn-cmd-nav-dashboard',    'label' => 'SN: Open Dashboard',    'description' => 'Site state, recent deploys, maintenance actions.', 'icon' => 'dashicons-dashboard' ),
		array( 'slug' => 'sn-cmd-nav-identity',     'label' => 'SN: Open Identity',     'description' => 'Site name, social profiles, OG cards, SEO copy.',  'icon' => 'dashicons-id' ),
		array( 'slug' => 'sn-cmd-nav-login',        'label' => 'SN: Open Login',        'description' => 'Custom login URL + emergency unlock.',             'icon' => 'dashicons-lock' ),
		array( 'slug' => 'sn-cmd-nav-cloudflare',   'label' => 'SN: Open Cloudflare',   'description' => 'CF API token + zone + auto-purge config.',         'icon' => 'dashicons-cloud' ),
		array( 'slug' => 'sn-cmd-nav-plausible',    'label' => 'SN: Open Plausible',    'description' => 'Stats API key for dashboard widgets.',             'icon' => 'dashicons-chart-line' ),
		array( 'slug' => 'sn-cmd-nav-rss',          'label' => 'SN: Open RSS',          'description' => 'Subscriber tracking + recent feed requests.',      'icon' => 'dashicons-rss' ),
		array( 'slug' => 'sn-cmd-nav-reading-time', 'label' => 'SN: Open Reading Time', 'description' => 'Legacy reading-time-string cleanup tool.',         'icon' => 'dashicons-clock' ),

		// Info (read from localized data → toast).
		array( 'slug' => 'sn-cmd-version-theme',  'label' => 'SN: Theme version',  'description' => 'Show current theme version + GitHub-latest comparison.',  'icon' => 'dashicons-admin-appearance' ),
		array( 'slug' => 'sn-cmd-version-plugin', 'label' => 'SN: Plugin version', 'description' => 'Show current plugin version + GitHub-latest comparison.', 'icon' => 'dashicons-admin-plugins' ),
	);

	foreach ( $commands as $cmd ) {
		desktop_mode_register_command( array(
			'slug'        => $cmd['slug'],
			'label'       => $cmd['label'],
			'description' => $cmd['description'],
			'icon'        => $cmd['icon'],
			'script'      => 'sn-desktop-mode',
		) );
	}
} );

/**
 * Desktop widget — SN Deploy Status.
 */
add_action( 'admin_enqueue_scripts', function() {
	if ( ! function_exists( 'desktop_mode_register_widget' ) ) {
		return;
	}
	desktop_mode_register_widget( 'sn-deploy-status', array(
		'label'  => 'SN Deploy Status',
		'script' => 'sn-desktop-mode-widget',
		'sort'   => 50,
	) );

	// v2.1.0: Quick Actions widget — replaces the 3-click path of
	// S&N → Dashboard → Maintenance with single-click access from desktop.
	desktop_mode_register_widget( 'sn-quick-actions', array(
		'label'  => 'SN Quick Actions',
		'script' => 'sn-desktop-mode-widget-actions',
		'sort'   => 55,
	) );

	// v2.1.0: RSS Subscribers widget — surfaces RSS feed activity that
	// was previously buried under S&N → RSS tab + a single line on the
	// SN Dashboard tab. At-a-glance subscriber growth on the desktop.
	desktop_mode_register_widget( 'sn-rss-subscribers', array(
		'label'  => 'SN RSS Subscribers',
		'script' => 'sn-desktop-mode-widget-rss',
		'sort'   => 60,
	) );
} );

/* ════════════════════════════════════════════════════════════════════════
 * REST ENDPOINTS — signal-noise/v1/cmd/*
 *
 * Single handler dispatches on the {action} URL param. Response shape:
 *   { ok: bool, message: string, data?: object }
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/cmd/(?P<action>[a-z-]+)', array(
		'methods'             => array( 'GET', 'POST' ),
		'callback'            => 'snt_desktop_cmd_handler',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'action' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		),
	) );
} );

function snt_desktop_cmd_handler( WP_REST_Request $request ) {
	$action = (string) $request->get_param( 'action' );

	switch ( $action ) {
		case 'force-check':
			delete_site_transient( 'sn_gh_latest_theme' );
			delete_site_transient( 'sn_gh_latest_plugin' );
			delete_site_transient( 'update_themes' );
			delete_site_transient( 'update_plugins' );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Update caches cleared. Next page-load fetches fresh data from GitHub.',
			) );

		case 'purge-caches':
			$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'All caches purged.',
				'data'    => array( 'count' => $count ),
			) );

		case 'clear-overrides':
			$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => sprintf( '%d database override%s cleared.', $count, 1 === $count ? '' : 's' ),
				'data'    => array( 'count' => $count ),
			) );

		case 'full-reset':
			$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array() );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => sprintf( 'Full reset: %d override%s cleared + all caches purged.', $count, 1 === $count ? '' : 's' ),
				'data'    => array( 'count' => $count ),
			) );

		case 'rss-stats':
			// v2.1.0: read-only RSS feed activity for the desktop widget.
			// Reuses sn_rss_tracker_window_stats_multi() that powers the
			// existing /sn-rss tab + Dashboard tab RSS summary.
			if ( ! function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
				return new WP_Error(
					'snt_rss_unavailable',
					'RSS tracker module not loaded.',
					array( 'status' => 503 )
				);
			}
			$stats        = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
			$last_rel     = '';
			if ( ! empty( $stats['most_recent'] ) ) {
				$t = strtotime( $stats['most_recent'] );
				if ( $t ) {
					$last_rel = human_time_diff( $t, time() ) . ' ago';
				}
			}
			return rest_ensure_response( array(
				'ok'   => true,
				'data' => array(
					'last_request'          => $stats['most_recent'] ?? null,
					'last_request_relative' => $last_rel,
					'windows'               => $stats['windows'] ?? array(),
				),
			) );

		case 'status':
			$theme  = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'theme' )  : array();
			$plugin = function_exists( 'snt_deploy_status_for' ) ? snt_deploy_status_for( 'plugin' ) : array();
			$runs   = function_exists( 'snt_gh_recent_runs_merged' )
				? snt_gh_recent_runs_merged( array( 'juanlentino/signal-and-noise', 'juanlentino/signal-and-noise-tools' ), 1 )
				: array();
			$last_deploy = '';
			if ( ! empty( $runs[0]['created_at'] ) ) {
				$t = strtotime( $runs[0]['created_at'] );
				if ( $t ) {
					$last_deploy = human_time_diff( $t, time() ) . ' ago';
				}
			}
			return rest_ensure_response( array(
				'ok'   => true,
				'data' => array(
					'theme'       => $theme,
					'plugin'      => $plugin,
					'last_deploy' => $last_deploy,
				),
			) );

		default:
			return new WP_Error(
				'snt_unknown_command',
				sprintf( 'Unknown command action: %s', esc_html( $action ) ),
				array( 'status' => 400 )
			);
	}
}

/* ════════════════════════════════════════════════════════════════════════
 * v2.1.3 — Desktop Mode Plugins-window fixes
 *
 * The v2.1.2 brand-assets work (icons + banners via plugins_api + the
 * update_plugins transient) only covers WP core surfaces. Desktop Mode
 * ships its own custom Plugins window (a REST-fed TypeScript frontend
 * under includes/plugins-window/* + src/plugins-window/*) that does NOT
 * consult either of those data sources. Two surgical filters land the
 * icon + decode the plugin name for that surface only.
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Provide our plugin's icon to Desktop Mode's custom Plugins window.
 *
 * Desktop Mode derives the icon URL by hardcoding
 *   https://ps.w.org/{dirname(plugin_file)}/assets/icon.svg
 * at includes/plugins-window/rest-fields.php:404-445 — works for the
 * wordpress.org plugin directory, 404s for self-hosted plugins like ours.
 * The update_plugins site_transient icons array (which we populate in
 * inc/wp-update-integration.php) is never read by this code path.
 *
 * The 'desktop_mode_plugins_window_icon_url' filter is exposed at the
 * same line; we return our own SVG so the icon column on Desktop Mode's
 * Plugins panel renders correctly. SVG renders crisp at any DPR — no
 * separate PNG fallback needed.
 *
 * Note on the JS fallback chain: src/plugins-window/icon-fallback.ts
 * only walks SVG → 256.png → 128.png when the URL matches the
 * ps.w.org/<slug>/assets/icon.svg shape. Custom URLs get one shot and
 * then resolve to the dashicons-admin-plugins placeholder. Our SVG is
 * served from the same WP origin as the admin UI, so CSP + mixed-content
 * checks pass and the single shot is enough.
 *
 * Verified against WordPress/desktop-mode
 * (includes/plugins-window/rest-fields.php trunk @ 2026-05-18).
 */
add_filter( 'desktop_mode_plugins_window_icon_url', function( $url, $slug ) {
	if ( defined( 'SN_GH_PLUGIN_SLUG' ) && SN_GH_PLUGIN_SLUG === $slug ) {
		return plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	}
	return $url;
}, 10, 2 );

/**
 * Decode HTML entities in our plugin's Name on the REST response.
 *
 * Desktop Mode's installed Plugins view calls Core's REST endpoint
 *   GET /wp/v2/plugins?context=view
 * which runs WP_REST_Plugins_Controller::prepare_item_for_response()
 * (wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 * lines 578-620). That method calls _get_plugin_data_markup_translate()
 * which unconditionally `wp_kses`'s the Name header (plugin.php line 188)
 * even when called with $markup=false — so the JSON response always
 * carries the entity-encoded form `"name": "Signal &amp; Noise Tools"`.
 *
 * Desktop Mode's frontend then sets `title.textContent = row.name`
 * (src/plugins-window/installed-view.ts + installed-detail.ts), and
 * textContent renders entities literally. The Browse view at card.ts
 * decodes via decodeEntities() — Installed/Detail views forgot to.
 *
 * v2.1.3 attempted this fix via the `all_plugins` filter — wrong layer:
 * `all_plugins` only fires from wp-admin/plugins.php's UI layer, NOT
 * from the REST controller. The REST controller is the ONLY data path
 * Desktop Mode uses for the Installed view.
 *
 * Correct layer: `rest_prepare_plugin` at line 619 of the REST
 * controller, the last writable layer before JSON serialization.
 * Scoped strictly to SN_GH_PLUGIN_BASENAME ($item['_file']) so other
 * plugins' Name strings are never touched.
 *
 * Verified against WordPress/WordPress @ tag 6.9.4:
 *   wp-includes/rest-api/endpoints/class-wp-rest-plugins-controller.php
 *   lines 578-620 + wp-admin/includes/plugin.php line 188.
 *
 * @since 2.1.6 (supersedes the all_plugins approach from v2.1.3)
 */
add_filter( 'rest_prepare_plugin', function( $response, $item, $request ) {
	if ( ! defined( 'SN_GH_PLUGIN_BASENAME' ) ) {
		return $response;
	}
	if ( ! is_array( $item ) || empty( $item['_file'] ) || SN_GH_PLUGIN_BASENAME !== $item['_file'] ) {
		return $response;
	}

	$data = $response->get_data();
	$dirty = false;

	// Decode the Name field — primary fix.
	if ( isset( $data['name'] ) && false !== strpos( $data['name'], '&amp;' ) ) {
		$data['name'] = html_entity_decode( $data['name'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Author field also runs through wp_kses in the same function.
	if ( isset( $data['author'] ) && false !== strpos( $data['author'], '&amp;' ) ) {
		$data['author'] = html_entity_decode( $data['author'], ENT_QUOTES, 'UTF-8' );
		$dirty = true;
	}

	// Icon URL — ALWAYS override, never just-when-empty (v2.1.7 fix).
	// Desktop Mode's get_callback may have already populated
	// desktop_mode_icon_url with the ps.w.org URL that 404s for self-
	// hosted plugins; in that case the field is non-empty but wrong, and
	// an `if ( empty(...) )` guard lets it pass through. Self-hosted
	// plugins know their own canonical icon URL — overwrite
	// unconditionally for our basename. Safe scope: gated on
	// $item['_file'] === SN_GH_PLUGIN_BASENAME at the top of this filter.
	$canonical_icon_url = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	if ( ! isset( $data['desktop_mode_icon_url'] ) || $data['desktop_mode_icon_url'] !== $canonical_icon_url ) {
		$data['desktop_mode_icon_url'] = $canonical_icon_url;
		$dirty = true;
	}

	if ( $dirty ) {
		$response->set_data( $data );
	}
	return $response;
}, 10, 3 );

/**
 * Inline DOM patch printed to admin_footer.
 *
 * Two upstream bugs in Desktop Mode trunk that have NO server-side filter:
 *
 *   1. "View on WordPress.org" button in the Installed-view detail panel
 *      ([installed-detail.ts:297-301]). Gated purely on `if (slug)` where
 *      slug = dirname(plugin_file) — non-empty for every installed plugin,
 *      so the button shows even for self-hosted plugins. Link 404s for us.
 *
 *   2. Belt + suspenders for the plugin Name. The rest_prepare_plugin
 *      filter above already decodes the Name on the wire, but if Desktop
 *      Mode ever caches a pre-fix response (service worker, in-memory),
 *      the literal `Signal &amp; Noise Tools` could resurface.
 *
 * Why admin_footer + inline instead of wp_enqueue_script:
 *   - wp_enqueue_script depends on Desktop Mode's script lifecycle. Their
 *     custom Plugins window may load JS in a different context than the
 *     standard admin enqueue chain (the v2.1.6 enqueue did not visibly
 *     fire — the button persisted post-install).
 *   - admin_footer prints into the raw <body>, so the script is in the
 *     DOM regardless of how Desktop Mode loads its frontend bundle.
 *   - Inline avoids the assets/ file altogether, dropping the 1 extra
 *     HTTP request + removing a moving part.
 *
 * The script is ~1.5KB minified, self-gates on `Signal &amp; Noise Tools`
 * presence + MutationObserver, and no-ops on any page where its target
 * nodes don't exist.
 *
 * @since 2.1.7 (supersedes the wp_enqueue_script approach from v2.1.6)
 */
add_action( 'admin_print_footer_scripts', function() {
	// Only fire when Desktop Mode is active — no point patching pages
	// it doesn't render.
	if ( ! function_exists( 'desktop_mode_register_command' ) ) {
		return;
	}
	?>
<script id="sn-desktop-mode-installed-view-patch">
(function(){
'use strict';
var SLUG = 'signal-and-noise-tools';
var LITERAL = 'Signal &amp; Noise Tools';
var DECODED = 'Signal & Noise Tools';
function patch(root){
if(!root||!root.querySelectorAll)return;
// Hide any wp.org link pointing at our self-hosted slug.
var links = root.querySelectorAll('a[href*="wordpress.org/plugins/'+SLUG+'"], a[href*="wordpress.org/support/plugin/'+SLUG+'"], a[href*="ps.w.org/'+SLUG+'"]');
for(var i=0;i<links.length;i++){
var el = links[i];
if(el.dataset.snHidden==='1')continue;
var host = el.closest('button, .wpd-button, [class*="action"], [class*="cta"], [class*="link"]') || el;
host.style.display='none';
el.dataset.snHidden='1';
}
// Defensive Name decode — only leaf nodes with the exact literal text.
var nodes = root.querySelectorAll('h1,h2,h3,h4,h5,h6,strong,span,div,td,a,p');
for(var j=0;j<nodes.length;j++){
var n = nodes[j];
if(n.children.length===0 && n.textContent===LITERAL){
n.textContent=DECODED;
}
}
}
function init(){
patch(document.body);
new MutationObserver(function(muts){
for(var i=0;i<muts.length;i++){
var added = muts[i].addedNodes;
for(var j=0;j<added.length;j++){
if(added[j].nodeType===1)patch(added[j]);
}
}
}).observe(document.body,{childList:true,subtree:true});
}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init,{once:true});}
else{init();}
})();
</script>
	<?php
}, 99 );
