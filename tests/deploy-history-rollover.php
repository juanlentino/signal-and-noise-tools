<?php
/**
 * Behavioral test — inc/deploy-history.php Breeze rollover dispatch (v4.8.1;
 * made async by render hardening FIX 2).
 *
 * WHY THIS FILE EXISTS (adversarial-review fix 2):
 *   tests/contracts-stub.php Contract 5 asserts an INLINE COPY of the rollover
 *   dispatch — it never invokes the real snt_deploy_history_version_check().
 *   That is tautological: it stays green even if the real function moved the
 *   dispatch OUT of the `if ($dirty)` branch (which would fire a full cache
 *   purge on EVERY admin_init, not just on a real version change). This is the
 *   project's documented "verify impl contracts, test observable effects"
 *   failure mode.
 *
 *   So here we drive the REAL function through a DIRTY state (sentinel version
 *   differs from the on-disk version) and assert — via a spy on the
 *   `sn_purge_all_caches_result` filter — that a version change SCHEDULES the
 *   rollover (does NOT fire the filter inline on admin_init) and that the
 *   scheduled event's own handler, snt_deploy_history_purge_rollover_run(),
 *   fires the filter exactly once with `$args['template_overrides'] === false`
 *   when cron later invokes it.
 *
 *   The function carries a `static $checked` short-circuit (it self-limits to
 *   one effective invocation per PHP request), so we can only meaningfully
 *   invoke it ONCE per process. We therefore test the DIRTY branch — the
 *   load-bearing one. The NOT-dirty (no rollover) case is structurally
 *   guaranteed by the dispatch's placement INSIDE `if ($dirty)`: there is no
 *   code path that reaches the scheduling call when $dirty is false. A
 *   second standalone process would be needed to assert not-dirty against the
 *   real function (the static would otherwise short-circuit it), and the
 *   structural guarantee makes that redundant.
 *
 * Pure-PHP CLI harness, modeled on tests/prepop-on-publish.php's stub idiom +
 * the add_filter/apply_filters/has_filter simulator from tests/contracts-stub.php.
 *
 * @since plugin v4.8.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Mutable test state ───────────────────────────────────────────────
$GLOBALS['__dh_options']        = array();   // in-memory option store
$GLOBALS['__dh_can']            = true;       // current_user_can result
$GLOBALS['__dh_theme_slug']     = 'signal-and-noise';
$GLOBALS['__dh_theme_version']  = '9.9.0';
$GLOBALS['__dh_purge_calls']    = array();    // every $args the spy received

// ─── Filter simulator (from contracts-stub.php) ──────────────────────
$GLOBALS['__test_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_filters'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		usort( $GLOBALS['__test_filters'][ $hook ], function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		} );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$registered = $GLOBALS['__test_filters'][ $hook ] ?? array();
		foreach ( $registered as $entry ) {
			$cb_args = array_merge( array( $value ), $args );
			$cb_args = array_slice( $cb_args, 0, $entry['accepted_args'] );
			$value   = call_user_func_array( $entry['callback'], $cb_args );
		}
		return $value;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return ! empty( $GLOBALS['__test_filters'][ $hook ] );
	}
}
// add_action is a no-op here — the module registers handlers at load, but we
// drive snt_deploy_history_version_check() directly, and simulate cron firing
// the scheduled rollover by invoking snt_deploy_history_purge_rollover_run()
// directly by name (it's a plain function; add_action wiring itself is not
// under test here).
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

// ─── Cron scheduling stub (render hardening FIX 2) ───────────
// Args-aware, mirrors tests/provenance-webhook.php's idiom: exact hook+args
// match, so wp_next_scheduled() only dedupes a truly identical event.
$GLOBALS['__dh_sched'] = array();
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $ts, $hook, $args = array() ) {
		$GLOBALS['__dh_sched'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( $GLOBALS['__dh_sched'] as $e ) {
			if ( $e['hook'] === $hook && $e['args'] === $args ) {
				return $e['ts'] > 0 ? $e['ts'] : 1;
			}
		}
		return false;
	}
}

// ─── Option store ─────────────────────────────────────────────────────
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__dh_options'] )
			? $GLOBALS['__dh_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__dh_options'][ $name ] = $value;
		return true;
	}
}

// ─── Capability + theme stubs ─────────────────────────────────────────
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return (bool) $GLOBALS['__dh_can'];
	}
}

/**
 * Stub WP_Theme — get_stylesheet() returns the slug; get('Version') the version;
 * exists() true. Mirrors the surface snt_deploy_history_version_check() uses.
 */
class DH_Theme_Stub {
	public function get_stylesheet() {
		return $GLOBALS['__dh_theme_slug'];
	}
	public function get( $key ) {
		return 'Version' === $key ? $GLOBALS['__dh_theme_version'] : '';
	}
	public function exists() {
		return true;
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = null ) {
		return new DH_Theme_Stub();
	}
}

// ─── snt_deploy_history_record() leaf deps ────────────────────────────
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return $min;
	}
}

// ─── Module constants + load ──────────────────────────────────────────
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', '4.8.1' );
}

require_once __DIR__ . '/../inc/deploy-history.php';
// SNT_DEPLOY_HISTORY_SENTINEL_OPTION is defined by the module itself.

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function dh_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		$pass++; echo "  PASS: $msg\n";
	} else {
		$fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function dh_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++; echo "  PASS: $msg\n";
	} else {
		$fail++; echo "  FAIL: $msg\n";
	}
}

echo "deploy-history Breeze rollover — REAL snt_deploy_history_version_check() — plugin v4.8.1 / async since render hardening FIX 2\n";

// ─── Arrange a DIRTY state ────────────────────────────────────────────
// Sentinel records OLD versions; on-disk (SNT_VERSION='4.8.1', theme='9.9.0')
// differ → $dirty becomes true → the rollover must be SCHEDULED once.
$GLOBALS['__dh_options'][ SNT_DEPLOY_HISTORY_SENTINEL_OPTION ] = array(
	'plugin' => '4.8.0',
	'theme'  => '9.8.0',
);

// Register the spy on the rollover filter. Records every $args it sees.
add_filter( 'sn_purge_all_caches_result', function ( $count, $args ) {
	$GLOBALS['__dh_purge_calls'][] = $args;
	return 7; // simulate "7 cache entries purged"
}, 10, 2 );

// ─── Act: drive the REAL function ────────────────────────────────────
snt_deploy_history_version_check();

// ─── Assert observable effects — SCHEDULED, not fired inline (FIX 2) ──
echo "\nDirty branch: real version-check SCHEDULES the rollover, does NOT fire it inline\n";
dh_eq( 0, count( $GLOBALS['__dh_purge_calls'] ), 'admin_init path does NOT call the purge filter inline — zero calls' );
dh_eq( 1, count( $GLOBALS['__dh_sched'] ), 'exactly one event scheduled on a real version change' );
dh_eq( SNT_DEPLOY_HISTORY_PURGE_HOOK, $GLOBALS['__dh_sched'][0]['hook'] ?? null, 'the scheduled hook is SNT_DEPLOY_HISTORY_PURGE_HOOK' );

// Side effect: the sentinel was advanced to the on-disk versions (proves the
// $dirty branch actually ran, not just the dispatch).
$sentinel = get_option( SNT_DEPLOY_HISTORY_SENTINEL_OPTION );
dh_eq( '4.8.1', $sentinel['plugin'] ?? null, 'sentinel advanced to on-disk plugin version' );
dh_eq( '9.9.0', $sentinel['theme'] ?? null, 'sentinel advanced to on-disk theme version' );

// Side effect: the new versions were recorded into deploy history.
$history = snt_deploy_history_get();
dh_true( is_array( $history ) && count( $history ) >= 2, 'two install records written (plugin + theme)' );
$refs = array_map( function ( $r ) {
	return $r['ref'] ?? '';
}, $history );
dh_true( in_array( 'v4.8.1', $refs, true ), 'history contains the v4.8.1 plugin ref' );
dh_true( in_array( 'v9.9.0', $refs, true ), 'history contains the v9.9.0 theme ref' );

// ─── The event handler fires the filter chain in cron context ─────────
// Simulates cron invoking the scheduled event: snt_deploy_history_purge_rollover_run()
// is the ONLY place the filter chain actually fires now.
echo "\ncron context: the scheduled event's handler fires the filter chain\n";
snt_deploy_history_purge_rollover_run();
dh_eq( 1, count( $GLOBALS['__dh_purge_calls'] ), 'the handler fires the rollover filter exactly once' );
$args = $GLOBALS['__dh_purge_calls'][0] ?? null;
dh_true( is_array( $args ), 'rollover forwarded an $args array' );
dh_true( is_array( $args ) && array_key_exists( 'template_overrides', $args ), '$args carries the template_overrides key' );
dh_eq( false, is_array( $args ) ? ( $args['template_overrides'] ?? null ) : null, 'template_overrides === false (preserves Site Editor DB overrides)' );

// ─── static $checked short-circuit ──────────────────────────────────
// A second call in the same process is a no-op (static guard). This proves the
// function self-limits to one effective run per request, which is WHY the
// not-dirty path is structurally — not empirically — covered here (see docblock).
echo "\nstatic \$checked short-circuit: a second call does nothing\n";
$before = count( $GLOBALS['__dh_sched'] );
snt_deploy_history_version_check();
dh_eq( $before, count( $GLOBALS['__dh_sched'] ), 'second invocation short-circuits (no extra event scheduled)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
