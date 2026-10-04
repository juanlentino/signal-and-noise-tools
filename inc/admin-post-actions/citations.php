<?php
/**
 * Signal & Noise: citation_forget, the owner removing one claim that is shown
 * to nobody (Integrity > Citations). Nonce and capability are the dispatcher's
 * (inc/admin-post-handler.php). A citation the site displays is refused.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * citation_forget: delete one non-public claim.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_citation_forget( $post ) {
	$id = isset( ( (array) $post )['claim'] ) ? (int) ( (array) $post )['claim'] : 0;
	if ( $id < 1 || ! function_exists( 'sn_cit_forget' ) ) {
		return 'citation_forget_none';
	}
	return sn_cit_forget( $id ) ? 'citation_forgotten' : 'citation_forget_none';
}
