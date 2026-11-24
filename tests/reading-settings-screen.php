<?php
/**
 * Settings > Reading: Notes per page and JSON feed items on WordPress's own
 * screen (inc/reading-settings-screen.php), saved through the shared path
 * (inc/admin-post-actions/reading.php).
 *
 * Pins: the `sn_reading` setting is allowlisted on the `reading` group (or
 * options.php refuses the post); one section on the `reading` page with the
 * two fields in core's own markup; a save through pre_update_option writes
 * both values clamped and returns the old value, so no `sn_reading` option is
 * stored; a post without the capability, or not an array, writes nothing.
 *
 * Run: php tests/reading-settings-screen.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__reg'] = array(); $GLOBALS['__sections'] = array(); $GLOBALS['__fields'] = array();
$GLOBALS['__hooks'] = array(); $GLOBALS['__written'] = array(); $GLOBALS['__settings'] = array(); $GLOBALS['__cap'] = true; $GLOBALS['__purges'] = 0;
function __( $s ) { return $s; }
function esc_html__( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function add_action( $h, $cb ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['__reg'][] = array( $group, $name ); }
function add_settings_section( $id, $title, $cb, $page ) { $GLOBALS['__sections'][ $id ] = array( $title, $cb, $page ); }
function add_settings_field( $id, $title, $cb, $page, $section, $args ) { $GLOBALS['__fields'][ $id ] = array( $title, $cb, $page, $section, $args ); }
function current_user_can( $c ) { return $GLOBALS['__cap']; }
function sn_setting( $k, $d = null ) { return $GLOBALS['__settings'][ $k ] ?? $d; }
function sn_setting_update( $k, $v ) { $GLOBALS['__written'][ $k ] = $v; return true; }
function sn_settings_snapshot() { return array(); }
function sn_settings_purge_if_changed( $b ) { $GLOBALS['__purges']++; return true; }

require __DIR__ . '/../inc/admin-post-actions/reading.php';
require __DIR__ . '/../inc/reading-settings-screen.php';

ok( in_array( 'sn_reading_screen_register', $GLOBALS['__hooks']['admin_init'] ?? array(), true ) && in_array( 'sn_reading_screen_save', $GLOBALS['__hooks']['pre_update_option_sn_reading'] ?? array(), true ), 'wired: registration on admin_init, the save on pre_update_option_sn_reading' );
$GLOBALS['__settings'] = array( 'theme.notes_per_page' => 12, 'theme.json_feed_items' => 33 );
sn_reading_screen_register();
ok( array( array( 'reading', 'sn_reading' ) ) === $GLOBALS['__reg'], 'sn_reading is allowlisted on the reading group, so options.php accepts the post' );
ok( array( 'Notes', 'sn_reading_screen_intro', 'reading' ) === $GLOBALS['__sections']['sn_reading'], 'one section, "Notes", on the reading page' );
ok( array( 'sn_reading_notes_per_page', 'sn_reading_json_feed_items' ) === array_keys( $GLOBALS['__fields'] ) && 'reading' === $GLOBALS['__fields']['sn_reading_notes_per_page'][2] && 'sn_reading' === $GLOBALS['__fields']['sn_reading_json_feed_items'][3], 'two fields in that section, Notes per page first' );
ob_start();
foreach ( $GLOBALS['__fields'] as $f ) { call_user_func( $f[1], $f[4] ); }
$html = ob_get_clean();
ok( false !== strpos( $html, '<input name="sn_reading[notes_per_page]" type="number" step="1" min="1" max="100" id="sn_reading_notes_per_page" value="12" class="small-text" /> notes' ) && false !== strpos( $html, '<input name="sn_reading[json_feed_items]" type="number" step="1" min="1" max="50" id="sn_reading_json_feed_items" value="33" class="small-text" /> notes' ), 'core markup: small-text number inputs with the stored values, the theme bounds and the unit after them, as core paints its own two' );
ok( 2 === substr_count( $html, '<p class="description">' ) && false === strpos( $html, "\xe2\x80\x94" ), 'each field has a description, and no em dash' );
ob_start(); sn_reading_screen_intro(); $intro = ob_get_clean();
ok( false !== strpos( $intro, 'WordPress’s own two still set the blog pages and the RSS feeds' ), 'the intro says core’s two still govern the blog and RSS: these complement them' );

$GLOBALS['__written'] = array();
ok( false === sn_reading_screen_save( array( 'notes_per_page' => '500', 'json_feed_items' => '0' ), false ), 'the save returns the old value, so update_option stores no sn_reading option' );
ok( array( 'theme.notes_per_page' => 100, 'theme.json_feed_items' => 1 ) === $GLOBALS['__written'] && $GLOBALS['__purges'] > 0, 'and it writes both, clamped (1 to 100, 1 to 50), then refreshes the cache when they changed' );
$GLOBALS['__written'] = array();
sn_reading_screen_save( null, false );
sn_reading_screen_save( 'x', false );
ok( array() === $GLOBALS['__written'], 'a missing or non-array post writes nothing' );
$GLOBALS['__cap'] = false;
sn_reading_screen_save( array( 'notes_per_page' => '7' ), false );
ok( array() === $GLOBALS['__written'], 'without manage_options it writes nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
