<?php
/**
 * The English fallback of an empty Spanish page (inc/bilingual-render.php),
 * run through core's own code: the real hook API (wp-includes/plugin.php), the
 * real core/post-content render callback (wp-includes/blocks/post-content.php)
 * and the real WP_HTML_Tag_Processor.
 *
 * The fallback used to print a hard-coded copy of core's wrapper. It now hands
 * core the English body and lets core wrap it, so the wrapper follows the
 * template (tag name, layout, block supports).
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/bilingual-fallback-on-core.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; } else { $fail++; echo "  FAIL - $label\n"; }
}

require_once __DIR__ . '/lib/wp-core-blocks.php';
$inc = dirname( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ) );
if ( ! is_file( "$inc/plugin.php" ) || ! is_file( "$inc/blocks/post-content.php" ) ) {
	echo "FAIL: core's plugin.php and blocks/post-content.php are not on this machine. Run bash tools/fetch-wp-test-core.sh <dir>, then set SNT_WP_HTML_API=<dir>/wp-includes/html-api.\n";
	echo "Result: 0 passed, 1 failed.\n";
	exit( 1 );
}
require "$inc/plugin.php";
require_once __DIR__ . '/lib/wp-core-kses.php'; // set_attribute() asks core's kses.php which attributes hold a URL.
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
function __( $s, $d = null ) { return $s; }
function wp_has_noncharacters( string $text ): bool { return false; } // core's utf8.php; set_attribute() asks it about the value.

// A tiny site: 11 music (English), 21 musica (Spanish, empty), 30 a post in a query loop.
$GLOBALS['meta']    = array( 21 => 11 );
$GLOBALS['status']  = array( 11 => 'publish', 21 => 'publish', 30 => 'publish' );
$GLOBALS['names']   = array( 11 => 'music', 21 => 'musica', 30 => 'a-note' );
$GLOBALS['content'] = array( 11 => 'English body', 21 => '', 30 => '' );
$GLOBALS['q']       = 21;   // queried id.
$GLOBALS['loop']    = null; // the post a query loop has set up, if any.
function get_post_meta( $id, $k, $s ) { return $GLOBALS['meta'][ $id ] ?? ''; }
function get_post_type( $id ) { return isset( $GLOBALS['status'][ $id ] ) ? 'page' : false; }
function get_post_status( $id ) { return $GLOBALS['status'][ $id ] ?? false; }
function get_post_field( $f, $id ) { return 'post_name' === $f ? ( $GLOBALS['names'][ $id ] ?? '' ) : ( $GLOBALS['content'][ $id ] ?? '' ); }
function get_posts( $a ) { return array(); }
function get_option( $k, $d = false ) { return 'page_on_front' === $k ? 383 : $d; }
function get_queried_object_id() { return $GLOBALS['q']; }
function get_queried_object() { return (object) array( 'ID' => $GLOBALS['q'], 'post_name' => $GLOBALS['names'][ $GLOBALS['q'] ] ); }
function is_singular( $t = '' ) { return true; }
function is_page( $slug = '' ) { return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_permalink( $id ) { return 'https://x.test/' . $GLOBALS['names'][ $id ] . '/'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function sn_setting( $k, $d = '' ) { return $d; }
function post_password_required( $id ) { return ! empty( $GLOBALS['pw'][ $id ] ); }
// What core's render callback reaches for.
function get_the_ID() { return $GLOBALS['loop'] ?? $GLOBALS['q']; }
function get_the_content() { return $GLOBALS['content'][ get_the_ID() ] ?? ''; }
function has_block( $name ) { return false; }
function get_block_wrapper_attributes( $extra = array() ) { return 'class="' . $extra['class'] . ' wp-block-post-content"'; }

require "$inc/blocks/post-content.php";
require __DIR__ . '/../inc/bilingual.php';

// Stand-ins for two things core hangs on these hooks: wpautop on the_content, and layout support on render_block.
add_filter( 'the_content', static function ( $c ) { return '' === trim( $c ) ? $c : '<p>' . $c . '</p>'; } );
add_filter(
	'render_block',
	static function ( $html, $block ) {
		$p = new WP_HTML_Tag_Processor( $html );
		if ( $p->next_tag() ) {
			$type = 'constrained' === ( $block['attrs']['layout']['type'] ?? 'default' ) ? 'constrained' : 'flow';
			$p->add_class( "is-layout-$type" );
			$p->add_class( "wp-block-post-content-is-layout-$type" );
		}
		return $p->get_updated_html();
	},
	10,
	2
);

/** One core/post-content block, rendered in the order render_block() and WP_Block::render() run. */
function render_post_content( $post_id, array $attrs = array() ) {
	$parsed   = array( 'blockName' => 'core/post-content', 'attrs' => $attrs );
	$instance = (object) array( 'context' => null === $post_id ? array() : array( 'postId' => $post_id ) );
	$pre      = apply_filters( 'pre_render_block', null, $parsed, null );
	if ( null !== $pre ) {
		return $pre;
	}
	$html = render_block_core_post_content( $attrs, '', $instance );
	$html = apply_filters( 'render_block', $html, $parsed, $instance );
	return apply_filters( 'render_block_core/post-content', $html, $parsed, $instance );
}
$armed = static function () { return false !== has_filter( 'the_content', 'sn_lang_content_fallback_body' ); };

echo "Group: an empty Spanish page shows the English body in core's wrapper\n";
$html = render_post_content( 21, array( 'layout' => array( 'type' => 'default' ) ) );
ok( '<div lang="en" class="entry-content wp-block-post-content is-layout-flow wp-block-post-content-is-layout-flow"><p>English body</p></div>' === $html, 'the template\'s own wrapper, marked lang="en", around the English body filtered once' );
$former = '<div class="entry-content wp-block-post-content is-layout-flow wp-block-post-content-is-layout-flow" lang="en"><p>English body</p></div>';
$attrs  = static function ( $h ) { $p = new WP_HTML_Tag_Processor( $h ); $p->next_tag(); $o = array(); foreach ( $p->get_attribute_names_with_prefix( '' ) as $n ) { $o[ $n ] = $p->get_attribute( $n ); } ksort( $o ); return $o; };
ok( $attrs( $former ) === $attrs( $html ) && substr( $former, strpos( $former, '>' ) ) === substr( $html, strpos( $html, '>' ) ), 'for a flow-layout template it is the element the hard-coded wrapper printed: same attributes and body, lang now written first' );
ok( ! $armed(), 'and nothing is left on the_content' );

echo "\nGroup: the wrapper follows the template, which the hard-coded copy could not\n";
$html = render_post_content( 21, array( 'tagName' => 'main', 'layout' => array( 'type' => 'constrained' ) ) );
ok( '<main lang="en" class="entry-content wp-block-post-content is-layout-constrained wp-block-post-content-is-layout-constrained"><p>English body</p></main>' === $html, 'tagName and a constrained layout come through (formerly always a flow-layout div)' );

echo "\nGroup: when the fallback must not run\n";
$GLOBALS['content'][21] = 'Cuerpo';
ok( '<div class="entry-content wp-block-post-content is-layout-flow wp-block-post-content-is-layout-flow"><p>Cuerpo</p></div>' === render_post_content( 21 ) && ! $armed(), 'a written Spanish body renders as itself, with no lang' );
$GLOBALS['content'][21] = '';
$GLOBALS['status'][11]  = 'private';
ok( '' === render_post_content( 21 ) && ! $armed(), 'a private English page lends no body' );
$GLOBALS['status'][11] = 'publish'; $GLOBALS['pw'][11] = true;
ok( '' === render_post_content( 21 ) && ! $armed(), 'a password-protected English page lends no body' );
$GLOBALS['pw'] = array();
$GLOBALS['q']  = 11;
ok( '<div class="entry-content wp-block-post-content is-layout-flow wp-block-post-content-is-layout-flow"><p>English body</p></div>' === render_post_content( 11 ) && ! $armed(), 'an English page never falls back' );
$GLOBALS['q'] = 21;

echo "\nGroup: the one-shot reaches the page's own block and nothing else\n";
$GLOBALS['loop'] = 30; // a query loop on the Spanish page, on an empty post.
ok( '' === render_post_content( 30 ) && ! $armed(), 'an empty post in a query loop does not take the English body' );
$GLOBALS['loop'] = null;
ok( '' === render_post_content( null ) && ! $armed(), 'a block core refuses to render (no postId) leaves nothing armed' );
ok( '' === apply_filters( 'the_content', '' ), 'so a later the_content call for this page, an excerpt say, stays empty' );
$html = render_post_content( 21 );
ok( false !== strpos( $html, 'English body' ) && '' === apply_filters( 'the_content', '' ), 'and after a real fallback the next the_content call is untouched too' );
ok( 'X' === apply_filters( 'render_block_core/post-content', 'X', array(), null ), 'a post-content block rendered without a fallback is returned as is' );
// A block inside the English body that filters empty content of its own (an excerpt, a nested render) while the body is still being filtered.
$GLOBALS['nested'] = null;
add_filter( 'the_content', static function ( $c ) { if ( false !== strpos( $c, 'English body' ) && null === $GLOBALS['nested'] ) { $GLOBALS['nested'] = 'x'; $GLOBALS['nested'] = apply_filters( 'the_content', '' ); } return $c; }, 9 );
render_post_content( 21 );
ok( '' === $GLOBALS['nested'], 'a the_content call nested inside the English body\'s own filtering does not take the body again: the one-shot is gone before it returns' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
