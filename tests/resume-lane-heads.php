<?php
/**
 * Each /resume lane address is a complete document head (2026-11-02): on
 * /resume/?lane=operations the <title>, og and twitter title and description,
 * og:url and the ProfilePage name that lane; its twins (Markdown, JSON Resume)
 * name their own lane address and both lanes. The music lane is unchanged, and
 * its twins change only by the lanes map.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/resume-lane-heads.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'OBJECT', 'OBJECT' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__f'] = array();
function add_filter( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__f'][ $tag ][ $p ][] = array( $cb, $a ); return true; }
function add_action( $tag, $cb, $p = 10, $a = 1 ) { return add_filter( $tag, $cb, $p, $a ); }
function remove_action() {}
function apply_filters( $tag, $value, ...$args ) {
	$hooks = $GLOBALS['__f'][ $tag ] ?? array();
	ksort( $hooks );
	foreach ( $hooks as $cbs ) {
		foreach ( $cbs as list( $cb, $n ) ) {
			$value = $cb( ...array_slice( array_merge( array( $value ), $args ), 0, max( 1, $n ) ) );
		}
	}
	return $value;
}
function apply_filters_deprecated( $tag, $args ) { return apply_filters( $tag, ...$args ); }
function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_tag() { return false; }
function is_404() { return false; }
function is_singular( $t = '' ) { return '' === $t || 'page' === $t; }
function is_page( $s = '' ) { return '' === $s || $GLOBALS['__slug'] === $s; }
function sn_setting( $p, $d = null ) { return $d; }
function get_queried_object() { return $GLOBALS['__post']; }
function get_queried_object_id() { return $GLOBALS['__post']->ID; }
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return 'resume' === $p ? $GLOBALS['__post'] : null; }
function get_permalink( $p = 0 ) { return 'https://juanlentino.com/' . $GLOBALS['__slug'] . '/'; }
function get_post_meta( $id, $k = '', $single = false ) { return ''; }
function get_post_modified_time( $f = 'U', $gmt = false, $p = null ) { return '2026-11-01T19:36:23-05:00'; }
function get_bloginfo( $k = '' ) { return 'Juan Lentino'; }
function get_the_title( $p = 0 ) { return 'Resume'; }
function sn_post_settings_get_description( $id ) { return 'Founder and music executive, 15+ years across the U.S. and Latin America.'; } // the page's written description, as live.
function wp_has_noncharacters( string $text ): bool { return false; }
function wp_kses_uri_attributes() { return array( 'href', 'src', 'action' ); }
$GLOBALS['__slug'] = 'resume';
$GLOBALS['__post'] = (object) array( 'ID' => 1184, 'post_type' => 'page', 'post_name' => 'resume', 'post_status' => 'publish', 'post_password' => '', 'post_excerpt' => '', 'post_parent' => 0 );

$root = dirname( __DIR__ );
foreach ( array( 'cloudflare-purge-post-parts', 'resume-page', 'resume-operations-setup', 'resume-pdf/operations', 'resume-sync-engine', 'resume-page-lane-render', 'resume-formats-route', 'seo', 'seo-schema-identity-parts', 'seo-schema-pages-parts', 'resume-lane-address' ) as $f ) {
	require_once "$root/inc/$f.php";
}
$plain = sn_resume_doc_normalize( sn_resume_seed_doc() );
$raw   = sn_resume_doc_normalize( sn_resume_operations_setup( $plain )['doc'] );
$GLOBALS['__opt']['sn_resume_doc']            = $raw;
$GLOBALS['__opt']['sn_resume_pdf_operations'] = array( 'url' => 'https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf', 'sha256' => str_repeat( 'a', 64 ) );
$GLOBALS['__opt']['sn_resume_pdf_operations_bytes'] = base64_encode( '%PDF-1.7' ); // live means a PDF is stored too (sn_resume_ops_pdf_live()).

// The head as WordPress prints it: the <title> parts, then every wp_head tag this plugin's SEO prints.
$head = static function ( $lane ) {
	$_GET = null === $lane ? array() : array( 'lane' => $lane );
	$t    = apply_filters( 'document_title_parts', array( 'title' => 'WP' ) );
	ob_start();
	$hooks = $GLOBALS['__f']['wp_head'];
	ksort( $hooks );
	foreach ( $hooks as $cbs ) {
		foreach ( $cbs as list( $cb ) ) {
			$cb();
		}
	}
	return '<title>' . esc_html( $t['title'] ) . "</title>\n" . ob_get_clean();
};
$tag = static fn( $html, $re ) => preg_match( $re, $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : null;
$title = static fn( $h ) => $tag( $h, '~<title>(.*?)</title>~' );
$prop  = static fn( $h, $p ) => $tag( $h, '~<meta (?:property|name)="' . preg_quote( $p, '~' ) . '" content="([^"]*)"~' );
$ops_first = 'Founder-operator with 15+ years building music businesses across the U.S. and Latin America.';

echo "Group: the operations lane's head\n";
$o = $head( 'operations' );
ok( 'Juan Lentino · Résumé · Operations & AI Strategy' === $title( $o ), '<title> names the lane' );
ok( 'Juan Lentino · Résumé · Operations & AI Strategy' === $prop( $o, 'og:title' ) && $prop( $o, 'og:title' ) === $prop( $o, 'twitter:title' ), 'og:title and twitter:title too' );
ok( $ops_first === $prop( $o, 'description' ) && $ops_first === $prop( $o, 'og:description' ) && $ops_first === $prop( $o, 'twitter:description' ), 'the description is the first sentence of the operations summary, verbatim ("the U.S. and" does not end it)' );
ok( 0 === strpos( (string) sn_resume_lane_doc( $raw, 'operations' )['hero']['summary'], $ops_first . ' ' ), '...read from the stored document' );
ok( 'https://juanlentino.com/resume/?lane=operations' === $prop( $o, 'og:url' ), 'og:url keeps the lane' );
ok( false !== strpos( $o, 'type="application/json" href="https://juanlentino.com/resume/json-resume.json?lane=operations"' ) && false !== strpos( $o, 'type="text/markdown" href="https://juanlentino.com/resume.md?lane=operations"' ), 'the JSON Resume and Markdown alternates name the operations twins' );
$_GET = array( 'lane' => 'operations' );
// The AI plugin's alternate goes through esc_url() (core's clean_url) with no filter of its own.
$ai = static fn( $u, $ctx = 'display' ) => apply_filters( 'clean_url', $u, $u, $ctx );
ok( 'https://juanlentino.com/resume/?output_format=markdown&#038;lane=operations' === $ai( 'https://juanlentino.com/resume/?output_format=markdown' ) && 'https://juanlentino.com/resume/?output_format=markdown&lane=operations' === $ai( 'https://juanlentino.com/resume/?output_format=markdown', 'db' ), 'the AI plugin\'s ?output_format=markdown alternate names the lane, so no Markdown alternate names the other one' );
ok( in_array( $ai( 'https://juanlentino.com/resume/?output_format=markdown', 'db' ), sn_cf_resume_format_urls( $GLOBALS['__post'] ), true ), 'a /resume save purges exactly the Markdown address the head advertises (the edge keys on the query as written)' );
ok( 'https://juanlentino.com/feed/markdown/' === $ai( 'https://juanlentino.com/feed/markdown/' ) && 'https://juanlentino.com/about/?output_format=markdown' === $ai( 'https://juanlentino.com/about/?output_format=markdown' ), '...and nothing else it escapes' );
$_GET = array();
ok( 'https://juanlentino.com/resume/?output_format=markdown' === $ai( 'https://juanlentino.com/resume/?output_format=markdown' ), '...and on the music lane it stays as it was' );
$_GET = array( 'lane' => 'operations' );
$wp   = sn_schema_webpage();
ok( 'ProfilePage' === $wp['@type'] && 'https://juanlentino.com/resume/?lane=operations' === $wp['url'] && $wp['url'] === $wp['@id'] && $ops_first === $wp['description'], 'ProfilePage url, @id and description follow the lane' );
ok( 'https://juanlentino.com/#/schema/Person' === $wp['mainEntity']['@id'] && 'https://juanlentino.com/resume/#breadcrumb' === $wp['breadcrumb']['@id'], '...the Person and the breadcrumb node it points at stay where they are' );

$first = static function ( $summary ) use ( $raw ) {
	$doc = $raw;
	$doc['variants']['operations']['summary'] = $summary;
	$GLOBALS['__opt']['sn_resume_doc'] = $doc; // a new stored value: the memo reads it afresh.
	$out = sn_resume_lane_description( 'PAGE' );
	$GLOBALS['__opt']['sn_resume_doc'] = $raw;
	return $out;
};
ok( 'Built it (twice.)' === $first( 'Built it (twice.) Then more.' ) && 'He said "go."' === $first( 'He said "go." Then went.' ), 'a sentence may end inside a closing parenthesis or quote' );
ok( 'Fifteen years.' === $first( 'Fifteen years. 2026 now.' ), '...and before a digit' );
ok( 'PAGE' === $first( str_repeat( 'word ', 80 ) . 'end. Next.' ) && 'PAGE' === $first( 'no end at all' ), 'no sentence end within 300 characters: the page\'s own description, never a cut one' );

echo "\nGroup: the music lane is unchanged\n";
$m = $head( null );
ok( 'Resume — Juan Lentino' === $title( $m ) && 'Resume — Juan Lentino' === $prop( $m, 'og:title' ), 'the music title, as before' );
ok( 'Founder and music executive, 15+ years across the U.S. and Latin America.' === $prop( $m, 'og:description' ) && 'https://juanlentino.com/resume/' === $prop( $m, 'og:url' ), 'its written description and /resume/' );
ok( $m === $head( 'music' ) && $m === $head( 'bogus' ), '?lane=music and an unknown lane are the music head' );
$_GET = array();
$wm   = sn_schema_webpage();
ok( 'https://juanlentino.com/resume/' === $wm['url'] && $wm['url'] === $wm['@id'] && 0 === strpos( $wm['description'], 'Founder and music executive' ), 'its ProfilePage too' );
$GLOBALS['__opt']['sn_resume_doc'] = $plain;
ok( 'Resume — Juan Lentino' === $title( $head( 'operations' ) ), 'no operations résumé: ?lane=operations is the music head' );
$GLOBALS['__opt']['sn_resume_doc'] = $raw;
$GLOBALS['__slug']                 = 'about';
ok( 'Resume — Juan Lentino' === $title( $head( 'operations' ) ) && 'https://juanlentino.com/about/' === $prop( $head( 'operations' ), 'og:url' ), 'another page with ?lane=operations: untouched' );
$GLOBALS['__slug'] = 'resume';

echo "\nGroup: the twins name their own lane and both lanes\n";
$jo    = sn_jsonresume_for( 'resume', 'operations' );
$jm    = sn_jsonresume_for( 'resume', 'music' );
$lanes = array(
	'music'      => array( 'html' => 'https://juanlentino.com/resume/', 'md' => 'https://juanlentino.com/resume.md', 'json' => 'https://juanlentino.com/resume/json-resume.json', 'pdf' => (string) sn_resume_formats( 'resume' )['pdf'] ),
	'operations' => array( 'html' => 'https://juanlentino.com/resume/?lane=operations', 'md' => 'https://juanlentino.com/resume.md?lane=operations', 'json' => 'https://juanlentino.com/resume/json-resume.json?lane=operations', 'pdf' => 'https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf' ),
);
ok( 'https://juanlentino.com/resume/?lane=operations' === $jo['meta']['canonical'] && 'https://juanlentino.com/resume/' === $jm['meta']['canonical'], 'JSON Resume meta.canonical is its own lane address' );
ok( '' !== $lanes['music']['pdf'] && $lanes === ( $jo['meta']['x-lanes'] ?? null ) && $lanes === ( $jm['meta']['x-lanes'] ?? null ), 'both JSON Resumes carry meta.x-lanes: each lane\'s page, Markdown, JSON and PDF' );
$mo = (string) sn_resume_format_body( 'md', 'resume', 'operations' )[1];
$mm = (string) sn_resume_format_body( 'md', 'resume', 'music' )[1];
ok( false !== strpos( $mo, "\nurl: \"https://juanlentino.com/resume/?lane=operations\"\n" ) && false !== strpos( $mm, "\nurl: \"https://juanlentino.com/resume/\"\n" ), 'the Markdown front matter url is its own lane address' );
$yaml = "lanes:\n  music:\n    html: \"https://juanlentino.com/resume/\"\n    md: \"https://juanlentino.com/resume.md\"\n    json: \"https://juanlentino.com/resume/json-resume.json\"\n    pdf: \"" . $lanes['music']['pdf'] . "\"\n  operations:\n    html: \"https://juanlentino.com/resume/?lane=operations\"\n    md: \"https://juanlentino.com/resume.md?lane=operations\"\n    json: \"https://juanlentino.com/resume/json-resume.json?lane=operations\"\n    pdf: \"https://juanlentino.com/resume/JuanLentino_Resume_Operations.pdf\"\n";
ok( 1 === substr_count( $mo, "\n" . $yaml ) && 1 === substr_count( $mm, "\n" . $yaml ), 'both Markdown front matters carry the lanes: map' );
ok( false !== strpos( $mo, "\n  markdown_operations: \"https://juanlentino.com/resume.md?lane=operations\"\n" ) && false !== strpos( $mm, "\n  markdown_operations: " ), 'formats.markdown_operations in both' );
$canon = static fn( $lane ) => sn_resume_format_headers( 'text/markdown; charset=utf-8', sn_resume_format_canonical( 'resume', $lane ) )[4];
ok( 'Link: <https://juanlentino.com/resume/?lane=operations>; rel="canonical"' === $canon( 'operations' ) && 'Link: <https://juanlentino.com/resume/>; rel="canonical"' === $canon( 'music' ) && 'https://juanlentino.com/cv/' === ( static function () { $GLOBALS['__slug'] = 'cv'; $c = sn_resume_format_canonical( 'cv', 'operations' ); $GLOBALS['__slug'] = 'resume'; return $c; } )(), 'the twins\' Link: rel=canonical is their own lane address (never a lane on /cv)' );
// header() records nothing under the CLI, so the wiring is read from the source.
$src = (string) file_get_contents( "$root/inc/resume-formats-route.php" ) . file_get_contents( "$root/inc/resume-jsonresume-route.php" ) . file_get_contents( "$root/inc/resume-formats-parts.php" );
ok( false !== strpos( $src, "sn_resume_format_send( \$body[0], \$route[1], 'md' === \$route[0] ? \$lane : 'music' )" ) && false !== strpos( $src, "sn_resume_format_send( 'application/json; charset=utf-8', \$slug, (string) ( \$resume['meta']['x-lane'] ?? 'music' ) )" ) && false !== strpos( $src, 'sn_resume_format_headers( $type, sn_resume_format_canonical( $slug, $lane ) )' ), '...both routes send it with their lane, the vCard as music' );
ok( false === strpos( $mo . $mm . json_encode( $jo ), '555' ) && ! isset( $jo['basics']['phone'] ), 'no phone anywhere' );

echo "\nGroup: the music twins change only by the lanes map\n";
// Held to the branch's own output before this change (69143006), from this fixture.
$jm_less = $jm;
unset( $jm_less['meta']['x-lanes'] );
ok( 'f01a171b101376d1e088dddd147fc6985e3e87ce' === sha1( json_encode( $jm_less ) ) && isset( $jm['meta']['x-lanes'] ), 'the music JSON Resume is the old one byte for byte, plus meta.x-lanes' );
ok( '42b1c4c1ad45a8a60ad9d1c11a3e6c3e721f0dd7' === sha1( str_replace( $yaml, '', $mm ) ) && false !== strpos( $mm, $yaml ), 'the music Markdown is the old one byte for byte, plus the lanes: map' );
$GLOBALS['__opt']['sn_resume_doc'] = $plain;
ok( ! isset( sn_jsonresume_for( 'resume', 'music' )['meta']['x-lanes'] ) && false === strpos( (string) sn_resume_format_body( 'md', 'resume', 'music' )[1], 'lanes:' ), 'one lane: no lanes map at all' );
$GLOBALS['__opt']['sn_resume_doc'] = $raw;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
