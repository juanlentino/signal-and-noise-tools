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
	return __( 'A short line shown under a release’s roles on /music (English) and /musica (Spanish), for what Muso.AI cannot say. Empty Spanish falls back to English. Clear both to remove a note.', 'signal-and-noise-tools' );
}

/**
 * The rows: release picker + English note + Spanish note, and the marker that
 * tells sn_discography_notes_save() this box was posted. Every value escaped.
 *
 * @return string HTML (no <form>; each surface wraps its own).
 */
function sn_discography_notes_fields_html() {
	$entries = function_exists( 'sn_discography_get' ) ? (array) ( sn_discography_get()['entries'] ?? array() ) : array();
	$rows    = sn_discography_notes_get();
	$rows[''] = array( 'en' => '', 'es' => '' ); // The add row.

	$out = '<input type="hidden" name="sn_disco_notes_form" value="1">';
	$n   = 0;
	foreach ( $rows as $id => $note ) {
		++$n;
		$opts = '<option value="">' . esc_html__( 'Choose a release…', 'signal-and-noise-tools' ) . '</option>';
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$label = trim( (string) ( $entry['title'] ?? '' ) . ', ' . (string) ( $entry['artist'] ?? '' ) . ( ! empty( $entry['year'] ) ? ' (' . (int) $entry['year'] . ')' : '' ) );
			$opts .= '<option value="' . esc_attr( (string) $entry['id'] ) . '"' . selected( (string) $entry['id'], (string) $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		// A note whose release left the latest sync keeps its row: without an
		// option for it, the select would post '' and the save would drop it.
		if ( '' !== (string) $id && false === strpos( $opts, 'value="' . esc_attr( (string) $id ) . '"' ) ) {
			$opts .= '<option value="' . esc_attr( (string) $id ) . '" selected="selected">' . esc_html__( '(not in the current sync)', 'signal-and-noise-tools' ) . '</option>';
		}
		$legend = '' === (string) $id ? __( 'Add a note', 'signal-and-noise-tools' ) : sprintf( /* translators: %d: row number */ __( 'Note %d', 'signal-and-noise-tools' ), $n );
		$out   .= '<fieldset style="margin:0 0 16px;padding:0;border:0">'
			. '<legend style="font-weight:600;margin-bottom:6px">' . esc_html( $legend ) . '</legend>'
			. '<p><label for="sn-disco-note-id-' . $n . '">' . esc_html__( 'Release', 'signal-and-noise-tools' ) . '</label><br>'
			. '<select id="sn-disco-note-id-' . $n . '" name="sn_disco_note_id[]" style="max-width:100%">' . $opts . '</select></p>'
			. '<p><label for="sn-disco-note-en-' . $n . '">' . esc_html__( 'Note (English)', 'signal-and-noise-tools' ) . '</label><br>'
			. '<input type="text" class="large-text" id="sn-disco-note-en-' . $n . '" name="sn_disco_note_en[]" maxlength="' . (int) SN_DISCO_NOTE_MAX . '" value="' . esc_attr( (string) ( $note['en'] ?? '' ) ) . '"></p>'
			. '<p><label for="sn-disco-note-es-' . $n . '">' . esc_html__( 'Note (Spanish)', 'signal-and-noise-tools' ) . '</label><br>'
			. '<input type="text" class="large-text" id="sn-disco-note-es-' . $n . '" name="sn_disco_note_es[]" lang="es" maxlength="' . (int) SN_DISCO_NOTE_MAX . '" value="' . esc_attr( (string) ( $note['es'] ?? '' ) ) . '"></p>'
			. '</fieldset>';
	}
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
