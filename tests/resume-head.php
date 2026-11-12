<?php
/**
 * /resume's head is one head (owner, 2026-11-12): whatever `?lane=` says, the
 * <title>, og and twitter tags, og:url, the alternates and the ProfilePage are
 * the same; og:url and the canonical are /resume/; the description is the
 * first sentence of the one résumé's summary (inc/resume-page-description.php).
 *
 * Run: php tests/resume-head.php
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
function get_post_status( $p = null ) { return 'publish'; }
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
foreach ( array( 'cloudflare-purge-post-parts', 'resume-page', 'resume-operations-setup', 'resume-pdf/operations', 'resume-sync-engine', 'resume-formats-route', 'seo', 'seo-schema-identity-parts', 'seo-schema-pages-parts', 'resume-page-description' ) as $f ) {
	require_once "$root/inc/$f.php";
}
$plain = sn_resume_doc_normalize( sn_resume_seed_doc() );
$raw   = sn_resume_doc_normalize( sn_resume_operations_setup( $plain )['doc'] );
$GLOBALS['__opt']['sn_resume_doc']                  = $raw;
$GLOBALS['__opt']['sn_resume_pdf_operations']       = array( 'url' => 'https://juanlentino.com/resume/JuanLentino_Resume.pdf', 'sha256' => str_repeat( 'a', 64 ) );
$GLOBALS['__opt']['sn_resume_pdf_operations_bytes'] = base64_encode( '%PDF-1.7' );

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

echo "Group: one head, whatever ?lane= says\n";
$h = $head( null );
ok( 'Resume — Juan Lentino' === $title( $h ) && 'Resume — Juan Lentino' === $prop( $h, 'og:title' ) && $prop( $h, 'og:title' ) === $prop( $h, 'twitter:title' ), 'the page\'s own title, no lane in it' );
ok( $ops_first === $prop( $h, 'description' ) && $ops_first === $prop( $h, 'og:description' ) && $ops_first === $prop( $h, 'twitter:description' ), 'the description is the first sentence of the résumé\'s summary, verbatim ("the U.S." does not end it)' );
ok( 0 === strpos( (string) sn_resume_lane_doc( $raw, 'operations' )['hero']['summary'], $ops_first . ' ' ), '...read from the stored document' );
ok( 'https://juanlentino.com/resume/' === $prop( $h, 'og:url' ), 'og:url is /resume/' );
ok( false !== strpos( $h, 'type="application/json" href="https://juanlentino.com/resume/json-resume.json"' ) && false !== strpos( $h, 'type="text/markdown" href="https://juanlentino.com/resume.md"' ) && false === strpos( $h, 'lane' ) && false === strpos( $h, 'text/vcard' ), 'the plain alternates, no lane, no vCard' );
foreach ( array( 'operations', 'music', '"><script>' ) as $l ) {
	ok( $h === $head( $l ), '?lane=' . htmlspecialchars( $l ) . ': the same head, byte for byte' );
}
$_GET = array( 'lane' => 'operations' );
$ai   = static fn( $u, $ctx = 'display' ) => apply_filters( 'clean_url', $u, $u, $ctx );
ok( 'https://juanlentino.com/resume/?output_format=markdown' === $ai( 'https://juanlentino.com/resume/?output_format=markdown' ), 'the AI plugin\'s Markdown alternate gets no lane added, even on ?lane=operations' );
$wp = sn_schema_webpage();
ok( 'ProfilePage' === $wp['@type'] && 'https://juanlentino.com/resume/' === $wp['url'] && $wp['url'] === $wp['@id'] && $ops_first === $wp['description'], 'ProfilePage url and @id are /resume/; its description the résumé\'s' );
ok( 'https://juanlentino.com/#/schema/Person' === $wp['mainEntity']['@id'] && 'https://juanlentino.com/resume/#breadcrumb' === $wp['breadcrumb']['@id'], '...the Person and the breadcrumb node stay where they are' );
$_GET = array();

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

echo "\nGroup: elsewhere, nothing changes\n";
$GLOBALS['__opt']['sn_resume_doc'] = $plain;
ok( 'Founder and music executive, 15+ years across the U.S. and Latin America.' === $prop( $head( null ), 'og:description' ) && false !== strpos( $head( null ), 'type="text/vcard"' ), 'no operations settings: the page\'s written description, and its vCard alternate' );
$GLOBALS['__opt']['sn_resume_doc'] = $raw;
$GLOBALS['__slug']                 = 'about';
ok( 'https://juanlentino.com/about/' === $prop( $head( 'operations' ), 'og:url' ) && 'PAGE' === sn_resume_lane_description( 'PAGE' ), 'another page: untouched' );
$GLOBALS['__slug'] = 'resume';

echo "\nGroup: the twins name /resume/\n";
$jr = sn_jsonresume_for( 'resume' );
$md = (string) sn_resume_format_body( 'md', 'resume' )[1];
ok( 'https://juanlentino.com/resume/' === $jr['meta']['canonical'] && false !== strpos( $md, "\nurl: \"https://juanlentino.com/resume/\"\n" ), 'JSON Resume meta.canonical and the Markdown url are /resume/' );
ok( 'Link: <https://juanlentino.com/resume/>; rel="canonical"' === sn_resume_format_headers( 'text/markdown; charset=utf-8', sn_resume_format_canonical( 'resume' ) )[4], 'and the formats\' Link header' );
ok( false === strpos( $md . json_encode( $jr ), '555' ) && ! isset( $jr['basics']['phone'] ), 'no phone anywhere' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
