<?php
/**
 * S&N Analytics host — the view.
 *
 * The window's body, in the order inc/analytics-dashboard-page.php prints it:
 * `<div class="wrap">`, `<h1>Analytics</h1>`, the flash notice, and then the
 * whole dashboard — `snt_analytics_render_dashboard()`, captured, not
 * reimplemented. That one call is the tab strip, the insights band, the
 * toolbar, the header region and whichever of the thirteen views is active, so
 * every view lands in the window with no view-level change and a view added
 * tomorrow lands for free.
 *
 * TWO THINGS ARE LENT, not one. The page reads `$_GET` (the nine `sn_*`
 * params) — and it also builds every link it prints on
 * `add_query_arg( array() )`, which is `$_SERVER['REQUEST_URI']`. A dispatch's
 * REQUEST_URI is the REST route, so lending only the query would leave every
 * tab and pill pointing at `/wp-json/…`, which the rewrite reads as an
 * external link and leaves clickable. Both are put back in a `finally`.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Analytics;

use OpenStation\App\Os;
use OpenStation\App\State;

// Direct access, unless a standalone host is booting on bare PHP.
if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Run the dashboard under the query AND the request URI it would have had.
 *
 * @param array<string,string> $get The `$_GET` from `snt_os_analytics_get()`.
 * @return string The HTML the page echoed.
 */
function capture( array $get ) {
	$uri = \snt_os_analytics_request_uri( $get );
	$had = array_key_exists( 'REQUEST_URI', $_SERVER );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read ONLY to put back byte-for-byte; sanitizing it here would change what the dispatch is restored to, which is the bug this line prevents.
	$prev = $had ? $_SERVER['REQUEST_URI'] : null;
	if ( '' !== $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
	}
	try {
		return \snt_os_host_capture( 'snt_analytics_render_dashboard', $get );
	} finally {
		if ( '' !== $uri ) {
			if ( $had ) {
				$_SERVER['REQUEST_URI'] = $prev;
			} else {
				unset( $_SERVER['REQUEST_URI'] );
			}
		}
	}
}

/**
 * The dashboard, captured, kept and rewritten — in that order.
 *
 * `snt_os_host_keep_forms()` runs BEFORE the rewrite because the rewrite is
 * what reads the marker: the export form must come out of the pass still a
 * form, with an action and a new tab, and everything else must come out a
 * dispatch.
 *
 * The own-page list is exactly this page's slug. `sn-theme-options` is
 * deliberately NOT in it — the unconfigured gate's "Configure analytics →" and
 * the Measurement → Analytics links are a DIFFERENT window's surface, so they
 * open as admin windows through `door`, the same way the Dashboard host treats
 * a link to this page. A cross-host `go` is the doors program's, not this
 * build's.
 *
 * @param State $state Window state.
 * @return string HTML, already escaped by the renderers that produced it.
 */
function dashboard_html( State $state ) {
	if ( ! function_exists( 'snt_analytics_render_dashboard' ) || ! function_exists( 'snt_os_host_capture' ) ) {
		return '';
	}
	$html = capture( \snt_os_analytics_get( $state ) );
	$html = \snt_os_host_keep_forms(
		$html,
		\snt_os_analytics_keep_actions(),
		function_exists( 'snt_analytics_page_url' ) ? (string) \snt_analytics_page_url() : ''
	);
	return \snt_os_host_rewrite( $html, array( page_slug() ) );
}
