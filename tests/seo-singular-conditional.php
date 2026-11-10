<?php
/**
 * Tests: the singular Last-Modified / 304 handler leaves core's no-cache views alone.
 *
 * WP::send_headers() merges wp_get_nocache_headers() for a logged-in visitor and
 * for a password-protected singular, which removes Last-Modified. The handler
 * ran afterwards at template_redirect and set it again, and could answer 304,
 * on exactly those views. An anonymous view of a public note must not change.
 *
 * The handler exits on a 304, so every case runs in a subprocess. With php-cgi
 * on the PATH the real response headers are read too; the CLI has none to read.
 *
 * Run: php tests/seo-singular-conditional.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

const SSC_MODIFIED = 1700000000; // Tue, 14 Nov 2023 22:13:20 GMT
$driver            = tempnam( sys_get_temp_dir(), 'ssc' );
file_put_contents(
	$driver,
	'<?php define( "ABSPATH", "/" );'
	. 'function add_action( $hook, $cb, $prio = 10 ) { if ( "template_redirect" === $hook ) { $GLOBALS["__cb"] = $cb; } }'
	. 'function add_filter() {}'
	. 'function is_singular() { return true; }'
	. 'function is_user_logged_in() { return "1" === getenv( "SSC_LOGGED_IN" ); }'
	. 'function get_queried_object() { return (object) array( "ID" => 7, "post_content" => "<p>A note.</p>", "post_password" => (string) getenv( "SSC_PASSWORD" ) ); }'
	. 'function get_post_modified_time( $f, $gmt, $post ) { return ' . SSC_MODIFIED . '; }'
	. 'function get_post_meta( $id, $key, $single = false ) { return ""; }'
	. 'function get_option( $k, $d = false ) { return $d; }'
	. 'function wp_unslash( $v ) { return $v; }'
	. 'function has_shortcode( $content, $tag ) { return false; }'
	. 'function status_header( $code, $description = "" ) { http_response_code( $code ); echo "STATUS $code\n"; }'
	. 'require ' . var_export( realpath( __DIR__ . '/../inc/seo-validators.php' ), true ) . ';'
	. 'require ' . var_export( realpath( __DIR__ . '/../inc/seo-delivery-parts.php' ), true ) . ';'
	. '( $GLOBALS["__cb"] )(); echo "REACHED\n";'
);

$cgi = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
$cgi = '' !== $cgi ? $cgi : ( is_executable( dirname( PHP_BINARY ) . '/php-cgi' ) ? dirname( PHP_BINARY ) . '/php-cgi' : '' );
$bin = '' !== $cgi ? $cgi : PHP_BINARY;

/** Run one request; returns headers and body as one string. */
function ssc_run( $bin, $driver, array $env ) {
	$prefix = '';
	foreach ( $env as $k => $v ) {
		$prefix .= $k . '=' . escapeshellarg( $v ) . ' ';
	}
	return (string) shell_exec( $prefix . escapeshellarg( $bin ) . ' ' . escapeshellarg( $driver ) . ' 2>&1' );
}

$stamp = gmdate( 'D, d M Y H:i:s', SSC_MODIFIED ) . ' GMT';
$lm    = 'Last-Modified: ' . $stamp;

echo "seo-singular-conditional (" . ( '' !== $cgi ? 'php-cgi: real headers read' : 'CLI only: header lines not readable, install php-cgi to read them' ) . ")\n\n";

echo "Group 1: an anonymous view of a public note, unchanged\n";
$plain = ssc_run( $bin, $driver, array() );
ok( false !== strpos( $plain, 'REACHED' ) && false === strpos( $plain, 'STATUS' ), 'no If-Modified-Since: the page renders' );
$hit = ssc_run( $bin, $driver, array( 'HTTP_IF_MODIFIED_SINCE' => $stamp ) );
ok( false !== strpos( $hit, 'STATUS 304' ) && false === strpos( $hit, 'REACHED' ), 'a current validator: 304 through status_header(), then exit' );
$old = ssc_run( $bin, $driver, array( 'HTTP_IF_MODIFIED_SINCE' => gmdate( 'D, d M Y H:i:s', SSC_MODIFIED - 60 ) . ' GMT' ) );
ok( false !== strpos( $old, 'REACHED' ) && false === strpos( $old, 'STATUS' ), 'a validator older than the content: the page renders' );

echo "\nGroup 2: the views core marks no-cache\n";
$in = ssc_run( $bin, $driver, array( 'HTTP_IF_MODIFIED_SINCE' => $stamp, 'SSC_LOGGED_IN' => '1' ) );
ok( false !== strpos( $in, 'REACHED' ) && false === strpos( $in, 'STATUS' ), 'logged in: never a 304, even with a current validator' );
$pw = ssc_run( $bin, $driver, array( 'HTTP_IF_MODIFIED_SINCE' => $stamp, 'SSC_PASSWORD' => 'secret' ) );
ok( false !== strpos( $pw, 'REACHED' ) && false === strpos( $pw, 'STATUS' ), 'password-protected: never a 304, even with a current validator' );

if ( '' !== $cgi ) {
	echo "\nGroup 3: the header itself (php-cgi)\n";
	ok( false !== strpos( $plain, $lm ) && false !== strpos( $hit, $lm ), 'anonymous: the same Last-Modified, on the page and on the 304' );
	ok( false !== strpos( $hit, 'Status: 304' ), 'anonymous: the response status is 304' );
	ok( false === stripos( $in, 'Last-Modified' ), 'logged in: Last-Modified is not sent again after core removed it' );
	ok( false === stripos( $pw, 'Last-Modified' ), 'password-protected: Last-Modified is not sent again after core removed it' );
}
 else {
	// Under CI a missing binary fails: a skipped header check there is a false green.
	$ci = (string) getenv( 'CI' );
	ok( '' === $ci || 'false' === $ci, 'php-cgi is installed, so the served headers can be read' );
}

unlink( $driver );
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
