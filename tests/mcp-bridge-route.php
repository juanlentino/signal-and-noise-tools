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

// LABEL REWORDED to match what the bodies below actually establish. It formerly
// read "refuses in order, and never leaks which gate refused" while asserting
// neither: every 401 pin sent an on-list slug, so the ordering was free, and no
// two refusals were ever compared against each other. Both properties now have
// witnesses — the pre-auth 401 pins for the first, the two identical 404s for the
// second — and the label is narrowed to exactly those, because a caller holding a
// VALID secret still learns 400-vs-404-vs-401, which is deliberate and not a leak.
echo "Group: the handler refuses in ORDER, and its two 404s are indistinguishable\n";
// A stub ability layer: one known slug that echoes its args.
$GLOBALS['__executed'] = array();
class SNB_Ability {
	private $slug;
	public function __construct( $s ) { $this->slug = $s; }
	public function execute( $args ) {
		$GLOBALS['__executed'][] = array( $this->slug, $args );
		// The throwing half of the stub. An ability CAN throw — a DB error, a
		// remote timeout, a TypeError inside a callback — and the handler's
		// cleanup has to survive it. See the throw group at the bottom.
		if ( ! empty( $GLOBALS['__throw'] ) ) { throw new RuntimeException( 'the ability exploded' ); }
		return array( 'ran' => $this->slug );
	}
}
/**
 * The ability lookup, with a suppression flag.
 *
 * $GLOBALS['__no_ability'] holds a slug that this stub refuses to resolve EVEN
 * THOUGH it is on sn_mcp_remote_slugs(). That is not a contrived state: the list
 * and the ability registry are separate things, and a slug can be listed while
 * its ability is not registered — a module deactivated, a load-order change, a
 * registration hook that ran too late. Do not delete this flag as dead
 * scaffolding; it is the only way to reach the handler's third refusal.
 */
function wp_get_ability( $slug ) {
	if ( isset( $GLOBALS['__no_ability'] ) && $slug === $GLOBALS['__no_ability'] ) {
		return null;
	}
	return in_array( $slug, sn_mcp_remote_slugs(), true ) ? new SNB_Ability( $slug ) : null;
}

// NOTE: SN_BRIDGE_TOKEN is ALREADY defined as 'topsecret' above, which the
// gate-opens assertion needs. Do not define it again here — a second define() of
// the same constant is a PHP warning and the value would not change.
$REMOTE = sn_mcp_remote_slugs()[0];

// THE SWITCH GOES BACK ON HERE, and it is not housekeeping. The group above left
// it OFF to prove the AND, and the handler now re-reads both gates at step 0
// (defence in depth against a toggle flipped mid-request), so every dispatch
// below would answer 404 with it still off. Leaving this line out does not make
// the suite stricter — it makes twelve pins assert the step-0 refusal while
// their labels claim to be testing the Bearer, the slug list and the envelope.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );

$r = sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => $REMOTE ) ) );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'no Authorization -> 404' );

$r = sn_bridge_handle_request( new SNB_Req( array( 'authorization' => 'Bearer wrong' ), array( 'slug' => $REMOTE ) ) );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'wrong Bearer -> 404' );

// THE ORDER PINS. The two 401s above both send an ON-LIST slug, so they are
// satisfied no matter which check runs first — moving the slug checks above the
// Bearer check leaves them green. These two are the only witnesses that
// AUTHENTICATION RUNS FIRST.
//
// Why that ordering is the security property and not a style preference: with
// the slug checks first, an UNAUTHENTICATED caller gets 404 for a slug that is
// off the remote list and 401 for one that is on it. That difference is a
// complete enumeration oracle for sn_mcp_remote_slugs(), readable with no
// credential at all — which is precisely what answering 404-rather-than-403
// below exists to deny. An unauthenticated caller must learn NOTHING about the
// body it sent, so every pre-auth refusal has to be the same 401.
$r = sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => 'signal-noise/get-post-content' ) ) );
ok( is_wp_error( $r ) && 404 === $r->data['status'] && 'rest_no_route' === $r->code, 'THE INDISTINGUISHABILITY PIN: no Authorization + an OFF-LIST slug is the SAME status AND code as every other anonymous refusal' );

$r = sn_bridge_handle_request( new SNB_Req( array(), array() ) );
ok( is_wp_error( $r ) && 404 === $r->data['status'] && 'rest_no_route' === $r->code, 'and no Authorization + no slug is the same again -> an anonymous caller learns nothing about its body OR the slug' );

$good = array( 'authorization' => 'Bearer topsecret' );
$offlist = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => 'signal-noise/get-post-content' ) ) );
ok( is_wp_error( $offlist ) && 404 === $offlist->data['status'], 'THE ONE THAT MATTERS: a valid secret with an off-list slug -> 404, never 403' );

$r = sn_bridge_handle_request( new SNB_Req( $good, array() ) );
ok( is_wp_error( $r ) && 400 === $r->data['status'], 'a missing slug -> 400' );

// THE THIRD REFUSAL, which nothing else in this file can reach. A slug can be on
// sn_mcp_remote_slugs() and still have no registered ability: the list and the
// ability registry are separate, so a deactivated module, a load-order change, or
// a registration hook that ran after rest_api_init all produce exactly this state
// in production. It is not hypothetical, and it must not be the one path that
// 500s or fatals — a stack trace from an authenticated bridge call is both an
// availability bug and an information leak.
$GLOBALS['__no_ability'] = $REMOTE;
$r = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
unset( $GLOBALS['__no_ability'] );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'an ON-LIST slug whose ability does not resolve -> 404, not a 500 and not a fatal' );
// AND INDISTINGUISHABLE FROM THE OFF-LIST REFUSAL. Matching the status alone is
// not enough: two refusals carrying different error CODES would rebuild the same
// enumeration oracle one field further down, telling a caller who holds a valid
// secret which listed slugs are actually wired up. The identical code IS the
// property, so it gets its own witness.
ok( $offlist->get_error_code() === $r->get_error_code(), 'and it carries the SAME error code as the off-list refusal — the two are not distinguishable from outside' );

echo "Group: a verified call dispatches, and leaves nothing behind\n";
$GLOBALS['__executed'] = array();
$GLOBALS['__removed']  = array();
$r = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE, 'args' => array( 'range' => 7 ) ) ) );
ok( ! is_wp_error( $r ), 'a fully valid call is not an error' );
// THE ENVELOPE PIN. `! is_wp_error()` is true of the ability's raw return too, so
// without this the wrapper is unasserted and returning $out unwrapped is a
// mutation no pin catches. The Worker parses this shape; changing it silently is
// a cross-artifact break that shows up only at the far end of the bridge.
ok( array( 'ok' => true, 'data' => array( 'ran' => $REMOTE ) ) === $r, 'and it comes back in the ok/data envelope, with the ability output under data' );
ok( 1 === count( $GLOBALS['__executed'] ), 'the ability executed exactly once' );
ok( array( 'range' => 7 ) === $GLOBALS['__executed'][0][1], 'and received its args' );
ok( false === sn_bridge_is_verified(), 'THE OTHER ONE THAT MATTERS: the verified flag is cleared after dispatch' );
ok( in_array( 'user_has_cap', $GLOBALS['__removed'], true ), 'and the capability filter was removed' );

echo "Group: the cleanup survives an ability that THROWS — the reason the finally exists\n";
// NOT COVERED BY THE GROUP ABOVE, and that is the whole point of adding it.
// Every pin above dispatches an ability that RETURNS, and on that path a handler
// with no try/finally at all — a plain `$out = $ability->execute( $args );`
// followed by the same two cleanup lines — is INDISTINGUISHABLE from the correct
// one. Deleting the finally leaves this suite green without these three.
//
// Only a throw separates them. Without the finally the exception unwinds straight
// past the cleanup, and every capability check for the remainder of that request
// is answered by a filter that is still attached and a flag that still says
// verified — the request keeps sn_read_remote_analytics that nothing took back.
$GLOBALS['__throw']   = true;
$GLOBALS['__removed'] = array();
$threw = false;
try {
	sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
$GLOBALS['__throw'] = false;
// The propagation pin is a discriminator, not decoration: a handler that
// swallowed the exception and returned a success envelope would satisfy both
// cleanup pins below while telling the Worker a failed call succeeded.
ok( $threw, 'the ability failure propagates rather than being swallowed into a success envelope' );
ok( false === sn_bridge_is_verified(), 'THE ONE THE finally EXISTS FOR: a throwing ability still leaves the flag cleared' );
ok( in_array( 'user_has_cap', $GLOBALS['__removed'], true ), 'and the capability filter is still removed when the ability throws' );

echo "Group: an anonymous probe cannot tell an ARMED door from a shut one\n";
// Finding 1 from the 2026-08-14 adversarial review. The handler used to answer
// 401 for a bad Bearer while an unregistered route answers 404 — so the status
// alone announced "this door is armed". Grok: "You cannot keep 401 and keep
// claim 5." v11.0.0 answered that with 404, which closed the STATUS half.
//
// THE BODY HALF WAS STILL OPEN, and these pins are what closes it. A REST client
// reads JSON, not a status line, and `sn_bridge_not_found` / "Not found." is not
// what WordPress says about a route that does not exist. Two 404s with different
// codes are the same oracle one field further down — which is the shape this
// increment has now been bitten by twice.
$anon = sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => $REMOTE ) ) );
$bad  = sn_bridge_handle_request( new SNB_Req( array( 'authorization' => 'Bearer nope' ), array( 'slug' => $REMOTE ) ) );
$off  = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => 'signal-noise/get-post-content' ) ) );
ok( 404 === $anon->data['status'] && 404 === $bad->data['status'] && 404 === $off->data['status'], 'anonymous, wrong-secret and off-list refusals all carry status 404' );
ok( $anon->code === $bad->code, 'and the two ANONYMOUS refusals carry the same error code — status parity alone is not indistinguishability' );

// THE VALUE-LEVEL PARITY PIN, and it must stay value-level. The suite cannot
// call WP_REST_Server::dispatch() to compare against the real thing, so the
// literals below stand in for it — copied verbatim from core's
// src/wp-includes/rest-api/class-wp-rest-server.php:
//
//     return new WP_Error(
//         'rest_no_route',
//         __( 'No route was found matching the URL and request method.' ),
//         array( 'status' => 404 )
//     );
//
// Asserting `$anon->code === $bad->code` alone would stay green if BOTH drifted
// to some third code, which is precisely the state that reopens the oracle. Each
// of the three fields therefore gets pinned to its core value, not to its twin.
ok( 'rest_no_route' === $anon->code, 'THE PARITY PIN (code): an anonymous refusal carries core\'s rest_no_route, so it is not distinguishable from an unregistered route' );
ok( 'No route was found matching the URL and request method.' === $anon->message, 'THE PARITY PIN (message): and core\'s message verbatim — the body is what a REST client actually reads' );
ok( array( 'status' => 404 ) === $anon->data, 'THE PARITY PIN (data): and core\'s data array exactly, with no extra keys to read the door by' );

// The secret-holder's refusal is DELIBERATELY distinct, and that asymmetry is
// the design rather than a leftover. They are authenticated; a distinct code
// tells them their slug was unknown and gives the Worker something to log.
// Without this pin, collapsing every refusal to rest_no_route would look like an
// improvement and would quietly cost the Worker its only diagnostic.
ok( 'sn_bridge_not_found' === $off->code, 'and a caller who HOLDS the secret gets the distinct sn_bridge_not_found — telling an authenticated caller its slug was unknown leaks nothing' );

echo "Group: the gates are read AGAIN at dispatch, so a toggle flipped mid-request lands\n";
// Defence in depth for the one-request TOCTOU window: the route was registered
// while both gates were open, and the owner unchecks the toggle before the
// request dispatches. Never a durable bypass — the next request would not
// register the route at all — but the refusal costs one predicate call and the
// window is exactly the moment an owner is trying to shut the door.
//
// The refusal must be the ANONYMOUS shape, not a distinct one: a caller holding
// a valid secret who could tell "shut mid-flight" from "never registered" would
// have a live read on the toggle.
$GLOBALS['__options'] = array();
$flipped = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
ok( is_wp_error( $flipped ) && 404 === $flipped->data['status'], 'a VALID secret is refused once the switch goes off, without waiting for the next registration pass' );
ok( 'rest_no_route' === $flipped->code, 'and it is refused in the anonymous shape, so the mid-flight case is not its own tell' );
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$restored = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
ok( ! is_wp_error( $restored ), 'THE ONE THAT PROVES IT REOPENS: switching back on dispatches again, so step 0 is a gate and not a permanent shutdown' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-bridge-route.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-bridge-route.php\n";
exit( $fail > 0 ? 1 : 0 );
