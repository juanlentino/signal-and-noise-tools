<?php
/**
 * Tests: the /workflow page (data layer, the hidden-row wall, the generator,
 * the Page upsert and the save handler).
 *
 * The wall: a map row whose "Show on page" is not checked must appear in NO
 * public output. Every public surface reads the generated Page, so the filter
 * sits at sn_workflow_public_data() and the generator reads nothing else.
 *
 * Run: php tests/workflow-page.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']  = array();
$GLOBALS['__page'] = null; // get_page_by_path('workflow')
$GLOBALS['__ins']  = array();
$GLOBALS['__upd']  = array();
$GLOBALS['__purged'] = array();

function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
// Like core: existing entities pass through (double_encode off). The body must not rely on it.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function delete_option( $k ) { $had = isset( $GLOBALS['__opt'][ $k ] ); unset( $GLOBALS['__opt'][ $k ] ); return $had; }
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return 'workflow' === $p ? $GLOBALS['__page'] : null; }
// Core unslashes what it is handed; so do these, so the stored body is what WP would store.
function wp_insert_post( $a, $e = false ) {
	$a = wp_unslash( $a );
	$GLOBALS['__ins'][] = $a;
	$GLOBALS['__page']  = (object) array( 'ID' => 77, 'post_status' => $a['post_status'], 'post_content' => $a['post_content'] );
	return 77;
}
function wp_update_post( $a, $e = false ) {
	$a = wp_unslash( $a );
	$GLOBALS['__upd'][] = $a;
	foreach ( $a as $k => $v ) { $GLOBALS['__page']->$k = $v; }
	return $a['ID'];
}
function do_action() {}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return false; } }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function sn_cf_purge_urls( $urls ) { $GLOBALS['__purged'][] = $urls; return true; }

require __DIR__ . '/../inc/generated-page-contract.php';
require __DIR__ . '/../inc/workflow-page.php';
require __DIR__ . '/../inc/workflow-page-render.php';
require __DIR__ . '/../inc/admin-post-actions/content.php';
require __DIR__ . '/../inc/admin-post-actions/workflow.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function wf_reset() { $GLOBALS['__opt'] = array(); $GLOBALS['__page'] = null; $GLOBALS['__ins'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__purged'] = array(); }
function wf_body() { return null === $GLOBALS['__page'] ? '' : (string) $GLOBALS['__page']->post_content; }

// ── (1)+(2) The hidden-row wall.
echo "Group: a map row not shown on the page appears nowhere public\n";
wf_reset();
$post = array( 'workflow' => array(
	'title' => 'Workflow',
	'dek'   => 'How the work gets done.',
	'map'   => array(
		array( 'title' => 'VISIBLE-TITLE', 'line' => 'VISIBLE-LINE', 'show' => '1' ),
		array( 'title' => 'HIDDEN-TITLE-7f3a', 'line' => 'HIDDEN-LINE-7f3a' ),                   // unchecked: posts nothing
		array( 'title' => 'ZERO-TITLE-7f3a', 'line' => 'ZERO-LINE-7f3a', 'show' => '0' ),
		array( 'title' => 'ODD-TITLE-7f3a', 'line' => 'ODD-LINE-7f3a', 'show' => 'yes' ),          // malformed
		array( 'title' => 'ARR-TITLE-7f3a', 'line' => 'ARR-LINE-7f3a', 'show' => array( '1' ) ),   // malformed
		'__M__' => array( 'title' => 'SPARE-TITLE-7f3a', 'line' => 'SPARE-LINE-7f3a' ),            // newly added row
	),
) );
ok( 'workflow_saved' === sn_handle_workflow_save( $post ), 'the save publishes' );
$html = wf_body();
$pub  = sn_workflow_public_data();
ok( false !== strpos( $html, 'VISIBLE-TITLE' ) && false !== strpos( $html, 'VISIBLE-LINE' ), 'the shown row is on the page' );
ok( 1 === count( $pub['map'] ) && 'VISIBLE-TITLE' === $pub['map'][0]['title'], 'sn_workflow_public_data() keeps only the shown row' );
ok( 1 === substr_count( $html, 'sn-workflow-map__item' ), 'the generated page has exactly one map item' );
ok( false === strpos( $html, '7f3a' ), 'no hidden row title or line reaches post_content (unchecked, "0", malformed, newly added)' );
ok( false === strpos( wp_json_encode_fallback( $pub ), '7f3a' ), 'no hidden row text is in the public data either' );
ok( false === strpos( $html, 'hidden' ) && false === strpos( $html, 'data-' ) && false === strpos( $html, '<!-- ' . 'HIDDEN' ), 'no hidden element, data attribute or comment stands in for a hidden row' );
$stored = get_option( SN_WORKFLOW_PAGE_OPTION );
ok( 6 === count( $stored['map'] ) && false === $stored['map'][1]['show'] && false === $stored['map'][3]['show'] && false === $stored['map'][4]['show'] && false === $stored['map'][5]['show'], 'every hidden row is still STORED, flagged false (the owner keeps them)' );
ok( 'SPARE-TITLE-7f3a' === $stored['map'][5]['title'], 'row order is posted order, the spare row last' );
ok( 'workflow_resynced' === sn_handle_workflow_save( $post ) && 1 === count( $GLOBALS['__ins'] ), 'an identical re-save re-renders the existing page, no second insert' );
$GLOBALS['__opt'][ SN_WORKFLOW_PAGE_OPTION ]['map'][1]['show'] = 'true';
ok( 1 === count( sn_workflow_public_data()['map'] ), 'a tampered stored flag that is not exactly true is hidden on read' );
unset( $GLOBALS['__opt'][ SN_WORKFLOW_PAGE_OPTION ]['map'][1]['show'] );
ok( 1 === count( sn_workflow_public_data()['map'] ), 'a stored row with NO flag is hidden on read' );

function wp_json_encode_fallback( $v ) { return json_encode( $v ); }

echo "\nGroup: page shape\n";
$ins = $GLOBALS['__ins'][0] ?? array();
ok( 0 === ( $ins['post_parent'] ?? -1 ) && 'workflow' === ( $ins['post_name'] ?? '' ) && 'page-workflow' === ( $ins['page_template'] ?? '' ) && 'page' === ( $ins['post_type'] ?? '' ) && 'publish' === ( $ins['post_status'] ?? '' ), 'top-level published page, slug workflow, template page-workflow' );
ok( 'Workflow' === ( $ins['post_title'] ?? '' ) && 'How the work gets done.' === ( $ins['post_excerpt'] ?? '' ), 'title is the stored Title, excerpt is the stored Dek' );
ok( 0 === strpos( $html, "<!-- wp:html -->\n<div class=\"sn-workflow-page\">" ) && "</div>\n<!-- /wp:html -->" === substr( $html, -strlen( "</div>\n<!-- /wp:html -->" ) ), 'one wp:html block around div.sn-workflow-page' );
ok( array( 'https://example.test/workflow', 'https://example.test/workflow/' ) === ( $GLOBALS['__purged'][0] ?? null ) && 2 === count( $GLOBALS['__purged'] ), 'the save and the re-save each purged /workflow (both slash forms)' );

// ── (3) Escaping.
echo "\nGroup: the sample body is literal text\n";
wf_reset();
$body = "<script>alert(1)</script>\n[gallery] and [/caption]\n--> <!-- wp:paragraph --> <!-- /wp:html -->";
sn_handle_workflow_save( array( 'workflow' => array( 'sample' => array( 'title' => 'Prompt [x]', 'body' => wp_slash( $body ) ) ) ) );
$html = wf_body();
ok( false === stripos( $html, '<script' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'no <script>, it is encoded text' );
ok( false === strpos( $html, '[gallery]' ) && false !== strpos( $html, '&#91;gallery&#93;' ) && false !== strpos( $html, '&#91;/caption&#93;' ), 'brackets are encoded, so no shortcode can run' );
ok( 1 === substr_count( $html, '<!-- wp:' ) && 1 === substr_count( $html, '<!-- /wp:' ) && 2 === substr_count( $html, '-->' ), 'the body cannot open or close a block: only the wrapper\'s own delimiters remain' );
ok( false !== strpos( $html, '--&gt; &lt;!-- wp:paragraph --&gt;' ), '"-->" and "<!-- wp:" are encoded text' );
ok( false !== strpos( $html, '<pre class="sn-workflow-sample__body" tabindex="0" role="region" aria-labelledby="sn-workflow-sample-title"><code>' ) && false !== strpos( $html, 'id="sn-workflow-sample-title">Prompt &#91;x&#93;</h2>' ), 'the body sits in the focusable pre region, named by the sample h2 (brackets encoded there)' );
ok( false !== strpos( $html, 'Prompt &#91;x&#93;</h2>' ), 'every other field is bracket-encoded too' );

// ── (4) Whitespace survives exactly.
echo "\nGroup: the body's whitespace survives normalize, store, generate\n";
wf_reset();
$body = "\n    leading spaces\n\tone tab\n\n\nthree blank lines above\ttrailing tab\t\nC:\\path\\to \"quoted\" & it's &amp; &lt;b&gt;\n";
sn_handle_workflow_save( array( 'workflow' => array( 'sample' => array( 'body' => wp_slash( $body ) ) ) ) );
ok( $body === get_option( SN_WORKFLOW_PAGE_OPTION )['sample']['body'], 'stored byte for byte (backslashes and quotes included)' );
ok( $body === sn_workflow_page_get()['sample']['body'], 'read back byte for byte (no second unslash)' );
ok( false !== strpos( wf_body(), '<code>' . htmlspecialchars( $body, ENT_QUOTES, 'UTF-8', true ) . '</code>' ), 'generated byte for byte inside <code>, first newline included' );
ok( false !== strpos( wf_body(), '&amp;amp; &amp;lt;b&amp;gt;' ), 'typed entities stay typed: "&amp;" shows as "&amp;", not "&"' );
ok( "a\0b" !== sn_workflow_verbatim( "a\0b" ) && 'ab' === sn_workflow_verbatim( "a\0b" ), 'a NUL byte is the one thing removed' );

// ── (5) Empty sections render nothing.
echo "\nGroup: an empty section renders nothing, heading included\n";
wf_reset();
$html = sn_workflow_page_html( sn_workflow_public_data( array( 'title' => 'Only a title', 'map' => array( array( 'title' => 'H', 'line' => 'L' ) ) ) ) );
ok( false !== strpos( $html, 'Only a title' ), 'the title renders' );
ok( false === strpos( $html, 'sn-workflow-sample' ) && false === strpos( $html, 'sn-workflow-map' ) && false === strpos( $html, 'sn-workflow-rules' ) && false === strpos( $html, '<h2' ) && false === strpos( $html, 'sn-workflow-dek' ), 'no empty sample, map (all rows hidden), rules or dek markup' );
ok( '' === sn_workflow_page_html( sn_workflow_public_data( array() ) ), 'an all-empty document generates nothing' );
ok( '' === sn_workflow_page_html( sn_workflow_public_data( array( 'map' => array( array( 'title' => 'H', 'line' => 'L' ) ) ) ) ), 'only hidden rows generates nothing' );
ok( 'workflow_nothing' === sn_handle_workflow_save( array( 'workflow' => array( 'title' => '  ', 'map' => array( '__M__' => array( 'title' => '', 'line' => '' ) ) ) ) ), 'an all-empty save reports nothing to publish' );
ok( array() === $GLOBALS['__ins'] && array() === $GLOBALS['__upd'] && array() === $GLOBALS['__purged'] && ! isset( $GLOBALS['__opt'][ SN_WORKFLOW_PAGE_OPTION ] ), '...and creates no page, purges nothing, stores nothing' );
ok( 'workflow_nothing' === sn_handle_workflow_save( array( 'workflow' => array( 'map' => array( array( 'title' => 'Private', 'line' => 'x' ) ) ) ) ) && array() === $GLOBALS['__ins'], 'only hidden rows: stored, but no page is created' );

echo "\nGroup: when nothing public is left, a live page goes to draft\n";
wf_reset();
sn_handle_workflow_save( array( 'workflow' => array( 'map' => array( array( 'title' => 'Once public', 'line' => 'x', 'show' => '1' ) ) ) ) );
ok( 'publish' === $GLOBALS['__page']->post_status, 'published while the row is shown' );
ok( 'workflow_withdrawn' === sn_handle_workflow_save( array( 'workflow' => array( 'map' => array( array( 'title' => 'Once public', 'line' => 'x' ) ) ) ) ), 'unchecking the last public row withdraws the page' );
ok( 'draft' === $GLOBALS['__page']->post_status && 2 === count( $GLOBALS['__purged'] ), '...to draft, and /workflow is purged' );
ok( '' === $GLOBALS['__page']->post_content, 'the withdrawn draft keeps none of the rows that were public' );
ok( 'workflow_saved' === sn_handle_workflow_save( array( 'workflow' => array( 'title' => 'Back' ) ) ) && 'publish' === $GLOBALS['__page']->post_status, 'content again republishes the same page' );

$GLOBALS['__page']->post_status = 'private';
sn_handle_workflow_save( array( 'workflow' => array( 'title' => 'Owner set private' ) ) );
ok( 'private' === $GLOBALS['__page']->post_status, 'a status the owner chose by hand (private) survives a save; only a page this module withdrew is republished' );

$only_map = sn_workflow_page_html( array( 'title' => '', 'dek' => '', 'sample' => array( 'label' => '', 'title' => '', 'intro' => '', 'body' => '', 'outcome' => '' ), 'map' => array( array( 'title' => 'One', 'line' => 'x', 'show' => true ) ), 'rules' => array() ) );
ok( false !== strpos( $only_map, '<h1 class="sn-workflow-title">Workflow</h1>' ) && strpos( $only_map, '<h1' ) < strpos( $only_map, '<section' ), 'with no Title the page still opens with an h1 (the Page title fallback), before any section' );

$headed = sn_workflow_page_html( sn_workflow_public_data( array( 'title' => 'W', 'map_heading' => 'The rest', 'rules_heading' => 'Field rules', 'map' => array( array( 'title' => 'One', 'line' => 'x', 'show' => '1' ) ), 'rules' => array() ) ) );
ok( false !== strpos( $headed, '<h2 class="sn-workflow-map__heading">The rest</h2>' ) && false === strpos( $headed, 'Field rules' ), 'a section heading renders with its rows; a heading over no rows renders nothing' );
$hidden_only = sn_workflow_page_html( sn_workflow_public_data( array( 'title' => 'W', 'map_heading' => 'The rest', 'map' => array( array( 'title' => 'Secret', 'line' => 'x' ) ) ) ) );
ok( false === strpos( $hidden_only, 'The rest' ), 'a map whose rows are all hidden renders no heading either' );

$GLOBALS['__page']->post_status = 'private'; $GLOBALS['__page']->post_content = 'old rows';
sn_handle_workflow_save( array( 'workflow' => array( 'map' => array( array( 'title' => 'Now hidden', 'line' => 'x' ) ) ) ) );
ok( 'private' === $GLOBALS['__page']->post_status && '' === $GLOBALS['__page']->post_content, 'a private page with nothing public left keeps its status and none of its rows' );
$named = sn_workflow_page_html( sn_workflow_public_data( array( 'title' => 'W', 'sample' => array( 'title' => 'Drafting', 'body' => "x\n" ), 'map' => array( array( 'title' => 'A', 'line' => 'b', 'show' => '1' ) ) ) ) );
ok( false !== strpos( $named, 'aria-labelledby="sn-workflow-sample-title"' ) && false !== strpos( $named, 'id="sn-workflow-sample-title"' ) && false !== strpos( $named, 'role="list"' ), 'the sample region is named by its h2; the unstyled map list keeps list semantics' );

echo "\nGroup: the write guard knows /workflow\n";
ok( false === snt_generated_page_guard( 'workflow', '<div>no wrapper</div>' ), 'a body without sn-workflow-page is refused' );

echo "\nGroup: the sample renders Label, Title, Intro, Outcome, Body\n";
$ord = sn_workflow_sample_html( array( 'label' => 'L', 'title' => 'T', 'intro' => 'I', 'outcome' => 'O', 'body' => 'B' ) );
$pos = array_map( static fn( $c ) => strpos( $ord, 'sn-workflow-sample__' . $c ), array( 'label', 'title', 'intro', 'outcome', 'body' ) );
ok( ! in_array( false, $pos, true ) && $pos === array_values( array_unique( $pos ) ) && $pos == array_values( ( static function ( $a ) { sort( $a ); return $a; } )( $pos ) ), 'the five parts render in order: label, title, intro, outcome, body' );
$no_out = sn_workflow_sample_html( array( 'label' => '', 'title' => '', 'intro' => 'I', 'outcome' => '', 'body' => 'B' ) );
ok( false === strpos( $no_out, 'sn-workflow-sample__outcome' ), 'an empty outcome renders nothing' );
ok( preg_match( '#sn-workflow-sample__intro">I</p><pre class="sn-workflow-sample__body"#', $no_out ) === 1, 'with no outcome, the body follows the intro directly' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
