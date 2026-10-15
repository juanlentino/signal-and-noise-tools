<?php
/**
 * Signal & Noise Tools: owner notes on discography releases, English + Spanish.
 *
 * A short visible line under a release's roles, for what Muso.AI cannot say:
 * the Richter releases list Juan as "Manager", but he was the band's stage
 * manager, a role Muso.AI does not have. The note lives in its own option keyed
 * by release id, NOT in the store: sn_discography_run_sync() rewrites the
 * store's entries wholesale every day, so a note there would last a day.
 * Merged onto the entries at the `sn_discography_entries` filter (after the
 * store answers), rendered by the theme's sn_discography_render_card().
 *
 * Edited under Connections › Discography (classic leaf and dashboard twin),
 * saved by sn_handle_music_save() through sn_discography_notes_save().
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_DISCO_NOTES_OPT      = 'sn_discography_notes';
const SN_DISCO_NOTES_SEED_OPT = 'sn_discography_notes_richter_seeded_v1';
const SN_DISCO_NOTE_MAX       = 200;

/**
 * @return array<string,array{en:string,es:string}> Release id => notes.
 */
function sn_discography_notes_get() {
	$raw = get_option( SN_DISCO_NOTES_OPT, array() );
	return is_array( $raw ) ? $raw : array();
}

/**
 * Build the stored map from the three parallel form arrays. Pure. A row with
 * no release id or with both notes empty is dropped; text is plain and capped.
 *
 * @param array $ids Release ids.
 * @param array $en  English notes.
 * @param array $es  Spanish notes.
 * @return array<string,array{en:string,es:string}>
 */
function sn_discography_notes_from_form( $ids, $en, $es ) {
	$out = array();
	foreach ( array_values( (array) $ids ) as $i => $id ) {
		$id   = sanitize_text_field( (string) $id );
		$note = array(
			'en' => mb_substr( sanitize_text_field( (string) ( array_values( (array) $en )[ $i ] ?? '' ) ), 0, SN_DISCO_NOTE_MAX ),
			'es' => mb_substr( sanitize_text_field( (string) ( array_values( (array) $es )[ $i ] ?? '' ) ), 0, SN_DISCO_NOTE_MAX ),
		);
		if ( '' !== $id && ( '' !== $note['en'] || '' !== $note['es'] ) ) {
			$out[ $id ] = $note;
		}
	}
	return $out;
}

/**
 * Merge the notes onto the entries as `note` and `note_es`. Pure over $notes.
 *
 * @param array $entries Store entries.
 * @param array $notes   sn_discography_notes_get().
 * @return array
 */
function sn_discography_notes_merge( $entries, $notes ) {
	if ( ! is_array( $entries ) || empty( $notes ) ) {
		return $entries;
	}
	return array_map(
		static function ( $entry ) use ( $notes ) {
			$id = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
			if ( '' === $id || ! isset( $notes[ $id ] ) ) {
				return $entry;
			}
			return array_merge( $entry, array( 'note' => (string) ( $notes[ $id ]['en'] ?? '' ), 'note_es' => (string) ( $notes[ $id ]['es'] ?? '' ) ) );
		},
		$entries
	);
}

add_filter(
	'sn_discography_entries',
	static function ( $entries ) {
		return sn_discography_notes_merge( $entries, sn_discography_notes_get() );
	},
	20
);

/**
 * Save the notes box. Acts only when the box itself was posted (its marker),
 * so saving another box on the same leaf never clears the notes.
 *
 * @param array $post Raw $_POST.
 * @return bool Whether the stored notes changed.
 */
function sn_discography_notes_save( $post ) {
	if ( empty( $post['sn_disco_notes_form'] ) ) {
		return false;
	}
	$next = sn_discography_notes_from_form(
		wp_unslash( (array) ( $post['sn_disco_note_id'] ?? array() ) ),
		wp_unslash( (array) ( $post['sn_disco_note_en'] ?? array() ) ),
		wp_unslash( (array) ( $post['sn_disco_note_es'] ?? array() ) )
	);
	if ( $next === sn_discography_notes_get() ) {
		return false;
	}
	update_option( SN_DISCO_NOTES_OPT, $next, false );
	if ( function_exists( 'sn_cf_purge_tag' ) ) {
		sn_cf_purge_tag( 'discography_notes' );
	}
	return true;
}

/**
 * One-time: the Richter releases get the stage-manager note (English only; the
 * Spanish line is the owner's to write). Waits for a synced store.
 */
function sn_discography_notes_seed_richter() {
	if ( get_option( SN_DISCO_NOTES_SEED_OPT ) || ! function_exists( 'sn_discography_get' ) ) {
		return;
	}
	$store = sn_discography_get();
	$notes = sn_discography_notes_get();
	$found = false;
	foreach ( (array) ( $store['entries'] ?? array() ) as $entry ) {
		if ( is_array( $entry ) && 0 === strcasecmp( trim( (string) ( $entry['artist'] ?? '' ) ), 'Richter' ) && ! empty( $entry['id'] ) ) {
			$notes[ (string) $entry['id'] ] = $notes[ (string) $entry['id'] ] ?? array( 'en' => 'Stage manager (listed on Muso.AI as Manager).', 'es' => '' );
			$found                          = true;
		}
	}
	if ( ! $found ) {
		return;
	}
	update_option( SN_DISCO_NOTES_OPT, $notes, false );
	update_option( SN_DISCO_NOTES_SEED_OPT, time(), false );
}
add_action( 'admin_init', 'sn_discography_notes_seed_richter' );

require_once __DIR__ . '/discography-notes-form.php';
