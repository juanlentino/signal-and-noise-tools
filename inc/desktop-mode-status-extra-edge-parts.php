<?php
/**
 * Signal & Noise Tools: the extra status rows, the pure edge rules (the per-install allowance, the dashboard path pattern, the installs-on-a-day count, the install explanation, the dashboard count).
 *
 * Split from inc/desktop-mode-status-extra.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 5xx one install may account for: WordPress answers 503 to every uncached
 * request while it installs (a few seconds), and an open admin tab's polls
 * meet it once or twice, counted again when the rights Worker forwarded them.
 * Oct 8-13 2026 measured about 2 to 2.5 per install.
 */
const SNT_EDGE_5XX_PER_INSTALL = 3;

/**
 * The owner's own dashboard paths: wp-admin, the OpenStation shell and the
 * REST routes its cards poll. One list, read by the dashboard split and the
 * install verdict alike.
 */
const SNT_EDGE_DASHBOARD_PATH_RE = '#^/(wp-admin/|openstation/|wp-json/(desktop-mode|openstation|wp-abilities)/|wp-json/signal-noise/v1/desktop/)#';

/**
 * Plugin and theme installs recorded on a UTC day (the rollup's day), from
 * the deploy history (inc/deploy-history.php). PURE.
 *
 * @param string $day     YYYY-MM-DD.
 * @param array  $history Deploy history rows, each with an ISO `created_at`.
 * @return int
 */
function snt_desktop_edge_installs_on( $day, array $history ) {
	$n = 0;
	foreach ( $history as $row ) {
		if ( is_array( $row ) && 0 === strpos( (string) ( $row['created_at'] ?? '' ), (string) $day ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Whether that day's installs explain every one of its 5xx: at least one
 * install, no more than SNT_EDGE_5XX_PER_INSTALL each, and every 5xx a 503
 * (maintenance) on the dashboard (SNT_EDGE_DASHBOARD_PATH_RE), the live poll,
 * wp-cron, wp-login or the favicon. A 500, a 522, any 5xx on a front-end page
 * or a public REST route is never explained; neither is a day whose stored
 * rows do not account for the whole count. PURE.
 *
 * The origin status may be "-": a request the Worker forwarded has none.
 * The deploy history's admin_init self-heal can record an install that never
 * entered maintenance, or on a later UTC day than its 503s; that errs toward
 * a looser allowance on the recorded day, or amber on the real one.
 *
 * ponytail: the rollup has no per-error time, so "during the install" is
 * "on the same UTC day"; an install just before midnight explains nothing
 * the next day.
 *
 * @param int        $total    The day's 5xx.
 * @param array|null $rows     The day's err_path_asker rows ("<asker> <edge> <origin> <cache> <path>").
 * @param int|null   $installs Installs that day; null when unknown.
 * @return bool
 */
function snt_desktop_edge_install_explained( $total, $rows, $installs ) {
	$total = (int) $total;
	if ( $total < 1 || ! is_int( $installs ) || $installs < 1 || $total > SNT_EDGE_5XX_PER_INSTALL * $installs || ! is_array( $rows ) ) {
		return false;
	}
	$sum = 0;
	foreach ( $rows as $r ) {
		if ( ! preg_match( '/^\S+ (\d+) (?:\d+|-) \S+ (.+)$/', (string) ( is_array( $r ) ? ( $r['value'] ?? '' ) : '' ), $m ) ) {
			return false;
		}
		// Beyond the dashboard: the live poll, wp-cron, wp-login and the favicon
		// (an open tab asks for it). A public REST route a reader loads is not.
		$quiet = preg_match( SNT_EDGE_DASHBOARD_PATH_RE, $m[2] ) || preg_match( '#^/(wp-json/signal-noise/v1/live(/|$)|wp-cron\.php|wp-login\.php|favicon\.ico$)#', $m[2] );
		if ( '503' !== $m[1] || ! $quiet ) {
			return false;
		}
		$sum += (int) ( $r['requests'] ?? 0 );
	}
	return $sum >= $total;
}

/**
 * How many of a day's 5xx hit the owner's own dashboard: wp-admin, the
 * OpenStation shell and its session ping, and the abilities and desktop
 * reads its cards poll (2026-10-05: about 25 of the week's 30). Counted from
 * the day's top failing paths, so a dashboard path outside that list counts
 * as "everything else"; the split never overstates the dashboard. PURE.
 *
 * @param mixed $paths The day's `paths`: list of { value, requests }.
 * @return int|null Null when the day carries no paths to split by.
 */
function snt_desktop_edge_dashboard_count( $paths ) {
	if ( ! is_array( $paths ) ) {
		return null;
	}
	$n = 0;
	foreach ( $paths as $p ) {
		$path = (string) ( is_array( $p ) ? ( $p['value'] ?? '' ) : '' );
		if ( preg_match( SNT_EDGE_DASHBOARD_PATH_RE, $path ) ) {
			$n += (int) ( $p['requests'] ?? 0 );
		}
	}
	return $n;
}
