<?php
/**
 * Guard: the headers the résumé's agent copies are served with
 * (sn_resume_format_send(), inc/resume-formats-parts.php).
 *
 * The JSON Resume, /resume.md and the /resume/?output_format=markdown twin
 * answer X-Robots-Tag: noindex and name the page as canonical in a Link that
 * joins WordPress's own Link headers, never replaces them; the page itself
 * gets neither. Each case runs the real template_redirect handlers in its own
 * php-cgi process, since serving ends the request and the CLI drops every
 * header() call: the CGI binary prints the headers, so they are read, not
 * grepped from source.
 *
 * Run: php tests/resume-format-headers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$cgi  = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
$cgi  = '' !== $cgi ? $cgi : ( is_executable( dirname( PHP_BINARY ) . '/php-cgi' ) ? dirname( PHP_BINARY ) . '/php-cgi' : '' );
$boot = sys_get_temp_dir() . '/sn-resume-format-headers-' . getmypid() . '.php';
$run  = static function ( $uri, array $get, $is_page ) use ( $root, $cgi, $boot ) {
	file_put_contents(
		$boot,
		'<?php define("ABSPATH","/"); define("OBJECT","OBJECT");'
		. 'function add_action(){} function add_filter(){} function apply_filters($t,$v){return $v;} function get_option($k,$d=false){return $d;}'
		. 'function wp_kses($s,$a){return strip_tags((string)$s,"<strong><em><a>");} function sanitize_email($e){return (string)$e;} function sanitize_text_field($s){return (string)$s;}'
		. 'function sanitize_key($k){return preg_replace("/[^a-z0-9_\-]/","",strtolower((string)$k));} function sanitize_file_name($s){return (string)$s;}'
		. 'function wp_unslash($v){return $v;} function esc_url_raw($s){return (string)$s;} function wp_json_encode($d,$o=0){return json_encode($d,$o);}'
		. 'function home_url($p=""){return "https://example.test".$p;} function get_permalink($p=0){return "https://example.test/resume/";}'
		. 'function get_page_by_path($p,$o=OBJECT,$t="page"){return (object)array("ID"=>7,"post_status"=>"publish","post_password"=>"");}'
		. 'function get_bloginfo($k=""){return "Juan Lentino";} function get_post_modified_time($f="U",$g=false,$p=null){return "2026-11-01T19:36:23-05:00";}'
		. 'function is_page($s=""){return ' . var_export( $is_page, true ) . '===$s;} function get_queried_object_id(){return 7;} function status_header($c){http_response_code($c);}'
		. 'require ' . var_export( $root . '/inc/resume-page.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-es.php', true ) . ';'
		. 'require ' . var_export( $root . '/inc/resume-formats-route.php', true ) . ';'
		. 'header("Link: <https://example.test/wp-json/>; rel=\"https://api.w.org/\"", false);' // WordPress's own, sent earlier.
		. '$_SERVER["REQUEST_URI"]=' . var_export( $uri, true ) . '; $_GET=' . var_export( $get, true ) . ';'
		. 'sn_jsonresume_maybe_serve(); sn_resume_formats_maybe_serve(); echo "PASSED THROUGH";' // both at template_redirect 0, in this order.
	);
	$out = (string) shell_exec( escapeshellarg( $cgi ) . ' -d cgi.force_redirect=0 ' . escapeshellarg( $boot ) . ' 2>&1' );
	list( $head, $body ) = array_pad( explode( "\r\n\r\n", $out, 2 ), 2, '' );
	return array( 'head' => strtolower( $head ) . "\r\n", 'body' => $body ); // every header line ends in CRLF, the last one too.
};

if ( '' === $cgi ) {
	// Under CI a missing binary fails: a skipped header check there is a false green.
	$ci = (string) getenv( 'CI' );
	ok( '' === $ci || 'false' === $ci, 'php-cgi is installed, so the served headers can be read' );
	echo "SKIP: php-cgi is not installed; the served headers were not read\n";
} else {
	$canonical = 'link: <https://example.test/resume/>; rel="canonical"' . "\r\n";
	$wp_link   = 'link: <https://example.test/wp-json/>; rel="https://api.w.org/"' . "\r\n";
	$cases     = array(
		'the JSON Resume'      => array( $run( '/resume/json-resume.json', array(), '' ), 'application/json; charset=utf-8' ),
		'/resume.md'           => array( $run( '/resume.md', array(), '' ), 'text/markdown; charset=utf-8' ),
		'the ?output_format=markdown twin' => array( $run( '/resume/?output_format=markdown', array( 'output_format' => 'markdown' ), 'resume' ), 'text/markdown; charset=utf-8' ),
	);
	foreach ( $cases as $name => list( $out, $type ) ) {
		ok( false === strpos( $out['body'], 'PASSED THROUGH' ) && '' !== trim( $out['body'] ), "$name is served and ends the request" );
		ok( false !== strpos( $out['head'], "content-type: $type\r\n" ), "$name answers $type" );
		ok( false !== strpos( $out['head'], "x-robots-tag: noindex\r\n" ), "$name answers X-Robots-Tag: noindex" );
		ok( false !== strpos( $out['head'], $canonical ) && false !== strpos( $out['head'], $wp_link ), "$name names /resume/ as canonical in a Link beside WordPress's own" );
	}
	$json = json_decode( $cases['the JSON Resume'][0]['body'], true );
	ok( 'Juan Lentino' === ( $json['basics']['name'] ?? '' ) && 0 === strpos( $cases['/resume.md'][0]['body'], "---\n" ), 'the bodies are the JSON Resume and the Markdown' );
	$page = $run( '/resume/', array(), 'resume' );
	ok( 'PASSED THROUGH' === $page['body'] && false === strpos( $page['head'], 'x-robots-tag' ) && false === strpos( $page['head'], 'rel="canonical"' ), 'the page itself passes through, indexable, its canonical left to WordPress' );
	ok( false !== strpos( $page['head'], 'link: <https://example.test/resume.md>; rel="alternate"; type="text/markdown"' . "\r\n" ), 'and points to its Markdown' );
	unlink( $boot );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
