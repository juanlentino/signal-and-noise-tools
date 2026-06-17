<?php
/**
 * Signal & Noise — legacy admin-URL compatibility.
 *
 * The legacy 12-slug page registry (sn_admin_pages), the legacy-tab →
 * canonical (top tab + sub-tab + anchor) redirect map, slug → tab resolution,
 * and the 301 redirect performed before dispatch. Keeps every pre-v3.8.0
 * ?page=sn-<slug> / ?tab=<slug> deep link working. Extracted from
 * inc/admin-page.php in v4.5.4.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy table of admin page slugs.
 *
 * Defined once at module scope so registration and dispatch read from
 * a single source of truth. Slug uniqueness is critical — WP's
 * add_submenu_page() has no duplicate detection (gotcha #16 in
 * docs/WORDPRESS-REFERENCE.md), so a typo here would silently produce
 * a phantom sidebar entry.
 *
 * Order in the array = display order in the WP sidebar.
 *
 * @internal Permanent legacy infrastructure (NOT pending removal). Was marked
 *           @deprecated in v4.2.0 framing but the function is load-bearing —
 *           active call site at line ~474 (POST allowlist) plus the legacy URL
 *           redirect at sn_admin_maybe_redirect_legacy() that 302s
 *           ?page=sn-<slug> URLs to canonical tab URLs. The redirect handles
 *           GET fine but POST bodies submitted to a legacy URL are lost in
 *           the redirect. New code MUST use sn_admin_top_tabs() for routing
 *           decisions; this table is the source of truth for the legacy URL
 *           shape only. Re-framed 2026-05-26 after Audit C HYG-08.
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
		'cloudflare'   => array( 'tab' => 'connections', 'sub' => 'cloudflare',        'anchor' => null ),  // v6.18.0: Site → Connections
		'login'        => array( 'tab' => 'security',    'sub' => 'login',             'anchor' => null ),
		'webhooks'     => array( 'tab' => 'connections', 'sub' => 'webhooks',          'anchor' => null ),  // v6.18.0: Automation → Connections
		'cron'         => array( 'tab' => 'connections', 'sub' => 'cron',              'anchor' => null ),  // v6.18.0
		'insights'     => array( 'tab' => 'monitoring',  'sub' => 'insights',          'anchor' => null ),
		'health'       => array( 'tab' => 'monitoring',  'sub' => 'health',            'anchor' => null ),
		'rss'          => array( 'tab' => 'content',     'sub' => 'rss',               'anchor' => null ),  // v6.18.0: Monitoring → Content
		'reading-time' => array( 'tab' => 'content',     'sub' => 'reading-time',      'anchor' => null ),  // v6.18.0: Tools → Content
		'links'        => array( 'tab' => 'tools',       'sub' => 'links',             'anchor' => null ),
		'automation'   => array( 'tab' => 'connections', 'sub' => null,                'anchor' => null ),  // v6.18.0: retired top tab → Connections
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
