<?php
/**
 * Signal & Noise Tools: analytics-admin.php, the range, class and view
 * resolvers, the view constants, the view tabs and the preset dates.
 *
 * Split from inc/analytics-admin.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelist the ?sn_range GET value to a supported window; default 7.
 *
 * @param mixed $raw
 * @return int|string 7 | 14 | 30 | 90 | 365 | 'all'
 */
function snt_analytics_resolve_range( $raw ) {
	if ( 'all' === (string) $raw ) {
		return 'all';
	}
	$n = (int) $raw;
	return in_array( $n, SN_ANALYTICS_RANGES, true ) ? $n : 7;
}

/**
 * Is $raw a member of the range vocabulary? The admin resolver above keeps
 * its 7-day fallback for a URL parameter; the ability path refuses instead
 * (#1239) — an unknown range silently answered as 7 days let a model report
 * 60-day figures that were 7-day.
 *
 * @since 14.0.2
 * @param mixed $raw
 * @return bool
 */
function snt_analytics_range_is_valid( $raw ) {
	if ( 'all' === (string) $raw ) {
		return true;
	}
	if ( ! is_int( $raw ) && ! ( is_string( $raw ) && 1 === preg_match( '/^\d+$/', $raw ) ) ) {
		return false;
	}
	return in_array( (int) $raw, SN_ANALYTICS_RANGES, true );
}

/**
 * Whitelist the ?sn_class GET value to a known class; default human.
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_class( $raw ) {
	$c = (string) $raw;
	return in_array( $c, SN_ANALYTICS_CLASSES, true ) ? $c : 'human';
}

// The tabbed views of the read-only dashboard, in display order (slug → label).
// The detailed dimension/derived panels live under one of these; the headline
// (controls + delta cards + trend) is persistent above the tabs.
// v9.68.0: 'overview' leads — the wired landing surface (the v9.67.0 preview
// mock, graduated) and the DEFAULT tab; Content is a normal tab now.
const SN_ANALYTICS_VIEWS = array(
	'overview'   => 'Overview',
	'content'    => 'Content',
	'campaigns'  => 'Campaigns',
	'posts'      => 'Posts',
	'technology' => 'Technology',
	'geography'  => 'Geography',
	'engagement' => 'Engagement',
	// v9.65.0 units fix: LABEL says what the tab counts (within-day SESSIONS,
	// live session engine) vs the Overview headline's visitor-day "Visits".
	// The SLUG stays 'visits' — dispatch, drilldown links, and ?sn_view= key
	// on it (renaming the slug breaks them; renaming the label is free).
	'visits'     => 'Sessions',
	'quality'    => 'Quality',
	// R6b: the pre-click half of the funnel. Its own tab because the metrics have
	// NO first-party counterpart — the collector can never see a search query.
	'search'     => 'Search',
	'events'     => 'Events',
	'edge'       => 'Traffic & edge',
	// The machine views sit together after Search and Traffic & edge: who reads
	// (Machine readers) and how each count is known (Reader evidence). Both
	// own their chrome, a 7/14/30/90-day window over the edge sensor.
	'machine-readers' => 'Machine readers',
	'reader-evidence' => 'Reader evidence',
	'login-defense' => 'Login defense',
);

/**
 * The view registry accessor. v9.68.0: the v9.67.0 flag branch is gone — the
 * "Overview (preview)" experiment graduated into the permanent 'overview'
 * slug inside SN_ANALYTICS_VIEWS itself, so the effective registry IS the
 * const again. Kept as the one lookup seam the tab strip + resolver share.
 *
 * @since 9.67.0
 * @return array<string,string> slug => label, display order.
 */
function snt_analytics_views() {
	return SN_ANALYTICS_VIEWS;
}

/**
 * Whitelist the ?sn_view GET value to a known tab; default 'overview' — the
 * wired landing surface (v9.68.0; was 'content'). Retired slugs
 * ('intelligence', 'overview-lab') fall back here too.
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_view( $raw ) {
	$v     = (string) $raw;
	$views = snt_analytics_views();
	return isset( $views[ $v ] ) ? $v : 'overview';
}

// Views that render their own complete chrome (own KPI cards, trend, range control)
// and therefore opt OUT of the shared pageview header. login-defense brings its
// own KPI/trend. 'edge' deliberately keeps the shared header it ships today
// (changing it would be a regression). v9.68.0: the wired 'overview' INHERITS
// the shared header (the v9.67.0 mock owned its chrome only because its static
// Headline panel would have doubled the real KPI strip — the wired tab drops
// that panel and lets the shared Overview card be the headline).
const SN_ANALYTICS_OWNS_CHROME = array( 'login-defense', 'machine-readers', 'reader-evidence' );

/**
 * True iff $view brings its own chrome, so the shared pageview header (controls +
 * Overview postbox + the post-switch empty hint) is suppressed for it.
 *
 * @param string $view
 * @return bool
 */
function snt_analytics_view_owns_chrome( $view ) {
	return in_array( (string) $view, SN_ANALYTICS_OWNS_CHROME, true );
}

/** Views that read the edge sensor (its own credentials), not Analytics Engine. */
const SN_ANALYTICS_SENSOR_VIEWS = array( 'machine-readers', 'reader-evidence' );

/**
 * True iff $view needs Analytics Engine configured. The machine views read the
 * rights-signals sensor, so the Analytics Engine gate does not hold them back;
 * every people view stays behind it.
 *
 * @param string $view View slug.
 * @return bool
 */
function snt_analytics_view_needs_ae( $view ) {
	return ! in_array( (string) $view, SN_ANALYTICS_SENSOR_VIEWS, true );
}

/**
 * THE view-reset param list (v9.68.0 review 2, F2): the window/view/view-local
 * params a tab-switch strips from the current URL before re-adding its own
 * carry. ONE source of truth — the tab strip below AND the Overview doorway
 * builder (snt_analytics_overview_tab_doorway, which builds its hrefs exactly
 * like tab links) both consume it, so a future param cannot silently diverge
 * the two lists (parity-pinned at the source level).
 *
 * sn_compare is deliberately ABSENT: the active compare mode rides along.
 *
 * @since 9.68.0
 * @return string[]
 */
function snt_analytics_view_reset_params() {
	return array( 'sn_view', 'sn_range', 'sn_class', 'sn_from', 'sn_to', 'sn_drill', 'sn_event_prop', 'sn_lg_range' );
}

/**
 * Render the WP-native tab strip for the dashboard views. Each tab link is the
 * current page with sn_view set + the active sn_range/sn_class preserved, so
 * switching tabs keeps the window + class filter. Mirrors the SN top-tab nav
 * (`.nav-tab-wrapper`/`.nav-tab`) for native styling.
 *
 * @param string     $active Active view slug.
 * @param int|string $range  Active range in days or 'all'.
 * @param string     $class  Active class (preserved across tabs).
 */
function snt_analytics_render_view_tabs( $active, $range, $class, $from = '', $to = '' ) {
	// sn_drill + sn_event_prop are stripped too: both are view-local filters scoped to
	// the view that owns the dim/property, so switching tabs clears them rather than
	// carrying a stale "Country = US" or event-property choice onto a tab with no
	// matching table (the panel render is also dim/view-gated as a backstop). This is
	// the ONE reset point in the param-carry matrix (v9.39.0 D3) — every other builder
	// preserves these across window/class/compare changes.
	$base = remove_query_arg( snt_analytics_view_reset_params(), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = snt_analytics_page_url();
	}
	echo '<nav class="nav-tab-wrapper sn-an-view-tabs" aria-label="Analytics views">';
	foreach ( snt_analytics_views() as $slug => $label ) {
		$url   = add_query_arg( array( 'sn_view' => $slug ) + snt_analytics_window_args( $range, $class, $from, $to ), $base );
		$is_on = ( $slug === $active );
		// aria-current inlined (not a pre-built $aria var) so the escaping stays
		// at the point of output and EscapeOutput can verify it.
		if ( $is_on ) {
			echo '<a class="nav-tab nav-tab-active" href="' . esc_url( $url ) . '" aria-current="page">' . esc_html( $label ) . '</a>';
		} else {
			echo '<a class="nav-tab" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
	}
	echo '</nav>';
}

/**
 * Inclusive [$from,$to] YYYY-MM-DD window ending on the anchor day.
 * Keyed on the SITE-LOCAL day (wp_timezone), the same day the daily rollup
 * table is bucketed by. A UTC "today" ran one day ahead from 20:00 to 24:00
 * America/New_York and the window dropped its oldest local day. $now is
 * injectable for deterministic tests. When $range is 'all', $from is the
 * earliest day in the rollup table (via sn_analytics_min_day()).
 *
 * @param int|string $range Days as int (7|14|30|90|365) or 'all'.
 * @param int|null   $now   Unix timestamp anchor (defaults to now).
 * @return array{0:string,1:string} [$from, $to]
 */
function snt_analytics_range_dates( $range, $now = null ) {
	$now = ( null === $now ) ? time() : (int) $now;
	$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$day = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
	$to  = $day->format( 'Y-m-d' );
	if ( 'all' === $range ) {
		$from = function_exists( 'sn_analytics_min_day' ) ? sn_analytics_min_day() : $to;
		return array( $from, $to );
	}
	$days = max( 1, (int) $range );
	$from = $day->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	return array( $from, $to );
}

/** True iff $s is a real YYYY-MM-DD date (format + checkdate). */
function snt_analytics_is_ymd( $s ) {
	if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $s, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/**
 * Concrete [$from,$to] (inclusive, YYYY-MM-DD, site-local day) for a named preset. $now
 * injectable for deterministic tests.
 *
 * @param string   $preset 'this-week' | 'this-month' | 'this-quarter' | 'ytd' | 'last-month' | 'last-quarter' | 'prev-year'.
 * @param int|null $now    Unix anchor.
 * @return array{0:string,1:string}
 */
function snt_analytics_preset_dates( $preset, $now = null ) {
	$now = ( null === $now ) ? time() : (int) $now;
	// Re-anchor on the SITE-LOCAL calendar day (as UTC midnight) so the UTC date
	// math below reads the local day, matching the daily rollup (#1961).
	list( , $local ) = snt_analytics_range_dates( 1, $now );
	$now   = (int) strtotime( $local . ' 00:00:00 UTC' );
	$today = gmdate( 'Y-m-d', $now );
	$y     = (int) gmdate( 'Y', $now );
	$mo    = (int) gmdate( 'n', $now );
	switch ( (string) $preset ) {
		case 'this-week':
			$dow = (int) gmdate( 'N', $now ); // ISO Monday=1, matching the weekly bucket's Monday floor
			return array( gmdate( 'Y-m-d', $now - ( $dow - 1 ) * DAY_IN_SECONDS ), $today );
		case 'this-month':
			return array( gmdate( 'Y-m-01', $now ), $today );
		case 'this-quarter':
			return array( sprintf( '%04d-%02d-01', $y, ( (int) ceil( $mo / 3 ) - 1 ) * 3 + 1 ), $today );
		case 'ytd':
			return array( sprintf( '%04d-01-01', $y ), $today );
		case 'prev-year':
			return array( sprintf( '%04d-01-01', $y - 1 ), sprintf( '%04d-12-31', $y - 1 ) );
		case 'last-month':
			$end = gmmktime( 0, 0, 0, $mo, 1, $y ) - DAY_IN_SECONDS; // last day of the prior month
			return array( gmdate( 'Y-m-01', $end ), gmdate( 'Y-m-d', $end ) );
		case 'last-quarter':
			$cur_q_first = ( (int) ceil( $mo / 3 ) - 1 ) * 3 + 1;                          // 1|4|7|10
			$end         = gmmktime( 0, 0, 0, $cur_q_first, 1, $y ) - DAY_IN_SECONDS;       // last day of the prior quarter
			$pe_y        = (int) gmdate( 'Y', $end );
			$pe_q_first  = ( (int) ceil( (int) gmdate( 'n', $end ) / 3 ) - 1 ) * 3 + 1;
			return array( sprintf( '%04d-%02d-01', $pe_y, $pe_q_first ), gmdate( 'Y-m-d', $end ) );
		default:
			return array( $today, $today );
	}
}

