<?php
/**
 * Signal & Noise Tools: native scheduled-post surfacing (read-only).
 *
 * Task 7 of the scheduled-content subsystem: a thin adapter that lists the
 * WordPress posts/pages currently in `future` status (scheduled by core to
 * auto-publish at their post_date) so Task 8's admin list can fold native
 * scheduling in beside the signal-noise/scheduled fragment queue.
 *
 * SURFACE-ONLY by design. This module registers NO hooks and has NO side
 * effects. It is one read-only helper, called on demand by Task 8's renderer.
 *
 * Why no purge hook lives here (the load-bearing constraint):
 *   inc/cloudflare-purge.php ALREADY purges a post's Cloudflare URL + the index
 *   URLs whenever a post/page reaches the published status. It hooks the
 *   after-insert action at priority 30, and WordPress core fires that same
 *   action on the scheduled auto-publish path. So the edge cache is already
 *   invalidated the instant a scheduled post goes live. A second purge or
 *   status-transition handler here would fire a redundant purge for the very
 *   same event (a double-purge). This file therefore invokes no purge helper
 *   and registers no status-transition or purge hook. tests/schedule-pages.php
 *   enforces that with both an add_action recorder and a static source scan for
 *   the forbidden identifiers.
 *
 * @package SignalNoiseTools
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List the native-scheduled posts/pages (core `future` status) for the admin
 * list, as a normalized lightweight shape.
 *
 * Returns one array per scheduled post, ordered by scheduled datetime ascending
 * (soonest first), each with:
 *   - 'id'              int    The post ID.
 *   - 'title'           string The post title (already decoded by get_the_title).
 *   - 'scheduled_ts'    int    The scheduled publish instant as a UTC unix
 *                              timestamp (post_date_gmt), for sorting / "in N
 *                              days" rendering. 0 when post_date_gmt is unset.
 *   - 'scheduled_gmt'   string The scheduled publish instant as a canonical
 *                              MySQL UTC DATETIME 'Y-m-d H:i:s', for display. ''
 *                              when post_date_gmt is unset.
 *   - 'edit_link'       string The wp-admin edit URL ('raw' context, so it has
 *                              no HTML-escaped ampersands). '' when core returns
 *                              none (e.g. the current user can not edit it).
 *
 * The normalized shape (rather than raw WP_Post objects) means Task 8 can sort
 * and render the row without a second round-trip to each post, and keeps the
 * date-normalization (UTC) consistent with the fragment-queue rows it sits
 * beside. get_posts is called with `suppress_filters => false` so query filters
 * (e.g. multilingual / access plugins) still apply.
 *
 * READ-ONLY: this helper performs a single query and shapes the result. It
 * mutates nothing and fires no action.
 *
 * @return array<int, array{id:int,title:string,scheduled_ts:int,scheduled_gmt:string,edit_link:string}>
 */
function sn_schedule_future_posts() {
	$posts = get_posts( array(
		'post_status'      => 'future',
		'post_type'        => array( 'post', 'page' ),
		'numberposts'      => -1,
		'orderby'          => 'date',
		'order'            => 'ASC',
		'suppress_filters' => false,
	) );

	if ( empty( $posts ) || ! is_array( $posts ) ) {
		return array();
	}

	$out = array();
	foreach ( $posts as $post ) {
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			continue;
		}
		$post_id = (int) $post->ID;

		// Scheduled instant as a UTC timestamp + canonical MySQL UTC string,
		// both derived from post_date_gmt via get_post_time so the value matches
		// how the fragment-queue rows are normalized.
		$ts        = (int) get_post_time( 'U', true, $post );
		$gmt       = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
		$edit_link = get_edit_post_link( $post_id, 'raw' );

		$out[] = array(
			'id'            => $post_id,
			'title'         => (string) get_the_title( $post ),
			'scheduled_ts'  => $ts,
			'scheduled_gmt' => $gmt,
			'edit_link'     => is_string( $edit_link ) ? $edit_link : '',
		);
	}

	return $out;
}
