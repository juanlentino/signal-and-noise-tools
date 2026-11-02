<?php
/**
 * Guard: the operations résumé PDF as Generate builds it (inc/resume-pdf/operations.php).
 *
 * Runs sn_resume_ops_pdf_build() and sn_resume_ops_pdf_generate() for real,
 * through Dompdf and the check, over an options array: a clean file is stored
 * and linked; a phone in it is withdrawn, the old file too, and its address
 * purged; any other flag keeps it published; a failed write keeps the old
 * file; removed settings leave nothing behind; and the PDF box says which.
 *
 * Run: php tests/resume-ops-pdf-build.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/lib/resume-pdf-harness.php';

$set = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( sn_resume_seed_doc() ) )['doc'] );
$url = 'https://example.test/resume/JuanLentino_Resume_Operations.pdf';
$reset = 'sn_test_pdf_reset';
$box = static fn() => implode( "\n", array_column( sn_resume_pdf_variant_lines(), 'text' ) );

echo "Group: a clean build\n";
$reset( $set );
$m = sn_resume_ops_pdf_build();
ok( is_array( $m ) && array() === $m['check']['flags'] && $url === $m['url'] && $m === $GLOBALS['opts']['sn_resume_pdf_operations'], 'it is stored with a clean check, at /resume/<file name>' );
ok( sn_resume_ops_pdf_live() && hash( 'sha256', base64_decode( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] ) ) === $m['sha256'], 'it is live, and the stored bytes are the file the facts describe' );
ok( array( $url ) === $GLOBALS['purged'], 'its address is purged' );
$bytes = base64_decode( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] );
$text  = sn_resume_pdf_text( $bytes );
ok( false !== strpos( $text, 'example.test/resume/?lane=operations' ) && false !== strpos( $bytes, '/URI (https://example.test/resume/?lane=operations)' ), 'its contact line prints and links its own lane: /resume/?lane=operations' );
ok( 0 === preg_match( '~example\.test/resume(?!/\?lane=operations)~', $text ) && false === strpos( $bytes, '/URI (https://example.test/resume/)' ), 'and not the music lane\'s address' );
$pdftotext = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
if ( '' !== $pdftotext && false !== file_put_contents( $GLOBALS['tmp'] . '/ops.pdf', $bytes ) ) {
	$ats = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $GLOBALS['tmp'] . '/ops.pdf' ) . ' - 2>/dev/null' );
	ok( false !== strpos( $ats, 'example.test/resume/?lane=operations' ) && 0 === preg_match( '~example\.test/resume(?!/\?lane=operations)~', $ats ), 'pdftotext (an ATS view) reads the same address' );
} else {
	echo "INFO: pdftotext not installed; the built-in reader's read above stands alone.\n";
}
ok( 'Juan Lentino · Résumé · Operations & AI Strategy' === sn_test_pdf_title( $bytes ), 'its Info Title names the lane: "' . sn_test_pdf_title( $bytes ) . '"' );
ok( $url . '?v=' . substr( $m['sha256'], 0, 8 ) === sn_resume_ops_pdf_link(), 'its link carries ?v=<first 8 of its SHA-256>, as the music Download does' );
$good = $GLOBALS['opts'];
$good_link = sn_resume_ops_pdf_link();

echo "\nGroup: a phone in it\n";
$ph = $set;
$ph['variants']['operations']['summary'] .= ' Call (407) 555-0123.';
$reset( $ph, $good );
$e = sn_resume_ops_pdf_build();
ok( is_wp_error( $e ) && 'sn_resume_pdf_phone' === $e->get_error_code(), 'a phone in the text is refused' );
ok( ! isset( $GLOBALS['opts']['sn_resume_pdf_operations'] ) && ! isset( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] ) && ! sn_resume_ops_pdf_live(), 'the earlier good file and its facts are gone: nothing links it' );
ok( in_array( $url, $GLOBALS['purged'], true ) && in_array( $good_link, $GLOBALS['purged'], true ), 'and its address is purged, the ?v= one the page linked too, so the edge does not keep serving it' );
ok( '' === sn_resume_ops_pdf_link(), 'withdrawn, it has no link' );
$reset( $set, $good );
$GLOBALS['name'] = 'Juan Lentino 407-555-0123';
ok( 'sn_resume_pdf_phone' === sn_resume_ops_pdf_build()->get_error_code(), 'a phone in the name (the Title metadata and the header) is refused too' );
$reset( $ph, $good );
sn_resume_ops_pdf_generate();
ok( false !== strpos( $box(), 'the last Generate failed' ) && false !== strpos( $box(), 'Nothing is served.' ) && false === strpos( $box(), 'Generate builds it' ), 'the box says it failed and nothing is served, and not that it is merely set up' );

echo "\nGroup: other flags keep it published\n";
$long = $set;
$long['variants']['operations']['summary'] = str_repeat( 'A long operations summary sentence that fills the page. ', 260 );
$reset( $long );
$m3 = sn_resume_ops_pdf_build();
ok( is_array( $m3 ) && in_array( sprintf( '%d pages, over 2', $m3['pages'] ), $m3['check']['flags'], true ) && $m3['pages'] > 2 && sn_resume_ops_pdf_live(), 'over two pages is flagged in the stored facts, and the file stays live' );
ok( false !== strpos( $box(), 'It stays published: only a phone number withdraws it.' ), 'the box says a flagged file stays published' );
$reset( $set );
$GLOBALS['robots'] = '';
ok( in_array( 'no X-Robots-Tag: noindex header', sn_resume_ops_pdf_build()['check']['flags'], true ), 'an address that answers without noindex is flagged' );

echo "\nGroup: a renamed file and a Website field\n";
$ren = $set;
$ren['variants']['operations']['pdf_file'] = 'Renamed_Operations.pdf';
$ren['variants']['operations']['summary'] .= ' Call (407) 555-0123.'; // the build after the rename fails.
$reset( $ren, $good );
$GLOBALS['opts']['sn_resume_pdf_operations_bytes'] = $good['sn_resume_pdf_operations_bytes'];
$GLOBALS['opts']['sn_resume_pdf_operations']       = $good['sn_resume_pdf_operations'] + array( 'error' => 'an earlier failure' );
ok( 'https://example.test/resume/Renamed_Operations.pdf?v=' . substr( $m['sha256'], 0, 8 ) === sn_resume_ops_pdf_link(), 'renamed, its link names the address the route serves now, not the stored one' );
$web = $set;
$web['pdf']['website'] = 'https://example.org/work';
$reset( $web );
$wb = base64_decode( (string) ( sn_resume_ops_pdf_build() ? $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] : '' ) );
ok( false !== strpos( sn_resume_pdf_text( $wb ), 'example.test/resume/?lane=operations' ) && false === strpos( $wb, 'example.org' ), 'a filled Website field does not replace the operations PDF\'s own address' );

echo "\nGroup: a write that does not land\n";
$changed = $set;
$changed['variants']['operations']['summary'] .= ' One more sentence, so the bytes differ.';
$reset( $changed, $good );
$GLOBALS['refuse'] = 'sn_resume_pdf_operations_bytes';
$w = sn_resume_ops_pdf_build();
ok( is_wp_error( $w ) && $good['sn_resume_pdf_operations'] === $GLOBALS['opts']['sn_resume_pdf_operations'], 'it is an error, and the facts still describe the file that is served' );
$reset( $set, $good );
$GLOBALS['quiet'] = 'sn_resume_pdf_operations_bytes';
ok( is_array( sn_resume_ops_pdf_build() ), 'a write update_option calls false but that landed (an unchanged value) is no failure: the bytes are read back' );
$reset( $set, array( 'sn_resume_pdf_operations' => $good['sn_resume_pdf_operations'], 'sn_resume_pdf_operations_bytes' => base64_encode( 'not a pdf' ) ) );
ok( ! sn_resume_ops_pdf_live(), 'facts without a PDF behind them are not live' );

echo "\nGroup: no operations résumé\n";
$none = $set;
unset( $none['variants'] );
$reset( $none, $good + array( 'sn_resume_draft' => $set ) );
ok( null === sn_resume_ops_pdf_build() && ! isset( $GLOBALS['opts']['sn_resume_pdf_operations'] ) && ! isset( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] ), 'settings removed: the old file and its facts are dropped' );
ok( false !== strpos( $box(), 'set up in the draft' ), 'the box says a set-up draft is waiting, not "not set up yet"' );
$empty = $set;
foreach ( array( 'experience', 'publications' ) as $k ) {
	foreach ( $empty[ $k ] as $i => $row ) {
		$empty[ $k ][ $i ]['variant'] = 'music';
	}
}
$reset( $empty, $good );
$v = sn_resume_ops_pdf_build();
ok( is_wp_error( $v ) && $good['sn_resume_pdf_operations_bytes'] === $GLOBALS['opts']['sn_resume_pdf_operations_bytes'], 'an operations résumé with no experience and no publications is not built; the last file stands' );

sn_test_pdf_cleanup();
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
