<?php
/**
 * Tests: the per-post settings ride the editor's native document panel (#1608).
 *
 * inc/post-settings.php no longer adds a meta box; it enqueues
 * assets/post-settings-panel.js on the block editor screens, registers the
 * prepop sentinels in REST so the panel's notice can read them, and clears
 * them on a REST save the way the classic save did. The script registers the
 * `snt-post-settings` plugin whose render is a PluginDocumentSettingPanel
 * over useEntityProp, with the meta box's fields and labels, and the three
 * AI scripts contribute actions instead of polling the DOM.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SNT_PATH', __DIR__ . '/../' );
define( 'SNT_VERSION', '0.0.0-test' );

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__hooks']      = array();
$GLOBALS['__registered'] = array();
$GLOBALS['__scripts']    = array();
$GLOBALS['__enqueued']   = array();
$GLOBALS['__inline']     = array();
$GLOBALS['__cleared']    = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $c; }
function add_meta_box() { $GLOBALS['__meta_box_calls'] = ( $GLOBALS['__meta_box_calls'] ?? 0 ) + 1; }
function register_post_meta( $type, $key, $args ) { $GLOBALS['__registered'][ $type ][ $key ] = $args; }
function current_user_can( $cap, $id = null ) { return true; }
function wp_register_script( $h, $src, $deps, $ver, $footer ) { $GLOBALS['__scripts'][ $h ] = compact( 'src', 'deps', 'ver', 'footer' ); }
function wp_enqueue_script( $h ) { $GLOBALS['__enqueued'][] = $h; }
function wp_add_inline_script( $h, $code, $pos ) { $GLOBALS['__inline'][ $h ] = array( $code, $pos ); }
function wp_set_script_translations( $h, $d ) {}
function plugins_url( $path, $file ) { return 'https://example.test/plugins/' . $path; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_current_screen() { return $GLOBALS['__screen']; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
// The prepop module the panel's notice reads through; loaded before post-settings in the plugin.
function sn_prepop_fields() { return array( '_sn_autogen_meta_description' => 'meta description', '_sn_autogen_excerpt' => 'excerpt', '_sn_autogen_og_card_title' => 'OG card title' ); }
function sn_prepop_clear_sentinels( $id ) { $GLOBALS['__cleared'][] = (int) $id; }

$php = (string) file_get_contents( __DIR__ . '/../inc/post-settings.php' );
require __DIR__ . '/../inc/post-settings.php';

echo "Group: the meta box is gone\n";
ok( false === strpos( $php, 'add_meta_box(' ), 'inc/post-settings.php no longer calls add_meta_box' );
ok( ! isset( $GLOBALS['__hooks']['add_meta_boxes'] ), 'nothing hooks add_meta_boxes' );
ok( ! function_exists( 'sn_post_settings_render' ), 'the classic render function is gone' );
ok( false === strpos( $php, 'sn_prepop_render_notice' ), 'the in-box prepop notice call is gone with the box' );
ok( ! function_exists( 'sn_post_settings_save' ), 'the classic save handler is gone with the form that fed it' );
ok( ! isset( $GLOBALS['__hooks']['save_post'] ), 'nothing hooks save_post' );
ok( false === strpos( $php, 'sn_post_settings_nonce' ) && false === strpos( $php, 'SN_POST_SETTINGS_NONCE' ), 'no nonce is named: the entity save carries the meta' );

echo "\nGroup: every key rides REST, the sentinels included\n";
sn_post_settings_register_meta();
foreach ( array( 'post', 'page' ) as $t ) {
	foreach ( array_keys( sn_prepop_fields() ) as $sentinel ) {
		$args = $GLOBALS['__registered'][ $t ][ $sentinel ] ?? array();
		ok( true === ( $args['show_in_rest'] ?? null ) && 'boolean' === ( $args['type'] ?? '' ), "$sentinel on '$t' is a REST boolean for the panel's notice" );
	}
}
ok( true === ( $GLOBALS['__registered']['page']['_sn_pillar']['show_in_rest'] ?? null ), '_sn_pillar rides REST' );
ok( true === ( $GLOBALS['__registered']['page']['_sn_pillar_designation']['show_in_rest'] ?? null ), '_sn_pillar_designation rides REST' );

echo "\nGroup: the panel script is enqueued on the block editor screens\n";
ok( in_array( 'sn_post_settings_enqueue_panel', $GLOBALS['__hooks']['enqueue_block_editor_assets'] ?? array(), true ), 'sn_post_settings_enqueue_panel hooks enqueue_block_editor_assets' );
// The hook also fires in the site editor and the widgets editor, where the
// panel slot mounts for a template: only a post or page document takes it.
foreach ( array(
	'site-editor' => (object) array( 'base' => 'site-editor', 'post_type' => '' ),
	'widgets'     => (object) array( 'base' => 'widgets', 'post_type' => '' ),
	'attachment'  => (object) array( 'base' => 'post', 'post_type' => 'attachment' ),
	'no screen'   => null,
) as $name => $screen ) {
	$GLOBALS['__screen'] = $screen;
	if ( function_exists( 'sn_post_settings_enqueue_panel' ) ) { sn_post_settings_enqueue_panel(); }
	ok( array() === $GLOBALS['__scripts'] && array() === $GLOBALS['__enqueued'], "$name: the panel stays out" );
}
$GLOBALS['__screen'] = (object) array( 'base' => 'post', 'post_type' => 'page' );
if ( function_exists( 'sn_post_settings_enqueue_panel' ) ) { sn_post_settings_enqueue_panel(); }
$reg = $GLOBALS['__scripts']['snt-post-settings-panel'] ?? array();
ok( false !== strpos( $reg['src'] ?? '', 'assets/post-settings-panel.js' ), 'registers assets/post-settings-panel.js' );
foreach ( array( 'wp-plugins', 'wp-editor', 'wp-core-data', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ) as $dep ) {
	ok( in_array( $dep, $reg['deps'] ?? array(), true ), "depends on $dep" );
}
ok( in_array( 'snt-post-settings-panel', $GLOBALS['__enqueued'], true ), 'and enqueues it' );
$inline = $GLOBALS['__inline']['snt-post-settings-panel'] ?? array( '', '' );
ok( 'before' === $inline[1] && false !== strpos( $inline[0], 'window.sntPostSettingsConfig = {"prepop":{"_sn_autogen_meta_description":"meta description"' ), 'hands the panel the sentinel labels from sn_prepop_fields, as an object' );

echo "\nGroup: a REST save acknowledges the prepop notice\n";
foreach ( array( 'post', 'page' ) as $t ) {
	ok( in_array( 'sn_post_settings_rest_after_insert', $GLOBALS['__hooks'][ 'rest_after_insert_' . $t ] ?? array(), true ), "rest_after_insert_$t clears the sentinels" );
}
if ( function_exists( 'sn_post_settings_rest_after_insert' ) ) { sn_post_settings_rest_after_insert( (object) array( 'ID' => 44 ) ); }
ok( array( 44 ) === $GLOBALS['__cleared'], 'the hook delegates to sn_prepop_clear_sentinels on the saved post' );

echo "\nGroup: the script is the native panel\n";
$js_path = __DIR__ . '/../assets/post-settings-panel.js';
ok( file_exists( $js_path ), 'assets/post-settings-panel.js exists' );
$js = file_exists( $js_path ) ? (string) file_get_contents( $js_path ) : '';
ok( false !== strpos( $js, "wp.plugins.registerPlugin( 'snt-post-settings'" ), "registers the 'snt-post-settings' plugin" );
ok( false !== strpos( $js, 'wp.editor.PluginDocumentSettingPanel' ), 'renders wp.editor.PluginDocumentSettingPanel' );
ok( false !== strpos( $js, "wp.coreData.useEntityProp" ) && false !== strpos( $js, "useEntityProp( 'postType', editor.postType, 'meta' )" ), 'reads and writes meta through useEntityProp on the post entity' );
ok( false !== strpos( $js, "title: 'Signal & Noise'" ), 'the panel keeps the meta box title' );
foreach ( array(
	'Sign this page (provenance)', 'Evergreen (timeless)', 'Hide from search engines (noindex)',
	'vouch for outbound links (nofollow)', 'No cached copy (noarchive)', 'Hide images from image search (noimageindex)',
	'SEO title', 'Meta description', 'Focus keyword', 'Canonical URL', 'OG image URL', 'OG card title',
	'Feature as a pillar essay', 'Pillar designation',
) as $label ) {
	ok( false !== strpos( $js, $label ), "label survives: $label" );
}
foreach ( array( '_sn_prov_sign', '_sn_evergreen', '_sn_noindex', '_sn_nofollow', '_sn_noarchive', '_sn_noimageindex', '_sn_seo_title', '_sn_meta_description', '_sn_focus_keyword', '_sn_canonical_url', '_sn_og_image_url', '_sn_og_card_title', '_sn_pillar', '_sn_pillar_designation' ) as $key ) {
	ok( false !== strpos( $js, "key: '$key'" ), "field: $key" );
}
ok( false !== strpos( $js, "key: '_sn_focus_keyword', kind: 'text', maxLength: 80" ), 'the keyword input keeps its 80-char maxlength' );

echo "\nGroup: save semantics are the POST handler's bytes\n";
ok( false !== strpos( $js, 'v ? true : null' ), 'a flag writes true (stored 1) or null (deleted)' );
ok( 2 === substr_count( $js, "'' === v ? null : v" ), 'text and textarea write the string or null (deleted), never an empty string' );
ok( false === strpos( $js, 'sn_post_settings_nonce' ) && false === strpos( $js, 'wp.apiFetch(' ), 'the panel never posts a nonce or its own request: the entity save carries the meta' );
// The editor sends the whole meta object and core stores a never-written
// key at its default as an '' row, so every S&N key at its default goes
// back as null before each entity write.
ok( false !== strpos( $js, "if ( '' === next[ k ] || false === next[ k ] ) {" ) && false !== strpos( $js, 'next[ k ] = null;' ), 'normalize() turns every S&N key at its default into null' );
ok( false !== strpos( $js, 'var OWN_KEYS = FIELDS.map( function( f ) { return f.key; } ).concat( Object.keys( cfg.prepop || {} ) );' ), 'over the field table and the prepop sentinels, no other plugin\'s keys' );
ok( 2 === substr_count( $js, 'setMeta( normalize( next ) );' ) && false === strpos( $js, 'setMeta( next );' ), 'both entity writes (a field edit, the notice dismiss) go through normalize()' );

echo "\nGroup: the AI scripts are panel actions, not DOM pollers\n";
foreach ( array( 'ai-meta-description.js' => '_sn_meta_description', 'ai-og-card-title.js' => '_sn_og_card_title', 'ai-excerpt.js' => null ) as $file => $field ) {
	$src = (string) file_get_contents( __DIR__ . '/../assets/' . $file );
	ok( false !== strpos( $src, 'window.sntPostSettingsActions.push(' ), "$file pushes an action onto window.sntPostSettingsActions" );
	ok( false === strpos( $src, 'getElementById' ) && false === strpos( $src, 'setTimeout' ), "$file no longer polls the DOM" );
	if ( $field ) {
		ok( false !== strpos( $src, "field: '$field'" ), "$file names its field" );
	} else {
		ok( false !== strpos( $src, "editPost( { excerpt: text } )" ), "$file writes the excerpt through the editor store" );
	}
}
ok( false !== strpos( $js, 'window.sntPostSettingsActions' ) && false !== strpos( $js, 'props.apply( res.value )' ), 'the panel paints the actions and writes a returned value into the field' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
