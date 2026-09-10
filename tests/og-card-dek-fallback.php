<?php
/**
 * Tests: sn_og_card_dek_source() dek precedence and sn_resolve_og_image_url()
 * with the new theme-fallback seam. (plugin v9.3.0)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__desc']     = '';   // sn_seo_singular_description filter
$GLOBALS['__ogimage']  = '';   // sn_seo_singular_og_image filter
$GLOBALS['__override'] = '';   // _sn_og_image_url
$GLOBALS['__resolved'] = null; // sn_og_image_url_for_post result

function apply_filters( $tag, $value, $post = null ) {
	if ( 'sn_seo_singular_description' === $tag ) { return $GLOBALS['__desc']; }
	// Model real WP: with no listener, apply_filters returns $value ($default).
	// A non-empty __ogimage simulates a theme listener returning a fallback URL.
	if ( 'sn_seo_singular_og_image' === $tag ) {
		return '' !== $GLOBALS['__ogimage'] ? $GLOBALS['__ogimage'] : $value;
	}
	return $value;
}
// v9.84.0 ladder: the theme-fallback seam moves to apply_filters_deprecated.
// Recorder + passthrough that models real WP (deprecated apply still filters).
$GLOBALS['__deprecated_calls'] = array();
function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) {
	$GLOBALS['__deprecated_calls'][] = array( 'tag' => $tag, 'version' => $version, 'message' => $message );
	return apply_filters( $tag, ...$args );
}
function strip_shortcodes( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return $s; }
function wp_trim_words( $s, $n = 55, $more = '...' ) { return trim( $s ); }
function sn_post_settings_get_og_image_url( $id ) { return $GLOBALS['__override']; }
function add_action() {} function add_filter() {}

// sn_og_image_url_for_post() is declared unguarded in the file, so we can't
// stub it — instead drive it through its real dependencies. $GLOBALS['__resolved']
// non-null → featured-image branch returns it; null → wp_upload_dir errors so
// sn_og_upload_dir() bails and the function returns null (theme-fallback path).
function get_post( $p = null ) { return is_object( $p ) ? $p : null; }
function has_post_thumbnail( $post ) { return null !== $GLOBALS['__resolved']; }
function get_the_post_thumbnail_url( $post, $size = 'large' ) { return $GLOBALS['__resolved']; }
function wp_upload_dir() { return array( 'error' => 'test: no uploads dir' ); }

require __DIR__ . '/../inc/og-card-generator.php';

echo "Group: card dek precedence\n";
$post = (object) array( 'ID' => 1, 'post_excerpt' => 'Hand excerpt.', 'post_content' => 'Body.' );
ok( 'Hand excerpt.' === sn_og_card_dek_source( $post ), 'post_excerpt wins' );

$post = (object) array( 'ID' => 2, 'post_excerpt' => '', 'post_content' => 'Derived body text.' );
ok( 'Derived body text.' === sn_og_card_dek_source( $post ), 'content-derived when no excerpt' );

$GLOBALS['__desc'] = 'Curated theme line.';
$post = (object) array( 'ID' => 3, 'post_excerpt' => '', 'post_content' => '' );
ok( 'Curated theme line.' === sn_og_card_dek_source( $post ), 'theme fallback when body + excerpt empty' );

$GLOBALS['__desc'] = '';
ok( '' === sn_og_card_dek_source( $post ), 'blank when every source is empty' );

echo "\nGroup: og:image resolution\n";
$p = (object) array( 'ID' => 9 );
$GLOBALS['__override'] = 'https://x/override.png';
ok( 'https://x/override.png' === sn_resolve_og_image_url( 'site-default.png', $p ), 'per-page override wins' );

$GLOBALS['__override'] = '';
$GLOBALS['__resolved'] = 'https://x/card.png';
ok( 'https://x/card.png' === sn_resolve_og_image_url( 'site-default.png', $p ), 'featured/generated card next' );

$GLOBALS['__resolved'] = null;
$GLOBALS['__ogimage']  = 'https://x/theme-route.png';
ok( 'site-default.png' === sn_resolve_og_image_url( 'site-default.png', $p ), 'v10.0.0: the sn_seo_singular_og_image seam is REMOVED — a listener can no longer influence the URL (it was shadowed dead code)' );

$GLOBALS['__ogimage'] = '';
ok( 'site-default.png' === sn_resolve_og_image_url( 'site-default.png', $p ), 'site default is the floor' );
ok( 'site-default.png' === sn_resolve_og_image_url( 'site-default.png', null ), 'null post returns the default' );

echo "\nGroup: v10.0.0 — the og seam is removed\n";
// The theme-fallback resolutions above must have flowed through
// apply_filters_deprecated — the 9.x marker the v10.0.0 removal rides on.
$og = array_values( array_filter(
	$GLOBALS['__deprecated_calls'],
	function ( $c ) { return 'sn_seo_singular_og_image' === $c['tag']; }
) );
ok( 0 === count( $og ), 'v10.0.0: no deprecated-apply remains — the seam is gone, not merely marked' );


/* ── A LEADING HEADING IS NOT THE DEK (v13.109.2) ──────────────────────────
 * The card printed the page's own title twice: once as the 88px Bebas title,
 * then again as the first words of the dek —
 *   "ON PROVENANCE / On Provenance Two papers, three long-form essays…"
 * — and those wasted words pushed the real sentence into the ellipsis.
 *
 * It needs BOTH conditions, which is why it hid for so long: a page template
 * that renders post-content ALONE (so the <h1> must live inside the content)
 * AND an empty excerpt. Notes take their title from post_title and open with
 * prose, so this path never met a heading.
 */
// NOTE ON THIS HARNESS: wp_strip_all_tags() is stubbed as a passthrough above,
// so a derived dek still carries its markup here — unlike production, where the
// tags are gone by this point. These assertions therefore strip with PHP's own
// strip_tags() before comparing. A first draft compared against clean text and
// failed against correct code.
$sn_txt = static function ( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); };

$post = (object) array( 'ID' => 10, 'post_excerpt' => '',
	'post_content' => '<!-- wp:heading {"level":1} -->' . "\n" . '<h1 class="wp-block-heading">On Provenance</h1>' . "\n" . '<!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph -->' . "\n" . '<p>Two papers, three long-form essays, and a running series of notes.</p>' . "\n" . '<!-- /wp:paragraph -->' );
$dek = sn_og_card_dek_source( $post );
ok( false === strpos( $sn_txt( $dek ), 'On Provenance' ), 'a leading <h1> is dropped — the dek does not repeat the title' );
ok( 0 === strpos( $sn_txt( $dek ), 'Two papers' ), 'and the dek starts at the first real sentence' );

// A bare <h1> with no block comment wrapper — hand-written or migrated content.
$post = (object) array( 'ID' => 11, 'post_excerpt' => '',
	'post_content' => '<h1>Title Here</h1><p>The body begins.</p>' );
ok( 0 === strpos( $sn_txt( sn_og_card_dek_source( $post ) ), 'The body begins' ), 'works without the block-comment wrapper too' );

// h2 as the opener (a page that leads with a section heading).
$post = (object) array( 'ID' => 12, 'post_excerpt' => '',
	'post_content' => '<h2>Section</h2><p>Prose follows.</p>' );
ok( 0 === strpos( $sn_txt( sn_og_card_dek_source( $post ) ), 'Prose follows' ), 'any leading h1-h6 is dropped, not just h1' );

/* THE CONTROLS. Only the LEADING heading goes. A heading further down is a
 * section title inside the prose, and stripping every one of them would splice
 * unrelated sentences together — a subtler corruption than the bug being
 * fixed, because it would still read as a sentence. */
$post = (object) array( 'ID' => 13, 'post_excerpt' => '',
	'post_content' => '<p>Opening sentence.</p><h2>A section</h2><p>More prose.</p>' );
$dek = sn_og_card_dek_source( $post );
ok( 0 === strpos( $sn_txt( $dek ), 'Opening sentence' ), 'CONTROL: content that starts with prose is untouched' );
ok( false !== strpos( $sn_txt( $dek ), 'A section' ), 'CONTROL: a heading further down is KEPT — only the leading one is dropped' );

// And a hand-written excerpt still wins over all of it.
$post = (object) array( 'ID' => 14, 'post_excerpt' => 'Hand-written.',
	'post_content' => '<h1>Ignored</h1><p>Also ignored.</p>' );
ok( 'Hand-written.' === sn_og_card_dek_source( $post ), 'CONTROL: an excerpt still wins — precedence is unchanged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
