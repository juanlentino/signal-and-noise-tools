<?php
/**
 * Tests: the four postless provenance routes answer without core's 404 first.
 *
 * /.well-known/did.json, /.well-known/provenance-keys.json,
 * /.well-known/webfinger and /verify are served at template_redirect priority
 * 0. WP::main() runs handle_404() before that, so core had already sent a 404
 * status and its no-cache headers: did.json left as no-store, and the two
 * routes with their own Cache-Control still carried core's 1984 Expires.
 *
 * Each case runs the REAL registered hooks in its own php-cgi process, in the
 * order WP::main() runs them (pre_handle_404, core's 404 when nothing claims
 * the path, then template_redirect): serving ends the request and the CLI
 * drops every header() call, so the headers are read, not grepped from source.
 *
 * Run: php tests/provenance-route-headers.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$cgi  = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
$cgi  = '' !== $cgi ? $cgi : ( is_executable( dirname( PHP_BINARY ) . '/php-cgi' ) ? dirname( PHP_BINARY ) . '/php-cgi' : '' );
$boot = sys_get_temp_dir() . '/sn-provenance-route-headers-' . getmypid() . '.php';
$run  = static function ( $uri, $with_key = true ) use ( $root, $cgi, $boot ) {
	file_put_contents(
		$boot,
		'<?php define("ABSPATH","/"); define("SNT_PATH",' . var_export( $root . '/', true ) . '); define("SNT_VERSION","test");'
		. '$GLOBALS["h"]=array();'
		. 'function add_action($t,$cb,$p=10){$GLOBALS["h"][$t][$p][]=$cb;} function add_filter($t,$cb,$p=10){$GLOBALS["h"][$t][$p][]=$cb;} function add_shortcode(){}'
		. 'function apply_filters($t,$v){return $v;} function get_option($k,$d=false){return $d;}'
		. 'function home_url($p=""){return "https://example.test".$p;} function rest_url($p=""){return "https://example.test/wp-json/".ltrim((string)$p,"/");}'
		. 'function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function untrailingslashit($s){return rtrim((string)$s,"/");}'
		. 'function plugins_url($p="",$f=""){return "https://example.test/wp-content/plugins/snt/".ltrim((string)$p,"/");}'
		. 'function esc_html($t){return htmlspecialchars((string)$t,ENT_QUOTES);} function esc_attr($t){return htmlspecialchars((string)$t,ENT_QUOTES);} function esc_url($t){return htmlspecialchars((string)$t,ENT_QUOTES);}'
		. 'function wp_json_encode($d,$f=0,$depth=512){return json_encode($d,$f,$depth);} function sanitize_text_field($s){return trim((string)$s);} function wp_unslash($v){return $v;}'
		. 'function status_header($c,$d=""){http_response_code($c);}'
		// Core's own nocache_headers(), as wp-includes/functions.php sends them.
		. 'function nocache_headers(){header_remove("Last-Modified");header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");header("Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private");}'
		. ( $with_key ? 'define("SN_PROV_PUBKEY_B64",' . var_export( base64_encode( str_repeat( "\x01", 32 ) ), true ) . ');' : '' )
		. 'foreach(array("provenance-core","provenance-webhook","provenance-render","provenance-did","provenance-webfinger","provenance-verify") as $f){require SNT_PATH."inc/".$f.".php";}'
		. '$_SERVER["REQUEST_URI"]=' . var_export( $uri, true ) . ';'
		. 'function run_hook($t,$v=null){$all=$GLOBALS["h"][$t]??array();ksort($all);foreach($all as $cbs){foreach($cbs as $cb){$v=$cb($v);}}return $v;}'
		// WP::handle_404() for a path with no post: the filter, or the 404 and its no-cache headers.
		. 'if(false===run_hook("pre_handle_404",false)){status_header(404);nocache_headers();}'
		. 'run_hook("template_redirect"); echo "PASSED THROUGH";'
	);
	$out = (string) shell_exec( escapeshellarg( $cgi ) . ' -d cgi.force_redirect=0 ' . escapeshellarg( $boot ) . ' 2>&1' );
	list( $head, $body ) = array_pad( explode( "\r\n\r\n", $out, 2 ), 2, '' );
	return array( 'head' => strtolower( $head ) . "\r\n", 'body' => $body ); // every header line ends in CRLF, the last one too.
};

echo "provenance-route-headers\n\n";
if ( '' === $cgi ) {
	// Under CI a missing binary fails: a skipped header check there is a false green.
	$ci = (string) getenv( 'CI' );
	ok( '' === $ci || 'false' === $ci, 'php-cgi is installed, so the served headers can be read' );
	echo "SKIP: php-cgi is not installed; the served headers were not read\n";
} else {
	$nocache = "cache-control: no-cache, must-revalidate, max-age=0, no-store, private\r\n";
	$served  = array(
		'/.well-known/did.json'             => 'application/did+json; charset=utf-8',
		'/.well-known/provenance-keys.json' => 'application/json; charset=utf-8',
		'/.well-known/webfinger?resource=acct:juan@example.test' => 'application/jrd+json; charset=utf-8',
		'/verify?note=abc'                  => 'text/html; charset=utf-8',
	);
	foreach ( $served as $uri => $type ) {
		$out = $run( $uri );
		ok( false === strpos( $out['body'], 'PASSED THROUGH' ) && '' !== trim( $out['body'] ), "$uri is served and ends the request" );
		ok( false === strpos( $out['head'], "status:" ), "$uri answers 200" );
		ok( false !== strpos( $out['head'], "content-type: $type\r\n" ), "$uri still answers $type" );
		ok( false !== strpos( $out['head'], "cache-control: public, max-age=300\r\n" ), "$uri says how long it may be kept: public, max-age=300" );
		ok( false === strpos( $out['head'], 'expires:' ), "$uri carries no 1984 Expires from a 404 core never should have sent" );
	}
	$did = json_decode( $run( '/.well-known/did.json' )['body'], true );
	ok( is_array( $did ) && 'did:web:example.test' === ( $did['id'] ?? '' ), 'the DID document body is the same JSON' );

	echo "\nThe routes' own errors stay out of every cache\n";
	$errors = array(
		'did.json without a key'             => array( $run( '/.well-known/did.json', false ), 404 ),
		'the key mirror without a key'       => array( $run( '/.well-known/provenance-keys.json', false ), 404 ),
		'webfinger without a resource'       => array( $run( '/.well-known/webfinger' ), 400 ),
		'webfinger for an unknown resource'  => array( $run( '/.well-known/webfinger?resource=acct:nobody@elsewhere.test' ), 404 ),
	);
	foreach ( $errors as $name => list( $out, $code ) ) {
		ok( false !== strpos( $out['head'], "status: $code" ) && false !== strpos( $out['head'], $nocache ), "$name: a $code that still says no-store" );
	}

	echo "\nEvery other path is still core's to answer\n";
	$other = $run( '/no-such-page/' );
	ok( false !== strpos( $other['head'], 'status: 404' ) && false !== strpos( $other['head'], $nocache ) && false !== strpos( $other['body'], 'PASSED THROUGH' ), 'an unrelated postless path still gets core\'s 404 and no-cache headers' );
	$near = $run( '/.well-known/did.json.bak' );
	ok( false !== strpos( $near['head'], 'status: 404' ), 'a path that only starts like a route is not claimed' );
}
if ( file_exists( $boot ) ) {
	unlink( $boot );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
