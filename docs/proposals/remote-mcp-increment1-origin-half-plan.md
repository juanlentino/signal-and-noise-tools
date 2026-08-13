# Remote analytics origin permission boundary — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the origin-side permission boundary for the remote analytics MCP — a dedicated capability, a remote-only per-slug permission callback, a separate remote ability slug, and a default-off kill switch — with no origin bridge and no data path.

**Architecture:** A new `inc/mcp/mcp-remote-guard.php` owns the remote kill switch (fail-CLOSED on absence, inverting the read door's semantics), the remote slug list, and the three-gate predicate `sn_remote_analytics_allows()`. A new `inc/abilities-remote-analytics.php` registers `signal-noise/remote-get-analytics-summary` gated by a per-slug callback that passes its own literal slug. One narrow edit to `inc/mcp/mcp-read-guard.php` extends the existing rate ceiling to cover the remote slug's run route without letting the *read* kill switch darken it.

**Tech Stack:** PHP 8.3, WordPress Abilities API, no framework. Tests are standalone PHP fixture files with hand-rolled WP stubs, swept by `bash tests/run.sh`. No DB, no WP install.

**Spec:** [`remote-mcp-increment1-origin-half.md`](remote-mcp-increment1-origin-half.md)

**Baseline before starting:** `bash tests/run.sh` → `-- swept 424 suites, 16905 assertions passed, 1 skipped --`

---

## Orientation for someone new to this codebase

Read these three things before Task 1. They explain conventions that will otherwise look arbitrary.

**1. Tests are standalone scripts, not PHPUnit.** Each `tests/*.php` file defines its own WP function stubs, `require`s the `inc/` file under test, and calls a local `ok( $condition, $message )` helper. It prints `  ok  - msg` or `  FAIL - msg` and a summary line. `tests/run.sh` sweeps them all and gates on the **summary line**, never on the absence of FAIL — a suite that fatals prints no summary and would otherwise read as green.

**2. Door guards deliberately do not import each other.** `inc/mcp/mcp-read-guard.php`'s header states it never calls into `mcp-rw-guard.php`, and its kill-switch predicate is *duplicated* from the rw one rather than shared: "the isolation IS the design." The new remote guard follows the same rule — it mirrors, it does not import.

**3. Absence semantics are the whole point here.** `sn_mcp_read_enabled` defaults to **true** (untouched = the owner never turned it off). The new `sn_mcp_remote_enabled` defaults to **false** (untouched = the owner never turned it on). If you find yourself writing `get_option( SN_MCP_REMOTE_ENABLED_OPTION, true )`, you have inverted the security property this increment exists to establish.

**Use a function, not a `const` array, for the remote slug list.** A duplicate top-level `const` across files silently keeps the first loader-order value. `sn_mcp_remote_slugs()` mirrors the existing `sn_mcp_allowlist()`.

---

## File Structure

| File | Responsibility |
| --- | --- |
| **Create** `inc/mcp/mcp-remote-guard.php` | Remote kill switch (fail-closed), `sn_mcp_remote_slugs()`, `sn_remote_analytics_allows()`, run-route kill-switch dispatch |
| **Create** `inc/abilities-remote-analytics.php` | Registers `signal-noise/remote-get-analytics-summary` + its per-slug permission callback |
| **Modify** `inc/mcp/mcp-read-guard.php:179-192` | Extend `sn_mcp_read_guard_is_read_path()` so the ceiling covers remote slugs; kill-switch path untouched |
| **Modify** `signal-and-noise-tools.php` | Two `require_once` lines |
| **Create** `tests/mcp-remote-guard.php` | Switch semantics, three-gate predicate, fixture-ability scope stability |
| **Create** `tests/abilities-remote-analytics.php` | Registration shape, schema parity with the admin ability, permission matrix |
| **Modify** `tests/mcp-read-guard-run-route.php` | Ceiling covers the remote slug; the READ switch does **not** darken it |
| **Modify** `tests/mcp-capabilities.php` | Pin: the remote slug is absent from both allowlists |
| **Modify** `CHANGELOG.md` | `[Unreleased]` entry, no version bump |

---

## Task 1: The remote kill switch and its predicate

**Files:**
- Create: `inc/mcp/mcp-remote-guard.php`
- Test: `tests/mcp-remote-guard.php`

- [ ] **Step 1: Write the failing test**

Create `tests/mcp-remote-guard.php`:

```php
<?php
/**
 * Tests: the remote analytics door's kill switch FAILS CLOSED on absence.
 *
 * This is the inverse of every other switch in the plugin, and the inversion is
 * the security property. `sn_mcp_read_enabled` absent means "the owner never
 * turned it off" and the read door is open. `sn_mcp_remote_enabled` absent means
 * "the owner never turned it ON" and the remote door is shut.
 *
 * THE ASSERTION THAT MATTERS MOST: a caller holding the capability, asking for a
 * slug that IS on the remote list, is still refused when the option is absent.
 * If that ever passes, the remote surface ships live instead of shipping shut.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }

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

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';

$REMOTE = 'signal-noise/remote-get-analytics-summary';

echo "Group: the pure decision inverts the read door's absence semantics\n";
ok( true  === sn_mcp_remote_kill_switch_decision( false, false ), 'option off  -> door shut' );
ok( false === sn_mcp_remote_kill_switch_decision( false, true ),  'option on   -> door open' );
ok( true  === sn_mcp_remote_kill_switch_decision( true,  true ),  'constant beats an enabled option' );

echo "Group: the live switch defaults SHUT when the option was never written\n";
$GLOBALS['__options'] = array();
ok( true === sn_mcp_remote_kill_switch_engaged(), 'absent option -> engaged (fail CLOSED)' );
$GLOBALS['__options']['sn_mcp_remote_enabled'] = true;
ok( false === sn_mcp_remote_kill_switch_engaged(), 'option true -> not engaged' );

echo "Group: the slug list names exactly one member, and it is not on either MCP allowlist\n";
ok( array( $REMOTE ) === sn_mcp_remote_slugs(), 'the remote list holds exactly the one Increment 1 slug' );

echo "Group: all three gates must pass, and each alone is insufficient\n";
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === sn_remote_analytics_allows( $REMOTE ), 'switch on + capability + listed slug -> allowed' );

$GLOBALS['__options'] = array();
ok( false === sn_remote_analytics_allows( $REMOTE ), 'THE ONE THAT MATTERS: capability held, slug listed, switch ABSENT -> refused' );

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array();
ok( false === sn_remote_analytics_allows( $REMOTE ), 'switch on, capability absent -> refused' );

$GLOBALS['__caps'] = array( 'manage_options' => true );
ok( false === sn_remote_analytics_allows( $REMOTE ), 'a manage_options admin WITHOUT the remote capability -> refused' );

$GLOBALS['__caps'] = array( 'sn_read_remote_analytics' => true );
ok( false === sn_remote_analytics_allows( 'signal-noise/get-post-content' ), 'a corpus slug is not on the remote list -> refused' );
ok( false === sn_remote_analytics_allows( 'signal-noise/sn-apply' ), 'a write slug -> refused' );
ok( false === sn_remote_analytics_allows( '' ), 'an empty slug -> refused' );

echo "Group: SCOPE STABILITY — registering a new ability must not widen the remote surface\n";
// The owner's stated test obligation. A gate whose reach grows when someone
// registers an ability tomorrow is the exact failure this design exists to
// prevent, and this is the assertion that would catch it.
$fixture = 'signal-noise/fixture-registered-after-the-gate';
ok( false === sn_remote_analytics_allows( $fixture ), 'a brand-new ability slug is out of remote scope BY DEFAULT' );
ok( ! in_array( $fixture, sn_mcp_remote_slugs(), true ), 'and it did not appear on the remote list' );

echo "Group: the wp-config constant wins over an enabled option, LIVE and not only in the predicate\n";
// Defined last, because define() cannot be undone: every assertion after this
// point sees the constant. An attacker holding only a leaked credential can
// never flip a wp-config constant, which is why it is the strongest lever.
define( 'SN_MCP_REMOTE_DISABLED', true );
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === sn_mcp_remote_kill_switch_engaged(), 'the constant engages the switch despite the option being on' );
ok( false === sn_remote_analytics_allows( $REMOTE ), 'and the gate refuses a fully-credentialled caller' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-remote-guard.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-remote-guard.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-guard.php`
Expected: PHP fatal — `Failed to open stream: No such file or directory` for `inc/mcp/mcp-remote-guard.php`.

- [ ] **Step 3: Write the implementation**

Create `inc/mcp/mcp-remote-guard.php`:

```php
<?php
/**
 * Signal & Noise — remote analytics door guard (R3 §3D, Increment 1 origin half).
 *
 * The remote analytics MCP surface is reached by a caller who is NOT the owner's
 * laptop. Everything in this file exists because that changes which way a missing
 * input should be read.
 *
 * THE INVERSION, and it is deliberate: `sn_mcp_read_enabled` is fail-OPEN on
 * absence — an untouched option means "the owner never turned it off", and the
 * read door stays open. `sn_mcp_remote_enabled` is fail-CLOSED on absence — an
 * untouched option means "the owner never turned it ON", and the remote door
 * stays shut. The proposal requires this for any brokered path, and it is what
 * makes this increment safe to ship with no bridge behind it: the whole remote
 * surface is inert in production the day it lands, and the tests are what prove
 * it can be turned on deliberately.
 *
 * ISOLATION: this file never calls into mcp-read-guard.php or mcp-rw-guard.php,
 * and neither calls into it. The kill-switch predicate below is MIRRORED from
 * the read door's rather than shared, exactly as the read door mirrors the write
 * door's. Sharing would couple the doors at the one layer the split exists to
 * keep apart.
 *
 * WHAT THIS FILE DOES NOT DO: it does not rate limit. The remote path's F1
 * ceiling is the edge Durable Object, which is Worker-side and belongs to the
 * increment that builds the bridge. The origin's run-route ceiling is the
 * existing fail-OPEN one in mcp-read-guard.php, extended to cover remote slugs.
 * Do not read this increment as closing F1 — it closes the kill switch.
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owner-facing switch. ABSENT MEANS OFF — see this file's header. */
const SN_MCP_REMOTE_ENABLED_OPTION = 'sn_mcp_remote_enabled';

/**
 * The capability the remote principal holds. Never `manage_options`, and never
 * implied by a role that carries it. NOTHING is granted this capability by the
 * plugin — no role gains it at activation and no migration adds it. It exists to
 * be granted deliberately by the bridge increment.
 */
const SN_MCP_REMOTE_CAPABILITY = 'sn_read_remote_analytics';

/**
 * The slugs a remote principal may reach.
 *
 * A function rather than a `const` array on purpose: a duplicate top-level const
 * across two files silently keeps whichever value loaded first, which is a
 * failure mode no test would catch. Mirrors sn_mcp_allowlist()'s shape.
 *
 * These slugs are DELIBERATELY ABSENT from sn_mcp_allowlist(): the laptop read
 * door gains nothing from this increment.
 *
 * @return string[]
 */
function sn_mcp_remote_slugs() {
	return array(
		'signal-noise/remote-get-analytics-summary',
	);
}

/**
 * Remote kill-switch PURE predicate.
 *
 * @param bool $constant_disabled defined('SN_MCP_REMOTE_DISABLED') && SN_MCP_REMOTE_DISABLED.
 * @param bool $option_enabled    The sn_mcp_remote_enabled option's value (default FALSE).
 * @return bool True when the remote door must be treated as disabled.
 */
function sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the remote door disabled right now?
 *
 * The `false` default on get_option() is the fail-closed half. Changing it to
 * `true` to "match the read door" would silently ship the remote surface open.
 *
 * @return bool
 */
function sn_mcp_remote_kill_switch_engaged() {
	$constant_disabled = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$option_enabled    = function_exists( 'get_option' )
		? (bool) get_option( SN_MCP_REMOTE_ENABLED_OPTION, false )
		: false;
	return sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled );
}

/**
 * The remote gate: switch, then capability, then slug membership. All three.
 *
 * ORDER MATTERS. The switch is first so that "closed" beats "you hold the
 * capability" — the same precedence the read guard achieves by running its kill
 * switch at rest_pre_dispatch priority 10 and its ceiling at 11.
 *
 * The slug arrives as a literal from a per-slug callback rather than being
 * resolved from ambient request state. An ambient resolver hands a NESTED inner
 * gate the OUTER slug, so an ability executing another ability would approve the
 * inner one against a name that was never its own — and it would look healthy,
 * because the membership check passed.
 *
 * @param string $slug The calling ability's own slug, passed as a literal.
 * @return bool
 */
function sn_remote_analytics_allows( $slug ) {
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( SN_MCP_REMOTE_CAPABILITY ) ) {
		return false;
	}
	return is_string( $slug ) && '' !== $slug && in_array( $slug, sn_mcp_remote_slugs(), true );
}

/**
 * Make the remote kill switch cover the remote slugs' native run routes.
 *
 * Without this, a remote slug held off the read allowlist would reach
 * POST /wp-abilities/v1/abilities/<slug>/run with no kill switch consulted at
 * all — which is the same single-route gap F2 was closed to eliminate, rebuilt
 * one increment later on a path that matters more.
 *
 * Route parsing is duplicated from mcp-read-guard.php rather than imported, per
 * this file's isolation rule.
 *
 * @param mixed $result  Pre-dispatch result; non-null means someone answered.
 * @param mixed $server  Unused.
 * @param mixed $request The REST request.
 * @return mixed Null to continue, or WP_Error to refuse.
 */
function sn_mcp_remote_guard_run_route( $result, $server = null, $request = null ) {
	if ( null !== $result ) {
		return $result;
	}
	if ( ! sn_mcp_remote_kill_switch_engaged() ) {
		return $result;
	}
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return $result;
	}
	if ( ! in_array( (string) $m[1], sn_mcp_remote_slugs(), true ) ) {
		return $result;
	}
	return new WP_Error(
		'sn_mcp_remote_disabled',
		__( 'The remote analytics door is currently disabled.', 'signal-and-noise-tools' ),
		array( 'status' => 403 )
	);
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_remote_guard_run_route', 10, 3 );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-guard.php`
Expected: `OK (22 passed, 0 failed): mcp-remote-guard.php`

(3 + 2 + 1 + 7 + 2 + 5 + 2 across the seven groups. If your count differs, you changed the test — reconcile before moving on rather than editing the expected number.)

The 5-assertion run-route group was added after this plan was first written: as drafted, `sn_mcp_remote_guard_run_route()` was the one control in the file that nothing asserted, and Task 4 covers the READ guard's dispatcher on remote slugs rather than this one. A kill switch no test exercises is indistinguishable from one that does not work. The group must sit BEFORE the constant group, because `define()` cannot be undone and the "switch open" half of its assertions would otherwise be unreachable.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-guard.php tests/mcp-remote-guard.php
git commit -m "feat: the remote analytics door gets a switch that ships shut

Every other kill switch in the plugin is fail-open on absence, because an
untouched option means the owner never turned the door off. This one inverts
that: an untouched sn_mcp_remote_enabled means the owner never turned it ON.

The inversion is the security property, not a style choice. It is what lets
the origin permission boundary ship before the bridge exists without shipping
a live remote surface, and the test that matters asserts a caller holding the
capability and naming a listed slug is STILL refused when the option is absent.

The gate takes its slug as a literal from a per-slug callback rather than
resolving it from the request. An ambient resolver hands a nested inner gate
the outer slug, and the membership check passes while guarding the wrong name."
```

---

## Task 2: The remote ability and its per-slug callback

**Files:**
- Create: `inc/abilities-remote-analytics.php`
- Test: `tests/abilities-remote-analytics.php`
- Read for reference (do NOT modify): `inc/abilities-analytics.php:60-120`

- [ ] **Step 1: Write the failing test**

Create `tests/abilities-remote-analytics.php`:

```php
<?php
/**
 * Tests: the remote analytics ability is a SEPARATE slug, gated ONLY by the
 * remote callback, and absent from the laptop read door.
 *
 * The alternative was a union callback on the existing get-analytics-summary
 * (manage_options OR remote scope). A union can only add allow paths, so it
 * could not break the admin caller — but it would put remote logic inside an
 * admin-facing gate, where widening the remote branch later widens an admin
 * surface too. A separate slug keeps snt_ability_perm_manage_options untouched.
 *
 * THE ASSERTION THAT MATTERS MOST is the negative one: the remote slug must not
 * appear on sn_mcp_allowlist(). If it does, this increment quietly handed the
 * laptop door a tool it was never meant to gain.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }

$GLOBALS['__caps'] = array();
function current_user_can( $c ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// Capture registrations instead of standing up the Abilities API.
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
$GLOBALS['__actions'] = array();
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/abilities-remote-analytics.php';

// Fire the registration hook the plugin would fire.
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$REMOTE = 'signal-noise/remote-get-analytics-summary';
$ADMIN  = 'signal-noise/get-analytics-summary';

echo "Group: the remote ability registered, and it is its own slug\n";
ok( isset( $GLOBALS['__abilities'][ $REMOTE ] ), 'the remote ability registered' );
ok( $REMOTE !== $ADMIN, 'it is a different slug from the admin ability' );

$reg = $GLOBALS['__abilities'][ $REMOTE ];

echo "Group: it is gated ONLY by the remote callback\n";
ok( 'snt_ability_perm_remote_analytics_summary' === $reg['permission_callback'], 'permission_callback is the remote per-slug callback' );
ok( 'snt_ability_perm_manage_options' !== $reg['permission_callback'], 'and is NOT the manage_options helper' );

echo "Group: it delegates to the SAME execute callback — one implementation, two doors\n";
ok( 'sn_ability_get_analytics_summary' === $reg['execute_callback'], 'execute_callback is the existing analytics reader' );

echo "Group: THE NEGATIVE ONE — the laptop read door gains nothing\n";
ok( ! in_array( $REMOTE, sn_mcp_allowlist(), true ), 'the remote slug is ABSENT from the MCP read allowlist' );
ok( ! in_array( $REMOTE, sn_mcp_rw_allowlist(), true ), 'and absent from the write allowlist' );
ok( in_array( $ADMIN, sn_mcp_allowlist(), true ), 'while the admin analytics slug is still on the read allowlist (unchanged)' );

echo "Group: the per-slug callback passes its OWN literal, and honours all three gates\n";
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'sn_read_remote_analytics' => true );
ok( true === snt_ability_perm_remote_analytics_summary(), 'switch on + capability -> allowed' );

$GLOBALS['__options'] = array();
ok( false === snt_ability_perm_remote_analytics_summary(), 'switch absent -> refused (fail closed reaches the callback)' );

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$GLOBALS['__caps']    = array( 'manage_options' => true );
ok( false === snt_ability_perm_remote_analytics_summary(), 'a manage_options admin without the remote capability -> refused' );

echo "Group: the ability is annotated read-only, like its admin twin\n";
ok( true === $reg['meta']['annotations']['readonly'], 'annotated readonly' );
ok( true === $reg['meta']['annotations']['idempotent'], 'annotated idempotent' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): abilities-remote-analytics.php\n"
	: "\nFAILURES ($pass passed, $fail failed): abilities-remote-analytics.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/abilities-remote-analytics.php`
Expected: PHP fatal — no such file `inc/abilities-remote-analytics.php`.

- [ ] **Step 3: Write the implementation**

Create `inc/abilities-remote-analytics.php`:

```php
<?php
/**
 * Signal & Noise — the remote analytics ability (R3 §3D, Increment 1 origin half).
 *
 * ONE slug, `signal-noise/remote-get-analytics-summary`, sharing the existing
 * `sn_ability_get_analytics_summary` reader and gated ONLY by the remote
 * callback below.
 *
 * WHY A SEPARATE SLUG rather than a union callback on the existing
 * get-analytics-summary. A union (manage_options OR remote scope) can only ever
 * ADD allow paths, so a bug in the remote branch could not break the admin
 * caller. But it would place remote logic inside an admin-facing gate, and any
 * future widening of the remote branch would widen an admin surface with it.
 * Registering separately keeps `snt_ability_perm_manage_options` on the existing
 * ability byte-identical — the same isolation mcp-read-guard.php keeps from
 * mcp-rw-guard.php.
 *
 * THIS SLUG IS DELIBERATELY OFF sn_mcp_allowlist(). The laptop read door already
 * reaches get-analytics-summary with an application password; it gains nothing
 * here, and a test pins that absence.
 *
 * The schemas below are duplicated from inc/abilities-analytics.php rather than
 * extracted from it, because extracting would modify the registration this
 * increment promises to leave unchanged. A parity test pins the two in step, so
 * the duplication cannot drift silently.
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callback for `signal-noise/remote-get-analytics-summary`.
 *
 * Passes its own slug as a LITERAL. A permission callback is handed only the
 * ability's arguments (`$ability->check_permissions( $args )` in
 * inc/mcp/mcp-tools.php), never its own name, so a shared callback would have to
 * infer the slug from ambient request state — and would infer wrongly whenever
 * one ability executes another.
 *
 * @return bool
 */
function snt_ability_perm_remote_analytics_summary() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-analytics-summary' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/remote-get-analytics-summary', array(
		'label'               => 'Get analytics summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-analytics-summary. '
			. 'Returns range analytics totals (range: 7|14|30|90|365|all, class: human|suspect|bot). '
			. 'Read-only. Identical response contract to the admin ability — see that ability\'s '
			. 'description for every denominator and its traps. Reachable only by a principal '
			. 'holding the sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_analytics_summary',
		'execute_callback'    => 'sn_ability_get_analytics_summary',
		'input_schema'        => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
				'class' => array( 'type' => 'string', 'default' => 'human' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'views'                     => array( 'type' => 'integer' ),
				'unique_visitor_days'       => array( 'type' => array( 'integer', 'null' ) ),
				'pageview_visits'           => array( 'type' => array( 'integer', 'null' ) ),
				'viewless_visits'           => array( 'type' => array( 'integer', 'null' ) ),
				'view_visit_ratio'          => array( 'type' => array( 'number', 'null' ) ),
				'pageviews_per_visitor_day' => array( 'type' => array( 'number', 'null' ) ),
				'scroll_avg_per_view'       => array( 'type' => array( 'number', 'null' ) ),
				'time_avg_per_view'         => array( 'type' => array( 'number', 'null' ) ),
				'scroll_avg_per_visit'      => array( 'type' => array( 'number', 'null' ) ),
				'time_avg_per_visit'        => array( 'type' => array( 'number', 'null' ) ),
				'integrity_violation'       => array( 'type' => 'boolean' ),
				'exact_metrics_since'       => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/abilities-remote-analytics.php`
Expected: `OK (13 passed, 0 failed): abilities-remote-analytics.php`

(2 + 2 + 1 + 3 + 3 + 2 across the six groups.)

- [ ] **Step 5: Commit**

```bash
git add inc/abilities-remote-analytics.php tests/abilities-remote-analytics.php
git commit -m "feat: the remote analytics ability is its own slug, not a wider admin one

A union callback on get-analytics-summary (manage_options OR remote scope)
would have been fewer moving parts, and could only ever ADD allow paths — so
it could not break the admin caller. It would still have put remote logic
inside an admin-facing gate, where widening the remote branch later widens an
admin surface with it.

Registering signal-noise/remote-get-analytics-summary separately keeps
snt_ability_perm_manage_options byte-identical, shares the one reader
implementation, and keeps the new slug OFF sn_mcp_allowlist() — pinned by the
negative assertion, because the laptop door gaining a tool silently is the way
this increment could go wrong without anything looking broken."
```

---

## Task 3: Schema parity between the two abilities

**Files:**
- Modify: `tests/abilities-remote-analytics.php` (append before the summary block)

The two registrations duplicate their schemas. Duplication is fine; **silent drift** is not. This pins them.

- [ ] **Step 1: Write the failing test**

In `tests/abilities-remote-analytics.php`, insert this **immediately before** the `echo ( 0 === $fail )` summary block:

```php
echo "Group: SCHEMA PARITY — the duplicated schemas may not drift apart\n";
// The remote registration copies its schemas rather than extracting shared ones,
// because extracting would modify the admin registration this increment promises
// to leave unchanged. That trade is only safe while something notices drift.
require_once __DIR__ . '/../inc/abilities-analytics.php';
$GLOBALS['__abilities'] = array();
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }

$r = $GLOBALS['__abilities'][ $REMOTE ];
$a = $GLOBALS['__abilities'][ $ADMIN ];

ok( $r['input_schema'] === $a['input_schema'], 'input_schema is identical to the admin ability\'s' );
ok( $r['output_schema'] === $a['output_schema'], 'output_schema is identical to the admin ability\'s' );
ok( $r['execute_callback'] === $a['execute_callback'], 'both dispatch to the same reader' );
ok( $r['permission_callback'] !== $a['permission_callback'], 'but their gates are different — that is the whole point' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/abilities-remote-analytics.php`
Expected: FAIL on at least one parity line if the schemas were transcribed imperfectly in Task 2. If all four pass immediately, that is a valid green — the transcription was exact. Confirm by deliberately deleting the `integrity_violation` key from the remote `output_schema`, re-running to see `FAIL - output_schema is identical`, then restoring it.

- [ ] **Step 3: Fix any transcription drift**

If a parity assertion failed, correct `inc/abilities-remote-analytics.php` so its schema matches `inc/abilities-analytics.php` exactly. Do **not** modify `inc/abilities-analytics.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/abilities-remote-analytics.php`
Expected: `OK (17 passed, 0 failed): abilities-remote-analytics.php` (13 + 4 parity)

- [ ] **Step 5: Commit**

```bash
git add tests/abilities-remote-analytics.php inc/abilities-remote-analytics.php
git commit -m "test: the two analytics registrations may share a reader but not drift

The remote ability copies its schemas instead of extracting shared ones,
because extracting would edit the admin registration this increment promises
to leave byte-identical. That trade is only defensible while something notices
when the copies diverge — an output_schema that gains a field on one side and
not the other would hand two callers two different contracts for one reader.

Pins input_schema, output_schema and the shared execute_callback as identical,
and the permission callbacks as different."
```

---

## Task 4: Extend the rate ceiling to the remote run route

The remote slug is off the read allowlist, so `sn_mcp_read_guard_is_read_path()` currently returns `false` for it and **no ceiling applies**. This extends the ceiling without letting the *read* kill switch darken the remote slug — those are two different functions and only one changes.

**Files:**
- Modify: `inc/mcp/mcp-read-guard.php:179-192`
- Test: `tests/mcp-read-guard-run-route.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-read-guard-run-route.php`, **immediately before** its summary block:

```php
echo "Group: the ceiling covers remote slugs, but the READ switch does not darken them\n";
// The remote slug is deliberately off sn_mcp_allowlist(). Left alone, that means
// its run route gets no ceiling at all. The ceiling is about LOAD, so extending
// it is right; the kill switch is about AUTHORIZATION, and the remote slug has
// its own — one that fails CLOSED, unlike this one.
require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
$remote_slug  = sn_mcp_remote_slugs()[0];
$remote_route = '/wp-abilities/v1/abilities/' . $remote_slug . '/run';

ok( true === sn_mcp_read_guard_is_read_path( $remote_route ), 'the remote run route IS on the read path, so the ceiling reaches it' );

// Engage the READ kill switch and confirm it does not answer for the remote slug.
$GLOBALS['__options']['sn_mcp_read_enabled'] = false;
ok( true === sn_mcp_read_kill_switch_engaged(), 'the read switch is engaged for this check' );
ok( null === sn_mcp_read_guard_run_route( null, null, new RG_Req( $remote_route ) ), 'THE NEGATIVE ONE: the READ kill switch does not darken a remote slug' );
ok( is_wp_error( sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) ) ), 'while it still darkens a genuine read-allowlist slug' );
$GLOBALS['__options']['sn_mcp_read_enabled'] = true;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-read-guard-run-route.php`
Expected: `FAIL - the remote run route IS on the read path, so the ceiling reaches it` (the other three pass already).

- [ ] **Step 3: Write the implementation**

In `inc/mcp/mcp-read-guard.php`, replace the body of `sn_mcp_read_guard_is_read_path()` (currently lines 179-192) with:

```php
function sn_mcp_read_guard_is_read_path( $route ) {
	$route = (string) $route;
	$ns    = function_exists( 'sn_mcp_namespace' )
		? sn_mcp_namespace()
		: ( defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1' );
	if ( '/' . $ns . '/mcp' === $route ) {
		return true;
	}
	$slug = sn_mcp_read_guard_route_slug( $route );
	if ( '' === $slug ) {
		return false;
	}
	// The remote analytics slugs are deliberately OFF sn_mcp_allowlist() — the
	// laptop door must not gain them. But "off the exposure list" must not mean
	// "off the ceiling": left out, a remote slug's run route would have no rate
	// limit at all. A ceiling is about LOAD, and load is load whoever is asking.
	//
	// This is the ONLY read-guard function remote slugs enter. In particular
	// sn_mcp_read_guard_run_route() is untouched, so the READ kill switch — which
	// is fail-OPEN on absence — never answers for a remote slug. The remote door
	// has its own switch in mcp-remote-guard.php, and it fails CLOSED.
	if ( function_exists( 'sn_mcp_remote_slugs' ) && in_array( $slug, sn_mcp_remote_slugs(), true ) ) {
		return true;
	}
	if ( ! function_exists( 'sn_mcp_allowlist' ) ) {
		return false;
	}
	return in_array( $slug, sn_mcp_allowlist(), true );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-read-guard-run-route.php`
Expected: all groups pass, summary `OK (N passed, 0 failed): mcp-read-guard-run-route.php` with N four higher than before.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-read-guard.php tests/mcp-read-guard-run-route.php
git commit -m "fix: a remote slug off the read allowlist still gets a ceiling

Holding the remote analytics slug off sn_mcp_allowlist() is what stops the
laptop door gaining it. It also, unintentionally, held it off the rate ceiling:
sn_mcp_read_guard_is_read_path() gates on that same list, so the remote run
route would have dispatched with no limit at all — the single-route gap F2 was
closed to eliminate, rebuilt one increment later on a path that matters more.

Only the CEILING function learns about remote slugs. sn_mcp_read_guard_run_route
is untouched on purpose, so the read kill switch — fail-OPEN on absence — never
answers for a remote slug; the remote door has its own, which fails closed. The
negative assertion pins that separation.

This does NOT close F1 for the remote path. The ceiling reached here is the
existing fail-open one; the remote path's fail-closed counter is the edge
Durable Object, and it belongs to the increment that builds the bridge."
```

---

## Task 5: Pin the allowlist absence in the capabilities suite

**Files:**
- Modify: `tests/mcp-capabilities.php`

Task 2 already asserts the absence, but from the *remote* suite. Pinning it from the **allowlist's own** suite is what makes a future edit to `sn_mcp_allowlist()` red — someone widening that list will run the allowlist tests, not the remote ones.

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-capabilities.php`, immediately before its summary block:

```php
echo "Group: the remote analytics slugs never join the laptop door's lists\n";
// Pinned HERE, in the allowlist's own suite, and not only in the remote suite:
// whoever widens sn_mcp_allowlist() next will be running this file.
require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
foreach ( sn_mcp_remote_slugs() as $remote_slug ) {
	ok( ! in_array( $remote_slug, sn_mcp_allowlist(), true ), "$remote_slug is absent from the READ allowlist" );
	ok( ! in_array( $remote_slug, sn_mcp_rw_allowlist(), true ), "$remote_slug is absent from the WRITE allowlist" );
}
```

- [ ] **Step 2: Run test to verify it behaves**

Run: `php tests/mcp-capabilities.php`
Expected: PASS (2 new assertions). This pin is green from birth — it exists to red on a *future* edit. Prove it can red: temporarily add `'signal-noise/remote-get-analytics-summary',` to the array returned by `sn_mcp_allowlist()` in `inc/mcp/mcp-capabilities.php`, re-run, confirm `FAIL - … is absent from the READ allowlist`, then revert.

A pin never observed failing is not yet known to be a pin.

- [ ] **Step 3: Commit**

```bash
git add tests/mcp-capabilities.php
git commit -m "test: the allowlist's own suite pins the remote slugs' absence

The remote suite already asserts the remote slug is off sn_mcp_allowlist().
That assertion is in the wrong file to do its job: whoever widens the allowlist
next will be running the allowlist's tests, not the remote door's.

Verified this pin can red by temporarily adding the slug to the allowlist and
watching it fail, then reverting — a pin never observed failing is not yet
known to be a pin."
```

---

## Task 6: Load both files, run the full sweep, changelog

**Files:**
- Modify: `signal-and-noise-tools.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Wire the requires**

In `signal-and-noise-tools.php`, immediately **after** line 209 (`require_once SNT_PATH . 'inc/mcp/mcp-read-guard.php';`), add:

```php
require_once SNT_PATH . 'inc/mcp/mcp-remote-guard.php'; // R3 §3D Increment 1: remote analytics kill switch (fail-CLOSED on absence) — isolated from the read/rw guards by design.
```

Then, immediately **after** the line `require_once SNT_PATH . 'inc/abilities-analytics.php';` (currently line 429), add:

```php
require_once SNT_PATH . 'inc/abilities-remote-analytics.php'; // R3 §3D Increment 1: remote-scoped analytics ability, off the MCP allowlists by design.
```

Order matters: `mcp-remote-guard.php` defines `sn_remote_analytics_allows()`, which the ability's permission callback calls.

- [ ] **Step 2: Run the full sweep**

Run: `bash tests/run.sh`
Expected summary: `-- swept 426 suites, 16945 assertions passed, 1 skipped --`

The arithmetic, so a mismatch is diagnosable rather than mysterious:

| Source | New assertions |
| --- | --- |
| `tests/mcp-remote-guard.php` (new suite) | 22 |
| `tests/abilities-remote-analytics.php` (new suite) | 17 |
| `tests/mcp-read-guard-run-route.php` (Task 4) | 4 |
| `tests/mcp-capabilities.php` (Task 5) | 2 |
| **Total** | **45** |

Baseline was 424 suites / 16,905 assertions → expect **426 / 16,950**.

(`mcp-remote-guard.php` is 22 rather than the 17 originally planned: Task 1's dispatcher, `sn_mcp_remote_guard_run_route()`, was specified with no coverage at all, and five assertions were added to close that before Task 2 began. See commit `4d850c3`.)

If the total differs, find out why before proceeding — do not adjust the expected number to match what you got. Count assertions *added*, never the absence of FAIL: a suite that fatals prints no summary line and contributes nothing, which reads as a smaller total rather than as a failure.

If any suite is missing from the sweep, it fataled. `tests/run.sh` gates on the summary line precisely because a dead suite prints no FAIL.

- [ ] **Step 3: Lint and static analysis**

Run: `composer lint`
Expected: no errors on the two new files.

Run: `composer phpstan`
Expected: no new errors versus `phpstan-baseline.neon`. If PHPStan reports the new files, they belong in the run, not the baseline — fix the code.

- [ ] **Step 4: Add the CHANGELOG entry**

Under `## [Unreleased]` in `CHANGELOG.md` (create the heading if absent — note it is contended across sessions, so append rather than restructure):

```markdown
### Added
- **The remote analytics door gets its origin-side permission boundary (R3 §3D, Increment 1 origin half).** A dedicated `sn_read_remote_analytics` capability held by nothing, a per-slug permission callback that passes its own literal slug, and a separate `signal-noise/remote-get-analytics-summary` ability sharing the existing reader — all behind a kill switch that is **fail-CLOSED on absence**, inverting every other switch in the plugin. The remote surface therefore ships shut and stays shut until someone turns it on deliberately. No origin bridge, no data path, no version bump: nothing user-visible activates.

### Fixed
- **A slug held off the MCP read allowlist no longer escapes the rate ceiling.** `sn_mcp_read_guard_is_read_path()` gated on the read allowlist, so any run route for a non-allowlisted slug dispatched with no limit at all. The remote analytics slug now enters that one function — and only that one, so the read door's fail-OPEN kill switch never answers for a remote slug. This does not close F1 for the remote path; that counter lives at the edge.
```

- [ ] **Step 5: Commit**

```bash
git add signal-and-noise-tools.php CHANGELOG.md
git commit -m "chore: load the remote analytics guard and ability

Guard before ability: the permission callback calls sn_remote_analytics_allows(),
so the file defining it has to be required first.

Full sweep green. No version bump — nothing user-visible activates. The remote
surface is off by default, nothing holds its capability, and no bridge exists
to reach it."
```

---

## Task 7: Mutation verification

Every gate this increment adds should be proved to red for the *right* reason. Commit first — you will be editing source and reverting.

- [ ] **Step 1: Confirm a clean tree**

Run: `git status --short`
Expected: empty. If not, commit or stash before mutating.

- [ ] **Step 2: Mutate the kill switch**

In `inc/mcp/mcp-remote-guard.php`, delete these three lines from `sn_remote_analytics_allows()`:

```php
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
```

Run: `php tests/mcp-remote-guard.php`
Expected: `FAIL - THE ONE THAT MATTERS: capability held, slug listed, switch ABSENT -> refused`

Confirm the mutation actually applied — `git diff --stat` must show the change. A "no pins fired" result from an edit that never landed is a broken experiment, not a green one.

Revert: `git checkout inc/mcp/mcp-remote-guard.php`

- [ ] **Step 3: Mutate the default to fail-open**

In `inc/mcp/mcp-remote-guard.php`, change the default in `sn_mcp_remote_kill_switch_engaged()`:

```php
		? (bool) get_option( SN_MCP_REMOTE_ENABLED_OPTION, true )
```

(and the `: false;` fallback to `: true;`)

Run: `php tests/mcp-remote-guard.php`
Expected: `FAIL - absent option -> engaged (fail CLOSED)` **and** `FAIL - THE ONE THAT MATTERS: …`

This is the single most important mutation in the plan — it is the exact edit a future contributor would make while "making the remote switch consistent with the read switch."

Revert: `git checkout inc/mcp/mcp-remote-guard.php`

- [ ] **Step 4: Mutate the capability check**

Delete the `current_user_can` block from `sn_remote_analytics_allows()`.

Run: `php tests/mcp-remote-guard.php`
Expected: `FAIL - switch on, capability absent -> refused` and `FAIL - a manage_options admin WITHOUT the remote capability -> refused`

Revert: `git checkout inc/mcp/mcp-remote-guard.php`

- [ ] **Step 5: Mutate the slug membership check**

Change the final line of `sn_remote_analytics_allows()` to `return true;`.

Run: `php tests/mcp-remote-guard.php`
Expected: `FAIL - a brand-new ability slug is out of remote scope BY DEFAULT` (the scope-stability assertion — the owner's stated test obligation) plus the corpus and write-slug refusals.

Revert: `git checkout inc/mcp/mcp-remote-guard.php`

- [ ] **Step 6: Mutate the allowlist absence**

In `inc/mcp/mcp-capabilities.php`, add `'signal-noise/remote-get-analytics-summary',` to the array returned by `sn_mcp_allowlist()`.

Run: `php tests/mcp-capabilities.php` and `php tests/abilities-remote-analytics.php`
Expected: both red on their absence assertions.

Revert: `git checkout inc/mcp/mcp-capabilities.php`

- [ ] **Step 7: Confirm the tree is clean and the sweep is green**

Run: `git status --short && bash tests/run.sh | tail -3`
Expected: empty status, and the same summary line recorded in Task 6 Step 2.

- [ ] **Step 8: Record the results**

Append to `docs/proposals/remote-mcp-increment1-origin-half.md`, at the end:

```markdown
---

## Mutation verification (record actual results here when implemented)

| Mutation | Pins that red | Verified |
| --- | --- | --- |
| Remove the kill-switch check from `sn_remote_analytics_allows()` | switch-absent refusal | |
| Flip the `get_option()` default to `true` (fail-open) | absent-option engaged + switch-absent refusal | |
| Remove the capability check | capability-absent + admin-without-capability | |
| Replace slug membership with `return true` | scope-stability fixture + corpus + write slug | |
| Add the remote slug to `sn_mcp_allowlist()` | allowlist absence, in both suites | |
```

Fill the Verified column with the actual observed failing assertion names.

```bash
git add docs/proposals/remote-mcp-increment1-origin-half.md
git commit -m "docs: record which pin each mutation reds

A pin never observed failing is not yet known to be a pin. The fail-open
default mutation is the one that matters most — it is the exact edit someone
would make while making the remote switch 'consistent' with the read switch,
and the table records that two assertions catch it."
```

---

## Definition of done

- [ ] `bash tests/run.sh` green, suite count +2, assertion count up, zero failures
- [ ] `composer lint` and `composer phpstan` clean, nothing added to the PHPStan baseline
- [ ] All five mutations observed reddening their named pins, and reverted
- [ ] `inc/abilities-analytics.php` and `inc/abilities-permission-helpers.php` **unmodified** — verify with `git diff --stat origin/main -- inc/abilities-analytics.php inc/abilities-permission-helpers.php` (expect no output)
- [ ] `sn_mcp_allowlist()` membership unchanged — same check against `inc/mcp/mcp-capabilities.php` shows only the intended lines
- [ ] No version bump; `[Unreleased]` CHANGELOG entry present
- [ ] Nothing holds `sn_read_remote_analytics`; `sn_mcp_remote_enabled` is unwritten

## Explicitly NOT done by this plan

The Worker→origin bridge, binding α vs β, principal establishment, session identity in telemetry, phone-reachable revoke, and any change to the Worker repo. F1 for the remote path remains open and closes at the edge.
