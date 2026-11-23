<?php
/**
 * S&N Dashboard: Settings > Accounts and connections, the Search Console box.
 * The native twin of inc/admin-forms/settings-accounts-search-console.php:
 * the service-account credential and which property to read, from
 * Measurement > Search Console (2026-11).
 *
 * Both forms are the functions that leaf called
 * (search_console_credential_form_html(), search_console_property_picker_html(),
 * monitoring-search-console.php), so the textarea, the select, the actions
 * and the nonces are the ones they were; the credential form was a closed
 * Setup fold there and is open here. The textarea is never prefilled. The
 * identity card (service account, project, key ID, fingerprint) is a reading
 * and stays on the leaf: it is printed nowhere new.
 *
 * Neither handler resets anything by omission, and neither form carries a
 * hidden tab or sub, so a save lands back on this pane.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/monitoring-search-console.php'; // the state reader and the two forms.

/**
 * The box: the credential form, then the picker once a credential parses
 * (the gate the leaf had).
 *
 * @return string '' when Search Console is not loaded.
 */
function accounts_search_console_html() {
	if ( ! function_exists( 'snt_gsc_credential_identity' ) || ! function_exists( 'snt_gsc_copy' ) || ! function_exists( 'snt_gsc_sync_last_status' ) ) {
		return '';
	}
	$s    = search_console_state();
	$body = search_console_credential_form_html( $s );
	if ( null !== $s['identity'] ) {
		$body = '<p class="snt-hint">' . \snt_kit_esc( \snt_gsc_copy()['replace'] ) . '</p>' . $body . search_console_property_picker_html( $s );
	}
	return \snt_kit_section( __( 'Search Console', 'signal-and-noise-tools' ), $body );
}
