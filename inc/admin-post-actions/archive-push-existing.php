<?php
/**
 * Signal & Noise: archive_push_existing, the owner's start (or resume) of the
 * Internet Archive run over notes that predate the keys (Tools > Provenance).
 * Nonce and capability are the dispatcher's (inc/admin-post-handler.php). It
 * books the first tick and asks the archive for nothing itself.
 *
 * @package SignalNoiseTools
 * @since 21.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * archive_push_existing: start or resume the run.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_archive_push_existing( $post ) {
	unset( $post );
	$result = function_exists( 'sn_archive_existing_start' ) ? sn_archive_existing_start()['result'] : 'unconfigured';
	return 'archive_existing_' . $result;
}
