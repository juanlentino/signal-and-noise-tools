<?php
/**
 * Signal & Noise Tools — the fallback dashboard boxes on index.php.
 *
 * FOUR BOXES, ONE PER SUBJECT. "dashboard-widget sprawl" is Declined, standing
 * (docs/superpowers/specs/2026-07-01-stack-audit-abilities-consolidation-design.md:85),
 * and v8.3.0 + v11.30.0 both folded boxes AWAY. The owner reopened it on
 * 2026-08-29 with a constraint that keeps the spirit of those folds: while
 * OpenStation's command palette is severed upstream (WordPress/openstation#705)
 * the Classic Admin home is the surface actually in use, so the ten desktop
 * widgets are grouped by WHAT THEY SHOW rather than mirrored one for one.
 *
 *   Audience     ← sn-site-views + sn-rss-subscribers     "who is reading?"
 *   Machines     ← sn-machine-readers                     "which machines?"
 *   Operations   ← sn-deploy-status + sn-cache + sn-cron  "what shipped, did it land?"
 *   Provenance   ← sn-anchors                             "are the Notes anchored?"
 *
 * Machines stays out of Audience deliberately: human and machine readership are
 * never summed (see inc/desktop-mode-widgets.php on sn-machine-readers), and two
 * boxes encode that rule where one box would invite the addition.
 *
 * The fifth box, sn_dashboard, is inc/dash-widget.php's and is untouched — it
 * owns the verdict/health glance. This module registers only its own four, which
 * is why tests/dash-widget.php's "only one THIS MODULE adds" pin still holds.
 *
 * ZERO COST ON RENDER, non-negotiable. index.php renders on every admin login,
 * so every render here prints an instant shell with em-dash placeholders and the
 * live reads happen client-side through readonly abilities — the same discipline
 * assets/uptime-status.js has followed since v8.2.0. A box therefore degrades to
 * labelled em dashes plus working deep links if the hydrator never runs.
 *
 * @package SignalNoiseTools
 * @since 13.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/dash-widgets-render.php';

/**
 * The box definitions. DATA, not code — the render lane walks this and nothing
 * branches per box.
 *
 * `sections[].fields[].path` is a dotted path into the ability's own output
 * schema; tests/dash-widgets.php pins every `ability` against the real
 * wp_register_ability() calls, because a typoed name renders an empty shell
 * forever and reports nothing.
 *
 * @since 13.30.0
 * @return array<int,array<string,mixed>>
 */
function snt_dwx_boxes() {
	$opt = 'admin.php?page=sn-theme-options';
	return (array) apply_filters( 'snt_dwx_boxes', array(
		array(
			'id'       => 'sn_dash_audience',
			'title'    => __( 'S&N Audience', 'signal-and-noise-tools' ),
			'caps'     => array( 'view_stats', 'manage_options' ),
			'blurb'    => __( 'Who is reading, on-site and by feed.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					// Server-side and free: snt_dashboard_measurement_data() is a
					// DB-local read and the ONLY source here that carries a prior
					// period, so this is where the delta can be honest.
					'label'   => '',
					'signals' => 'snt_dwx_traffic_signals',
				),
				array(
					'label'   => '',
					'ability' => 'signal-noise/get-analytics-summary',
					'fields'  => array(
						array(
							'path'    => 'pageview_visits',
							'label'   => __( 'Visits', 'signal-and-noise-tools' ),
							/* translators: %s: views-per-visit ratio */
							'compare' => array( 'template' => __( '%s views per visit', 'signal-and-noise-tools' ), 'path' => 'view_visit_ratio' ),
						),
						array(
							'path'    => 'unique_visitor_days',
							'label'   => __( 'Visitor-days', 'signal-and-noise-tools' ),
							/* translators: %s: count of visitor-days with zero pageviews */
							'compare' => array( 'template' => __( '%s with no pageview', 'signal-and-noise-tools' ), 'path' => 'viewless_visits' ),
						),
					),
				),
				array(
					'label'   => __( 'Feed', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-rss-stats',
					'fields'  => array(
						array(
							'path'    => 'data.windows.7.uniques',
							'label'   => __( 'Subscribers 7d', 'signal-and-noise-tools' ),
							/* translators: %s: unique feed subscribers over 30 days */
							'compare' => array( 'template' => __( '%s over 30d', 'signal-and-noise-tools' ), 'path' => 'data.windows.30.uniques' ),
						),
						array(
							'path'    => 'data.windows.7.total',
							'label'   => __( 'Feed fetches 7d', 'signal-and-noise-tools' ),
							/* translators: %s: human-readable relative time of the last feed request */
							'compare' => array( 'template' => __( 'last %s', 'signal-and-noise-tools' ), 'path' => 'data.last_request_relative' ),
						),
					),
				),
			),
			'lists'    => array(
				array(
					'label'   => __( 'Feed windows', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-rss-stats',
					'path'    => 'data.windows',
					'keys'    => array( '1' => '24h', '7' => '7d', '30' => '30d' ),
					/* translators: %s: total feed fetches in the window */
					'item'    => array( 'value' => 'uniques', 'sub' => 'total', 'sub_template' => __( '%s fetches', 'signal-and-noise-tools' ) ),
				),
			),
			'links'    => array(
				array( 'label' => __( 'Analytics', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=monitoring&sub=analytics' ),
				array( 'label' => __( 'RSS', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=monitoring&sub=rss' ),
			),
		),
		array(
			'id'       => 'sn_dash_machines',
			'title'    => __( 'S&N Machine Readers', 'signal-and-noise-tools' ),
			'caps'     => array( 'manage_options' ),
			'blurb'    => __( 'Crawler readership. Never summed with the human half.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/get-machine-readers-summary',
					'fields'  => array(
						array(
							'path'    => 'total',
							'label'   => __( 'Reads', 'signal-and-noise-tools' ),
							/* translators: %s: the sensor window in days */
							'compare' => array( 'template' => __( 'over %s days', 'signal-and-noise-tools' ), 'path' => 'days' ),
						),
						array(
							'path'    => 'ai_training',
							'label'   => __( 'AI-training', 'signal-and-noise-tools' ),
							/* translators: %s: AI-training reads as a percentage of all reads */
							'compare' => array( 'template' => __( '%s of reads', 'signal-and-noise-tools' ), 'percent_of' => 'total', 'path' => 'ai_training' ),
						),
						array(
							'path'    => 'families.0.hits',
							'label'   => __( 'Top family', 'signal-and-noise-tools' ),
							'compare' => array( 'template' => '%s', 'path' => 'families.0.family' ),
						),
						array(
							'path'    => 'ai_rights',
							'label'   => __( 'Rights-file reads', 'signal-and-noise-tools' ),
							/* translators: %s: total machine reads in the window */
							'compare' => array( 'template' => __( 'of %s total', 'signal-and-noise-tools' ), 'path' => 'total' ),
						),
					),
				),
			),
			'lists'    => array(
				array(
					'label'   => __( 'Top families', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-machine-readers-summary',
					'path'    => 'families',
					'limit'   => 5,
					'item'    => array( 'label' => 'family', 'value' => 'hits' ),
				),
				array(
					'label'   => __( 'AI-training reads by surface', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-machine-readers-summary',
					'path'    => 'ai_surfaces',
					'limit'   => 4,
					'item'    => array( 'label' => 'surface', 'value' => 'hits' ),
				),
			),
			'links'    => array(
				array( 'label' => __( 'Machine Readers', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=monitoring&sub=machine-readers' ),
			),
		),
		array(
			'id'       => 'sn_dash_ops',
			'title'    => __( 'S&N Operations', 'signal-and-noise-tools' ),
			'caps'     => array( 'manage_options' ),
			'blurb'    => __( 'What is shipped, and whether the edge took it.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/get-deploy-status',
					'fields'  => array(
						array(
							'path'    => 'theme.current',
							'label'   => __( 'Theme', 'signal-and-noise-tools' ),
							'compare' => array( 'template' => '%s', 'path' => 'theme.state' ),
						),
						array(
							'path'    => 'plugin.current',
							'label'   => __( 'Plugin', 'signal-and-noise-tools' ),
							'compare' => array( 'template' => '%s', 'path' => 'plugin.state' ),
						),
						array(
							'path'    => 'last_deploy',
							'label'   => __( 'Last deploy', 'signal-and-noise-tools' ),
							'compare' => array( 'template' => '%s', 'path' => 'last_deploy_component' ),
						),
					),
				),
			),
			'lists'    => array(
				array(
					'label'   => __( 'Workers', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-deploy-status',
					'path'    => 'workers',
					'limit'   => 5,
					/* translators: %s: the latest released worker version */
					'item'    => array( 'label' => 'label', 'value' => 'live', 'sub' => 'latest', 'sub_template' => __( 'latest %s', 'signal-and-noise-tools' ) ),
				),
			),
			'actions'  => array(
				array(
					'label'   => __( 'Purge caches', 'signal-and-noise-tools' ),
					'busy'    => __( 'Purging&hellip;', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/purge-all-caches',
				),
			),
			'links'    => array(
				array( 'label' => __( 'Dashboard', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=dashboard' ),
				array( 'label' => __( 'Cache', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=connections&sub=cloudflare' ),
				array( 'label' => __( 'Cron', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=connections&sub=cron' ),
			),
		),
		array(
			'id'       => 'sn_dash_provenance',
			'title'    => __( 'S&N Provenance', 'signal-and-noise-tools' ),
			'caps'     => array( 'manage_options' ),
			'blurb'    => __( 'Anchor status for the signed Notes.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/anchor-status',
					'fields'  => array(
						array(
							'path'    => 'confirmed',
							'label'   => __( 'Anchored', 'signal-and-noise-tools' ),
							/* translators: %s: total number of Notes */
							'compare' => array( 'template' => __( 'of %s notes', 'signal-and-noise-tools' ), 'path' => 'total' ),
						),
						array(
							'path'    => 'pending.length',
							'label'   => __( 'Pending', 'signal-and-noise-tools' ),
							'compare' => array( 'template' => __( 'awaiting Bitcoin', 'signal-and-noise-tools' ) ),
						),
					),
				),
			),
			'lists'    => array(
				array(
					'label'   => __( 'Pending', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/anchor-status',
					'path'    => 'pending',
					'limit'   => 5,
					'empty'   => __( 'No anchors pending.', 'signal-and-noise-tools' ),
					// `confirmations: null` is "not recorded" and must never render
					// as 0/6 — the desktop widget's rule, carried over.
					'item'    => array( 'label' => 'title', 'format' => 'confirmations' ),
				),
			),
			'actions'  => array(
				array(
					'label'   => __( 'Sweep now', 'signal-and-noise-tools' ),
					'busy'    => __( 'Sweeping&hellip;', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/anchor-sweep',
				),
			),
			'links'    => array(
				array( 'label' => __( 'Provenance', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=tools&sub=provenance' ),
			),
		),
	) );
}
