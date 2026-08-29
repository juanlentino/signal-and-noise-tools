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
			'cap'      => 'view_stats',
			'blurb'    => __( 'Who is reading, on-site and by feed.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => __( 'Traffic', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-analytics-summary',
					'fields'  => array(
						array( 'path' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ) ),
						array( 'path' => 'pageview_visits', 'label' => __( 'Visits', 'signal-and-noise-tools' ) ),
					),
				),
				array(
					'label'   => __( 'Feed', 'signal-and-noise-tools' ),
					'ability' => 'signal-noise/get-rss-stats',
					'fields'  => array(
						array( 'path' => 'data.windows.7.uniques', 'label' => __( 'Subscribers, 7d', 'signal-and-noise-tools' ) ),
						array( 'path' => 'data.windows.30.uniques', 'label' => __( 'Subscribers, 30d', 'signal-and-noise-tools' ) ),
						array( 'path' => 'data.last_request_relative', 'label' => __( 'Last request', 'signal-and-noise-tools' ) ),
					),
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
			'cap'      => 'manage_options',
			'blurb'    => __( 'Crawler readership. Never summed with the human half.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/get-machine-readers-summary',
					'fields'  => array(
						array( 'path' => 'total', 'label' => __( 'Reads', 'signal-and-noise-tools' ) ),
						array( 'path' => 'ai_training', 'label' => __( 'Declared AI-training', 'signal-and-noise-tools' ) ),
						array( 'path' => 'ai_rights', 'label' => __( 'Rights-file reads', 'signal-and-noise-tools' ) ),
						array( 'path' => 'families.0.family', 'label' => __( 'Top family', 'signal-and-noise-tools' ) ),
					),
				),
			),
			'links'    => array(
				array( 'label' => __( 'Machine Readers', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=monitoring&sub=machine-readers' ),
			),
		),
		array(
			'id'       => 'sn_dash_ops',
			'title'    => __( 'S&N Operations', 'signal-and-noise-tools' ),
			'cap'      => 'manage_options',
			'blurb'    => __( 'What is shipped, and whether the edge took it.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/get-deploy-status',
					'fields'  => array(
						array( 'path' => 'theme.current', 'label' => __( 'Theme', 'signal-and-noise-tools' ) ),
						array( 'path' => 'plugin.current', 'label' => __( 'Plugin', 'signal-and-noise-tools' ) ),
						array( 'path' => 'last_deploy', 'label' => __( 'Last deploy', 'signal-and-noise-tools' ) ),
					),
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
			'cap'      => 'manage_options',
			'blurb'    => __( 'Anchor status for the signed Notes.', 'signal-and-noise-tools' ),
			'sections' => array(
				array(
					'label'   => '',
					'ability' => 'signal-noise/anchor-status',
					'fields'  => array(
						array( 'path' => 'confirmed', 'label' => __( 'Anchored', 'signal-and-noise-tools' ) ),
						array( 'path' => 'total', 'label' => __( 'Notes', 'signal-and-noise-tools' ) ),
						array( 'path' => 'pending.length', 'label' => __( 'Pending', 'signal-and-noise-tools' ) ),
					),
				),
			),
			'links'    => array(
				array( 'label' => __( 'Provenance', 'signal-and-noise-tools' ), 'url' => $opt . '&tab=tools&sub=provenance' ),
			),
		),
	) );
}
