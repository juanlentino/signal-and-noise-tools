<?php
/**
 * Guard: Generate, for real (inc/resume-pdf/generate.php through Dompdf, the
 * check and the operations build it fires).
 *
 * A renderer that throws is an error to report, not a fatal. No PDF carries
 * a phone: the old switch changes nothing, and a phone in the text fails
 * Generate. And what the owner is told after Generate, Publish or Revert names a
 * PDF that failed. Since 2026-11-12 the music PDF is built only for a document
 * without résumé settings. A music, résumé or Spanish PDF that fails is logged
 * and recorded, and the PDF box shows it.
 *
 * Run: php tests/resume-pdf-generate.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/lib/resume-pdf-harness.php';
require_once __DIR__ . '/../inc/admin-flash-messages.php';
require_once __DIR__ . '/../inc/admin-post-actions/resume-draft.php';

$set   = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( sn_resume_seed_doc() ) )['doc'] );
$plain = sn_resume_doc_normalize( sn_resume_seed_doc() ); // no résumé settings: the music PDF is the fallback (2026-11-12).
$flags = static fn() => (array) ( $GLOBALS['opts']['sn_resume_pdf']['check']['flags'] ?? array( 'never checked' ) );

echo "Group: the renderer\n";
$log = $GLOBALS['tmp'] . '-error.log';
ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet -- the test reads what the code logs.
$logged = static fn( $needle ) => false !== strpos( (string) @file_get_contents( $log ), $needle );
try {
	$thrown = sn_resume_pdf_render( sn_resume_doc_view( $set, 'music' ), new stdClass(), $GLOBALS['tmp'] . '/cache' ); // a stand-in for any exception inside Dompdf.
} catch ( Throwable $t ) {
	$thrown = $t;
}
ok( is_wp_error( $thrown ) && 'sn_resume_pdf_render' === $thrown->get_error_code(), 'a renderer that throws returns an error, so Generate reports it instead of a fatal' );
ok( $logged( 'résumé PDF render failed: ' ), 'and its message is written to the error log, whichever caller asked' );

echo "\nGroup: each PDF its own lane's address\n";
sn_test_pdf_reset( $plain );
sn_resume_pdf_generate();
$music = (string) file_get_contents( $GLOBALS['tmp'] . '/resume/' . SN_RESUME_PDF_FILE );
$mtext = sn_resume_pdf_text( $music );
ok( 1 === preg_match( '~example\.test/resume(?!/\?lane=operations)~', $mtext ) && false !== strpos( $music, '/URI (https://example.test/resume/)' ), 'the music PDF prints and links its lane: /resume' );
ok( false === strpos( $mtext, '?lane=operations' ) && false === strpos( $music, '?lane=operations' ), 'and not the operations lane\'s address' );
ok( 'Juan Lentino, Resume' === sn_test_pdf_title( $music ), 'the music Info Title is today\'s: "' . sn_test_pdf_title( $music ) . '"' );
// Held to the same document rendered with the bare home URL, which prints
// main's contact line (tests/resume-variants.php pins that HTML to main's).
$bare = sn_resume_pdf_render( sn_resume_doc_get(), 'Juan Lentino', $GLOBALS['tmp'] . '/cache', false, 'https://example.test/', sn_resume_pdf_music_config( sn_resume_doc_get_raw() ) );
ok( str_replace( 'example.test', 'example.test/resume', sn_resume_pdf_text( $bare['bytes'] ) ) === $mtext, 'and nothing else in the music PDF changed: its text is main\'s but the address' );
sn_test_pdf_reset( $set );
sn_resume_pdf_generate();
$ops = base64_decode( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] );
ok( false !== strpos( $ops, '/URI (https://example.test/resume/)' ) && false === strpos( $ops, 'lane=' ) && 'Juan Lentino · Résumé' === sn_test_pdf_title( $ops ), 'with résumé settings, Generate builds the résumé PDF with its own address and Info Title' );

echo "\nGroup: no PDF carries a phone\n";
$pub                        = $plain;
$pub['pdf']['phone']        = '(407) 555-0123';
$pub['pdf']['phone_public'] = true; // the old switch, still stored: it must change nothing.
sn_test_pdf_reset( $pub );
ok( ! is_wp_error( sn_resume_pdf_generate() ) && array() === $flags(), 'the phone stored and the old switch on: the music PDF builds without it, and nothing is flagged' );
$other                   = $pub;
$other['pdf']['summary'] = 'Call (321) 555-0199 for references. ' . $plain['hero']['summary'];
sn_test_pdf_reset( $other );
$e = sn_resume_pdf_generate();
ok( is_wp_error( $e ) && 'sn_resume_pdf_phone' === $e->get_error_code() && ! isset( $GLOBALS['opts']['sn_resume_pdf']['sha256'] ) && false !== strpos( (string) ( $GLOBALS['opts']['sn_resume_pdf']['error'] ?? '' ), 'phone number' ), 'a phone in the résumé text fails Generate: nothing is published, and the error is stored for the box' );
$own                   = $pub;
$own['pdf']['summary'] = 'Reach me at 407 5550123 or by email. ' . $plain['hero']['summary'];
sn_test_pdf_reset( $own );
$e = sn_resume_pdf_generate();
ok( is_wp_error( $e ) && 'sn_resume_pdf_phone' === $e->get_error_code(), 'so does the stored phone typed into the text in a format only its digits match' );

echo "\nGroup: what the owner is told\n";
$ops_phone                                     = $set;
$ops_phone['variants']['operations']['summary'] .= ' Call (407) 555-0123.';
sn_test_pdf_reset( $set );
ok( 'resume_pdf_generated' === sn_handle_resume_pdf_generate( array() ), 'Generate, both PDFs built: generated' );
sn_test_pdf_reset( $ops_phone );
$code = sn_handle_resume_pdf_generate( array() );
ok( 'resume_pdf_failed' === $code && isset( sn_admin_flash_messages()[ $code ] ), 'Generate, the résumé PDF refused: the résumé PDF was not built, not "generated" (it is the only PDF, 2026-11-12)' );
// As on the site since 2026-11-12: Generate first (no music facts are kept), then Publish.
sn_test_pdf_reset( $set );
sn_resume_pdf_generate();
$GLOBALS['opts']['sn_resume_draft'] = $ops_phone;
ok( ! isset( $GLOBALS['opts']['sn_resume_pdf'] ) && 'resume_published_pdf_failed' === sn_handle_resume_publish( array() ), 'Publish, the résumé PDF refused: published, PDF failed' );
sn_test_pdf_reset( $ops_phone );
sn_resume_pdf_generate(); // withdrawn for the phone: not live, its error recorded.
$GLOBALS['opts']['sn_resume_draft'] = $set;
ok( ! sn_resume_ops_pdf_live() && 'resume_published' === sn_handle_resume_publish( array() ) && ! sn_resume_ops_pdf_failed() && sn_resume_ops_pdf_live(), 'Publish after a withdrawn résumé PDF: it is rebuilt, published, and the earlier failure is cleared' );
ok( false !== strpos( sn_admin_flash_messages()['resume_published_pdf_failed'][1], 'a PDF could not be rebuilt' ), 'the publish flash says a PDF, not the PDF' );

echo "\nGroup: a PDF that fails is recorded and shown\n";
$box   = static fn() => implode( "\n", array_column( sn_resume_pdf_variant_lines(), 'text' ) );
$flash = sn_admin_flash_messages();
sn_test_pdf_reset( $plain );
sn_resume_pdf_generate(); // the music PDF (no résumé settings).
$good                = $GLOBALS['opts']['sn_resume_pdf'];
$GLOBALS['up_error'] = 'Unable to create directory';
ok( is_wp_error( sn_resume_pdf_generate() ), 'the music PDF failing: Generate is an error' );
$m = $GLOBALS['opts']['sn_resume_pdf'];
ok( 'The uploads directory is unavailable.' === ( $m['error'] ?? '' ) && ! empty( $m['error_at'] ) && $good['sha256'] === $m['sha256'], 'the error is stored beside the facts of the file still served' );
ok( false !== strpos( $box(), 'Music PDF: the last Generate failed (The uploads directory is unavailable.). The file above is still the one served.' ), 'the PDF box shows it, instead of only the old check' );
$GLOBALS['up_error'] = false;
ok( is_array( sn_resume_pdf_generate() ) && ! isset( $GLOBALS['opts']['sn_resume_pdf']['error'] ) && false === strpos( $box(), 'failed' ), 'the next good Generate clears the error' );
sn_test_pdf_reset( $set );
sn_resume_pdf_generate(); // the one résumé: its PDF only.
$good                = $GLOBALS['opts']['sn_resume_pdf_operations'];
$changed             = $set;
$changed['hero']['summary'] .= ' One more sentence.';
$GLOBALS['opts']['sn_resume_draft'] = $changed;
$GLOBALS['up_error'] = 'Unable to create directory';
ok( 'resume_published_pdf_failed' === sn_handle_resume_publish( array() ), 'Publish, the résumé PDF failing before it is built: published, PDF failed' );
$m = $GLOBALS['opts']['sn_resume_pdf_operations'];
ok( 'The uploads directory is unavailable.' === ( $m['error'] ?? '' ) && $good['sha256'] === $m['sha256'] && ! isset( $GLOBALS['opts']['sn_resume_pdf'] ), 'the error is stored beside the résumé PDF\'s facts, and no music facts appear' );
ok( false !== strpos( $box(), 'Résumé PDF: the last Generate failed (The uploads directory is unavailable.). The file below is still the one served.' ) && false === strpos( $box(), 'Music PDF' ), 'the PDF box shows it on the Résumé PDF' );
ok( 'resume_pdf_failed' === sn_handle_resume_pdf_generate( array() ) && false !== strpos( $flash['resume_pdf_failed'][1], 'The PDF box shows the error.' ) && false === strpos( $flash['resume_pdf_failed'][1], 'lib/pdf' ), 'Generate failing: the flash points at the PDF box and names no cause' );
$GLOBALS['opts']['sn_resume_draft'] = $changed;
ok( 'resume_pdf_stream_failed' === sn_handle_resume_preview_pdf( array() ) && isset( $flash['resume_pdf_stream_failed'] ) && false === strpos( $flash['resume_pdf_stream_failed'][1], 'PDF box' ), 'a draft preview that cannot be built has its own flash: it stores nothing for the box to show' );
$GLOBALS['up_error'] = false;
ok( is_array( sn_resume_pdf_generate() ) && empty( $GLOBALS['opts']['sn_resume_pdf_operations']['error'] ) && false === strpos( $box(), 'failed' ), 'the next good Generate clears the error' );

echo "\nGroup: a Spanish CV that fails is recorded too\n";
require_once __DIR__ . '/../inc/resume-es.php';
$GLOBALS['up_error'] = 'Unable to create directory';
ok( false === sn_resume_es_pdf_generate() && 'No Spanish document, or uploads/resume is unavailable.' === ( $GLOBALS['opts']['sn_resume_pdf_es']['error'] ?? '' ), 'it is stored on its own facts, not lost in a false' );
ok( $logged( 'Spanish CV PDF not built: No Spanish document' ) && false !== strpos( $box(), 'Spanish CV PDF: the last build failed' ), 'logged, and shown in the PDF box' );

sn_test_pdf_cleanup();
@unlink( $log );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
