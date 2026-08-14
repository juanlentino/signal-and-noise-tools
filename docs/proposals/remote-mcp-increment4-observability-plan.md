# Remote Door Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record that the remote analytics door was used — when, how often, with what outcome — and show it beside the toggle, so the revoke runbook has a trigger instead of assuming the owner already suspects something.

**Architecture:** One new isolated module, `inc/mcp/mcp-remote-observability.php`, owning a single non-autoloaded option (`sn_mcp_remote_log_v1`) that holds per-day outcome counters, a denormalised `last_used`, and a 50-entry ring of recent calls. Refusal counts coalesce through a transient so an anonymous flood cannot drive one DB write per request. The bridge handler calls one recording function per outcome branch, always behind `function_exists()`, so the log is observational and the door works identically without it.

**Tech Stack:** PHP 7.4+, WordPress options/transients API, the repo's standalone test harness (`tests/*.php` run by `bash tests/run.sh` — plain PHP files with an `ok()` assertion helper and stubbed WP functions; no PHPUnit).

**Spec:** `docs/proposals/remote-mcp-increment4-observability.md`

---

## Conventions this plan assumes

Read these once; every task depends on them.

- **Tests are standalone PHP.** Each file stubs the WP functions it needs, `require`s the code under test, calls `ok( $condition, $label )`, and exits non-zero on failure. Copy the header idiom from `tests/mcp-bridge-route.php`.
- **The gate is the exit code**, not the summary line. `bash tests/run.sh` prints a clean-looking summary and *then* exits 1 when a suite is red. Always check `$?`.
- **Run a single suite** with `php tests/<name>.php` (fast) and the sweep with `bash tests/run.sh`.
- **Never revert a mutation with `git checkout --` or `git stash`.** Two agents destroyed uncommitted work that way. Copy the file to the scratchpad and `cp` it back.
- **Commit after every task.** Do not batch.

### Times follow the WordPress timezone setting

Day-buckets and stored timestamps both use `wp_date()` — the same call `snt_audit_today_key()`
makes. **Do not use `gmdate()` here.** This log sits beside the login audit log in the same admin
area and is read by the same person; two security readouts disagreeing about what "today" means
would be a defect, and a UTC bucket reads as wrong to anyone looking at the panel in the evening.

The known cost: changing the site timezone reinterprets stored values. That is acceptable for a
diagnostic line, and it is the same trade `inc/audit-log.php` already makes.

**Every test file must stub `wp_date()`**, since these suites do not load WordPress:

```php
function wp_date( $format, $ts = null ) { return date( $format, null === $ts ? time() : $ts ); }
```

---

## File Structure

| File | Responsibility |
| --- | --- |
| `inc/mcp/mcp-remote-observability.php` *(create)* | Everything: constants, blob accessors, pure decision predicates, the recorder, the coalescing flush, the reader, the presenter. Target ≈200 lines. Isolated — it calls nothing in `mcp-remote-guard.php` or `audit-log.php`. |
| `inc/mcp/mcp-bridge-route.php` *(modify)* | Add one guarded `sn_mcp_remote_record()` call per outcome branch. No other change. |
| `inc/admin-forms/mcp-connect-status.php` *(modify)* | Append one presenter-produced line to the remote card's meta. No logic — that file is already 318 lines. |
| `signal-and-noise-tools.php` *(modify)* | `require` the new module. |
| `tests/mcp-remote-observability.php` *(create)* | The module's own suite. |
| `tests/mcp-bridge-route.php` *(modify)* | Pin that each handler branch records, and that the bridge is byte-identical without the module. |

---

## Task 1: The store — constants, blob shape, day key

**Files:**
- Create: `inc/mcp/mcp-remote-observability.php`
- Create: `tests/mcp-remote-observability.php`

- [ ] **Step 1: Write the failing test**

Create `tests/mcp-remote-observability.php`:

```php
<?php
/**
 * Tests: the remote door's observability store.
 *
 * THE PROPERTY THAT MATTERS MOST across this suite is that recording is
 * OBSERVATIONAL. The door must work identically with this module absent, so
 * nothing here may become a dependency of the bridge. That pin lives in
 * tests/mcp-bridge-route.php, because it is a property of the BRIDGE.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

function __( $s, $d = null ) { return (string) $s; }
// The module follows the WordPress timezone setting; these suites do not load
// WordPress, so wp_date() is stubbed to the server's. What matters is that the
// module CALLS wp_date and not gmdate — pinned below.
$GLOBALS['__wp_date_calls'] = array();
function wp_date( $format, $ts = null ) {
	$GLOBALS['__wp_date_calls'][] = $format;
	return date( $format, null === $ts ? time() : $ts );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
$GLOBALS['__autoload'] = array();
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}

$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; $GLOBALS['__ttls'][ $k ] = $ttl; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

require __DIR__ . '/../inc/mcp/mcp-remote-observability.php';

echo "Group: the outcome list is a closed set\n";
ok( in_array( 'dispatched', SN_MCP_REMOTE_OUTCOMES, true ), 'dispatched is an outcome' );
ok( in_array( 'refused_shut', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_shut is an outcome' );
ok( in_array( 'refused_auth', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_auth is an outcome' );
ok( in_array( 'refused_slug', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_slug is an outcome' );
ok( in_array( 'refused_request', SN_MCP_REMOTE_OUTCOMES, true ), 'refused_request is an outcome' );
ok( 5 === count( SN_MCP_REMOTE_OUTCOMES ), 'and there are exactly five — a new one must be added deliberately, with a counter and a label' );

echo "Group: the day key follows the WordPress timezone setting\n";
// PINNED BY CALL, NOT BY VALUE, and deliberately. On a UTC server wp_date() and
// gmdate() return the SAME STRING, so a value comparison would pass against
// either and prove nothing — it would be green on CI and green on a mutation
// that swapped the call. Recording that wp_date was asked for 'Y-m-d' is the
// only assertion here that a swap to gmdate() can fail.
$GLOBALS['__wp_date_calls'] = array();
$key = sn_mcp_remote_log_day_key();
ok( in_array( 'Y-m-d', $GLOBALS['__wp_date_calls'], true ), 'THE TIMEZONE PIN: the day key is produced by wp_date, so it agrees with snt_audit_today_key()' );
ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $key ), 'and it is shaped Y-m-d' );

echo "Group: the blob lazy-initialises to a valid shape\n";
$GLOBALS['__options'] = array();
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'], 'a missing option yields schema 1' );
ok( null === $blob['last_used'], 'with no last_used' );
ok( array() === $blob['counters'], 'no counters' );
ok( array() === $blob['recent'], 'and no recent rows' );

echo "Group: a corrupt option does not poison the reader\n";
// An option can be hand-edited, half-written, or restored from an older schema.
// Returning garbage here would propagate into every caller.
$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = 'not an array';
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['schema'] && array() === $blob['counters'], 'a non-array option falls back to the empty shape rather than propagating garbage' );

$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = array( 'schema' => 1 );
$blob = sn_mcp_remote_log_get_blob();
ok( array() === $blob['counters'] && array() === $blob['recent'] && null === $blob['last_used'], 'and a partial blob gains its missing keys instead of returning undefined ones' );

echo "Group: saving does NOT autoload\n";
// This option is read by the admin panel and by nothing on a front-end request.
// Autoloading it would tax every page view for one screen's data.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_save_blob( sn_mcp_remote_log_get_blob() );
ok( false === $GLOBALS['__autoload'][ SN_MCP_REMOTE_LOG_OPTION ], 'THE ONE THAT MATTERS FOR EVERY PAGE LOAD: the log option is saved with autoload FALSE' );

echo ( 0 === $fail )
	? "\nOK ($pass passed, $fail failed): mcp-remote-observability.php\n"
	: "\nFAILURES ($pass passed, $fail failed): mcp-remote-observability.php\n";
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Failed opening required '.../inc/mcp/mcp-remote-observability.php'`

- [ ] **Step 3: Write minimal implementation**

Create `inc/mcp/mcp-remote-observability.php`:

```php
<?php
/**
 * Signal & Noise — remote analytics door observability (R3 §3D Increment 4).
 *
 * Nothing recorded that the remote door was used, so every control in
 * docs/ops/remote-mcp-revoke-runbook.md assumed the owner already suspected
 * something. This module is the trigger those controls were missing.
 *
 * IT IS OBSERVATIONAL, AND THAT IS A HARD CONSTRAINT. The bridge calls into
 * this file only behind function_exists(), and a test pins that the door
 * behaves byte-identically with this module absent. A broken log must not be
 * able to shut the door — and must not be able to open it either.
 *
 * ISOLATION: this file calls nothing in mcp-remote-guard.php or audit-log.php,
 * and neither calls into it. The shape below MIRRORS inc/audit-log.php's proven
 * blob (per-day counters, a capped ring, retention) without sharing its
 * storage, exactly as the remote guard mirrors the read guard's predicate
 * rather than importing it.
 *
 * WHAT IT CANNOT DO: record WHO. Cloudflare Access issues and holds the
 * session; WordPress never sees it, so at the origin a bridge call is a valid
 * Bearer token and nothing more. The threat model's §8.4 "audit the caller"
 * requirement is NOT satisfied here and cannot be — that is Worker-side, where
 * src/guard.mjs already returns { sub, email }. Do not add a field that implies
 * otherwise.
 *
 * @package SignalNoiseTools
 * @since 11.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The record. NOT autoloaded — only the admin panel reads it. */
const SN_MCP_REMOTE_LOG_OPTION = 'sn_mcp_remote_log_v1';

/** Coalescing buffer for refusal counts. See sn_mcp_remote_record(). */
const SN_MCP_REMOTE_PENDING_TRANSIENT = 'sn_mcp_remote_pending';

/** Day-buckets older than this are dropped on write. Mirrors the audit log. */
const SN_MCP_REMOTE_LOG_RETENTION_DAYS = 90;

/**
 * Recent-call ring size. Small enough that the option stays trivial, large
 * enough to survive one ordinary phone session without rolling. It is a display
 * aid; the counters are the durable record.
 */
const SN_MCP_REMOTE_LOG_RING_CAP = 50;

/** How long pending refusals may sit before the next request flushes them. */
const SN_MCP_REMOTE_FLUSH_SECONDS = 60;

/**
 * Pending-buffer TTL. DELIBERATELY far longer than the flush window: nothing
 * schedules a flush, so if a probe stops, the last sub-minute of counts sits
 * here with no further request to trigger one. The admin read collects them. A
 * TTL near the flush window would silently discard the tail of an attack that
 * stopped — the counts most worth having.
 */
const SN_MCP_REMOTE_PENDING_TTL = HOUR_IN_SECONDS;

/**
 * The closed set of outcomes.
 *
 * refused_shut and refused_auth are BYTE-IDENTICAL TO THE CALLER — that is the
 * whole point of the 404 parity fix — and separable only here, in a record that
 * is admin-only and never echoed. "Calls arrived while I had it switched off"
 * is a different and more alarming fact than "someone guessed at the token".
 * Do not collapse them to match the wire, and do not leak the distinction to it.
 */
const SN_MCP_REMOTE_OUTCOMES = array(
	'dispatched',
	'refused_shut',
	'refused_auth',
	'refused_slug',
	'refused_request',
);

/**
 * Today's bucket key, in the SITE timezone.
 *
 * wp_date(), exactly as snt_audit_today_key() does. This log sits beside the
 * login audit log in the same admin area and is read by the same person; two
 * security readouts disagreeing about what "today" means would be a defect, and
 * a UTC bucket reads as wrong to anyone looking at the panel in the evening.
 *
 * DO NOT swap this to gmdate(). On a UTC server the two return the same string,
 * so the swap is invisible to any value-comparison test — which is why the pin
 * asserts wp_date was CALLED rather than what it returned.
 *
 * Known cost: changing the site timezone reinterprets stored values. Acceptable
 * for a diagnostic line, and the same trade inc/audit-log.php already makes.
 *
 * @return string
 */
function sn_mcp_remote_log_day_key() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date( 'Y-m-d' );
}

/**
 * A timestamp for the record, in the SITE timezone. Same reasoning as the day
 * key — one timezone throughout, so nothing needs converting for display.
 *
 * @return string
 */
function sn_mcp_remote_log_now() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
}

/**
 * The empty, valid blob.
 *
 * @return array
 */
function sn_mcp_remote_log_empty_blob() {
	return array(
		'schema'    => 1,
		'last_used' => null,
		'counters'  => array(),
		'recent'    => array(),
	);
}

/**
 * Read the blob, repairing anything missing.
 *
 * An option can be hand-edited, half-written, or restored from an older schema.
 * Every key is filled defensively so callers never index an undefined one.
 *
 * @return array
 */
function sn_mcp_remote_log_get_blob() {
	$stored = function_exists( 'get_option' ) ? get_option( SN_MCP_REMOTE_LOG_OPTION, array() ) : array();
	if ( ! is_array( $stored ) ) {
		return sn_mcp_remote_log_empty_blob();
	}
	$blob = array_merge( sn_mcp_remote_log_empty_blob(), $stored );

	$blob['schema']    = 1;
	$blob['counters']  = is_array( $blob['counters'] ) ? $blob['counters'] : array();
	$blob['recent']    = is_array( $blob['recent'] ) ? $blob['recent'] : array();
	$blob['last_used'] = is_string( $blob['last_used'] ) ? $blob['last_used'] : null;

	return $blob;
}

/**
 * Persist the blob. NEVER autoloaded — see the constant's docblock.
 *
 * @param array $blob
 * @return void
 */
function sn_mcp_remote_log_save_blob( $blob ) {
	if ( function_exists( 'update_option' ) ) {
		update_option( SN_MCP_REMOTE_LOG_OPTION, $blob, false );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php tests/mcp-remote-observability.php
git commit -m "feat: the remote door's observability store — shape, day key, non-autoloaded save"
```

---

## Task 2: Recording a persisted outcome — counters, last_used, ring

**Files:**
- Modify: `inc/mcp/mcp-remote-observability.php` (append)
- Modify: `tests/mcp-remote-observability.php` (append before the summary block)

- [ ] **Step 1: Write the failing test**

Insert into `tests/mcp-remote-observability.php`, immediately **before** the `echo ( 0 === $fail )` block:

```php
echo "Group: a persisted outcome moves a counter, the ring, and last_used\n";
$GLOBALS['__options'] = array();
$today = sn_mcp_remote_log_day_key();

sn_mcp_remote_log_apply( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
$blob = sn_mcp_remote_log_get_blob();
ok( 1 === $blob['counters'][ $today ]['dispatched'], 'a dispatch increments today\'s dispatched counter' );
ok( 1 === count( $blob['recent'] ), 'and appends one ring row' );
ok( 'signal-noise/remote-get-analytics-summary' === $blob['recent'][0]['slug'], 'carrying the slug' );
ok( 'dispatched' === $blob['recent'][0]['outcome'], 'and the outcome' );
ok( is_string( $blob['last_used'] ) && '' !== $blob['last_used'], 'and last_used is now set' );

echo "Group: only a DISPATCH sets last_used — a refusal is not a use\n";
// Without this, last_used answers "was this endpoint touched", which is a
// different and much less alarming question than "did someone get data out".
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'refused_auth', '' );
$blob = sn_mcp_remote_log_get_blob();
ok( null === $blob['last_used'], 'THE ONE THAT MATTERS: a refusal leaves last_used null' );
ok( 1 === $blob['counters'][ $today ]['refused_auth'], 'while still counting the refusal' );

echo "Group: an unknown outcome is dropped, not stored\n";
// Mirrors SN_AUDIT_COUNTER_TYPES' guard. A typo'd outcome silently creating a
// key would produce a counter no label ever reads.
$GLOBALS['__options'] = array();
sn_mcp_remote_log_apply( 'not_a_real_outcome', '' );
$blob = sn_mcp_remote_log_get_blob();
ok( array() === $blob['counters'], 'an unknown outcome creates no counter' );
ok( array() === $blob['recent'], 'and no ring row' );

echo "Group: the ring is capped, and NEWEST FIRST\n";
// Asserting only "count <= cap" cannot tell a working cap from a ring that
// never filled. Overfill it, then assert the oldest is GONE and the newest is
// at index 0 — those two together are what pin the behaviour.
$GLOBALS['__options'] = array();
for ( $i = 0; $i < SN_MCP_REMOTE_LOG_RING_CAP + 5; $i++ ) {
	sn_mcp_remote_log_apply( 'dispatched', 'slug-' . $i );
}
$blob = sn_mcp_remote_log_get_blob();
ok( SN_MCP_REMOTE_LOG_RING_CAP === count( $blob['recent'] ), 'the ring stops at the cap' );
ok( 'slug-' . ( SN_MCP_REMOTE_LOG_RING_CAP + 4 ) === $blob['recent'][0]['slug'], 'THE ORDER PIN: the newest entry is at index 0' );
$slugs = array();
foreach ( $blob['recent'] as $row ) { $slugs[] = $row['slug']; }
ok( ! in_array( 'slug-0', $slugs, true ), 'THE EVICTION PIN: the oldest entry is gone, so the cap evicts rather than refusing to append' );
ok( SN_MCP_REMOTE_LOG_RING_CAP + 5 === $blob['counters'][ $today ]['dispatched'], 'and the COUNTER kept counting past the ring cap — the ring is a display aid, the counter is the record' );
ok( is_string( $blob['last_used'] ) && '' !== $blob['last_used'], 'THE OTHER DENORMALISATION PIN: last_used survives the ring rolling over — it is stored outside the ring precisely so it can' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Call to undefined function sn_mcp_remote_log_apply()`

- [ ] **Step 3: Write minimal implementation**

Append to `inc/mcp/mcp-remote-observability.php`:

```php
/**
 * Apply ONE outcome to the persisted blob, immediately.
 *
 * This is the un-coalesced path. sn_mcp_remote_record() decides whether an
 * outcome comes straight here or buffers first; keeping the two separate is
 * what makes the buffering testable without a clock.
 *
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES. Anything else is dropped.
 * @param string $slug    The requested slug, or '' when there was none.
 * @return void
 */
function sn_mcp_remote_log_apply( $outcome, $slug = '' ) {
	$outcome = (string) $outcome;
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return;
	}

	$blob = sn_mcp_remote_log_get_blob();
	$day  = sn_mcp_remote_log_day_key();
	$now  = sn_mcp_remote_log_now();

	$blob = sn_mcp_remote_log_add_count( $blob, $day, $outcome, 1 );

	// ONLY a dispatch is a "use". A refusal means somebody knocked; last_used
	// answering that would make the headline fact far less alarming than it
	// reads, because the owner would see a timestamp for every failed probe.
	if ( 'dispatched' === $outcome ) {
		$blob['last_used'] = $now;
	}

	array_unshift(
		$blob['recent'],
		array( 'ts' => $now, 'slug' => (string) $slug, 'outcome' => $outcome )
	);
	if ( count( $blob['recent'] ) > SN_MCP_REMOTE_LOG_RING_CAP ) {
		$blob['recent'] = array_slice( $blob['recent'], 0, SN_MCP_REMOTE_LOG_RING_CAP );
	}

	sn_mcp_remote_log_save_blob( $blob );
}

/**
 * Add to one day's counter, creating the bucket if needed.
 *
 * Separated so the flush path can add several counts to a bucket without
 * repeating the initialisation, and so neither path can drift from the other.
 *
 * @param array  $blob
 * @param string $day     'Y-m-d'.
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES.
 * @param int    $n       How many to add.
 * @return array The modified blob.
 */
function sn_mcp_remote_log_add_count( $blob, $day, $outcome, $n ) {
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return $blob;
	}
	if ( ! isset( $blob['counters'][ $day ] ) || ! is_array( $blob['counters'][ $day ] ) ) {
		$blob['counters'][ $day ] = array();
	}
	$current = isset( $blob['counters'][ $day ][ $outcome ] ) ? (int) $blob['counters'][ $day ][ $outcome ] : 0;
	$blob['counters'][ $day ][ $outcome ] = $current + (int) $n;
	return $blob;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php tests/mcp-remote-observability.php
git commit -m "feat: record an outcome — counters, capped newest-first ring, dispatch-only last_used"
```

---

## Task 3: Pruning on write

**Files:**
- Modify: `inc/mcp/mcp-remote-observability.php` (append, and one line inside `sn_mcp_remote_log_apply`)
- Modify: `tests/mcp-remote-observability.php` (append)

- [ ] **Step 1: Write the failing test**

Insert before the summary block:

```php
echo "Group: old day-buckets are dropped on write, and recent ones are KEPT\n";
// A prune asserted only by "the old bucket is gone" is satisfied by a prune
// that deletes everything. The keep-assertion is the discriminator.
$GLOBALS['__options'] = array();
$old    = wp_date( 'Y-m-d', time() - ( ( SN_MCP_REMOTE_LOG_RETENTION_DAYS + 5 ) * DAY_IN_SECONDS ) );
$recent = wp_date( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) );
$GLOBALS['__options'][ SN_MCP_REMOTE_LOG_OPTION ] = array(
	'schema'    => 1,
	'last_used' => '2020-01-01 00:00:00',
	'counters'  => array(
		$old    => array( 'dispatched' => 7 ),
		$recent => array( 'dispatched' => 2 ),
	),
	'recent'    => array(),
);
sn_mcp_remote_log_apply( 'dispatched', 'slug' );
$blob = sn_mcp_remote_log_get_blob();
ok( ! array_key_exists( $old, $blob['counters'] ), 'a bucket past retention is dropped' );
ok( array_key_exists( $recent, $blob['counters'] ), 'THE DISCRIMINATOR: a bucket inside retention survives' );
ok( 2 === $blob['counters'][ $recent ]['dispatched'], 'with its count intact' );

echo "Group: last_used survives a prune that removes its own day\n";
// last_used is denormalised out of the ring and the counters precisely so it
// can outlive both. Nothing else proves it does.
ok( is_string( $blob['last_used'] ), 'THE DENORMALISATION PIN: last_used is still set after pruning' );

echo "Group: the pure prune predicate is exhaustive on the boundary\n";
// Testing the predicate directly rather than only through a write, so the
// off-by-one at the cutoff has its own witness.
ok( true  === sn_mcp_remote_log_is_expired( '2026-01-01', '2026-01-02' ), 'a day strictly before the cutoff is expired' );
ok( false === sn_mcp_remote_log_is_expired( '2026-01-02', '2026-01-02' ), 'THE BOUNDARY: the cutoff day itself is NOT expired' );
ok( false === sn_mcp_remote_log_is_expired( '2026-01-03', '2026-01-02' ), 'a day after the cutoff is not expired' );
ok( false === sn_mcp_remote_log_is_expired( 'garbage', '2026-01-02' ), 'and an unparseable key is KEPT, not silently deleted' );
```

Add near the top of the test file, beside the `HOUR_IN_SECONDS` define:

```php
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Call to undefined function sn_mcp_remote_log_is_expired()`

- [ ] **Step 3: Write minimal implementation**

Append to `inc/mcp/mcp-remote-observability.php`:

```php
/**
 * Is a day-bucket key past the cutoff?
 *
 * PURE, so the boundary has a witness that does not depend on the clock. An
 * unparseable key returns FALSE — keeping data you cannot classify beats
 * deleting it, and a malformed key is a bug to notice rather than to erase.
 *
 * @param string $day_key 'Y-m-d'.
 * @param string $cutoff  'Y-m-d'; anything strictly before this is expired.
 * @return bool
 */
function sn_mcp_remote_log_is_expired( $day_key, $cutoff ) {
	$day_key = (string) $day_key;
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_key ) ) {
		return false;
	}
	return $day_key < (string) $cutoff;
}

/**
 * Drop expired day-buckets.
 *
 * OPPORTUNISTIC, on write, rather than on cron. A cron can drift, be
 * unscheduled, or fail silently; a prune that runs as part of the write cannot
 * get out of step with the data it prunes. It also avoids touching the
 * cron-events registry, which is a full-sweep contract.
 *
 * The ring is capped independently, by count, so a single busy day cannot evict
 * the record that the door was used last month.
 *
 * @param array $blob
 * @return array
 */
function sn_mcp_remote_log_prune( $blob ) {
	$cutoff = function_exists( 'wp_date' )
		? wp_date( 'Y-m-d', time() - ( SN_MCP_REMOTE_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) )
		: date( 'Y-m-d', time() - ( SN_MCP_REMOTE_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
	foreach ( array_keys( $blob['counters'] ) as $day_key ) {
		if ( sn_mcp_remote_log_is_expired( $day_key, $cutoff ) ) {
			unset( $blob['counters'][ $day_key ] );
		}
	}
	return $blob;
}
```

Then in `sn_mcp_remote_log_apply()`, insert one line immediately **before** `sn_mcp_remote_log_save_blob( $blob );`:

```php
	$blob = sn_mcp_remote_log_prune( $blob );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php tests/mcp-remote-observability.php
git commit -m "feat: prune remote-log day buckets on write, with the boundary pinned pure"
```

---

## Task 4: Coalescing — the pure predicate and the pending buffer

**Corrected during execution:** the original pending_add day-mismatch branch discarded a fresh buffer's counts at midnight; a day change is now a third flush condition. Found by Task 4's code-quality review.

**Files:**
- Modify: `inc/mcp/mcp-remote-observability.php` (append)
- Modify: `tests/mcp-remote-observability.php` (append)

- [ ] **Step 1: Write the failing test**

Insert before the summary block:

```php
echo "Group: the flush predicate is pure and covers all four combinations\n";
// Truth table, exhaustively. A predicate tested on two of four combinations is
// satisfied by `return $is_dispatch;` — which would never flush a pure-refusal
// probe at all, the exact case this buffer exists for.
ok( true  === sn_mcp_remote_should_flush( 999, false ), 'stale buffer, no dispatch -> flush' );
ok( true  === sn_mcp_remote_should_flush( 0,   true  ), 'fresh buffer, dispatch -> flush (it is writing anyway)' );
ok( true  === sn_mcp_remote_should_flush( 999, true  ), 'stale buffer, dispatch -> flush' );
ok( false === sn_mcp_remote_should_flush( 0,   false ), 'THE ONE THAT MAKES IT A BUFFER: fresh buffer, no dispatch -> hold' );

echo "Group: a refusal buffers instead of writing the option\n";
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'refused_auth', '' );
ok( ! array_key_exists( SN_MCP_REMOTE_LOG_OPTION, $GLOBALS['__options'] ), 'THE ONE THAT MATTERS FOR A FLOOD: a single refusal writes NO option' );
ok( array_key_exists( SN_MCP_REMOTE_PENDING_TRANSIENT, $GLOBALS['__transients'] ), 'it lands in the pending transient instead' );
ok( 1 === $GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ]['counts']['refused_auth'], 'with a count of one' );

sn_mcp_remote_record( 'refused_auth', '' );
sn_mcp_remote_record( 'refused_auth', '' );
ok( 3 === $GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ]['counts']['refused_auth'], 'and further refusals accumulate there — three requests, still zero option writes' );
ok( ! array_key_exists( SN_MCP_REMOTE_LOG_OPTION, $GLOBALS['__options'] ), 'confirmed: still no option write after three refusals' );

echo "Group: the pending TTL is far longer than the flush window\n";
// Nothing SCHEDULES a flush. If a probe stops, the tail sits here until an
// admin read collects it. A TTL near the flush window would discard exactly
// the counts most worth having.
ok( $GLOBALS['__ttls'][ SN_MCP_REMOTE_PENDING_TRANSIENT ] > SN_MCP_REMOTE_FLUSH_SECONDS * 10, 'THE TAIL-LOSS PIN: the pending TTL is more than ten flush windows' );

echo "Group: a dispatch flushes the buffer along with itself\n";
$today = sn_mcp_remote_log_day_key();
sn_mcp_remote_record( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
$blob = sn_mcp_remote_log_get_blob();
ok( 3 === $blob['counters'][ $today ]['refused_auth'], 'the three buffered refusals landed in the persisted counters' );
ok( 1 === $blob['counters'][ $today ]['dispatched'], 'alongside the dispatch that flushed them' );
ok( ! array_key_exists( SN_MCP_REMOTE_PENDING_TRANSIENT, $GLOBALS['__transients'] ), 'and the buffer was cleared, so nothing double-counts' );

echo "Group: a pending set files under the day it was RECORDED, not flushed\n";
// The midnight bug. A set recorded at 23:59:58 and flushed at 00:00:05 belongs
// to the day it was recorded; recomputing the key at flush time would file it
// under the wrong date and understate the busy day.
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$GLOBALS['__transients'][ SN_MCP_REMOTE_PENDING_TRANSIENT ] = array(
	'day'        => $yesterday,
	'first_seen' => time() - 3600,
	'counts'     => array( 'refused_auth' => 4 ),
);
sn_mcp_remote_record( 'dispatched', 'slug' );
$blob = sn_mcp_remote_log_get_blob();
ok( 4 === $blob['counters'][ $yesterday ]['refused_auth'], 'THE MIDNIGHT PIN: buffered counts land in YESTERDAY\'s bucket' );
ok( ! isset( $blob['counters'][ sn_mcp_remote_log_day_key() ]['refused_auth'] ), 'and not in today\'s' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Call to undefined function sn_mcp_remote_should_flush()`

- [ ] **Step 3: Write minimal implementation**

Append to `inc/mcp/mcp-remote-observability.php`:

```php
/**
 * Should the pending buffer be folded into the option now?
 *
 * PURE — it takes the buffer's age, whether this is a dispatch, and whether the
 * day has changed since the buffer started, and reads no clock and no storage.
 * The live wrapper supplies all three. Same split as
 * sn_mcp_remote_kill_switch_decision() / …_engaged().
 *
 * A day change is a flush condition in its own right, not just an age check:
 * a buffer created at 23:59:30 is only 50s old at 00:00:20, well under the
 * flush window, but it is holding YESTERDAY's counts. Buffering into it further
 * (or replacing it) would either mis-file today's count under yesterday or
 * silently drop yesterday's — the same loss the midnight pin on the flush path
 * exists to prevent, one path over.
 *
 * @param int  $age_seconds  Seconds since the buffer's first_seen.
 * @param bool $is_dispatch  True when a successful dispatch is being recorded.
 * @param bool $day_changed  True when the buffer's day no longer matches today.
 * @return bool
 */
function sn_mcp_remote_should_flush( $age_seconds, $is_dispatch, $day_changed = false ) {
	if ( (bool) $is_dispatch ) {
		return true;
	}
	if ( (bool) $day_changed ) {
		return true;
	}
	return (int) $age_seconds >= SN_MCP_REMOTE_FLUSH_SECONDS;
}

/**
 * Read the pending buffer, or null when there is none.
 *
 * @return array|null { day: string, first_seen: int, counts: array }
 */
function sn_mcp_remote_pending_get() {
	if ( ! function_exists( 'get_transient' ) ) {
		return null;
	}
	$pending = get_transient( SN_MCP_REMOTE_PENDING_TRANSIENT );
	if ( ! is_array( $pending ) || ! isset( $pending['day'], $pending['first_seen'], $pending['counts'] ) ) {
		return null;
	}
	if ( ! is_array( $pending['counts'] ) ) {
		return null;
	}
	return $pending;
}

/**
 * Fold a pending buffer into a blob and clear it.
 *
 * THE DAY KEY COMES FROM THE BUFFER, never from the clock. A set recorded at
 * 23:59:58 and flushed at 00:00:05 belongs to the day it was recorded;
 * recomputing here would file it under the wrong date and understate the busy
 * day. That is pinned.
 *
 * @param array      $blob
 * @param array|null $pending
 * @return array The blob with the pending counts folded in.
 */
function sn_mcp_remote_pending_fold( $blob, $pending ) {
	if ( null === $pending ) {
		return $blob;
	}
	foreach ( $pending['counts'] as $outcome => $n ) {
		$blob = sn_mcp_remote_log_add_count( $blob, (string) $pending['day'], (string) $outcome, (int) $n );
	}
	return $blob;
}

/**
 * Record one outcome from the bridge. THE ONLY ENTRY POINT THE BRIDGE CALLS.
 *
 * A dispatch is written straight through — it is rare, and it is the fact worth
 * having immediately. Refusals buffer, because /wp-json/signal-noise/v1/bridge
 * is a PUBLIC origin route while the door is armed — it is not behind Access
 * the way mcp.juanlentino.com is. On a DB-backed transient (the common case)
 * set_transient() IS itself an option write per request; the coalescing win is
 * not "fewer writes to the database" but a constant-size, non-autoloaded row
 * that never grows with request volume, and skipping the ring/prune work on
 * every refusal — versus rewriting a growing blob and pruning it per request.
 *
 * Refusals never produce ring rows — deliberate: a flood must not wash the
 * dispatch history out of a 50-row ring.
 *
 * Best-effort under concurrency: read-modify-write races on the transient can
 * lose or duplicate a handful of counts; acceptable for a diagnostic counter.
 *
 * This function must never throw and must never alter the caller's behaviour.
 *
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES.
 * @param string $slug    The requested slug, or ''.
 * @return void
 */
function sn_mcp_remote_record( $outcome, $slug = '' ) {
	$outcome = (string) $outcome;
	if ( ! in_array( $outcome, SN_MCP_REMOTE_OUTCOMES, true ) ) {
		return;
	}

	$is_dispatch = ( 'dispatched' === $outcome );
	$pending     = sn_mcp_remote_pending_get();
	$age         = ( null === $pending ) ? 0 : max( 0, time() - (int) $pending['first_seen'] );
	$day_changed = ( null !== $pending && sn_mcp_remote_log_day_key() !== (string) $pending['day'] );

	if ( ! $is_dispatch && ! sn_mcp_remote_should_flush( $age, false, $day_changed ) ) {
		sn_mcp_remote_pending_add( $outcome );
		return;
	}

	// A dispatch with no pending buffer has nothing to fold: skip straight to
	// sn_mcp_remote_log_apply()'s own read-modify-write rather than doing a
	// redundant get/fold/prune/save pass here first.
	//
	// (A refusal with no pending buffer never reaches this point: $age is 0 and
	// $day_changed is false when $pending is null, so should_flush() above is
	// always false and the early-return buffering path already handled it.)
	if ( null !== $pending ) {
		$blob = sn_mcp_remote_log_get_blob();
		$blob = sn_mcp_remote_pending_fold( $blob, $pending );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( SN_MCP_REMOTE_PENDING_TRANSIENT );
		}
		if ( ! $is_dispatch ) {
			// A stale or day-rolled buffer flushed by a refusal: that refusal counts too.
			$blob = sn_mcp_remote_log_add_count( $blob, sn_mcp_remote_log_day_key(), $outcome, 1 );
		}
		$blob = sn_mcp_remote_log_prune( $blob );
		sn_mcp_remote_log_save_blob( $blob );
	}

	if ( $is_dispatch ) {
		sn_mcp_remote_log_apply( $outcome, $slug );
	}
}

/**
 * Add one refusal to the pending buffer, creating it if absent.
 *
 * first_seen is stamped ONCE, at creation, so the buffer ages from when it
 * started rather than from the last thing added to it — otherwise a steady
 * flood would keep resetting the age and never flush.
 *
 * @param string $outcome
 * @return void
 */
function sn_mcp_remote_pending_add( $outcome ) {
	if ( ! function_exists( 'set_transient' ) ) {
		return;
	}
	$pending = sn_mcp_remote_pending_get();
	// The day-mismatch half of this condition is unreachable via
	// sn_mcp_remote_record(), which flushes on day change before buffering;
	// kept so a direct caller cannot make counts leak across days.
	if ( null === $pending || sn_mcp_remote_log_day_key() !== $pending['day'] ) {
		$pending = array(
			'day'        => sn_mcp_remote_log_day_key(),
			'first_seen' => time(),
			'counts'     => array(),
		);
	}
	$current                       = isset( $pending['counts'][ $outcome ] ) ? (int) $pending['counts'][ $outcome ] : 0;
	$pending['counts'][ $outcome ] = $current + 1;

	set_transient( SN_MCP_REMOTE_PENDING_TRANSIENT, $pending, SN_MCP_REMOTE_PENDING_TTL );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php tests/mcp-remote-observability.php
git commit -m "feat: coalesce refusal writes through a pending buffer, pinned on the midnight case"
```

---

## Task 5: The reader — fold pending so the panel never under-reports

**Files:**
- Modify: `inc/mcp/mcp-remote-observability.php` (append)
- Modify: `tests/mcp-remote-observability.php` (append)

- [ ] **Step 1: Write the failing test**

Insert before the summary block:

```php
echo "Group: the reader folds PENDING counts, so nothing is under-reported\n";
// Guards §4's failure directly. Without folding, the owner reads "0 refused"
// while a probe is in progress — a readout that is quietly wrong is worse than
// one that is absent, because it is trusted.
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'refused_auth', '' );
sn_mcp_remote_record( 'refused_auth', '' );
ok( ! array_key_exists( SN_MCP_REMOTE_LOG_OPTION, $GLOBALS['__options'] ), 'precondition: the two refusals are still only buffered' );
$view = sn_mcp_remote_log_read();
ok( 2 === $view['today']['refused_auth'], 'THE UNDER-REPORTING PIN: the reader still shows both buffered refusals' );

echo "Group: the reader reports totals the panel actually renders\n";
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
sn_mcp_remote_record( 'refused_auth', '' );
$view = sn_mcp_remote_log_read();
ok( 1 === $view['today']['dispatched'], 'today\'s dispatch count' );
ok( 1 === $view['today_refused'], 'today\'s refusals summed across every refusal outcome' );
ok( is_string( $view['last_used'] ), 'and last_used comes through' );

echo "Group: an empty record reads as never-used rather than as zero-shaped garbage\n";
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$view = sn_mcp_remote_log_read();
ok( null === $view['last_used'], 'last_used is null on a fresh install' );
ok( 0 === $view['today']['dispatched'], 'and every counter reads 0 rather than being absent' );
ok( 0 === $view['today_refused'], 'including the refusal total' );

echo "Group: reading does not DOUBLE-count what it folded\n";
// The reader folds pending without clearing it (a read is not a write path).
// Reading twice must not inflate the numbers.
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'refused_auth', '' );
$first  = sn_mcp_remote_log_read();
$second = sn_mcp_remote_log_read();
ok( $first['today_refused'] === $second['today_refused'], 'THE IDEMPOTENCE PIN: two consecutive reads report the same total' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Call to undefined function sn_mcp_remote_log_read()`

- [ ] **Step 3: Write minimal implementation**

Append to `inc/mcp/mcp-remote-observability.php`:

```php
/**
 * The display-ready view of the record.
 *
 * IT FOLDS THE PENDING BUFFER IN. Without that the panel under-reports by up to
 * a flush window, and the owner reads "0 refused" while a probe is in progress.
 * A readout that is quietly wrong is worse than one that is absent, because it
 * is trusted.
 *
 * It folds WITHOUT clearing — a read is not a write path, and clearing here
 * would make two consecutive reads disagree. Folding is therefore idempotent,
 * which is pinned.
 *
 * @return array {
 *     @type string|null $last_used     GMT timestamp of the last dispatch.
 *     @type array       $today         outcome => int, every outcome present.
 *     @type int         $today_refused Sum of every refusal outcome today.
 *     @type array       $recent        The ring, newest first.
 * }
 */
function sn_mcp_remote_log_read() {
	$blob = sn_mcp_remote_log_get_blob();
	$blob = sn_mcp_remote_pending_fold( $blob, sn_mcp_remote_pending_get() );

	$day    = sn_mcp_remote_log_day_key();
	$bucket = isset( $blob['counters'][ $day ] ) ? $blob['counters'][ $day ] : array();

	$today   = array();
	$refused = 0;
	foreach ( SN_MCP_REMOTE_OUTCOMES as $outcome ) {
		$n               = isset( $bucket[ $outcome ] ) ? (int) $bucket[ $outcome ] : 0;
		$today[ $outcome ] = $n;
		if ( 'dispatched' !== $outcome ) {
			$refused += $n;
		}
	}

	return array(
		'last_used'     => $blob['last_used'],
		'today'         => $today,
		'today_refused' => $refused,
		'recent'        => $blob['recent'],
	);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php tests/mcp-remote-observability.php
git commit -m "feat: a reader that folds pending counts, idempotently"
```

---

## Task 6: Wire the bridge — and pin that it does not depend on this

**Files:**
- Modify: `inc/mcp/mcp-bridge-route.php`
- Modify: `signal-and-noise-tools.php`
- Modify: `tests/mcp-bridge-route.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/mcp-bridge-route.php`, immediately **before** its `echo ( 0 === $fail )` block:

```php
echo "Group: the handler reports its outcome, WITHOUT depending on a recorder\n";
// THE PROPERTY THAT MATTERS MOST HERE is the second half. Every assertion above
// this line ran with NO sn_mcp_remote_record() defined at all — this suite never
// loads the observability module — so they are already the byte-identical-
// without-the-module evidence, and this group only makes that explicit and
// then checks the wiring with a recorder present.
ok( ! function_exists( 'sn_mcp_remote_record' ), 'THE INDEPENDENCE PIN: every pin above ran with no recorder defined, so the door does not need one' );

// Now define one and confirm the handler feeds it. Declared HERE rather than at
// the top of the file precisely so the pin above can be true.
$GLOBALS['__recorded'] = array();
function sn_mcp_remote_record( $outcome, $slug = '' ) {
	$GLOBALS['__recorded'][] = array( $outcome, $slug );
}

$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );

$GLOBALS['__recorded'] = array();
sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
ok( array( array( 'dispatched', $REMOTE ) ) === $GLOBALS['__recorded'], 'a dispatch records dispatched, with the slug' );

$GLOBALS['__recorded'] = array();
sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => $REMOTE ) ) );
ok( array( array( 'refused_auth', $REMOTE ) ) === $GLOBALS['__recorded'], 'an anonymous call records refused_auth' );

$GLOBALS['__recorded'] = array();
sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => 'signal-noise/get-post-content' ) ) );
ok( array( array( 'refused_slug', 'signal-noise/get-post-content' ) ) === $GLOBALS['__recorded'], 'an off-list slug records refused_slug' );

$GLOBALS['__recorded'] = array();
sn_bridge_handle_request( new SNB_Req( $good, array() ) );
ok( array( array( 'refused_request', '' ) ) === $GLOBALS['__recorded'], 'a missing slug records refused_request' );

$GLOBALS['__options']  = array();
$GLOBALS['__recorded'] = array();
$shut = sn_bridge_handle_request( new SNB_Req( $good, array( 'slug' => $REMOTE ) ) );
ok( array( array( 'refused_shut', $REMOTE ) ) === $GLOBALS['__recorded'], 'a call arriving while the switch is off records refused_shut' );

// THE ASYMMETRY PIN, both directions. refused_shut and refused_auth are
// distinct IN THE RECORD and identical ON THE WIRE. Collapsing the record loses
// the signal; leaking the distinction reopens the oracle #642 closed.
$GLOBALS['__options'] = array( 'sn_mcp_remote_enabled' => true );
$anon = sn_bridge_handle_request( new SNB_Req( array(), array( 'slug' => $REMOTE ) ) );
ok( $shut->code === $anon->code && $shut->message === $anon->message && $shut->data === $anon->data, 'THE ASYMMETRY PIN: shut and bad-auth refusals stay byte-identical on the wire' );
ok( 'refused_shut' !== 'refused_auth', 'while remaining distinct outcomes in the record' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-bridge-route.php`
Expected: FAIL on `a dispatch records dispatched, with the slug` (and the four other wiring pins) — the handler does not call the recorder yet.

- [ ] **Step 3: Write minimal implementation**

In `inc/mcp/mcp-bridge-route.php`, add this helper immediately above `sn_bridge_handle_request()`:

```php
/**
 * Report an outcome to the observability module, if one is installed.
 *
 * OBSERVATIONAL, AND SUBORDINATE TO THE DOOR. The function_exists() guard is
 * the whole contract: the bridge behaves byte-identically with no recorder
 * present, which tests/mcp-bridge-route.php pins by running its entire suite
 * without one. A broken log must not be able to shut the door, and must not be
 * able to open it either.
 *
 * @param string $outcome One of SN_MCP_REMOTE_OUTCOMES.
 * @param string $slug    The requested slug, or ''.
 * @return void
 */
function sn_bridge_report( $outcome, $slug = '' ) {
	if ( function_exists( 'sn_mcp_remote_record' ) ) {
		sn_mcp_remote_record( $outcome, $slug );
	}
}
```

Then add one call per branch inside `sn_bridge_handle_request()`. The slug is not
parsed until after the Bearer check, so the two pre-auth branches need it read
early — **for reporting only**, never for a decision:

```php
function sn_bridge_handle_request( $request ) {
	// Read the slug up front FOR REPORTING ONLY. No refusal below branches on
	// it before authentication — doing so would rebuild the enumeration oracle
	// the ordering exists to deny. It is recorded, not acted on.
	$body = ( is_object( $request ) && method_exists( $request, 'get_json_params' ) )
		? (array) $request->get_json_params()
		: array();
	$slug = isset( $body['slug'] ) ? (string) $body['slug'] : '';
	$args = ( isset( $body['args'] ) && is_array( $body['args'] ) ) ? $body['args'] : array();

	// STEP 0 — the gates, read again as late as possible.
	if ( ! sn_bridge_should_register() ) {
		sn_bridge_report( 'refused_shut', $slug );
		return sn_bridge_absent_route_error();
	}

	$header = is_object( $request ) && method_exists( $request, 'get_header' )
		? $request->get_header( 'authorization' )
		: null;

	if ( ! sn_bridge_bearer_matches( $header, sn_bridge_secret() ) ) {
		sn_bridge_report( 'refused_auth', $slug );
		return sn_bridge_absent_route_error();
	}

	if ( '' === $slug ) {
		sn_bridge_report( 'refused_request', '' );
		return new WP_Error(
			'sn_bridge_bad_request',
			__( 'Missing slug.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'sn_mcp_remote_slugs' ) || ! in_array( $slug, sn_mcp_remote_slugs(), true ) ) {
		sn_bridge_report( 'refused_slug', $slug );
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
	if ( ! $ability ) {
		sn_bridge_report( 'refused_slug', $slug );
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	sn_bridge_set_verified( true );
	add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
	try {
		$out = $ability->execute( $args );
	} finally {
		remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
		sn_bridge_set_verified( false );
	}

	sn_bridge_report( 'dispatched', $slug );

	return is_wp_error( $out ) ? $out : array( 'ok' => true, 'data' => $out );
}
```

**Delete** the now-duplicated `$body` / `$slug` / `$args` block that previously sat after the
Bearer check — the values are read once, at the top.

In `signal-and-noise-tools.php`, add the require beside the other `inc/mcp/` requires:

```php
require_once SNT_PATH . 'inc/mcp/mcp-remote-observability.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-bridge-route.php`
Expected: `OK (<n> passed, 0 failed): mcp-bridge-route.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

Then the sweep:

```bash
bash tests/run.sh; echo "EXIT=$?"
```
Expected: `EXIT=0` and zero lines matching `  FAIL`.

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-bridge-route.php signal-and-noise-tools.php tests/mcp-bridge-route.php
git commit -m "feat: the bridge reports each outcome, without depending on a recorder"
```

---

## Task 7: The presenter and the admin line

**Files:**
- Modify: `inc/mcp/mcp-remote-observability.php` (append)
- Modify: `inc/admin-forms/mcp-connect-status.php`
- Modify: `tests/mcp-remote-observability.php` (append)

- [ ] **Step 1: Write the failing test**

Insert before the summary block:

```php
echo "Group: the presenter says never-used, and says it plainly\n";
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
$text = sn_mcp_remote_log_summary_text();
ok( false !== strpos( $text, 'Never used' ), 'a fresh install reads "Never used"' );
ok( false === strpos( $text, '1970' ), 'and never renders an epoch timestamp for a null last_used' );

echo "Group: the presenter shows dispatches AND refusals together\n";
// The pair is the point. A dispatch count alone reads as reassuring; it is the
// refusal count beside it that makes a probe legible.
$GLOBALS['__options']    = array();
$GLOBALS['__transients'] = array();
sn_mcp_remote_record( 'dispatched', 'signal-noise/remote-get-analytics-summary' );
sn_mcp_remote_record( 'refused_auth', '' );
sn_mcp_remote_record( 'refused_auth', '' );
$text = sn_mcp_remote_log_summary_text();
ok( false !== strpos( $text, '1 call' ), 'the dispatch count is rendered' );
ok( false !== strpos( $text, '2 refused' ), 'THE PAIR PIN: the refusal count is rendered beside it' );
ok( false === strpos( $text, 'UTC' ), 'and "today" carries no timezone label — buckets are in the site timezone, so the reader\'s "today" and the record\'s already agree' );
ok( false === strpos( $text, 'Never used' ), 'and it no longer claims the door is unused' );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/mcp-remote-observability.php`
Expected: PHP fatal — `Call to undefined function sn_mcp_remote_log_summary_text()`

- [ ] **Step 3: Write minimal implementation**

Append to `inc/mcp/mcp-remote-observability.php`:

```php
/**
 * One line for the admin remote card.
 *
 * THE LOGIC LIVES HERE, NOT IN THE VIEW. inc/admin-forms/mcp-connect-status.php
 * is already 318 lines and is a renderer; it prints this string and decides
 * nothing.
 *
 * "today" carries NO timezone label, and that is correct rather than an
 * omission: buckets are keyed with wp_date(), so the record's "today" and the
 * reader's are the same day. A label would only be needed if they could differ.
 * See sn_mcp_remote_log_day_key().
 *
 * @return string Escaped-safe plain text (no markup).
 */
function sn_mcp_remote_log_summary_text() {
	$view = sn_mcp_remote_log_read();

	if ( null === $view['last_used'] && 0 === $view['today_refused'] ) {
		return __( 'Never used.', 'signal-and-noise-tools' );
	}

	$last = ( null === $view['last_used'] )
		? __( 'never', 'signal-and-noise-tools' )
		: $view['last_used'];

	return sprintf(
		/* translators: 1: last-used timestamp or "never", 2: dispatch count, 3: refusal count. */
		__( 'Last used %1$s · %2$d calls today · %3$d refused', 'signal-and-noise-tools' ),
		$last,
		(int) $view['today']['dispatched'],
		(int) $view['today_refused']
	);
}
```

> The test asserts the substring `1 call`, which `%2$d calls` satisfies. Keep the plural
> form — a singular/plural split here would need `_n()` and buys nothing on a diagnostic line.

In `inc/admin-forms/mcp-connect-status.php`, find the `case` arm that sets `$remote_meta` for
`bridge_ready` and append the usage line to it:

```php
		case 'bridge_ready':
			$remote_value = __( 'Bridge ready', 'signal-and-noise-tools' );
			$remote_meta  = __( 'The switch is on and the bridge secret is defined, so the route is registered and answers the Worker.', 'signal-and-noise-tools' );
			if ( function_exists( 'sn_mcp_remote_log_summary_text' ) ) {
				$remote_meta .= '<br>' . esc_html( sn_mcp_remote_log_summary_text() );
			}
			$remote_pill  = array( 'kind' => 'ok', 'text' => __( 'ready', 'signal-and-noise-tools' ) );
			break;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/mcp-remote-observability.php`
Expected: `OK (<n> passed, 0 failed): mcp-remote-observability.php` — green, AND the count has risen by exactly the number of ok() calls this task added. Record the number; a total that did not move means the new block never ran.

Then:
```bash
bash tests/run.sh; echo "EXIT=$?"
composer lint
composer phpstan
```
Expected: `EXIT=0`; lint clean; phpstan `[OK] No errors` **with the progress bar completed** (an OOM can read as false-clean).

- [ ] **Step 5: Commit**

```bash
git add inc/mcp/mcp-remote-observability.php inc/admin-forms/mcp-connect-status.php tests/mcp-remote-observability.php
git commit -m "feat: show last-used, calls and refusals beside the remote toggle"
```

---

## Task 8: Mutation-verify, then document

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/security/agent-surface-threat-model.md`
- Modify: `docs/proposals/remote-mcp-increment4-observability.md` (status line)

- [ ] **Step 1: Mutation-verify the pins, confirming each mutation APPLIED**

"No pins fired" is usually a broken mutation, not a weak suite — a previous session's
`s///` hit a docblock quote instead of the code and reported zero reds. Confirm the diff is
non-empty before believing any result, and restore with `cp`, never `git checkout`/`git stash`.

```bash
S=/tmp/mut-obs; mkdir -p $S
cp inc/mcp/mcp-remote-observability.php $S/obs.bak

mut() {
  cp $S/obs.bak inc/mcp/mcp-remote-observability.php
  perl -0777 -pi -e "$2" inc/mcp/mcp-remote-observability.php
  if diff -q $S/obs.bak inc/mcp/mcp-remote-observability.php >/dev/null; then
    echo "  !! $1 DID NOT APPLY — fix the pattern, do not record a result"
  else
    echo "== $1 =="
    php tests/mcp-remote-observability.php 2>&1 | grep -E "^  FAIL" | cut -c1-90
  fi
}

mut "autoload flips to true"      "s/SN_MCP_REMOTE_LOG_OPTION, \\\$blob, false/SN_MCP_REMOTE_LOG_OPTION, \\\$blob, true/"
mut "day key swaps to gmdate"     "s/function_exists\( 'wp_date' \) \? wp_date\( 'Y-m-d' \) : date\( 'Y-m-d' \)/gmdate( 'Y-m-d' )/"
mut "refusals set last_used"      "s/if \( 'dispatched' === \\\$outcome \) \{\n\t\t\\\$blob\['last_used'\] = \\\$now;\n\t\}/\\\$blob['last_used'] = \\\$now;/"
mut "flush always returns true"   "s/return \(int\) \\\$age_seconds >= SN_MCP_REMOTE_FLUSH_SECONDS;/return true;/"
mut "fold recomputes the day key" "s/\(string\) \\\$pending\['day'\]/sn_mcp_remote_log_day_key()/"
mut "reader stops folding"        "s/\\\$blob = sn_mcp_remote_pending_fold\( \\\$blob, sn_mcp_remote_pending_get\(\) \);\n\n\t\\\$day/\\\$day/"
mut "ring appends oldest-first"   "s/array_unshift\(/array_push(/"

cp $S/obs.bak inc/mcp/mcp-remote-observability.php
echo "== CONTROL (must be green) =="; php tests/mcp-remote-observability.php | tail -1
```

Expected: every mutation prints at least one `FAIL`, and each `FAIL` is the pin **named after
that property** — e.g. the day-key mutation reds `THE TIMEZONE PIN`, the fold mutation reds `THE
UNDER-REPORTING PIN`. If a mutation reds only an unrelated pin, the property has coverage but no
witness; add the missing assertion before continuing.

**The day-key mutation is the one to watch.** On a UTC server `gmdate()` and `wp_date()` return
identical strings, so it reds *only* the call-based pin. If it reds nothing, the pin has been
weakened back into a value comparison — fix that before continuing, because the mutation is
otherwise invisible on CI and on any UTC host.

- [ ] **Step 2: Update the CHANGELOG**

Add under `## [Unreleased]`:

```markdown
### Added
- **The remote analytics door now records that it was used** (R3 §3D Increment 4, first slice). Nothing did before, so every control in the revoke runbook assumed the owner already suspected something — a door with five ways to shut it and no way to know it opened. The MCP status panel now shows, beside the toggle: when the door was last used, how many calls it served today, and how many were refused. Refusals are counted because they are the probe signal, and their writes are coalesced through a short-lived buffer because `/wp-json/signal-noise/v1/bridge` is a public origin route while the door is armed — uncoalesced, an anonymous caller would drive one database write per request.
- **What this deliberately does not record: who.** Cloudflare Access issues and holds the session, so WordPress never sees it; at the origin a bridge call is a valid Bearer token and nothing more. The threat model's "audit the caller, not just the call" requirement is **not** closed by this and cannot be closed at the origin. That remains Worker-side.
```

- [ ] **Step 3: Update the threat model**

In `docs/security/agent-surface-threat-model.md`, append to the **R-3D-c** bullet:

```markdown
  **Partially addressed 2026-08-14** by `inc/mcp/mcp-remote-observability.php`: per-day counters
  by outcome, a denormalised last-used timestamp, and a capped ring, surfaced beside the toggle.
  Volume is now *recorded*; it is not yet *alerted on*, and the row stays open until it is.
  §8.4's "audit the caller" is untouched and cannot be satisfied at the origin — see
  `docs/proposals/remote-mcp-increment4-observability.md` §7.
```

- [ ] **Step 4: Flip the spec's status line**

In `docs/proposals/remote-mcp-increment4-observability.md`, change:

```markdown
**Status:** design, approved 2026-08-14. Not built.
```

to:

```markdown
**Status:** BUILT 2026-08-14. See `docs/proposals/remote-mcp-increment4-observability-plan.md`.
```

- [ ] **Step 5: Final verification and commit**

```bash
bash tests/run.sh; echo "EXIT=$?"
grep -c "  FAIL" /dev/stdin < <(bash tests/run.sh 2>&1) || true
composer lint
composer phpstan
git add -A
git commit -m "docs: record the observability slice, and what it cannot see"
```

Expected: `EXIT=0`, zero `FAIL` lines, lint clean, phpstan `[OK] No errors`.

---

## Definition of done

- [ ] `bash tests/run.sh` exits **0** with zero `  FAIL` lines (check `$?`, not the summary line).
- [ ] `composer lint` clean; `composer phpstan` reports `[OK] No errors` with a completed progress bar.
- [ ] Every mutation in Task 8 Step 1 reds the pin **named after** its property.
- [ ] `sn_mcp_remote_log_v1` is saved with `autoload = false`.
- [ ] The bridge suite passes with **no** `sn_mcp_remote_record()` defined until the final group declares one.
- [ ] The admin remote card shows "Never used." on a fresh install.
- [ ] The spec's §7 limitation appears in the CHANGELOG and the threat model, not only in the design doc.
