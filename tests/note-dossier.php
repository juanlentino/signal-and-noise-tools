<?php
/**
 * Standalone test: inc/note-dossier.php — the block vocabulary the four
 * builders share, the window whitelist, the tone bridge, and the composer
 * that turns one failing builder into a block instead of a lost dossier.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function __( $t, $d = null ) { return $t; }
function get_post( $id ) { return $GLOBALS['__posts'][ (int) $id ] ?? null; }
function human_time_diff( $a, $b = 0 ) { return '2 hours'; }
class WP_Post { public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_password = ''; public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
$GLOBALS['__posts'] = array(
	7  => new WP_Post( array( 'ID' => 7 ) ),
	8  => new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ),
	9  => new WP_Post( array( 'ID' => 9, 'post_type' => 'page' ) ),
	10 => new WP_Post( array( 'ID' => 10, 'post_password' => 'x' ) ),
);
require __DIR__ . '/../inc/note-dossier.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "note dossier -- vocabulary\n\n";

ok( 30 === sn_note_dossier_days( '30' ) && 7 === sn_note_dossier_days( 7 ) && 90 === sn_note_dossier_days( '90' ), 'the three windows pass, as ints, from ints or strings' );
ok( 30 === sn_note_dossier_days( 14 ) && 30 === sn_note_dossier_days( null ), 'anything else is 30' );

ok( 7 === sn_note_dossier_post( 7 )->ID, 'a note resolves' );
ok( null === sn_note_dossier_post( 9 ) && null === sn_note_dossier_post( 999 ), 'a page or a missing post does not' );
ok( sn_note_dossier_is_public( get_post( 7 ) ) && ! sn_note_dossier_is_public( get_post( 8 ) ) && ! sn_note_dossier_is_public( get_post( 10 ) ), 'public = published and not password-protected' );

ok( 'success' === sn_note_dossier_tone( 'ok' ) && 'warning' === sn_note_dossier_tone( 'warn' ) && 'neutral' === sn_note_dossier_tone( 'muted' ) && 'danger' === sn_note_dossier_tone( 'err' ) && 'info' === sn_note_dossier_tone( '' ), 'the admin pill kinds map onto the kit tones' );
ok( 'neutral' === sn_note_dossier_tone( 'anything' ), 'an unknown kind is neutral, never a made-up tone' );

$s = sn_note_dossier_stats( 'trust', 'Numbers', array( array( 'label' => 'Views', 'value' => '312', 'window' => '30 days' ) ), 'analytics table' );
ok( 'stats' === $s['kind'] && 'trust' === $s['group'] && 'analytics table' === $s['source'] && 1 === count( $s['tiles'] ) && ! isset( $s['door'] ), 'a stats block carries group, kind, tiles and source; no door key when none given' );
$st = sn_note_dossier_status( 'state', 'Edge', 'success', 'Edge fresh', 'verified 2 hours ago', 'probe log', sn_note_dossier_door( 'Open', 'https://example.test/x' ) );
ok( 'status' === $st['kind'] && 'success' === $st['tone'] && 'verified 2 hours ago' === $st['meta'] && 'Open' === $st['door']['label'], 'a status block carries tone, text, meta and a door' );
ok( 'neutral' === sn_note_dossier_status( 'state', 'x', 'bogus', 'y' )['tone'], 'a tone outside the kit set falls to neutral' );
$u = sn_note_dossier_unreadable( 'numbers', 'Numbers', 'the analytics table' );
ok( 'status' === $u['kind'] && 'warning' === $u['tone'] && false !== strpos( $u['text'], 'could not be read' ) && false !== strpos( $u['meta'], 'the analytics table' ), 'an unreadable source is a warning block that names the source' );
ok( '2 hours ago' === sn_note_dossier_ago( time() - 7200 ) && '' === sn_note_dossier_ago( 0 ), 'ago wording; nothing for no time' );

echo "\ncompose: one failing builder is one block\n";
function sn_note_dossier_trust( $id, $f = null ) { return array( sn_note_dossier_text( 'trust', 'Trust', 'ok' ) ); }
function sn_note_dossier_numbers( $id, $d ) { throw new RuntimeException( 'boom' ); }
function sn_note_dossier_state( $id ) { return array(); }
function sn_note_dossier_editorial( $id ) { return array( sn_note_dossier_text( 'editorial', 'Tags', 'a, b' ) ); }
$c = sn_note_dossier_compose( 7, 30 );
ok( true === $c['ok'] && 7 === $c['post_id'] && 30 === $c['days'] && true === $c['is_public'] && is_int( $c['fetched_at'] ), 'the envelope: ok, post_id, days, is_public, fetched_at' );
$groups = array_map( static function ( $b ) { return $b['group'] . ':' . $b['kind']; }, $c['blocks'] );
ok( array( 'trust:text', 'numbers:status', 'editorial:text' ) === $groups, 'blocks keep the order trust, numbers, state, editorial; the throwing builder became one warning block; the empty builder added nothing' );
ok( false !== strpos( $c['blocks'][1]['meta'], 'numbers' ), 'the warning names the builder that failed' );
ok( null === sn_note_dossier_compose( 999, 30 ), 'no post, no dossier' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
