<?php
/**
 * Tests: the bridge route does not EXIST unless both gates are open.
 *
 * The strongest property in this increment is that "switch off" and "secret
 * absent" are the same thing from outside: a route that was never registered.
 * An unregistered route cannot be reached by a handler bug, a filter ordering
 * mistake, or a future refactor — the code path does not exist.
 *
 * THE ASSERTION THAT MATTERS MOST asserts ABSENCE FROM THE ROUTE TABLE, not a
 * 404 status. A handler that returned 404 would satisfy a status assertion while
 * leaving the path reachable, which is the bug this design exists to prevent.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }
function remove_filter( $t, $c, $p = 10 ) { $GLOBALS['__removed'][] = $t; return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $t ][] = $c; return true; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

$GLOBALS['__caps'] = array();
function current_user_can( $c ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// Capture route registrations instead of standing up the REST server.
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
require __DIR__ . '/../inc/mcp/mcp-bridge-route.php';

echo "Group: the secret reader treats absent and empty alike\n";
ok( '' === sn_bridge_secret(), 'undefined constant -> empty string' );

echo "Group: BOTH gates must be open, and neither alone is enough\n";
// Switch off, no secret.
$GLOBALS['__options'] = array();
ok( false === sn_bridge_should_register(), 'switch off + no secret -> do not register' );

// Switch on, still no secret.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
ok( false === sn_bridge_should_register(), 'THE ONE THAT MATTERS: switch ON but secret ABSENT -> do not register' );

echo "Group: the rest_api_init callback registers nothing while a gate is shut\n";
$GLOBALS['__routes'] = array();
sn_bridge_register_routes();
ok( array() === $GLOBALS['__routes'], 'no route table entry when a gate is shut' );

// The empty-constant path — SN_BRIDGE_TOKEN defined as '' — is NOT asserted
// separately, and deliberately so. define() cannot be undone and the constant can
// only be defined once per process, so reaching it would mean either a second
// fixture file or contorting this one. It is covered by construction instead:
// sn_bridge_secret() decides absence and emptiness in ONE expression,
// `defined( ... ) && '' !== (string) SN_BRIDGE_TOKEN`, whose false branch is the
// constant-absent assertion at the top of this file. Splitting that expression in
// a future refactor is what would break the coverage — not this omission.

// EVERYTHING BELOW SEES THE CONSTANT. define() is permanent, so no assertion
// after this line can exercise the secret-absent half; that is why every
// secret-absent assertion above is placed first and must stay there.
define( 'SN_BRIDGE_TOKEN', 'topsecret' );

echo "Group: the gate OPENS when both are satisfied — the direction nothing else proves\n";
// Without this assertion the entire suite is one-directional: every other
// expectation here is false-or-absent, so `return false;` in
// sn_bridge_should_register() would be a mutant no test could detect, and a
// permanently-shut gate would be indistinguishable from a working one.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
ok( true === sn_bridge_should_register(), 'THE ONE THAT PROVES IT OPENS: switch ON + secret PRESENT -> register' );

$GLOBALS['__routes'] = array();
sn_bridge_register_routes();
ok( isset( $GLOBALS['__routes']['signal-noise/v1/bridge'] ), 'and the route is actually in the route table' );

// Pin the registration ARGUMENTS. Nothing else in this increment asserts what is
// registered — only whether. The permission_callback is deliberately open:
// authentication happens in the handler, in ONE ordered place, so a request is
// never partially authenticated while already inside the abilities layer. Do not
// "harden" it to a capability check — that would split verification across two
// layers, which is the thing this design refuses.
// Read defensively so that a mutation which stops registration reddens these
// pins cleanly instead of burying them under undefined-key warnings. Under
// correct code the key always exists, so this costs no strictness.
$args = isset( $GLOBALS['__routes']['signal-noise/v1/bridge'] ) ? $GLOBALS['__routes']['signal-noise/v1/bridge'] : array();
ok( 'POST' === ( $args['methods'] ?? null ), 'the route is POST only' );
ok( 'sn_bridge_handle_request' === ( $args['callback'] ?? null ), 'the callback is the bridge handler' );
ok( '__return_true' === ( $args['permission_callback'] ?? null ), 'permission_callback is open BY DESIGN — the handler verifies, in one place' );

echo "Group: the mirror case — with the secret PRESENT, the switch alone still shuts the gate\n";
// This is what makes "switch ON but secret ABSENT" above meaningful. One
// direction alone is satisfied by OR; the two together are only satisfied by AND.
// Without this, deleting the kill-switch check from sn_bridge_should_register()
// would leave the suite fully green.
$GLOBALS['__options'] = array();
ok( false === sn_bridge_should_register(), 'THE AND-DISCRIMINATOR: secret PRESENT but switch OFF -> do not register' );

$GLOBALS['__routes'] = array();
sn_bridge_register_routes();
ok( array() === $GLOBALS['__routes'], 'and it registers nothing, so the route ceases to exist when the owner darkens the door' );

echo "Group: the Bearer is compared in constant time, and absence is refusal\n";
// A minimal request stand-in: the handler only asks for a header and the body.
class SNB_Req {
	private $headers; private $body;
	public function __construct( $headers = array(), $body = array() ) { $this->headers = $headers; $this->body = $body; }
	public function get_header( $k ) { $k = strtolower( $k ); return isset( $this->headers[ $k ] ) ? $this->headers[ $k ] : null; }
	public function get_json_params() { return $this->body; }
}

ok( false === sn_bridge_bearer_matches( null, 'secret' ), 'a null Authorization header never matches' );
ok( false === sn_bridge_bearer_matches( '', 'secret' ), 'an empty Authorization header never matches' );
ok( false === sn_bridge_bearer_matches( 'Bearer wrong', 'secret' ), 'a wrong bearer does not match' );
ok( false === sn_bridge_bearer_matches( 'secret', 'secret' ), 'the bare secret without the Bearer prefix does not match' );
ok( true  === sn_bridge_bearer_matches( 'Bearer secret', 'secret' ), 'the correct Bearer matches' );
ok( false === sn_bridge_bearer_matches( 'Bearer secret', '' ), 'THE ONE THAT MATTERS: an empty configured secret matches NOTHING' );

// DO NOT "tidy" these two values into something readable — the strangeness IS the
// test. Both are numeric strings in scientific notation, so PHP's == coerces each
// to the float 0 and reports 0 == 0, i.e. TRUE: the classic magic-hash bypass.
// hash_equals() compares them as strings and returns false. This assertion is
// therefore the witness that distinguishes the two operators, and it is the only
// one that does. Replacing hash_equals() with == would be an AUTHENTICATION
// BYPASS for any numeric-looking SN_BRIDGE_TOKEN, not merely a timing regression.
// (The timing half of hash_equals()'s job remains unassertable in this harness —
// that is a known and accepted gap, recorded rather than papered over.)
ok( false === sn_bridge_bearer_matches( 'Bearer 0e222222', '0e111111' ), 'THE TYPE-JUGGLING PIN: two distinct numeric strings must not authenticate each other (PHP == would say 0 == 0)' );

echo "Group: the capability is granted ONLY while a verified request is in flight\n";
// The filter must consult the module flag, never the request alone.
ok( false === sn_bridge_is_verified(), 'nothing is verified at rest' );

// EVERY ABSENCE PIN BELOW USES array_key_exists(), NEVER isset(). isset() is
// false for a key whose value is null, so it cannot tell "never granted" from
// "granted null" — a grant of `$allcaps['manage_options'] = null;` would sail
// past an isset() pin while the label still claimed the capability was never
// granted. array_key_exists() asserts genuine absence, which is what these
// labels say. Do not "simplify" them back to isset().
$caps = sn_bridge_grant_capability( array( 'read' => true ) );
ok( ! array_key_exists( 'sn_read_remote_analytics', $caps ), 'THE ONE THAT MATTERS: the filter grants NOTHING when no verified request is in flight' );
ok( true === $caps['read'], 'and it passes other capabilities through untouched' );

sn_bridge_set_verified( true );
ok( true === sn_bridge_is_verified(), 'the flag can be set' );
$caps = sn_bridge_grant_capability( array( 'read' => true ) );
ok( true === $caps['sn_read_remote_analytics'], 'a verified request grants exactly the remote capability' );
ok( ! array_key_exists( 'manage_options', $caps ), 'and never manage_options' );

// The revoke case is handed a NON-EMPTY array on purpose. Passed array(), this
// pin could not tell "revoked the grant" from "returned an empty array" — an
// implementation whose unverified branch did `return array();` would look
// revoked while silently discarding every capability the caller already held.
// The second assertion is the discriminator.
sn_bridge_set_verified( false );
$caps = sn_bridge_grant_capability( array( 'read' => true ) );
ok( ! array_key_exists( 'sn_read_remote_analytics', $caps ), 'clearing the flag revokes the grant' );
ok( true === $caps['read'], 'and the revoked path still passes other capabilities through' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-bridge-route.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-bridge-route.php\n";
exit( $fail > 0 ? 1 : 0 );
