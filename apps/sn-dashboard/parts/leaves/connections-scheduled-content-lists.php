<?php
/**
 * S&N Dashboard: Connections > Scheduled, the two open lists.
 *
 * Split out of connections-scheduled-content.php (2026-10-11, no folds). The
 * fragments list sat in an always-open disclosure headed by its count; both
 * lists are open sections now, each under a short heading with one finding
 * sentence built from the rows, and the soonest-first cap (IA SCHED1) is
 * still said out loud by the "+N more" line under them.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The native scheduled posts: the finding and the house table (15.10.1, the
 * Cron leaf's shape: core publishes these, so no row carries an op).
 *
 * @param array<int,array<string,string>> $post_rows The shown posts, soonest first (scheduled_content_post_table_row()).
 * @param int                             $total     Every scheduled post and page, shown or not.
 * @return string
 */
function scheduled_content_posts_html( array $post_rows, $total ) {
	$soonest = (string) ( $post_rows[0]['in'] ?? '' );
	$finding = sprintf(
		/* translators: %s: scheduled posts and pages. */
		_n( '%s post or page waits to auto-publish', '%s posts and pages wait to auto-publish', (int) $total, 'signal-and-noise-tools' ),
		number_format_i18n( (int) $total )
	);
	if ( '' !== $soonest && \sn_admin_schedule_nothing_pending() !== $soonest ) {
		/* translators: %s: when the (soonest) one publishes, e.g. "in 3 hours". */
		$finding .= sprintf( _n( '; it publishes %s', '; the soonest publishes %s', (int) $total, 'signal-and-noise-tools' ), $soonest );
	}
	$columns = array(
		array( 'key' => 'title', 'label' => __( 'Title', 'signal-and-noise-tools' ), 'filter' => 'text' ),
		array( 'key' => 'type', 'label' => __( 'Type', 'signal-and-noise-tools' ) ),
		array( 'key' => 'publishes', 'label' => __( 'Publishes', 'signal-and-noise-tools' ) ),
		array( 'key' => 'in', 'label' => __( 'In', 'signal-and-noise-tools' ) ),
		array( 'key' => 'id', 'label' => __( 'ID', 'signal-and-noise-tools' ), 'align' => 'end' ),
	);
	return \snt_kit_section(
		__( 'Scheduled posts', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( $finding . '.' ) . '</p>'
			. \snt_kit_table( $columns, $post_rows, array( 'empty' => __( 'No scheduled posts.', 'signal-and-noise-tools' ) ) ),
		__( 'WordPress publishes these itself on the date shown (site timezone), soonest first. Open one from the Posts window to move it.', 'signal-and-noise-tools' )
	);
}

/**
 * The scheduled fragments: the finding (every fragment, by status) and the
 * list rows, which stay list rows because each carries live op forms.
 *
 * @param array<int,array<string,mixed>> $fragments Every fragment row, shown or not.
 * @param string                         $rows_html The shown rows, painted.
 * @return string
 */
function scheduled_content_fragments_html( array $fragments, $rows_html ) {
	$by_status = array();
	foreach ( $fragments as $row ) {
		$status               = (string) ( $row['status'] ?? 'queued' );
		$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;
	}
	arsort( $by_status ); // most first; ties keep the rows' order.
	$parts = array();
	foreach ( $by_status as $status => $n ) {
		$parts[] = number_format_i18n( $n ) . ' ' . $status;
	}
	$finding = sprintf(
		/* translators: 1: scheduled fragments, 2: per-status counts, e.g. "28 queued, 2 error". */
		_n( '%1$s scheduled fragment: %2$s.', '%1$s scheduled fragments: %2$s.', count( $fragments ), 'signal-and-noise-tools' ),
		number_format_i18n( count( $fragments ) ),
		implode( ', ', $parts )
	);
	return \snt_kit_section(
		__( 'Scheduled fragments', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( $finding ) . '</p>'
			. '<div class="snt-ledger snt-ledger--wrap"><ul class="snt-list snt-list--ledger">' . scheduled_content_row_head_html() . $rows_html . '</ul></div>'
	);
}

/**
 * The "+N more" line under both lists: the cap runs over the soonest-first
 * union of the two producers, so the tail it counts is the furthest out.
 *
 * @param int $remainder Items past the cap.
 * @return string
 */
function scheduled_content_more_html( $remainder ) {
	return \snt_kit_more(
		(int) $remainder,
		/* translators: %s: scheduled items not shown. */
		_n( '+%s more scheduled item, sorted soonest-first; the tail is the furthest out.', '+%s more scheduled items, sorted soonest-first; the tail is the furthest out.', (int) $remainder, 'signal-and-noise-tools' )
	);
}

/**
 * The Runway box (2026-10-14, queue phase 2, item 6): how far ahead the
 * schedule covers, the count under it. The classic twin says the same
 * (sn_admin_render_queue_runway()).
 *
 * @param array $read From sn_content_queue_runway_read().
 * @return string
 */
function scheduled_content_runway_html( array $read ) {
	$inner = '<p class="snt-prose">' . \snt_kit_esc( (string) ( $read['text'] ?? '' ) ) . '</p>';
	return \snt_kit_section( __( 'Runway', 'signal-and-noise-tools' ), $inner, (string) ( $read['count'] ?? '' ) );
}

/**
 * The Cadence box (2026-10-12, queue phase 2): every cluster and unplanned
 * gap from the latest published note through the scheduled ones, one sentence each with its word first, under
 * the rule. The classic twin says the same (sn_admin_render_queue_cadence()).
 *
 * @param array<int,array<string,mixed>> $warnings From sn_content_queue_cadence().
 * @return string
 */
function scheduled_content_cadence_html( array $warnings ) {
	$rows = array();
	foreach ( $warnings as $w ) {
		$rows[] = array( 'label' => (string) ( $w['text'] ?? '' ), 'dot' => 'warn' );
	}
	$inner = array() === $rows
		? '<p class="snt-prose">' . \snt_kit_esc( \sn_content_queue_cadence_clear() ) . '</p>'
		: \snt_kit_list( $rows );
	return \snt_kit_section( __( 'Cadence', 'signal-and-noise-tools' ), $inner, \sn_content_queue_cadence_rule() );
}

/**
 * The Suggested slots box (2026-10-13, queue phase 2, item 2): one sentence
 * per draft, the draft's title a door to its editor, under the rule. A
 * suggestion only. The classic twin says the same (sn_admin_render_queue_slots()).
 *
 * @param array $read From sn_content_queue_slot_read().
 * @return string
 */
function scheduled_content_slots_html( array $read ) {
	$empty = (string) ( $read['empty'] ?? '' );
	if ( '' !== $empty ) {
		$inner = '<p class="snt-prose">' . \snt_kit_esc( $empty ) . '</p>';
	} else {
		$items = '';
		foreach ( (array) ( $read['slots'] ?? array() ) as $s ) {
			$title  = (string) ( $s['title'] ?? '' );
			$url    = (string) ( $s['edit_url'] ?? '' );
			$items .= '<li>' . ( '' !== $url ? \snt_kit_link( $title, $url ) : \snt_kit_esc( $title ) ) . ': ' . \snt_kit_esc( (string) ( $s['text'] ?? '' ) ) . '</li>';
		}
		$inner = '<ul class="snt-prose">' . $items . '</ul>';
		if ( ! empty( $read['more'] ) ) {
			$inner .= '<p class="snt-prose">' . \snt_kit_esc( \sn_content_queue_slot_more( (int) $read['more'] ) ) . '</p>';
		}
	}
	return \snt_kit_section( __( 'Suggested slots', 'signal-and-noise-tools' ), $inner, \sn_content_queue_slot_rule() );
}
