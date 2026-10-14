<?php
/**
 * Signal & Noise Tools: the suggested slots' reads and the classic twin's box
 * (split from inc/abilities-content-queue-slot.php, 2026-10-13). The pure
 * function and its sentences live there.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The drafts this user can edit, newest edit first, capped at
 * SN_CONTENT_QUEUE_DRAFTS (review on #2031: a contributor reads their own,
 * never a colleague's working title), and the site's draft count. Shared by
 * the content-queue ability and the Scheduled twins, so both list the same
 * drafts.
 *
 * @return array{rows:array,count:?int,more:?int}
 */
function sn_content_queue_draft_read() {
	$others = current_user_can( 'edit_others_posts' );
	$draft  = get_posts( array( 'post_type' => 'post', 'suppress_filters' => false, 'orderby' => array( 'modified' => 'DESC', 'ID' => 'DESC' ), 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'post_status' => 'draft', 'order' => 'DESC', 'numberposts' => SN_CONTENT_QUEUE_DRAFTS ) + ( $others ? array() : array( 'author' => get_current_user_id() ) ) );
	$draft  = array_filter( is_array( $draft ) ? $draft : array(), static fn( $p ) => $p instanceof WP_Post && current_user_can( 'edit_post', $p->ID ) );
	$rows   = array_values( array_filter( array_map( 'sn_content_queue_row', $draft ) ) );
	$counts = wp_count_posts( 'post' );
	$count  = is_object( $counts ) && isset( $counts->draft ) ? (int) $counts->draft : null;
	return array(
		'rows'  => $rows,
		'count' => $count,
		// "+N more" is the site's count less the list, so it is said only to
		// someone whose list could hold any draft; otherwise not read.
		'more'  => $others && null !== $count ? max( 0, $count - count( $rows ) ) : null,
	);
}

/**
 * The schedule both Scheduled twin boxes read (Cadence and Suggested slots),
 * read once per paint (review on #2054: the boxes each ran the future and
 * published queries): every scheduled note's time and the newest published.
 *
 * @return array{future:int[],published:?int}
 */
function sn_content_queue_schedule_read() {
	$base   = array( 'post_type' => 'post', 'suppress_filters' => false, 'orderby' => 'date', 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
	$queue  = get_posts( $base + array( 'post_status' => 'future', 'order' => 'ASC', 'numberposts' => -1 ) );
	$last   = get_posts( $base + array( 'post_status' => 'publish', 'order' => 'DESC', 'numberposts' => 1 ) );
	$future = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $queue ) ? $queue : array() ) ) );
	$done   = array_values( array_filter( array_map( 'sn_content_queue_row', is_array( $last ) ? $last : array() ) ) );
	return array(
		'future'    => array_map( 'intval', array_column( $future, 'ts' ) ),
		'published' => isset( $done[0]['ts'] ) ? (int) $done[0]['ts'] : null,
	);
}

/**
 * The slots for the Connections › Scheduled twins, read the way the queue
 * ability reads them.
 *
 * @param array|null $schedule From sn_content_queue_schedule_read(), or null to read it.
 * @return array{slots:array,empty:string,more:?int}
 */
function sn_content_queue_slot_read( $schedule = null ) {
	$schedule = is_array( $schedule ) ? $schedule : sn_content_queue_schedule_read();
	$drafts   = sn_content_queue_draft_read();
	return array(
		'slots' => sn_content_queue_slots( $drafts['rows'], $schedule['future'], $schedule['published'], time(), wp_timezone(), sn_content_queue_planned_gaps() ),
		'empty' => sn_content_queue_slot_empty( $drafts['count'], count( $drafts['rows'] ) ),
		'more'  => $drafts['more'],
	);
}

/**
 * The classic Connections › Scheduled page's Suggested slots box: the same
 * rule and sentences as the native leaf (scheduled_content_slots_html()).
 * Open, never folded; read-only (the only action is the draft's edit link).
 *
 * @param array $read From sn_content_queue_slot_read().
 * @return void
 */
function sn_admin_render_queue_slots( array $read ) {
	echo '<section aria-labelledby="sn-queue-slots"><h3 id="sn-queue-slots">' . esc_html__( 'Suggested slots', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-field-helper">' . esc_html( sn_content_queue_slot_rule() ) . '</p>';
	if ( '' !== (string) ( $read['empty'] ?? '' ) ) {
		echo '<p>' . esc_html( (string) $read['empty'] ) . '</p></section>';
		return;
	}
	echo '<ul>';
	foreach ( (array) ( $read['slots'] ?? array() ) as $s ) {
		$title = (string) ( $s['title'] ?? '' );
		$url   = (string) ( $s['edit_url'] ?? '' );
		echo '<li>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ': ' . esc_html( (string) ( $s['text'] ?? '' ) ) . '</li>';
	}
	echo '</ul>';
	if ( ! empty( $read['more'] ) ) {
		echo '<p>' . esc_html( sn_content_queue_slot_more( (int) $read['more'] ) ) . '</p>';
	}
	echo '</section>';
}

/**
 * The drafts left off the list, said the same on both twins.
 *
 * @param int $more Drafts past the cap.
 * @return string
 */
function sn_content_queue_slot_more( $more ) {
	return sprintf( 1 === (int) $more ? '+%d more draft, not given a slot here.' : '+%d more drafts, not given a slot here.', (int) $more );
}
