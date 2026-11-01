<?php
/**
 * Guard: the check on every generated résumé PDF (inc/resume-pdf/text-check.php).
 *
 * The built-in reader must read what pdftotext reads (word for word, when the
 * machine has pdftotext), and the flags must fire on what the brief names:
 * control characters, ligatures, a phone number in the text or the metadata,
 * too many pages.
 *
 * Run: php tests/resume-pdf-text-check.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }
function get_option( $k, $d = false ) { return $d; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-pdf/generate.php';
require_once __DIR__ . '/../inc/resume-pdf/text-check.php';

echo "Group: the reader\n";
$doc = sn_resume_doc_normalize( sn_resume_seed_doc() );
$doc['pdf']['email'] = 'juan@juanlentino.com';
$cache = sys_get_temp_dir() . '/sn-resume-text-check-' . getmypid();
$r     = sn_resume_pdf_render( $doc, 'Juan Lentino', $cache, false, 'https://juanlentino.com/' );
ok( is_array( $r ) && 0 === strpos( $r['bytes'], '%PDF-' ), 'the fixture renders' );
$text = sn_resume_pdf_text( $r['bytes'] );
ok( false !== strpos( $text, 'JUAN LENTINO' ) && false !== strpos( $text, 'juan@juanlentino.com' ) && false !== strpos( $text, $doc['experience'][0]['roles'][0]['bullets'][0] === strip_tags( $doc['experience'][0]['roles'][0]['bullets'][0] ) ? mb_substr( $doc['experience'][0]['roles'][0]['bullets'][0], 0, 12 ) : 'PROFESSIONAL' ), 'it reads the name, the email and the first bullet' );
ok( 0 === preg_match( '/[\x00-\x08\x0B\x0D-\x1F]/', $text ), 'the text it reads holds no control characters (it decodes, not copies, the UTF-16 strings)' );
$which = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
if ( '' !== $which ) {
	$tmp = $cache . '/t.pdf';
	file_put_contents( $tmp, $r['bytes'] );
	$ref   = (string) shell_exec( 'pdftotext ' . escapeshellarg( $tmp ) . ' - 2>/dev/null' );
	$words = static function ( $t ) { preg_match_all( '/[^\s.,;:()]+/u', $t, $m ); return array_count_values( $m[0] ); };
	ok( count( $words( $text ) ) > 100 && $words( $ref ) == $words( $text ), 'it reads the same words as pdftotext, as often' ); // == : same pairs, any order.
} else {
	echo "SKIP: pdftotext is not installed; the strings above still had to be read\n";
}

echo "\nGroup: the flags\n";
// The thin-text flag is its own case below; these probe one flag each.
$F = static fn( ...$a ) => array_values( array_filter( sn_resume_pdf_text_flags( ...$a ), static fn( $f ) => 0 !== strpos( $f, 'only ' ) ) );
ok( array() === $F( "Founder\tand operator\nPage two\f", '/Title (Juan Lentino)', 2, 2 ), 'a clean text, two pages of two: no flags' );
ok( array( 'control characters in the text' ) === $F( "a\x00b", '', 1 ), 'a NUL in the text' );
ok( array( 'control characters in the text' ) === $F( "a\x1Fb", '', 1 ), 'a unit separator in the text' );
ok( array( 'ligature characters in the text' ) === $F( "work\u{FB02}ow", '', 1 ), 'an fl ligature' );
ok( array( 'a phone number' ) === $F( 'call (407) 555-0123 today', '', 1 ), 'a phone in the text' );
ok( array( 'a phone number' ) === $F( 'nothing here', 'Subject +1 407.555.0123', 1 ), 'a phone in the metadata' );
ok( array() === $F( 'Sep 2018 - Present, 2016 - 2021, 10,000 listeners, 0009-0006-8151-5920', '', 1 ), 'dates, counts and an ORCID are not phone numbers' );
ok( array( '3 pages, over 2' ) === $F( 'ok', '', 3, 2 ), 'three pages where two are allowed' );
ok( array( 'no text could be read' ) === sn_resume_pdf_text_flags( '   ', '', 1 ), 'an unreadable file never passes as clean' );
ok( array( 'only 13 characters could be read' ) === sn_resume_pdf_text_flags( 'a short read.', '', 1 ), 'a read too short for a résumé is flagged, not passed' );

echo "\nGroup: real Dompdf files\n";
require_once __DIR__ . '/../lib/pdf/vendor/autoload.php';
$o = new \Dompdf\Options();
$o->set( 'fontDir', $cache ); $o->set( 'fontCache', $cache );
$d = new \Dompdf\Dompdf( $o );
$d->loadHtml( '<p>' . str_repeat( 'A plain résumé line. ', 80 ) . '</p>', 'UTF-8' );
$d->addInfo( 'Subject', 'Call 407-555-0123' );
$d->render();
$meta_pdf = (string) $d->output();
ok( false !== strpos( $meta_pdf, '\\376\\377' ) || false !== strpos( $meta_pdf, "\xFE\xFF" ), 'Dompdf writes the Subject as a UTF-16 literal (the case the check must decode)' );
ok( in_array( 'a phone number', sn_resume_pdf_check( $meta_pdf, 1 )['flags'], true ), 'a phone in a real Dompdf file\'s metadata is flagged' );
$intl            = $doc;
$intl['pdf']['phone'] = '+54 9 11 5555-1234';
$ri              = sn_resume_pdf_render( $intl, 'Juan Lentino', $cache, true, 'https://juanlentino.com/' );
ok( in_array( 'a phone number', sn_resume_pdf_check( $ri['bytes'], $ri['pages'], null, '+54 9 11 5555-1234' )['flags'], true ), 'the stored phone, in any format, is found by its digits' );
ok( ! in_array( 'a phone number', sn_resume_pdf_check( $r['bytes'], $r['pages'], null, '+54 9 11 5555-1234' )['flags'], true ), 'and a PDF without it is not flagged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
