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

	if ( array() !== $post_rows ) {
		$columns = array(
			array( 'key' => 'title', 'label' => __( 'Title', 'signal-and-noise-tools' ), 'filter' => 'text' ),
			array( 'key' => 'type', 'label' => __( 'Type', 'signal-and-noise-tools' ) ),
			array( 'key' => 'publishes', 'label' => __( 'Publishes', 'signal-and-noise-tools' ) ),
			array( 'key' => 'in', 'label' => __( 'In', 'signal-and-noise-tools' ) ),
			array( 'key' => 'id', 'label' => __( 'ID', 'signal-and-noise-tools' ), 'align' => 'end' ),
		);
		/* translators: %d: scheduled posts and pages */
		$posts_heading = sprintf( _n( '%d scheduled post', '%d scheduled posts', count( $post_rows ), 'signal-and-noise-tools' ), count( $post_rows ) );
		$out .= \snt_kit_section(
			$posts_heading,
			\snt_kit_table( $columns, $post_rows, array( 'empty' => __( 'No scheduled posts.', 'signal-and-noise-tools' ) ) ),
			__( 'WordPress publishes these itself on the date shown (site timezone), soonest first. Open one from the Posts window to move it.', 'signal-and-noise-tools' )
		);
	}

	if ( '' !== $frag_rows ) {
		/* translators: %d: scheduled fragments */
		$frag_heading = sprintf( _n( '%d scheduled fragment', '%d scheduled fragments', count( $d['fragments'] ), 'signal-and-noise-tools' ), count( $d['fragments'] ) );
		$out .= \snt_kit_tag( 'os-disclosure', array( 'heading' => $frag_heading, 'open' => true ), '<ul class="snt-list">' . scheduled_content_row_head_html() . $frag_rows . '</ul>' );
	}

	if ( $d['remainder'] > 0 ) {
		$out .= '<p class="snt-hint snt-schedule-remainder">' . \snt_kit_esc(
			sprintf(
				/* translators: %d: hidden row count */
				_n( '+%d more scheduled item, sorted soonest-first — the tail is the furthest out.', '+%d more scheduled items, sorted soonest-first — the tail is the furthest out.', $d['remainder'], 'signal-and-noise-tools' ),
				(int) $d['remainder']
			)
		) . '</p>';
	}

	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/scheduled-content'] = __NAMESPACE__ . '\\paint_connections_scheduled_content';
		return $painters;
	}
);
