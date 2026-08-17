<?php
/**
 * Behavioral test — signal-noise/get-deploy-status `last_deploy` reads the
 * MERGED deploy feed (v9.63.3).
 *
 * THE BUG: snt_ability_get_deploy_status() derived last_deploy from
 * snt_gh_recent_runs_merged() — GHA workflow runs ONLY. deploy.yml has been
 * workflow_dispatch-only (emergency fallback) since v1.10.1, and real releases
 * ship via wp-admin → Updates, so the desktop-mode widget's "Last deploy: X
 * ago" line froze at the last manual dispatch forever. The admin Dashboard was
 * fixed in v4.1.4 via snt_deploy_history_merged() (inc/deploy-history.php,
 * which records wp-admin installs off upgrader_process_complete); the ability
 * payload never followed.
 *
 * THE CONTRACT UNDER TEST (v9.63.3):
 *   - last_deploy   = age of the newest record in the MERGED feed (wp-admin
 *                     installs + GHA runs) — a wp-admin install MOVES it.
 *                     Same meaning consumers always pinned ("when did the last
 *                     deploy happen"); only the broken source is fixed.
 *   - last_gha_run  = NEW additive field: the old GHA-only reading, clearly
 *                     labeled, so the CI-run datum is exposed rather than
 *                     silently dropped (additive honesty).
 *   - Both are strings by construction (they ride REST JSON to
 *     desktop-mode-widget.js; no wp_localize_script scalar-cast surface).
 *
 * Harness idiom copied from tests/deploy-history-rollover.php: REAL
 * inc/deploy-history.php + REAL inc/abilities-system.php are require()d; only
 * true externals are stubbed. The snt_gh_recent_runs_merged() stub models the
 * REAL transport's record shape (see the docblock in
 * inc/github-actions-api.php: id/status/conclusion/ref/trigger/created_at/
 * duration_s/html_url + repo) — never an invented shape.
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
$GLOBALS['__df_options'] = array();   // in-memory option store
$GLOBALS['__df_actions'] = array();   // captured add_action registrations
$GLOBALS['__ab']         = array();   // captured ability registrations

// The stale GHA run: 30 days old — the "frozen at the last emergency
// dispatch" scenario. Computed once so every assertion sees the same instant.
$GLOBALS['__df_gha_created_at'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * 86400 );

// ─── WP stubs ─────────────────────────────────────────────────────────
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {
		if ( null !== $cb ) {
			$GLOBALS['__df_actions'][ $hook ][] = $cb;
		}
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['__df_options'] )
			? $GLOBALS['__df_options'][ $name ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['__df_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return $min;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $slug, $config ) {
		$GLOBALS['__ab'][ $slug ] = $config;
		return true;
	}
}
// Faithful-enough human_time_diff: WP core rounds to mins (floor 1) / hours /
// days. Deterministic here: a just-written record → '1 min'; the stale GHA
// run → '30 days'. (The real fn never returns 'ago' — callers append it.)
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to = 0 ) {
		if ( empty( $to ) ) {
			$to = time();
		}
		$diff = (int) abs( $to - $from );
		if ( $diff < 3600 ) {
			$n = max( 1, (int) round( $diff / 60 ) );
			return $n . ' min' . ( 1 === $n ? '' : 's' );
		}
		if ( $diff < 86400 ) {
			$n = max( 1, (int) round( $diff / 3600 ) );
			return $n . ' hour' . ( 1 === $n ? '' : 's' );
		}
		$n = max( 1, (int) round( $diff / 86400 ) );
		return $n . ' day' . ( 1 === $n ? '' : 's' );
	}
}

// ─── Sibling-impl stubs (live in modules not under test here) ─────────
// Additive workers list (inc/deploy-workers.php); empty stub is enough here.
if ( ! function_exists( 'snt_deploy_workers_status' ) ) {
	function snt_deploy_workers_status( $opts = array() ) {
		return array();
	}
}
// snt_deploy_status_for() lives in inc/admin-tab-dashboard.php.
if ( ! function_exists( 'snt_deploy_status_for' ) ) {
	function snt_deploy_status_for( $package ) {
		return array( 'current' => '9.63.3', 'latest' => '9.63.3', 'state' => 'ok' );
	}
}
// The GitHub transport (cache-backed; inc/github-actions-api.php). Returns ONE
// stale deploy.yml run in the REAL merged-record shape.
if ( ! function_exists( 'snt_gh_recent_runs_merged' ) ) {
	function snt_gh_recent_runs_merged( array $repos, $count = 5 ) {
		return array(
			array(
				'id'         => 987654321,
				'status'     => 'completed',
				'conclusion' => 'success',
				'ref'        => 'v9.60.0',
				'trigger'    => 'workflow_dispatch',
				'created_at' => $GLOBALS['__df_gha_created_at'],
				'duration_s' => 42,
				'html_url'   => 'https://github.com/juanlentino/signal-and-noise-tools/actions/runs/987654321',
				'repo'       => 'juanlentino/signal-and-noise-tools',
			),
		);
	}
}

// ─── Load the REAL modules under test ─────────────────────────────────
require_once __DIR__ . '/../inc/deploy-history.php';
require_once __DIR__ . '/../inc/abilities-system.php';

// Fire the captured wp_abilities_api_init registrations so the ability
// config (incl. output_schema) lands in $GLOBALS['__ab'].
foreach ( ( $GLOBALS['__df_actions']['wp_abilities_api_init'] ?? array() ) as $cb ) {
	$cb();
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function df_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) {
		$pass++; echo "  PASS: $msg\n";
	} else {
		$fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n";
	}
}
function df_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) {
		$pass++; echo "  PASS: $msg\n";
	} else {
		$fail++; echo "  FAIL: $msg\n";
	}
}

echo "get-deploy-status last_deploy reads the MERGED deploy feed — v9.63.3\n";

// ─── 1. Output schema: additive honesty ───────────────────────────────
echo "\nSchema: last_gha_run added, last_deploy kept\n";
$props = $GLOBALS['__ab']['signal-noise/get-deploy-status']['output_schema']['properties'] ?? array();
df_true( array_key_exists( 'last_deploy', $props ), 'output_schema keeps last_deploy (consumers pin it)' );
df_true( array_key_exists( 'last_gha_run', $props ), 'output_schema declares the NEW last_gha_run field' );
df_eq( 'string', $props['last_gha_run']['type'] ?? null, 'last_gha_run is typed string' );

// ─── 2. Empty local history: merged feed degrades to the GHA run ──────
// No wp-admin installs recorded yet → the merged feed contains only the GHA
// run, so last_deploy === last_gha_run. Proves the fix did NOT redefine
// last_deploy to "wp-admin installs only" — the GHA source still counts.
echo "\nEmpty history: GHA run still drives both fields\n";
$out = snt_ability_get_deploy_status( array() );
df_true( is_array( $out ) && isset( $out['theme'], $out['plugin'] ), 'theme + plugin structs intact' );
df_eq( '30 days ago', $out['last_deploy'] ?? null, 'last_deploy falls back to the stale GHA run when history is empty' );
df_eq( '30 days ago', $out['last_gha_run'] ?? null, 'last_gha_run carries the GHA-only reading' );

// ─── 3. THE BUG: a wp-admin install must MOVE last_deploy ─────────────
// Drive the REAL recorder (the exact path upgrader_process_complete and the
// admin_init version-check use) with a fresh plugin install, then re-read.
echo "\nwp-admin install recorded: last_deploy moves, last_gha_run stays honest\n";
df_true( snt_deploy_history_record( 'plugin', '9.63.3' ), 'REAL snt_deploy_history_record() accepts the install' );
$out = snt_ability_get_deploy_status( array() );
df_eq( '1 min ago', $out['last_deploy'] ?? null, 'last_deploy now reflects the just-installed wp-admin release (was frozen at the GHA run)' );
df_eq( '30 days ago', $out['last_gha_run'] ?? null, 'last_gha_run still reports the stale GHA run — datum exposed, not dropped' );

// ─── 4. Transport-type honesty ────────────────────────────────────────
// Both ride REST JSON to desktop-mode-widget.js ('Last deploy: ' +
// status.last_deploy). Strings by construction; pin it so a future refactor
// returning ints/timestamps trips here first (the wp_localize_script
// scalar-cast trap taught us to assert the wire type, not the PHP type).
echo "\nWire types\n";
df_true( is_string( $out['last_deploy'] ?? null ), 'last_deploy is a string' );
df_true( is_string( $out['last_gha_run'] ?? null ), 'last_gha_run is a string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
