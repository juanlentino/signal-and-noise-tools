<?php
/**
 * Signal & Noise Tools: the template overrides the theme's clear removes.
 *
 * One query for the dashboard count, the diagnostics list and the
 * list-template-overrides ability, so all three name the rows the theme's
 * sn_clear_template_overrides() deletes (theme #604). Required by
 * inc/admin-tab-dashboard-data-parts.php and inc/abilities-system-deploy-parts.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The query for the overrides the theme's clear removes (2026-10-24, theme
 * #604): this theme's wp_template and wp_template_part rows only, by their
 * wp_theme term as core's get_block_templates() reads them, in every status
 * core can render or restore. Navigation posts hold menus, not overrides, and
 * another theme's rows never render here, so neither is counted: the count,
 * the list and the clear name the same set.
 *
 * @since 2026.10.63
 * @param array $extra Extra get_posts() args (e.g. fields).
 * @return array
 */
function snt_template_override_query_args( array $extra = array() ) {
	return array_merge(
		array(
			'post_type'      => array( 'wp_template', 'wp_template_part' ),
			'posts_per_page' => -1,
			'post_status'    => array( 'auto-draft', 'draft', 'publish', 'pending', 'private', 'future', 'trash' ),
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a handful of rows, the scope core uses.
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => function_exists( 'get_stylesheet' ) ? get_stylesheet() : '',
				),
			),
		),
		$extra
	);
}
