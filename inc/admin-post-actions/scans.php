<?php
/**
 * Signal & Noise — admin POST handlers: on-demand corpus scans: pattern adoption, block migrations.
 *
 * Split out of inc/admin-post-actions.php in v12.21.2, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: pattern_adoption_scan, block_migrations_scan
 *
 * @package SignalNoiseTools
 * @since 12.21.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_handle_pattern_adoption_scan( $post ) {
	// v4.3.0: routes through the central dispatcher per the health_scan pattern.
	if ( function_exists( 'snt_pattern_adoption_run_scan' ) ) {
		snt_pattern_adoption_run_scan();
	}
	return 'pattern_adoption_scanned';
}

function sn_handle_block_migrations_scan( $post ) {
	// v4.5.0: mirrors the pattern_adoption_scan dispatcher.
	if ( function_exists( 'snt_block_migrations_run_scan' ) ) {
		snt_block_migrations_run_scan();
	}
	return 'block_migrations_scanned';
}
