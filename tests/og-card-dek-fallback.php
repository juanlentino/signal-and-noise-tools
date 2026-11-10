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
	foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value ); }
	return $value;
}
// v9.84.0 ladder: the theme-fallback seam moves to apply_filters_deprecated.
// Recorder + passthrough that models real WP (deprecated apply still filters).
$GLOBALS['__deprecated_calls'] = array();
function apply_filters_deprecated( $tag, $args, $version = '', $replacement = '', $message = '' ) {
	$GLOBALS['__deprecated_calls'][] = array( 'tag' => $tag, 'version' => $version, 'message' => $message );
	return apply_filters( $tag, ...$args );
}
function strip_shortcodes( $s ) { return preg_replace( '/\[sn_reading_time[^\]]*\]/', '', (string) $s ); } // the one shortcode these cases use.
function wp_strip_all_tags( $s ) { return $s; }
function wp_trim_words( $s, $n = 55, $more = '...' ) { return trim( $s ); }
function sn_post_settings_get_og_image_url( $id ) { return $GLOBALS['__override']; }
function add_action() {}
// A registry, so the dek's render_block filter runs and its removal can be checked.
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function remove_filter( $tag, $cb, $p = 10 ) { $GLOBALS['__filters'][ $tag ] = array_values( array_filter( $GLOBALS['__filters'][ $tag ] ?? array(), static fn( $c ) => $c !== $cb ) ); return true; }

// sn_og_image_url_for_post() is declared unguarded in the file, so we can't
// stub it — instead drive it through its real dependencies. $GLOBALS['__resolved']
// non-null → featured-image branch returns it; null → wp_upload_dir errors so
// sn_og_upload_dir() bails and the function returns null (theme-fallback path).
function get_post( $p = null ) { return is_object( $p ) ? $p : null; }
function has_post_thumbnail( $post ) { return null !== $GLOBALS['__resolved']; }
function get_the_post_thumbnail_url( $post, $size = 'large' ) { return $GLOBALS['__resolved']; }
function wp_upload_dir() { return array( 'error' => 'test: no uploads dir' ); }

$GLOBALS['__seo'] = array(); // sn_seo_description_for_post() by post ID
function sn_seo_description_for_post( $p ) { return $GLOBALS['__seo'][ $p->ID ] ?? ''; }
function sn_get_reading_time( $p ) { return 6; }
require_once __DIR__ . '/lib/wp-html-api.php';
snt_test_load_excerpt_remove_blocks(); // core's real parse_blocks() and excerpt_remove_blocks(): the derived dek stands on them.
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

/* ── WHICH BLOCKS REACH THE DEK ────────────────────────────────────────────
 * The derived dek is the first words of the body. These cases pin which
 * blocks' text counts, on stored block markup, through core's real parser and
 * core's real excerpt_remove_blocks() (tests/lib/wp-html-api.php). Compared
 * as text: this harness passes tags through (see the note above).
 */
echo "\nGroup: which blocks reach the dek\n";
$p    = static fn( $t ) => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$body = 'Two papers and a running series of notes.';
$dek_cases = array(
	'paragraphs only'              => $p( 'First <strong>sentence</strong> with <a href="/x" title="a > b">a link</a>.' ) . "\n\n" . $p( 'Second sentence.' ),
	'a buttons block first'        => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/paper/">Read the paper</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' . $p( $body ),
	'an embed with a caption first' => '<!-- wp:embed {"url":"https://video.example/v/1","type":"video"} --><figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">' . "\nhttps://video.example/v/1\n" . '</div><figcaption class="wp-element-caption">The talk, recorded</figcaption></figure><!-- /wp:embed -->' . $p( $body ),
	'an image with a caption first' => '<!-- wp:image {"id":5,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="/i.png" alt="A ledger" class="wp-image-5"/><figcaption class="wp-element-caption">The 1954 ledger</figcaption></figure><!-- /wp:image -->' . $p( $body ),
	'a details block first'        => '<!-- wp:details --><details class="wp-block-details"><summary>Method</summary>' . $p( 'How the numbers were gathered.' ) . '</details><!-- /wp:details -->' . $p( $body ),
	'a plugin block first'         => '<!-- wp:sn/callout {"tone":"note"} --><div class="wp-block-sn-callout"><p>Updated in October.</p></div><!-- /wp:sn/callout -->' . $p( $body ),
	'a void dynamic block first'   => '<!-- wp:latest-posts {"postsToShow":3} /-->' . $p( $body ),
	'paragraphs inside a group'    => '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $p( 'Inside the group.' ) . $p( $body ) . '</div><!-- /wp:group -->',
	'paragraphs inside columns'    => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $p( 'Left column.' ) . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">' . $p( 'Right column.' ) . '</div><!-- /wp:column --></div><!-- /wp:columns -->',
	'a quote, then prose'          => '<!-- wp:quote --><blockquote class="wp-block-quote">' . $p( 'Quoted words.' ) . '<cite>Someone</cite></blockquote><!-- /wp:quote -->' . $p( $body ),
	'a list, then prose'           => '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One item</li><!-- /wp:list-item --></ul><!-- /wp:list -->' . $p( $body ),
	'a leading heading in a group' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">On Provenance</h1><!-- /wp:heading -->' . $p( $body ) . '</div><!-- /wp:group -->',
	'a raw > in attribute JSON'    => '<!-- wp:paragraph {"placeholder":"a > b"} --><p>Hand-edited markup.</p><!-- /wp:paragraph -->' . $p( $body ),
	'multibyte text'               => $p( 'Señal y ruido: dos artículos y una serie de notas 日本語.' ),
	'classic content, no blocks'   => "<p>Written in the classic editor.</p>\n<p>Second paragraph.</p>",
);
// The blocks the loaded core lets into an automatic excerpt, read from its own excerpt_allowed_blocks
// filter rather than assumed. An empty read (the filter never ran) leaves the list case at null, so
// it fails rather than guessing.
$core_allowed = array();
$read_allowed = static function ( $list ) use ( &$core_allowed ) { $core_allowed = $list; return $list; };
add_filter( 'excerpt_allowed_blocks', $read_allowed );
excerpt_remove_blocks( $p( 'x' ) );
remove_filter( 'excerpt_allowed_blocks', $read_allowed );
$list_dek = in_array( 'core/list', $core_allowed, true ) && in_array( 'core/list-item', $core_allowed, true ) ? 'One item Two papers and a running series of notes.' : 'Two papers and a running series of notes.';
$want_dek = array(
	'paragraphs only'               => 'First sentence with a link. Second sentence.',
	// The five below changed when the delimiter regex gave way to core's excerpt_remove_blocks(). The regex
	// kept the text of every block; core keeps the blocks its own automatic excerpts treat as prose.
	'a buttons block first'         => 'Two papers and a running series of notes.', // The regex answered 'Read the paper Two papers ...': a button label opened the dek.
	'an embed with a caption first' => 'Two papers and a running series of notes.', // The regex answered 'https://video.example/v/1 The talk, recorded Two papers ...': the embed's URL and caption.
	'an image with a caption first' => 'Two papers and a running series of notes.', // The regex answered 'The 1954 ledger Two papers ...': the image caption.
	'a details block first'         => 'Two papers and a running series of notes.', // The regex answered 'Method How the numbers were gathered. Two papers ...': core/details is not on core's list.
	'a plugin block first'          => 'Two papers and a running series of notes.', // The regex answered 'Updated in October. Two papers ...': no plugin block is on core's list.
	'a void dynamic block first'    => 'Two papers and a running series of notes.',
	'paragraphs inside a group'     => 'Inside the group. Two papers and a running series of notes.',
	'paragraphs inside columns'     => 'Left column. Right column.',
	'a quote, then prose'           => 'Quoted words. Someone Two papers and a running series of notes.',
	// Core 7.1 leaves core/list off its excerpt list and skips the list, in its own excerpts too; 7.2 adds
	// core/list and core/list-item, so the list opens the dek. The answer follows the loaded core's list.
	'a list, then prose'            => array() === $core_allowed ? null : $list_dek,
	'a leading heading in a group'  => 'On Provenance Two papers and a running series of notes.',
	'a raw > in attribute JSON'     => 'Hand-edited markup. Two papers and a running series of notes.',
	'multibyte text'                => 'Señal y ruido: dos artículos y una serie de notas 日本語.',
	'classic content, no blocks'    => 'Written in the classic editor. Second paragraph.',
);
ok( array_keys( $want_dek ) === array_keys( $dek_cases ), 'an answer is pinned for every dek case' );
foreach ( $dek_cases as $name => $content ) {
	$got = $sn_txt( sn_og_card_dek_source( (object) array( 'ID' => 40, 'post_excerpt' => '', 'post_content' => $content ) ) );
	ok( ( $want_dek[ $name ] ?? null ) === $got, "dek: $name" . ( ( $want_dek[ $name ] ?? null ) === $got ? '' : ' (got ' . var_export( $got, true ) . ')' ) );
}
ok( array() === ( $GLOBALS['__filters']['render_block'] ?? array() ), 'the spacing filter on render_block is gone once the dek is derived' );

// A render_block listener that expands a shortcode, as inc/reading-time.php does on the live site. The
// regex path stripped the shortcode and never rendered a block, so the dek never carried its output.
$expand = static fn( $html ) => str_replace( '[sn_reading_time]', '6 min read', $html );
add_filter( 'render_block', $expand );
$got = $sn_txt( sn_og_card_dek_source( (object) array( 'ID' => 41, 'post_excerpt' => '', 'post_content' => $p( '[sn_reading_time]' ) . $p( $body ) ) ) );
remove_filter( 'render_block', $expand );
ok( 'Two papers and a running series of notes.' === $got, 'a shortcode is stripped before blocks render, so a listener cannot expand it into the dek (got ' . var_export( $got, true ) . ')' );

echo "\nGroup: a page's card says what its description says (Unreleased)\n";
$GLOBALS['__seo'] = array( 1184 => 'Founder and music executive, 15+ years.' );
$page = (object) array( 'ID' => 1184, 'post_type' => 'page', 'post_excerpt' => 'Twenty-plus years of production.', 'post_content' => '' );
ok( 'Founder and music executive, 15+ years.' === sn_og_card_dek_source( $page ), 'a page uses its meta description over a stale excerpt' );
$note = (object) array( 'ID' => 1184, 'post_type' => 'post', 'post_excerpt' => 'The note dek.', 'post_content' => '' );
ok( 'The note dek.' === sn_og_card_dek_source( $note ), 'CONTROL: a note keeps its excerpt even when a description exists' );
$GLOBALS['__seo'] = array();
$page->post_excerpt = '';
$page->post_content = 'Body of the page.';
ok( 'Body of the page.' === sn_og_card_dek_source( $page ), 'a page with no description still falls back to its content' );
ok( '' === sn_og_card_footer( $page ) && '6 MIN READ' === sn_og_card_footer( $note ), 'a page card has no reading time; a note keeps it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
