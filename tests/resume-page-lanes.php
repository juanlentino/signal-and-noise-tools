<?php
/**
 * Guard: /resume's two lanes (inc/resume-page-lanes.php, inc/resume-page-lane-render.php),
 * its receipts (inc/resume-receipts.php) and the operations JSON Resume (owner brief 2026-11-01 #2).
 *
 * The single body is unchanged without operations settings and in Spanish;
 * with them the body holds both lanes, ?lane= hides one at render time and
 * changes no text; receipts are kept only when real and shown only on the
 * English page; the JSON Resume reads the same lane the page shows.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/resume-page-lanes.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0; $skip = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function add_action() {}
function add_filter() {}
if ( ! function_exists( 'wp_has_noncharacters' ) ) { function wp_has_noncharacters( string $text ): bool { return false; } } // core's utf8 helper the tag processor calls.
if ( ! function_exists( 'wp_kses_uri_attributes' ) ) { function wp_kses_uri_attributes() { return array( 'href', 'src', 'action' ); } }

$api = '';
foreach ( array( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ), dirname( __DIR__, 4 ) . '/wp-includes/html-api' ) as $dir ) {
	if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) { $api = $dir; break; }
}
if ( '' !== $api ) {
	if ( is_file( dirname( $api ) . '/class-wp-token-map.php' ) ) { require_once dirname( $api ) . '/class-wp-token-map.php'; }
	foreach ( array( 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-attribute-token.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $f ) {
		require_once "$api/$f";
	}
}

$root = dirname( __DIR__ );
require_once "$root/inc/resume-page.php";
require_once "$root/inc/resume-operations-setup.php";
require_once "$root/inc/resume-pdf/operations.php";
require_once "$root/inc/resume-sync-engine.php";
require_once "$root/inc/resume-page-lane-render.php";
require_once "$root/inc/resume-jsonresume.php";
require_once "$root/inc/provenance-core-normalize-parts.php";

$plain = sn_resume_doc_normalize( sn_resume_seed_doc() );
$raw   = sn_resume_doc_normalize( sn_resume_operations_setup( $plain )['doc'] );

echo "Group: one body or two\n";
// Held to main's own output (0ef1762f, 2026.11.6), not to this code: the
// English single body is main's byte for byte once the receipt marks are out;
// the Spanish one is main's outright.
$strip_marks = static fn( $s ) => (string) preg_replace( '# <a class="sn-resume-receipt"[^>]*>[a-z]+</a>#', '', $s );
ok( '8d5d27a43047a787e440be7508b18de386d32d71' === sha1( $strip_marks( sn_resume_page_body( $plain ) ) ), 'no operations settings: main\'s single body, plus only the receipt marks' );
sn_resume_lang( 'es' );
ok( '6d1d9c490ab9132d236b9d67d1d2d4aa9b21d869' === sha1( sn_resume_page_body( $raw ) ) && '6d1d9c490ab9132d236b9d67d1d2d4aa9b21d869' === sha1( sn_resume_page_body( $plain ) ), 'Spanish: main\'s single body exactly, operations settings or not' );
sn_resume_lang( 'en' );
$no_pdf = sn_resume_page_body( $raw ); // the operations PDF never generated.
$GLOBALS['__opt']['sn_resume_pdf_operations'] = array( 'url' => 'https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf', 'sha256' => str_repeat( 'a', 64 ) );
$body = sn_resume_page_body( $raw );
ok( 1 === substr_count( $body, '<h1' ) && 1 === substr_count( $body, 'class="wp-block-group sn-resume-switch"' ), 'two lanes: one page title, one switch' );
ok( 1 === substr_count( $body, 'id="resume-music"' ) && 1 === substr_count( $body, 'id="resume-operations"' ), 'two lanes: one group each, with its id' );
ok( false !== strpos( $body, '>Music</a>' ) && false !== strpos( $body, '>Operations &amp; AI Strategy</a>' ) && false !== strpos( $body, 'href="/resume/?lane=operations"' ) && false !== strpos( $body, 'href="/resume/"' ), 'the switch: two links, the music one without a query' );
ok( false === strpos( $body, 'hidden' ) && false === strpos( $body, 'aria-current' ), 'the stored body never depends on a request (no hidden, no aria-current)' );
ok( function_exists( 'snt_generated_page_guard' ) ? snt_generated_page_guard( 'resume', $body ) : ( false !== strpos( $body, 'sn-resume-hero-split' ) && 0 !== strpos( ltrim( $body ), '<!-- wp:html' ) ), 'the generated-page guard still accepts it' );
list( $head_html, $rest ) = explode( 'id="resume-music"', $body, 2 ) + array( '', '' );
list( $music_html, $ops_html ) = explode( 'id="resume-operations"', $rest, 2 ) + array( '', '' );
ok( false !== strpos( $ops_html, '>Founder and Operator</p>' ) && false !== strpos( $ops_html, "WHAT I&#039;VE BUILT" ) && false !== strpos( $ops_html, 'github.com/juanlentino</a>' ), 'the operations lane: its headline, What I\'ve Built, its extra link' );
ok( false !== strpos( $ops_html, 'href="https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf"' ) && false === strpos( $music_html, 'Operations.pdf' ), 'each lane its own Download: the operations PDF only in the operations lane' );
ok( false === strpos( $no_pdf, 'Operations.pdf' ) && false !== strpos( $no_pdf, 'id="resume-operations"' ), 'before the operations PDF exists, its lane has no Download (never a link to a 404)' );
ok( false !== strpos( $ops_html, '01 · Projects' ) && false !== strpos( $ops_html, '02 · Professional Experience' ) && false === strpos( $ops_html, 'PUBLICATIONS' ), 'the operations lane numbers what it shows, and leaves out what its sections leave out' );
$rev               = $raw;
$rev['experience'] = array_reverse( $rev['experience'] ); // Panacea first in the document; the operations order puts Independent Practice first.
$rev_body          = explode( 'id="resume-operations"', sn_resume_page_body( $rev ), 2 );
ok( strpos( $rev_body[0], 'PANACEA STUDIO' ) < strpos( $rev_body[0], 'INDEPENDENT PRACTICE' ) && strpos( $rev_body[1], 'INDEPENDENT PRACTICE' ) < strpos( $rev_body[1], 'PANACEA STUDIO' ), 'the music lane keeps the document\'s order; the operations lane its own' );
ok( false === strpos( $music_html, 'WHAT I&#039;VE BUILT' ) && false !== strpos( $music_html, '01 · Professional Experience' ) && false !== strpos( $music_html, 'PUBLICATIONS' ), 'the music lane is the music résumé, numbered as always' );

echo "\nGroup: the two-lane body is well-formed block markup\n";
// A stack parse of the delimiters: every opener closes in order, every
// attribute blob decodes, no stray comment (each breaks the editor).
preg_match_all( '/<!--\s*(\/?)wp:([a-z][a-z0-9-]*)(\s+(\{.*?\}))?\s*(\/?)-->|<!--(.*?)-->/s', $body, $tok, PREG_SET_ORDER );
$stack = array(); $order_ok = true; $json_ok = true; $stray = 0;
foreach ( $tok as $t ) {
	if ( '' === ( $t[2] ?? '' ) ) { $stray++; continue; }
	if ( '' !== ( $t[4] ?? '' ) && null === json_decode( $t[4], true ) ) { $json_ok = false; }
	if ( '/' === $t[5] ) { continue; } // self-closing.
	if ( '/' === $t[1] ) { $order_ok = $order_ok && array_pop( $stack ) === $t[2]; } else { $stack[] = $t[2]; }
}
ok( $order_ok && array() === $stack && 0 === $stray, 'every block opens and closes in order (' . count( $tok ) . ' delimiters), no stray comment' );
ok( $json_ok, 'every attribute blob decodes' );
$html = (string) preg_replace( '/<!--.*?-->/s', '', $body );
$tags_ok = true;
foreach ( array( 'div', 'nav', 'ul', 'li', 'p', 'h1', 'h2', 'h3', 'details', 'figure', 'a' ) as $tag ) {
	if ( preg_match_all( '/<' . $tag . '[\s>]/', $html ) !== substr_count( $html, '</' . $tag . '>' ) ) { $tags_ok = false; echo "    (imbalance: <$tag>)\n"; }
}
ok( $tags_ok, 'every HTML tag the bands use opens and closes the same number of times' );
ok( 1 === substr_count( $html, 'id="resume-music"' ) && 1 === substr_count( $html, 'id="resume-operations"' ), 'the two lanes carry their ids once each' );
ok( 0 === preg_match_all( '/\sid="(?!resume-(music|operations)")/', $html ), 'no other id in the body, so the two lanes never repeat one' );

echo "\nGroup: ?lane= at render time\n";
if ( '' === $api ) {
	++$skip;
	echo "SKIP: no WP_HTML_Tag_Processor on this machine (SNT_WP_HTML_API=<wp>/wp-includes/html-api)\n";
} else {
	$strip = static fn( $s ) => (string) preg_replace( '/<!--.*?-->\n?/s', '', $s );
	$group = static fn( $lane ) => $strip( sn_resume_lane_group( $lane, '<p>' . $lane . ' text</p>' ) );
	$nav   = $strip( substr( $body, (int) strpos( $body, '<nav' ), strpos( $body, '</nav>' ) + 6 - strpos( $body, '<nav' ) ) );
	$view  = static function ( $lane ) use ( $group, $nav ) {
		$_GET = null === $lane ? array() : array( 'lane' => $lane );
		$g    = static fn( $l ) => sn_resume_lane_render( $group( $l ), array( 'attrs' => array( 'className' => 'sn-resume-variant sn-resume-variant--' . $l ) ) );
		return array( 'music' => $g( 'music' ), 'operations' => $g( 'operations' ), 'nav' => sn_resume_lane_render( $nav, array( 'attrs' => array( 'className' => 'sn-resume-switch' ) ) ) );
	};
	$hidden = static fn( $html ) => 1 === preg_match( '/^<div[^>]*\shidden[\s>=]/', $html );
	$v      = $view( null );
	ok( ! $hidden( $v['music'] ) && $hidden( $v['operations'] ), 'no lane asked: music shows, operations is hidden' );
	ok( 1 === preg_match( '/data-lane="music"[^>]*aria-current="page"|aria-current="page"[^>]*data-lane="music"/', $v['nav'] ) && 1 === substr_count( $v['nav'], 'aria-current' ), 'and the switch marks Music as current, once' );
	ok( false !== strpos( $v['nav'], 'aria-label="Résumé version"' ), 'the switch is a labeled nav' );
	$o = $view( 'operations' );
	ok( $hidden( $o['music'] ) && ! $hidden( $o['operations'] ) && 1 === preg_match( '/data-lane="operations"[^>]*aria-current|aria-current="page"[^>]*data-lane="operations"/', $o['nav'] ), '?lane=operations: music hidden, operations current' );
	$x = $view( '"><script>' );
	ok( $x === $v && false === strpos( implode( '', $x ), 'script' ), 'an unknown lane is the music lane, and is never echoed' );
	$n = static fn( $set ) => sn_prov_normalize_v1( $set['nav'] . $set['music'] . $set['operations'] );
	ok( $n( $v ) === $n( $o ) && $n( $v ) === sn_prov_normalize_v1( $nav . $group( 'music' ) . $group( 'operations' ) ), 'the page text is the same whichever lane shows (the provenance normalizer reads no attributes)' );
	ok( $html = sn_resume_lane_render( '<div class="x">a</div>', array( 'attrs' => array( 'className' => 'x' ) ) ), 'any other group passes through' );
}

echo "\nGroup: receipts\n";
ok( array() === sn_resume_receipt_keep( array( 'receipt_url' => 'http://example.com/x' ) ) && array() === sn_resume_receipt_keep( array( 'receipt_url' => 'javascript:alert(1)' ) ), 'only an https receipt is kept' );
ok( array( 'receipt_url' => 'https://example.com/x', 'receipt_type' => 'verification' ) === sn_resume_receipt_keep( array( 'receipt_url' => 'https://example.com/x', 'receipt_type' => 'bogus' ) ), 'an unknown type is a verification' );
$b  = array( 'One bullet.', 'Two bullet.' );
$br = sn_resume_bullet_receipts( array( sn_resume_bullet_key( 'One bullet.' ) => array( 'receipt_url' => 'https://example.com/r', 'receipt_type' => 'repository' ), 'ffffffffffff' => array( 'receipt_url' => 'https://example.com/z' ) ), $b );
ok( array( sn_resume_bullet_key( 'One bullet.' ) ) === array_keys( $br ), 'a bullet receipt is kept only for a bullet the role still has' );
$doc = $plain;
$doc['experience'][0]['receipt_url']                   = 'https://example.com/practice';
$doc['experience'][0]['roles'][0]['bullet_receipts']    = array( sn_resume_bullet_key( $doc['experience'][0]['roles'][0]['bullets'][0] ) => array( 'receipt_url' => 'https://example.com/bullet', 'receipt_type' => 'publication' ) );
$doc  = sn_resume_doc_normalize( $doc );
$html = sn_resume_body_html( sn_resume_doc_view( $doc, 'music' ) );
ok( false !== strpos( $html, 'href="https://example.com/practice" rel="noopener" aria-label="Verify: ' ) && false !== strpos( $html, 'href="https://example.com/bullet" rel="noopener" aria-label="Paper: ' ), 'an entry and a bullet show their marks, named for what they back' );
ok( false !== strpos( $html, 'abstract=6402298" rel="noopener" aria-label="Paper: ' ) && false !== strpos( $html, 'parchment.com/lp/award/acd27b60-9389-4b6d-95e9-33c6755f53e5" rel="noopener" aria-label="Verify: MBA' ), 'the papers and the MBA show theirs from the links already stored' );
sn_resume_lang( 'es' );
ok( false === strpos( sn_resume_body_html( sn_resume_doc_view( $doc, 'music' ) ), 'sn-resume-receipt' ), 'the Spanish page shows no marks' );
sn_resume_lang( 'en' );
$changed = $doc;
$changed['experience'][0]['roles'][0]['bullets'][0] .= ' Edited.';
ok( empty( sn_resume_doc_normalize( $changed )['experience'][0]['roles'][0]['bullet_receipts'] ), 'editing a bullet\'s text drops its receipt' );

echo "\nGroup: the operations JSON Resume\n";
$phone                        = $raw;
$phone['pdf']['phone']        = '+1 555 123 4567';
$phone['pdf']['phone_public'] = true; // the music PDF's switch.
$pj = sn_jsonresume_build( sn_resume_lane_doc( $phone, 'operations' ), array( 'name' => 'x', 'lane' => 'operations' ) );
$pm = sn_jsonresume_build( sn_resume_lane_doc( $phone, 'music' ), array( 'name' => 'x', 'lane' => 'music' ) );
ok( '+1 555 123 4567' === ( $pm['basics']['phone'] ?? '' ) && ! isset( $pj['basics']['phone'] ) && false === strpos( (string) json_encode( $pj ), '555' ), 'the music PDF\'s phone switch never reaches the operations résumé: no phone in its JSON Resume' );
$jr = sn_jsonresume_build( sn_resume_lane_doc( $raw, 'operations' ), array( 'name' => 'Juan Lentino', 'projects' => sn_resume_lane_doc( $raw, 'operations' )['built']['items'], 'lane' => 'operations', 'profiles' => array( 'https://github.com/juanlentino' ) ) );
ok( 'Founder and Operator' === ( $jr['basics']['label'] ?? '' ) && 0 === strpos( (string) ( $jr['basics']['summary'] ?? '' ), 'Founder-operator with 15+ years' ), 'its basics are the operations headline and summary' );
ok( 3 === count( $jr['projects'] ?? array() ) && 'https://github.com/juanlentino/can-i-apply' === ( $jr['projects'][1]['url'] ?? '' ), 'What I\'ve Built is its projects' );
ok( 'operations' === ( $jr['meta']['x-lane'] ?? '' ) && empty( $jr['publications'] ), 'meta names the lane, and it leaves out what the operations résumé leaves out' );
$jm = sn_jsonresume_build( sn_resume_lane_doc( $raw, 'music' ), array( 'name' => 'Juan Lentino', 'lane' => 'music' ) );
ok( empty( $jm['projects'] ) && ! empty( $jm['publications'] ) && 'music' === ( $jm['meta']['x-lane'] ?? '' ), 'the music JSON Resume is the music résumé, no projects' );
ok( ! isset( sn_jsonresume_build( $plain, array( 'name' => 'x' ) )['meta']['x-lane'] ), 'no lanes, no x-lane' );

$ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $ci && '0' !== $ci && 'false' !== strtolower( $ci ) ) {
	echo "FAILED: $skip group(s) SKIPPED under CI because WordPress's html-api is missing; ?lane= is the check's core.\n";
	$fail += $skip;
}
echo "\nResult: $pass passed, $fail failed, $skip skipped.\n";
exit( $fail > 0 ? 1 : 0 );
