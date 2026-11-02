<?php
/**
 * Guard: Generate, for real (inc/resume-pdf/generate.php through Dompdf, the
 * check and the operations build it fires).
 *
 * A renderer that throws is an error to report, not a fatal. The public
 * phone, switched on, is not flagged every time; another phone still is. And
 * what the owner is told after Generate, Publish or Revert names an
 * operations PDF that failed beside a music one that did not.
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
$flags = static fn() => (array) ( $GLOBALS['opts']['sn_resume_pdf']['check']['flags'] ?? array( 'never checked' ) );

echo "Group: the renderer\n";
try {
	$thrown = sn_resume_pdf_render( sn_resume_doc_view( $set, 'music' ), new stdClass(), $GLOBALS['tmp'] . '/cache' ); // a stand-in for any exception inside Dompdf.
} catch ( Throwable $t ) {
	$thrown = $t;
}
ok( is_wp_error( $thrown ) && 'sn_resume_pdf_render' === $thrown->get_error_code(), 'a renderer that throws returns an error, so Generate reports it instead of a fatal' );

echo "\nGroup: the public phone\n";
$pub                        = $set;
$pub['pdf']['phone']        = '(407) 555-0123';
$pub['pdf']['phone_public'] = true;
sn_test_pdf_reset( $pub );
ok( ! is_wp_error( sn_resume_pdf_generate() ) && ! in_array( 'a phone number', $flags(), true ), 'switched on, the phone the music PDF prints on purpose is not flagged on every Generate' );
ok( false !== strpos( sn_resume_pdf_check_line( $GLOBALS['opts']['sn_resume_pdf'] ), 'other than the public one' ), 'and the check line says it passed with the public phone in it' );
$other                   = $pub;
$other['pdf']['summary'] = 'Call (321) 555-0199 for references. ' . $set['hero']['summary'];
sn_test_pdf_reset( $other );
sn_resume_pdf_generate();
ok( in_array( 'a phone number', $flags(), true ), 'another phone in the music PDF is still flagged' );
$off                        = $pub;
$off['pdf']['phone_public'] = false;
sn_test_pdf_reset( $off );
sn_resume_pdf_generate();
ok( array() === $flags(), 'switched off, the phone is not printed and nothing is flagged' );

echo "\nGroup: what the owner is told\n";
$ops_phone                                     = $set;
$ops_phone['variants']['operations']['summary'] .= ' Call (407) 555-0123.';
sn_test_pdf_reset( $set );
ok( 'resume_pdf_generated' === sn_handle_resume_pdf_generate( array() ), 'Generate, both PDFs built: generated' );
sn_test_pdf_reset( $ops_phone );
$code = sn_handle_resume_pdf_generate( array() );
ok( 'resume_pdf_ops_failed' === $code && isset( sn_admin_flash_messages()[ $code ] ), 'Generate, the operations PDF refused: a flash that says so, not "generated"' );
sn_test_pdf_reset( $set, array( 'sn_resume_draft' => $ops_phone, 'sn_resume_pdf' => array( 'sha256' => str_repeat( 'a', 64 ) ) ) );
ok( 'resume_published_pdf_failed' === sn_handle_resume_publish( array() ), 'Publish, the operations PDF refused: published, PDF failed' );
sn_test_pdf_reset( $ops_phone, array( 'sn_resume_draft' => $set, 'sn_resume_pdf' => array( 'sha256' => str_repeat( 'a', 64 ) ) ) );
ok( 'resume_published' === sn_handle_resume_publish( array() ) && ! sn_resume_ops_pdf_failed(), 'Publish, both built: published, and the earlier failure is cleared' );
ok( false !== strpos( sn_admin_flash_messages()['resume_published_pdf_failed'][1], 'a PDF could not be rebuilt' ), 'the publish flash says a PDF, not the PDF' );

sn_test_pdf_cleanup();
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
