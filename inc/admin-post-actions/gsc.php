<?php
/**
 * Signal & Noise — admin POST handlers: Google Search Console property, credentials, sync and test.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: gsc_property_save, gsc_sync, gsc_test, gsc_credential_save
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * R6b: choose which Search Console property to read.
 *
 * Validated against what the credential can ACTUALLY see rather than accepted
 * as typed — a property string that looks right but was never granted fails
 * later as a 403, at a distance from the mistake.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_gsc_property_save( $post ) {
	$want = isset( $post['sn_gsc_property'] ) ? trim( (string) wp_unslash( $post['sn_gsc_property'] ) ) : '';
	if ( '' === $want ) {
		return 'gsc_property_unchanged';
	}
	$sites = snt_gsc_list_sites();
	if ( is_wp_error( $sites ) ) {
		return 'gsc_test_failed';
	}
	$allowed = wp_list_pluck( $sites, 'siteUrl' );
	if ( ! in_array( $want, $allowed, true ) ) {
		return 'gsc_property_unknown';
	}
	sn_setting_update( 'search_console.property', $want );
	return 'gsc_property_saved';
}

/**
 * R6b: fetch the current window and store it.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_gsc_sync( $post ) {
	unset( $post );
	$res = snt_gsc_sync();
	if ( is_wp_error( $res ) ) {
		set_transient( 'snt_gsc_last_test', array(
			'ok'    => false,
			'code'  => $res->get_error_code(),
			'error' => $res->get_error_message(),
			'when'  => time(),
		), 10 * MINUTE_IN_SECONDS );
		return 'gsc_sync_failed';
	}
	return 'gsc_sync_ok';
}

/**
 * R6b: exercise the stored credential end to end and cache what it can read.
 *
 * Deliberately mints a FRESH token (force=true) rather than reusing a cached
 * one: the button's whole job is to answer "does this credential work RIGHT
 * NOW", and a cached token would answer "did it work up to an hour ago".
 *
 * The result is stashed in a transient so the render can show it once after the
 * redirect. A flash code alone cannot carry a list of properties.
 *
 * @param array $post Raw $_POST (unused).
 * @return string Flash code.
 */
function sn_handle_gsc_test( $post ) {
	unset( $post );
	if ( ! snt_gsc_credential_is_configured() ) {
		return 'gsc_test_not_configured';
	}
	$sites = snt_gsc_list_sites( true );
	if ( is_wp_error( $sites ) ) {
		set_transient( 'snt_gsc_last_test', array(
			'ok'    => false,
			'code'  => $sites->get_error_code(),
			'error' => $sites->get_error_message(),
			'when'  => time(),
		), 10 * MINUTE_IN_SECONDS );
		return 'gsc_test_failed';
	}
	set_transient( 'snt_gsc_last_test', array(
		'ok'    => true,
		'sites' => $sites,
		'when'  => time(),
	), 10 * MINUTE_IN_SECONDS );
	return empty( $sites ) ? 'gsc_test_no_properties' : 'gsc_test_ok';
}

/**
 * R6b: save the Google Search Console service-account credential.
 *
 * The textarea is ALWAYS submitted empty unless the owner pasted something, so
 * an empty value means "leave the stored credential alone" — never "clear it".
 * Removal is the explicit `clear` sentinel the analytics token already uses.
 *
 * A paste that fails validation is REFUSED and the stored value is untouched.
 * Storing an unusable credential would trade a clear error at the moment of the
 * mistake for an opaque token failure later, with the screen still showing
 * "configured".
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_gsc_credential_save( $post ) {
	if ( ! isset( $post['sn_gsc_credential'] ) ) {
		return 'gsc_credential_unchanged';
	}
	// NOT sanitize_textarea_field(): it strips and re-encodes, and a PEM block
	// plus JSON escaping must survive byte-exact or the key stops parsing.
	$raw = trim( (string) wp_unslash( $post['sn_gsc_credential'] ) );

	if ( '' === $raw ) {
		return 'gsc_credential_unchanged';
	}
	if ( 'clear' === strtolower( $raw ) ) {
		if ( '' === snt_gsc_credential_raw() ) {
			return 'gsc_credential_unchanged';
		}
		sn_setting_update( SNT_GSC_CREDENTIAL_PATH, '' );
		return 'gsc_credential_cleared';
	}
	$check = snt_gsc_credential_validate( $raw );
	if ( ! $check['ok'] ) {
		// Distinct codes for the two mistakes that change what the owner does
		// next; everything else (a missing field, a mangled PEM) collapses into
		// one message, because the fix is the same: re-download and re-paste.
		// A flash code is all that survives the redirect, so the reason has to
		// BE the code — a global would be gone by the time the notice renders.
		if ( 'not_json' === $check['error'] ) {
			return 'gsc_credential_not_json';
		}
		if ( 'not_service_account' === $check['error'] ) {
			return 'gsc_credential_not_service_account';
		}
		return 'gsc_credential_rejected';
	}
	if ( $raw === snt_gsc_credential_raw() ) {
		return 'gsc_credential_unchanged';
	}
	sn_setting_update( SNT_GSC_CREDENTIAL_PATH, $raw );
	return 'gsc_credential_saved';
}
