<?php
/**
 * Guard: the operations résumé PDF's address (inc/resume-pdf/operations.php).
 *
 * WordPress serves it at /resume/<file name> with X-Robots-Tag noindex,
 * nofollow, from bytes in an option; any other path, or no bytes yet, passes
 * through to the ordinary request. Each case runs in its own PHP process,
 * since serving ends the request.
 *
 * Run: php tests/resume-ops-pdf-route.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$run  = static function ( $uri, $bytes ) use ( $root ) {
	$boot = 'define("ABSPATH","/");'
		. 'function wp_kses($s,$a){return strip_tags((string)$s,"<strong><em><a>");} function sanitize_email($e){return (string)$e;}'
		. 'function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_unslash($v){return $v;} function home_url($p=""){return "https://example.test".$p;}'
		. 'function add_action(){} function status_header($c){echo "STATUS ".$c."\n";}'
		. '$GLOBALS["o"]=array("sn_resume_pdf_operations_bytes"=>' . var_export( null === $bytes ? '' : base64_encode( $bytes ), true ) . ');'
		. 'function get_option($k,$d=false){ if("sn_resume_doc"===$k){ return array("experience"=>array(array("org"=>"A","roles"=>array(array("title"=>"T","bullets"=>array("b"))))),"variants"=>array("operations"=>array("headline"=>"H","pdf_file"=>"Ops.pdf"))); } return $GLOBALS["o"][$k] ?? $d; }'
		. 'require ' . var_export( $root . '/inc/admin-post-actions/content.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-page.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-pdf/operations.php', true ) . ';'
		. '$_SERVER["REQUEST_URI"]=' . var_export( $uri, true ) . ';'
		. 'sn_resume_ops_pdf_maybe_serve(); echo "PASSED THROUGH";';
	return (string) shell_exec( 'php -r ' . escapeshellarg( $boot ) . ' 2>&1' );
};

$pdf = "%PDF-1.7\nfake";
$out = $run( '/resume/Ops.pdf?check=1', $pdf );
ok( 0 === strpos( $out, "STATUS 200\n" ) && false !== strpos( $out, $pdf ) && false === strpos( $out, 'PASSED THROUGH' ), 'its address serves the stored PDF and ends the request' );
ok( 'PASSED THROUGH' === trim( $run( '/resume/Other.pdf', $pdf ) ), 'any other path passes through' );
ok( 'PASSED THROUGH' === trim( $run( '/resume/Ops.pdf', null ) ), 'before the first generation the address is the ordinary 404' );
ok( 'PASSED THROUGH' === trim( $run( '/resume/Ops.pdf', 'not a pdf' ) ), 'stored bytes that are not a PDF are never served' );
$src = (string) file_get_contents( $root . '/inc/resume-pdf/operations.php' );
ok( 1 === preg_match( '/sn_resume_ops_pdf_build\(\);.*?sn_resume_sync_page\(\);\s*\}\s*return \$out;/s', $src ), 'every operations generation, failed or not, syncs /resume again so its Download follows the file' );
ok( 1 === preg_match( "/delete_option\( SN_RESUME_OPS_PDF_BYTES \);\s*delete_option\( SN_RESUME_OPS_PDF_OPTION \);/", $src ), 'a PDF withdrawn for a phone also drops its facts, so nothing links it' );
$fmt = (string) file_get_contents( $root . '/inc/resume-formats-route.php' );
ok( false !== strpos( $fmt, "sn_resume_ops_pdf_live()" ) && false !== strpos( $fmt, "null !== sn_resume_variant_config( sn_resume_doc_get_raw(), 'operations' )" ), 'the format list (llms.txt, the agents manifest) names the operations files only while the résumé and its PDF exist' );
ok( false !== strpos( $src, "header( 'X-Robots-Tag: noindex, nofollow' );" ) && false !== strpos( $src, "header( 'Content-Type: application/pdf' );" ), 'it is served as a PDF with X-Robots-Tag: noindex, nofollow' );
ok( false === strpos( $src, 'file_put_contents' ), 'the operations PDF is never written to a public file' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
