<?php
/**
 * Standalone tests for inc/search-console-sync.php — the R6b-closing cron.
 *
 * Pins: (1) the schedule is SELF-HEALING in both directions — it exists
 * exactly while the integration is configured; (2) the cron spends the ONE
 * producer the button spends (source-level parity — a parallel fetch path is
 * the drift this arc's design forbids); (3) the attempt record keeps the
 * three states apart: never-ran (null), ran-ok, ran-failed with Google's own
 * words (realtime-zero-vs-null applied to a cron).
 *
 * Run: php tests/search-console-sync.php
 *
 * @since plugin v13.9.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ── WP + integration stubs, defined BEFORE the module binds to them ──
$GLOBALS['__opts'] = array(); $GLOBALS['__sched'] = array(); $GLOBALS['__actions'] = array();
$GLOBALS['__ready_credential'] = false; $GLOBALS['__property'] = '';
$GLOBALS['__producer_calls'] = 0; $GLOBALS['__producer_result'] = null;

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['__actions'][ $h ][] = $cb; }
function wp_next_scheduled( $h ) { return isset( $GLOBALS['__sched'][ $h ] ) ? $GLOBALS['__sched'][ $h ] : false; }
function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['__sched'][ $h ] = $ts; }
function wp_unschedule_event( $ts, $h ) { unset( $GLOBALS['__sched'][ $h ] ); }
function sn_setting( $p, $d = null ) { return 'search_console.property' === $p ? $GLOBALS['__property'] : $d; }
function snt_gsc_credential_is_configured() { return $GLOBALS['__ready_credential']; }
function __( $s, $d = null ) { return $s; }
class WP_Error { private $c; private $m; public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } public function get_error_code() { return $this->c; } public function get_error_message() { return $this->m; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
// The ONE producer, stubbed to observe spending — the store is NOT loaded.
function snt_gsc_sync( $force = false ) { $GLOBALS['__producer_calls']++; return $GLOBALS['__producer_result']; }

require_once __DIR__ . '/../inc/search-console-sync.php';

echo "Group: self-healing schedule, both directions\n";
snt_gsc_sync_schedule();
ok( false === wp_next_scheduled( SNT_GSC_SYNC_HOOK ), 'unconfigured: no event is created' );
$GLOBALS['__ready_credential'] = true;
snt_gsc_sync_schedule();
ok( false === wp_next_scheduled( SNT_GSC_SYNC_HOOK ), 'credential alone is not ready — a property-less sync would fail nightly forever' );
$GLOBALS['__property'] = 'sc-domain:example.com';
snt_gsc_sync_schedule();
ok( false !== wp_next_scheduled( SNT_GSC_SYNC_HOOK ), 'configured: the daily event exists' );
$before = wp_next_scheduled( SNT_GSC_SYNC_HOOK );
snt_gsc_sync_schedule();
ok( $before === wp_next_scheduled( SNT_GSC_SYNC_HOOK ), 'idempotent while ready — no double-scheduling' );
$GLOBALS['__ready_credential'] = false;
snt_gsc_sync_schedule();
ok( false === wp_next_scheduled( SNT_GSC_SYNC_HOOK ), 'credential cleared: the event is REMOVED — no orphan on the cron dashboard' );

echo "\nGroup: one producer\n";
ok( isset( $GLOBALS['__actions'][ SNT_GSC_SYNC_HOOK ] ) && array( 'snt_gsc_sync_cron' ) === $GLOBALS['__actions'][ SNT_GSC_SYNC_HOOK ], 'the hook binds exactly snt_gsc_sync_cron' );
// Comments stripped first — the docblock SAYS snt_gsc_sync() while telling
// this story, and a mention is not a spend (a scan is its exclusions).
$src = (string) file_get_contents( __DIR__ . '/../inc/search-console-sync.php' );
$src = preg_replace( '#/\*.*?\*/#s', '', $src );
$src = preg_replace( '#(^|\s)//[^\n]*#', '$1', $src );
ok( 1 === substr_count( $src, 'snt_gsc_sync(' ), 'the module CODE spends snt_gsc_sync() exactly once — the same producer the button spends, no parallel fetch path' );

echo "\nGroup: the attempt record keeps three states apart\n";
ok( null === snt_gsc_sync_last_status(), 'never ran: null, not a zero row' );
$GLOBALS['__producer_result'] = array( 'synced_at' => 1 );
snt_gsc_sync_cron();
$s = snt_gsc_sync_last_status();
ok( is_array( $s ) && true === $s['ok'] && ! isset( $s['code'] ), 'ran ok: ok=true, no error residue' );
ok( 1 === $GLOBALS['__producer_calls'], 'the producer was spent exactly once' );
$GLOBALS['__producer_result'] = new WP_Error( 'snt_gsc_http_403', 'Google Search Console API has not been used in project X' );
snt_gsc_sync_cron();
$s = snt_gsc_sync_last_status();
ok( is_array( $s ) && false === $s['ok'] && 'snt_gsc_http_403' === $s['code'] && false !== strpos( $s['message'], 'has not been used' ), "ran failed: Google's own words recorded, code for machines, message for the human" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
