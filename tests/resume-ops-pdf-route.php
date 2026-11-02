<?php
/**
 * Guard: the operations résumé PDF's address (inc/resume-pdf/operations.php).
 *
 * WordPress serves it at /resume/<file name> with X-Robots-Tag noindex,
 * nofollow, from bytes in an option; any other path, or no bytes yet, passes
 * through to the ordinary request. Each case runs in its own php-cgi process,
 * since serving ends the request and the CLI drops every header() call: the
 * CGI binary prints the headers, so they are read, not grepped from source.
 *
 * Run: php tests/resume-ops-pdf-route.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$cgi  = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
$cgi  = '' !== $cgi ? $cgi : ( is_executable( dirname( PHP_BINARY ) . '/php-cgi' ) ? dirname( PHP_BINARY ) . '/php-cgi' : '' );
$boot = sys_get_temp_dir() . '/sn-resume-ops-route-' . getmypid() . '.php';
$run  = static function ( $uri, $bytes ) use ( $root, $cgi, $boot ) {
	file_put_contents(
		$boot,
		'<?php define("ABSPATH","/");'
		. 'function wp_kses($s,$a){return strip_tags((string)$s,"<strong><em><a>");} function sanitize_email($e){return (string)$e;}'
		. 'function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_unslash($v){return $v;} function home_url($p=""){return "https://example.test".$p;}'
		. 'function add_action(){} function status_header($c){http_response_code($c);}'
		. '$GLOBALS["o"]=array("sn_resume_pdf_operations_bytes"=>' . var_export( null === $bytes ? '' : base64_encode( $bytes ), true ) . ');'
		. 'function get_option($k,$d=false){ if("sn_resume_doc"===$k){ return array("experience"=>array(array("org"=>"A","roles"=>array(array("title"=>"T","bullets"=>array("b"))))),"variants"=>array("operations"=>array("headline"=>"H","pdf_file"=>"Ops.pdf"))); } return $GLOBALS["o"][$k] ?? $d; }'
		. 'require ' . var_export( $root . '/inc/admin-post-actions/content.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-page.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-pdf/operations.php', true ) . ';'
		. '$_SERVER["REQUEST_URI"]=' . var_export( $uri, true ) . ';'
		. 'sn_resume_ops_pdf_maybe_serve(); echo "PASSED THROUGH";'
	);
	$out = (string) shell_exec( escapeshellarg( $cgi ) . ' -d cgi.force_redirect=0 ' . escapeshellarg( $boot ) . ' 2>&1' );
	list( $head, $body ) = array_pad( explode( "\r\n\r\n", $out, 2 ), 2, '' );
	return array( 'head' => strtolower( $head ), 'body' => $body );
};

if ( '' === $cgi ) {
	// Under CI a missing binary fails: a skipped header check there is a false green.
	$ci = (string) getenv( 'CI' );
	ok( '' === $ci || 'false' === $ci, 'php-cgi is installed, so the served headers can be read' );
	echo "SKIP: php-cgi is not installed; the served headers were not read\n";
} else {
	$pdf = "%PDF-1.7\nfake";
	$out = $run( '/resume/Ops.pdf?check=1', $pdf );
	ok( $pdf === $out['body'], 'its address serves the stored PDF and ends the request' );
	ok( false !== strpos( $out['head'], "content-type: application/pdf\r\n" ) && false !== strpos( $out['head'], 'content-disposition: inline; filename="ops.pdf"' ), 'as a PDF, shown inline under its file name' );
	ok( false !== strpos( $out['head'], "x-robots-tag: noindex, nofollow\r\n" ) && false !== strpos( $out['head'], "x-content-type-options: nosniff\r\n" ), 'with X-Robots-Tag: noindex, nofollow and nosniff' );
	ok( false !== strpos( $out['head'], 'cache-control: public, max-age=300' ) && false !== strpos( $out['head'], 'content-length: ' . strlen( $pdf ) ), 'cached five minutes (Generate purges it), with its length' );
	$through = $run( '/resume/Other.pdf', $pdf );
	ok( 'PASSED THROUGH' === $through['body'] && false === strpos( $through['head'], 'x-robots-tag' ) && false === strpos( $through['head'], 'application/pdf' ), 'any other path passes through, without the PDF headers' );
	ok( 'PASSED THROUGH' === $run( '/resume/Ops.pdf', null )['body'], 'before the first generation the address is the ordinary 404' );
	ok( 'PASSED THROUGH' === $run( '/resume/Ops.pdf', 'not a pdf' )['body'], 'stored bytes that are not a PDF are never served' );
	unlink( $boot );
}

$src = (string) file_get_contents( $root . '/inc/resume-pdf/operations.php' );
ok( 1 === preg_match( '/sn_resume_ops_pdf_build\(\);.*?sn_resume_sync_page\(\);\s*\}\s*return \$out;/s', $src ), 'every operations generation, failed or not, syncs /resume again so its Download follows the file' );
$fmt = (string) file_get_contents( $root . '/inc/resume-formats-route.php' );
ok( false !== strpos( $fmt, "sn_resume_ops_pdf_live()" ) && false !== strpos( $fmt, "null !== sn_resume_variant_config( sn_resume_doc_get_raw(), 'operations' )" ), 'the format list (llms.txt, the agents manifest) names the operations files only while the résumé and its PDF exist' );
ok( false === strpos( $src, 'file_put_contents' ), 'the operations PDF is never written to a public file' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
