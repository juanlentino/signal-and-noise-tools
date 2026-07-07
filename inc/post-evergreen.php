<?php
/**
 * Signal & Noise — B5: the per-post "evergreen" flag (freshness arc, v8.11.0).
 *
 * The editorial counterpart to the A4 decay signal. `_sn_evergreen` (registered +
 * saved alongside the other per-post meta in inc/post-settings.php) marks a Note
 * as intentionally timeless. Two effects:
 *   1. The stale-posts health check (inc/health-checks.php) stops nagging it —
 *      "accept as evergreen" made actionable.
 *   2. The A4 Lifecycle leaderboard treats a flagged post as NOT a refresh
 *      candidate even when its traffic is cooling (the editor overrode the data).
 *
 * This module owns the read accessor + the Posts list-table indicator column (the
 * at-a-glance editor indicator). The meta box checkbox that sets it lives in the
 * consolidated post-settings meta box.
 *
 * @package signal-and-noise-tools
 * @since 8.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_EVERGREEN_META = '_sn_evergreen';

/**
 * Is this post flagged evergreen?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function sn_post_is_evergreen( $post_id ) {
	return (bool) get_post_meta( (int) $post_id, SN_EVERGREEN_META, true );
}

/**
 * Insert an "Evergreen" column into the Posts list table, just before Date (or
 * appended when there is no Date column).
 *
 * @param array<string,string> $cols Existing columns.
 * @return array<string,string>
 */
function sn_evergreen_add_column( $cols ) {
	$label = __( 'Evergreen', 'signal-and-noise-tools' );
	$out   = array();
	foreach ( (array) $cols as $key => $value ) {
		if ( 'date' === $key ) {
			$out['sn_evergreen'] = $label;
		}
		$out[ $key ] = $value;
	}
	if ( ! isset( $out['sn_evergreen'] ) ) {
		$out['sn_evergreen'] = $label;
	}
	return $out;
}
add_filter( 'manage_post_posts_columns', 'sn_evergreen_add_column' );

/**
 * Render the Evergreen column cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Row post ID.
 * @return void
 */
function sn_evergreen_render_column( $column, $post_id ) {
	if ( 'sn_evergreen' !== $column ) {
		return;
	}
	echo sn_evergreen_column_html( sn_post_is_evergreen( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper builds escaped markup.
}
add_action( 'manage_post_posts_custom_column', 'sn_evergreen_render_column', 10, 2 );

/**
 * The cell markup: an "Evergreen" pill when flagged, a muted dash otherwise.
 *
 * @param bool $is_evergreen Flag state.
 * @return string Escaped HTML.
 */
function sn_evergreen_column_html( $is_evergreen ) {
	if ( $is_evergreen ) {
		return '<span class="sn-pill sn-pill--ok">' . esc_html__( 'Evergreen', 'signal-and-noise-tools' ) . '</span>';
	}
	return '<span class="sn-an-muted" aria-hidden="true">&mdash;</span>';
}
