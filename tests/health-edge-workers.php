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
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}

// ── sn-remote-mcp probe stubs (v11.x, H1): the URL is fixed on our own zone
// (no function_exists skip valve like the provenance probe), so the wrapper
// ALWAYS calls wp_remote_get() for it. Controlled via __ew['remote_mcp_resp']
// (array 'code'/'body') + __ew['remote_mcp_error'] (bool, is_wp_error()).
// Defaults to a HEALTHY body so every pre-existing wrapper-level test above
// the dedicated remote-mcp group (which predates this worker) keeps its
// count/finding pins unmoved; the dedicated group below overrides per-case.
//
// THIS FIXTURE IS THE WORKER'S REAL BODY SHAPE, not an invented one. The
// first version of this suite invented a FLAT body; the Worker nests
// configured/bridge_secret_bound/killed under `config`, so every pin passed
// while the consumer read keys that never existed. A fixture that models a
// shape the producer never emits is a green test for code that cannot work.
// Verified against the live endpoint (2026-08-14): top-level carries worker,
// version, source_commit, cf_version_id, deployed_at, increment, config;
// config carries configured, missing, edge_state_bound, bridge_secret_bound,
// bridge_origin, killed. `anomaly` IS top-level (Increment 4's DO counter is
// not part of `config`).
function sn_ew_real_remote_mcp_body( array $overrides = array(), array $config_overrides = array() ) {
	return array_merge(
		array(
			'worker'        => 'sn-remote-mcp',
			'version'       => '0.3.0',
			'source_commit' => 'abc1234',
			'cf_version_id' => 'deadbeef-0000-0000-0000-000000000000',
			'deployed_at'   => '2026-08-14T00:00:00Z',
			'increment'     => 2,
			'config'        => array_merge(
				array(
					'configured'          => true,
					'missing'             => array(),
					'edge_state_bound'    => true,
					'bridge_secret_bound' => true,
					'bridge_origin'       => 'https://juanlentino.com',
					'killed'              => false,
				),
				$config_overrides
			),
			'anomaly'       => array( 'flagged' => false, 'total_today' => 0, 'subjects_over' => 0 ),
		),
		$overrides
	);
}

$GLOBALS['__ew']['remote_mcp_resp']  = array(
	'code' => 200,
	'body' => json_encode( sn_ew_real_remote_mcp_body() ),
);
$GLOBALS['__ew']['remote_mcp_error'] = false;
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['__ew']['remote_mcp_resp']; }
function is_wp_error( $x ) { return $GLOBALS['__ew']['remote_mcp_error']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_http_validate_url( $url ) { return false !== filter_var( (string) $url, FILTER_VALIDATE_URL ) ? $url : false; }

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

// ── 1b. Analytics worker self-reported config (worker v1.9.0+): a reachable-but-
// misconfigured worker is the silent-data-loss alert. ──
$healthyLg = array( 'denylistCount' => 4586, 'compiledAt' => $fresh );

$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array( 'px_token_set' => true, 'ae_bound' => true, 'salt_seed_set' => true ) );
ok( 0 === count( $f ), 'reachable analytics + all config wired → no finding' );

$f = sn_health_edge_worker_findings( true, 'https://x/_sn/version', $healthyLg, $NOW, $STALE, array( 'px_token_set' => false, 'ae_bound' => true ) );
ok( 1 === count( $f ) && 'sn-analytics' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'MISCONFIGURED' ) && false !== strpos( $f[0]['note'], 'SN_PX_TOKEN' ), 'px_token_set=false → MISCONFIGURED finding naming SN_PX_TOKEN' );

$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array( 'px_token_set' => true, 'ae_bound' => false ) );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'SN_AE' ), 'ae_bound=false → MISCONFIGURED finding naming SN_AE' );

$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array() );
ok( 0 === count( $f ), 'empty config (older worker, unknown) → NO finding (no false positive)' );

// Misconfig is NOT evaluated when the worker is UNREACHABLE — the unreachable
// finding takes precedence (the elseif), and config cannot be trusted anyway.
$f = sn_health_edge_worker_findings( false, 'u', $healthyLg, $NOW, $STALE, array( 'px_token_set' => false ) );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'not reachable' ) && false === strpos( $f[0]['note'], 'MISCONFIGURED' ), 'unreachable analytics → reachability finding only, not misconfig' );

// ── 2. I/O wrapper ──
ok( function_exists( 'sn_health_check_edge_workers' ), 'sn_health_check_edge_workers() defined' );

// not configured (no derivable endpoint) → skip, advisory hint, never false-flags.
$GLOBALS['__ew']['endpoint'] = '';
$r = sn_health_check_edge_workers();
ok( 0 === $r['count'] && false !== strpos( (string) $r['skipped'], 'not configured' ), 'no endpoint → skip with advisory hint (no false positives)' );
ok( is_string( $r['skipped'] ) && '' !== $r['skipped'], '   ...and it reports as SKIPPED rather than as a check that ran and found nothing' );
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


/* v10.62.0 — four-worker expansion (cross-worker observability review). */
echo "\nGroup: provenance /_sn/status consumer\n";

// Backward compat: the six-arg calls above ran unchanged (prov defaults to
// 'unconfigured'); pin that explicitly.
$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE );
ok( array() === $f, 'BC: six-arg call (prov defaulted to unconfigured) still yields zero findings' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), null );
ok( 1 === count( $f ) && 'sn-provenance' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'not reachable' ), 'prov: null (transport failure) -> unreachable finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), array( 'status' => 'healthy', 'reasons' => array() ) );
ok( array() === $f, 'prov: healthy status -> no finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), array( 'status' => 'degraded', 'reasons' => array( 'cron-stale', 'pending-entry-stale' ), 'pending' => array( 'count' => 3 ) ) );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'DEGRADED' ) && false !== strpos( $f[0]['note'], 'cron-stale, pending-entry-stale' ) && false !== strpos( $f[0]['note'], 'Pending anchors: 3' ), 'prov: degraded -> finding names the reasons + pending count' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), array( 'status' => 'degraded', 'reasons' => array( '<script>alert(1)</script>', 'calendar-unreachable' ) ) );
ok( false === strpos( $f[0]['note'], '<script>' ) && false !== strpos( $f[0]['note'], 'calendar-unreachable' ), 'prov: a non-enum reason token is dropped by the allowlist, never rendered' );

echo "\nGroup: rights-signals sensor consumer\n";

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), 'unconfigured', null );
ok( array() === $f, 'mr: absent sensor block (older worker / failed probe) -> absent measurement, no finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), 'unconfigured', array( 'ae_bound' => true, 'last_write_ok' => true, 'last_write_at' => '2026-08-08T00:00:00Z' ) );
ok( array() === $f, 'mr: healthy sensor -> no finding' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), 'unconfigured', array( 'ae_bound' => false, 'last_write_ok' => null ) );
ok( 1 === count( $f ) && 'sn-rights-signals' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'DEAD' ), 'mr: ae_bound false -> dead-sensor finding (quiet dataset != no crawlers)' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE, array(), 'unconfigured', array( 'ae_bound' => true, 'last_write_ok' => false ) );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'FAILING' ), 'mr: last_write_ok false -> failing-writes finding' );

echo "\nGroup: login-guard refresh reason enrichment\n";

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $old, 'lastRefreshOk' => false, 'lastRefreshReason' => 'http-503' ), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'Last refresh attempt failed: http-503' ), 'lg: stale finding carries the persisted failure reason' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 0, 'compiledAt' => $fresh, 'lastRefreshOk' => false, 'lastRefreshReason' => 'canary-miss' ), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'canary-miss' ), 'lg: empty finding carries the reason too' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $old, 'lastRefreshOk' => false, 'lastRefreshReason' => 'x"><img onerror=1>' ), $NOW, $STALE );
ok( 1 === count( $f ) && false === strpos( $f[0]['note'], 'onerror' ), 'lg: a reason failing the allowlist is dropped, never rendered' );

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $old, 'lastRefreshOk' => true ), $NOW, $STALE );
ok( 1 === count( $f ) && false === strpos( $f[0]['note'], 'Last refresh attempt failed' ), 'lg: a successful last refresh appends nothing (stale is age, not reason)' );

echo "\nGroup: sn-remote-mcp status consumer (H1, R3 §3D Increments 2+4)\n";

// BC: every call above omitted the new 9th param — the default (false, "not
// measured") must produce zero remote-mcp findings, same as before this task.
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null );
ok( array() === $f, 'BC: 8-arg call (remote_mcp defaulted to false/not-measured) still yields zero findings' );

// null = the probe ran and could not reach/parse the endpoint. The URL is fixed
// on our own zone, so there is no "unconfigured" skip here — null is an outage.
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, null );
ok( 1 === count( $f ) && 'sn-remote-mcp' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'unreachable' ), 'remote-mcp: null (probe unreachable) -> unreachable finding' );

// $healthyRemote models the REAL body sn_ew_real_remote_mcp_body() returns —
// configured/bridge_secret_bound/killed nested under `config`, anomaly
// top-level. Passed directly to the PURE findings function below (which
// does not care about `worker` — that field only matters to the probe) AND
// reused as the wrapper-level JSON body further down.
$healthyRemote = sn_ew_real_remote_mcp_body( array( 'anomaly' => array( 'flagged' => false, 'total_today' => 3, 'subjects_over' => 0 ) ) );

// THE REAL-SHAPE PIN: the healthy REAL (nested) body yields zero findings —
// this is the pin that would have caught the top-level/nested mismatch: the
// old flat-fixture suite asserted this exact claim and passed while the
// consumer read keys ($remote_mcp['configured'], array_key_exists(
// 'bridge_secret_bound', $remote_mcp)) that do not exist on this shape.
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $healthyRemote );
ok( array() === $f, 'THE REAL-SHAPE PIN: the healthy REAL (nested) body -> zero findings' );

// THE REAL-SHAPE PIN, part 2: config.configured => false must yield the
// MISCONFIG outage finding (not the lost-readout one) — this is the half of
// the old bug that went the other way: `false === null` never fired, so a
// real outage was silently swallowed.
$notConfigured = sn_ew_real_remote_mcp_body( array(), array( 'configured' => false ) );
$f             = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $notConfigured );
ok( 1 === count( $f ) && 'sn-remote-mcp' === $f[0]['subject_label'] && false !== strpos( $f[0]['note'], 'missing' ), 'THE REAL-SHAPE PIN: config.configured:false -> the misconfig outage finding naming what is missing' );

$killed = sn_ew_real_remote_mcp_body( array(), array( 'killed' => true ) );
$f      = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $killed );
ok( array() === $f, 'THE STATE-NOT-FAILURE PIN: config.killed:true -> NO finding (a deliberately dark door is a state)' );

$lostReadout = $healthyRemote;
unset( $lostReadout['config']['bridge_secret_bound'] );
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $lostReadout );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'lost the readout' ), 'remote-mcp: config.bridge_secret_bound key ABSENT -> finding, note distinct from an unbound secret' );

$anomalous            = $healthyRemote;
$anomalous['anomaly'] = array( 'flagged' => true, 'total_today' => 611, 'subjects_over' => 2 );
$f                    = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $anomalous );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], '611' ) && false !== strpos( $f[0]['note'], '2' ), 'remote-mcp: anomaly.flagged (top-level) -> finding, note carries the counts' );
ok( 0 === preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $f[0]['note'] ), 'THE NO-IDENTITY PIN (health half): the anomaly note contains no email-shaped string' );

// THE DEGRADED-INSTRUMENT PIN: anomaly null/non-array is UNKNOWN, never a
// finding. This is the Worker's own fail-open shape (its DO's day-counter
// store unreachable -> the anomaly state degrades to unknown rather than
// throwing) — a future refactor that indexes $anomaly['flagged'] directly,
// without the is_array() guard, would misread "not measured" as "not
// flagged" and must red here instead of silently degrading a warning into
// a false negative.
$anomalyNull            = $healthyRemote;
$anomalyNull['anomaly'] = null;
$f                      = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $anomalyNull );
ok( array() === $f, 'THE DEGRADED-INSTRUMENT PIN: anomaly null/non-array is UNKNOWN, never a finding (anomaly: null)' );

$anomalyScalar            = $healthyRemote;
$anomalyScalar['anomaly'] = 'unknown';
$f                        = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $anomalyScalar );
ok( array() === $f, 'THE DEGRADED-INSTRUMENT PIN: anomaly null/non-array is UNKNOWN, never a finding (anomaly: "unknown" scalar)' );

// A v0.2.0-era body (pre-Increment-4 Worker, no anomaly block at all): absent
// field = absent measurement, never a finding by itself — the file's
// existing doctrine, extended to this worker.
$preAnomalyWorker = $healthyRemote;
unset( $preAnomalyWorker['anomaly'] );
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $preAnomalyWorker );
ok( array() === $f, 'remote-mcp: v0.2.0-era body with no anomaly block at all -> absent measurement, no finding' );

// Item 4: `config.configured` key ABSENT entirely (distinct from
// configured:false) is also absent measurement, never a finding — the same
// absent!=false doctrine this file already applies to $mr_sensor and the
// login-guard refresh-reason fields. Only an explicit `configured: false`
// is an outage.
$noConfiguredKey = $healthyRemote;
unset( $noConfiguredKey['config']['configured'] );
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $noConfiguredKey );
ok( array() === $f, 'remote-mcp: config.configured key ABSENT (not false) -> absent measurement, no finding' );

// A body with `config` missing ENTIRELY (e.g. a garbage/partial body)
// degrades to an empty $rm_config: `configured` reads as absent (no
// misconfig finding — absent!=false), but `bridge_secret_bound` is
// ALSO absent from that empty array, so the lost-readout finding still
// fires — a config block gone missing is at least as strong a signal as
// one field gone missing from it, and the existing check already covers it
// without a special case.
$noConfigBlock = $healthyRemote;
unset( $noConfigBlock['config'] );
$f = sn_health_edge_worker_findings( true, 'u', $healthyLg, $NOW, $STALE, array(), 'unconfigured', null, $noConfigBlock );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'lost the readout' ), 'remote-mcp: config block missing ENTIRELY -> the lost-readout finding fires (bridge_secret_bound is also absent from the empty fallback)' );

// ── I/O wrapper: the probe is unconditional (fixed URL, no config skip) ──
// $healthyLg is "fresh" relative to the fixed $NOW used by the pure-function
// group above; the wrapper calls time() for real, so a login-guard fixture
// must be built against real-time freshness here, or it reads STALE and
// contaminates the remote-mcp-only assertion below.
$GLOBALS['__ew']['transient'] = array();
$GLOBALS['__ew']['wv']        = array( 'ok' => true, 'url' => 'https://x.test/_sn/version' );
$GLOBALS['__ew']['lg']        = array( 'denylistCount' => 4586, 'compiledAt' => gmdate( 'Y-m-d\TH:i:s' ) . '.000Z' );
$GLOBALS['__ew']['remote_mcp_resp']  = array( 'code' => 200, 'body' => json_encode( $healthyRemote ) );
$GLOBALS['__ew']['remote_mcp_error'] = false;
$r = sn_health_check_edge_workers();
ok( 0 === $r['count'], 'wrapper: THE REAL-SHAPE PIN — a healthy REAL (nested) body over the wire contributes no findings' );

$GLOBALS['__ew']['transient']       = array();
$GLOBALS['__ew']['remote_mcp_error'] = true;
$r = sn_health_check_edge_workers();
ok( $r['count'] >= 1 && false !== strpos( json_encode( $r['findings'] ), 'sn-remote-mcp' ), 'wrapper: a transport failure surfaces the sn-remote-mcp finding' );
$GLOBALS['__ew']['remote_mcp_error'] = false;

// THE WORKER-IDENTITY PIN: this zone has a standing memory that /_sn/version
// answers as sn-analytics regardless of which config endpoint was probed —
// the `worker` field must be read before believing anything else in the
// body. A well-shaped 200 whose `worker` field names a DIFFERENT worker (or
// omits it) must be treated exactly like an unparseable body: unreachable.
$wrongWorker = $healthyRemote;
$wrongWorker['worker'] = 'sn-analytics';
$GLOBALS['__ew']['transient']       = array();
$GLOBALS['__ew']['remote_mcp_resp'] = array( 'code' => 200, 'body' => json_encode( $wrongWorker ) );
$r = sn_health_check_edge_workers();
ok( 1 === $r['count'] && false !== strpos( $r['findings'][0]['note'], 'unreachable' ), 'THE WORKER-IDENTITY PIN: a 200 body naming the WRONG worker is treated as unreachable, not believed' );

$missingWorkerField = $healthyRemote;
unset( $missingWorkerField['worker'] );
$GLOBALS['__ew']['transient']       = array();
$GLOBALS['__ew']['remote_mcp_resp'] = array( 'code' => 200, 'body' => json_encode( $missingWorkerField ) );
$r = sn_health_check_edge_workers();
ok( 1 === $r['count'] && false !== strpos( $r['findings'][0]['note'], 'unreachable' ), 'THE WORKER-IDENTITY PIN: a 200 body with the worker field ABSENT is also treated as unreachable' );

$GLOBALS['__ew']['remote_mcp_resp'] = array( 'code' => 200, 'body' => json_encode( $healthyRemote ) );


// ── the v6 denylist (worker v1.11.0) ────────────────────────────────────────
// The login guard grew a SECOND feed (Spamhaus DROPv6) that refreshes
// independently and keeps last-known on every failure branch. Without its own
// finding a dead v6 cron is invisible here — but the field's ABSENCE is not a
// failure: production runs the older worker until PR #24 deploys, and
// "not reported" must never be read as "empty".

$f = sn_health_edge_worker_findings( true, 'u', array( 'denylistCount' => 4586, 'compiledAt' => $fresh ), $NOW, $STALE );
ok( 0 === count( $f ),
	'a worker that reports NO v6 fields raises no v6 finding — absence is not a failure' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 4586, 'compiledAt' => $fresh,
	'denylist6Count' => 82, 'compiled6At' => $fresh,
), $NOW, $STALE );
ok( 0 === count( $f ), 'a healthy v6 feed raises no finding' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 4586, 'compiledAt' => $fresh,
	'denylist6Count' => 0, 'compiled6At' => $fresh,
), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'IPv6' ) && false !== strpos( $f[0]['note'], 'EMPTY' ),
	'a v6 feed reporting zero ranges is flagged EMPTY, and the note says IPv6' );
ok( 1 === count( $f ) && false === strpos( $f[0]['note'], '4,586' ),
	'…and the v6 finding does not mis-state the healthy v4 count' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 4586, 'compiledAt' => $fresh,
	'denylist6Count' => 82, 'compiled6At' => $old,
), $NOW, $STALE );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'IPv6' ) && false !== strpos( $f[0]['note'], 'STALE' ),
	'a v6 feed that stopped refreshing is flagged STALE independently of v4' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 0, 'compiledAt' => $fresh,
	'denylist6Count' => 0, 'compiled6At' => $fresh,
), $NOW, $STALE );
ok( 2 === count( $f ), 'both feeds empty → TWO findings; one feed never masks the other' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 4586, 'compiledAt' => $fresh,
	'denylist6Count' => 0, 'compiled6At' => $fresh,
	'last6RefreshOk' => false, 'last6RefreshReason' => 'canary-overmatch',
), $NOW, $STALE );
ok( false !== strpos( $f[0]['note'], 'canary-overmatch' ),
	'the v6 failure branch is named in the note, not just ok:false' );

$f = sn_health_edge_worker_findings( true, 'u', array(
	'denylistCount' => 4586, 'compiledAt' => $fresh,
	'denylist6Count' => 0, 'compiled6At' => $fresh,
	'last6RefreshOk' => false, 'last6RefreshReason' => '<script>alert(1)</script>',
), $NOW, $STALE );
ok( false === strpos( $f[0]['note'], '<script' ),
	'edge JSON never reaches a Health note unsanitized — the v6 reason gets the same charset allowlist' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
