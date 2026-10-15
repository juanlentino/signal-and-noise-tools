<?php
/**
 * Tests: owner notes on discography releases (inc/discography-notes.php). The
 * notes live outside the store, which every sync overwrites, and merge onto
 * the entries at the sn_discography_entries filter.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; } else { $fail++; echo "  FAIL - $label\n"; }
}
$GLOBALS['opts'] = array(); $GLOBALS['purged'] = 0;
function add_action() {} function add_filter() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_unslash( $v ) { return $v; }
function sn_cf_purge_tag( $t ) { $GLOBALS['purged']++; return true; }
function sn_discography_get() { return $GLOBALS['store']; }

require __DIR__ . '/../inc/discography-notes.php';

echo "Group: the form\n";
$n = sn_discography_notes_from_form( array( 'a', '', 'c', 'd' ), array( 'Stage manager.', 'orphan', '', '' ), array( '', '', 'Solo español', '' ) );
ok( array( 'a', 'c' ) === array_keys( $n ), 'rows without a release, or with both notes empty, are dropped' );
ok( 'Solo español' === $n['c']['es'] && '' === $n['c']['en'], 'a Spanish-only note is kept' );
ok( 'bad' === sn_discography_notes_from_form( array( 'x' ), array( '<script>bad</script>' ), array( '' ) )['x']['en'], 'markup is stripped to plain text' );
ok( SN_DISCO_NOTE_MAX === mb_strlen( sn_discography_notes_from_form( array( 'x' ), array( str_repeat( 'é', 500 ) ), array( '' ) )['x']['en'] ), 'notes are capped' );

echo "\nGroup: merge onto the entries\n";
$entries = array( array( 'id' => 'a', 'title' => 'Fin del Mundo' ), array( 'id' => 'b', 'title' => 'Other' ) );
$m = sn_discography_notes_merge( $entries, array( 'a' => array( 'en' => 'Stage manager.', 'es' => 'Jefe.' ) ) );
ok( 'Stage manager.' === $m[0]['note'] && 'Jefe.' === $m[0]['note_es'], 'the noted release carries note and note_es' );
ok( ! isset( $m[1]['note'] ), 'another release is untouched' );
ok( $entries === sn_discography_notes_merge( $entries, array() ), 'no notes: entries pass through' );

echo "\nGroup: save\n";
ok( false === sn_discography_notes_save( array( 'sn_music_featured' => '' ) ), 'saving another box (no marker) never touches the notes' );
$GLOBALS['opts'][ SN_DISCO_NOTES_OPT ] = array( 'a' => array( 'en' => 'Keep', 'es' => '' ) );
sn_discography_notes_save( array( 'sn_music_featured' => '' ) );
ok( 'Keep' === $GLOBALS['opts'][ SN_DISCO_NOTES_OPT ]['a']['en'], '...and the stored note survives it' );
ok( true === sn_discography_notes_save( array( 'sn_disco_notes_form' => '1', 'sn_disco_note_id' => array( 'a' ), 'sn_disco_note_en' => array( 'New' ), 'sn_disco_note_es' => array( '' ) ) ) && 1 === $GLOBALS['purged'], 'a change saves and purges the page cache' );
ok( false === sn_discography_notes_save( array( 'sn_disco_notes_form' => '1', 'sn_disco_note_id' => array( 'a' ), 'sn_disco_note_en' => array( 'New' ), 'sn_disco_note_es' => array( '' ) ) ) && 1 === $GLOBALS['purged'], 'an unchanged save neither writes nor purges' );

echo "\nGroup: the Richter seed\n";
$GLOBALS['opts'] = array(); $GLOBALS['store'] = array( 'entries' => array() );
sn_discography_notes_seed_richter();
ok( ! isset( $GLOBALS['opts'][ SN_DISCO_NOTES_SEED_OPT ] ), 'an unsynced store: waits, no flag' );
$GLOBALS['store'] = array( 'entries' => array( array( 'id' => 'r1', 'artist' => 'Richter' ), array( 'id' => 'r2', 'artist' => 'richter ' ), array( 'id' => 'o', 'artist' => 'Other' ) ) );
sn_discography_notes_seed_richter();
$notes = $GLOBALS['opts'][ SN_DISCO_NOTES_OPT ];
ok( array( 'r1', 'r2' ) === array_keys( $notes ), 'every Richter release gets the note, nothing else' );
ok( 'Stage manager (listed on Muso.AI as Manager).' === $notes['r1']['en'] && '' === $notes['r1']['es'], 'English line seeded; the Spanish line is left for the owner' );
$GLOBALS['opts'][ SN_DISCO_NOTES_OPT ]['r1']['en'] = 'Edited';
sn_discography_notes_seed_richter();
ok( 'Edited' === $GLOBALS['opts'][ SN_DISCO_NOTES_OPT ]['r1']['en'], 'it runs once: an edited note is never re-seeded' );

echo "\nGroup: the form keeps a note whose release left the sync\n";
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return esc_html( $s ); }
function esc_attr__( $s ) { return esc_attr( $s ); }
function __( $s ) { return $s; }
function selected( $a, $b, $echo ) { return $a === $b ? ' selected="selected"' : ''; }
$GLOBALS['store'] = array( 'entries' => array( array( 'id' => 'live', 'title' => 'T', 'artist' => 'A' ) ) );
$GLOBALS['opts'][ SN_DISCO_NOTES_OPT ] = array( 'gone' => array( 'en' => 'Old note', 'es' => '' ) );
$html = sn_discography_notes_fields_html();
ok( false !== strpos( $html, '<input type="hidden" name="sn_disco_note_id[]" value="gone">(not in the current sync)' ), 'an orphaned note keeps its id, so the next save does not drop it' );
ok( 1 === substr_count( $html, '<details>' ) && 1 === substr_count( $html, '<select' ), 'compact: saved notes are one line each; the add row is the one picker, folded in <details>' );
ok( false === strpos( $html, '<fieldset' ), 'no fieldset per note any more' );
ok( substr_count( $html, 'value="live"' ) >= 1, 'live releases are still listed' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
