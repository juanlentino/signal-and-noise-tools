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
		array( 'slug' => 'sn-rss',           'tab' => 'rss',          'label' => 'RSS',           'title' => 'Signal & Noise — RSS',           'subtitle' => 'RSS subscriber tracking and feed-request analytics.' ),
		array( 'slug' => 'sn-reading-time',  'tab' => 'content',      'label' => 'Reading Time',  'title' => 'Signal & Noise — Reading Time',  'subtitle' => 'Retired cleanup tool (v10.0.0) — old bookmarks land on the Content tab.' ),
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
		'rss'          => array( 'tab' => 'monitoring',  'sub' => 'rss',               'anchor' => null ),  // v6.18.0: Monitoring → Content; v10.46.0: back to Measurement (feed-request analytics)
		'reading-time' => array( 'tab' => 'content',     'sub' => null,                'anchor' => null ),  // v10.24.0: the cleanup tool retired in v10.0.0 — Content default, no ghost sub
		'links'        => array( 'tab' => 'tools',       'sub' => 'links',             'anchor' => null ),
		'automation'   => array( 'tab' => 'connections', 'sub' => null,                'anchor' => null ),  // v6.18.0: retired top tab → Connections
	);
}

/**
 * Phase-2 IA (v6.18.0): leaves whose PARENT tab changed. Keyed '<oldtab>/<oldsub>'
 * → canonical new { tab, sub }. The legacy redirect map handles pre-v3.8 FLAT slugs
 * (?tab=cloudflare); this handles post-v3.8 CANONICAL bookmarks
 * (?tab=site&sub=cloudflare) whose parent tab still exists but no longer owns the
 * leaf. Consumed by sn_admin_canonical_destination().
 *
 * @since 6.18.0
 * @return array<string,array{tab:string,sub:string}>
 */
function sn_admin_subtab_moves() {
	return array(
		'site/cloudflare'     => array( 'tab' => 'connections', 'sub' => 'cloudflare' ),
		'automation/webhooks' => array( 'tab' => 'connections', 'sub' => 'webhooks' ),
		'automation/cron'     => array( 'tab' => 'connections', 'sub' => 'cron' ),
		'automation/indexnow' => array( 'tab' => 'connections', 'sub' => 'indexnow' ),
		'tools/reading-time'  => array( 'tab' => 'content', 'sub' => null ),           // v10.24.0: tool retired in v10.0.0

		// ── v10.46.0 Phase-3 IA: leaves that changed parent tab. ──
		'content/front-end'      => array( 'tab' => 'site', 'sub' => 'front-end' ),
		'content/performance'    => array( 'tab' => 'site', 'sub' => 'performance' ),
		'connections/redirects'  => array( 'tab' => 'site', 'sub' => 'redirects' ),
		'content/music'          => array( 'tab' => 'connections', 'sub' => 'music' ),
		'content/rss'            => array( 'tab' => 'monitoring', 'sub' => 'rss' ),
		'tools/block-migrations' => array( 'tab' => 'content', 'sub' => 'block-migrations' ),
		'tools/mcp-connect'      => array( 'tab' => 'ai', 'sub' => 'mcp-connect' ),
		'tools/copilot-usage'    => array( 'tab' => 'ai', 'sub' => 'copilot-usage' ),

		// ── Repointed, NOT chained. These three entries already existed, aimed at
		// destinations that have themselves now moved. This resolver runs ONCE per
		// request — it does not follow a second hop — so leaving them pointed at the
		// old intermediate would land a v6.18.0-era bookmark on a tab that no longer
		// owns the leaf (a silent mis-route, the exact failure class recorded at
		// CHANGELOG.md:874). Each now names the FINAL home directly. ──
		'tools/performance'   => array( 'tab' => 'site', 'sub' => 'performance' ),      // was content
		'tools/front-end'     => array( 'tab' => 'site', 'sub' => 'front-end' ),        // was content
		'monitoring/music'    => array( 'tab' => 'connections', 'sub' => 'music' ),     // was content
		// 'monitoring/rss' is deliberately ABSENT: RSS now lives in monitoring, so an
		// entry here would bounce the leaf straight back out of its own tab.
	);
}

/**
 * Resolve where a requested (tab, sub) should canonically land after the v6.18.0
 * IA reshuffle. PURE — no side effects (the GET 301 wrapper and the POST PRG both
 * consume this so a moved leaf lands identically either way).
 *
 * Precedence: (1) a leaf that changed parent tab; (2) a current top tab → null
 * (already canonical, caller passes through verbatim); (3) a pre-v3.8 legacy tab
 * slug → its mapped destination. Returns null when no redirect is needed.
 *
 * @since 6.18.0
 * @param string $requested_tab
 * @param string $requested_sub
 * @return array{tab:string,sub:?string,anchor:?string}|null
 */
function sn_admin_canonical_destination( $requested_tab, $requested_sub = '' ) {
	$top_tabs = array_column( sn_admin_top_tabs(), 'tab' );

	// 1. A leaf whose parent tab changed (post-v3.8 canonical bookmark).
	if ( '' !== (string) $requested_sub ) {
		$moves = sn_admin_subtab_moves();
		$key   = $requested_tab . '/' . $requested_sub;
		if ( isset( $moves[ $key ] ) ) {
			return array( 'tab' => $moves[ $key ]['tab'], 'sub' => $moves[ $key ]['sub'], 'anchor' => null );
		}
	}

	// 2. Already a current top tab — no redirect; caller keeps tab + sub verbatim.
	if ( in_array( $requested_tab, $top_tabs, true ) ) {
		return null;
	}

	// 3. Pre-v3.8 legacy tab slug → mapped destination.
	$map = sn_admin_legacy_redirect_map();
	if ( isset( $map[ $requested_tab ] ) ) {
		return array(
			'tab'    => $map[ $requested_tab ]['tab'],
			'sub'    => $map[ $requested_tab ]['sub'],
			'anchor' => $map[ $requested_tab ]['anchor'],
		);
	}

	return null;
}

/**
 * The PRG (Post/Redirect/Get) target for a save posted from (requested_tab,
 * requested_sub). PURE — the GET 301 and this POST resolver share
 * sn_admin_canonical_destination() so a save lands on the same canonical sub-tab a
 * bookmark would. A moved leaf / legacy slug is rewritten; a current top tab passes
 * through with its sub preserved; an unknown tab falls back to dashboard (matching
 * pre-refactor behaviour). Extracted so the wiring is unit-testable
 * (sn_handle_admin_post itself ends in header()+exit and can't be driven by a fixture).
 *
 * @since 6.18.0
 * @param string $requested_tab
 * @param string $requested_sub
 * @return array{tab:string,sub:?string,anchor:?string}
 */
function sn_admin_post_redirect_target( $requested_tab, $requested_sub = '' ) {
	$dest = sn_admin_canonical_destination( $requested_tab, $requested_sub );
	if ( null !== $dest ) {
		return array(
			'tab'    => $dest['tab'],
			'sub'    => ! empty( $dest['sub'] ) ? $dest['sub'] : null,
			'anchor' => ! empty( $dest['anchor'] ) ? $dest['anchor'] : null,
		);
	}
	$top_tabs = array_column( sn_admin_top_tabs(), 'tab' );
	if ( in_array( $requested_tab, $top_tabs, true ) ) {
		return array( 'tab' => $requested_tab, 'sub' => ( '' !== (string) $requested_sub ? $requested_sub : null ), 'anchor' => null );
	}
	return array( 'tab' => 'dashboard', 'sub' => null, 'anchor' => null );
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
	// Source 1: explicit ?tab=<slug> (+ ?sub=<leaf> for moved-leaf bookmarks).
	$requested_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	$requested_sub = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : '';

	// Source 2: derive the tab from ?page=sn-<slug> when ?tab= is absent.
	if ( ! $requested_tab && isset( $_GET['page'] ) ) {
		// sn_admin_page_tab_for_slug() maps legacy + retired page slugs to a tab name
		// (sn-automation → automation → connections via the redirect map).
		$requested_tab = sn_admin_page_tab_for_slug( sanitize_text_field( wp_unslash( $_GET['page'] ) ) );
	}

	if ( ! $requested_tab ) {
		return;  // No tab in URL; the dispatcher defaults to 'dashboard'.
	}

	// v6.18.0: one resolver for GET 301 + POST PRG. null = already canonical.
	$dest = sn_admin_canonical_destination( $requested_tab, $requested_sub );
	if ( null === $dest ) {
		return;
	}

	$url = admin_url( 'admin.php?page=sn-theme-options&tab=' . rawurlencode( $dest['tab'] ) );
	if ( ! empty( $dest['sub'] ) ) {
		$url .= '&sub=' . rawurlencode( $dest['sub'] );
	}
	if ( ! empty( $dest['anchor'] ) ) {
		$url .= '#sn-sec-' . rawurlencode( $dest['anchor'] );
	}

	// Raw header() because wp_safe_redirect() strips the fragment. Same-host admin
	// URL built from a fixed allow-listed destination, no user input → safe.
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
	// v6.18.0: retired top-tab slugs (Phase 2 IA) resolve to their old tab name so
	// the redirect map can 301 them to the new home (sn-automation → automation → connections).
	$retired = array( 'sn-automation' => 'automation' );
	if ( isset( $retired[ $slug ] ) ) {
		return $retired[ $slug ];
	}
	return 'dashboard';
}
