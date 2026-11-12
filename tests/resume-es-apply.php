<?php
/**
 * Guard: signal-noise/resume-es-apply (inc/abilities-resume-es.php), the
 * Spanish CV written without a release (owner, 2026-11-12): read, dry run,
 * the refusals (a phone, a document that does not render) and the write.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/resume-es-apply.php
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
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $c; public $m; function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; } function get_error_code() { return $this->c; } function get_error_message() { return $this->m; } } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return false; } }
if ( ! function_exists( 'error_log_stub' ) ) { function error_log_stub() {} }
require_once "$root/inc/resume-page.php";
require_once "$root/inc/resume-operations-setup.php";
require_once "$root/inc/resume-sync-engine.php";
require_once "$root/inc/resume-es.php";
require_once "$root/inc/resume-es-stale.php";
require_once "$root/inc/abilities-resume-es.php";
if ( ! defined( 'SN_RESUME_ENGINE_REV' ) ) { define( 'SN_RESUME_ENGINE_REV', 7 ); }
$GLOBALS['__opt']['sn_resume_doc'] = sn_resume_doc_normalize( sn_resume_seed_doc() );
$seed = json_decode( (string) file_get_contents( "$root/inc/seed-content/resume-data-es.json" ), true );

echo "Group: read\n";
$r = snt_resume_es_apply( array() );
ok( 'bundled' === $r['source'] && $seed === $r['document'] && 12 === strlen( $r['english_version'] ), 'no document: the current one, from the bundled seed, with the English version code' );

echo "\nGroup: refusals\n";
$phone = $seed;
$phone['hero']['contact_line'] = 'Orlando, FL | +1 (407) 555-0199';
ok( is_wp_error( snt_resume_es_apply( array( 'document' => $phone ) ) ), 'a phone in any line is refused' );
$phone2 = $seed;
$phone2['pdf']['phone'] = '4075550199';
ok( is_wp_error( snt_resume_es_apply( array( 'document' => $phone2 ) ) ), 'a phone field is refused' );
ok( false === snt_resume_es_has_phone( 'Trayectoria anterior · 1997 - 2015, ene. 2005 - dic. 2015, de 600 a 850' ), 'date ranges and counts are not phones' );
ok( is_wp_error( snt_resume_es_apply( array( 'document' => array( 'hero' => array( 'summary' => 'x' ) ) ) ) ), 'a document that does not render is refused' );
ok( is_wp_error( snt_resume_es_apply( array( 'document' => 'nope' ) ) ), 'not an object: refused' );

echo "\nGroup: dry run and write\n";
$next = $seed;
$next['hero']['summary'] = 'Resumen nuevo, de prueba.';
$dry = snt_resume_es_apply( array( 'document' => $next ) );
ok( true === $dry['dry_run'] && in_array( 'Resumen nuevo, de prueba.', $dry['diff']['changed'], true ) && ! isset( $GLOBALS['__opt'][ SN_RESUME_ES_DOC_OPT ] ), 'dry run by default: the diff, nothing stored' );
$w = snt_resume_es_apply( array( 'document' => $next, 'dry_run' => false ) );
ok( false === $w['dry_run'] && $next === json_decode( $GLOBALS['__opt'][ SN_RESUME_ES_DOC_OPT ], true ), 'dry_run false: stored as JSON in the option' );
ok( 'stored' === snt_resume_es_apply( array() )['source'] && 'Resumen nuevo, de prueba.' === sn_resume_es_doc()['hero']['summary'], 'from then on /cv reads the stored text, not the bundled seed' );
ok( false !== strpos( (string) file_get_contents( "$root/inc/mcp/mcp-capabilities.php" ), "'signal-noise/resume-es-apply'," ), 'on the write door' );

$ci = (string) getenv( 'CI' );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
