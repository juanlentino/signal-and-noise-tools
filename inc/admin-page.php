<?php
/**
 * Signal & Noise — Theme options admin page.
 *
 * Registers the Appearance → Signal & Noise submenu and renders a tabbed
 * interface that covers theme management without overflowing into a
 * single-page-of-everything:
 *
 *   - Dashboard      — status overview + the four maintenance actions
 *                      (full reset, clear overrides, purge caches,
 *                      check for updates).
 *   - Cloudflare     — token + zone configuration, status, manual
 *                      zone purge, last-purge timestamp.
 *   - Reading Time   — legacy reading-time-string cleanup tool
 *                      (preview + apply).
 *   - Links          — external service links.
 *
 * Modules contribute their per-tab content via dedicated action hooks
 * (`sn_admin_cloudflare_tab`, `sn_admin_reading_time_tab`) so each
 * subsystem keeps its UI code colocated with its logic.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Signal & Noise — top-level menu (v1.8.1+).
 *
 * Lives at admin.php?page=sn-theme-options (was previously under
 * Appearance via add_theme_page; URL slug unchanged so all existing
 * ?tab=… deep links remain valid). The hook suffix returned by
 * add_menu_page() is cached so the stylesheet enqueue can guard on
 * it without re-deriving it from the slug.
 *
 * The auto-generated first submenu would otherwise duplicate the
 * parent label ("Signal & Noise / Signal & Noise"); add_submenu_page
 * with the same slug overrides the auto entry's label to "Dashboard".
 */
/**
 * The 8 SN admin pages, each rendered by sn_theme_options_page().
 *
 * Defined once at module scope so registration and dispatch read from
 * a single source of truth. Slug uniqueness is critical — WP's
 * add_submenu_page() has no duplicate detection (gotcha #16 in
 * docs/WORDPRESS-REFERENCE.md), so a typo here would silently produce
 * a phantom sidebar entry.
 *
 * Order in the array = display order in the WP sidebar.
 */
function sn_admin_pages() {
	// Note: the 'dashboard' slug ('sn-theme-options') intentionally matches
	// the parent menu slug to suppress WP's auto-prepended duplicate-parent
	// submenu entry (gotcha #14). Order matters: must be first submenu
	// registered.
	return array(
		array( 'slug' => 'sn-theme-options', 'tab' => 'dashboard',    'label' => 'Dashboard',     'title' => 'Signal & Noise — Dashboard',     'subtitle' => 'Status overview and maintenance actions for the theme + plugin pair.' ),
		array( 'slug' => 'sn-identity',      'tab' => 'identity',     'label' => 'Identity',      'title' => 'Signal & Noise — Identity',      'subtitle' => 'Site name, social profiles, Open Graph cards, and per-route SEO copy.' ),
		array( 'slug' => 'sn-login',         'tab' => 'login',        'label' => 'Login',         'title' => 'Signal & Noise — Login',         'subtitle' => 'Custom login URL and emergency unlock for the WordPress admin.' ),
		array( 'slug' => 'sn-cloudflare',    'tab' => 'cloudflare',   'label' => 'Cloudflare',    'title' => 'Signal & Noise — Cloudflare',    'subtitle' => 'API token and zone config for automatic edge-cache purges.' ),
		array( 'slug' => 'sn-plausible',     'tab' => 'plausible',    'label' => 'Plausible',     'title' => 'Signal & Noise — Plausible',     'subtitle' => 'Stats API token for the dashboard widgets.' ),
		array( 'slug' => 'sn-rss',           'tab' => 'rss',          'label' => 'RSS',           'title' => 'Signal & Noise — RSS',           'subtitle' => 'RSS subscriber tracking (delivered by the rss-plausible-tracker MU plugin).' ),
		array( 'slug' => 'sn-reading-time',  'tab' => 'reading-time', 'label' => 'Reading Time',  'title' => 'Signal & Noise — Reading Time',  'subtitle' => 'Legacy reading-time-string cleanup tool for posts written before the shortcode existed.' ),
		array( 'slug' => 'sn-cron',          'tab' => 'cron',         'label' => 'Cron',          'title' => 'Signal & Noise — Cron',          'subtitle' => 'Scheduled jobs — next run, recurrence, last fired, manual trigger.' ),
		array( 'slug' => 'sn-webhooks',      'tab' => 'webhooks',     'label' => 'Webhooks',      'title' => 'Signal & Noise — Webhooks',      'subtitle' => 'Personal automation — fire HMAC-signed POSTs to your own endpoints when posts publish.' ),
		array( 'slug' => 'sn-insights',      'tab' => 'insights',     'label' => 'Insights',      'title' => 'Signal & Noise — Insights',      'subtitle' => 'AI-synthesized recommendations from your analytics, publish history, and automation patterns.' ),
		array( 'slug' => 'sn-health',        'tab' => 'health',       'label' => 'Health',        'title' => 'Signal & Noise — Content Health','subtitle' => 'Detection scans — missing alt text, orphaned media, broken internal links, stale posts.' ),
		array( 'slug' => 'sn-links',         'tab' => 'links',        'label' => 'Links',         'title' => 'Signal & Noise — Links',         'subtitle' => 'External shortcuts — GitHub repos, release pages, Cloudflare, Cloudways.' ),
	);
}

/**
 * The 6 top-level tabs of the SN admin UI (v3.8.0+ IA).
 *
 * Each entry has a `sub_sections` array (may be empty for landing pages
 * with no in-page TOC). Sub-section ordering = display order in the
 * in-page TOC. Slugs are stable URL fragments (`?tab=<top>#sn-sec-<sub>`).
 *
 * @since 3.8.0
 * @return array<int,array<string,mixed>>
 */
function sn_admin_top_tabs() {
	return array(
		array(
			'slug'     => 'sn-theme-options',
			'tab'      => 'dashboard',
			'label'    => 'Dashboard',
			'title'    => 'Signal & Noise — Dashboard',
			'subtitle' => 'Status overview and maintenance actions.',
			'sub_tabs' => array(),  // landing page, no sub-tabs
		),
		array(
			'slug'     => 'sn-site',
			'tab'      => 'site',
			'label'    => 'Site',
			'title'    => 'Signal & Noise — Site',
			'subtitle' => 'Site identity, social profiles, Open Graph, SEO copy, Cloudflare.',
			'sub_tabs' => array(
				// Identity-and-SEO bundles 4 tightly-coupled form sections (one save button
				// saves all 4). Internal TOC navigates between them.
				'identity-and-seo' => array(
					'label'        => 'Identity & SEO',
					'sub_sections' => array(
						'identity'   => array( 'label' => 'Identity' ),
						'social'     => array( 'label' => 'Social' ),
						'open-graph' => array( 'label' => 'Open Graph' ),
						'seo-copy'   => array( 'label' => 'SEO Copy' ),
					),
				),
				// Cloudflare is its own sub-tab — independent form, its own save button via module hook.
				'cloudflare' => array(
					'label' => 'Cloudflare',
				),
			),
		),
		array(
			'slug'     => 'sn-security',
			'tab'      => 'security',
			'label'    => 'Security',
			'title'    => 'Signal & Noise — Security',
			'subtitle' => 'Custom login URL.',
			'sub_tabs' => array(
				'login'     => array( 'label' => 'Login URL' ),
				// v3.8.3: audit-log sub-tab. Adding the 2nd sub-tab automatically
				// reveals the sub-tab nav row (sn_admin_render_sub_tabs() hides at count<2).
				'audit-log' => array( 'label' => 'Audit log' ),
			),
		),
		array(
			'slug'     => 'sn-automation',
			'tab'      => 'automation',
			'label'    => 'Automation',
			'title'    => 'Signal & Noise — Automation',
			'subtitle' => 'Webhooks and scheduled jobs.',
			'sub_tabs' => array(
				'webhooks' => array( 'label' => 'Webhooks' ),
				'cron'     => array( 'label' => 'Cron' ),
			),
		),
		array(
			'slug'     => 'sn-monitoring',
			'tab'      => 'monitoring',
			'label'    => 'Monitoring',
			'title'    => 'Signal & Noise — Monitoring',
			'subtitle' => 'Insights, content health, analytics, RSS subscribers.',
			'sub_tabs' => array(
				'insights'  => array( 'label' => 'Insights' ),
				'health'    => array( 'label' => 'Health' ),
				'plausible' => array( 'label' => 'Plausible' ),
				'rss'       => array( 'label' => 'RSS' ),
			),
		),
		array(
			'slug'     => 'sn-tools',
			'tab'      => 'tools',
			'label'    => 'Tools',
			'title'    => 'Signal & Noise — Tools',
			'subtitle' => 'Utility surfaces and external shortcuts.',
			'sub_tabs' => array(
				'reading-time' => array( 'label' => 'Reading Time' ),
				'links'        => array( 'label' => 'Links' ),
			),
		),
	);
}

/**
 * Render the in-page TOC for a multi-section sub-tab (e.g., Identity & SEO
 * with its 4 inner sections: Identity / Social / Open Graph / SEO Copy).
 *
 * Reads sub-sections from sn_admin_top_tabs()'s nested
 * sub_tabs[<sub>]['sub_sections']. No-op if the sub-tab has no inner
 * sub_sections defined.
 *
 * Generates: <nav class="sn-toc" aria-label="..."><a href="#sn-sec-X">…</a></nav>
 *
 * v3.8.1 change: now scoped to a specific sub-tab (not the whole top tab),
 * since v3.8.0's flat top-level sub_sections moved into sub_tabs in v3.8.1.
 *
 * @since 3.8.0  (3.8.1 added $sub_tab_slug parameter for sub-tabs IA)
 * @param string $tab_slug      The top-tab slug (e.g., 'site').
 * @param string $sub_tab_slug  The sub-tab slug (e.g., 'identity-and-seo').
 */
function sn_admin_render_toc( $tab_slug, $sub_tab_slug ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] !== $tab_slug ) {
			continue;
		}
		$sub_tab = $top['sub_tabs'][ $sub_tab_slug ] ?? null;
		if ( ! is_array( $sub_tab ) || empty( $sub_tab['sub_sections'] ) ) {
			return;
		}
		echo '<nav class="sn-toc" aria-label="' . esc_attr( $sub_tab['label'] . ' sections' ) . '">';
		echo '<span class="sn-toc-label">Jump to</span>';
		foreach ( $sub_tab['sub_sections'] as $sub_slug => $sub ) {
			echo '<a href="#sn-sec-' . esc_attr( $sub_slug ) . '">' . esc_html( $sub['label'] ) . '</a>';
		}
		echo '</nav>';
		return;
	}
}

/**
 * Render the sub-tab nav for a top tab. Reads sub_tabs from
 * sn_admin_top_tabs() — single source of truth for both display order
 * and labels.
 *
 * Generates: <nav class="sn-sub-tabs"><a href="?tab=...&sub=...">…</a></nav>
 *
 * Hidden (returns without echoing) when:
 * - Top tab has 0 sub_tabs (Dashboard — landing page)
 * - Top tab has only 1 sub_tab (Security at v3.8.1 — single-item nav is noise)
 *
 * @since 3.8.1
 * @param string $tab_slug     The top-tab slug.
 * @param string $active_sub   The currently-active sub-tab slug (for is-active class).
 */
function sn_admin_render_sub_tabs( $tab_slug, $active_sub ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] !== $tab_slug ) {
			continue;
		}
		$sub_tabs = is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
		if ( count( $sub_tabs ) < 2 ) {
			// 0 sub_tabs (Dashboard) or 1 sub_tab (Security at v3.8.1) → no nav.
			return;
		}
		$base_url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $tab_slug ) );
		echo '<nav class="sn-sub-tabs" aria-label="' . esc_attr( $top['label'] . ' sub-tabs' ) . '">';
		foreach ( $sub_tabs as $sub_slug => $sub ) {
			$is_active = ( $sub_slug === $active_sub );
			$class     = 'sn-sub-tab' . ( $is_active ? ' is-active' : '' );
			$url       = $base_url . '&sub=' . rawurlencode( $sub_slug );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $sub['label'] ) . '</a>';
		}
		echo '</nav>';
		return;
	}
}

/**
 * Helper: get the configured sub_tabs array for a top tab.
 * Returns empty array if the tab has no sub_tabs.
 *
 * @since 3.8.1
 * @param string $tab_slug
 * @return array<string,array<string,mixed>>
 */
function sn_admin_get_sub_tabs( $tab_slug ) {
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( $top['tab'] === $tab_slug ) {
			return is_array( $top['sub_tabs'] ?? null ) ? $top['sub_tabs'] : array();
		}
	}
	return array();
}

/**
 * Helper: resolve the active sub-tab for a top tab from $_GET['sub'].
 * Falls back to the first configured sub-tab. Returns empty string if
 * the top tab has no sub_tabs (Dashboard).
 *
 * @since 3.8.1
 * @param string $tab_slug
 * @return string The active sub-tab slug (or '' if no sub_tabs configured).
 */
function sn_admin_resolve_active_sub( $tab_slug ) {
	$sub_tabs = sn_admin_get_sub_tabs( $tab_slug );
	if ( empty( $sub_tabs ) ) {
		return '';
	}
	$requested = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : '';
	if ( $requested && isset( $sub_tabs[ $requested ] ) ) {
		return $requested;
	}
	// Default: first sub-tab in display order.
	return (string) array_key_first( $sub_tabs );
}

/**
 * Render a sub-section wrapper with anchor target. The callback emits
 * the section's actual content (form fields, hook invocation, etc.).
 *
 * Wraps with .sn-fieldset (matching the existing Identity tab pattern)
 * so existing CSS at admin.css applies without changes. The anchor ID
 * is the structural commitment for the TOC links.
 *
 * For module-hook sub-sections (e.g., Cloudflare), the callback should
 * just `do_action('sn_admin_<slug>_tab')` — the hook listener will
 * emit its own heading + form inside this wrapper.
 *
 * @since 3.8.0
 * @param string   $section_slug Anchor target (e.g., 'identity', 'cloudflare').
 * @param callable $callback     Emits the section body.
 */
function sn_admin_render_section( $section_slug, $callback ) {
	echo '<div class="sn-fieldset" id="sn-sec-' . esc_attr( $section_slug ) . '">';
	call_user_func( $callback );
	echo '</div>';
}

/**
 * Map of legacy tab slugs (and equivalent page slugs) to their canonical
 * v3.8.0+ destinations: a top tab + anchor.
 *
 * Used by sn_admin_maybe_redirect_legacy() to 301 old URLs to canonical
 * `?tab=<top>#sn-sec-<sub>` destinations.
 *
 * The dashboard slug maps to itself (already canonical) — explicit entry
 * for completeness so the absence-vs-present check is uniform.
 *
 * @since 3.8.0
 * @return array<string,array{tab:string,anchor:?string}>
 */
function sn_admin_legacy_redirect_map() {
	// v3.8.1: each entry now includes a `sub` field naming the canonical sub-tab
	// (introduced by the sub-tabs IA). The `anchor` field stays — it's the inner
	// section anchor within the Identity & SEO sub-tab for the 4 form sections
	// (identity, social, open-graph, seo-copy). Other sub-tabs have anchor=null.
	return array(
		'dashboard'    => array( 'tab' => 'dashboard',  'sub' => null,                'anchor' => null ),
		'identity'     => array( 'tab' => 'site',       'sub' => 'identity-and-seo',  'anchor' => 'identity' ),
		'social'       => array( 'tab' => 'site',       'sub' => 'identity-and-seo',  'anchor' => 'social' ),       // v3.8.1: previously inner section, now redirectable
		'open-graph'   => array( 'tab' => 'site',       'sub' => 'identity-and-seo',  'anchor' => 'open-graph' ),   // v3.8.1
		'seo-copy'     => array( 'tab' => 'site',       'sub' => 'identity-and-seo',  'anchor' => 'seo-copy' ),     // v3.8.1
		'cloudflare'   => array( 'tab' => 'site',       'sub' => 'cloudflare',        'anchor' => null ),
		'login'        => array( 'tab' => 'security',   'sub' => 'login',             'anchor' => null ),
		'webhooks'     => array( 'tab' => 'automation', 'sub' => 'webhooks',          'anchor' => null ),
		'cron'         => array( 'tab' => 'automation', 'sub' => 'cron',              'anchor' => null ),
		'insights'     => array( 'tab' => 'monitoring', 'sub' => 'insights',          'anchor' => null ),
		'health'       => array( 'tab' => 'monitoring', 'sub' => 'health',            'anchor' => null ),
		'plausible'    => array( 'tab' => 'monitoring', 'sub' => 'plausible',         'anchor' => null ),
		'rss'          => array( 'tab' => 'monitoring', 'sub' => 'rss',               'anchor' => null ),
		'reading-time' => array( 'tab' => 'tools',      'sub' => 'reading-time',      'anchor' => null ),
		'links'        => array( 'tab' => 'tools',      'sub' => 'links',             'anchor' => null ),
	);
}

/**
 * If the current request has a legacy ?tab=<slug> OR is on a legacy
 * ?page=sn-<slug> URL whose tab is no longer top-level, 301-redirect
 * to the canonical destination + URL fragment.
 *
 * Called early in sn_theme_options_page() before any output. Uses raw
 * header() + exit because wp_safe_redirect() strips URL fragments — the
 * fragment is the part that scrolls the page to the right sub-section.
 *
 * Same-host admin URLs are trusted; the redirect destination is always
 * constructed from a fixed allow-listed top-tab whitelist, never from
 * user input.
 *
 * @since 3.8.0
 */
function sn_admin_maybe_redirect_legacy() {
	$top_tabs = array_column( sn_admin_top_tabs(), 'tab' );
	$map      = sn_admin_legacy_redirect_map();

	// Source 1: explicit ?tab=<slug>
	$requested_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

	// Source 2: derive from ?page=sn-<slug>
	if ( ! $requested_tab && isset( $_GET['page'] ) ) {
		$current_slug  = sanitize_text_field( wp_unslash( $_GET['page'] ) );
		// sn_admin_page_tab_for_slug() returns the tab name for a given
		// legacy page slug; v3.8.0+ also recognizes new top-tab slugs
		// (sn-site, sn-security, etc.) and returns their canonical tab.
		$requested_tab = sn_admin_page_tab_for_slug( $current_slug );
	}

	if ( ! $requested_tab ) {
		return;  // No tab in URL; default dispatcher will use 'dashboard'.
	}

	// If the requested tab is already a NEW top tab, nothing to redirect.
	if ( in_array( $requested_tab, $top_tabs, true ) ) {
		return;
	}

	// If it's a legacy slug, look up canonical destination.
	if ( ! isset( $map[ $requested_tab ] ) ) {
		return;  // Unknown slug; let the dispatcher fall through to dashboard.
	}

	$canonical = $map[ $requested_tab ];
	$url       = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $canonical['tab'] ) );
	// v3.8.1: include &sub= query arg for sub-tab routing
	if ( ! empty( $canonical['sub'] ) ) {
		$url .= '&sub=' . rawurlencode( $canonical['sub'] );
	}
	if ( ! empty( $canonical['anchor'] ) ) {
		$url .= '#sn-sec-' . rawurlencode( $canonical['anchor'] );
	}

	// Raw header() because wp_safe_redirect() strips the fragment.
	// Same-host admin URL, no user input in destination → safe.
	header( 'Location: ' . $url, true, 301 );
	exit;
}

/**
 * Look up the subtitle for the active tab. Used by the page header.
 *
 * v3.8.0+: reads from sn_admin_top_tabs() (the new 6-tab structure).
 * The legacy sn_admin_pages() still drives the WP submenu sidebar
 * (preserves all 12 deep-link shortcuts) but the page header reflects
 * the new top-tab IA the user is actually navigating.
 */
function sn_admin_page_subtitle_for_tab( $tab ) {
	foreach ( sn_admin_top_tabs() as $page ) {
		if ( $page['tab'] === $tab ) {
			return $page['subtitle'];
		}
	}
	return '';
}

/**
 * Single source of truth: every tab slug registered in sn_admin_pages().
 *
 * Derived (not duplicated) so adding a new tab is a one-line edit in
 * sn_admin_pages(). v3.0.0 shipped a regression where Task 10 added the
 * page entry + dispatch case but missed two inline whitelists 200 lines
 * away (CHANGELOG v3.0.2). Encoding this as a derived helper makes the
 * coordination constraint impossible to violate.
 *
 * @since 3.0.2
 */
function sn_admin_page_valid_tabs() {
	// v3.8.0+: derive from the 6 NEW top tabs. Legacy tab slugs are
	// handled by sn_admin_maybe_redirect_legacy() (301-redirected before
	// dispatch ever reaches the valid-tabs check).
	return array_column( sn_admin_top_tabs(), 'tab' );
}

/**
 * Single source of truth: tab → label map, keyed by tab slug.
 *
 * @since 3.0.2
 */
function sn_admin_page_tab_labels() {
	// v3.8.0+: derive from the 6 NEW top tabs (drives the in-page
	// .nav-tab-wrapper). The WP submenu sidebar still uses
	// sn_admin_pages() for its 12 entries.
	return array_column( sn_admin_top_tabs(), 'label', 'tab' );
}

/**
 * Map an admin-page slug to a tab name. Used by sn_theme_options_page()
 * to dispatch when $_GET['tab'] isn't present (v1.9.0+ deep links).
 *
 * v3.8.0+: iterates the union of sn_admin_top_tabs() (new 6 slugs:
 * sn-site, sn-security, sn-automation, sn-monitoring, sn-tools) AND
 * sn_admin_pages() (legacy 12 slugs). Legacy slugs return their legacy
 * tab name (e.g., 'sn-login' → 'login'); the redirect map in
 * sn_admin_maybe_redirect_legacy() then 301s 'login' → 'security#sn-sec-login'.
 * New slugs return their canonical top-tab name (e.g., 'sn-site' → 'site');
 * no redirect needed.
 */
function sn_admin_page_tab_for_slug( $slug ) {
	foreach ( sn_admin_top_tabs() as $page ) {
		if ( $page['slug'] === $slug ) {
			return $page['tab'];
		}
	}
	foreach ( sn_admin_pages() as $page ) {
		if ( $page['slug'] === $slug ) {
			return $page['tab'];
		}
	}
	return 'dashboard';
}

/**
 * Cache of all registered hook suffixes for the SN admin pages.
 * Used by the enqueue guard to load the stylesheet on any of our
 * pages without re-deriving hook names from slugs.
 *
 * add_menu_page() always returns a string; add_submenu_page() returns
 * false when the user lacks the required capability (gotcha #15), so
 * we filter the array before comparing.
 */
function sn_admin_page_hooks( $set = null ) {
	static $hooks = array();
	if ( is_array( $set ) ) {
		$hooks = array_values( array_filter( $set, 'is_string' ) );
	}
	return $hooks;
}

add_action( 'admin_menu', function() {
	$hooks = array();

	$hooks[] = add_menu_page(
		'Signal & Noise',
		'Signal & Noise',
		'manage_options',
		'sn-theme-options',
		'sn_theme_options_page',
		'dashicons-megaphone',
		81
	);

	// v3.8.1+: register 6 submenu entries (matching the new top-tab IA) instead
	// of the 12 legacy entries from sn_admin_pages(). Legacy entries' URLs still
	// resolve via the redirect map in sn_admin_maybe_redirect_legacy(). The 12-
	// entry sidebar was creating a duplicate-nav appearance in desktop-mode
	// (where the WP submenu renders as horizontal top nav instead of left sidebar).
	foreach ( sn_admin_top_tabs() as $page ) {
		$hooks[] = add_submenu_page(
			'sn-theme-options',
			$page['title'],
			$page['label'],
			'manage_options',
			$page['slug'],
			'sn_theme_options_page'
		);
	}

	sn_admin_page_hooks( $hooks );
} );

/**
 * Enqueue the SN admin stylesheet on any of our 8 pages.
 *
 * Guards via in_array() against the collected hook list so a slug
 * rename in sn_admin_pages() won't silently break the guard. Cache-
 * busted by SNT_VERSION.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! in_array( $hook, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_style(
		'sn-admin',
		SNT_URL . 'assets/admin.css',
		array(),
		SNT_VERSION
	);
	wp_enqueue_script(
		'sn-admin',
		SNT_URL . 'assets/admin.js',
		array(),
		SNT_VERSION,
		true // load in footer, after DOM is parsed
	);

	// v4.1.1 (U-01): shared confirm-dialog utility. Replaces 7 legacy
	// `window.confirm()` / `onclick="return confirm(...)"` call sites with
	// an in-page modal that works inside the desktop-mode portal iframe
	// (native confirm() is blocked there by the chrome-extension boundary).
	wp_enqueue_script(
		'snt-confirm',
		SNT_URL . 'assets/snt-confirm.js',
		array( 'wp-i18n' ),
		SNT_VERSION,
		true
	);

	// v4.0.0: Health Suggest+Apply JS — only on the Health tab, only
	// if an AI provider is configured. Mirrors the gating in
	// inc/health-checks-admin.php (the "AI fix" column + Suggest
	// buttons don't render at all without snt_ai_is_available()).
	// Tab param is canonical post-redirect: ?page=sn-monitoring&tab=health.
	if ( isset( $_GET['tab'] ) && 'health' === $_GET['tab'] ) {
		if ( function_exists( 'snt_ai_is_available' ) && snt_ai_is_available() ) {
			wp_enqueue_script(
				'snt-health-suggest-actions',
				plugins_url( 'assets/health-suggest-actions.js', SNT_PATH . 'signal-and-noise-tools.php' ),
				// v4.1.6 (U-15): snt-status provides window.sntSetStatus (replaces local setStatus copy).
				array( 'wp-api-fetch', 'wp-i18n', 'snt-status' ),
				SNT_VERSION,
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'snt-health-suggest-actions', 'signal-noise-tools' );
			}
		}
	}
} );

/**
 * Handle all SN admin form submissions on admin_init.
 *
 * Runs before any HTML output, so wp_safe_redirect() works cleanly.
 * This implements Post/Redirect/Get for our custom forms — the Plugin
 * Handbook recommends Settings API specifically because it does this
 * for you. Since we bypass Settings API to keep a single nested-array
 * option (sn_settings), we own this responsibility (gotchas #18, #19).
 *
 * Save status survives the redirect via the ?sn_flash query arg,
 * which sn_theme_options_page() reads to render the appropriate
 * success/error notice on the post-redirect GET request.
 */
add_action( 'admin_init', 'sn_handle_admin_post' );

function sn_handle_admin_post() {
	if ( ! isset( $_POST['sn_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only process for our admin pages — guards against the handler
	// firing for an unrelated $_POST that happens to carry sn_action.
	$current_page  = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
	$our_slugs     = array_column( sn_admin_pages(), 'slug' );
	if ( ! in_array( $current_page, $our_slugs, true ) ) {
		return;
	}

	check_admin_referer( 'sn_theme_options_nonce' );

	$action = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );
	$flash  = '';

	if ( 'clear_overrides' === $action ) {
		$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
		$flash = 'cleared_' . $count;
	} elseif ( 'purge_caches' === $action ) {
		apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
		$flash = 'purged';
	} elseif ( 'full_reset' === $action ) {
		// v4.1.1 (D-07): pass explicit template_overrides=true rather than an
		// empty args array. The theme-side listener's interpretation of an
		// empty array vs. an explicit truthy flag was previously undefined at
		// the call site. "Full reset" semantically includes template overrides;
		// being explicit prevents drift if the theme tightens its filter contract.
		$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true ) );
		$flash = 'reset_' . $count;
	} elseif ( 'save_identity' === $action ) {
		$saved = sn_settings_save( $_POST );
		$flash = $saved ? 'identity_saved' : 'identity_unchanged';
	} elseif ( 'save_login' === $action ) {
		$slug = isset( $_POST['login_slug'] ) ? sanitize_title( wp_unslash( $_POST['login_slug'] ) ) : '';
		if ( ! $slug ) {
			$flash = 'login_empty';
		} else {
			$settings                  = (array) get_option( 'sn_settings', array() );
			$settings['login']         = is_array( $settings['login'] ?? null ) ? $settings['login'] : array();
			$settings['login']['slug'] = $slug;
			update_option( 'sn_settings', $settings );
			// gotcha #10: update_option returns false on both "no change"
			// and "real failure" — re-read to disambiguate.
			$re_read = (array) get_option( 'sn_settings', array() );
			$flash   = ( $re_read['login']['slug'] ?? '' ) === $slug ? 'login_saved' : 'login_failed';
		}
	} elseif ( 'pl_save' === $action ) {
		// Constant-locked field: short-circuit the save so admin edits
		// can't override wp-config. Matches the locked-field-disabled
		// pattern on the Login tab.
		if ( defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN ) {
			$flash = 'pl_locked';
		} else {
			$new_token = isset( $_POST['sn_pl_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_pl_token'] ) ) : '';
			if ( 'clear' === $new_token ) {
				delete_option( SN_PLAUSIBLE_TOKEN_OPT );
				sn_pl_admin_invalidate_caches();
				$flash = 'pl_cleared';
			} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
				update_option( SN_PLAUSIBLE_TOKEN_OPT, $new_token, false ); // not autoloaded
				sn_pl_admin_invalidate_caches();
				$flash = 'pl_saved';
			} else {
				// Empty submission with the obscured placeholder = leave alone.
				$flash = 'pl_unchanged';
			}
		}
	} elseif ( 'pl_test' === $action ) {
		$cfg = sn_plausible_config();
		if ( ! $cfg ) {
			$flash = 'pl_test_unconfigured';
		} else {
			delete_transient( SN_PLAUSIBLE_ERR_KEY ); // force-fresh
			$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
			$flash  = is_array( $result ) ? 'pl_test_ok' : 'pl_test_err';
		}
	} elseif ( 'cf_save' === $action ) {
		$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
		$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

		if ( ! $token_const ) {
			$new_token = isset( $_POST['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_token'] ) ) : '';
			if ( 'clear' === $new_token ) {
				delete_option( SN_CF_TOKEN_OPT );
			} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
				update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
			}
		}
		if ( ! $zone_const ) {
			$new_zone = isset( $_POST['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_zone'] ) ) : '';
			if ( 'clear' === $new_zone ) {
				delete_option( SN_CF_ZONE_OPT );
			} elseif ( '' !== $new_zone ) {
				update_option( SN_CF_ZONE_OPT, $new_zone, true );
			}
		}
		$flash = 'cf_saved';
	} elseif ( 'cf_purge_now' === $action ) {
		$flash = sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
	} elseif ( 'apply_reading_time_cleanup' === $action ) {
		$count = (int) sn_apply_legacy_reading_time_cleanup();
		$flash = 'rt_applied_' . $count;
	} elseif ( 'health_scan' === $action ) {
		// v3.5.1: route through the central dispatcher per the established
		// pattern (matches cf_save, pl_save, etc.). The impl module owns
		// the work; this handler just dispatches + sets the flash.
		if ( function_exists( 'sn_health_run_scan' ) ) {
			sn_health_run_scan();
		}
		$flash = 'health_scanned';
	} elseif ( 'webhook_add' === $action ) {
		if ( function_exists( 'sn_webhook_create' ) ) {
			$result = sn_webhook_create( wp_unslash( $_POST ) );
			if ( is_wp_error( $result ) ) {
				$flash = 'wh_invalid';
			} else {
				// Encode new id in the flash so the renderer can show the
				// secret once. Same pattern as 'rt_applied_<count>' etc.
				$flash = 'wh_added_' . $result['id'];
			}
		} else {
			$flash = 'wh_invalid';
		}
	} elseif ( 'webhook_update' === $action ) {
		if ( function_exists( 'sn_webhook_update' ) ) {
			$id     = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
			$rotate = ! empty( $_POST['rotate_secret'] );
			$result = sn_webhook_update( $id, wp_unslash( $_POST ) );
			if ( is_wp_error( $result ) ) {
				$flash = 'wh_not_found';
			} else {
				$flash = $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
			}
		} else {
			$flash = 'wh_not_found';
		}
	} elseif ( 'webhook_delete' === $action ) {
		if ( function_exists( 'sn_webhook_delete' ) ) {
			$id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
			sn_webhook_delete( $id );
		}
		$flash = 'wh_deleted';
	} elseif ( 'insights_run' === $action ) {
		if ( function_exists( 'snt_insights_run_scan' ) ) {
			$force  = ! empty( $_POST['force'] );
			$result = snt_insights_run_scan( $force );
			$flash  = is_wp_error( $result ) ? 'insights_failed' : 'insights_scanned';
		} else {
			$flash = 'insights_failed';
		}
	} elseif ( 'insights_dismiss' === $action ) {
		if ( function_exists( 'snt_insights_dismiss' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_dismiss( $id );
		}
		$flash = 'insights_dismissed';
	} elseif ( 'insights_snooze' === $action ) {
		if ( function_exists( 'snt_insights_snooze' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_snooze( $id );
		}
		$flash = 'insights_snoozed';
	} elseif ( 'insights_mark_done' === $action ) {
		if ( function_exists( 'snt_insights_mark_done' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_mark_done( $id );
		}
		$flash = 'insights_done';
	} elseif ( 'save_insights_settings' === $action ) {
		$settings = (array) get_option( 'sn_settings', array() );
		if ( ! isset( $settings['insights'] ) || ! is_array( $settings['insights'] ) ) {
			$settings['insights'] = array();
		}
		$was_enabled = ! empty( $settings['insights']['weekly_cron_enabled'] );
		$settings['insights']['weekly_cron_enabled'] = ! empty( $_POST['insights_weekly_cron'] );
		update_option( 'sn_settings', $settings );

		// Sync the cron schedule with the new setting.
		if ( $settings['insights']['weekly_cron_enabled'] ) {
			if ( function_exists( 'snt_insights_maybe_schedule_weekly_cron' ) ) {
				snt_insights_maybe_schedule_weekly_cron();
			}
		} else {
			if ( function_exists( 'snt_insights_unschedule_weekly_cron' ) ) {
				snt_insights_unschedule_weekly_cron();
			}
		}
		$flash = 'insights_settings_saved';
	} else {
		return;
	}

	$redirect_args = array(
		'page'     => $current_page,
		'sn_flash' => $flash,
	);

	// v3.8.0+: redirect to canonical top-tab + anchor (instead of legacy
	// tab slug). v3.8.1+: also preserves &sub= query arg so flash notices
	// land on the right sub-tab (otherwise saving a form on sub-tab X
	// would redirect to the top-tab's default sub-tab, losing context).
	// The legacy tab slug from the form POST is mapped via
	// sn_admin_legacy_redirect_map() — if it's a known legacy slug, the
	// canonical top-tab + sub-tab + anchor replace it.
	$anchor = '';
	if ( isset( $_REQUEST['tab'] ) ) {
		$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
		$map           = sn_admin_legacy_redirect_map();
		$top_tabs      = array_column( sn_admin_top_tabs(), 'tab' );

		if ( in_array( $requested_tab, $top_tabs, true ) ) {
			// Already a canonical top tab; pass through.
			$redirect_args['tab'] = $requested_tab;
			// v3.8.1+: preserve &sub= from the request (set by sub-tab forms).
			if ( isset( $_REQUEST['sub'] ) ) {
				$redirect_args['sub'] = sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) );
			}
		} elseif ( isset( $map[ $requested_tab ] ) ) {
			// Legacy slug; rewrite to canonical destination.
			$redirect_args['tab'] = $map[ $requested_tab ]['tab'];
			if ( ! empty( $map[ $requested_tab ]['sub'] ) ) {
				$redirect_args['sub'] = $map[ $requested_tab ]['sub'];
			}
			if ( ! empty( $map[ $requested_tab ]['anchor'] ) ) {
				$anchor = '#sn-sec-' . rawurlencode( $map[ $requested_tab ]['anchor'] );
			}
		} else {
			// Unknown slug; fall back to dashboard.
			$redirect_args['tab'] = 'dashboard';
		}
	}

	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

	// Raw header() because wp_safe_redirect() strips URL fragments.
	// Destination is admin_url() (same-host, trusted) with sanitized
	// top-tab name from a fixed allowlist — safe.
	// 302 not 301: this is a transient post-save redirect, not a "moved permanently" signal.
	header( 'Location: ' . $redirect_url, true, 302 );
	exit;
}

function sn_theme_options_page() {
	// Defense-in-depth capability check. WordPress's add_theme_page()
	// already gates access to the admin URL itself, but re-checking here
	// matches WPCS convention for any handler that mutates state and
	// keeps this function safe if it's ever invoked from another context
	// (e.g. a future shortcode, AJAX dispatcher, or REST callback).
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
	}

	// v3.8.0+: 301-redirect legacy tab/page slugs to canonical destinations.
	// Must run BEFORE any output so headers can still be sent.
	sn_admin_maybe_redirect_legacy();

	$theme         = wp_get_theme( 'signal-and-noise' );
	$local_version = $theme->get( 'Version' );
	$notices       = array();
	$valid_tabs = sn_admin_page_valid_tabs();

	// Dispatch order: (1) explicit ?tab=… in URL (v1.8.x legacy deep links;
	// must keep working); (2) derive from the current ?page=… slug (v1.9.0
	// path — each sidebar submenu has a unique slug). Default to dashboard
	// if neither resolves.
	if ( isset( $_GET['tab'] ) ) {
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	} else {
		$current_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
		$active_tab   = sn_admin_page_tab_for_slug( $current_slug );
	}

	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'dashboard';
	}

	// Form processing happens in sn_handle_admin_post() on admin_init —
	// before any output, so wp_safe_redirect() works (gotcha #17, #19).
	// This block just translates ?sn_flash=… into notices for the
	// post-redirect GET request.
	if ( isset( $_GET['sn_flash'] ) ) {
		$flash = sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) );
		if ( 'identity_saved' === $flash ) {
			$notices[] = array( 'success', 'Identity settings saved.' );
		} elseif ( 'identity_unchanged' === $flash ) {
			$notices[] = array( 'info', 'No changes to save.' );
		} elseif ( 'login_saved' === $flash ) {
			$slug_now  = sn_setting( 'login.slug', 'sn-login' );
			$login_url = home_url( '/' . $slug_now );
			$notices[] = array( 'success', 'Login slug saved. New URL: <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>' );
		} elseif ( 'login_empty' === $flash ) {
			$notices[] = array( 'error', 'Login slug cannot be empty.' );
		} elseif ( 'login_failed' === $flash ) {
			$notices[] = array( 'error', 'Login slug save failed.' );
		} elseif ( 'pl_saved' === $flash ) {
			$notices[] = array( 'success', 'Stats API key saved. Caches purged — widgets refresh on next dashboard view.' );
		} elseif ( 'pl_cleared' === $flash ) {
			$notices[] = array( 'success', 'Stats API key cleared. Caches purged.' );
		} elseif ( 'pl_unchanged' === $flash ) {
			$notices[] = array( 'info', 'No changes to save.' );
		} elseif ( 'pl_locked' === $flash ) {
			$notices[] = array( 'error', 'Token is locked by the SN_PLAUSIBLE_STATS_TOKEN constant — remove the constant in wp-config.php to edit here.' );
		} elseif ( 'pl_test_ok' === $flash ) {
			// Read fresh count from the transient sn_plausible_api populates.
			$cached   = get_transient( SN_PLAUSIBLE_BATCH_KEY );
			$visitors = is_array( $cached ) && isset( $cached['data']['visitors']['value'] ) ? (int) $cached['data']['visitors']['value'] : 0;
			$notices[] = array( 'success', '&#10003; API call succeeded — ' . number_format_i18n( $visitors ) . ' visitor(s) in last 7 days.' );
		} elseif ( 'pl_test_err' === $flash ) {
			$err    = sn_plausible_last_error();
			$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
			$notices[] = array( 'error', '&#10005; API call failed &mdash; ' . $detail );
		} elseif ( 'pl_test_unconfigured' === $flash ) {
			$notices[] = array( 'error', 'Plausible not fully configured (missing domain or token).' );
		} elseif ( 'cf_saved' === $flash ) {
			$notices[] = array( 'success', 'Cloudflare settings saved.' );
		} elseif ( 'cf_purged_ok' === $flash ) {
			$notices[] = array( 'success', 'Cloudflare zone purge dispatched.' );
		} elseif ( 'cf_purged_unconfigured' === $flash ) {
			$notices[] = array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' );
		} elseif ( 0 === strpos( $flash, 'rt_applied_' ) ) {
			$count     = (int) substr( $flash, strlen( 'rt_applied_' ) );
			$notices[] = array( 'success', sprintf( '%d post(s) cleaned. Reading-time cache rebuilt.', $count ) );
		} elseif ( 'purged' === $flash ) {
			$notices[] = array( 'success', 'All caches purged.' );
		} elseif ( 0 === strpos( $flash, 'cleared_' ) ) {
			$count     = (int) substr( $flash, strlen( 'cleared_' ) );
			$notices[] = array( 'success', $count . ' database override(s) cleared. Site is reading from theme files.' );
		} elseif ( 0 === strpos( $flash, 'reset_' ) ) {
			$count     = (int) substr( $flash, strlen( 'reset_' ) );
			$notices[] = array( 'success', 'Full reset: ' . $count . ' override(s) cleared + all caches purged.' );
		} elseif ( 0 === strpos( $flash, 'wh_added_' ) ) {
			$notices[] = array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' );
		} elseif ( 'wh_updated' === $flash ) {
			$notices[] = array( 'success', 'Webhook updated.' );
		} elseif ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
			$notices[] = array( 'success', 'Webhook updated. <strong>Signing secret was rotated</strong> — copy the new value below before navigating away.' );
		} elseif ( 'wh_deleted' === $flash ) {
			$notices[] = array( 'success', 'Webhook deleted. Pending retries (if any) will drop on next dispatch.' );
		} elseif ( 'wh_invalid' === $flash ) {
			$notices[] = array( 'error', 'Could not add webhook — name and valid URL are required.' );
		} elseif ( 'wh_not_found' === $flash ) {
			$notices[] = array( 'error', 'Webhook not found.' );
		} elseif ( 'insights_scanned' === $flash ) {
			$notices[] = array( 'success', 'Insights scan complete — recommendations below.' );
		} elseif ( 'insights_failed' === $flash ) {
			$notices[] = array( 'error', 'Insights scan failed. Check that an AI provider is configured under Settings → Connectors.' );
		} elseif ( 'insights_dismissed' === $flash ) {
			$notices[] = array( 'success', 'Recommendation dismissed.' );
		} elseif ( 'insights_snoozed' === $flash ) {
			$notices[] = array( 'success', 'Recommendation snoozed for 30 days.' );
		} elseif ( 'insights_done' === $flash ) {
			$notices[] = array( 'success', 'Recommendation marked as done.' );
		} elseif ( 'insights_settings_saved' === $flash ) {
			$notices[] = array( 'success', 'Insights settings saved.' );
		} elseif ( 'health_scanned' === $flash ) {
			$notices[] = array( 'success', 'Scan complete — findings below.' );
		}
	}

	// Extract the new/rotated webhook id from the flash so the Webhooks
	// renderer can highlight the affected row + show the secret once.
	if ( ! isset( $_GET['new_id'] ) && isset( $_GET['sn_flash'] ) ) {
		$flash_now = sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) );
		if ( 0 === strpos( $flash_now, 'wh_added_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_added_' ) );
		} elseif ( 0 === strpos( $flash_now, 'wh_rotated_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_rotated_' ) );
		}
	}

	// v4.1.1 (X-03): removed dead `$local_sha = get_option('sn_github_local_sha', '')`.
	// The option was written by the legacy updater (inc/updater.php) retired in
	// theme v8.3.0 — the variable was never read after fetch and the option is
	// always empty string on current installs. Existing leftover DB data is
	// harmless; no migration needed.

	$overrides = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part', 'wp_navigation' ), 'posts_per_page' => -1, 'post_status' => 'any' ) );
	$base_url  = admin_url( 'admin.php?page=sn-theme-options' );

	// ── PAGE SHELL ──
	echo '<div class="wrap">';
	echo '<h1 class="sn-page-h1">Signal &amp; Noise</h1>';
	$subtitle = sn_admin_page_subtitle_for_tab( $active_tab );
	if ( $subtitle ) {
		echo '<p class="sn-page-subtitle">' . esc_html( $subtitle ) . '</p>';
	}

	// Notices. Severity is escaped as an attribute; bodies are run
	// through wp_kses_post because some entries deliberately ship
	// inline markup (<a>, <code>) — esc_html would mangle those.
	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
	}

	// ── TABS ──
	$tab_labels = sn_admin_page_tab_labels();
	echo '<nav class="nav-tab-wrapper sn-nav-tabs">';
	foreach ( $tab_labels as $slug => $label ) {
		$is_active = ( $slug === $active_tab );
		echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	// v3.8.1+: resolve the active sub-tab for the current top tab. Used by
	// every dispatch arm below to render only the active sub-tab's content
	// instead of all sub-sections (fixes the v3.8.0 long-scroll-per-tab issue).
	// Returns '' for Dashboard (which has no sub_tabs).
	$active_sub = sn_admin_resolve_active_sub( $active_tab );

	// ════════════════════════════════════════
	// TAB: DASHBOARD (landing — no sub-tabs)
	// ════════════════════════════════════════
	if ( 'dashboard' === $active_tab ) {

		/**
		 * Dashboard renders the hero state grid + recent deploys +
		 * maintenance cards + API summary + diagnostics via the
		 * sn_admin_dashboard_extras hook (see inc/admin-tab-dashboard.php).
		 * This is a landing page with no in-page TOC.
		 */
		do_action( 'sn_admin_dashboard_extras' );

	// ════════════════════════════════════════
	// TAB: SITE (v3.8.1+: sub-tabs)
	// Sub-tabs: identity-and-seo (with inner TOC for 4 form sections), cloudflare
	// ════════════════════════════════════════
	} elseif ( 'site' === $active_tab ) {

		sn_admin_render_sub_tabs( 'site', $active_sub );

	if ( 'cloudflare' === $active_sub ) {
		// Cloudflare sub-tab — module-owned (inc/cloudflare-purge.php), own form.
		sn_admin_render_section( 'cloudflare', function() {
			do_action( 'sn_admin_cloudflare_tab' );
		} );
	} else {
		// Default sub-tab: 'identity-and-seo' (bundle of 4 form sections with one Save).
		sn_admin_render_toc( 'site', 'identity-and-seo' );

		echo '<form method="post" class="sn-identity-form">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="save_identity">';

		sn_admin_render_section( 'identity', function() {
			echo '<h2 class="sn-fieldset-h">Identity</h2>';
			echo '<p class="sn-fieldset-intro">Site-wide name, description, and locale.</p>';

			echo '<div class="sn-field sn-field-w-md">';
			echo '<label class="sn-field-label" for="sn_identity_site_name">Site name</label>';
			echo '<input type="text" id="sn_identity_site_name" name="identity_site_name" value="' . esc_attr( sn_setting( 'identity.site_name', '' ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_identity_site_description">Site description</label>';
			echo '<textarea id="sn_identity_site_description" name="identity_site_description" rows="2">' . esc_textarea( (string) sn_setting( 'identity.site_description', '' ) ) . '</textarea>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-md">';
			echo '<label class="sn-field-label" for="sn_identity_person_name">Person name (schema author)</label>';
			echo '<input type="text" id="sn_identity_person_name" name="identity_person_name" value="' . esc_attr( sn_setting( 'identity.person_name', '' ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-md">';
			echo '<label class="sn-field-label" for="sn_identity_job_title">Job title</label>';
			echo '<input type="text" id="sn_identity_job_title" name="identity_job_title" value="' . esc_attr( sn_setting( 'identity.job_title', 'Music Producer' ) ) . '" placeholder="Music Producer">';
			echo '<p class="sn-field-helper">Emitted as <code>jobTitle</code> on the Person schema. Single short phrase.</p>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_identity_knows_about">Knows about</label>';
			$knows_about_value = (array) sn_setting(
				'identity.knows_about',
				array( 'Music Production', 'Audio Engineering', 'Provenance', 'Music Industry' )
			);
			echo '<textarea id="sn_identity_knows_about" name="identity_knows_about" rows="4">' . esc_textarea( implode( "\n", $knows_about_value ) ) . '</textarea>';
			echo '<p class="sn-field-helper">One topic per line. Emitted as the <code>knowsAbout</code> array on the Person schema — domain expertise areas that signal to search engines what this person is about. Leave a line blank to omit the entry.</p>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xs">';
			echo '<label class="sn-field-label" for="sn_identity_locale">Locale</label>';
			echo '<input type="text" id="sn_identity_locale" name="identity_locale" value="' . esc_attr( sn_setting( 'identity.locale', 'en_US' ) ) . '" placeholder="en_US">';
			echo '<p class="sn-field-helper">WP locale code (e.g. <code>en_US</code>). Used for og:locale and schema inLanguage.</p>';
			echo '</div>';
		} );

		sn_admin_render_section( 'social', function() {
			echo '<h2 class="sn-fieldset-h">Social</h2>';
			echo '<p class="sn-fieldset-intro">Twitter / X handle and profile URLs (emitted as schema sameAs).</p>';

			echo '<div class="sn-field sn-field-w-sm">';
			echo '<label class="sn-field-label" for="sn_social_twitter_handle">Twitter / X handle</label>';
			echo '<input type="text" id="sn_social_twitter_handle" name="social_twitter_handle" value="' . esc_attr( sn_setting( 'social.twitter_handle', '' ) ) . '" placeholder="@username">';
			echo '<p class="sn-field-helper">Used as twitter:site and twitter:creator. Include the @ prefix.</p>';
			echo '</div>';

			$same_as = (array) sn_setting( 'social.same_as', array() );
			echo '<div class="sn-field">';
			echo '<label class="sn-field-label">Profile URLs (sameAs)</label>';
			echo '<div class="sn-sameas">';
			foreach ( $same_as as $url ) {
				echo '<input type="url" name="social_same_as[]" value="' . esc_attr( (string) $url ) . '" placeholder="https://...">';
			}
			echo '<button type="button" class="sn-add-row-btn" aria-label="Add another profile URL row">Add another profile URL</button>';
			echo '<noscript>';
			echo '<input type="url" name="social_same_as[]" value="" placeholder="https://..." class="sn-sameas-extra">';
			echo '</noscript>';
			echo '</div>'; // .sn-sameas
			echo '<p class="sn-field-helper">Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.</p>';
			echo '</div>';
		} );

		sn_admin_render_section( 'open-graph', function() {
			echo '<h2 class="sn-fieldset-h">Open Graph</h2>';
			echo '<p class="sn-fieldset-intro">Fallback OG image and card dimensions for social shares.</p>';

			echo '<div class="sn-field sn-field-w-lg">';
			echo '<label class="sn-field-label" for="sn_og_default_image_url">Default OG image URL</label>';
			echo '<input type="url" id="sn_og_default_image_url" name="og_default_image_url" value="' . esc_attr( (string) sn_setting( 'og.default_image_url', '' ) ) . '">';
			echo '<p class="sn-field-helper">Fallback image used when no per-post OG card exists.</p>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xs">';
			echo '<label class="sn-field-label" for="sn_og_card_width">Card width (px)</label>';
			echo '<input type="number" min="1" id="sn_og_card_width" name="og_card_width" value="' . esc_attr( (string) sn_setting( 'og.card_width', 1200 ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xs">';
			echo '<label class="sn-field-label" for="sn_og_card_height">Card height (px)</label>';
			echo '<input type="number" min="1" id="sn_og_card_height" name="og_card_height" value="' . esc_attr( (string) sn_setting( 'og.card_height', 630 ) ) . '">';
			echo '</div>';
		} );

		sn_admin_render_section( 'seo-copy', function() {
			echo '<h2 class="sn-fieldset-h">SEO Copy</h2>';
			echo '<p class="sn-fieldset-intro">Per-route title + description for the home, /notes, and /provenance pages.</p>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_home_title">Home title</label>';
			echo '<input type="text" id="sn_seo_home_title" name="seo_home_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.home_title', '' ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_home_description">Home description</label>';
			echo '<textarea id="sn_seo_home_description" name="seo_home_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.home_description', '' ) ) . '</textarea>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_notes_title">/notes title</label>';
			echo '<input type="text" id="sn_seo_notes_title" name="seo_notes_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.notes_title', '' ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_notes_description">/notes description</label>';
			echo '<textarea id="sn_seo_notes_description" name="seo_notes_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.notes_description', '' ) ) . '</textarea>';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_provenance_title">/provenance title</label>';
			echo '<input type="text" id="sn_seo_provenance_title" name="seo_provenance_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.provenance_title', '' ) ) . '">';
			echo '</div>';

			echo '<div class="sn-field sn-field-w-xl">';
			echo '<label class="sn-field-label" for="sn_seo_provenance_description">/provenance description</label>';
			echo '<textarea id="sn_seo_provenance_description" name="seo_provenance_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.provenance_description', '' ) ) . '</textarea>';
			echo '</div>';
		} );

		// Sticky save bar — saves Identity / Social / OG / SEO Copy (the 4 above).
		// Cloudflare's save is separate (its own form on its own sub-tab now).
		echo '<div class="sn-savebar">';
		echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
		echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
		echo '</div>';
		echo '</form>';
	}  // close: else (identity-and-seo sub-tab)

	// ════════════════════════════════════════
	// TAB: SECURITY (v3.8.1+: sub-tabs)
	// Sub-tabs: login (audit-log added in future v3.8.x). Sub-tab nav hidden when count < 2.
	// ════════════════════════════════════════
	} elseif ( 'security' === $active_tab ) {

		sn_admin_render_sub_tabs( 'security', $active_sub );

		// v3.8.3+: 2 sub-tabs (Login URL + Audit log) — sub-tab nav now visible.
		if ( 'audit-log' === $active_sub ) {
			sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
		} elseif ( 'login' === $active_sub || '' === $active_sub ) {
		sn_admin_render_section( 'login', function() {
			// Detect module state. Three possibilities:
			//   1. ACTIVE: our login-hide.php is firing (no wps-hide-login,
			//      no SN_LOGIN_BYPASS)
			//   2. DORMANT (conflict): wps-hide-login is still active so
			//      our module stood down
			//   3. DORMANT (bypass): SN_LOGIN_BYPASS constant is set
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$wps_basename = 'wps-hide-login/wps-hide-login.php';
			$wps_active   = is_plugin_active( $wps_basename ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
			$bypassed     = defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS;
			$slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' );
			$slug_const   = defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG;
			$login_url    = home_url( '/' . $slug );

			echo '<p class="sn-prose">Custom login URL module — replaces <code>/wp-login.php</code> with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).</p>';

			// Status box
			if ( $bypassed ) {
				echo '<div class="sn-status-box sn-status-box--warn">';
				echo '<div>';
				echo '<p class="sn-status-box-title">Module bypassed</p>';
				echo '<p class="sn-status-box-body">The <code>SN_LOGIN_BYPASS</code> constant is set in <code>wp-config.php</code>. Default <code>/wp-login.php</code> behavior is restored. Remove the constant to re-enable.</p>';
				echo '</div>';
				echo '<span class="sn-pill sn-pill--warn">Bypassed</span>';
				echo '</div>';
			} elseif ( $wps_active ) {
				echo '<div class="sn-status-box sn-status-box--warn">';
				echo '<div>';
				echo '<p class="sn-status-box-title">Module dormant — conflict with wps-hide-login</p>';
				echo '<p class="sn-status-box-body">The <code>wps-hide-login</code> plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.</p>';
				echo '</div>';
				echo '<span class="sn-pill sn-pill--warn">Dormant</span>';
				echo '</div>';
			} else {
				echo '<div class="sn-status-box">';
				echo '<div>';
				echo '<p class="sn-status-box-title">Module active</p>';
				echo '<p class="sn-status-box-body">Direct visits to <code>/wp-login.php</code> and unauthenticated <code>/wp-admin</code> return 404. Login form reachable at the custom URL below.</p>';
				echo '</div>';
				echo '<span class="sn-pill sn-pill--ok">Active</span>';
				echo '</div>';
			}

			echo '<form method="post">';
			wp_nonce_field( 'sn_theme_options_nonce' );
			echo '<input type="hidden" name="sn_action" value="save_login">';

			echo '<h2 class="sn-fieldset-h">Custom login slug</h2>';
			echo '<p class="sn-fieldset-intro">The path segment used in place of <code>wp-login.php</code>.</p>';

			echo '<div class="sn-field sn-field-w-sm">';
			echo '<label class="sn-field-label" for="sn_login_slug">Slug</label>';
			if ( $slug_const ) {
				echo '<input type="text" id="sn_login_slug" value="' . esc_attr( $slug ) . '" disabled>';
				echo '<p class="sn-field-helper"><strong>Locked.</strong> The <code>SN_LOGIN_SLUG</code> constant in <code>wp-config.php</code> is overriding this field. Remove the constant to edit here.</p>';
			} else {
				echo '<input type="text" id="sn_login_slug" name="login_slug" value="' . esc_attr( $slug ) . '" placeholder="sn-login">';
				echo '<p class="sn-field-helper">Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).</p>';
			}
			echo '</div>';

			echo '<div class="sn-field">';
			echo '<label class="sn-field-label">Current login URL</label>';
			echo '<a class="sn-url-preview" href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>';
			echo '<p class="sn-field-helper">Bookmark this URL. The default <code>/wp-login.php</code> 404s for unauthenticated visitors.</p>';
			echo '</div>';

			echo '<div class="sn-fieldset-actions">';
			if ( $slug_const ) {
				echo '<p class="sn-fieldset-actions-hint">Slug locked by <code>SN_LOGIN_SLUG</code> constant.</p>';
			}
			echo '<button type="submit" class="button button-primary"' . ( $slug_const ? ' disabled' : '' ) . '>Save</button>';
			echo '</div>';

			echo '</form>';

			// Emergency unlock docs (out-of-form, no submission)
			echo '<div class="sn-callout">';
			echo '<p class="sn-callout-h">Emergency unlock</p>';
			echo '<p>If you ever lock yourself out (forgot the slug, can\'t reach the login form), add either of these constants to <code>wp-config.php</code> via SSH or your host\'s file manager:</p>';
			echo '<pre>// Option 1 — pin the slug. Reachable at /&lt;slug-here&gt;.
define( \'SN_LOGIN_SLUG\', \'your-fallback-slug\' );

// Option 2 — disable the module entirely. Restores /wp-login.php.
define( \'SN_LOGIN_BYPASS\', true );</pre>';
			echo '<p>The constants take priority over the setting and persist across plugin updates. Remove them once you\'ve regained access.</p>';
			echo '</div>';
		} );
		}  // close: elseif login (default)

	// ════════════════════════════════════════
	// TAB: AUTOMATION (v3.8.1+: sub-tabs)
	// Sub-tabs: webhooks, cron
	// ════════════════════════════════════════
	} elseif ( 'automation' === $active_tab ) {

		sn_admin_render_sub_tabs( 'automation', $active_sub );

		if ( 'cron' === $active_sub ) {
			sn_admin_render_section( 'cron', function() {
				do_action( 'sn_admin_cron_tab' );
			} );
		} else {
			// Default sub-tab: 'webhooks'
			sn_admin_render_section( 'webhooks', function() {
				do_action( 'sn_admin_webhooks_tab' );
			} );
		}

	// ════════════════════════════════════════
	// TAB: MONITORING (v3.8.1+: sub-tabs)
	// Sub-tabs: insights, health, plausible, rss
	// ════════════════════════════════════════
	} elseif ( 'monitoring' === $active_tab ) {

		sn_admin_render_sub_tabs( 'monitoring', $active_sub );

		if ( 'health' === $active_sub ) {
			sn_admin_render_section( 'health', function() {
				do_action( 'sn_admin_health_tab' );
			} );
		} elseif ( 'plausible' === $active_sub ) {
			sn_admin_render_section( 'plausible', function() {
				do_action( 'sn_admin_plausible_tab' );
			} );
		} elseif ( 'rss' === $active_sub ) {
			sn_admin_render_section( 'rss', function() {
				if ( has_action( 'sn_admin_rss_tab' ) ) {
					do_action( 'sn_admin_rss_tab' );
				} else {
					echo '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS subscriber tracker not installed.</strong></p>';
					echo '<p>Copy <code>mu-plugins/rss-plausible-tracker.php</code> from the theme repo to <code>wp-content/mu-plugins/</code> on this host. MU plugins activate automatically — no further action needed.</p></div>';
				}
			} );
		} else {
			// Default sub-tab: 'insights'
			sn_admin_render_section( 'insights', function() {
				do_action( 'sn_admin_insights_tab' );
			} );
		}

	// ════════════════════════════════════════
	// TAB: TOOLS (v3.8.1+: sub-tabs)
	// Sub-tabs: reading-time, links
	// ════════════════════════════════════════
	} elseif ( 'tools' === $active_tab ) {

		sn_admin_render_sub_tabs( 'tools', $active_sub );

		if ( 'links' === $active_sub ) {
			sn_admin_render_section( 'links', function() {
			$link_groups = array(
				array(
					'label' => 'Source code',
					'links' => array(
						array( 'title' => 'Theme repo',  'href' => 'https://github.com/juanlentino/signal-and-noise' ),
						array( 'title' => 'Plugin repo', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools' ),
					),
				),
				array(
					'label' => 'Releases',
					'links' => array(
						array( 'title' => 'Theme releases',  'href' => 'https://github.com/juanlentino/signal-and-noise/releases' ),
						array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/releases' ),
					),
				),
				array(
					'label' => 'Infrastructure',
					'links' => array(
						array( 'title' => 'Cloudflare dashboard', 'href' => 'https://dash.cloudflare.com' ),
						array( 'title' => 'Cloudways platform',   'href' => 'https://platform.cloudways.com' ),
					),
				),
			);
			echo '<div class="sn-link-grid">';
			foreach ( $link_groups as $group ) {
				foreach ( $group['links'] as $link ) {
					$host = (string) wp_parse_url( $link['href'], PHP_URL_HOST );
					echo '<div class="sn-link-card">';
					echo '<span class="sn-link-card__label">' . esc_html( $group['label'] ) . '</span>';
					echo '<span class="sn-link-card__title">' . esc_html( $link['title'] ) . '</span>';
					echo '<span class="sn-link-card__host">' . esc_html( $host ) . ' &#x2197;</span>';
					echo '<a class="sn-link-card__link" href="' . esc_url( $link['href'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['title'] ) . '</a>';
					echo '</div>';
				}
			}
			echo '</div>';
		} );
		} else {
			// Default sub-tab: 'reading-time'
			sn_admin_render_section( 'reading-time', function() {
				do_action( 'sn_admin_reading_time_tab' );
			} );
		}

	}

	echo '</div>'; // wrap
}
