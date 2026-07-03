<?php
/**
 * Signal & Noise Tools — cache freshness indicator (dashboard glance card).
 *
 * A small "Caches" card on the Dashboard first-glance grid, filled client-side
 * by assets/freshness-dot.js. For each cache-critical route the JS fetches the
 * canonical URL and a cache-busted variant, extracts the combined-CSS hash
 * (sn-styles-<hash>.css) from each, and compares — a mismatch means the edge is
 * serving a stale render for that route. This is the same signal the manual
 * sweep uses ("expect <hash> everywhere") and would have caught the 2026-07-02
 * pruned-CSS 404 incident.
 *
 * Why client-side: the check runs in the admin's browser, a true external
 * vantage that resolves to Cloudflare and sees the real public cache state. The
 * origin box cannot probe its own public edge reliably (origin lockdown 403s
 * origin-direct), and the edge caches HTML with no logged-in bypass so a
 * logged-in admin still sees the public cached copy.
 *
 * This is stage 1 (a client-side CSS-hash heuristic). When the verified-purge
 * pipeline lands, the same card is upgraded to read the server-side report.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_FRESHNESS_CARD_ID = 'snt-freshness-card';

/**
 * Cache-critical routes the freshness dot checks. Paths with a leading slash,
 * relative to the site root. Filterable so the set grows without a code edit.
 * Defaults mirror the post-save CF purge list (home, /notes/, /provenance/).
 *
 * @return string[]
 */
function snt_freshness_routes() {
	$routes = array( '/', '/notes/', '/provenance/' );
	$routes = (array) apply_filters( 'snt_freshness_routes', $routes );
	$routes = array_filter( $routes, static function ( $r ) {
		return is_string( $r ) && '' !== $r;
	} );
	return array_values( array_unique( $routes ) );
}

/**
 * The freshness glance card. Server renders a neutral "Checking…" state with the
 * JS-target id and NO pill; assets/freshness-dot.js fills the result client-side.
 *
 * @return array{label:string,value:string,id:string}
 */
function snt_freshness_card() {
	return array(
		'label' => 'Caches',
		'value' => 'Checking…',
		'id'    => SNT_FRESHNESS_CARD_ID,
	);
}

/**
 * Enqueue the freshness-dot script on any S&N admin page. The JS is a no-op
 * unless the freshness card (#snt-freshness-card) is on the page, so gating to
 * the SN page group (not the exact dashboard tab) is safe — the same pattern as
 * inc/cron-dashboard-admin.php. Named (not a closure) so it is unit-testable.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 * @return void
 */
function snt_freshness_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' )
		|| ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_register_script(
		'sn-freshness-dot',
		plugins_url( 'assets/freshness-dot.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION,
		true
	);
	wp_localize_script( 'sn-freshness-dot', 'sntFreshness', array(
		'routes' => array_map( static function ( $p ) { return home_url( $p ); }, snt_freshness_routes() ),
		'cardId' => SNT_FRESHNESS_CARD_ID,
	) );
	wp_enqueue_script( 'sn-freshness-dot' );
}
add_action( 'admin_enqueue_scripts', 'snt_freshness_enqueue' );
