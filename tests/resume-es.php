<?php
/**
 * Tests: the Spanish résumé (inc/resume-es.php, inc/resume-lang.php). One
 * Spanish file renders /cv/ and JuanLentino_CV.pdf through the same builder
 * and template as /resume; English output is untouched.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sanitize_email( $s ) { return (string) $s; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function add_action() {}
function apply_filters( $t, $v ) { return $v; }
$GLOBALS['__page'] = null; $GLOBALS['__updates'] = array();
function get_page_by_path( $p ) { return $GLOBALS['__page']; }
function wp_slash( $v ) { return $v; }
function wp_update_post( $a ) { $GLOBALS['__updates'][] = $a; return $a['ID']; }
function is_wp_error( $x ) { return false; }
function sn_lang_en_counterpart( $id ) { return 1184; }
if ( ! defined( 'SN_RESUME_SLUG' ) ) { define( 'SN_RESUME_SLUG', 'resume' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-sync-engine.php';
require_once __DIR__ . '/../inc/resume-es.php';

echo "\nTest: the Spanish file\n";
$raw = json_decode( (string) file_get_contents( __DIR__ . '/../inc/seed-content/resume-data-es.json' ), true );
ok( is_array( $raw ), 'resume-data-es.json is valid JSON' );
$flat = json_encode( $raw );
ok( false === strpos( $flat, '"phone"' ) && false === strpos( $flat, '"email"' ), 'no contact details in the committed file: they come from the live English document' );

$en  = sn_resume_doc_normalize( sn_resume_seed_doc() );
$en['pdf']['phone'] = '+1 555 0100'; $en['pdf']['email'] = 'juan@example.test'; $en['hero']['linkedin'] = 'https://www.linkedin.com/in/juanlentino';
$es  = sn_resume_es_merge( $raw, $en, 'https://x.test/uploads/resume/JuanLentino_CV.pdf?v=abcd1234' );
ok( is_array( $es ), 'the Spanish document normalizes' );
ok( '+1 555 0100' === $es['pdf']['phone'] && 'juan@example.test' === $es['pdf']['email'] && 'https://www.linkedin.com/in/juanlentino' === $es['hero']['linkedin'], 'contact details are taken from the English document' );
ok( count( $es['experience'] ) === 3 && count( $es['publications'] ) === 3 && count( $es['skills'] ) === 10, 'every section carries over (3 employers, 3 papers, 10 skill rows)' );

echo "\nTest: rendering\n";
$es_body = sn_resume_es_render( static fn() => sn_resume_body_html( $es ) );
ok( 'en' === sn_resume_lang(), 'the render language is switched back to English afterwards' );
foreach ( array( 'CURRICULUM VITAE', 'Descargar PDF', '01 · Experiencia profesional', 'CREDENCIALES', 'PUBLICACIONES', 'HABILIDADES', 'Trayectoria anterior' ) as $needle ) {
	ok( false !== strpos( $es_body, $needle ), "the Spanish body says \"$needle\"" );
}
ok( false !== strpos( $es_body, 'JuanLentino_CV.pdf?v=abcd1234' ), 'the Download button points at the Spanish PDF' );
ok( false === strpos( $es_body, 'Download PDF' ) && false === strpos( $es_body, '>RESUME<' ), 'no English heading leaks into /cv/' );
$en_body = sn_resume_body_html( $en );
ok( false !== strpos( $en_body, '>RESUME<' ) && false !== strpos( $en_body, 'Download PDF' ) && false === strpos( $en_body, 'Descargar' ), 'the English body is untouched' );

echo "\nTest: filling /cv/\n";
$GLOBALS['__page'] = (object) array( 'ID' => 3650, 'post_content' => '' );
$GLOBALS['__options'][ SN_RESUME_ES_PDF_OPT ] = array( 'url' => 'https://x.test/uploads/resume/JuanLentino_CV.pdf?v=abcd1234' );
ok( true === sn_resume_es_sync_page() && 1 === count( $GLOBALS['__updates'] ), '/cv/ is written once' );
ok( array( 'ID', 'post_content' ) === array_keys( $GLOBALS['__updates'][0] ), 'only its content is written: the status stays the owner\'s' );
$GLOBALS['__page']->post_content = $GLOBALS['__updates'][0]['post_content'];
ok( true === sn_resume_es_sync_page() && 1 === count( $GLOBALS['__updates'] ), 'an unchanged body is not written again' );
$GLOBALS['__page'] = null;
ok( false === sn_resume_es_sync_page(), 'no /cv/ page yet: nothing to fill' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
