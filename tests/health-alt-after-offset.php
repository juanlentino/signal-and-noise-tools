<?php
/**
 * The caption and heading an inline <img> is judged against.
 *
 * sn_health_caption_after_offset() and sn_health_heading_after_offset() read
 * the markup after an image: its own <figcaption>, and the heading a screen
 * reader announces next. Each case below gives the image tag the helpers start
 * after, and pins what both return.
 *
 * The pins were taken from the byte-regex helpers first, then the helpers moved
 * onto core's Tag Processor. Every expectation that changed says, in its
 * comment, what the regex answered and why that was wrong; the rest held.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );

require_once __DIR__ . '/lib/wp-html-api.php';
snt_test_load_html_processor();
require_once __DIR__ . '/../inc/health-alt-quality.php';

$pass = 0;
$fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

/** Byte offset just past $img in $html, the way the with-alt reader passes it. */
function after_img( $html, $img ) {
	$at = strpos( $html, $img );
	if ( false === $at ) {
		throw new LogicException( "needle not in case: $img" );
	}
	return $at + strlen( $img );
}

// Exact byte arithmetic for the distance bound: after </figure>, a <div> of
// filler, then a heading, ending at byte 2000 of non-comment markup (in), or
// at 2001 (out). The filler is two-byte "ñ" so the bound is bytes, not characters.
$edge_in  = '<div>' . str_repeat( 'ñ', 987 ) . '</div><h2>Señal</h2>';
$edge_out = '<div>' . str_repeat( 'ñ', 987 ) . 'x</div><h2>Señal</h2>';
if ( 2000 !== strlen( $edge_in ) || 2001 !== strlen( $edge_out ) ) {
	throw new LogicException( 'edge fixtures drifted' );
}
// A heading that OPENS inside the window and closes past it, on multibyte text.
$edge_straddle = '<div>' . str_repeat( 'ñ', 985 ) . '</div><h2>日本語の見出し</h2>';
// Forty block delimiters: 2,440 bytes of comments before the heading.
$many_delimiters = str_repeat( '<!-- wp:group {"layout":{"type":"constrained"}} -->', 40 ) . '<h3>Past the delimiters</h3>';
// A heading ending at byte 2000 of non-comment markup, with a 208-byte comment inside it.
$edge_comment = '<div>' . str_repeat( 'x', 1978 ) . '</div><h2>A<!-- ' . str_repeat( 'c', 200 ) . ' -->B</h2>';

$cases = array(
	// name => array( html, img tag the helpers start after, caption, heading ).
	'figure with a figcaption' => array(
		'<figure><img src="a.png" alt="A"><figcaption>A caption</figcaption></figure><p>after</p>',
		'<img src="a.png" alt="A">', 'A caption', '',
	),
	'wp-block-image with a caption, then its heading' => array(
		"<!-- wp:image {\"id\":5,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.test/l.png\" alt=\"Ledger\" class=\"wp-image-5\"/><figcaption class=\"wp-element-caption\">The 1954 <em>ledger</em>, reopened</figcaption></figure>\n<!-- /wp:image -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">The ledger</h2>\n<!-- /wp:heading -->",
		'<img src="https://example.test/l.png" alt="Ledger" class="wp-image-5"/>', 'The 1954 ledger, reopened', 'The ledger',
	),
	'a heading right after a bare image' => array(
		'<img src="h.png" alt="H"><h2>Right after</h2>',
		'<img src="h.png" alt="H">', '', 'Right after',
	),
	'a heading inside the skip budget' => array(
		'<img src="n.png" alt="N"><p>one</p><p>two</p><h3>Third</h3>',
		'<img src="n.png" alt="N">', '', 'Third',
	),
	'a heading past the skip budget' => array(
		'<img src="n2.png" alt="N2"><p>one</p><p>two</p><p>three</p><h3>Fourth</h3>',
		'<img src="n2.png" alt="N2">', '', '',
	),
	'a heading beyond 2000 bytes' => array(
		'<figure><img src="d.png" alt="D"></figure><p>' . str_repeat( 'x', 2100 ) . '</p><h2>Too far</h2>',
		'<img src="d.png" alt="D">', '', '',
	),
	'a heading ending at byte 2000, multibyte filler' => array(
		'<figure><img src="e.png" alt="E"></figure>' . $edge_in,
		'<img src="e.png" alt="E">', '', 'Señal',
	),
	'a heading ending at byte 2001, multibyte filler' => array(
		'<figure><img src="e2.png" alt="E2"></figure>' . $edge_out,
		'<img src="e2.png" alt="E2">', '', '',
	),
	'a multibyte heading straddling the window edge' => array(
		'<figure><img src="e3.png" alt="E3"></figure>' . $edge_straddle,
		'<img src="e3.png" alt="E3">', '', '',
	),
	'block delimiters do not spend the distance bound' => array(
		'<figure><img src="f.png" alt="F"></figure>' . $many_delimiters,
		'<img src="f.png" alt="F">', '', 'Past the delimiters',
	),
	'nested markup and entities inside a caption' => array(
		'<figure><img src="g.png" alt="G"><figcaption>Tom &amp; <a href="/j"><strong>Jerry</strong></a>&nbsp;at the <br>desk<!-- note --></figcaption></figure>',
		'<img src="g.png" alt="G">', 'Tom &amp; Jerry&nbsp;at the desk', '',
	),
	'a heading with inline markup, inside a wrapper' => array(
		'<figure><img src="m.png" alt="M"></figure><div class="x"><h2 class="t"><a href="/x">Signal <em>&amp;</em> Noise</a></h2></div>',
		'<img src="m.png" alt="M">', '', 'Signal &amp; Noise',
	),
	'an svg title inside a heading' => array(
		'<figure><img src="w.png" alt="W"></figure><h3><svg role="img"><title>Icon</title><path d="M0"/></svg> Services</h3>',
		'<img src="w.png" alt="W">', '', 'Icon Services',
	),
	'an svg title inside a figcaption' => array(
		'<figure><img src="w2.png" alt="W2"><figcaption><svg role="img"><title>Icon</title></svg> Services</figcaption></figure>',
		'<img src="w2.png" alt="W2">', 'Icon Services', '',
	),
	'a script after an inline svg' => array(
		'<figure><img src="w3.png" alt="W3"></figure><svg aria-hidden="true"></svg><script>var s = \'<h2>Scripted</h2>\';</script>',
		'<img src="w3.png" alt="W3">', '', '', // The regex answered 'Scripted': it read a script's string as markup.
	),
	'uppercase tags' => array(
		'<FIGURE><IMG SRC="u.png" ALT="U"><FIGCAPTION>Upper</FIGCAPTION></FIGURE><H2>Loud</h2>',
		'<IMG SRC="u.png" ALT="U">', 'Upper', 'Loud',
	),
	'nested figures: a gallery' => array(
		'<figure class="wp-block-gallery"><figure class="wp-block-image"><img src="q.png" alt="Q"/><figcaption>One</figcaption></figure><figure class="wp-block-image"><img src="q2.png" alt="Q2"/></figure><figcaption>Whole gallery</figcaption></figure><h2>Gallery</h2>',
		'<img src="q.png" alt="Q"/>', 'One', '',
	),
	'nested figures: the last image in a gallery' => array(
		'<figure class="wp-block-gallery"><figure class="wp-block-image"><img src="q.png" alt="Q"/><figcaption>One</figcaption></figure><figure class="wp-block-image"><img src="q2.png" alt="Q2"/></figure><figcaption>Whole gallery</figcaption></figure><h2>Gallery</h2>',
		'<img src="q2.png" alt="Q2"/>', '', 'Gallery',
	),
	'a figure opening after the caption, before the close' => array(
		'<figure><img src="v.png" alt="V"><figcaption>Whose?</figcaption><figure><img src="v2.png" alt="V2"></figure></figure><h2>After</h2>',
		'<img src="v.png" alt="V">', '', '',
	),
	'a bare image before an unrelated card' => array(
		'<p><img src="p.png" alt="P"></p><figure><img src="p2.png" alt="P2"></figure><h3>Card</h3>',
		'<img src="p.png" alt="P">', '', '',
	),
	'a heading that never closes' => array(
		'<img src="o.png" alt="O"><h2>Open',
		'<img src="o.png" alt="O">', '', '',
	),
	'a heading inside an HTML comment' => array(
		'<figure><img src="c.png" alt="C"></figure><!-- <h2>Commented</h2> --><p>x</p>',
		'<img src="c.png" alt="C">', '', '',
	),
	'a figcaption inside an HTML comment' => array(
		'<figure><img src="c2.png" alt="C2"><!-- <figcaption>Old caption</figcaption> --></figure>',
		'<img src="c2.png" alt="C2">', '', '', // The regex answered caption 'Old caption': it read markup inside a comment.
	),
	'a </figure> inside an HTML comment' => array(
		'<figure><img src="c3.png" alt="C3"><!-- </figure> --><figcaption>Real caption</figcaption></figure>',
		'<img src="c3.png" alt="C3">', 'Real caption', '', // The regex answered caption '': it ended the figure at the </figure> inside the comment.
	),
	'a heading inside a comment that never closes' => array(
		'<figure><img src="c4.png" alt="C4"></figure><p>a</p><!-- <h2>Never closed</h2>',
		'<img src="c4.png" alt="C4">', '', '', // The regex answered 'Never closed': it stripped only comments that close, but an unclosed one runs to the end of the document.
	),
	'a heading inside a script' => array(
		'<figure><img src="s.png" alt="S"></figure><script>document.write(\'<h2>Scripted</h2>\');</script>',
		'<img src="s.png" alt="S">', '', '', // The regex answered 'Scripted': it read a script's string as markup.
	),
	'a figcaption inside a script' => array(
		'<figure><img src="s2.png" alt="S2"><script>var c = \'<figcaption>Fake</figcaption>\';</script></figure>',
		'<img src="s2.png" alt="S2">', '', '', // The regex answered caption 'Fake': it read a script's string as markup.
	),
	'a script inside a figcaption' => array(
		'<figure><img src="s3.png" alt="S3"><figcaption>Caption<script>track("x")</script></figcaption></figure>',
		'<img src="s3.png" alt="S3">', 'Caption', '', // The regex answered 'Captiontrack("x")': strip_tags() keeps a script's source as text.
	),
	'a heading inside a textarea' => array(
		'<figure><img src="t.png" alt="T"></figure><textarea><h2>Typed</h2></textarea>',
		'<img src="t.png" alt="T">', '', '', // The regex answered 'Typed': it read a textarea's text as markup.
	),
	'a > inside the figcaption\'s attribute' => array(
		'<figure><img src="k.png" alt="K"><figcaption title="a > b">Arrow</figcaption></figure>',
		'<img src="k.png" alt="K">', 'Arrow', '', // The regex answered 'b">Arrow': it ended the tag at the '>' inside the title.
	),
	'a > inside the heading\'s attribute' => array(
		'<figure><img src="k2.png" alt="K2"></figure><p title="1 > 0">a</p><h2 title="x > y">Arrow head</h2>',
		'<img src="k2.png" alt="K2">', '', 'Arrow head', // The regex answered 'y">Arrow head': it ended the tag at the '>' inside the title.
	),
	'a heading spelled inside an attribute' => array(
		'<figure><img src="k3.png" alt="K3"></figure><p title="a><h2>Fake</h2>">x</p>',
		'<img src="k3.png" alt="K3">', '', '', // The regex answered 'Fake': it ended the <p> at the '>' inside the title and read the rest of the value as markup.
	),
	'a bare image, then a figure that is not its own' => array(
		'<p><img src="x1.png" alt="X1"></p><figure><blockquote>q</blockquote></figure><h2>Pull</h2>',
		'<img src="x1.png" alt="X1">', '', '',
	),
	'text after the figcaption, before the close' => array(
		'<figure><img src="x2.png" alt="X2"><figcaption>Cap</figcaption>Credit: AP</figure>',
		'<img src="x2.png" alt="X2">', 'Cap', '',
	),
	'a heading four wrappers deep' => array(
		'<figure><img src="x3.png" alt="X3"></figure><section><div><article><main><h2>Deep</h2></main></article></div></section>',
		'<img src="x3.png" alt="X3">', '', 'Deep',
	),
	'a comment inside a heading at the window edge' => array(
		'<figure><img src="x4.png" alt="X4"></figure>' . $edge_comment,
		'<img src="x4.png" alt="X4">', '', 'AB',
	),
);

foreach ( $cases as $name => $case ) {
	list( $html, $img, $want_caption, $want_heading ) = $case;
	$at      = after_img( $html, $img );
	$caption = sn_health_caption_after_offset( $html, $at );
	$heading = sn_health_heading_after_offset( $html, $at );
	ok( $want_caption === $caption, "caption: $name" . ( $want_caption === $caption ? '' : ' (got ' . var_export( $caption, true ) . ')' ) );
	ok( $want_heading === $heading, "heading: $name" . ( $want_heading === $heading ? '' : ' (got ' . var_export( $heading, true ) . ')' ) );

	// The with-alt reader answers the same for this image: it finds each image's
	// figure in one pass and hands the helpers' walks only what they need.
	preg_match( '/src="([^"]*)"/i', $img, $m );
	$row = array();
	foreach ( sn_health_extract_inline_imgs_with_alt( $html ) as $r ) {
		if ( $m[1] === $r['src'] ) {
			$row = $r;
		}
	}
	$got = array( $row['caption'] ?? null, $row['heading'] ?? null );
	ok( array( $want_caption, $want_heading ) === $got, "reader: $name" . ( array( $want_caption, $want_heading ) === $got ? '' : ' (got ' . var_export( $got, true ) . ')' ) );
}

echo "\nGroup: the cost stays linear in the post\n";
// 200 bare images in tag-dense paragraphs, with one captioned figure halfway.
// A per-image search for the image's own figure walks to that figure from every
// bare image before it: a hundred walks across half the post. Counted in
// tokens, not timed.
$long = '';
for ( $i = 0; $i < 200; $i++ ) {
	if ( 100 === $i ) {
		$long .= '<figure><img src="mid.png" alt="Mid"><figcaption>Halfway</figcaption></figure><h2>Half</h2>';
	}
	$long .= '<p><img src="b' . $i . '.png" alt="Bare ' . $i . '">' . str_repeat( '<span>x</span>', 40 ) . '</p>';
}
$counter = get_class( snt_html_span_processor( '' ) );

$counter::$tokens_visited = 0;
$walk                     = snt_html_span_processor( $long );
while ( $walk->next_token() ) {
	continue;
}
$tokens = $counter::$tokens_visited;

$counter::$tokens_visited = 0;
$rows                     = sn_health_extract_inline_imgs_with_alt( $long );
$visited                  = $counter::$tokens_visited;
ok( 201 === count( $rows ) && 'Halfway' === $rows[100]['caption'] && 'Half' === $rows[100]['heading'] && '' === $rows[0]['caption'] . $rows[0]['heading'] . $rows[200]['caption'], 'the long post is read: 201 images, the figure captioned and headed, the bare ones not' );
ok( $visited <= 3 * $tokens, "reading it visits at most three times the post's $tokens tokens (visited $visited)" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
