<?php
/**
 * S&N Home, Continue working: the three most recently edited posts and
 * pages, and a link to the Posts list that counts what that list shows.
 *
 * 2026.10.4 audit (batch 4): "View all (164)" counted every post AND page in
 * five statuses, then opened Posts, which lists no pages. The link now says
 * "All posts" and counts the posts the Posts list's own All view counts.
 * Split out of dashboard.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/** Recent items Continue working lists. */
const SNT_HOME_WORK_ROWS = 3;

/**
 * The posts the Posts list's All view counts: every status but trash and
 * auto-draft. Null when the count cannot be read.
 *
 * @return int|null
 */
function home_post_total() {
	if ( ! function_exists( 'wp_count_posts' ) ) {
		return null;
	}
	$counts = wp_count_posts( 'post' );
	if ( ! is_object( $counts ) ) {
		return null;
	}
	$n = 0;
	foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $status ) {
		$n += (int) ( $counts->$status ?? 0 );
	}
	return $n;
}

/**
 * The recent items, newest edit first.
 *
 * @return array<int,array<string,mixed>>
 */
function home_recent_work() {
	if ( ! class_exists( 'WP_Query' ) ) {
		return array();
	}
	$query = new \WP_Query(
		array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => array( 'draft', 'pending', 'future', 'private', 'publish' ),
			'posts_per_page'         => SNT_HOME_WORK_ROWS,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$items = array();
	foreach ( (array) $query->posts as $p ) {
		$edit_url = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $p->ID, 'raw' ) : admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
		if ( ! is_string( $edit_url ) || '' === $edit_url ) {
			continue;
		}
		$type_obj   = function_exists( 'get_post_type_object' ) ? get_post_type_object( $p->post_type ) : null;
		$status_obj = function_exists( 'get_post_status_object' ) ? get_post_status_object( $p->post_status ) : null;
		$title      = function_exists( 'get_the_title' ) ? get_the_title( $p ) : $p->post_title;
		$modified   = strtotime( (string) $p->post_modified_gmt . ' UTC' );
		$items[]    = array(
			'title'      => '' !== trim( (string) $title ) ? (string) $title : __( '(Untitled)', 'signal-and-noise-tools' ),
			'type'       => $type_obj ? $type_obj->labels->singular_name : __( 'Post', 'signal-and-noise-tools' ),
			'icon'       => 'page' === $p->post_type ? 'dashicons-admin-page' : 'dashicons-admin-post',
			'status'     => $p->post_status,
			'status_lbl' => $status_obj ? $status_obj->label : $p->post_status,
			'time_ago'   => false !== $modified ? \snt_kit_relative_time( $modified ) : '',
			'edit_url'   => $edit_url,
		);
	}
	return $items;
}

/**
 * Render the Continue Working section.
 *
 * @param string $tab Current tab.
 * @return string
 */
function home_continue_working_html( $tab ) {
	unset( $tab );
	$items = home_recent_work();
	$posts = home_post_total();
	$out   = '<section class="snt-home__section" aria-labelledby="snt-home-work-heading">'
		. '<div class="snt-home__section-heading">'
		. '<h2 id="snt-home-work-heading">' . esc_html__( 'Continue working', 'signal-and-noise-tools' ) . '</h2>';
	if ( null !== $posts && $posts > 0 ) {
		/* translators: %s: posts in the Posts list's All view. */
		$out .= '<a class="snt-home__view-all" href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html( sprintf( __( 'All posts (%s)', 'signal-and-noise-tools' ), number_format_i18n( $posts ) ) ) . '</a>';
	}
	$out .= '</div><div class="snt-home__work">';

	if ( empty( $items ) ) {
		// The kit's empty state, as Station Home paints this section
		// (apps/station-home/parts/view.php).
		$out .= \snt_kit_empty(
			__( 'Your desk is clear', 'signal-and-noise-tools' ),
			__( 'Start something new and it will be waiting here when you return.', 'signal-and-noise-tools' ),
			'welcome-write-blog'
		);
	}
	foreach ( $items as $item ) {
		$tone = 'publish' === $item['status'] ? 'success' : ( in_array( $item['status'], array( 'future', 'pending' ), true ) ? 'warning' : 'neutral' );
		$out .= '<a class="snt-home__work-row" href="' . esc_url( $item['edit_url'] ) . '">'
			. '<span class="snt-home__row-icon"><span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span></span>'
			. '<span class="snt-home__row-copy">'
			. '<span class="snt-home__row-title">' . esc_html( $item['title'] ) . '</span>'
			. '<span class="snt-home__row-meta">' . esc_html( $item['type'] ) . '</span>'
			. '</span>'
			. '<os-badge tone="' . esc_attr( $tone ) . '">' . esc_html( $item['status_lbl'] ) . '</os-badge>'
			. ( '' !== $item['time_ago'] ? '<span class="snt-home__row-time">' . $item['time_ago'] . '</span>' : '' )
			. '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>'
			. '</a>';
	}
	return $out . '</div></section>';
}
