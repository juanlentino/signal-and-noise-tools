<?php
/**
 * Guard: the phone is in no version, human or machine (owner, 2026-11-02).
 *
 * With the phone stored and the old "phone public" switch on, no PDF text
 * layer (the music PDF, the operations PDF, the Spanish CV; read with
 * pdftotext when installed, else the built-in reader), no page body (/resume
 * on /resume, /cv, the hero rail inside them) and no identity JSON-LD
 * carries it.
 *
 * Run: php tests/resume-no-phone.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SNT_TEST_WITH_BLOCKS_PHP', true ); // The /resume body is serialized by core's get_comment_delimited_block_content().
require_once __DIR__ . '/lib/wp-core-blocks.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
// /resume exists (Generate re-saves its body); /cv does not, so the Spanish sync stops there.
$GLOBALS['resume_page'] = (object) array( 'ID' => 1184, 'post_content' => '' );
function get_page_by_path( $slug ) { return 'resume' === $slug ? $GLOBALS['resume_page'] : null; }
function wp_slash( $v ) { return $v; }
function wp_update_post( $a ) { $GLOBALS['resume_page']->post_content = $a['post_content']; return $a['ID']; }

require_once __DIR__ . '/lib/resume-pdf-harness.php';
require_once __DIR__ . '/../inc/resume-sync-engine.php';
require_once __DIR__ . '/../inc/resume-es.php';

// The stored phone, digits only: found in any format.
$digits = '4075550123';
$has    = static fn( $t ) => false !== strpos( (string) preg_replace( '/\D+/', '', (string) $t ), $digits );
$which  = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
$text   = static function ( $bytes ) use ( $which ) {
	if ( '' === $which ) {
		return sn_resume_pdf_text( $bytes );
	}
	$f = $GLOBALS['tmp'] . '/read.pdf';
	file_put_contents( $f, $bytes );
	return (string) shell_exec( escapeshellarg( $which ) . ' ' . escapeshellarg( $f ) . ' - 2>/dev/null' );
};
echo '' === $which ? "NOTE: pdftotext is not installed; the built-in reader reads the PDFs\n" : "NOTE: the PDFs are read with pdftotext\n";

$doc                        = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( sn_resume_seed_doc() ) )['doc'] );
$doc['pdf']['phone']        = '(407) 555-0123';
$doc['pdf']['phone_public'] = true;
$doc['pdf']['email']        = 'juan@example.com';
$doc                        = sn_resume_doc_normalize( $doc );
ok( is_array( $doc ) && '(407) 555-0123' === $doc['pdf']['phone'] && true === $doc['pdf']['phone_public'], 'the fixture stores the phone with the old switch on' );

echo "\nGroup: every PDF\n";
sn_test_pdf_reset( $doc );
$music = sn_resume_pdf_generate(); // fires the operations build too.
$file  = (string) @file_get_contents( $GLOBALS['tmp'] . '/resume/' . SN_RESUME_PDF_FILE );
$mt    = $text( $file );
ok( is_array( $music ) && false !== strpos( $mt, 'juan@example.com' ) && ! $has( $mt ), 'the music PDF: built, its contact line printed, no phone in its text layer' );
$ops = sn_resume_ops_pdf_bytes();
$ot  = $text( $ops );
ok( '' !== $ops && '' !== trim( $ot ) && ! $has( $ot ), 'the operations PDF: built, no phone in its text layer' );
$es = sn_resume_es_doc();
$er = sn_resume_es_render( static fn() => sn_resume_pdf_render( $es, 'Juan Lentino', $GLOBALS['tmp'] . '/resume/.font-cache', true, 'https://example.test/' ) );
$et = is_array( $er ) ? $text( $er['bytes'] ) : '';
ok( is_array( $es ) && '(407) 555-0123' === $es['pdf']['phone'] && '' !== trim( $et ) && ! $has( $et ), 'the Spanish CV: given the phone and asked for it, no phone in its text layer' );
ok( ! $has( sn_resume_pdf_html( sn_resume_doc_view( $doc, 'music' ), 'Juan Lentino', '/fonts', true, 'https://example.test/' ) ), 'the template a draft preview streams prints none either, whatever the caller asks' );

echo "\nGroup: every page\n";
$en_body = (string) $GLOBALS['resume_page']->post_content; // what Generate wrote to /resume.
ok( '' !== $en_body && $en_body === sn_resume_page_body( $doc ) && false !== strpos( $en_body, 'sn-resume-headline' ) && false === strpos( $en_body, 'sn-resume-variant' ), '/resume was written by Generate: the one résumé' );
ok( ! $has( $en_body ), '/resume (the hero rail included): no phone' );
$cv_body = (string) sn_resume_es_render( static fn() => sn_resume_body_html( $es ) );
ok( '' !== $cv_body && ! $has( $cv_body ), '/cv: no phone' );
$schema = '';
foreach ( (array) glob( __DIR__ . '/../inc/seo-schema*.php' ) as $f ) {
	$schema .= (string) file_get_contents( $f );
}
ok( '' !== $schema && false === stripos( $schema, 'telephone' ) && false === strpos( $schema, "['phone']" ), 'the JSON-LD builders have no telephone and read no phone' );

sn_test_pdf_cleanup();
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
