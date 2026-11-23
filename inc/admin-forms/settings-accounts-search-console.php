<?php
/**
 * Signal & Noise: Settings > Accounts and connections, the Search Console
 * box. Two settings of one account, from Measurement > Search Console
 * (2026-11): the service-account credential and which property to read. An
 * account's credential lives with the accounts; the leaf keeps the identity
 * card, Test connection, the property it reads, Sync and Unanswered searches.
 *
 * Each form is the one the leaf painted, field for field (name, id, label,
 * every attribute of the textarea, the nonce on the action URL and on the
 * button's formaction), and neither carried a hidden tab or sub, so a save
 * returns to this pane.
 *
 * What the box does NOT bring: the identity card (service account, project,
 * key ID, fingerprint). It is a reading, and printing it here would print it
 * somewhere new. The textarea is never prefilled, exactly as before.
 *
 * One handler, one form. Neither resets anything by omission:
 * sn_handle_gsc_credential_save() returns unchanged on a missing or empty
 * field, sn_handle_gsc_property_save() on an empty pick. Test connection and
 * Sync now stayed on the leaf in forms of their own, each naming its own
 * action (gsc_test, gsc_sync), and neither handler reads the post.
 *
 * The native twin is apps/sn-dashboard/parts/leaves/settings-accounts-search-console.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The credential form: the textarea and Save credential. Always empty; an
 * empty save keeps the stored credential, `clear` removes it.
 *
 * @param bool $stored   Whether a credential is stored (the placeholder says which).
 * @param bool $readable Whether it parses (the replace line shows then, as it did).
 * @return void
 */
function snt_gsc_render_credential_form( $stored, $readable ) {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'gsc_credential_save' ) ) . '" class="sn-gsc-settings">';
	if ( $readable ) {
		echo '<p class="description">' . esc_html( snt_gsc_copy()['replace'] ) . '</p>';
	}
	echo '<p><label for="sn_gsc_credential"><strong>' . esc_html__( 'Service-account JSON', 'signal-and-noise-tools' ) . '</strong></label><br>';
	echo '<textarea id="sn_gsc_credential" name="sn_gsc_credential" rows="8" class="large-text code" spellcheck="false" autocomplete="off" placeholder="' . esc_attr( $stored ? __( 'Paste a fresh key file to replace; type clear to remove; leave empty to keep the current one', 'signal-and-noise-tools' ) : __( '{ "type": "service_account", … }', 'signal-and-noise-tools' ) ) . '"></textarea></p>';
	echo '<p><button type="submit"' . sn_admin_post_button( 'gsc_credential_save' ) . ' class="button button-primary">' . esc_html__( 'Save credential', 'signal-and-noise-tools' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped inside sn_admin_post_button().
	echo '</form>';
}

/**
 * The property picker: the last Test connection's list, or where to run it.
 * The list is never fetched on a paint (it would spend quota).
 *
 * @return void
 */
function snt_gsc_render_property_picker() {
	$last    = get_transient( 'snt_gsc_last_test' );
	$current = (string) sn_setting( 'search_console.property', '' );
	$sites   = ( is_array( $last ) && ! empty( $last['ok'] ) ) ? (array) ( $last['sites'] ?? array() ) : array();
	if ( empty( $sites ) ) {
		echo '<p class="description">' . esc_html( snt_gsc_copy()['list_at'] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=search-console' ) ) . '">' . esc_html__( 'Measurement › Search Console', 'signal-and-noise-tools' ) . '</a>.</p>';
		return;
	}
	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'gsc_property_save' ) ) . '" class="sn-gsc-settings">';
	echo '<p><label for="sn_gsc_property"><strong>' . esc_html__( 'Read search data for', 'signal-and-noise-tools' ) . '</strong></label><br>';
	echo '<select id="sn_gsc_property" name="sn_gsc_property">';
	echo '<option value="">' . esc_html__( 'Choose a property', 'signal-and-noise-tools' ) . '</option>';
	foreach ( $sites as $site ) {
		$url = (string) ( $site['siteUrl'] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		printf(
			'<option value="%1$s"%2$s>%3$s (%4$s)</option>',
			esc_attr( $url ),
			selected( $url, $current, false ),
			esc_html( $url ),
			esc_html( (string) ( $site['permissionLevel'] ?? 'unknown' ) )
		);
	}
	echo '</select></p>';
	echo '<p><button type="submit"' . sn_admin_post_button( 'gsc_property_save' ) . ' class="button button-primary">' . esc_html__( 'Use this property', 'signal-and-noise-tools' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped inside sn_admin_post_button().
	echo '</form>';
}

/**
 * The box: the credential form, then the picker once a credential parses (the
 * gate the leaf had).
 *
 * @return void
 */
function snt_accounts_render_search_console_box() {
	$readable = null !== snt_gsc_credential_identity();
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Search Console', 'signal-and-noise-tools' ) . '</h2>';
	snt_gsc_render_credential_form( function_exists( 'snt_gsc_credential_raw' ) && '' !== snt_gsc_credential_raw(), $readable );
	if ( $readable ) {
		snt_gsc_render_property_picker();
	}
	echo '</div>';
}
