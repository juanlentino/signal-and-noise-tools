<?php
/**
 * Settings › Front end, the six save_theme fields, both twins: the native
 * pane (apps/sn-dashboard/parts/leaves/settings-front-end.php) and the
 * classic form (inc/admin-forms/front-end.php).
 *
 * This is the old Site › Front-End suite, every assertion kept, painted on
 * the pane the leaf moved to whole in 2026-11. The oracle is still the
 * classic form: the kit form must carry the same six names, the one
 * sn_action, and every value, bound, option, label and helper the classic
 * form prints (for the theme's defaults, a rich fixture, the palette-off
 * state, a hostile alias and a one-alias allowlist) and none of wp-admin's
 * markup. What changed is the grouping: three headed boxes inside the ONE
 * form. The pane around the form (the registry, the redirect, the
 * speculative loading switch) is tests/os-leaf-settings-front-end-pane.php.
 *
 * Run: php tests/os-leaf-settings-front-end.php
 */
require_once __DIR__ . '/lib/settings-front-end-fixture.php';

/** The classic save_theme form, alone. */
function fe_classic_form() { return snt_leaf_classic_html( 'sn_admin_render_front_end_form' ); }
/** The native pane's save_theme form, alone ('' before the change). */
function fe_kit_form() { return fe_form( fe_kit(), 'os-form', 'save_theme' ); }

$knobs = array(
	// name => [ min, max, default ], the classic bounds (front-end.php:50-100) and defaults (:27-33).
	'theme_related_count'          => array( 1, 12, 3 ),
	'theme_palette_recent_count'   => array( 0, 20, 8 ),
	'theme_updated_threshold_days' => array( 1, 90, 14 ),
	'theme_reading_wpm'            => array( 100, 400, 225 ),
);
$number_tag = static function ( $name, $value ) use ( $knobs ) { return '<os-number-field name="' . $name . '" value="' . $value . '" min="' . $knobs[ $name ][0] . '" max="' . $knobs[ $name ][1] . '">'; };

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/front-end'] ) && ! isset( \SignalNoise\OpenStationHost\Dashboard\painters()['site/front-end'] ), 'the painter is registered under settings/front-end, and no longer under site/front-end' );

// ── Defaults: nothing stored, the theme's own values.
$classic = fe_classic_form();
$kit     = fe_kit_form();
ok( '' !== $kit, 'the kit leaf paints' );
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ) && 6 === count( snt_leaf_names( $kit ) ), 'field names match the classic form: ' . implode( ',', snt_leaf_names( $kit ) ) . ' (classic: ' . implode( ',', snt_leaf_names( $classic ) ) . ')' );
ok( array( 'save_theme' ) === snt_leaf_actions( $kit ) && snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ), 'the one action is save_theme, as on the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, '<os-form class="snt-form snt-site-form" os-action="post" submit-label="Save front-end settings" show-reset="false" columns="auto">' ) && false === strpos( $kit, 'os-arg-pipeline' ), 'the form is an os-form dispatching post on the admin-post pipeline, auto columns for the wide leaf, the classic submit label' );
// 2026.10.4 audit (batch 4): the window's width, as the classic wide leaf; the 760px cap halved it.
$css = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) );
ok( 1 === preg_match( '/\.snt-app\[data-os-app="sn-dashboard"\] \.snt-form\.snt-site-form\s*\{\s*max-width:\s*none;/', $css ), 'the Site forms lift the 760px form cap, scoped to this app' );
$defaults = array();
foreach ( $knobs as $name => $b ) { $defaults[] = $number_tag( $name, $b[2] ); }
ok( has_all( $kit, $defaults, $missing ), 'defaults: every number knob carries the theme default and its classic bounds' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
ok( false !== strpos( $kit, '<os-select name="theme_note_reply_alias" value="research">' ) && false !== strpos( $kit, '<os-checkbox-label name="theme_palette_enabled" value="1" checked label="Enable the ⌘K command palette and its footer trigger">' ), 'defaults: the alias is research and the palette checkbox is checked' );
// 2026-11: the Front-End heading became three headed boxes by subject, inside the one form; the intro leads them.
ok( false !== strpos( $kit, '<p class="snt-prose">How the site’s pages render; the companion theme reads these values. The defaults are the theme’s own, so nothing changes until you save here.</p>' ) && false === strpos( $kit, 'heading="Front-End"' ), 'the intro leads the form; the lone Front-End heading is gone' );
$box = static function ( $heading ) use ( &$kit ) { return 1 === preg_match( '#<os-section heading="' . $heading . '" stack>(.*?)</os-section>#s', $kit, $m ) ? $m[1] : ''; };
ok( array( 'theme_reading_wpm', 'theme_related_count', 'theme_updated_threshold_days' ) === snt_leaf_names( $box( 'Reading' ) ) && array( 'theme_palette_enabled', 'theme_palette_recent_count' ) === snt_leaf_names( $box( 'Command palette' ) ) && array( 'theme_note_reply_alias' ) === snt_leaf_names( $box( 'Replies' ) ), 'native: three headed boxes by subject, Reading (three), Command palette (two) and Replies (one)' );
ok( 1 === preg_match( '#^<os-form [^>]*>' . preg_quote( '<p class="snt-prose">', '#' ) . '.*?</p>' . preg_quote( snt_leaf_row( 3, 260 ), '#' ) . '<os-section heading="Reading" stack>.*</os-section><os-section heading="Command palette" stack>.*</os-section><os-section heading="Replies" stack>.*</os-section></os-grid><p class="snt-hint" slot="footer-leading">#s', $kit ), 'native: the three boxes are one row inside the form, in that order, then the save-row hint' );
ok( 1 === substr_count( $classic, '<p class="sn-field-helper">Notes per page and JSON feed items are on <a href="https://example.test/wp-admin/options-reading.php#sn_reading_notes_per_page">Settings &rsaquo; Reading</a>, with WordPress&rsquo;s own.</p>' ) && false === strpos( $classic, 'theme_notes_per_page' ) && false === strpos( $classic, 'theme_json_feed_items' ), 'classic: the Reading box says where Notes per page and JSON feed items went, with a link to Settings › Reading, and posts neither' );
ok( 1 === preg_match( '#^<form [^>]*class="sn-front-end-form">.*<p class="sn-fieldset-intro">.*</p><div class="sn-2up"><div class="sn-fieldset"><h2 class="sn-fieldset-h">Reading</h2>.*</div><div class="sn-fieldset"><h2 class="sn-fieldset-h">Command palette</h2>.*</div><div class="sn-fieldset"><h2 class="sn-fieldset-h">Replies</h2>.*</div></div><div class="sn-fieldset-actions">.*</div></form>$#s', $classic ), 'classic: the same three headed boxes, one row inside the one form, then its save row' );
ok( has_all( $kit, array( 'Related notes shown', 'Note reply goes to', 'Command-palette recent notes', 'Reader command palette', '“Updated” badge after (days)', 'Reading speed (words/min)' ), $missing ), 'all six classic labels are painted' . ( $missing ? ': missing ' . implode( ' | ', $missing ) : '' ) );
$helpers = array(
	'hint="How many related notes appear under a single note (1 to 12)."',
	'hint="Which existing alias the Reply row on a note writes to. Only aliases the mailbox already filters are offered; a new address would arrive unfiltered."',
	'hint="Recent notes listed in the ⌘K reader palette (0 to 20)."',
	'hint="Turning this off hides the trigger and skips loading the palette’s scripts and styles."',
	'hint="Show the “Updated” badge when a note was revised this many days after publishing (1 to 90)."',
	'hint="Words per minute used to estimate reading time (100 to 400)."',
	'<p class="snt-hint" slot="footer-leading">Saving a change refreshes the site’s cached pages, so the live site shows it the next time a page loads.</p>',
);
ok( has_all( $kit, $helpers, $missing ), 'all six helpers ride the field rows as hints and the save-row hint rides the form footer' . ( $missing ? ': missing ' . implode( ' | ', $missing ) : '' ) );
// 2026.10.4 audit (batch 4): the twins say the same sentences, in plain words
// ("render knobs", "JS/CSS", "local part" gone), ranges in words, no dashes,
// and the save hint keeps its promise (a changed save purges the page cache).
$classic_text = html_entity_decode( strip_tags( $classic ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
foreach ( array( 'How the site’s pages render; the companion theme reads these values.', 'skips loading the palette’s scripts and styles.', 'a new address would arrive unfiltered.', '(1 to 12)', '(100 to 400)', 'Saving a change refreshes the site’s cached pages, so the live site shows it the next time a page loads.' ) as $line ) {
	ok( false !== strpos( $classic_text, $line ), 'the classic twin says it too: ' . $line );
}
ok( 1 !== preg_match( '/Render knobs|JS\/CSS|local part|re-renders|\x{2014}|\x{2013}/u', $kit . $classic_text ), 'neither twin says "render knobs", "JS/CSS", "local part" or "re-renders", or carries an em or en dash' );
$options = array();
foreach ( $GLOBALS['__aliases'] as $a ) { $options[] = '<os-option value="' . $a . '">' . $a . '@</os-option>'; }
ok( has_all( $kit, $options, $missing ) && 5 === substr_count( $kit, '<os-option ' ) && 5 === substr_count( $classic, '<option ' ), 'the alias select offers exactly the five allowlisted aliases as alias@, as the classic select does' );

// ── Rich fixture: every knob stored off its default, palette off, another alias.
$GLOBALS['__settings'] = array(
	'theme.related_count'          => 5,
	'theme.palette_recent_count'   => 0,
	'theme.palette_enabled'        => false,
	'theme.updated_threshold_days' => 45,
	'theme.reading_wpm'            => 310,
	'theme.note_reply_alias'       => 'speaking',
);
$classic = fe_classic_form();
$kit     = fe_kit_form();
ok( snt_leaf_names( $classic ) === snt_leaf_names( $kit ), 'rich: field names still match the classic form' );
$stored = array( $number_tag( 'theme_related_count', 5 ), $number_tag( 'theme_palette_recent_count', 0 ), $number_tag( 'theme_updated_threshold_days', 45 ), $number_tag( 'theme_reading_wpm', 310 ) );
ok( has_all( $kit, $stored, $missing ), 'rich: every number knob carries its stored value (0 included) with its bounds' . ( $missing ? ' — missing ' . implode( ' | ', $missing ) : '' ) );
ok( false !== strpos( $kit, '<os-select name="theme_note_reply_alias" value="speaking">' ) && false !== strpos( $classic, 'value="speaking" selected="selected"' ), 'rich: the stored alias is the select value, the one the classic select marks selected' );
ok( false !== strpos( $kit, '<os-checkbox-label name="theme_palette_enabled" value="1" label="Enable the ⌘K command palette and its footer trigger">' ) && false === strpos( $kit, ' checked' ) && false === strpos( $classic, 'checked="checked"' ), 'palette off: the checkbox is unchecked, as on the classic leaf' );

// ── Escaping: a hostile stored alias and a hostile allowlist entry never reach the markup raw;
// a hostile number reads as the classic (int) cast does.
$GLOBALS['__settings']['theme.note_reply_alias'] = '"><script>x</script>';
$GLOBALS['__settings']['theme.related_count']    = '"><script>y</script>';
$GLOBALS['__aliases'][]                          = '<img src=x onerror=1>';
$kit = fe_kit_form();
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, 'value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"' ), 'a hostile stored alias is escaped' );
ok( false === strpos( $kit, '<img' ) && false !== strpos( $kit, '<os-option value="&lt;img src=x onerror=1&gt;">&lt;img src=x onerror=1&gt;@</os-option>' ), 'a hostile allowlist entry is escaped in both the option value and its label' );
ok( false !== strpos( $kit, $number_tag( 'theme_related_count', 0 ) ), 'a hostile number reads as 0, the classic (int) cast' );
array_pop( $GLOBALS['__aliases'] );
$GLOBALS['__settings'] = array();

// ── A one-alias allowlist (the classic fallback shape when the reader is absent): one option.
$GLOBALS['__aliases'] = array( 'research' );
$classic = fe_classic_form();
$kit     = fe_kit_form();
ok( 1 === substr_count( $kit, '<os-option ' ) && false !== strpos( $kit, '<os-option value="research">research@</os-option>' ) && 1 === substr_count( $classic, '<option ' ), 'a one-alias allowlist paints one option, as the classic select does' );

$GLOBALS['__aliases'] = array( 'research', 'press', 'speaking', 'role', 'music' );
snt_leaf_preview( 'settings-front-end', fe_kit() );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
