<?php
/**
 * Signal & Noise — Monitoring → Analytics tab.
 *
 * Native wp-admin surface (no theme vocabulary) for the first-party edge
 * analytics. Reads only the durable rollup accessors (never AE) so it never
 * blocks; shows a config/empty state until the Cloudflare creds + worker land.
 * v5.5.0: a persistent header (controls → separation → delta cards → trend) over
 * a WP-native tab strip (Content · Technology · Geography · Engagement · Quality · Events);
 * each tab lazily fetches only its own panels' data.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_RANGES = array( 7, 30, 90, 365 );

/**
 * Whitelist the ?sn_range GET value to a supported window; default 7.
 *
 * @param mixed $raw
 * @return int|string 7 | 30 | 90 | 365 | 'all'
 */
function snt_analytics_resolve_range( $raw ) {
	if ( 'all' === (string) $raw ) {
		return 'all';
	}
	$n = (int) $raw;
	return in_array( $n, SN_ANALYTICS_RANGES, true ) ? $n : 7;
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
const SN_ANALYTICS_VIEWS = array(
	'content'    => 'Content',
	'campaigns'  => 'Campaigns',
	'posts'      => 'Posts',
	'technology' => 'Technology',
	'geography'  => 'Geography',
	'engagement' => 'Engagement',
	'visits'     => 'Visits',
	'quality'    => 'Quality',
	'events'     => 'Events',
	'edge'       => 'Traffic & edge',
	'login-defense' => 'Login defense',
);

/**
 * Whitelist the ?sn_view GET value to a known tab; default 'content'.
 *
 * @param mixed $raw
 * @return string
 */
function snt_analytics_resolve_view( $raw ) {
	$v = (string) $raw;
	return isset( SN_ANALYTICS_VIEWS[ $v ] ) ? $v : 'content';
}

// Views that render their own complete chrome (own KPI cards, trend, range control)
// and therefore opt OUT of the shared pageview header. login-defense brings its
// own KPI/trend. 'edge' deliberately keeps the shared header it ships today
// (changing it would be a regression).
const SN_ANALYTICS_OWNS_CHROME = array( 'login-defense' );

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
	// sn_drill is stripped too: a drill is scoped to the view that owns the dim, so
	// switching tabs clears it rather than carrying a stale "Country = US" onto a tab
	// with no Country table (the panel render is also dim/view-gated as a backstop).
	$base = remove_query_arg( array( 'sn_view', 'sn_range', 'sn_class', 'sn_from', 'sn_to', 'sn_drill', 'sn_lg_range' ), add_query_arg( array() ) );
	if ( '' === (string) $base ) {
		$base = admin_url( 'index.php?page=sn-analytics' );
	}
	echo '<nav class="nav-tab-wrapper sn-an-view-tabs" aria-label="Analytics views">';
	foreach ( SN_ANALYTICS_VIEWS as $slug => $label ) {
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
 * UTC (gmdate) to align with AE's toStartOfDay() buckets. $now is injectable
 * for deterministic tests. When $range is 'all', $from is the earliest day in
 * the rollup table (via sn_analytics_min_day()).
 *
 * @param int|string $range Days as int (7|30|90|365) or 'all'.
 * @param int|null   $now   Unix timestamp anchor (defaults to now).
 * @return array{0:string,1:string} [$from, $to]
 */
function snt_analytics_range_dates( $range, $now = null ) {
	$now = ( null === $now ) ? time() : (int) $now;
	$to  = gmdate( 'Y-m-d', $now );
	if ( 'all' === $range ) {
		$from = function_exists( 'sn_analytics_min_day' ) ? sn_analytics_min_day() : $to;
		return array( $from, $to );
	}
	$days = max( 1, (int) $range );
	$from = gmdate( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
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
 * Concrete [$from,$to] (inclusive, YYYY-MM-DD, UTC) for a named preset. $now
 * injectable for deterministic tests.
 *
 * @param string   $preset 'ytd' | 'last-month' | 'last-quarter' | 'prev-year'.
 * @param int|null $now    Unix anchor.
 * @return array{0:string,1:string}
 */
function snt_analytics_preset_dates( $preset, $now = null ) {
	$now   = ( null === $now ) ? time() : (int) $now;
	$today = gmdate( 'Y-m-d', $now );
	$y     = (int) gmdate( 'Y', $now );
	$mo    = (int) gmdate( 'n', $now );
	switch ( (string) $preset ) {
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

/**
 * Validate + clamp a user custom window. Rejects malformed dates (→ null), swaps a
 * reversed pair, clamps `to`/`from` to today, and `from` to sn_analytics_min_day()
 * when available. Returns [$from,$to] or null (caller falls back to the default).
 *
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now Unix anchor.
 * @return array{0:string,1:string}|null
 */
function snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now = null ) {
	$now   = ( null === $now ) ? time() : (int) $now;
	$today = gmdate( 'Y-m-d', $now );
	$from  = trim( (string) $from_raw );
	$to    = trim( (string) $to_raw );
	if ( ! snt_analytics_is_ymd( $from ) || ! snt_analytics_is_ymd( $to ) ) {
		return null;
	}
	if ( $from > $to ) { // ISO YYYY-MM-DD sorts lexically
		$tmp = $from; $from = $to; $to = $tmp;
	}
	if ( $to > $today ) {
		$to = $today;
	}
	if ( $from > $today ) {
		$from = $today;
	}
	if ( function_exists( 'sn_analytics_min_day' ) ) {
		$min = sn_analytics_min_day();
		if ( snt_analytics_is_ymd( $min ) && $from < $min ) {
			$from = $min;
		}
	}
	if ( $from > $to ) {
		return null;
	}
	return array( $from, $to );
}

/**
 * Single resolver for the dashboard/export window. Returns [$range_token,$from,$to]
 * — $range_token is the scalar used for URL/display (7|30|90|365|'all'|preset|'custom'),
 * $from/$to the concrete inclusive window. Presets + custom resolve here; int/'all'
 * delegate to the unchanged resolve_range + range_dates.
 *
 * @param mixed    $range_raw
 * @param string   $from_raw
 * @param string   $to_raw
 * @param int|null $now
 * @return array{0:int|string,1:string,2:string}
 */
function snt_analytics_resolve_window( $range_raw, $from_raw = '', $to_raw = '', $now = null ) {
	$range_raw = (string) $range_raw;
	$presets   = array( 'ytd', 'last-month', 'last-quarter', 'prev-year' );
	if ( in_array( $range_raw, $presets, true ) ) {
		list( $from, $to ) = snt_analytics_preset_dates( $range_raw, $now );
		return array( $range_raw, $from, $to );
	}
	if ( 'custom' === $range_raw ) {
		$win = snt_analytics_resolve_custom_window( $from_raw, $to_raw, $now );
		if ( null !== $win ) {
			return array( 'custom', $win[0], $win[1] );
		}
		$range = 7;
	} else {
		$range = snt_analytics_resolve_range( $range_raw );
	}
	list( $from, $to ) = snt_analytics_range_dates( $range, $now );
	return array( $range, $from, $to );
}

/**
 * The settings page the dashboard's "Configure →" link points at (and where the
 * creds form lives): Monitoring → Analytics. Built on the page=sn-theme-options
 * route so the form POST hits the allow-listed admin-post handler.
 */
function snt_analytics_settings_url() {
	return admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' );
}

/**
 * Render the comprehensive READ-ONLY analytics dashboard. Lives on the native WP
 * Dashboard → Analytics page (inc/analytics-dashboard-page.php); the credential
 * settings are split out to Monitoring → Analytics
 * (snt_analytics_render_settings_section). No <h1> heading or settings form here
 * — the page chrome owns the title, and the read view carries no form.
 *
 * v5.5.0 layout: a persistent header (controls + separation + delta cards +
 * trend) above a WP-native tab strip (Content · Technology · Geography ·
 * Engagement · Quality · Events). The active tab (?sn_view=, whitelisted) lazily fetches
 * ONLY its own panels' data. Every dimension/derived panel renders its own empty
 * state until the edge data accrues (worker v1.1.0 — no backfill).
 *
 * Note: period-over-period deltas are suppressed for the 'all' range. Trend
 * granularity is daily for windows ≤90 days, weekly beyond.
 */
function snt_analytics_render_dashboard() {
	// Read-only display params — sanitized + whitelisted (no nonce: not state-changing).
	$range_raw = isset( $_GET['sn_range'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_range'] ) ) : '7';
	$from_raw  = isset( $_GET['sn_from'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_from'] ) ) : '';
	$to_raw    = isset( $_GET['sn_to'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_to'] ) ) : '';
	$class     = snt_analytics_resolve_class( isset( $_GET['sn_class'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_class'] ) ) : 'human' );
	$view      = snt_analytics_resolve_view( isset( $_GET['sn_view'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_view'] ) ) : 'content' );

	// Config gate: empty notice + a link to the settings page (the form lives there now).
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		snt_analytics_render_empty( 'unconfigured' );
		echo '<p><a class="button button-primary" href="' . esc_url( snt_analytics_settings_url() ) . '">Configure analytics &rarr;</a></p>';
		return;
	}

	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	// Granularity from the resolved window day-count — works for every range incl.
	// presets/custom, and is behaviour-identical to the old (int)$range for fixed ranges.
	$gran_days   = (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
	$granularity = sn_analytics_granularity( $gran_days );

	// Views that own their chrome (login-defense) skip the shared pageview header
	// entirely — they bring their own KPI cards, trend, and range control and would
	// otherwise stack pageview stats above their own. Computed once; gates the three
	// pageview-only regions below (fetches, header render, post-switch empty hint).
	$owns_chrome = snt_analytics_view_owns_chrome( $view );

	snt_analytics_render_error(); // AE diagnostic (admins only) — always, every view.

	// ── Persistent header (shared-chrome tabs only). v8.5.0: the whole frame
	// (controls, class strip, full Overview + rail grid, uptime detail panel,
	// the still-firing after-Overview seam) lives in
	// inc/analytics-header-region.php; it returns the range totals so the
	// tail empty-hint below keeps its signal.
	$totals = array();
	if ( ! $owns_chrome ) {
		$totals = snt_analytics_render_header_region( $view, $range, $class, $from, $to, $granularity );
	} elseif ( 'login-defense' === $view && function_exists( 'sn_login_defense_render_header' ) ) {
		// The chrome-owning view renders its OWN header (range + Overview + breakdown)
		// here, ABOVE the tabs, so the frame matches the pageview views (no tab-bar jump).
		sn_login_defense_render_header();
	}

	// ── Tabs + the active view's panels. Each view fetches ONLY its own data,
	// so a tab switch is a lighter query set, not just CSS show/hide.
	snt_analytics_render_view_tabs( $view, $range, $class, $from, $to );

	echo '<div class="sn-an-view">';

	// Cross-tab drill-down: ?sn_drill=<dim>:<value> → "Top pages where <dim>=<value>"
	// (on-demand AE, whitelisted + cached). The panel renders ONLY on the view that
	// owns the drilled dim — so a stale drill carried onto another tab shows nothing
	// (no orphan panel above a view with no such table).
	$sn_drill_dims = array(
		'technology' => array( 'browser', 'os', 'device', 'protocol', 'tls' ),
		'geography'  => array( 'country', 'city', 'region', 'network', 'colo', 'timezone' ),
		'content'    => array( 'referrer' ),
	);
	$sn_drill = isset( $_GET['sn_drill'] ) ? sn_analytics_drilldown_parse( sanitize_text_field( wp_unslash( $_GET['sn_drill'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET filter on an admin report, no state change.
	if ( null !== $sn_drill && in_array( $sn_drill[0], $sn_drill_dims[ $view ] ?? array(), true ) ) {
		$drill_note = ( strtotime( (string) $to ) - strtotime( (string) $from ) > 90 * DAY_IN_SECONDS )
			? '(reflects the last ~90 days — Analytics Engine raw retention)'
			: '';
		snt_analytics_render_drilldown_panel( $sn_drill[0], $sn_drill[1], sn_analytics_drilldown( $sn_drill[0], $sn_drill[1], $from, $to, $class ), $drill_note );
	}

	switch ( $view ) {
		case 'posts':
			// Post-lifecycle view: hero + trajectory + catalog + velocity/decay.
			// Manages its own layout (hero/trajectory full-width, then a grid).
			snt_analytics_render_posts_view( sn_analytics_posts_bundle() );
			// v8.11.0 (A4): the catalogue-wide decay census + refresh queue.
			snt_analytics_render_lifecycle_section( sn_analytics_posts_lifecycle() );
			break;

		case 'technology':
			snt_analytics_render_view_technology( $from, $to, $class, $granularity ); // v8.5.0: inc/analytics-view-technology.php
			break;

		case 'geography':
			snt_analytics_render_view_geography( $from, $to, $class ); // v8.5.0: inc/analytics-view-geography.php
			break;

		case 'edge':
			// Server-side Cloudflare edge analytics (GraphQL) — not class-segmented
			// and not drillable (no per-page AE join); its own dormant gate.
			snt_edge_render_view( $from, $to );
			break;

		case 'engagement':
			snt_analytics_render_view_engagement( $from, $to, $class ); // v8.5.0: inc/analytics-view-engagement.php
			break;

		case 'quality':
			snt_analytics_render_view_quality( $from, $to, $class, $granularity ); // v8.5.0: inc/analytics-view-quality.php
			break;

		case 'events':
			snt_analytics_render_view_events( $from, $to ); // v8.5.0: inc/analytics-view-events.php
			break;

		case 'visits':
			snt_analytics_render_view_sessions( $from, $to, $class );
			break;

		case 'login-defense':
			sn_login_defense_render_body();
			break;

		case 'campaigns':
			// v9.29.0: UTM campaign attribution — inc/analytics-view-campaigns.php.
			snt_analytics_render_view_campaigns( $from, $to, $class, $granularity );
			break;

		case 'content':
		default:
			// v8.5.0: regrouped landing — inc/analytics-view-content.php.
			snt_analytics_render_view_content( $from, $to, $class, $granularity );
			break;
	}
	echo '</div>';

	// Empty hint when configured but the tables are still dormant — keyed on the
	// always-fetched totals, so it shows on whichever tab you land on first. Gated on
	// ! $owns_chrome: $totals is unset for chrome-owning views (login-defense brings
	// its own empty states), so reading it there would warn + show a false notice.
	if ( ! $owns_chrome && (int) ( $totals['views'] ?? 0 ) === 0 ) {
		echo '<p class="sn-an-empty sn-an-empty--note">No analytics data in this range yet. New data appears within ~15 minutes of a visit once the worker is live.</p>';
	}
}

/**
 * The Monitoring → Analytics settings section. Open-and-wide Phase 2 (v6.44.0):
 * a `.sn-2up` two-column layout that splits the active settings (credentials +
 * own-visit exclusion) from the read-only edge-worker reference (live version,
 * one-time Worker setup). The `analytics` leaf is marked
 * `'wide' => true` in inc/admin-tabs-data.php, so the wrapper emits a bare
 * `.sn-section` and each column owns its own `.sn-fieldset` chrome here (the
 * wide-leaf card-ownership rule). The forms post on the page=sn-theme-options
 * route (the Monitoring sub-tab nav guarantees that slug), so the existing
 * admin-post handler processes analytics_save / _test / _exclude_save / _export
 * unchanged — each <form> keeps its own nonce + sn_action button.
 */
function snt_analytics_render_settings_section() {
	echo '<div class="sn-2up">';

	// ── Left: active settings (credentials + own-visit exclusion). ──
	echo '<div class="sn-fieldset">';
	echo '<p class="sn-an-settings-help">First-party analytics credentials. The comprehensive read-only dashboard lives under <strong>Dashboard &rarr; Analytics</strong>. <a href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">View dashboard &rarr;</a></p>';
	snt_analytics_render_credentials();
	// The "Exclude my own visits" role allow-list is a primary analytics setting,
	// so it sits with the credentials in the active-settings column (v6.23.0).
	if ( function_exists( 'snt_analytics_render_exclusion' ) ) {
		snt_analytics_render_exclusion();
	}
	echo '</div>';

	// ── Right: edge-worker reference (what's live → how to deploy). ──
	echo '<div class="sn-fieldset">';
	// The deployed edge-Worker version, read live from /_sn/version (guarded +
	// SWR-cached) — "what's live" above the manual setup steps.
	if ( function_exists( 'sn_worker_version_render_card' ) ) {
		sn_worker_version_render_card();
	}
	snt_analytics_render_worker_setup();
	echo '</div>';

	echo '</div>'; // .sn-2up
}

/**
 * Unconfigured notice shown above the settings form when creds are missing.
 * Points users to the form rendered immediately after this notice, and mentions
 * the wp-config-constant alternative for those who prefer it.
 *
 * @param string $reason 'unconfigured'.
 */
function snt_analytics_render_empty( $reason ) {
	echo '<div class="notice notice-info notice-alt inline"><p><strong>Analytics isn\'t receiving data yet.</strong> ';
	echo 'Add your Cloudflare read credentials below to connect the dashboard. You can also set ';
	echo '<code>SN_CF_ANALYTICS_TOKEN</code> / <code>SN_CF_ACCOUNT_ID</code> in <code>wp-config.php</code> ';
	echo '(see <em>Cloudflare Worker setup</em> below).</p></div>';
}

/**
 * Surface the last AE read error (admins only) so a blank dashboard is debuggable.
 */
function snt_analytics_render_error() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'sn_analytics_last_error' ) ) {
		return;
	}
	$err = sn_analytics_last_error();
	if ( ! $err || ! is_array( $err ) ) {
		return;
	}
	$code = isset( $err['code'] ) && (int) $err['code'] > 0 ? ( 'HTTP ' . (int) $err['code'] ) : 'Network error';
	echo '<div class="notice notice-error notice-alt inline"><p><strong>Analytics read failed.</strong> ' . esc_html( $code );
	if ( ! empty( $err['url'] ) ) {
		echo ' from <code>' . esc_html( (string) $err['url'] ) . '</code>';
	}
	if ( ! empty( $err['message'] ) ) {
		echo '<br>' . esc_html( (string) $err['message'] );
	}
	echo '</p></div>';
}
