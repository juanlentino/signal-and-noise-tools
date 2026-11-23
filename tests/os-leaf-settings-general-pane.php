<?php
/**
 * Settings > General, the pane around its form, both twins: the native pane
 * (apps/sn-dashboard/parts/leaves/settings-general.php) and the classic form
 * (inc/admin-forms/identity-and-seo.php). The seventh pane of the Settings
 * tab, and its FIRST: a General pane opens a preferences window.
 *
 * Site > Identity & SEO moved here WHOLE. It was eighteen settings in one
 * form and nothing else (no reading, no preview), so sn_admin_subtab_moves()
 * redirects it and Site keeps Performance, Redirects and Broken links. Its
 * four sections, their order and their #sn-sec-<slug> anchors came with it.
 *
 * This suite: the registry, the redirects, the one whole form and what the
 * handler does to a field a form leaves out, and the person name's hint. The
 * fields themselves are tests/os-leaf-settings-general.php.
 *
 * Run: php tests/os-leaf-settings-general-pane.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
require_once __DIR__ . '/lib/whole-forms.php';

if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'name' === $k ? 'Example Site' : ''; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $s ) ), '-' ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }

require SNT_PATH . 'inc/settings.php';
require SNT_PATH . 'inc/admin-legacy-redirect.php';
require SNT_PATH . 'inc/admin-tabs.php';
require SNT_PATH . 'inc/admin-forms/identity-and-seo.php';
if ( is_file( SNT_PATH . 'apps/sn-dashboard/parts/leaves/settings-general.php' ) ) { // Absent before the change: red, not dead.
	require SNT_PATH . 'apps/sn-dashboard/parts/leaves/settings-general.php';
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

const GEN_HINT     = 'Leave empty to use your profile’s display name. A page with no author, such as an archive, uses the site title.';
const GEN_SECTIONS = array( 'identity', 'social', 'open-graph', 'seo-copy' );

$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ] = $t; }
$panes = (array) ( $by_tab['settings']['sub_tabs'] ?? array() );

// ── 1) The registry: General is the first pane; Site lost the leaf, whole.
ok( array( 'general', 'notifications', 'security', 'ai', 'front-end', 'accounts', 'analytics' ) === array_keys( $panes ), 'seven panes, General first, the six before it in the order they had: ' . implode( ',', array_keys( $panes ) ) );
ok( 'General' === ( $panes['general']['label'] ?? '' ) && 'sn_admin_render_identity_and_seo_form' === ( $panes['general']['render'] ?? '' ), 'the pane is labelled "General" and paints the one identity form' );
ok( GEN_SECTIONS === array_keys( (array) ( $panes['general']['sub_sections'] ?? array() ) ) && array( 'Identity', 'Social', 'Open Graph', 'SEO Copy' ) === array_column( (array) ( $panes['general']['sub_sections'] ?? array() ), 'label' ), 'its four sections came with it, in their order, with their labels' );
ok( array( 'performance', 'redirects', 'broken-links' ) === array_keys( $by_tab['site']['sub_tabs'] ), 'Site keeps Performance, Redirects and Broken links: ' . implode( ',', array_keys( $by_tab['site']['sub_tabs'] ) ) );
ok( 0 === strpos( (string) $by_tab['settings']['subtitle'], 'The plugin\'s own preferences, by pane. General:' ) && 0 === strpos( (string) $by_tab['site']['subtitle'], 'How the site itself behaves: performance readings, redirects, and broken links.' ) && false !== strpos( (string) $by_tab['site']['subtitle'], 'are in Settings › General.' ), 'the subtitles follow: Settings names General first; Site lists its three leaves and says where the identity settings went' );
ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['settings/general'] ) && array_keys( $panes ) === array_keys( \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'settings' ) ) && array( 'performance', 'redirects', 'broken-links' ) === array_keys( \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'site' ) ), 'native: the painter is settings/general and the window lists the same leaves' );
ok( ! is_file( SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-identity-and-seo.php' ) && ! is_file( SNT_PATH . 'apps/sn-dashboard/parts/leaves/site-identity-and-seo-parts.php' ), 'the old native leaf files are gone, not left painting under Site' );

// ── 2) The redirects: the old leaf, GET and in-flight POST, and the four flat slugs.
$here = array( 'tab' => 'settings', 'sub' => 'general', 'anchor' => null );
ok( null === sn_admin_canonical_destination( 'settings', 'general' ) && $here === sn_admin_post_redirect_target( 'settings', 'general' ), 'settings/general is canonical, and a save posted from it returns to it' );
ok( 'settings' === sn_admin_post_redirect_target( 'settings', '' )['tab'] && null === sn_admin_post_redirect_target( 'settings', '' )['sub'] && 'general' === array_key_first( $panes ), 'a save posted from the bare Settings tab returns to the bare tab, whose first pane is General' );
ok( $here === sn_admin_canonical_destination( 'site', 'identity-and-seo' ) && $here === sn_admin_post_redirect_target( 'site', 'identity-and-seo' ), 'site/identity-and-seo redirects to the pane, GET and in-flight POST alike' );
foreach ( GEN_SECTIONS as $slug ) {
	$want = array( 'tab' => 'settings', 'sub' => 'general', 'anchor' => $slug );
	ok( $want === sn_admin_canonical_destination( $slug, '' ) && $want === sn_admin_post_redirect_target( $slug, '' ), "the flat slug ?tab=$slug names the pane directly and keeps its #sn-sec-$slug anchor" );
}
foreach ( array( 'performance', 'redirects', 'broken-links' ) as $kept ) {
	ok( null === sn_admin_canonical_destination( 'site', $kept ), "site/$kept is still canonical" );
}

// ── 3) The section strip and the anchors, as they were.
$strip   = snt_leaf_classic_html( static function () { sn_admin_render_section_tabs( 'settings', 'general' ); } );
$classic = snt_leaf_classic_html( 'sn_admin_render_identity_and_seo_form' );
$kit     = snt_leaf_paint( 'settings', 'general' );
preg_match_all( '/href="#(sn-sec-[a-z-]+)"/', $strip, $hrefs );
preg_match_all( '/id="(sn-sec-[a-z-]+)"/', $classic, $ids );
preg_match_all( '/<os-tabpanel for="[a-z-]+" id="(sn-sec-[a-z-]+)"/', $kit, $kit_ids );
$anchors = array( 'sn-sec-identity', 'sn-sec-social', 'sn-sec-open-graph', 'sn-sec-seo-copy' );
ok( $anchors === $hrefs[1] && $anchors === $ids[1] && $anchors === $kit_ids[1], 'the strip, the classic sections and the native panels carry the same four anchors in the same order' );
ok( '' === snt_leaf_classic_html( static function () { sn_admin_render_section_tabs( 'site', 'identity-and-seo' ); } ), 'the Site tab paints no section strip for a leaf it no longer has' );

// ── 4) One whole form: sn_settings_save() writes every field on a plain save.
foreach ( array( 'form' => array( $classic, 'social_same_as[]' ), 'os-form' => array( $kit, 'social_same_as[0]' ) ) as $tag => $twin ) {
	$fields = array_merge( SNT_IDENTITY_SAVE_FIELDS, array( $twin[1] ) );
	ok( 1 === snt_whole_forms( $twin[0], $tag, 'save_identity', $fields ) && 18 === count( $fields ), "<$tag>: one form posts save_identity and it carries all eighteen fields the handler writes" );
}
ok( -1 === snt_whole_forms( '<form><input name="action" value="sn_save_identity"><input name="identity_person_name"></form>', 'form', 'save_identity', SNT_IDENTITY_SAVE_FIELDS ), 'CONTROL: a form posting save_identity with one field is refused' );
$GLOBALS['__options']['sn_settings'] = array(
	'identity' => array( 'site_name' => 'Override', 'person_name' => 'Juan Example', 'job_title' => 'Mixing Engineer', 'availability' => 'Booking', 'knows_about' => array( 'Mixing' ), 'locale' => 'es_AR' ),
	'social'   => array( 'twitter_handle' => '@juan', 'same_as' => array( 'https://a.example/juan' ) ),
	'og'       => array( 'default_image_url' => 'https://cdn.example/og.png', 'card_width' => 1600, 'card_height' => 900 ),
	'seo_copy' => array( 'home_title' => 'Home T' ),
);
sn_settings_save( array( 'identity_person_name' => 'Juan Example' ) );
$after = (array) $GLOBALS['__options']['sn_settings'];
ok( array( 'person_name' => 'Juan Example', 'availability' => '' ) === $after['identity'] && array( 'twitter_handle' => '', 'same_as' => array() ) === $after['social'] && array( 'default_image_url' => '', 'card_width' => 1200, 'card_height' => 630 ) === $after['og'] && '' === $after['seo_copy']['home_title'], 'why: a save carrying one field clears or resets the other seventeen (overrides, job title, topics, handle, profiles, OG image and size, SEO copy)' );
sn_setting_reset_cache();

// ── 5) The person name: what an empty field means, on both twins.
ok( 1 === preg_match( '#<input type="text" id="sn_identity_person_name" name="identity_person_name" value="[^"]*"><p class="sn-field-helper">' . preg_quote( GEN_HINT, '#' ) . '</p>#', html_entity_decode( $classic, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 'classic: the hint sits under the person name field, in the helper line its siblings use' );
ok( 1 === preg_match( '#<os-field-row label="Person name \(schema author\)" hint="' . preg_quote( GEN_HINT, '#' ) . '"><os-text-field name="identity_person_name"#', $kit ), 'native: the same sentence is that field row\'s hint' );
ok( 1 === substr_count( $classic, '<label class="sn-field-label" for="sn_identity_person_name">Person name (schema author)</label>' ), 'the label is the one it had, still bound to the field' );
ok( false === strpos( $kit . $classic . $strip, "\xe2\x80\x94" ), 'no em dash on either twin' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
