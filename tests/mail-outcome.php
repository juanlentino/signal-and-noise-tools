<?php
/**
 * Standalone test: what the mail server said, from core's wp_mail hooks (2026-10-24).
 *
 * wp_mail_failed keeps the last refusal (time, the WP_Error code, its message
 * trimmed and with any address taken out); wp_mail_succeeded counts. Never the
 * recipient, the subject or the body, which core puts in the error data.
 * Acceptance by the mail server is not delivery to an inbox.
 *
 * Run: php tests/mail-outcome.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

$GLOBALS['__options'] = array();
$GLOBALS['__autoload'] = array();
$GLOBALS['__hooks']   = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $hook ] = array( $cb, $args ); }
class WP_Error {
	private $c; private $m; private $d;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}

$module = dirname( __DIR__ ) . '/inc/mail-outcome.php';
if ( ! is_readable( $module ) ) {
	ok( false, 'inc/mail-outcome.php exists' );
	echo "\nResult: $pass passed, $fail failed.\n";
	exit( 1 );
}
require $module;

function fire( $hook, ...$args ) { $h = $GLOBALS['__hooks'][ $hook ] ?? null; return $h ? call_user_func_array( $h[0], array_slice( $args, 0, $h[1] ) ) : null; }

echo "\nGroup: core's two wp_mail hooks\n";
ok( 1 === ( $GLOBALS['__hooks']['wp_mail_failed'][1] ?? null ), 'wp_mail_failed is hooked and takes the WP_Error only' );
ok( 0 === ( $GLOBALS['__hooks']['wp_mail_succeeded'][1] ?? null ), 'wp_mail_succeeded is hooked with no arguments, so the mail itself is never passed in' );

echo "\nGroup: a refusal\n";
$data = array( 'to' => array( 'reader@example.org' ), 'subject' => 'Your secret subject', 'message' => 'The private body', 'headers' => array(), 'attachments' => array(), 'phpmailer_exception_code' => 2 );
fire( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: The following recipients failed: reader@example.org: <reader@example.org>: Recipient address rejected', $data ) );
$o = snt_mail_outcome_get();
$f = $o['last_failure'] ?? array();
ok( 'wp_mail_failed' === ( $f['code'] ?? '' ) && abs( time() - (int) ( $f['at'] ?? 0 ) ) < 5, 'the time and the error code are kept' );
ok( 0 === strpos( (string) ( $f['message'] ?? '' ), 'SMTP Error: The following recipients failed:' ), 'the message is kept: ' . ( $f['message'] ?? '' ) );
$all = json_encode( $GLOBALS['__options'] );
foreach ( array( '@', 'reader', 'Your secret subject', 'The private body' ) as $never ) {
	ok( false === strpos( $all, $never ), "never stored: $never" );
}
fire( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', '<b>' . str_repeat( 'x', 500 ) . '</b>' ) );
$m = (string) snt_mail_outcome_get()['last_failure']['message'];
ok( strlen( $m ) <= 200 && false === strpos( $m, '<' ), 'a long message is trimmed and stripped of tags' );
ok( false === ( $GLOBALS['__autoload'][ SN_MAIL_OUTCOME_OPTION ] ?? null ), 'the option is not autoloaded' );

echo "\nGroup: acceptances are counted\n";
fire( 'wp_mail_succeeded', array( 'to' => array( 'reader@example.org' ) ) );
fire( 'wp_mail_succeeded', array( 'to' => array( 'reader@example.org' ) ) );
$o = snt_mail_outcome_get();
ok( 2 === $o['accepted'] && $o['since'] > 0, 'two acceptances counted, with the time counting began' );
ok( 'wp_mail_failed' === $o['last_failure']['code'], 'an acceptance leaves the last refusal in place' );
ok( false === strpos( json_encode( $GLOBALS['__options'] ), '@' ), 'still no address stored' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
