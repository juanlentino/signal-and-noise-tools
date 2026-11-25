<?php
/**
 * Signal & Noise: Settings > Accounts and connections, the classic section.
 *
 * The fifth pane of the Settings tab, one headed box per account:
 *
 * 1. Credentials. It came WHOLE from Connections > Credentials: the ledger of
 *    every keyring row with its source, its obscured value and its verdict,
 *    the "Set a credential" form, Verify all, the owner key and the worker
 *    commands. The list of accounts with their state is what an Accounts pane
 *    holds (Internet Accounts in System Settings, Accounts in Windows
 *    Settings), and the form's own sentences point at those tables.
 *    sn_admin_render_credentials_section() is CALLED as it is, not retyped, so
 *    the form keeps its nonce, its field types and every guard it had.
 *    sn_admin_subtab_moves() sends the old address here.
 * 2. Zenodo: the environment with the ledger concept DOI, and the downloads
 *    line on /provenance. The leaf keeps its status, its states, Deposit and
 *    the ledger.
 * 3. IndexNow: the switch. The leaf keeps its status, the key
 *    file, the last submission and the two maintenance actions.
 * 4. Muso.AI: the profile ID. Discography keeps the sync, the featured
 *    release (an editorial pick, not a setting of the account) and the notes.
 * 5. Search Console (2026-11, from Measurement): the service-account
 *    credential and the property to read. The leaf keeps the identity card,
 *    Test connection, Sync and Unanswered searches.
 *
 * Nothing about saving changed: keyring_save, keyring_verify,
 * keyring_generate, zenodo_env_save, zenodo_doi_line_save, indexnow_save,
 * music_save, gsc_credential_save and gsc_property_save, the same fields, the same handlers. A form posts back to the
 * address it was painted on (sn_admin_post_url()) and carries no hidden tab
 * or sub of its own, so a save from this pane returns to it.
 *
 * Three boxes are inc/admin-forms/settings-accounts-forms.php and the fourth
 * is inc/admin-forms/settings-accounts-search-console.php; the native
 * twin is apps/sn-dashboard/parts/leaves/settings-accounts.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/settings-accounts-pointers.php'; // the pane's address and name.
require_once __DIR__ . '/settings-accounts-forms.php';
require_once __DIR__ . '/settings-accounts-search-console.php'; // the Search Console box (2026-11).

/**
 * The sentence an old screen keeps, up to the link. Shared by both twins. It
 * names settings and never a value.
 *
 * @param string $from `indexnow`, `zenodo`, `muso` (Connections > Discography) or `gsc` (Measurement > Search Console).
 * @return string
 */
function snt_settings_accounts_moved_text( $from ) {
	$texts = array(
		'indexnow' => __( 'The switch that turns IndexNow on moved to', 'signal-and-noise-tools' ),
		'zenodo'   => __( 'The environment, the ledger concept DOI and the downloads line moved to', 'signal-and-noise-tools' ),
		'muso'     => __( 'The Muso.AI profile ID moved to', 'signal-and-noise-tools' ),
		'gsc'      => __( 'The service-account JSON and the property picker moved to', 'signal-and-noise-tools' ),
	);
	return (string) ( $texts[ $from ] ?? '' );
}

/**
 * That line on a classic screen, with its link to the pane.
 *
 * @param string $from See snt_settings_accounts_moved_text().
 * @return void
 */
function snt_settings_accounts_moved_line( $from ) {
	echo '<p class="sn-field-helper">' . esc_html( snt_settings_accounts_moved_text( $from ) ) . ' <a href="' . esc_url( snt_settings_accounts_url() ) . '">'
		. esc_html( snt_settings_accounts_label() ) . '</a>.</p>';
}

/**
 * The section: the Credentials box (the keyring's own two-column shell), then
 * one row of boxes, one per account. A box paints only when its module is
 * loaded. Every old leaf gated on manage_options; so does the pane.
 *
 * @return void
 */
function sn_admin_render_settings_accounts_section() {
	// Rows by subject (owner, 2026-11-24): search engines, as Google Search
	// Console and Bing Webmaster Tools treat submission and indexing; then
	// records and profiles. Credentials keeps its own heading.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( function_exists( 'sn_admin_render_credentials_section' ) && function_exists( 'sn_keyring_models' ) ) {
		echo '<h2 class="sn-section-h">' . esc_html__( 'Credentials', 'signal-and-noise-tools' ) . '</h2>';
		sn_admin_render_credentials_section();
	}
	$h = snt_settings_accounts_rows();
	echo '<h2 class="sn-section-h">' . esc_html( $h['search'] ) . '</h2><div class="sn-2up">';
	if ( function_exists( 'sn_indexnow_is_enabled' ) ) {
		snt_accounts_render_indexnow_box();
	}
	if ( function_exists( 'snt_gsc_credential_identity' ) && function_exists( 'snt_gsc_copy' ) ) {
		snt_accounts_render_search_console_box();
	}
	echo '</div><h2 class="sn-section-h">' . esc_html( $h['records'] ) . '</h2><div class="sn-2up">';
	if ( function_exists( 'sn_zenodo_leaf_data' ) && function_exists( 'snt_prov_doi_settings' ) ) {
		snt_accounts_render_zenodo_box(); // Two boxes: Zenodo, then the downloads line on /provenance.
	}
	if ( function_exists( 'sn_muso_profile_id' ) ) {
		snt_accounts_render_muso_box();
	}
	echo '</div>';
}

/**
 * The row headings under Credentials, the same on both twins.
 *
 * @return array{search:string,records:string}
 */
function snt_settings_accounts_rows() {
	return array(
		'search'  => __( 'Search engines', 'signal-and-noise-tools' ),
		'records' => __( 'Records and profiles', 'signal-and-noise-tools' ),
	);
}
