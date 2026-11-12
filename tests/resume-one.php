<?php
/**
 * Guard: the one résumé (owner, 2026-11-12; inc/resume-page-lanes.php,
 * inc/resume-page-bands.php, inc/resume-formats-*.php, inc/resume-jsonresume*.php)
 * and its receipts (inc/resume-receipts.php).
 *
 * The single music body is unchanged without operations settings and in
 * Spanish. With them /resume is one résumé: no switch, no hidden second lane,
 * no marks; its kicker and its verified-credits line (/music); every format
 * is the same résumé, and `?lane=` changes nothing anywhere.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/resume-one.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SNT_TEST_WITH_BLOCKS_PHP', true ); // The /resume body is serialized by core's get_comment_delimited_block_content().
require_once __DIR__ . '/lib/wp-core-blocks.php';

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
// The format routes (inc/resume-formats-route.php): a published page, its permalink, is_page().
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$GLOBALS['__page'] = null; $GLOBALS['__is_page'] = '';
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) {
	if ( 'music' === $p || 'musica' === $p ) { return empty( $GLOBALS['__music'] ) ? null : (object) array( 'ID' => 9, 'path' => $p ); } // /music and /musica, the verified credits.
	return $GLOBALS['__page'];
}
function get_permalink( $p = 0 ) { return 'https://juanlentino.com/' . ( is_object( $p ) && isset( $p->path ) ? $p->path : 'resume' ) . '/'; }
function get_post_status( $p = null ) { return 'publish'; }
function get_bloginfo( $k = '' ) { return 'Juan Lentino'; }
function get_post_modified_time( $f = 'U', $gmt = false, $p = null ) { return '2026-11-01T19:36:23-05:00'; }
function is_page( $s = '' ) { return $GLOBALS['__is_page'] === $s; }
function esc_url_raw( $s ) { return (string) $s; }

$api = '';
foreach ( array( rtrim( (string) getenv( 'SNT_WP_HTML_API' ), '/' ), dirname( __DIR__, 4 ) . '/wp-includes/html-api' ) as $dir ) {
	if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) { $api = $dir; break; }
}
$parser = '' !== $api && is_file( dirname( $api ) . '/class-wp-block-parser.php' ) && is_file( dirname( $api ) . '/class-wp-block-processor.php' );
if ( $parser ) {
	foreach ( array( 'class-wp-block-processor.php', 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php' ) as $f ) {
		require_once dirname( $api ) . "/$f"; // The parser needs the processor from WordPress 7.2.
	}
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
require_once "$root/inc/resume-jsonresume.php";
require_once "$root/inc/resume-markdown.php";
require_once "$root/inc/resume-formats-route.php";
require_once "$root/inc/resume-vcard.php";
require_once "$root/inc/resume-es.php";
require_once "$root/inc/provenance-core-normalize-parts.php";

$plain = sn_resume_doc_normalize( sn_resume_seed_doc() );
$set   = sn_resume_operations_setup( $plain )['doc'];
$set['variants']['operations']['kicker'] = 'Founder & Operator · Music, Rights & AI';
$raw   = sn_resume_doc_normalize( $set );

echo "Group: the music body stays where it is used\n";
ok( '488a844f9c316d00c9925e0f5a451c3fab0fe794' === sha1( sn_resume_page_body( $plain ) ), 'no operations settings: the single music body, byte for byte as before' );
sn_resume_lang( 'es' );
$es_file = sn_resume_es_merge( json_decode( (string) file_get_contents( "$root/inc/seed-content/resume-data-es.json" ), true ), $plain, '' );
ok( 'ab72ff0aee4e342f9e51e57b50822002ba6d49e1' === sha1( sn_resume_page_body( $plain ) ) && sn_resume_page_body( $es_file ) === sn_resume_body_html( $es_file ), 'Spanish with no operations settings (the bundled seed): the single body, byte for byte as before' );
$GLOBALS['__music'] = true;
$es_one = sn_resume_page_body( $raw, 'https://juanlentino.com/wp-content/uploads/resume/JuanLentino_CV.pdf?v=bbbbbbbb' );
ok( false !== strpos( $es_one, '>CURRICULUM VITAE</h1>' ) && false !== strpos( $es_one, "LO QUE CONSTRUÍ" ) && false !== strpos( $es_one, 'Créditos verificados: <a href="https://juanlentino.com/musica/">juanlentino.com/musica</a>' ), 'Spanish with operations settings (/cv since 2026-11-12): the one résumé, in Spanish, credits to /musica' );
ok( false !== strpos( $es_one, 'También en <a href="/cv.md">Markdown</a> · <a href="/cv/json-resume.json">JSON Resume</a>' ) && false !== strpos( $es_one, 'JuanLentino_CV.pdf?v=bbbbbbbb' ) && false === strpos( $es_one, 'Operations.pdf' ), '...its formats line and Download are /cv\'s own' );
$GLOBALS['__music'] = false;
sn_resume_lang( 'en' );

echo "\nGroup: one résumé\n";
$no_pdf = sn_resume_page_body( $raw ); // the PDF never generated, /music not published.
$GLOBALS['__opt']['sn_resume_pdf_operations']       = array( 'url' => 'https://juanlentino.com/resume/JuanLentino_Resume.pdf', 'sha256' => str_repeat( 'a', 64 ) );
$GLOBALS['__opt']['sn_resume_pdf_operations_bytes'] = base64_encode( '%PDF-1.7' ); // live means a PDF is stored too.
$GLOBALS['__music'] = true;
$body = sn_resume_page_body( $raw );
foreach ( array( 'sn-resume-switch', 'sn-resume-variant', 'data-lane', 'lane=', 'Two doors', 'sn-resume-only', 'sn-resume-hunk', 'sn-resume-legend', 'Only in this version', 'resume_lane_', 'resume_hunk_', 'hidden', 'aria-current' ) as $gone ) {
	ok( false === strpos( $body, $gone ), "no $gone" );
}
ok( 1 === substr_count( $body, '<h1' ) && 1 === substr_count( $body, '>RESUME</h1>' ), 'one page title' );
ok( false !== strpos( $body, '<p class="sn-catalog-eyebrow sn-resume-kicker">Founder &amp; Operator · Music, Rights &amp; AI</p>' . "\n<!-- /wp:paragraph -->\n\n" . '<!-- wp:heading {"className":"sn-resume-headline"} -->' ), 'the kicker, straight over the headline' );
ok( false !== strpos( $body, '>Founder and Operator</h2>' ) && false !== strpos( $body, "WHAT I&#039;VE BUILT" ) && false !== strpos( $body, 'github.com/juanlentino</a>' ), 'the operations view: its headline, What I\'ve Built, its extra link' );
ok( 1 === substr_count( $body, 'Verified credits: <a href="https://juanlentino.com/music/">juanlentino.com/music</a>' ), 'the verified-credits line, once, the address printed in full' );
ok( false === strpos( $no_pdf, 'Verified credits' ), 'no /music published: no credits line (never a link to a 404)' );
ok( false !== strpos( $body, 'href="https://juanlentino.com/resume/JuanLentino_Resume.pdf?v=aaaaaaaa"' ) && false === strpos( $body, 'uploads/resume' ) && false === strpos( $body, '_Operations.pdf' ) && false === strpos( $no_pdf, '.pdf' ), 'one Download: the PDF with its ?v=; before it exists, none' );
ok( 1 === substr_count( $body, '<p class="sn-resume-formats">Also as <a href="/resume.md">Markdown</a> · <a href="/resume/json-resume.json">JSON Resume</a></p>' ), 'the formats line, once, no lane' );
ok( false !== strpos( $body, '01 · Projects' ) && false !== strpos( $body, '02 · Professional Experience' ) && false === strpos( $body, '>PUBLICATIONS</h2>' ), 'numbered as it falls; the sections the settings leave out stay out' );
$with_pubs = $set;
$with_pubs['variants']['operations']['sections'][] = 'publications';
ok( false !== strpos( sn_resume_page_body( sn_resume_doc_normalize( $with_pubs ) ), '>PUBLICATIONS</h2>' ), 'add "publications" to Sections and the band is back' );
$rev               = $raw;
$rev['experience'] = array_reverse( $rev['experience'] );
$rb                = sn_resume_page_body( $rev );
ok( strpos( $rb, 'INDEPENDENT PRACTICE' ) < strpos( $rb, 'PANACEA STUDIO' ), 'the operations experience order wins over the document\'s' );
ok( function_exists( 'snt_generated_page_guard' ) ? snt_generated_page_guard( 'resume', $body ) : ( false !== strpos( $body, 'sn-resume-hero-split' ) && 0 !== strpos( ltrim( $body ), '<!-- wp:html' ) ), 'the generated-page guard accepts it' );
$phone = array_replace_recursive( $raw, array( 'pdf' => array( 'phone' => '+1 555 123 4567', 'phone_public' => true ) ) );
ok( $body === sn_resume_page_body( $phone ) && false === strpos( $body, '555' ), 'the same body with a phone stored and public: no phone' );
ok( 'c879443a2364dde6ae441f6fdc4755abf2e27cf5' === sha1( str_replace( '/resume/JuanLentino_Resume.pdf?', '/resume/JuanLentino_Resume_Operations.pdf?', $body ) ), 'the body is pinned (engine rev 7): a change to it is a deliberate one, with a rev bump; only the PDF\'s default name changed (2026-11-12), and the live body follows the stored name on Generate' );

echo "\nGroup: well-formed\n";
preg_match_all( '/<!--\s*(\/?)wp:([a-z][a-z0-9-]*)(\s+(\{.*?\}))?\s*(\/?)-->|<!--(.*?)-->/s', $body, $tok, PREG_SET_ORDER );
$stack = array(); $order_ok = true; $json_ok = true; $stray = 0;
foreach ( $tok as $t ) {
	if ( '' === ( $t[2] ?? '' ) ) { $stray++; continue; }
	if ( '' !== ( $t[4] ?? '' ) && null === json_decode( $t[4], true ) ) { $json_ok = false; }
	if ( '/' === $t[5] ) { continue; }
	if ( '/' === $t[1] ) { $order_ok = $order_ok && array_pop( $stack ) === $t[2]; } else { $stack[] = $t[2]; }
}
ok( $order_ok && array() === $stack && 0 === $stray && $json_ok, 'every block opens and closes in order (' . count( $tok ) . ' delimiters), every attribute blob decodes' );
$html    = (string) preg_replace( '/<!--.*?-->/s', '', $body );
$tags_ok = true;
foreach ( array( 'div', 'ul', 'li', 'p', 'h1', 'h2', 'h3', 'details', 'figure', 'a', 'span' ) as $tag ) {
	if ( preg_match_all( '/<' . $tag . '[\s>]/', $html ) !== substr_count( $html, '</' . $tag . '>' ) ) { $tags_ok = false; echo "    (imbalance: <$tag>)\n"; }
}
ok( $tags_ok, 'every HTML tag opens and closes the same number of times' );
preg_match_all( '/\sid="([^"]*)"/', $html, $ids );
ok( array( 'resume-built', 'resume-experience', 'resume-credentials', 'resume-skills' ) === $ids[1], 'the only ids are each band\'s anchor, once: ' . implode( ', ', $ids[1] ) );
preg_match_all( '#<h([1-6])[\s>]#', $body, $lv );
$levels = array_map( 'intval', $lv[1] );
$skips  = 0;
foreach ( $levels as $i => $l ) { $skips += ( $i > 0 && $l > $levels[ $i - 1 ] + 1 ) ? 1 : 0; }
ok( 1 === ( $levels[0] ?? 0 ) && 1 === count( array_keys( $levels, 1, true ) ) && 0 === $skips, 'headings run h1, then h2s, then h3s, never skipping a level' );
if ( ! $parser ) {
	++$skip;
	echo "SKIP: no WP_Block_Parser beside the html-api (bash tools/fetch-wp-test-core.sh fetches it)\n";
} else {
	// Core's serialize_block_attributes(), serialize_block() and get_comment_delimited_block_content() (wp-includes/blocks.php, 7.1).
	$attrs = static fn( $a ) => strtr( wp_json_encode( $a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array( '\\\\' => '\\u005c', '--' => '\\u002d\\u002d', '<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022' ) );
	$ser   = static function ( $b ) use ( &$ser, $attrs ) {
		$c = '';
		$i = 0;
		foreach ( $b['innerContent'] as $chunk ) { $c .= is_string( $chunk ) ? $chunk : $ser( $b['innerBlocks'][ $i++ ] ); }
		if ( null === $b['blockName'] ) { return $c; }
		$name = 0 === strpos( $b['blockName'], 'core/' ) ? substr( $b['blockName'], 5 ) : $b['blockName'];
		$a    = empty( $b['attrs'] ) ? '' : $attrs( $b['attrs'] ) . ' ';
		return '' === $c ? sprintf( '<!-- wp:%s %s/-->', $name, $a ) : sprintf( '<!-- wp:%s %s-->%s<!-- /wp:%s -->', $name, $a, $c, $name );
	};
	$rt = static fn( $s ) => implode( '', array_map( $ser, ( new WP_Block_Parser() )->parse( $s ) ) );
	ok( $rt( $body ) === $body, 'serialize_blocks( parse_blocks( $body ) ) gives the body back byte for byte (an editor save changes nothing)' );
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
$no_h3_receipt = static fn( $h ) => 0 === preg_match( '#<h3[^>]*>(?:(?!</h3>).)*sn-resume-receipt#s', $h );
ok( $no_h3_receipt( $html ) && 1 === preg_match( '#<p class="sn-resume-rail">Sep 2022 - Present<br>Remote <a class="sn-resume-receipt" href="https://example.com/practice"[^>]*>verify</a></p>#', $html ) && false !== strpos( $html, '<h3 class="wp-block-heading sn-resume-role">INDEPENDENT PRACTICE</h3>' ), 'an employer\'s receipt rides its date and place line, out of the heading (the heading\'s name is the employer alone)' );
$with_org_receipt = $raw;
$with_org_receipt['experience'][0]['receipt_url'] = 'https://example.com/practice';
$org_body = sn_resume_page_body( sn_resume_doc_normalize( $with_org_receipt ) );
ok( 1 === substr_count( $org_body, 'href="https://example.com/practice"' ) && $no_h3_receipt( $org_body ), 'and on the one résumé, once, never inside an h3' );
ok( false === strpos( $html, 'aria-label="Paper: Provenance' ) && false !== strpos( $html, '<a href="https://ssrn.com/abstract=6402298" rel="noopener">Provenance Over Detection' ) && false !== strpos( $html, 'parchment.com/lp/award/acd27b60-9389-4b6d-95e9-33c6755f53e5" rel="noopener" aria-label="Verify: MBA' ), 'the papers carry no mark of their own (each title links to the paper), and the MBA shows its verify mark from the link already stored' );
sn_resume_lang( 'es' );
ok( false === strpos( sn_resume_body_html( sn_resume_doc_view( $doc, 'music' ) ), 'sn-resume-receipt' ), 'the Spanish page shows no marks' );
sn_resume_lang( 'en' );
$changed = $doc;
$changed['experience'][0]['roles'][0]['bullets'][0] .= ' Edited.';
ok( empty( sn_resume_doc_normalize( $changed )['experience'][0]['roles'][0]['bullet_receipts'] ), 'editing a bullet\'s text drops its receipt' );

echo "\nGroup: every format is the same résumé, and ?lane= changes nothing\n";
$GLOBALS['__page']                 = (object) array( 'ID' => 7, 'post_status' => 'publish', 'post_password' => '' );
$GLOBALS['__opt']['sn_resume_doc'] = $raw;
$asked = static function ( $lane, $fn ) {
	$_GET = null === $lane ? array() : array( 'lane' => $lane );
	$out  = $fn();
	$_GET = array();
	return $out;
};
$jr = sn_jsonresume_for( 'resume' );
ok( 'Founder and Operator' === ( $jr['basics']['label'] ?? '' ) && 3 === count( $jr['projects'] ?? array() ) && empty( $jr['publications'] ), 'the JSON Resume is the one résumé: its headline, What I\'ve Built as projects' );
ok( ! isset( $jr['meta']['x-lane'] ) && ! isset( $jr['meta']['x-lanes'] ) && 'https://juanlentino.com/resume/' === ( $jr['meta']['canonical'] ?? '' ), 'no x-lane, no x-lanes; canonical /resume/' );
ok( in_array( array( 'network' => 'Verified credits', 'username' => 'music', 'url' => 'https://juanlentino.com/music/' ), $jr['basics']['profiles'] ?? array(), true ), 'basics.profiles names /music as "Verified credits"' );
foreach ( array( 'music', 'operations', 'bogus' ) as $l ) {
	ok( $jr === $asked( $l, static fn() => sn_jsonresume_for( 'resume' ) ), "?lane=$l: the same JSON Resume" );
}
$md = sn_resume_format_body( 'md', 'resume' );
ok( 'text/markdown; charset=utf-8' === ( $md[0] ?? '' ) && false !== strpos( $md[1], "\nFounder and Operator\n" ) && false !== strpos( $md[1], "## What I've Built" ), '/resume.md is the one résumé' );
ok( false === strpos( $md[1], "\nlane:" ) && false === strpos( $md[1], "\nlanes:" ) && false === strpos( $md[1], '_operations' ) && false === strpos( $md[1], 'lane=' ), 'no lane, no lanes map, no operations formats' );
ok( false !== strpos( $md[1], "\n  - \"https://juanlentino.com/music/\"\n" ) && false === strpos( $md[1], 'contact.vcf' ), 'its profiles name /music; it links no vCard (owner, 2026-11-02)' );
ok( $md === $asked( 'music', static fn() => sn_resume_format_body( 'md', 'resume' ) ) && $md === $asked( 'operations', static fn() => sn_resume_format_body( 'md', 'resume' ) ), '?lane= any value: the same Markdown' );
$vc = sn_resume_format_body( 'vcf', 'resume' )[1] ?? '';
ok( false !== strpos( $vc, "TITLE:Founder and Operator\r\n" ) && false !== strpos( $vc, 'X-SOCIALPROFILE;TYPE=verifiedcredits:https://juanlentino.com/music/' ), 'the vCard route, still served, is the one résumé too' );
$f = sn_resume_formats( 'resume' );
ok( array( 'html', 'markdown', 'pdf', 'json_resume' ) === array_keys( $f ) && 'https://juanlentino.com/resume/JuanLentino_Resume.pdf?v=aaaaaaaa' === $f['pdf'], 'the format list: one PDF (the page\'s), no operations entries, no vCard' );
$head = static function ( $fn, $slug, $lane ) {
	$GLOBALS['__is_page'] = $slug;
	$_GET                 = null === $lane ? array() : array( 'lane' => $lane );
	ob_start();
	$fn();
	$out = (string) ob_get_clean();
	$GLOBALS['__is_page'] = '';
	$_GET                 = array();
	return $out;
};
foreach ( array( null, 'operations', 'music' ) as $l ) {
	$h = $head( 'sn_jsonresume_head_link', 'resume', $l ) . $head( 'sn_resume_formats_head_links', 'resume', $l );
	ok( false !== strpos( $h, 'href="https://juanlentino.com/resume/json-resume.json"' ) && false !== strpos( $h, 'href="https://juanlentino.com/resume.md"' ) && false === strpos( $h, 'lane' ) && false === strpos( $h, 'text/vcard' ), '/resume' . ( null === $l ? '' : "?lane=$l" ) . ': the plain alternates, no vCard' );
}
ok( 'https://juanlentino.com/resume/' === sn_resume_format_canonical( 'resume' ) && 'https://juanlentino.com/resume.md' === sn_resume_markdown_url( 'resume' ), 'the formats\' canonical is /resume/' );
$GLOBALS['__opt']['sn_resume_doc'] = array_replace_recursive( $raw, array( 'pdf' => array( 'phone' => '+1 555 867 5309', 'phone_public' => true ) ) );
foreach ( array( 'Markdown' => sn_resume_format_body( 'md', 'resume' )[1] ?? '', 'JSON Resume' => (string) json_encode( sn_jsonresume_for( 'resume' ) ), 'vCard' => sn_resume_format_body( 'vcf', 'resume' )[1] ?? '' ) as $name => $copy ) {
	ok( '' !== $copy && ! preg_match( '~555|867|5309|tel:|\r\nTEL|"phone"|\bphone:~i', $copy ), "the $name carries no phone, with one stored and public" );
}
$GLOBALS['__opt']['sn_resume_doc'] = $plain;
$fp = sn_resume_formats( 'resume' );
ok( isset( $fp['vcard'] ) && 'music' !== ( sn_jsonresume_for( 'resume' )['basics']['profiles'][0]['username'] ?? '' ) && false === strpos( (string) json_encode( sn_jsonresume_for( 'resume' ) ), 'Verified credits' ), 'no operations settings: the plain music formats, as before (vCard listed, no credits profile)' );
$GLOBALS['__opt']['sn_resume_doc'] = $raw;
$hdr = sn_resume_format_headers( 'application/json; charset=utf-8', sn_resume_format_canonical( 'resume' ) );
ok( in_array( 'X-Robots-Tag: noindex', $hdr, true ) && in_array( 'Link: <https://juanlentino.com/resume/>; rel="canonical"', $hdr, true ), 'a format answers noindex, with /resume/ as canonical' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/resume-jsonresume-route.php" ), "sn_resume_format_send( 'application/json; charset=utf-8', \$slug );" ) && false !== strpos( (string) file_get_contents( "$root/inc/resume-formats-route.php" ), 'sn_resume_format_send( $body[0], $route[1] );' ), 'both routes send through the shared headers, with no lane' );

$ci = (string) getenv( 'CI' );
if ( $skip > 0 && '' !== $ci && '0' !== $ci && 'false' !== strtolower( $ci ) ) {
	echo "FAILED: $skip group(s) SKIPPED under CI because WordPress's block parser is missing.\n";
	exit( 1 );
}
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
