<?php
/**
 * Test helper: the résumé PDF press without WordPress, for suites that run
 * Generate for real (Dompdf, the check, the operations build).
 *
 * Options live in $GLOBALS['opts']; `refuse` names one update_option() that
 * fails, `quiet` one that stores but returns false (as for an unchanged
 * value). Actions run: add_action() registers, do_action() calls, so Generate
 * fires the operations build as it does on the site. Uploads go under
 * $GLOBALS['tmp'], removed by sn_test_pdf_cleanup().
 *
 * @package SignalNoiseTools
 */

$GLOBALS['tmp'] = sys_get_temp_dir() . '/sn-resume-press-' . getmypid();
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
function wp_date( $f, $ts = null ) { return gmdate( 'Y-m-d', $ts ?? time() ); }
function add_action( $h, $f ) { $GLOBALS['hooks'][ $h ][] = $f; }
function do_action( $h ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $f ) { $f(); } }
function sn_cf_purge_urls( $urls ) { $GLOBALS['purged'] = array_merge( $GLOBALS['purged'], $urls ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { if ( $k === $GLOBALS['refuse'] ) { return false; } $GLOBALS['opts'][ $k ] = $v; return $k !== $GLOBALS['quiet']; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }

$root = dirname( __DIR__, 2 );
require_once $root . '/inc/admin-post-actions/content.php';
require_once $root . '/inc/resume-page.php';
require_once $root . '/inc/resume-draft.php';
require_once $root . '/inc/resume-pdf/template.php';
require_once $root . '/inc/resume-pdf/generate.php';
require_once $root . '/inc/resume-pdf/text-check.php';
require_once $root . '/inc/resume-operations-setup.php';
require_once $root . '/inc/resume-pdf/operations.php';

/**
 * A fresh press: the stored document, any other options, and clean switches.
 *
 * @param array $doc  The stored résumé document.
 * @param array $opts Other options.
 */
function sn_test_pdf_reset( $doc, $opts = array() ) {
	$GLOBALS['opts']   = array( 'sn_resume_doc' => $doc ) + $opts;
	$GLOBALS['name']   = 'Juan Lentino';
	$GLOBALS['robots'] = 'noindex, nofollow';
	$GLOBALS['refuse'] = '';
	$GLOBALS['quiet']  = '';
	$GLOBALS['purged'] = array();
}

/** Remove everything the press wrote under $GLOBALS['tmp']. */
function sn_test_pdf_cleanup() {
	if ( ! is_dir( $GLOBALS['tmp'] ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['tmp'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $GLOBALS['tmp'] );
}
