<?php
/**
 * Signal & Noise — Analytics tab partials (barrel).
 *
 * The Analytics page's render functions used to live in this one ~1.3k-line
 * monolith. v8.9.x split them by panel/domain into the analytics-render-*.php
 * files this manifest loads — mirroring the v8.5.0 analytics-view-*.php
 * extraction. This file stays the single include point (the plugin and the
 * tests both `require` it), so nothing downstream had to change: pulling in this
 * barrel still defines every snt_analytics_render_* function exactly as before.
 *
 * Native wp-admin markup; every dynamic value is escaped at the point of output
 * (no PHPCS EscapeOutput exclusion needed). See inc/analytics-admin.php for the
 * orchestrator that consumes these partials.
 *
 * Load order below puts the shared primitives (analytics-panels.php chrome +
 * analytics-render-helpers.php pure helpers) first, then the domain renderers.
 * Each domain file also require_once's the primitives it needs, so it is
 * loadable on its own; the ordering here is documentation, not a hard dependency.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';         // panel chrome + the empty-fold collector the renderers emit into
require_once __DIR__ . '/analytics-render-helpers.php'; // fmt_time + smooth_path — the two cross-domain primitives

require_once __DIR__ . '/analytics-render-controls.php';     // range/class toolbar, custom range, class-separation notice
require_once __DIR__ . '/analytics-render-overview.php';     // KPI strip + views trend + delta badges (the Overview panel)
require_once __DIR__ . '/analytics-render-distribution.php'; // referrer categories, scroll/time bands, hour heatmap, percentiles
require_once __DIR__ . '/analytics-render-quality.php';      // traffic-quality stacked bar + bot-share trend
require_once __DIR__ . '/analytics-render-anomalies.php';    // cross-metric engagement-anomalies panel (v8.9.0 arc)
require_once __DIR__ . '/analytics-render-tables.php';       // top pages, dimension tables, low-engagement, entry/exit, inline sparkline
require_once __DIR__ . '/analytics-render-events.php';       // custom-events + event-property tables
require_once __DIR__ . '/analytics-render-geography.php';    // world-map choropleth + SVG recolor transform
require_once __DIR__ . '/analytics-render-drilldown.php';    // cross-tab dimension drill-down panel
require_once __DIR__ . '/analytics-render-settings.php';     // settings-hub partials: credentials, exclusion, worker setup, pipeline strip, tuning, mirrors, filter reference
