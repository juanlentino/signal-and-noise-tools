<?php
/**
 * Standalone tests — Deploy Status Cloudflare workers (inc/deploy-workers.php).
 *
 * Pins:
 *   - registry contents (five worker ids / labels)
 *   - unprobeable honesty (probe_url null → live "unprobeable", state unknown)
 *   - behind-state computation (live < latest)
 *   - cache behavior (second live probe does not re-HTTP)
 *   - wrong-worker identity refusal
 *
 * Run: php tests/deploy-workers.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', '11.11.0-test' );
}

$pass = 0;
$fail = 0;

$GLOBALS['__dw_transients']      = array();
$GLOBALS['__dw_site_transients'] = array();
$GLOBALS['__dw_http']            = array(); // queue of responses
$GLOBALS['__dw_get_calls']       = array();
$GLOBALS['__dw_filters']         = array();
$GLOBALS['__dw_options']         = array(); // durable last-good store (v11.11.2)
$GLOBALS['__dw_scheduled']       = array(); // one-off warm events (v11.11.5)

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) { return isset( $GLOBALS['__dw_scheduled'][ $hook ] ) ? $GLOBALS['__dw_scheduled'][ $hook ] : false; }
	function wp_schedule_single_event( $ts, $hook ) { $GLOBALS['__dw_scheduled'][ $hook ] = $ts; return true; }
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__dw_options'] ) ? $GLOBALS['__dw_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__dw_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( isset( $GLOBALS['__dw_filters'][ $tag ] ) && is_callable( $GLOBALS['__dw_filters'][ $tag ] ) ) {
			return call_user_func( $GLOBALS['__dw_filters'][ $tag ], $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $u ) {
		if ( ! is_string( $u ) || '' === $u ) {
			return false;
		}
		$p = parse_url( $u );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ? $u : false;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/\\' );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['__dw_transients'] )
			? $GLOBALS['__dw_transients'][ $k ]
			: false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__dw_transients'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) {
		unset( $GLOBALS['__dw_transients'][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['__dw_site_transients'] )
			? $GLOBALS['__dw_site_transients'][ $k ]
			: false;
	}
}
if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__dw_site_transients'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $k ) {
		unset( $GLOBALS['__dw_site_transients'][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__dw_get_calls'][] = array( 'url' => $url, 'args' => $args );
		if ( empty( $GLOBALS['__dw_http'] ) ) {
			return array( 'response' => array( 'code' => 0 ), 'body' => '' );
		}
		return array_shift( $GLOBALS['__dw_http'] );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $resp ) {
		return is_array( $resp ) && isset( $resp['response']['code'] ) ? (int) $resp['response']['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $resp ) {
		return is_array( $resp ) && isset( $resp['body'] ) ? (string) $resp['body'] : '';
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return is_object( $t ) && is_a( $t, 'WP_Error' );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $c = '', $m = '' ) {
			$this->code    = $c;
			$this->message = $m;
		}
	}
}

// Fixed endpoints for the default registry (no analytics/provenance helpers loaded).
require_once __DIR__ . '/../inc/deploy-workers.php';

function dw_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS: $msg\n";
	} else {
		++$fail;
		echo "  FAIL: $msg\n";
	}
}

function dw_http_json( $code, $body ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
	);
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d ) {
		return json_encode( $d );
	}
}

function dw_reset() {
	$GLOBALS['__dw_transients']      = array();
	$GLOBALS['__dw_site_transients'] = array();
	$GLOBALS['__dw_http']            = array();
	$GLOBALS['__dw_get_calls']       = array();
	$GLOBALS['__dw_filters']         = array();
	$GLOBALS['__dw_options']         = array();
}

// ─── A: registry contents ────────────────────────────────────────────
echo "Group A: registry contents (five workers)\n";
$reg  = snt_deploy_workers_registry();
$ids  = array_keys( $reg );
$want = array( 'sn-analytics', 'sn-provenance', 'sn-login-guard', 'sn-remote-mcp', 'sn-rights-signals' );
dw_assert( $ids === $want, 'registry keys are the five worker ids in order' );
dw_assert( 'Analytics' === ( $reg['sn-analytics']['label'] ?? '' ), 'analytics label' );
dw_assert( 'juanlentino/signal-and-noise-analytics-worker' === ( $reg['sn-analytics']['repo'] ?? '' ), 'analytics repo' );
dw_assert( 'https://juanlentino.com/_sn/version' === ( $reg['sn-analytics']['probe_url'] ?? '' ), 'analytics probe /_sn/version' );
dw_assert( 'version' === ( $reg['sn-analytics']['version_path'] ?? '' ), 'analytics version_path' );
dw_assert( 'source_commit' === ( $reg['sn-analytics']['commit_path'] ?? '' ), 'analytics commit_path' );
dw_assert( 'https://juanlentino.com/_sn/login-guard/status' === ( $reg['sn-login-guard']['probe_url'] ?? '' ), 'login-guard probe /_sn/login-guard/status' );
dw_assert( 'https://juanlentino.com/_sn/remote-mcp/status' === ( $reg['sn-remote-mcp']['probe_url'] ?? '' ), 'remote-mcp probe /_sn/remote-mcp/status' );
dw_assert( 'https://juanlentino.com/_sn/rights-signals/version' === ( $reg['sn-rights-signals']['probe_url'] ?? '' ), 'rights-signals probe /_sn/rights-signals/version' );
// Provenance: no SN_PROV_WORKER_URL in this harness → empty probe_url (not null).
dw_assert( '' === ( $reg['sn-provenance']['probe_url'] ?? 'MISSING' ), 'provenance probe empty when worker URL unset (not unprobeable)' );

// ─── B: unprobeable honesty ──────────────────────────────────────────
echo "\nGroup B: unprobeable honesty\n";
dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	return array(
		'ghost' => array(
			'label'        => 'Ghost',
			'repo'         => 'juanlentino/ghost-worker',
			'probe_url'    => null, // NO version route
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'ghost',
		),
	);
};
// Seed a latest tag so the only reason for unknown is unprobeable live.
$GLOBALS['__dw_site_transients']['snt_dw_tag_ghost'] = '1.0.0';
$row = snt_deploy_worker_status_for( 'ghost', array( 'allow_probe' => true ) );
dw_assert( 'unprobeable' === ( $row['live'] ?? '' ), 'null probe_url → live "unprobeable"' );
dw_assert( 'unknown' === ( $row['state'] ?? '' ), 'unprobeable → state unknown (never ok)' );
dw_assert( 0 === count( $GLOBALS['__dw_get_calls'] ), 'unprobeable never issues HTTP' );
// Row still present in the full status list.
$list = snt_deploy_workers_status( array( 'probe_budget' => 5 ) );
dw_assert( 1 === count( $list ) && 'ghost' === ( $list[0]['id'] ?? '' ), 'unprobeable worker still appears as a row' );

// ─── C: behind-state computation ─────────────────────────────────────
echo "\nGroup C: behind-state computation\n";
dw_assert( 'behind' === snt_deploy_worker_state( '1.0.0', '1.2.0' ), 'live < latest → behind' );
dw_assert( 'ok' === snt_deploy_worker_state( '1.2.0', '1.2.0' ), 'live == latest → ok' );
dw_assert( 'ok' === snt_deploy_worker_state( '1.3.0', '1.2.0' ), 'live > latest → ok (ahead is not behind)' );
dw_assert( 'unknown' === snt_deploy_worker_state( '', '1.2.0' ), 'empty live → unknown' );
dw_assert( 'unknown' === snt_deploy_worker_state( 'unprobeable', '1.2.0' ), 'unprobeable live → unknown' );
dw_assert( 'unknown' === snt_deploy_worker_state( '1.0.0', '' ), 'empty latest → unknown' );

dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	return array(
		'sn-analytics' => array(
			'label'        => 'Analytics',
			'repo'         => 'juanlentino/signal-and-noise-analytics-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-analytics',
		),
	);
};
// Live 1.0.0, latest tag 1.2.0 → behind.
$GLOBALS['__dw_http'][] = dw_http_json( 200, array(
	'worker'        => 'sn-analytics',
	'version'       => '1.0.0',
	'source_commit' => 'abc123',
) );
$GLOBALS['__dw_http'][] = dw_http_json( 200, array(
	array( 'name' => 'v1.2.0' ),
	array( 'name' => 'v1.1.0' ),
) );
$row = snt_deploy_worker_status_for( 'sn-analytics', array( 'allow_probe' => true, 'force' => true ) );
dw_assert( '1.0.0' === ( $row['live'] ?? '' ), 'behind fixture: live from probe' );
dw_assert( '1.2.0' === ( $row['latest'] ?? '' ), 'behind fixture: latest from tags (not releases)' );
dw_assert( 'behind' === ( $row['state'] ?? '' ), 'behind fixture: state behind' );
dw_assert( 'abc123' === ( $row['source_commit'] ?? '' ), 'behind fixture: source_commit path' );

// ─── D: cache behavior ───────────────────────────────────────────────
echo "\nGroup D: cache behavior\n";
dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	return array(
		'sn-analytics' => array(
			'label'        => 'Analytics',
			'repo'         => 'juanlentino/signal-and-noise-analytics-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-analytics',
		),
	);
};
$GLOBALS['__dw_site_transients']['snt_dw_tag_sn-analytics'] = '1.0.0';
$GLOBALS['__dw_http'][] = dw_http_json( 200, array(
	'worker'  => 'sn-analytics',
	'version' => '1.0.0',
) );
snt_deploy_worker_live_probe( 'sn-analytics', snt_deploy_workers_registry()['sn-analytics'], false );
$first_calls = count( $GLOBALS['__dw_get_calls'] );
dw_assert( 1 === $first_calls, 'first live probe issues one HTTP GET' );
// Second call must serve the transient.
snt_deploy_worker_live_probe( 'sn-analytics', snt_deploy_workers_registry()['sn-analytics'], false );
dw_assert( 1 === count( $GLOBALS['__dw_get_calls'] ), 'warm live probe does not re-HTTP' );
// force_refresh path: flush + re-probe.
snt_deploy_workers_flush_caches();
$GLOBALS['__dw_http'][] = dw_http_json( 200, array(
	'worker'  => 'sn-analytics',
	'version' => '1.0.1',
) );
$r = snt_deploy_worker_live_probe( 'sn-analytics', snt_deploy_workers_registry()['sn-analytics'], true );
dw_assert( 2 === count( $GLOBALS['__dw_get_calls'] ), 'force after flush re-probes' );
dw_assert( '1.0.1' === ( $r['live'] ?? '' ), 'force probe returns fresh version' );

// Probe failure → unknown, never ok.
dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	return array(
		'sn-analytics' => array(
			'label'        => 'Analytics',
			'repo'         => 'juanlentino/signal-and-noise-analytics-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-analytics',
		),
	);
};
$GLOBALS['__dw_site_transients']['snt_dw_tag_sn-analytics'] = '1.0.0';
$GLOBALS['__dw_http'][] = dw_http_json( 500, 'nope' );
$row = snt_deploy_worker_status_for( 'sn-analytics', array( 'allow_probe' => true, 'force' => true ) );
dw_assert( 'unknown' === ( $row['state'] ?? '' ), 'HTTP 500 probe → state unknown (never ok)' );
dw_assert( '' === ( $row['live'] ?? 'X' ), 'HTTP 500 probe → empty live' );

// Wrong worker identity.
dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	return array(
		'sn-analytics' => array(
			'label'        => 'Analytics',
			'repo'         => 'juanlentino/signal-and-noise-analytics-worker',
			'probe_url'    => 'https://juanlentino.com/_sn/version',
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => 'sn-analytics',
		),
	);
};
$GLOBALS['__dw_site_transients']['snt_dw_tag_sn-analytics'] = '1.0.0';
$GLOBALS['__dw_http'][] = dw_http_json( 200, array(
	'worker'  => 'sn-login-guard', // wrong identity for this URL
	'version' => '9.9.9',
) );
$row = snt_deploy_worker_status_for( 'sn-analytics', array( 'allow_probe' => true, 'force' => true ) );
dw_assert( 'unknown' === ( $row['state'] ?? '' ), 'wrong worker identity → unknown' );
dw_assert( '9.9.9' !== ( $row['live'] ?? '' ), 'wrong worker identity never trusts the version' );

// ─── E: probe_budget stagger ─────────────────────────────────────────
echo "\nGroup E: probe_budget\n";
dw_reset();
$GLOBALS['__dw_filters']['snt_deploy_workers_registry'] = static function () {
	$mk = static function ( $id ) {
		return array(
			'label'        => $id,
			'repo'         => 'juanlentino/' . $id,
			'probe_url'    => 'https://juanlentino.com/_sn/' . $id,
			'version_path' => 'version',
			'commit_path'  => 'source_commit',
			'worker_id'    => $id,
		);
	};
	return array(
		'a' => $mk( 'a' ),
		'b' => $mk( 'b' ),
		'c' => $mk( 'c' ),
	);
};
// Pre-seed tags so only live probes spend budget.
foreach ( array( 'a', 'b', 'c' ) as $id ) {
	$GLOBALS['__dw_site_transients'][ 'snt_dw_tag_' . $id ] = '1.0.0';
}
// One live response for the single budgeted probe.
$GLOBALS['__dw_http'][] = dw_http_json( 200, array( 'worker' => 'a', 'version' => '1.0.0' ) );
$list = snt_deploy_workers_status( array( 'probe_budget' => 1 ) );
dw_assert( 3 === count( $list ), 'budget path still returns every row' );
dw_assert( 1 === count( $GLOBALS['__dw_get_calls'] ), 'probe_budget=1 issues one live GET on a cold registry' );
dw_assert( '1.0.0' === ( $list[0]['live'] ?? '' ), 'first worker spends the budget' );
dw_assert( '' === ( $list[1]['live'] ?? 'X' ), 'second worker stays cache-cold (empty live)' );
dw_assert( 'unknown' === ( $list[1]['state'] ?? '' ), 'cache-cold worker is unknown, not omitted' );
// v11.11.5: cold is not broken, and a cold render self-heals.
dw_assert( 'warming' === ( $list[1]['reason'] ?? '' ), 'a budget-skipped row says warming, never the internal skipped token' );
dw_assert( isset( $GLOBALS['__dw_scheduled']['snt_deploy_workers_warm'] ), 'a render that left workers cold schedules the one-off warm event' );
$GLOBALS['__dw_get_calls'] = array();
snt_deploy_workers_warm_cb();
dw_assert( count( $GLOBALS['__dw_get_calls'] ) >= 2, 'the warm cron probes the remaining cold workers with a full budget' );

// ── v11.11.2: a transport failure must not demote a KNOWN tag ──────────────
// Learned live during the 2026-08-17 GitHub outage: minutes after five tags
// were pushed, every worker card read "no GitHub tag" because the failure
// path cached '' over knowledge a successful fetch had already recorded.
dw_reset();
$GLOBALS['__dw_http'][] = dw_http_json( 200, array( array( 'name' => 'v2.5.0' ), array( 'name' => 'v2.4.0' ) ) );
dw_assert( '2.5.0' === snt_deploy_worker_latest_tag( 'o/r', 'stale1' ), 'stale-on-error: good fetch records 2.5.0' );
unset( $GLOBALS['__dw_site_transients']['snt_dw_tag_stale1'] ); // expire the cache
$GLOBALS['__dw_http'][] = dw_http_json( 503, array( 'message' => 'outage' ) );
dw_assert( '2.5.0' === snt_deploy_worker_latest_tag( 'o/r', 'stale1' ), 'stale-on-error: a 503 serves the last GOOD tag, never null' );
dw_assert( '2.5.0' === ( $GLOBALS['__dw_site_transients']['snt_dw_tag_stale1'] ?? '' ), 'stale-on-error: the stale value re-caches (briefly) so the next render retries' );
// A POSITIVE 200-with-no-matching-tags is the only writer of the no-tag sentinel.
dw_reset();
$GLOBALS['__dw_http'][] = dw_http_json( 200, array( array( 'name' => 'not-semver' ) ) );
dw_assert( null === snt_deploy_worker_latest_tag( 'o/r', 'stale2' ), 'a real empty tag list still reads as no tag' );
unset( $GLOBALS['__dw_site_transients']['snt_dw_tag_stale2'] );
$GLOBALS['__dw_http'][] = dw_http_json( 500, array() );
dw_assert( null === snt_deploy_worker_latest_tag( 'o/r', 'stale2' ), 'failure with NO prior good value stays null (never fabricates)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
