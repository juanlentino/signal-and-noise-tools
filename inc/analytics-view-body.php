<?php
/**
 * Shared Analytics report dispatch for classic and OpenStation surfaces.
 *
 * @package SignalNoiseTools
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a report using its canonical readers and presentation.
 *
 * @param string $view Report slug.
 * @param string $from Start date.
 * @param string $to End date.
 * @param string $class Traffic class.
 * @param string $granularity Bucket size.
 * @param string $range Range token.
 * @param string $compare Comparison mode.
 * @return void
 */
function snt_analytics_render_view_body( $view, $from, $to, $class, $granularity, $range, $compare ) {
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

		case 'search':
			// Takes NO range arguments on purpose: Search Console data is a stored
			// rolling window, not a per-range query. Passing $from/$to would imply
			// a filter this view cannot honour. inc/analytics-view-search.php
			snt_analytics_render_view_search();
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
			// v8.5.0 regrouped content view (the landing until v9.68.0) —
			// inc/analytics-view-content.php.
			snt_analytics_render_view_content( $from, $to, $class, $granularity );
			break;

		case 'overview':
		default:
			// v9.68.0: the wired landing surface (the v9.67.0 mock, graduated)
			// — inc/analytics-view-overview.php. Default on purpose: it is
			// also where resolve_view sends every unknown/retired slug.
			// Part 4: the body also receives the range token (doorway links
			// carry the window exactly as the tab strip does) and the compare
			// mode (change-vs-prior chips follow the header control).
			snt_analytics_render_view_overview( $from, $to, $class, $range, $compare );
			break;
	}
}
