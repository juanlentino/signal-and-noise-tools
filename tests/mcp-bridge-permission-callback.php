<?php
/**
 * Tests: the bridge SATISFIES the ability's permission callback — it does not
 * bypass it — and the grant that satisfies it exists only mid-dispatch.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM tests/mcp-bridge-route.php. That suite's
 * `SNB_Ability::execute()` never calls `check_permissions()`. It is a fine stub
 * for what that suite asserts (routing, ordering, cleanup) and a useless one for
 * this property: the whole three-gate design rests on `execute()` running the
 * ability's own permission callback, and a stub that skips it CANNOT SEE whether
 * it ran. Every pin in that file stays green if the callback is never consulted.
 *
 * So this file models core faithfully instead. Real `WP_Ability::execute()`
 * (WP 6.9 / 7.1) runs:
 *
 *     wp_pre_execute_ability -> normalize_input -> validate_input
 *       -> check_permissions()  <- the gate
 *       -> do_execute()
 *
 * SNP_Ability below reproduces the two steps that matter — check_permissions()
 * before do_execute(), with `ability_invalid_permissions` on refusal — and it is
 * built from the ability's REAL registration arguments, captured from
 * inc/abilities-remote-analytics.php rather than retyped here. A fixture that
 * hand-declares the callback names would keep passing after a registration
 * renamed one; this one goes red.
 *
 * THE OTHER HALF is the capability system. tests/mcp-bridge-route.php resolves
 * `current_user_can()` out of `$GLOBALS['__caps']`, which is a switch this file
 * could flip to manufacture any answer it liked. Here `current_user_can()` is
 * CORE-SHAPED: it starts from a principal holding NOTHING — the shape of
 * `WP_User( 0 )`, an unauthenticated request, which is what a bridge call
 * actually is — builds an $allcaps map, and runs the registered `user_has_cap`
 * filters over it. So the bridge's grant is exercised through the same mechanism
 * WordPress uses, and "the capability appears" is an OBSERVED CONSEQUENCE of the
 * filter being attached rather than a value this fixture wrote down.
 *
 * THE ASSERTION THAT MATTERS MOST is the first one: a direct execute() with no
 * bridge dispatch in flight must refuse. If it returns data, the capability, the
 * grant, the filter and the finally are all decorative, and the door is open to
 * anyone who can reach the ability by any route at all.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }

// A REAL filter registry, not a no-op. tests/mcp-bridge-route.php can stub
// add_filter() away because it asserts only that the names were passed; this
// file needs the callbacks to actually RUN, because the grant reaching
// current_user_can() through user_has_cap is the property under test.
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb, $priority = 10, $accepted = 1 ) {
	$GLOBALS['__filters'][ $tag ][ $priority ][] = $cb;
	return true;
}
function remove_filter( $tag, $cb, $priority = 10 ) {
	if ( ! isset( $GLOBALS['__filters'][ $tag ][ $priority ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['__filters'][ $tag ][ $priority ] as $i => $existing ) {
		if ( $existing === $cb ) {
			unset( $GLOBALS['__filters'][ $tag ][ $priority ][ $i ] );
			return true;
		}
	}
	return false;
}
function apply_filters( $tag, $value ) {
	if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
		return $value;
	}
	$by_priority = $GLOBALS['__filters'][ $tag ];
	ksort( $by_priority );
	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$value = call_user_func( $cb, $value );
		}
	}
	return $value;
}
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

/**
 * Core-shaped capability resolution for a principal that holds NOTHING.
 *
 * `WP_User::has_cap()` builds $allcaps from the user's roles and then hands it
 * to the `user_has_cap` filter; `current_user_can()` reads the result. User 0 —
 * an unauthenticated request, which every bridge call is — contributes an EMPTY
 * map, so anything true here arrived through a filter and nowhere else.
 *
 * Deliberately NOT a `$GLOBALS['__caps']` lookup. That form lets a suite assert
 * "the capability is present" by having set it itself, which proves nothing
 * about the code under test.
 *
 * @param string $cap
 * @return bool
 */
function current_user_can( $cap ) {
	$allcaps = apply_filters( 'user_has_cap', array() );
	return ! empty( $allcaps[ $cap ] );
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

function register_rest_route( $ns, $route, $args = array() ) { return true; }

// Capture the registration instead of standing up the Abilities API.
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }

/**
 * Stand-in for the shared analytics reader.
 *
 * Declared BEFORE inc/abilities-remote-analytics.php is required so the real
 * reader — which wants $wpdb, an options table and a rollup schema — is never
 * loaded. The registration references it only by NAME, so the ability under
 * test is otherwise unchanged. The marker payload exists so a pin can tell
 * "the execute callback ran" from "something returned a truthy array".
 *
 * @param array $args
 * @return array
 */
function sn_ability_get_analytics_summary( $args = array() ) {
	return array( 'reader_ran' => true, 'args' => $args );
}

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
require __DIR__ . '/../inc/mcp/mcp-bridge-route.php';
require __DIR__ . '/../inc/abilities-remote-analytics.php';

// Fire the registration hook the plugin would fire.
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$REMOTE = 'signal-noise/remote-get-analytics-summary';

/**
 * A FAITHFUL ability, built from the real registration.
 *
 * The callbacks are read out of $GLOBALS['__abilities'] rather than named here,
 * so renaming `permission_callback` in the registration reds this suite instead
 * of silently leaving it testing a callback nothing uses.
 *
 * check_permissions() runs BEFORE do_execute(), and its refusal is core's
 * `ability_invalid_permissions` — the same code WP_Ability::execute() returns.
 */
class SNP_Ability {
	private $args_spec;
	public $permission_calls = 0;
	public $execute_calls    = 0;

	public function __construct( $registration ) {
		$this->args_spec = $registration;
	}

	public function check_permissions( $args = array() ) {
		$this->permission_calls++;
		return (bool) call_user_func( $this->args_spec['permission_callback'], $args );
	}

	public function execute( $args = array() ) {
		if ( ! $this->check_permissions( $args ) ) {
			return new WP_Error(
				'ability_invalid_permissions',
				__( 'Sorry, you are not allowed to execute this ability.', 'signal-and-noise-tools' ),
				array( 'status' => 403 )
			);
		}
		$this->execute_calls++;
		return call_user_func( $this->args_spec['execute_callback'], $args );
	}
}

$GLOBALS['__ability'] = new SNP_Ability( $GLOBALS['__abilities'][ $REMOTE ] );
function wp_get_ability( $slug ) {
	return in_array( $slug, sn_mcp_remote_slugs(), true ) ? $GLOBALS['__ability'] : null;
}

class SNP_Req {
	private $headers; private $body;
	public function __construct( $headers = array(), $body = array() ) { $this->headers = $headers; $this->body = $body; }
	public function get_header( $k ) { $k = strtolower( $k ); return isset( $this->headers[ $k ] ) ? $this->headers[ $k ] : null; }
	public function get_json_params() { return $this->body; }
}

echo "Group: the fixture is wired to the REAL registration, not to names retyped here\n";
// Without these two the rest of the file could be exercising a callback the
// plugin does not use, and every pin below would still be green.
ok( 'snt_ability_perm_remote_analytics_summary' === $GLOBALS['__abilities'][ $REMOTE ]['permission_callback'], 'the fixture executes the registration\'s own permission_callback' );
ok( 'sn_ability_get_analytics_summary' === $GLOBALS['__abilities'][ $REMOTE ]['execute_callback'], 'and the registration\'s own execute_callback' );

echo "Group: the door is SHUT to a direct execute() — no bridge, no grant, no data\n";
// THE ONE THAT MATTERS. If this returns data, the capability, the grant filter
// and the finally are all decorative: anything that can reach the ability by any
// route executes it. Note the switch is ON here, so the refusal comes from the
// CAPABILITY and not from the kill switch — the kill-switch case has its own
// group at the bottom and would otherwise mask this one.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$direct = $GLOBALS['__ability']->execute( array( 'range' => 7 ) );
ok( is_wp_error( $direct ), 'THE ONE THAT MATTERS: a direct execute() with no bridge dispatch in flight is refused' );
ok( 'ability_invalid_permissions' === $direct->get_error_code(), 'and it is refused by the PERMISSION layer — ability_invalid_permissions, core\'s own code' );
ok( 0 === $GLOBALS['__ability']->execute_calls, 'and the execute callback never ran, so no analytics were read' );

echo "Group: the capability is absent at rest, and it is absent because nothing granted it\n";
ok( false === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'the remote capability is absent for a principal holding nothing' );
ok( false === current_user_can( 'manage_options' ), 'and so is manage_options' );

echo "Group: a verified bridge dispatch SATISFIES the callback — it does not skip it\n";
define( 'SN_BRIDGE_TOKEN', 'topsecret' );
$before = $GLOBALS['__ability']->permission_calls;
$r = sn_bridge_handle_request( new SNP_Req( array( 'authorization' => 'Bearer topsecret' ), array( 'slug' => $REMOTE, 'args' => array( 'range' => 7 ) ) ) );
ok( ! is_wp_error( $r ), 'a valid bridge call succeeds' );
// THE DISCRIMINATOR between "satisfied" and "bypassed". A handler that dispatched
// past check_permissions() would satisfy the success pin above and this counter
// would not move. Both are needed: the count alone cannot tell allowed from
// refused, and the success alone cannot tell satisfied from skipped.
ok( $GLOBALS['__ability']->permission_calls > $before, 'and the permission callback was CONSULTED, not skipped' );
ok( 1 === $GLOBALS['__ability']->execute_calls, 'and the execute callback ran exactly once' );
ok( isset( $r['data']['reader_ran'] ) && true === $r['data']['reader_ran'], 'and the ability\'s own output came back under data' );

echo "Group: the grant is what satisfies it — remove the filter and the same call refuses\n";
// Grok's pin, written out. This is the counterfactual the success above cannot
// supply on its own: without it, an ability whose permission callback returned
// true unconditionally would look identical from outside.
// The filter is re-attached BY HAND here because the dispatch above already
// removed it in its finally — which is the correct behaviour and exactly why
// this line is needed. Do not delete it as redundant setup; without it the
// "attached" pin below asserts a state that does not hold, and the
// counterfactual after it stops being a counterfactual at all.
sn_bridge_set_verified( true );
add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
ok( true === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'with the flag set AND the filter attached, the capability resolves true' );
remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
ok( false === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'THE COUNTERFACTUAL: detach the grant filter and the capability vanishes even with the flag still set' );
$without = $GLOBALS['__ability']->execute( array( 'range' => 7 ) );
ok( is_wp_error( $without ) && 'ability_invalid_permissions' === $without->get_error_code(), 'and the ability refuses — so the GRANT is what satisfies the callback, not the ability being open' );
sn_bridge_set_verified( false );

echo "Group: the flag alone grants nothing, and neither does the filter alone\n";
// The AND-discriminator for the two halves of the grant. Either one alone
// leaving the capability resolvable would be a standing capability.
add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
ok( false === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'filter attached but flag clear -> nothing granted' );
remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
sn_bridge_set_verified( true );
ok( false === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'flag set but filter detached -> nothing granted' );
sn_bridge_set_verified( false );

echo "Group: nothing survives the dispatch\n";
// The state pins in tests/mcp-bridge-route.php assert the flag and the filter
// name; this asserts the CONSEQUENCE the owner actually cares about — that the
// capability is not resolvable afterwards, through the real resolution path.
$GLOBALS['__ability']->execute_calls = 0;
sn_bridge_handle_request( new SNP_Req( array( 'authorization' => 'Bearer topsecret' ), array( 'slug' => $REMOTE ) ) );
ok( 1 === $GLOBALS['__ability']->execute_calls, 'the second dispatch ran' );
ok( false === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'and afterwards the capability is gone — the grant did not persist past the request' );
$after = $GLOBALS['__ability']->execute( array() );
ok( is_wp_error( $after ) && 'ability_invalid_permissions' === $after->get_error_code(), 'so a direct execute() AFTER a successful bridge call is refused exactly as it was before one' );

echo "Group: the kill switch beats the capability, at the ability's own gate\n";
// sn_remote_analytics_allows() checks the switch FIRST. Reaching that ordering
// through the permission callback — rather than by calling the predicate — is
// what makes this a pin on the ability rather than on the guard.
$GLOBALS['__options'] = array();
sn_bridge_set_verified( true );
add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
ok( true === current_user_can( SN_MCP_REMOTE_CAPABILITY ), 'the capability is held' );
$shut = $GLOBALS['__ability']->execute( array() );
ok( is_wp_error( $shut ) && 'ability_invalid_permissions' === $shut->get_error_code(), 'THE PRECEDENCE PIN: switch OFF refuses a caller who HOLDS the capability' );
remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
sn_bridge_set_verified( false );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-bridge-permission-callback.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-bridge-permission-callback.php\n";
exit( $fail > 0 ? 1 : 0 );
