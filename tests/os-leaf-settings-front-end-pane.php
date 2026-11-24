<?php
/**
 * Settings › Front end, the pane around its forms, both twins: the native
 * pane (apps/sn-dashboard/parts/leaves/settings-front-end.php) and the
 * classic section (inc/admin-forms/settings-front-end.php). The fourth pane
 * of the Settings tab.
 *
 * Site › Front-End moved here WHOLE: it was nothing but the one save_theme
 * form, so sn_admin_subtab_moves() redirects it. The speculative loading
 * switch moved as a FIELD: Site › Performance keeps its readings (which
 * profile is in effect, the Profile reference, the outbound calls) and one
 * line saying where the switch went, so it has no moves line.
 *
 * This suite: the registry, the redirect, the two whole forms, the switch
 * and what each state now means, and the leaf the switch left. The eight
 * save_theme fields themselves are tests/os-leaf-settings-front-end.php.
 *
 * Run: php tests/os-leaf-settings-front-end-pane.php
 */
require_once __DIR__ . '/lib/settings-front-end-fixture.php';

const FE_HINT  = 'On: pages a visitor is likely to open next are prerendered. Off: WordPress’s own default applies (prefetch, unless the host set another).';
const FE_LABEL = 'Enabled: prerender the pages a visitor is likely to open next';
const FE_PANE  = 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=settings&sub=front-end';

$GLOBALS['__settings'] = array( 'perf.speculative_loading' => true );
$classic = fe_classic();
$kit     = fe_kit();

// ── 1) The registry: a fourth pane; Site lost one leaf and kept Performance.
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );
ok( array( 'general', 'notifications', 'security', 'ai', 'front-end' ) === array_slice( array_keys( $panes ), 0, 5 ) && 'Front end' === ( $panes['front-end']['label'] ?? '' ) && 'sn_admin_render_settings_front_end_section' === ( $panes['front-end']['render'] ?? '' ) && ! empty( $panes['front-end']['wide'] ), 'front-end ("Front end") is the fifth pane (the fourth until General took first place), after ai, wide: it paints its own boxes' );
ok( array( 'performance', 'redirects', 'broken-links' ) === array_keys( $by_tab['site']['sub_tabs'] ), 'Site keeps three leaves: Front-End left whole (and Identity & SEO after it, for Settings > General), Performance stayed' );
ok( false !== strpos( (string) $by_tab['settings']['subtitle'], 'Front end:' ) && false === stripos( (string) $by_tab['site']['subtitle'], 'how pages render' ), 'the subtitles follow: Settings names the pane, Site no longer promises how pages render' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/front-end'] ) && array( 'general', 'notifications', 'security', 'ai', 'front-end', 'accounts', 'analytics' ) === array_keys( \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'settings' ) ) && ! is_file( SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-front-end.php' ), 'native: the painter is registered under settings/front-end, the frame lists seven panes, and the old leaf file is gone' );
$here = array( 'tab' => 'settings', 'sub' => 'front-end', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'front-end' ) && $here === sn_admin_post_redirect_target( 'settings', 'front-end' ), 'classic: settings/front-end is canonical and a save posted from it returns to it' );
ok( $here === sn_admin_canonical_destination( 'site', 'front-end' ) && $here === sn_admin_post_redirect_target( 'site', 'front-end' ) && null === sn_admin_canonical_destination( 'site', 'performance' ), 'the old leaf redirects, GET and in-flight POST alike; Site › Performance does not' );

// ── 2) The pane: seven settings in two whole forms on the classic twin; the
// native twin adds the two Reading numbers in a third, because their classic
// home is WordPress's Settings › Reading, which the native window does not paint.
$seven = array_merge( SNT_PERF_SAVE_FIELDS, SNT_THEME_SAVE_FIELDS );
$nine  = array_merge( $seven, SNT_READING_SAVE_FIELDS );
sort( $seven );
sort( $nine );
ok( '' !== $kit && $nine === snt_leaf_names( $kit ), 'native: the seven settings and the two Reading numbers: ' . implode( ',', snt_leaf_names( $kit ) ) );
ok( '' !== $classic && $seven === snt_leaf_names( $classic ), 'classic: exactly the seven settings: ' . implode( ',', snt_leaf_names( $classic ) ) );
ok( array( 'perf_save', 'reading_save', 'save_theme' ) === snt_leaf_actions( $kit ) && array( 'perf_save', 'save_theme' ) === snt_leaf_actions( $classic ), 'native posts perf_save, reading_save and save_theme; classic posts the two it had' );
ok( 1 === snt_whole_forms( $kit, 'os-form', 'reading_save', SNT_READING_SAVE_FIELDS ) && 1 === preg_match( '#<os-section heading="Notes and the JSON feed" stack><os-form [^>]*submit-label="Save"#', $kit ) && false !== strpos( $kit, '<p class="snt-hint">The same two numbers are on WordPress’s Settings › Reading too.</p>' ), 'native: one box, "Notes and the JSON feed", one form posting reading_save with both numbers, and a line naming Settings › Reading' );
ok( strpos( $kit, 'heading="Replies"' ) < strpos( $kit, 'heading="Notes and the JSON feed"' ) && strpos( $kit, 'heading="Notes and the JSON feed"' ) < strpos( $kit, 'heading="Performance"' ), 'native: the Reading box sits between the front-end form and Performance' );
ok( array() === snt_leaf_classic_markers( $kit ) && false === strpos( $kit, '<os-disclosure' ), 'native: no wp-admin markup, and no fold: a setting on a settings pane is open' );
foreach ( array( 'form' => $classic, 'os-form' => $kit ) as $tag => $html ) {
	ok( 1 === snt_whole_forms( $html, $tag, 'save_theme', SNT_THEME_SAVE_FIELDS ), "<$tag>: one form posts save_theme and it carries all six fields the handler writes" );
	ok( 1 === snt_whole_forms( $html, $tag, 'perf_save', SNT_PERF_SAVE_FIELDS ), "<$tag>: one form posts perf_save and it carries the switch" );
	ok( false === strpos( fe_form( $html, $tag, 'save_theme' ), 'speculative_loading' ) && false === strpos( fe_form( $html, $tag, 'perf_save' ), 'theme_' ), "<$tag>: the two forms are siblings, neither inside the other" );
}
ok( -1 === snt_whole_forms( '<form><input name="action" value="sn_save_theme"><input name="theme_related_count"></form>', 'form', 'save_theme', SNT_THEME_SAVE_FIELDS ) && -1 === snt_whole_forms( '<form>sn_perf_save</form>', 'form', 'perf_save', SNT_PERF_SAVE_FIELDS ) && 0 === snt_whole_forms( '<form>sn_other</form>', 'form', 'save_theme', SNT_THEME_SAVE_FIELDS ), 'the guard can fail: a save_theme form with one field is -1, a perf_save form without the switch is -1, an unrelated form is not counted' );
$GLOBALS['__written'] = array();
sn_handle_save_theme( array( 'theme_related_count' => '5' ) );
ok( 5 === $GLOBALS['__written']['theme.related_count'] && false === $GLOBALS['__written']['theme.palette_enabled'] && 'research' === $GLOBALS['__written']['theme.note_reply_alias'] && 6 === count( $GLOBALS['__written'] ), 'why the six share a form: a save that omits fields turns the palette off and resets the alias' );
ok( ! array_key_exists( 'theme.notes_per_page', $GLOBALS['__written'] ) && ! array_key_exists( 'theme.json_feed_items', $GLOBALS['__written'] ), 'a front-end save leaves Notes per page and JSON feed items alone: their form is on Settings › Reading now' );
$GLOBALS['__written'] = array();
ok( 'reading_saved' === sn_handle_reading_save( array( 'theme_notes_per_page' => '500', 'theme_json_feed_items' => '0' ) ) && array( 'theme.notes_per_page' => 100, 'theme.json_feed_items' => 1 ) === $GLOBALS['__written'], 'reading_save writes both numbers, clamped to the theme bounds (1 to 100, 1 to 50)' );
$GLOBALS['__written'] = array();
sn_handle_reading_save( array( 'theme_notes_per_page' => '12' ) );
ok( array( 'theme.notes_per_page' => 12 ) === $GLOBALS['__written'], 'reading_save keeps a value that is missing: it never resets one by omission' );
$GLOBALS['__written'] = array();
sn_handle_perf_save( array() );
ok( array( 'perf.speculative_loading' => false ) === $GLOBALS['__written'], 'and a perf_save without the switch turns it off: the one field, written on every save' );

// ── 3) The switch: the label it had, and a hint that says what each state does.
ok( 1 === preg_match( '#<os-section heading="Performance" stack><os-form class="snt-form" os-action="post" submit-label="Save"[^>]*><os-field-row label="Speculative loading" hint="' . preg_quote( FE_HINT, '#' ) . '"><os-checkbox-label name="speculative_loading" value="1" checked label="' . FE_LABEL . '"></os-checkbox-label></os-field-row><input type="hidden" name="action" value="sn_perf_save">#', $kit ), 'native: a box headed Performance, the checkbox with its label, the hint on its row, Save' );
ok( 1 === preg_match( '#<form method="post" action="[^"]*" class="sn-fieldset"><input type="hidden" name="_wpnonce" value="nonce-sn_perf_save"><input type="hidden" name="action" value="sn_perf_save"><h2 class="sn-fieldset-h">Performance</h2><div class="sn-field"><label class="sn-field-label">Speculative loading</label><label><input type="checkbox" name="speculative_loading" value="1" checked="checked"> ' . FE_LABEL . '</label><p class="sn-field-helper">' . preg_quote( FE_HINT, '#' ) . '</p></div><div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save</button></div></form>$#', $classic ), 'classic: the same box, the checkbox inside its label, the hint under it, its own Save, after the save_theme form' );
ok( strpos( $kit, 'sn_save_theme' ) < strpos( $kit, 'heading="Performance"' ) && strpos( $kit, 'heading="Replies"' ) < strpos( $kit, 'heading="Performance"' ), 'native: Performance is the fourth box, after the three the first form holds' );
$GLOBALS['__settings'] = array( 'perf.speculative_loading' => false );
ok( false === strpos( fe_form( fe_kit(), 'os-form', 'perf_save' ), ' checked' ) && false === strpos( fe_form( fe_classic(), 'form', 'perf_save' ), 'checked=' ), 'off: the switch paints unchecked on both twins' );
$GLOBALS['__settings'] = array();
ok( false !== strpos( fe_form( fe_kit(), 'os-form', 'perf_save' ), ' checked ' ) && false !== strpos( fe_form( fe_classic(), 'form', 'perf_save' ), 'checked="checked"' ), 'unset: the switch defaults to on, as the setting does' );
ok( false === strpos( $kit . $classic, "\xe2\x80\x94" ) && false === strpos( $kit . $classic, 'Outbound calls' ) && false === strpos( $kit . $classic, 'Profile' ), 'no em dash on either twin, and no reading came along (no ledger, no Profile)' );

// ── 4) Site › Performance: the readings, one pointer, no way to post the switch.
foreach ( array( true, false ) as $on ) {
	$GLOBALS['__settings'] = array( 'perf.speculative_loading' => $on );
	$old_c = snt_leaf_classic_html( 'sn_admin_render_performance_section' );
	$old_k = snt_leaf_paint( 'site', 'performance' );
	$state = $on ? 'on' : 'off';
	ok( array() === snt_leaf_names( $old_c ) && array() === snt_leaf_names( $old_k ) && 0 === snt_whole_forms( $old_c, 'form', 'perf_save', SNT_PERF_SAVE_FIELDS ) && 0 === snt_whole_forms( $old_k, 'os-form', 'perf_save', SNT_PERF_SAVE_FIELDS ) && false === strpos( $old_c . $old_k, 'perf_save' ), "$state: Site › Performance carries no field and no perf_save form on either twin" );
	ok( 1 === substr_count( $old_c, '<p class="sn-field-helper">The prerender switch moved to <a href="' . FE_PANE . '">Settings › Front end</a>.</p>' ), "$state, classic: one line says where the switch went, with a link to the pane" );
	ok( 1 === preg_match( '#<p class="snt-hint">The prerender switch moved to <os-button class="snt-go" variant="link" data-snt-tab="settings" data-snt-sub="front-end"[^>]*>Settings › Front end</os-button>\.</p>#', $old_k ) && 1 === substr_count( $old_k, 'moved to' ), "$state, native: the same line, once, an in-window link" );
	ok( false === stripos( $old_c . $old_k, 'disables speculative loading' ) && false === stripos( $old_c . $old_k, 'no speculation rules' ) && false === strpos( $old_c . $old_k, 'Speculative loading is o' ) && false === strpos( $old_c . $old_k, 'Enabling this' ), "$state: nothing left says off means no speculation rules, or speaks of a switch that is not here" );
}
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
