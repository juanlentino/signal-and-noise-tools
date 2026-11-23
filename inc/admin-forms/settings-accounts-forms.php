<?php
/**
 * Signal & Noise: Settings > Accounts and connections, the three boxes that
 * took settings from Connections leaves which kept their readings. Each form
 * is the one its leaf painted, field for field (names, ids, labels, bounds,
 * nonce, action), minus the hidden tab and sub that named the old leaf: a
 * form posts back to the address it was painted on (sn_admin_post_url()).
 *
 * One handler, one form, because three of the four write every field on a
 * plain save (tests/lib/whole-forms.php holds them to it):
 * - sn_handle_zenodo_env_save() writes the environment (anything but
 *   `production` is sandbox) and the ledger DOI (missing clears it).
 * - sn_handle_zenodo_doi_line_save() writes the switch, the label and the
 *   floor as one option.
 * - sn_handle_indexnow_save() writes the switch from `! empty()`.
 * - sn_handle_music_save() is the other kind: a field it is not sent is left
 *   alone, so the profile ID posts without the featured release.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hint under the Zenodo environment. Shared by both twins. The tokens
 * are keyring rows, in the Credentials box of the same pane.
 *
 * @return string
 */
function snt_accounts_zenodo_env_hint() {
	return __( 'Each environment reads its own token, set under Credentials on this pane.', 'signal-and-noise-tools' );
}

/**
 * The Zenodo box: the environment form, then the downloads line form, each
 * with its own Save. From Connections > Zenodo.
 *
 * @return void
 */
function snt_accounts_render_zenodo_box() {
	$d    = sn_zenodo_leaf_data();
	$copy = sn_zenodo_doi_line_copy();
	$line = snt_prov_doi_settings();
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Zenodo', 'signal-and-noise-tools' ) . '</h2>';

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_zenodo_env_save' );
	echo '<input type="hidden" name="action" value="sn_zenodo_env_save">';
	echo '<div class="sn-field"><label for="sn_zenodo_env">Environment</label><select id="sn_zenodo_env" name="zenodo_env"><option value="sandbox"' . selected( $d['env'], 'sandbox', false ) . '>Sandbox (sandbox.zenodo.org): test DOIs, never public</option><option value="production"' . selected( $d['env'], 'production', false ) . '>Production (zenodo.org)</option></select></div>';
	echo '<p class="sn-helper">' . esc_html( snt_accounts_zenodo_env_hint() ) . '</p>';
	echo '<div class="sn-field"><label for="sn_zenodo_ledger_doi">Ledger concept DOI</label><input type="text" id="sn_zenodo_ledger_doi" name="zenodo_ledger_doi" value="' . esc_attr( $d['ledger'] ) . '" placeholder="10.5281/zenodo.NNNNNNN" autocomplete="off"></div>';
	echo '<p class="sn-helper">The DOI Zenodo minted for the ledger repository\'s monthly snapshot (the concept DOI, which resolves to the latest snapshot). Every deposit names it as the record it is part of. Empty until the first snapshot release.</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save environment</button></div></form>';

	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_zenodo_doi_line_save' );
	echo '<input type="hidden" name="action" value="sn_zenodo_doi_line_save">';
	echo '<p class="sn-callout-h">' . esc_html( $copy['title'] ) . '</p>';
	echo '<p class="sn-helper">' . esc_html( $copy['intro'] ) . '</p>';
	echo '<div class="sn-field"><label><input type="checkbox" id="sn_zenodo_doi_line_enabled" name="zenodo_doi_line_enabled" value="1"' . checked( $line['enabled'], true, false ) . '> ' . esc_html( $copy['enabled'] ) . '</label></div>';
	echo '<div class="sn-field"><label for="sn_zenodo_doi_line_label">' . esc_html( $copy['label'] ) . '</label><input type="text" id="sn_zenodo_doi_line_label" name="zenodo_doi_line_label" value="' . esc_attr( $line['label'] ) . '" maxlength="80" autocomplete="off"></div>';
	echo '<p class="sn-helper">' . esc_html( $copy['label_hint'] ) . '</p>';
	echo '<div class="sn-field"><label for="sn_zenodo_doi_line_floor">' . esc_html( $copy['floor'] ) . '</label><input type="number" id="sn_zenodo_doi_line_floor" name="zenodo_doi_line_floor" value="' . (int) $line['floor'] . '" min="0" step="1"></div>';
	echo '<p class="sn-helper">' . esc_html( $copy['floor_hint'] ) . '</p>';
	echo '<p class="sn-helper">' . esc_html( sn_zenodo_doi_line_preview() ) . '</p>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">' . esc_html( $copy['submit'] ) . '</button></div></form>';

	echo '</div>';
}

/**
 * The IndexNow box: the one switch and its Save. From Connections > IndexNow.
 *
 * @return void
 */
function snt_accounts_render_indexnow_box() {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset">';
	wp_nonce_field( 'sn_indexnow_save' );
	echo '<input type="hidden" name="action" value="sn_indexnow_save">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Search engines (IndexNow)', 'signal-and-noise-tools' ) . '</h2>';
	echo '<div class="sn-field"><label><input type="checkbox" name="indexnow_enabled" value="1"' . checked( sn_indexnow_is_enabled(), true, false ) . '> Notify search engines when content changes</label></div>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save IndexNow settings</button></div></form>';
}

/**
 * The Muso.AI box: the profile ID. From Connections > Discography. While
 * SN_MUSO_PROFILE_ID sets it, the field is disabled, has no name and no form
 * around it: a Save could change nothing.
 *
 * @return void
 */
function snt_accounts_render_muso_box() {
	$locked = defined( 'SN_MUSO_PROFILE_ID' ) && SN_MUSO_PROFILE_ID;
	$open   = $locked ? '<div class="sn-fieldset">' : '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-fieldset">' . wp_nonce_field( 'sn_music_save', '_wpnonce', true, false );
	echo $open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup; the action URL is escaped above and the nonce field is core's.
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Muso.AI', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">The public profile whose credits are mirrored. Defaults to Juan&rsquo;s. No credential: it&rsquo;s the same id in the public credits URL. Lockable via <code>SN_MUSO_PROFILE_ID</code>.</p>';
	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="sn_muso_profile">Profile ID</label>';
	if ( $locked ) {
		echo '<input type="text" value="' . esc_attr( sn_muso_profile_id() ) . '" disabled class="sn-mono">';
		echo '<p class="sn-field-helper"><strong>Locked</strong> by <code>SN_MUSO_PROFILE_ID</code>.</p></div></div>';
		return;
	}
	echo '<input type="text" id="sn_muso_profile" name="sn_muso_profile" value="' . esc_attr( sn_muso_profile_id() ) . '" class="sn-mono">';
	echo '<p class="sn-field-helper">Type <code>clear</code> to revert to the default profile.</p>';
	echo '</div>';
	echo '<div class="sn-fieldset-actions"><button type="submit" name="action" value="sn_music_save" class="button button-primary">Save settings</button></div></form>';
}
