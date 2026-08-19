<?php
/**
 * Tests: a 403 reports what Google SAID, not what I guessed.
 *
 * Run: php tests/search-console-403.php
 *
 * THE REGRESSION (v11.19.0, caught on the first real credential): the 403
 * message asserted ONE cause — "the service account is almost certainly not a
 * user on the property" — and shipped it as the whole explanation. The account
 * HAD been added with Full permission and the 403 persisted, so the message
 * confidently sent the owner to a door that was already open, while discarding
 * Google's own error text, which names the real reason.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }

// Only the helper is under test; the file's other functions need WP HTTP.
$src = file_get_contents( __DIR__ . '/../inc/search-console-client.php' );
$start = strpos( $src, 'function snt_gsc_forbidden_error' );
$end   = strpos( $src, 'function snt_gsc_api_get' );
eval( substr( $src, $start, $end - $start ) );

echo "Group: the SERVICE_DISABLED case is named outright\n";
// What Google actually returns when the API is off in the Cloud project.
$disabled = array( 'error' => array(
	'code'    => 403,
	'message' => 'Google Search Console API has not been used in project 933132158749 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/searchconsole.googleapis.com/overview?project=933132158749 then retry.',
	'status'  => 'PERMISSION_DENIED',
	'details' => array( array( 'reason' => 'SERVICE_DISABLED' ) ),
) );
$e = snt_gsc_forbidden_error( $disabled );
ok( 'snt_gsc_api_disabled' === $e->get_error_code(), 'SERVICE_DISABLED gets its OWN error code, not the generic forbidden' );
ok( false !== strpos( $e->get_error_message(), 'not enabled' ), 'and says the API is not enabled' );
ok( false !== strpos( $e->get_error_message(), 'not a property-permission problem' ), 'and explicitly rules OUT the property permission — the wrong door the old message sent you to' );
ok( false !== strpos( $e->get_error_message(), 'console.developers.google.com' ), "and carries Google's activation link through" );

// Detection must not depend on the details[] array alone — the message form
// appears without it on some responses.
$msg_only = array( 'error' => array( 'code' => 403, 'message' => 'Google Search Console API has not been used in project 1 before or it is disabled.' ) );
ok( 'snt_gsc_api_disabled' === snt_gsc_forbidden_error( $msg_only )->get_error_code(), 'detected from the message text alone when details[] is absent' );

$legacy = array( 'error' => array( 'code' => 403, 'message' => 'x', 'errors' => array( array( 'reason' => 'SERVICE_DISABLED' ) ) ) );
ok( 'snt_gsc_api_disabled' === snt_gsc_forbidden_error( $legacy )->get_error_code(), 'and from the legacy errors[].reason shape' );

echo "\nGroup: any other 403 leads with Google's text, then hypotheses\n";
$other = array( 'error' => array( 'code' => 403, 'message' => "User does not have sufficient permission for site 'https://juanlentino.com/'." ) );
$e = snt_gsc_forbidden_error( $other );
ok( 'snt_gsc_forbidden' === $e->get_error_code(), 'a non-disabled 403 keeps the generic code' );
ok( false !== strpos( $e->get_error_message(), 'sufficient permission' ), "Google's own sentence is IN the message" );
ok( false !== strpos( $e->get_error_message(), 'Google said' ), 'and is attributed to Google, not asserted as mine' );
ok( false === strpos( $e->get_error_message(), 'almost certainly' ), 'the overconfident phrasing is GONE — three causes, none asserted' );
ok( false !== strpos( $e->get_error_message(), 'propagated' ), 'and propagation delay is offered, which the old message never mentioned' );

echo "\nGroup: a 403 with no body still says something useful\n";
$e = snt_gsc_forbidden_error( null );
ok( is_wp_error( $e ), 'a null body still yields a WP_Error' );
ok( false !== strpos( $e->get_error_message(), 'without explaining why' ), 'and admits Google gave no reason rather than inventing one' );
ok( false !== strpos( $e->get_error_message(), 'usual causes' ), 'while still listing what to check' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
