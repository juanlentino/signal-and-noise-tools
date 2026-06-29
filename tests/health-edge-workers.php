<?php
/**
 * Tests for the edge-worker Health check (8th check, plugin v6.49.0).
 *
 * inc/health-edge-workers.php folds owned-Worker observability into the Content
 * Health scan: it flags an unreachable analytics/login-guard Worker and — the
 * high-signal one — a STALE or EMPTY login-guard denylist (the daily refresh cron
 * stalled, so the edge enforces an outdated blocklist). Timestamps use the REAL
 * status-endpoint format (ISO-8601 with millis/micros + Z) so the strtotime()
 * freshness path is exercised against the real wire, not a tidy stub.
 *
 * @since plugin v6.49.0
 */

// SECURITY: Prevent web access.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP + sibling-module stubs ──
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n ) { return number_format( (int) $n ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } } // identity: defaults pass through
$GLOBALS['__ew'] = array( 'transient' => array(), 'wv' => null, 'lg' => null, 'endpoint' => 'https://x.test/_sn/version' );
function get_transient( $k ) { return $GLOBALS['__ew']['transient'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__ew']['transient'][ $k ] = $v; return true; }
function sn_worker_version_endpoint_url() { return $GLOBALS['__ew']['endpoint']; }
function sn_worker_version_get() { return $GLOBALS['__ew']['wv']; }
function sn_login_defense_status() { return $GLOBALS['__ew']['lg']; }
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint );
}

require __DIR__ . '/../inc/health-edge-workers.php';

// The worker emits compiledAt with sub-second precision + a Z suffix (verified
// against the live endpoint: "2026-06-22T17:16:27.106121Z").
$iso = static function ( $ts ) { return gmdate( 'Y-m-d\TH:i:s', $ts ) . '.000Z'; };

$NOW   = strtotime( '2026-06-28T12:00:00Z' );
$STALE = 3 * DAY_IN_SECONDS;
$fresh = $iso( $NOW - HOUR_IN_SECONDS );          // 1h ago
$old   = $iso( $NOW - 5 * DAY_IN_SECONDS );       // 5 days ago (stale)

// ── 1. Pure findings function (deterministic via injected $now) ──
ok( function_exists( 'sn_health_edge_worker_findings' ), 'sn_health_edge_worker_findings() defined' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE );
ok( 0 === count( $f ), 'analytics ok + login-guard fresh → no findings' );

$f = sn_health_edge_worker_findings( false, 'https://x.test/_sn/version', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE );
ok( 1 === count( $f ) && 'sn-analytics' === $f[0]['subject_label'], 'analytics unreachable → one sn-analytics finding' );
ok( 'edge_worker' === $f[0]['subject_type'] && '' === $f[0]['edit_url'], 'finding shape mirrors the non-post (cf-headers) template' );

$f = sn_health_edge_worker_findings( true, 'u', null, $NOW, $STALE );
ok( 1 === count( $f ) && 'sn-login-guard' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'not reachable' ), 'login-guard null → unreachable finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 0, 'compiledAt' => $fresh ), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'EMPTY' ), 'denylist count 0 → EMPTY finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $old ), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'STALE' ) && false !== strpos( $f[0]['note'], '5 days' ), 'denylist 5 days old → STALE finding (with age)' );

$just = $iso( $NOW - ( 3 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) ); // just under 3 days
$f    = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 10, 'compiledAt' => $just ), $NOW, $STALE );
ok( 0 === count( $f ), 'denylist just under the stale threshold → no finding (boundary)' );

$f = sn_health_edge_worker_findings( false, 'u', null, $NOW, $STALE );
ok( 2 === count( $f ), 'analytics down + login-guard down → two findings' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 0, 'compiledAt' => $old ), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'EMPTY' ), 'empty+old → single EMPTY finding (count checked before age)' );

// ── 2. I/O wrapper ──
ok( function_exists( 'sn_health_check_edge_workers' ), 'sn_health_check_edge_workers() defined' );

// not configured (no derivable endpoint) → skip, advisory hint, never false-flags.
$GLOBALS['__ew']['endpoint'] = '';
$r = sn_health_check_edge_workers();
ok( 0 === $r['count'] && false !== strpos( $r['fix_hint'], 'not configured' ), 'no endpoint → skip with advisory hint (no false positives)' );
$GLOBALS['__ew']['endpoint'] = 'https://x.test/_sn/version';

// configured + analytics ok + login-guard stale → flagged, and the status is cached.
$GLOBALS['__ew']['transient'] = array();
$GLOBALS['__ew']['wv']        = array( 'ok' => true, 'url' => 'https://x.test/_sn/version' );
$GLOBALS['__ew']['lg']        = array( 'denylistCount' => 4586, 'compiledAt' => gmdate( 'Y-m-d\TH:i:s', time() - 5 * DAY_IN_SECONDS ) . '.500000Z' );
$r = sn_health_check_edge_workers();
ok( 1 === $r['count'] && false !== strpos( $r['findings'][0]['note'], 'STALE' ), 'wrapper flags a 5-day-stale denylist (real micros+Z compiledAt)' );
ok( isset( $GLOBALS['__ew']['transient'][ SN_HEALTH_EDGE_LG_TRANSIENT ] ), 'wrapper caches the login-guard status probe' );

// an unreachable login-guard status (null) is NOT cached → self-heals next scan.
$GLOBALS['__ew']['transient'] = array();
$GLOBALS['__ew']['lg']        = null;
$r = sn_health_check_edge_workers();
ok( ! isset( $GLOBALS['__ew']['transient'][ SN_HEALTH_EDGE_LG_TRANSIENT ] ), 'an unreachable login-guard status is NOT cached (self-heal)' );
ok( $r['count'] >= 1, 'unreachable login-guard yields a finding' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
