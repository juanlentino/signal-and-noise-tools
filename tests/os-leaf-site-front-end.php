<?php
/**
 * Native window leaf: Site → Front-End (apps/sn-dashboard/parts/leaves/site-front-end.php).
 *
 * The oracle is the classic leaf: the kit form must carry the same ten names
 * (eight knobs + sn_action + the nonce), the one sn_action, and every value,
 * bound, option, label and helper the classic form prints — for the theme's
 * defaults, a rich fixture, the palette-off state, a hostile alias and a
 * one-alias allowlist — and none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-site-front-end.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The leaf's own readers: sn_setting() answers from a flat path => value map,
// sn_note_reply_aliases() from a list the suite controls (the real list is
// inc/settings.php:43, mirrored verbatim).
$GLOBALS['__settings'] = array();
$GLOBALS['__aliases']  = array( 'research', 'press', 'speaking', 'role', 'music' );
if ( ! function_exists( 'sn_setting' ) ) { function sn_setting( $path, $default = null ) { return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default; } }
if ( ! function_exists( 'sn_note_reply_aliases' ) ) { function sn_note_reply_aliases() { return $GLOBALS['__aliases']; } }

require SNT_PATH . 'inc/admin-forms/front-end.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-front-end.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
/** Every needle present in the haystack; the missing ones named. */
function has_all( $html, array $needles, &$missing ) { $missing = array(); foreach ( $needles as $n ) { if ( false === strpos( $html, $n ) ) { $missing[] = $n; } } return array() === $missing; }

$knobs = array(
	// name => [ min, max, default ], the classic bounds (front-end.php:50-100) and defaults (:27-33).
	'theme_related_count'          => array( 1, 12, 3 ),
	'theme_palette_recent_count'   => array( 0, 20, 8 ),
	'theme_json_feed_items'        => array( 1, 50, 20 ),
	'theme_updated_threshold_days' => array( 1, 90, 14 ),
	'theme_reading_wpm'            => array( 100, 400, 225 ),
	'theme_notes_per_page'         => array( 1, 100, 20 ),
);
$number_tag = static function ( $name, $value ) use ( $knobs ) { return '<os-number-field name="' . $name . '" value="' . $value . '" min="' . $knobs[ $name ][0] . '" max="' . $knobs[ $name ][1] . '">'; };

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/front-end'] ), 'the painter is registered under site/front-end' );

// ── Defaults: nothing stored, the theme's own values.
$classic = snt_leaf_classic_html( 'sn_admin_render_front_end_form' );
$kit     = snt_leaf_paint( 'site', 'front-end' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 10 === count( snt_leaf_names( $kit ) ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'save_theme' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is save_theme, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form class="snt-form" os-action="post" submit-label="Save front-end settings" show-reset="false" columns="auto">' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post on the shared sn_action table, auto columns for the wide leaf, the classic submit label' );
$defaults = array();
foreach ( $knobs as $name => $b ) { $defaults[] = $number_tag( $name, $b[2] ); }
ok( has_all( $kit, $defaults, $missing ), 'defaults: every number knob carries the theme default and its classic bounds' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
ok( false !== strpos( $kit, '<os-select name="theme_note_reply_alias" value="research">' ) && false !== strpos( $kit, '<os-checkbox-label name="theme_palette_enabled" value="1" checked label="Enable the ⌘K command palette and its footer trigger">' ), 'defaults: the alias is research and the palette checkbox is checked' );
ok( false !== strpos( $kit, '<os-section heading="Front-End" description="Render knobs the companion theme reads via filters. Defaults match the theme’s own hardcoded values, so changes apply only once you save here. Each takes effect on the next front-end request.">' ), 'the Front-End heading and intro are the section heading and description' );
ok( has_all( $kit, array( 'Related notes shown', 'Note reply goes to', 'Command-palette recent notes', 'Reader command palette', 'JSON feed items', '“Updated” badge after (days)', 'Reading speed (words/min)', 'Notes per page' ), $missing ), 'all eight classic labels are painted' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
ok( has_all( $kit, array(
	'hint="How many related notes appear under a single note (1–12)."',
	'hint="Which existing alias the Reply row on a note writes to. Only aliases the mailbox already filters are offered — a new local part would arrive unfiltered."',
	'hint="Recent notes listed in the ⌘K reader palette (0–20)."',
	'hint="Turning this off hides the trigger and skips the palette’s JS/CSS entirely."',
	'hint="Number of notes in the JSON feed (1–50)."',
	'hint="Show the “Updated” badge when a note was revised this many days after publishing (1–90)."',
	'hint="Words per minute used to estimate reading time (100–400)."',
	'hint="How many notes per page on the /notes index (1–100). Pagination appears once published notes exceed this."',
	'<p class="snt-hint" slot="footer-leading">Changes apply on the next front-end request. Live site re-renders automatically.</p>',
), $missing ), 'all eight helpers ride the field rows as hints and the save-row hint rides the form footer' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
$options = array();
foreach ( $GLOBALS['__aliases'] as $a ) { $options[] = '<os-option value="' . $a . '">' . $a . '@</os-option>'; }
ok( has_all( $kit, $options, $missing ) && 5 === substr_count( $kit, '<os-option ' ) && 5 === substr_count( $classic, '<option ' ), 'the alias select offers exactly the five allowlisted aliases as alias@, as the classic select does' );

// ── Rich fixture: every knob stored off its default, palette off, another alias.
$GLOBALS['__settings'] = array(
	'theme.related_count'          => 5,
	'theme.palette_recent_count'   => 0,
	'theme.palette_enabled'        => false,
	'theme.json_feed_items'        => 33,
	'theme.updated_threshold_days' => 45,
	'theme.reading_wpm'            => 310,
	'theme.notes_per_page'         => 12,
	'theme.note_reply_alias'       => 'speaking',
);
$classic = snt_leaf_classic_html( 'sn_admin_render_front_end_form' );
$kit     = snt_leaf_paint( 'site', 'front-end' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'rich: field names still match the classic form' );
$stored = array( $number_tag( 'theme_related_count', 5 ), $number_tag( 'theme_palette_recent_count', 0 ), $number_tag( 'theme_json_feed_items', 33 ), $number_tag( 'theme_updated_threshold_days', 45 ), $number_tag( 'theme_reading_wpm', 310 ), $number_tag( 'theme_notes_per_page', 12 ) );
ok( has_all( $kit, $stored, $missing ), 'rich: every number knob carries its stored value (0 included) with its bounds' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
ok( false !== strpos( $kit, '<os-select name="theme_note_reply_alias" value="speaking">' ) && false !== strpos( $classic, 'value="speaking" selected="selected"' ), 'rich: the stored alias is the select value, the one the classic select marks selected' );
ok( false !== strpos( $kit, '<os-checkbox-label name="theme_palette_enabled" value="1" label="Enable the ⌘K command palette and its footer trigger">' ) && false === strpos( $kit, ' checked' ) && false === strpos( $classic, 'checked="checked"' ), 'palette off: the checkbox is unchecked, as on the classic leaf' );

// ── Escaping: a hostile stored alias and a hostile allowlist entry never reach the markup raw;
// a hostile number reads as the classic (int) cast does.
$GLOBALS['__settings']['theme.note_reply_alias'] = '"><script>x</script>';
$GLOBALS['__settings']['theme.related_count']    = '"><script>y</script>';
$GLOBALS['__aliases'][]                          = '<img src=x onerror=1>';
$kit = snt_leaf_paint( 'site', 'front-end' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"' ), 'a hostile stored alias is escaped' );
ok( false === strpos( $kit, '<img' ) && false !== strpos( $kit, '<os-option value="&lt;img src=x onerror=1&gt;">&lt;img src=x onerror=1&gt;@</os-option>' ), 'a hostile allowlist entry is escaped in both the option value and its label' );
ok( false !== strpos( $kit, $number_tag( 'theme_related_count', 0 ) ), 'a hostile number reads as 0, the classic (int) cast' );
array_pop( $GLOBALS['__aliases'] );
$GLOBALS['__settings'] = array();

// ── A one-alias allowlist (the classic fallback shape when the reader is absent): one option.
$GLOBALS['__aliases'] = array( 'research' );
$classic = snt_leaf_classic_html( 'sn_admin_render_front_end_form' );
$kit     = snt_leaf_paint( 'site', 'front-end' );
ok( 1 === substr_count( $kit, '<os-option ' ) && false !== strpos( $kit, '<os-option value="research">research@</os-option>' ) && 1 === substr_count( $classic, '<option ' ), 'a one-alias allowlist paints one option, as the classic select does' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
