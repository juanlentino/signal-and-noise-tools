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

$tmp = sys_get_temp_dir() . '/sn-resume-ops-build-' . getmypid();
class WP_Error { public $code; public $message; function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } function get_error_message() { return $this->message; } function get_error_code() { return $this->code; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_kses( $s, $a ) { return strip_tags( (string) $s, '<strong><em><a>' ); }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function apply_filters( $t, $v ) { return 'sn_resume_pdf_name' === $t ? $GLOBALS['name'] : $v; }
function get_bloginfo( $k ) { return 'Juan Lentino'; }
function wp_upload_dir( $a = null, $b = true ) { return array( 'basedir' => $GLOBALS['tmp'], 'baseurl' => 'https://example.test/up' ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function add_query_arg( $k, $v, $u ) { return $u . '?' . $k . '=' . $v; }
function wp_remote_head( $u, $a = array() ) { return array( 'code' => 200, 'hdr' => $GLOBALS['robots'] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['hdr']; }
function wp_date( $f, $ts ) { return gmdate( 'Y-m-d', $ts ); }
function add_action() {}
function sn_cf_purge_urls( $urls ) { $GLOBALS['purged'] = array_merge( $GLOBALS['purged'], $urls ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { if ( $k === $GLOBALS['refuse'] ) { return false; } $GLOBALS['opts'][ $k ] = $v; return $k !== $GLOBALS['quiet']; } // quiet: stored, but false, as for an unchanged value.
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-draft.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-pdf/generate.php';
require_once __DIR__ . '/../inc/resume-pdf/text-check.php';
require_once __DIR__ . '/../inc/resume-operations-setup.php';
require_once __DIR__ . '/../inc/resume-pdf/operations.php';

$set = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( sn_resume_seed_doc() ) )['doc'] );
$url = 'https://example.test/resume/JuanLentino_Resume_Operations.pdf';
$reset = static function ( $doc, $opts = array() ) {
	$GLOBALS['opts']   = array( 'sn_resume_doc' => $doc ) + $opts;
	$GLOBALS['name']   = 'Juan Lentino';
	$GLOBALS['robots'] = 'noindex, nofollow';
	$GLOBALS['refuse'] = '';
	$GLOBALS['quiet']  = '';
	$GLOBALS['purged'] = array();
};
$box = static fn() => implode( "\n", array_column( sn_resume_pdf_variant_lines(), 'text' ) );

echo "Group: a clean build\n";
$reset( $set );
$m = sn_resume_ops_pdf_build();
ok( is_array( $m ) && array() === $m['check']['flags'] && $url === $m['url'] && $m === $GLOBALS['opts']['sn_resume_pdf_operations'], 'it is stored with a clean check, at /resume/<file name>' );
ok( sn_resume_ops_pdf_live() && hash( 'sha256', base64_decode( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] ) ) === $m['sha256'], 'it is live, and the stored bytes are the file the facts describe' );
ok( array( $url ) === $GLOBALS['purged'], 'its address is purged' );
$good = $GLOBALS['opts'];

echo "\nGroup: a phone in it\n";
$ph = $set;
$ph['variants']['operations']['summary'] .= ' Call (407) 555-0123.';
$reset( $ph, $good );
$e = sn_resume_ops_pdf_build();
ok( is_wp_error( $e ) && 'sn_resume_pdf_phone' === $e->get_error_code(), 'a phone in the text is refused' );
ok( ! isset( $GLOBALS['opts']['sn_resume_pdf_operations'] ) && ! isset( $GLOBALS['opts']['sn_resume_pdf_operations_bytes'] ) && ! sn_resume_ops_pdf_live(), 'the earlier good file and its facts are gone: nothing links it' );
ok( in_array( $url, $GLOBALS['purged'], true ), 'and its address is purged, so the edge does not keep serving it' );
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

array_map( 'unlink', (array) glob( $tmp . '/resume/.font-cache/*' ) );
array_map( 'rmdir', array( $tmp . '/resume/.font-cache', $tmp . '/resume', $tmp ) );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
