<?php
/**
 * Signal & Noise Tools: signal-noise/indexing-requests, the indexing guard as a read.
 *
 * The execute callback. Its row lives in snt_search_console_abilities()
 * (inc/abilities-search-console.php), so the local registration, the read-door
 * allowlist and the remote twin (inc/abilities-remote-set-search-parts.php)
 * all read ONE schema.
 * Read-only over stored data: it never inspects, never requests indexing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback: signal-noise/indexing-requests (and its remote twin).
 *
 * @param mixed $input Unused.
 * @return array|WP_Error
 */
function snt_ability_indexing_requests( $input ) {
	unset( $input );
	if ( ! function_exists( 'sn_indexing_report' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'The indexing guard is not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	return array_merge( array( 'ok' => true ), sn_indexing_report() );
}
