<?php
/**
 * Signal & Noise Tools: the release-notes box, shared by the classic
 * Connections › Discography leaf and its dashboard twin. One row per saved
 * note plus one empty row to add the next; clearing both notes in a row
 * removes it. Loaded by inc/discography-notes.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The intro sentence both surfaces print above the rows.
 *
 * @return string Plain text.
 */
function sn_discography_notes_intro() {
	return __( 'A line under a release’s roles on /music and /musica. Empty Spanish shows the English. Clear both to remove a note.', 'signal-and-noise-tools' );
}

/**
 * One compact row: what the release is, then the two notes side by side.
 * Labels are aria-labels so the row stays one line. Every value escaped.
 *
 * @param string $release The release cell (a name, or the picker for the add row).
 * @param string $name    Accessible name of the release, for the inputs' labels.
 * @param array  $note    {en, es}.
 * @return string
 */
function sn_discography_notes_row_html( $release, $name, $note ) {
	$grid = 'display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) minmax(0,1fr);gap:8px;align-items:center;margin:0 0 8px';
	return '<div style="' . $grid . '">' . $release
		/* translators: %s: release title */
		. '<input type="text" name="sn_disco_note_en[]" maxlength="' . (int) SN_DISCO_NOTE_MAX . '" placeholder="' . esc_attr__( 'English', 'signal-and-noise-tools' ) . '" aria-label="' . esc_attr( sprintf( __( 'English note for %s', 'signal-and-noise-tools' ), $name ) ) . '" value="' . esc_attr( (string) ( $note['en'] ?? '' ) ) . '">'
		/* translators: %s: release title */
		. '<input type="text" name="sn_disco_note_es[]" lang="es" maxlength="' . (int) SN_DISCO_NOTE_MAX . '" placeholder="' . esc_attr__( 'Español', 'signal-and-noise-tools' ) . '" aria-label="' . esc_attr( sprintf( __( 'Spanish note for %s', 'signal-and-noise-tools' ), $name ) ) . '" value="' . esc_attr( (string) ( $note['es'] ?? '' ) ) . '">'
		. '</div>';
}

/**
 * The rows: one compact line per saved note, and "Add a note" folded into a
 * single line until it is opened (owner, 2026-10-15: keep the settings small).
 * The marker tells sn_discography_notes_save() this box was posted.
 *
 * @return string HTML (no <form>; each surface wraps its own).
 */
function sn_discography_notes_fields_html() {
	$entries = function_exists( 'sn_discography_get' ) ? (array) ( sn_discography_get()['entries'] ?? array() ) : array();
	$titles  = array();
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
			$titles[ (string) $entry['id'] ] = trim( (string) ( $entry['title'] ?? '' ) . ', ' . (string) ( $entry['artist'] ?? '' ) . ( ! empty( $entry['year'] ) ? ' (' . (int) $entry['year'] . ')' : '' ) );
		}
	}

	$out = '<input type="hidden" name="sn_disco_notes_form" value="1">';
	foreach ( sn_discography_notes_get() as $id => $note ) {
		// A note whose release left the latest sync keeps its row and its id.
		$name    = $titles[ (string) $id ] ?? __( '(not in the current sync)', 'signal-and-noise-tools' );
		$release = '<span><input type="hidden" name="sn_disco_note_id[]" value="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</span>';
		$out    .= sn_discography_notes_row_html( $release, $name, $note );
	}

	$opts = '<option value="">' . esc_html__( 'Choose a release…', 'signal-and-noise-tools' ) . '</option>';
	foreach ( $titles as $id => $title ) {
		$opts .= '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $title ) . '</option>';
	}
	$picker = '<select name="sn_disco_note_id[]" aria-label="' . esc_attr__( 'Release', 'signal-and-noise-tools' ) . '" style="max-width:100%">' . $opts . '</select>';
	$out   .= '<details><summary>' . esc_html__( 'Add a note', 'signal-and-noise-tools' ) . '</summary><div style="margin-top:8px">'
		. sn_discography_notes_row_html( $picker, __( 'the chosen release', 'signal-and-noise-tools' ), array() )
		. '</div></details>';
	return $out;
}

/**
 * The classic leaf's box: its own music_save form, so Save sits with what it saves.
 */
function sn_discography_notes_render_classic() {
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '">';
	wp_nonce_field( 'sn_music_save' );
	echo '<input type="hidden" name="tab" value="content"><input type="hidden" name="sub" value="music">';
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">' . esc_html__( 'Release notes', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p class="sn-fieldset-intro">' . esc_html( sn_discography_notes_intro() ) . '</p>';
	echo sn_discography_notes_fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per value in the builder
	echo '<div class="sn-fieldset-actions"><button type="submit" name="action" value="sn_music_save" class="button button-primary">' . esc_html__( 'Save notes', 'signal-and-noise-tools' ) . '</button></div>';
	echo '</div></form>';
}
