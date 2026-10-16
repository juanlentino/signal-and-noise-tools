<?php
/**
 * S&N Dashboard — Connections → Scheduled, painted from the kit.
 *
 * The classic leaf (inc/schedule-admin.php, `sn_admin_render_scheduled_content_section()`)
 * folds two producers — the fragment/queue rows (sn_schedule_all()) and native
 * WordPress future posts (sn_schedule_future_posts()) — into one read-mostly
 * status wall: a glance hero, a derived "version swaps" table, a capped,
 * soonest-first, foldable row list, and two per-fragment ops (Run now,
 * Re-purge) plus one per-swap op (Run swap now). Same reads, same three
 * forms, same handlers; see connections-scheduled-content-parts.php for the
 * row/section builders (kept separate to stay under the leaf-file budget).
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/connections-scheduled-content-parts.php';
require_once __DIR__ . '/connections-scheduled-content-lists.php';

/**
 * The module's data, read the way the classic leaf reads it: both producers,
 * the derived swap pairs, and the soonest-first capped slice (IA SCHED1).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os (unused — read-mostly).
 * @return array{fragments:array,posts:array,total:int,pairs:array,shown:array,remainder:int}
 */
function scheduled_content_data( array $ctx ) {
	unset( $ctx );
	$fragments = function_exists( 'sn_schedule_all' ) ? (array) \sn_schedule_all() : array();
	$posts     = function_exists( 'sn_schedule_future_posts' ) ? (array) \sn_schedule_future_posts() : array();
	$total     = count( $fragments ) + count( $posts );
	$pairs     = function_exists( 'sn_schedule_swap_pairs' ) ? (array) \sn_schedule_swap_pairs( $fragments ) : array();
	$ordered   = function_exists( 'sn_admin_schedule_ordered_rows' ) ? \sn_admin_schedule_ordered_rows( $fragments, $posts ) : array();
	$cap       = defined( 'SN_SCHEDULE_DISPLAY_CAP' ) ? (int) \SN_SCHEDULE_DISPLAY_CAP : 25;
	$shown     = array_slice( $ordered, 0, $cap );
	return array(
		'fragments' => $fragments,
		'posts'     => $posts,
		'total'     => $total,
		'pairs'     => $pairs,
		'shown'     => $shown,
		'remainder' => $total - count( $shown ),
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_scheduled_content( array $ctx ) {
	$d = scheduled_content_data( $ctx );

	$out = '';
	if ( $d['total'] > 0 && function_exists( 'snt_schedule_glance_cards' ) ) {
		$out .= scheduled_content_glance_html( \snt_schedule_glance_cards( $d['fragments'], $d['posts'] ) );
	}

	// 2026-10-12 (queue phase 2): clusters and gaps, as the classic twin.
	// 2026-10-13 (item 2): a suggested slot per draft, from the same read.
	if ( function_exists( 'sn_content_queue_schedule_read' ) ) {
		$schedule = \sn_content_queue_schedule_read();
		// 2026-10-14 (item 6): how far ahead the schedule covers.
		$out     .= scheduled_content_runway_html( \sn_content_queue_runway_read( $schedule ) );
		// 2026-10-16: Cadence and Suggested slots share one row (both grow
		// with the queue); Runway is one sentence, so it stays a full-width
		// line above them rather than a short box beside two tall ones.
		$out     .= \snt_kit_grid(
			array(
				scheduled_content_cadence_html( \sn_content_queue_cadence_read( $schedule ) ),
				scheduled_content_slots_html( \sn_content_queue_slot_read( $schedule ) ),
			)
		);
	}

	$out .= scheduled_content_swaps_html( $d['pairs'] );

	$out .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Hand-authored content scheduled to reveal or hide on a date (signal-noise/scheduled blocks), plus WordPress posts and pages waiting to auto-publish. Times shown in the site timezone.', 'signal-and-noise-tools' ) ) . '</p>';

	if ( 0 === $d['total'] ) {
		$out .= \snt_kit_empty( __( 'No scheduled content. Add a signal-noise/scheduled block to a page, or schedule a post for the future, and it will appear here.', 'signal-and-noise-tools' ) );
		return $out;
	}

	// 15.10.1: two producers, two shapes. The native future posts carry no
	// op (core publishes them), so they take the house table (<os-table>, the
	// Cron leaf's shape: sortable, filterable, every column its own width)
	// instead of seven cramped list cells. The fragments keep the list: each
	// row carries a live <form> (Run now / Re-purge), which a data-driven
	// table cannot hold (see the parts file's header).
	$post_rows = array();
	$frag_rows = '';
	foreach ( $d['shown'] as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( 'post' === (string) ( $entry['kind'] ?? '' ) ) {
			$post_rows[] = scheduled_content_post_table_row( (array) ( $entry['row'] ?? array() ) );
		} else {
			$frag_rows .= scheduled_content_fragment_row_html( (array) ( $entry['row'] ?? array() ) );
		}
	}

	// 2026-10-11 (no folds): two open sections, each with its finding
	// (connections-scheduled-content-lists.php), then the cap's "+N more".
	if ( array() !== $post_rows ) {
		$out .= scheduled_content_posts_html( $post_rows, count( $d['posts'] ) );
	}
	if ( '' !== $frag_rows ) {
		$out .= scheduled_content_fragments_html( $d['fragments'], $frag_rows );
	}
	return $out . scheduled_content_more_html( $d['remainder'] );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/scheduled-content'] = __NAMESPACE__ . '\\paint_connections_scheduled_content';
		return $painters;
	}
);
