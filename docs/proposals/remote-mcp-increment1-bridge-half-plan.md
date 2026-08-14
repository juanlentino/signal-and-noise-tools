# Remote analytics bridge (origin side) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Cloudflare Worker an authenticated origin channel to exactly one read-only analytics ability, plus a wp-admin toggle so the owner can darken the path from a phone.

**Architecture:** A new `inc/mcp/mcp-bridge-route.php` registers `POST /signal-noise/v1/bridge` **only** when the remote kill switch is on and `SN_BRIDGE_TOKEN` is defined — so both failure modes are a non-existent route rather than a handler branch. The handler compares the Bearer with `hash_equals()`, sets a module flag, grants `sn_read_remote_analytics` through a `user_has_cap` filter, dispatches the ability, and removes the filter in a `finally`. A checkbox on the existing MCP admin surface writes `sn_mcp_remote_enabled` through the plugin's existing `sn_action` dispatch.

**Tech Stack:** PHP 8.3, WordPress REST API, Abilities API. Tests are standalone PHP fixture files with hand-rolled WP stubs, swept by `bash tests/run.sh`. No DB, no WP install.

**Spec:** [`remote-mcp-increment1-bridge-half.md`](remote-mcp-increment1-bridge-half.md)

**Baseline before starting:** `bash tests/run.sh` → `-- swept 426 suites, 16956 assertions passed, 1 skipped --`

---

## Orientation — five things that will otherwise look arbitrary

**1. Tests are standalone scripts, not PHPUnit.** Each `tests/*.php` defines its own WP stubs, `require`s the `inc/` file, and uses a local `ok( $condition, $message )` helper. Run one with `php tests/<name>.php`. `tests/run.sh` gates on each suite's **summary line**, never on absence of FAIL — a fataled suite prints no summary and silently contributes zero. Read `tests/mcp-remote-guard.php` first; this work is its sibling.

**2. The kill switch fails CLOSED.** `sn_mcp_remote_enabled` absent means OFF (`inc/mcp/mcp-remote-guard.php:92` is `get_option( ..., false )`). This inverts every other switch in the plugin and is deliberate. Never "fix" it toward the read door's `true`.

**3. Admin saves go through one dispatcher.** Forms carry `wp_nonce_field( 'sn_theme_options_nonce' )` plus a hidden `sn_action`. `inc/admin-post-handler.php:155` checks the nonce, looks the action up in `sn_admin_post_handlers()` (line 29), and calls a handler in `inc/admin-post-actions.php` which receives raw `$_POST`, unslashes per field, and returns a flash string. Follow that exactly — do not add a bespoke `admin_post_` hook.

**4. Constant-wins is an established admin pattern.** `sn_handle_cf_save()` (`inc/admin-post-actions.php:63`) checks `defined( 'SN_CLOUDFLARE_API_TOKEN' )` and refuses to let the form override it. The toggle does the same with `SN_MCP_REMOTE_DISABLED`.

**5. `inc/admin-tabs-data.php` is a full-sweep contract.** If you touch it, run the entire suite, not one file.

---

## File Structure

| File | Responsibility |
| --- | --- |
| **Create** `inc/mcp/mcp-bridge-route.php` | Registration gate, secret compare, capability filter, dispatch |
| **Create** `tests/mcp-bridge-route.php` | Gate, verification order, capability lifecycle |
| **Modify** `inc/admin-post-handler.php:29-…` | One line in the handler registry |
| **Modify** `inc/admin-post-actions.php` | `sn_handle_remote_toggle()` |
| **Create** `tests/admin-remote-toggle.php` | Toggle handler incl. constant-wins |
| **Modify** `inc/admin-forms/mcp-connect-status.php` | Remote row states + the toggle form |
| **Modify** `signal-and-noise-tools.php` | One `require_once` |
| **Modify** `CHANGELOG.md` | `[Unreleased]` entry |

**Out of scope, tracked elsewhere:** the Worker's `sub` log line lives in `~/Projects/sn-remote-mcp-worker` and is Task 7.

---

## Task 1: The registration gate

**Files:**
- Create: `inc/mcp/mcp-bridge-route.php`
- Test: `tests/mcp-bridge-route.php`

- [ ] **Step 1: Write the failing test**

Create `tests/mcp-bridge-route.php`:

```php
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

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-bridge-route.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-bridge-route.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-bridge-route.php`
Expected: PHP fatal — `Failed to open stream` for `inc/mcp/mcp-bridge-route.php`.

- [ ] **Step 3: Write the implementation**

Create `inc/mcp/mcp-bridge-route.php`:

```php
<?php
/**
 * Signal & Noise — the Worker→origin bridge (R3 §3D, Increment 1 bridge half).
 *
 * ONE route, POST /signal-noise/v1/bridge, that lets the remote analytics Worker
 * call exactly the slugs on sn_mcp_remote_slugs() and nothing else.
 *
 * THE REGISTRATION GATE IS THE DESIGN. The route is registered only when the
 * remote kill switch is on AND SN_BRIDGE_TOKEN is defined. Both failure modes
 * are therefore a route that does not exist — a 404 — rather than a handler that
 * decides to refuse. An unregistered route cannot be reached by a handler bug, a
 * filter ordering mistake, or a future refactor.
 *
 * It also refuses to leak: an earlier draft answered 503 when the secret was
 * missing, to separate misconfiguration from a client error. A 503 tells an
 * unauthenticated caller the route exists and the site means to serve it, which
 * is exactly the reconnaissance a 404 denies. Diagnosis lives in the admin
 * status panel, which is authenticated.
 *
 * THE SECRET IS A CONSTANT, NOT AN OPTION, and that is stronger rather than more
 * awkward: an option is readable by anything that reaches the database — an
 * admin-level compromise, a plugin vulnerability, a leaked SQL dump — while
 * wp-config.php is readable by no web request. Same reasoning as
 * SN_MCP_READ_DISABLED and SN_MCP_REMOTE_DISABLED.
 *
 * Prior art for verifying an INBOUND Worker credential is
 * inc/analytics-refresh-rest.php, which compares SN_SRV_TOKEN with hash_equals().
 * (SN_MR_READ_TOKEN in inc/machine-readers-api.php is OUTBOUND — WP calling a
 * Worker — and is not the pattern here.)
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bridge secret, or '' when it is not usable.
 *
 * Absent and empty are deliberately the same answer: a constant defined as ''
 * must never authenticate anybody.
 *
 * @return string
 */
function sn_bridge_secret() {
	return ( defined( 'SN_BRIDGE_TOKEN' ) && '' !== (string) SN_BRIDGE_TOKEN )
		? (string) SN_BRIDGE_TOKEN
		: '';
}

/**
 * Should the bridge route exist at all on this request?
 *
 * BOTH gates, and neither alone. The kill switch is checked through the remote
 * guard so there is one definition of "the remote door is open".
 *
 * @return bool
 */
function sn_bridge_should_register() {
	if ( ! function_exists( 'sn_mcp_remote_kill_switch_engaged' ) ) {
		return false;
	}
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
	return '' !== sn_bridge_secret();
}

/**
 * Register the route, or do nothing at all.
 *
 * @return void
 */
function sn_bridge_register_routes() {
	if ( ! sn_bridge_should_register() ) {
		return;
	}
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}
	register_rest_route(
		'signal-noise/v1',
		'/bridge',
		array(
			'methods'  => 'POST',
			// Authentication happens in the handler, in ONE ordered place, so
			// there is never a state where a partially-authenticated request is
			// already inside the abilities layer.
			'permission_callback' => '__return_true',
			'callback'            => 'sn_bridge_handle_request',
		)
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'sn_bridge_register_routes' );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-bridge-route.php`
Expected: `OK (11 passed, 0 failed): mcp-bridge-route.php`

If the count differs from 11, reconcile why rather than editing the number.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-route.php tests/mcp-bridge-route.php
git commit -m "feat: the bridge route does not exist unless both gates are open

Registration requires the remote kill switch ON and SN_BRIDGE_TOKEN defined, so
'switch off' and 'secret absent' are the same thing from outside: a route that
was never registered. An unregistered route cannot be reached by a handler bug,
a filter ordering mistake, or a future refactor.

An earlier draft answered 503 on a missing secret to separate misconfiguration
from a client error. That leaks — a 503 tells an unauthenticated caller the route
exists and the site means to serve it. Diagnosis moves to the admin status panel,
which is authenticated.

The test asserts ABSENCE FROM THE ROUTE TABLE rather than a 404 status, because a
handler returning 404 would satisfy a status assertion while leaving the path
reachable."
```

---

## Task 2: Secret comparison and the verification order

**Files:**
- Modify: `inc/mcp/mcp-bridge-route.php`
- Test: `tests/mcp-bridge-route.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-bridge-route.php`, immediately before the summary block:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-bridge-route.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function sn_bridge_bearer_matches()`

- [ ] **Step 3: Write the implementation**

Add to `inc/mcp/mcp-bridge-route.php`, after `sn_bridge_secret()`:

```php
/**
 * Does the Authorization header carry the configured secret?
 *
 * hash_equals() rather than === so the comparison does not leak the secret's
 * prefix through timing. Mirrors inc/analytics-refresh-rest.php.
 *
 * An empty $secret refuses everything. That case cannot reach here through
 * sn_bridge_register_routes() — the route would not exist — but the function is
 * public and must not become an authenticator for a misconfigured site if it is
 * ever called from somewhere else.
 *
 * @param string|null $header The raw Authorization header.
 * @param string      $secret The configured secret.
 * @return bool
 */
function sn_bridge_bearer_matches( $header, $secret ) {
	$secret = (string) $secret;
	if ( '' === $secret ) {
		return false;
	}
	$header = (string) $header;
	if ( 0 !== strncmp( $header, 'Bearer ', 7 ) ) {
		return false;
	}
	return hash_equals( $secret, substr( $header, 7 ) );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-bridge-route.php`
Expected: `OK (11 passed, 0 failed): mcp-bridge-route.php`

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-route.php tests/mcp-bridge-route.php
git commit -m "feat: the bridge compares its Bearer in constant time

hash_equals rather than === so the comparison cannot leak the secret's prefix
through timing — the same shape inc/analytics-refresh-rest.php already uses for
SN_SRV_TOKEN, which is the house pattern for verifying an inbound Worker call.

An empty configured secret refuses everything. That state cannot reach this
function through the registration gate, but the function is public and must not
become an authenticator for a misconfigured site if it is ever called from
somewhere else."
```

---

## Task 3: The request-scoped capability

**Files:**
- Modify: `inc/mcp/mcp-bridge-route.php`
- Test: `tests/mcp-bridge-route.php`

This is where the increment could leak a capability, so the tests assert the *lifecycle*, not just the grant.

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-bridge-route.php`, before the summary block:

```php
echo "Group: the capability is granted ONLY while a verified request is in flight\n";
// The filter must consult the module flag, never the request alone.
ok( false === sn_bridge_is_verified(), 'nothing is verified at rest' );

$caps = sn_bridge_grant_capability( array( 'read' => true ) );
ok( ! isset( $caps['sn_read_remote_analytics'] ), 'THE ONE THAT MATTERS: the filter grants NOTHING when no verified request is in flight' );
ok( true === $caps['read'], 'and it passes other capabilities through untouched' );

sn_bridge_set_verified( true );
ok( true === sn_bridge_is_verified(), 'the flag can be set' );
$caps = sn_bridge_grant_capability( array( 'read' => true ) );
ok( true === $caps['sn_read_remote_analytics'], 'a verified request grants exactly the remote capability' );
ok( ! isset( $caps['manage_options'] ), 'and never manage_options' );

sn_bridge_set_verified( false );
$caps = sn_bridge_grant_capability( array() );
ok( ! isset( $caps['sn_read_remote_analytics'] ), 'clearing the flag revokes the grant' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-bridge-route.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function sn_bridge_is_verified()`

- [ ] **Step 3: Write the implementation**

Add to `inc/mcp/mcp-bridge-route.php`:

```php
/**
 * The in-flight verification flag.
 *
 * A module-scoped static rather than a global so nothing outside this file can
 * set it. The capability filter consults ONLY this — never the request — so a
 * request that did not pass verification cannot be granted anything even if the
 * filter is somehow still attached.
 *
 * @param bool $value
 * @return void
 */
function sn_bridge_set_verified( $value ) {
	sn_bridge_verified_state( (bool) $value );
}

/**
 * @return bool
 */
function sn_bridge_is_verified() {
	return sn_bridge_verified_state( null );
}

/**
 * Single owner of the flag's storage.
 *
 * @param bool|null $set Null reads; a bool writes.
 * @return bool
 */
function sn_bridge_verified_state( $set = null ) {
	static $verified = false;
	if ( null !== $set ) {
		$verified = (bool) $set;
	}
	return $verified;
}

/**
 * Grant the remote capability, and ONLY while a verified request is in flight.
 *
 * Attached to `user_has_cap` for the duration of one dispatch and removed in a
 * `finally`. Grants exactly one capability — never a role, never
 * manage_options, and never anything derived from the request.
 *
 * @param array $allcaps
 * @return array
 */
function sn_bridge_grant_capability( $allcaps ) {
	if ( ! sn_bridge_is_verified() ) {
		return $allcaps;
	}
	$allcaps = is_array( $allcaps ) ? $allcaps : array();
	$cap     = defined( 'SN_MCP_REMOTE_CAPABILITY' ) ? SN_MCP_REMOTE_CAPABILITY : 'sn_read_remote_analytics';
	$allcaps[ $cap ] = true;
	return $allcaps;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-bridge-route.php`
Expected: `OK (18 passed, 0 failed): mcp-bridge-route.php`

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-route.php tests/mcp-bridge-route.php
git commit -m "feat: the bridge capability exists only while a verified request is in flight

The user_has_cap filter consults a module-scoped flag and NEVER the request, so
a request that did not pass verification cannot be granted anything even if the
filter is somehow still attached. It grants exactly one capability — never a
role, never manage_options.

The tests assert the LIFECYCLE rather than the grant: nothing at rest, granted
while verified, revoked when the flag clears. A test that only checked the grant
would pass against an implementation that never removed it."
```

---

## Task 4: The handler — verification order and dispatch

**Files:**
- Modify: `inc/mcp/mcp-bridge-route.php`
- Test: `tests/mcp-bridge-route.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-bridge-route.php`, before the summary block:

```php
echo "Group: the handler refuses in order, and never leaks which gate refused\n";
// A stub ability layer: one known slug that echoes its args.
$GLOBALS['__executed'] = array();
class SNB_Ability {
	private $slug;
	public function __construct( $s ) { $this->slug = $s; }
	public function execute( $args ) { $GLOBALS['__executed'][] = array( $this->slug, $args ); return array( 'ran' => $this->slug ); }
}
function wp_get_ability( $slug ) { return in_array( $slug, sn_mcp_remote_slugs(), true ) ? new SNB_Ability( $slug ) : null; }

// NOTE: SN_BRIDGE_TOKEN is ALREADY defined as 'topsecret' by Task 1's block, which
// needs it to assert that the gate opens. Do not define it again here — a second
// define() of the same constant is a PHP warning and the value would not change.
$REMOTE = sn_mcp_remote_slugs()[0];

$r = sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => $REMOTE ) ) );
ok( is_wp_error( $r ) && 401 === $r->data['status'], 'no Authorization -> 401' );

$r = sn_bridge_handle_request( new SNB_Req( array( 'authorization' => 'Bearer wrong' ), array( 'slug' => $REMOTE ) ) );
ok( is_wp_error( $r ) && 401 === $r->data['status'], 'wrong Bearer -> 401' );

$good = array( 'authorization' => 'Bearer topsecret' );
$r = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => 'signal-noise/get-post-content' ) ) );
ok( is_wp_error( $r ) && 404 === $r->data['status'], 'THE ONE THAT MATTERS: a valid secret with an off-list slug -> 404, never 403' );

$r = sn_bridge_handle_request( new SNB_Req( $good, array() ) );
ok( is_wp_error( $r ) && 400 === $r->data['status'], 'a missing slug -> 400' );

echo "Group: a verified call dispatches, and leaves nothing behind\n";
$GLOBALS['__executed'] = array();
$GLOBALS['__removed']  = array();
$r = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE, 'args' => array( 'range' => 7 ) ) ) );
ok( ! is_wp_error( $r ), 'a fully valid call is not an error' );
ok( 1 === count( $GLOBALS['__executed'] ), 'the ability executed exactly once' );
ok( array( 'range' => 7 ) === $GLOBALS['__executed'][0][1], 'and received its args' );
ok( false === sn_bridge_is_verified(), 'THE OTHER ONE THAT MATTERS: the verified flag is cleared after dispatch' );
ok( in_array( 'user_has_cap', $GLOBALS['__removed'], true ), 'and the capability filter was removed' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-bridge-route.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function sn_bridge_handle_request()`

- [ ] **Step 3: Write the implementation**

Add to `inc/mcp/mcp-bridge-route.php`:

```php
/**
 * Handle one bridge call.
 *
 * VERIFICATION ORDER, each step failing closed. The registration gate has
 * already guaranteed the switch is on and the secret exists, so this function
 * starts at the Bearer.
 *
 *   1. Bearer matches            -> else 401
 *   2. slug is on the remote list -> else 404 (never 403)
 *   3. the ability resolves       -> else 404
 *
 * STEP 2 ANSWERS 404, NOT 403, ON PURPOSE. A 403 confirms the slug exists and
 * turns this endpoint into an enumeration oracle for the remote allowlist.
 * sn_mcp_call_tool() already answers unknown tools with -32602 rather than a
 * permission error; this is the REST-shaped equivalent.
 *
 * There is no separate scope check: THE REMOTE SLUG LIST IS THE SCOPE. With one
 * secret and one list, a per-secret scope field would encode the same fact twice
 * and could drift out of step with it.
 *
 * @param object $request The REST request.
 * @return array|WP_Error
 */
function sn_bridge_handle_request( $request ) {
	$header = is_object( $request ) && method_exists( $request, 'get_header' )
		? $request->get_header( 'authorization' )
		: null;

	if ( ! sn_bridge_bearer_matches( $header, sn_bridge_secret() ) ) {
		return new WP_Error(
			'sn_bridge_unauthorized',
			__( 'Unauthorized.', 'signal-and-noise-tools' ),
			array( 'status' => 401 )
		);
	}

	$body = ( is_object( $request ) && method_exists( $request, 'get_json_params' ) )
		? (array) $request->get_json_params()
		: array();
	$slug = isset( $body['slug'] ) ? (string) $body['slug'] : '';
	$args = ( isset( $body['args'] ) && is_array( $body['args'] ) ) ? $body['args'] : array();

	if ( '' === $slug ) {
		return new WP_Error(
			'sn_bridge_bad_request',
			__( 'Missing slug.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'sn_mcp_remote_slugs' ) || ! in_array( $slug, sn_mcp_remote_slugs(), true ) ) {
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
	if ( ! $ability ) {
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	// Grant, dispatch, and ALWAYS put it back. The finally is the reason this is
	// safe: an ability that throws must not leave the capability attached.
	sn_bridge_set_verified( true );
	add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
	try {
		$out = $ability->execute( $args );
	} finally {
		remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
		sn_bridge_set_verified( false );
	}

	return is_wp_error( $out ) ? $out : array( 'ok' => true, 'data' => $out );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-bridge-route.php`
Expected: `OK (27 passed, 0 failed): mcp-bridge-route.php`

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-route.php tests/mcp-bridge-route.php
git commit -m "feat: the bridge handler verifies in order and cleans up in a finally

Bearer, then slug membership, then ability resolution — each failing closed. The
slug refusal answers 404 rather than 403 on purpose: a 403 confirms the slug
exists and turns the endpoint into an enumeration oracle for the remote
allowlist, which is why sn_mcp_call_tool() already answers unknown tools with
-32602 rather than a permission error.

There is no separate scope check. The remote slug list IS the scope; a per-secret
scope field would encode the same fact twice and could drift.

The finally is load-bearing: an ability that throws must not leave the capability
attached. The test asserts the flag is cleared and the filter removed AFTER a
successful dispatch, not merely that the grant worked."
```

---

## Task 5: The wp-admin toggle

**Files:**
- Modify: `inc/admin-post-handler.php` (the `sn_admin_post_handlers()` array at line 29)
- Modify: `inc/admin-post-actions.php`
- Test: `tests/admin-remote-toggle.php`

- [ ] **Step 1: Write the failing test**

Create `tests/admin-remote-toggle.php`:

```php
<?php
/**
 * Tests: the remote-door toggle, and the constant that overrides it.
 *
 * This is the phone-reachable control. sn_mcp_remote_enabled is absent by
 * default, so without this the door needs WP-CLI to turn ON and WP-CLI to turn
 * OFF — a terminal in both directions, which is exactly what the owner cannot
 * reach from a phone.
 *
 * THE ASSERTION THAT MATTERS MOST is that SN_MCP_REMOTE_DISABLED beats the form.
 * A wp-config kill must not be re-openable from a web request, or the constant
 * is decorative. Same shape as sn_handle_cf_save() refusing to override
 * SN_CLOUDFLARE_API_TOKEN.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : ''; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }

require __DIR__ . '/../inc/admin-post-actions.php';

echo "Group: the toggle writes the option in both directions\n";
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( true === get_option( 'sn_mcp_remote_enabled', false ), 'checked -> option true' );
ok( 'remote_enabled' === $flash, 'and reports the enabled flash' );

$flash = sn_handle_remote_toggle( array() );
ok( false === get_option( 'sn_mcp_remote_enabled', true ), 'unchecked (absent key) -> option false' );
ok( 'remote_disabled' === $flash, 'and reports the disabled flash' );

echo "Group: THE ONE THAT MATTERS — the wp-config constant beats the form\n";
define( 'SN_MCP_REMOTE_DISABLED', true );
$GLOBALS['__options'] = array();
$flash = sn_handle_remote_toggle( array( 'sn_remote_enabled' => '1' ) );
ok( 'remote_constant_locked' === $flash, 'the form refuses when the constant kills the door' );
ok( ! array_key_exists( 'sn_mcp_remote_enabled', $GLOBALS['__options'] ), 'and writes NOTHING — a killed door cannot be re-opened from a web request' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): admin-remote-toggle.php\n"
	: "\nFAILURES ($pass passed, $fail failed): admin-remote-toggle.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/admin-remote-toggle.php`
Expected: `PHP Fatal error: Uncaught Error: Call to undefined function sn_handle_remote_toggle()`

- [ ] **Step 3: Write the implementation**

Append to `inc/admin-post-actions.php`:

```php
/**
 * Toggle the remote analytics door (R3 §3D).
 *
 * THE PHONE-REACHABLE CONTROL. sn_mcp_remote_enabled is absent by default and
 * fails CLOSED, so without this handler the door needs WP-CLI to turn on and
 * WP-CLI to turn off — a terminal in both directions. The "off" half is the one
 * that matters at 2am away from a laptop.
 *
 * SN_MCP_REMOTE_DISABLED WINS UNCONDITIONALLY. A wp-config kill that a web form
 * could undo would be decorative. Same shape as sn_handle_cf_save() refusing to
 * override SN_CLOUDFLARE_API_TOKEN.
 *
 * The secret itself has no UI here, deliberately: an option is readable by
 * anything that reaches the database, while wp-config.php is readable by no web
 * request. Stopping the door is urgent and belongs on the web; rotating the
 * secret is rare and belongs on a laptop.
 *
 * @param array $post Raw $_POST.
 * @return string Flash key.
 */
function sn_handle_remote_toggle( $post ) {
	if ( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ) {
		return 'remote_constant_locked';
	}
	$on = ! empty( $post['sn_remote_enabled'] );
	update_option( 'sn_mcp_remote_enabled', $on, false );
	return $on ? 'remote_enabled' : 'remote_disabled';
}
```

Then add one line to the array in `sn_admin_post_handlers()` in `inc/admin-post-handler.php`, after `'health_scan' => 'sn_handle_health_scan',`:

```php
		'remote_toggle'              => 'sn_handle_remote_toggle',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/admin-remote-toggle.php`
Expected: `OK (6 passed, 0 failed): admin-remote-toggle.php`

- [ ] **Step 5: Commit**

```bash
git add inc/admin-post-actions.php inc/admin-post-handler.php tests/admin-remote-toggle.php
git commit -m "feat: the remote door gets a toggle that a phone can reach

sn_mcp_remote_enabled is absent by default and fails closed, so without this the
door needed WP-CLI to turn on AND WP-CLI to turn off — a terminal in both
directions, which is exactly what is unavailable from a phone. The 'off' half is
the one that matters away from a laptop.

SN_MCP_REMOTE_DISABLED wins unconditionally and the handler writes nothing when
it is set: a wp-config kill a web form could undo would be decorative. Same shape
as sn_handle_cf_save() refusing to override SN_CLOUDFLARE_API_TOKEN.

The secret gets no UI, deliberately. An option is readable by anything reaching
the database; wp-config.php is readable by no web request. Stopping is urgent and
belongs on the web, rotating is rare and belongs on a laptop."
```

---

## Task 6: The status panel distinguishes what the endpoint hides

**Files:**
- Modify: `inc/admin-forms/mcp-connect-status.php`

The endpoint answers 404 for three different conditions on purpose. The owner still needs to tell them apart, and this is where that is paid for.

- [ ] **Step 1: Read the file first**

Run: `sed -n '1,80p' inc/admin-forms/mcp-connect-status.php`

Note how `$rw_state` is computed and rendered as a pill (`constant_killed | option_off | inactive | bound | unresolvable`). The remote row follows the same shape. **This file currently contains zero `<form>` and zero `<input>` — it is a read-only display, and this task makes it interactive for the first time.**

- [ ] **Step 2: Add the remote state resolver and the toggle form**

Add a `$remote_state` computed the same way `$rw_state` is:

```php
$remote_state = 'option_off';
if ( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ) {
	$remote_state = 'constant_killed';
} elseif ( function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged() ) {
	// The door is open. Distinguish "ready" from "on but unusable", because the
	// endpoint deliberately answers 404 for both and the owner cannot tell them
	// apart from outside.
	$remote_state = ( function_exists( 'sn_bridge_secret' ) && '' !== sn_bridge_secret() )
		? 'bridge_ready'
		: 'secret_missing';
}
```

Render `secret_missing` with an error pill reading `secret missing` and the help text: *"The door is on but `SN_BRIDGE_TOKEN` is not defined in wp-config.php, so the bridge route is not registered. The endpoint answers 404 — the same as a closed door — on purpose."*

Add the toggle form, following the house pattern exactly:

```php
<form method="post">
	<?php wp_nonce_field( 'sn_theme_options_nonce' ); ?>
	<input type="hidden" name="sn_action" value="remote_toggle" />
	<label>
		<input type="checkbox" name="sn_remote_enabled" value="1"
			<?php checked( function_exists( 'sn_mcp_remote_kill_switch_engaged' ) && ! sn_mcp_remote_kill_switch_engaged() ); ?>
			<?php disabled( defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED ); ?> />
		<?php esc_html_e( 'Remote analytics door enabled', 'signal-and-noise-tools' ); ?>
	</label>
	<?php submit_button( __( 'Save', 'signal-and-noise-tools' ), 'secondary' ); ?>
</form>
```

**Use `esc_html_e()`, not `esc_html__()`, where the string is being output** — `esc_html_e` echoes. Getting this backwards prints nothing and is a known trap in this codebase.

- [ ] **Step 3: Check the render suite's stubs BEFORE running it**

This form introduces four WordPress functions into a file that `tests/mcp-connect-render.php`
renders: `wp_nonce_field()`, `checked()`, `disabled()`, `submit_button()`. **If that suite does
not stub them, it fatals** — and a fataled suite prints no summary line, so `tests/run.sh`
counts it as *missing* rather than *failing*. The sweep total drops and nothing says why.

Run first:

```bash
grep -n "function wp_nonce_field\|function checked\|function disabled\|function submit_button" tests/mcp-connect-render.php
```

Add a stub for each one that is absent, matching the file's existing stub style, e.g.:

```php
function wp_nonce_field( $a = '', $n = '_wpnonce', $r = true, $e = true ) { echo '<input type="hidden" name="' . $n . '" value="nonce" />'; }
function checked( $a, $b = true, $e = true ) { $r = ( $a == $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function disabled( $a, $b = true, $e = true ) { $r = ( $a == $b ) ? ' disabled' : ''; if ( $e ) { echo $r; } return $r; }
function submit_button( $t = '', $c = '', $n = 'submit', $w = true ) { echo '<button>' . $t . '</button>'; }
```

Then: `php -l inc/admin-forms/mcp-connect-status.php` → `No syntax errors detected`

Then: `php tests/mcp-connect-render.php` → the suite still prints its summary line. If it asserts
on rendered markup its count may rise; record the true number and do not edit an expected value
to match.

- [ ] **Step 4: Commit**

```bash
git add inc/admin-forms/mcp-connect-status.php
git commit -m "feat: the status panel tells apart what the endpoint deliberately hides

The bridge answers 404 for three different conditions — switch off, secret
missing, unknown slug — so an unauthenticated caller cannot tell a dark switch
from a broken deploy. That is the design, and its cost is that the OWNER cannot
tell either.

This is where that cost is paid: the remote row now renders secret_missing as a
distinct state from option_off, on an authenticated surface. Diagnosis moves to
wp-admin instead of living on the endpoint.

Also the first interactive control in this file, which until now had zero form
and zero input elements."
```

---

## Task 7: Wire, sweep, changelog, and the Worker line

**Files:**
- Modify: `signal-and-noise-tools.php`
- Modify: `CHANGELOG.md`
- Modify (other repo): `~/Projects/sn-remote-mcp-worker`

- [ ] **Step 1: Require the bridge route**

In `signal-and-noise-tools.php`, immediately after the `inc/mcp/mcp-remote-guard.php` require:

```php
require_once SNT_PATH . 'inc/mcp/mcp-bridge-route.php'; // R3 §3D Increment 1 bridge half: Worker→origin channel, registered only when the switch is on AND SN_BRIDGE_TOKEN is defined.
```

Order matters: the bridge calls `sn_mcp_remote_kill_switch_engaged()` and `sn_mcp_remote_slugs()`.

- [ ] **Step 2: Run the full sweep**

Run: `bash tests/run.sh`

Baseline was `426 suites, 16956 assertions`. Expect **428 suites** (two new files) and roughly **+33** assertions — 27 from `mcp-bridge-route.php`, 6 from `admin-remote-toggle.php` — plus any the connect-render suite gains from the new form.

**Do not adjust an expected number to match what you got.** A lower count usually means a suite fataled: `tests/run.sh` gates on the summary line precisely because a crashed suite prints none and contributes zero. Record the real figure.

- [ ] **Step 3: Lint and static analysis**

Run: `composer lint` — expected clean.
Run: `composer phpstan` — expected clean. If it flags the new files, fix the code; do not add them to `phpstan-baseline.neon`.

- [ ] **Step 4: CHANGELOG**

Append under `## [Unreleased]` (contended across sessions — append, do not restructure):

```markdown
### Added
- **The remote analytics door gets its origin channel (R3 §3D, Increment 1 bridge half).** `POST /signal-noise/v1/bridge` accepts a Worker call carrying `SN_BRIDGE_TOKEN`, grants `sn_read_remote_analytics` for that one request, dispatches exactly one allowlisted ability, and removes the grant in a `finally`. The route is **registered only** when the remote kill switch is on and the constant is defined, so both failure modes are a route that does not exist.
- **A wp-admin toggle for the remote door**, so it can be darkened from a phone. `SN_MCP_REMOTE_DISABLED` still wins unconditionally and the form writes nothing when it is set.
```

- [ ] **Step 5: Commit the plugin side**

```bash
git add signal-and-noise-tools.php CHANGELOG.md
git commit -m "chore: load the bridge route, after the guard it depends on

The bridge calls sn_mcp_remote_kill_switch_engaged() and sn_mcp_remote_slugs(),
so mcp-remote-guard.php must be required first.

No version bump in this commit; the toggle makes the increment user-visible, so
the release that carries it is MINOR."
```

- [ ] **Step 6: The Worker's one line**

In `~/Projects/sn-remote-mcp-worker`, on the `tools/call` path, log the Access subject that `guard()` already returns (`src/guard.mjs` returns `{ sub, email }`).

This is the **only** place the Claude session has a name — the origin sees only the Worker, so threat-model §8.3 precondition 5 cannot be satisfied at the origin. Optionally forward it as a non-authoritative `X-SN-Bridge-Subject` header; the origin must **never** authenticate on it, since it is attacker-supplied on any request that reaches the origin outside the Worker.

Commit in that repo separately. It is not part of the plugin's sweep.

---

## Task 8: Mutation verification

Commit first — you will be editing source and reverting. Verify each mutation **actually applied** with `git diff` before believing a result; a mutation that silently fails to land produces a passing run indistinguishable from a correct one.

- [ ] **Step 0:** Replace the body of `sn_bridge_should_register()` with `return false;`.
Run `php tests/mcp-bridge-route.php` → expect `FAIL - THE ONE THAT PROVES IT OPENS: switch ON + secret PRESENT -> register` plus the three argument pins. Revert.

**This is the mutation that found the original defect.** Task 1's first draft asserted only false-or-absence, five times, so a permanently-shut gate survived every assertion. Any future edit that leaves this mutation green has removed the only proof the bridge can open at all.

- [ ] **Step 1:** Delete the `'' !== sn_bridge_secret()` check from `sn_bridge_should_register()`.
Run `php tests/mcp-bridge-route.php` → expect `FAIL - THE ONE THAT MATTERS: switch ON but secret ABSENT -> do not register`. Revert.

- [ ] **Step 1b:** Delete the `sn_mcp_remote_kill_switch_engaged()` check from `sn_bridge_should_register()`.
Run → expect `FAIL - THE AND-DISCRIMINATOR: secret PRESENT but switch OFF -> do not register`. Steps 1 and 1b together are what prove the gate is AND rather than OR; either one alone is satisfied by an implementation that ignores the other input. Revert.

- [ ] **Step 2:** Change `hash_equals` to `==` in `sn_bridge_bearer_matches()`.
Run → the wrong-bearer pins still pass (both are false), so this mutation is **expected to survive**. That is not a gap in the tests — timing-safety cannot be asserted in this harness. Note it and move on rather than inventing a test that appears to cover it.

- [ ] **Step 3:** Remove the `if ( ! sn_bridge_is_verified() )` guard from `sn_bridge_grant_capability()`.
Run → expect `FAIL - THE ONE THAT MATTERS: the filter grants NOTHING when no verified request is in flight`. Revert.

- [ ] **Step 4:** Delete the `finally` block's two lines in `sn_bridge_handle_request()`.
Run → expect `FAIL - THE OTHER ONE THAT MATTERS: the verified flag is cleared after dispatch` and the filter-removed pin. Revert.

- [ ] **Step 5:** Change the off-list slug refusal from 404 to 403.
Run → expect `FAIL - THE ONE THAT MATTERS: a valid secret with an off-list slug -> 404, never 403`. Revert.

- [ ] **Step 6:** Delete the `SN_MCP_REMOTE_DISABLED` guard from `sn_handle_remote_toggle()`.
Run `php tests/admin-remote-toggle.php` → expect both constant-wins pins red. Revert.

- [ ] **Step 7:** Confirm the tree is clean and the sweep matches Task 7 Step 2.

Run: `git status --short && bash tests/run.sh | tail -1`

- [ ] **Step 8:** Record results in the spec

Append a mutation table to `docs/proposals/remote-mcp-increment1-bridge-half.md` with the actual failing assertion names observed — including **Step 2's expected survivor**, which is the honest entry that stops a later reader assuming timing-safety is pinned.

---

## Definition of done

- [ ] `bash tests/run.sh` green, suite count +2, zero failures, real number recorded
- [ ] `composer lint` and `composer phpstan` clean, nothing added to the baseline
- [ ] All mutations observed reddening their named pins, except Step 2's documented survivor
- [ ] `inc/mcp/mcp-remote-guard.php` and `inc/abilities-remote-analytics.php` **unmodified** — verify with `git diff --stat origin/main -- inc/mcp/mcp-remote-guard.php inc/abilities-remote-analytics.php` (expect no output)
- [ ] With `SN_BRIDGE_TOKEN` undefined, `sn_bridge_should_register()` returns false
- [ ] CHANGELOG `[Unreleased]` entry present, no version bump in these commits

## Explicitly NOT done by this plan

F1's fail-closed counter (edge Durable Object), Increment 3's phone-first *secret rotation*, more than one remote slug, and any change to the read or rw doors.
