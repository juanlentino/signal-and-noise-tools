<?php
/**
 * S&N Dashboard: Settings > Accounts and connections, the three boxes that
 * took settings from Connections leaves which kept their readings, and the
 * line each of those leaves keeps. Same fields, names, bounds and words as
 * the classic twin (inc/admin-forms/settings-accounts-forms.php), same
 * actions. They were Setup folds on their leaves; a setting on a settings
 * pane is open.
 *
 * One handler, one form: zenodo_env_save, zenodo_doi_line_save and
 * indexnow_save write every field they own on a plain save
 * (tests/lib/whole-forms.php). No form carries a hidden tab or sub.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The in-window link to the pane, named as every pointer names it.
 *
 * @param string $current The tab the link is painted on.
 * @return string
 */
function accounts_go( $current ) {
	$label = function_exists( 'snt_settings_accounts_label' ) ? \snt_settings_accounts_label() : __( 'Settings › Accounts and connections', 'signal-and-noise-tools' );
	return \snt_kit_go( $label, array( 'tab' => 'settings', 'sub' => 'accounts', 'current' => (string) $current ) );
}

/**
 * The one line a Connections leaf keeps: what moved, with a link to the pane.
 * The sentence is the classic twin's (snt_settings_accounts_moved_text()).
 *
 * @param string $from    `indexnow`, `zenodo`, `muso` or `gsc`.
 * @param string $current The tab the line is painted on (`monitoring` for gsc).
 * @return string '' before the classic twin is loaded.
 */
function accounts_moved_html( $from, $current = 'connections' ) {
	if ( ! function_exists( 'snt_settings_accounts_moved_text' ) ) {
		return '';
	}
	return '<p class="snt-hint">' . \snt_kit_esc( \snt_settings_accounts_moved_text( $from ) ) . ' ' . accounts_go( $current ) . '.</p>';
}

/**
 * The Zenodo box: the environment with the ledger concept DOI, then the
 * downloads line on /provenance, each a form with its own Save.
 *
 * @return string '' when Zenodo is not loaded.
 */
function accounts_zenodo_html() {
	if ( ! function_exists( 'sn_zenodo_leaf_data' ) || ! function_exists( 'snt_prov_doi_settings' ) || ! function_exists( 'snt_accounts_zenodo_env_hint' ) ) {
		return '';
	}
	$d   = \sn_zenodo_leaf_data();
	$env = \snt_kit_field(
		'select',
		'zenodo_env',
		__( 'Environment', 'signal-and-noise-tools' ),
		$d['env'],
		array(
			'options' => array(
				'sandbox'    => __( 'Sandbox (sandbox.zenodo.org): test DOIs, never public', 'signal-and-noise-tools' ),
				'production' => __( 'Production (zenodo.org)', 'signal-and-noise-tools' ),
			),
			'hint'    => \snt_accounts_zenodo_env_hint(),
		)
	)
		. \snt_kit_field(
			'text',
			'zenodo_ledger_doi',
			__( 'Ledger concept DOI', 'signal-and-noise-tools' ),
			(string) ( $d['ledger'] ?? '' ),
			array(
				'placeholder' => '10.5281/zenodo.NNNNNNN',
				'hint'        => __( 'The DOI Zenodo minted for the ledger repository\'s monthly snapshot (the concept DOI, which resolves to the latest snapshot). Every deposit names it as the record it is part of. Empty until the first snapshot release.', 'signal-and-noise-tools' ),
			)
		);
	$c    = \sn_zenodo_doi_line_copy();
	$s    = \snt_prov_doi_settings();
	$line = '<h4 class="snt-h">' . \snt_kit_esc( $c['title'] ) . '</h4><p class="snt-hint">' . \snt_kit_esc( $c['intro'] ) . '</p>'
		. \snt_kit_field( 'checkbox', 'zenodo_doi_line_enabled', $c['enabled'], $s['enabled'] )
		. \snt_kit_field( 'text', 'zenodo_doi_line_label', $c['label'], $s['label'], array( 'hint' => $c['label_hint'], 'maxlength' => 80 ) )
		. \snt_kit_field( 'number', 'zenodo_doi_line_floor', $c['floor'], $s['floor'], array( 'hint' => $c['floor_hint'], 'min' => 0, 'step' => 1 ) )
		. '<p class="snt-hint">' . \snt_kit_esc( \sn_zenodo_doi_line_preview() ) . '</p>';
	return \snt_kit_section(
		__( 'Zenodo', 'signal-and-noise-tools' ),
		\snt_kit_form( 'zenodo_env_save', $env, array( 'submit' => __( 'Save environment', 'signal-and-noise-tools' ) ) )
		. \snt_kit_form( 'zenodo_doi_line_save', $line, array( 'submit' => $c['submit'] ) )
	);
}

/**
 * The IndexNow box: the one switch and its Save.
 *
 * @return string '' when IndexNow is not loaded.
 */
function accounts_indexnow_html() {
	if ( ! function_exists( 'sn_indexnow_is_enabled' ) ) {
		return '';
	}
	$toggle = \snt_kit_field( 'checkbox', 'indexnow_enabled', __( 'Notify search engines when content changes', 'signal-and-noise-tools' ), (bool) \sn_indexnow_is_enabled() );
	return \snt_kit_section( __( 'Search engines (IndexNow)', 'signal-and-noise-tools' ), \snt_kit_form( 'indexnow_save', $toggle, array( 'submit' => __( 'Save IndexNow settings', 'signal-and-noise-tools' ) ) ) );
}

/**
 * The Muso.AI box: the profile ID. While SN_MUSO_PROFILE_ID sets it, the
 * value shows disabled with no name and no form (music_locked_field()).
 *
 * @return string '' when the Muso module or the Discography parts are not loaded.
 */
function accounts_muso_html() {
	if ( ! function_exists( 'sn_muso_profile_id' ) || ! function_exists( __NAMESPACE__ . '\\music_locked_field' ) ) {
		return '';
	}
	$id    = (string) \sn_muso_profile_id();
	$intro = '<p class="snt-prose">'
		. \snt_kit_esc( __( 'The public profile whose credits are mirrored. Defaults to Juan’s. No credential: it’s the same id in the public credits URL. Lockable via', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'SN_MUSO_PROFILE_ID', false ) . '.'
		. '</p>';
	if ( defined( 'SN_MUSO_PROFILE_ID' ) && \SN_MUSO_PROFILE_ID ) {
		return \snt_kit_section( __( 'Muso.AI', 'signal-and-noise-tools' ), $intro . music_locked_field( __( 'Profile ID', 'signal-and-noise-tools' ), $id, 'SN_MUSO_PROFILE_ID' ) );
	}
	$field = \snt_kit_field( 'text', 'sn_muso_profile', __( 'Profile ID', 'signal-and-noise-tools' ), $id )
		. music_helper_html( \snt_kit_esc( __( 'Type', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'clear', false ) . ' ' . \snt_kit_esc( __( 'to revert to the default profile.', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Muso.AI', 'signal-and-noise-tools' ), $intro . \snt_kit_form( 'music_save', $field, array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ) ) ) );
}
