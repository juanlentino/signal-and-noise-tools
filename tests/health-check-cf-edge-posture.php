<?php
/**
 * inc/health-check-cf-edge-posture.php: the 27th check (15.4.0).
 *
 * Never fetches. No record, unconfigured, a stale record and a full transport
 * failure are SKIPPED with a reason (never a pass); a refused read is a
 * FINDING naming the scope; drifts are findings with the sentence; a clean
 * record is 0 findings with skipped null.
 *
 * Run: php tests/health-check-cf-edge-posture.php
 *
 * @since 15.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'x' ); define( 'SN_CF_MONITOR_HOOK', 'h' ); define( 'SN_CF_FW_ABILITIES_RULE', 'abilities' );
$GLOBALS['__opt'] = array();
function __( $s, $d = null ) { return $s; }
function add_action() {}
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_cf_is_configured() { return true; }
function sn_cf_get_zone() { return 'z'; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function human_time_diff( $a, $b ) { return ''; }
function sn_cf_api_get( $p, $t = null ) { return array( 'http' => 404, 'body' => array(), 'error' => '' ); }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
require_once __DIR__ . '/../inc/cloudflare-posture.php';
require_once __DIR__ . '/../inc/health-check-cf-edge-posture.php';

$okres = static function ( $r ) { return array( 'http' => 200, 'body' => array( 'success' => true, 'errors' => array(), 'result' => $r ), 'error' => '' ); };
$refused = array( 'http' => 403, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 10000, 'message' => 'Authentication error' ) ) ), 'error' => '' );
$down = array( 'http' => 0, 'body' => array(), 'error' => 'timeout' );
$settings = static function ( array $over = array() ) use ( $okres ) { $rows = array(); foreach ( array_merge( array( 'ssl' => 'strict', 'min_tls_version' => '1.2', 'always_use_https' => 'on', 'development_mode' => 'off' ), $over ) as $id => $v ) { $rows[] = array( 'id' => $id, 'value' => $v ); } return $okres( $rows ); };
$record = static function ( $s, $d, $r, $age = 3600, $configured = true ) use ( $okres ) { return array( 'fetched_at' => time() - $age, 'configured' => $configured ) + sn_cf_posture_from( $s, $d, $r ); };
$dns = static function ( $st ) use ( $okres ) { return $okres( array( 'status' => $st ) ); };
$rules = $okres( array( 'rules' => array() ) );

$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && false !== strpos( (string) $c['skipped'], 'never been read' ), 'no record: skipped, says never read' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => false, 'settings' => null, 'dnssec' => null, 'rules' => null );
$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && false !== strpos( (string) $c['skipped'], 'not configured' ), 'unconfigured: skipped' );
$GLOBALS['__opt']['sn_cf_posture'] = $record( $settings(), $dns( 'active' ), $rules, 3 * 86400 );
$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && false !== strpos( (string) $c['skipped'], 'older than two days' ), 'stale record: skipped, not a pass' );
$GLOBALS['__opt']['sn_cf_posture'] = $record( $down, $down, $down );
$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && false !== strpos( (string) $c['skipped'], 'could not be read' ), 'all three reads failed in transport: skipped with the errors' );
$GLOBALS['__opt']['sn_cf_posture'] = $record( $settings(), $dns( 'active' ), $rules );
$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && null === $c['skipped'], 'a clean record: 0 findings and it RAN' );
$GLOBALS['__opt']['sn_cf_posture'] = $record( $settings( array( 'ssl' => 'flexible', 'development_mode' => 'on' ) ), $dns( 'disabled' ), $refused );
$c = sn_health_check_cf_edge_posture();
$labels = array_column( $c['findings'], 'subject_label' );
ok( 4 === $c['count'] && array( 'rules: unread', 'ssl', 'development_mode', 'dnssec' ) === $labels, 'a refused read, two drifts and DNSSEC off are four findings: ' . implode( ', ', $labels ) );
ok( 'edge_posture' === $c['findings'][1]['subject_type'] && false !== strpos( $c['findings'][1]['note'], 'expected "strict"' ), 'a drift finding carries the sentence' );
ok( false !== strpos( $c['findings'][0]['note'], 'Zone › Zone WAF › Read' ), 'the refused read names its scope' );
$GLOBALS['__opt']['sn_cf_posture'] = $record( $down, $dns( 'active' ), $rules );
$c = sn_health_check_cf_edge_posture();
ok( 0 === $c['count'] && null === $c['skipped'], 'one transport failure among three reads: the others still judge (0 findings, ran)' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
