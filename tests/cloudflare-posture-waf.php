<?php
/**
 * inc/cloudflare-posture-waf.php: the posture's blind spots.
 *
 * Three more reads on the same Zone WAF Read scope: the Free Managed Ruleset
 * (deployed and enabled), the one rate-limit rule (exists, enabled, action,
 * and whether its expression is non-empty, never the expression itself), and
 * leaked credentials detection. A missing entrypoint (404) is "missing", a
 * refusal is "unavailable (needs Zone › Zone WAF › Read)", never off or zero;
 * a record stored before these reads existed raises nothing new.
 *
 * Run: php tests/cloudflare-posture-waf.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'https://api.cloudflare.com/client/v4' );
define( 'SN_CF_MONITOR_HOOK', 'sn_cf_monitor_daily' );
define( 'SN_CF_FW_ABILITIES_RULE', 'abilities' );
$GLOBALS['__opt'] = array(); $GLOBALS['__calls'] = array(); $GLOBALS['__http'] = array();
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) {}
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_cf_is_configured() { return true; }
function sn_cf_get_zone() { return 'zone123'; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function human_time_diff( $a, $b ) { return ''; }
function sn_cf_api_get( $path, $token = null ) { $GLOBALS['__calls'][] = $path; return $GLOBALS['__http'][ $path ] ?? array( 'http' => 404, 'body' => array(), 'error' => '' ); }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) { return array( 'count' => count( $findings ), 'findings' => $findings, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-posture.php';
require_once __DIR__ . '/../inc/health-check-cf-edge-posture.php';
require_once __DIR__ . '/../inc/abilities-cloudflare-status.php';

$okres    = static function ( $result ) { return array( 'http' => 200, 'body' => array( 'success' => true, 'errors' => array(), 'result' => $result ), 'error' => '' ); };
$refused  = array( 'http' => 403, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 10000, 'message' => 'Authentication error' ) ) ), 'error' => '' );
$noentry  = array( 'http' => 404, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 10001, 'message' => 'could not find entrypoint ruleset' ) ) ), 'error' => '' );
$down     = array( 'http' => 0, 'body' => array(), 'error' => 'timeout' );
$settings = $okres( array( array( 'id' => 'ssl', 'value' => 'strict' ), array( 'id' => 'min_tls_version', 'value' => '1.2' ), array( 'id' => 'always_use_https', 'value' => 'on' ), array( 'id' => 'development_mode', 'value' => 'off' ) ) );
$dnssec   = $okres( array( 'status' => 'active' ) );
$custom   = $okres( array( 'rules' => array() ) );
$managed  = static function ( $enabled = true, $id = '77454fe2d30c4220b5701f6fdfb893ba' ) use ( $okres ) { return $okres( array( 'rules' => array( array( 'id' => 'm1', 'action' => 'execute', 'action_parameters' => array( 'id' => $id ), 'expression' => 'true', 'enabled' => $enabled ) ) ) ); };
$rl       = static function ( $expr = '(http.request.uri.path eq "/door-k7" and cf.waf.credential_check.password_leaked)', $enabled = true, $action = 'block' ) use ( $okres ) { return $okres( array( 'rules' => array( array( 'id' => 'r1', 'description' => 'Leaked password on /door-k7', 'action' => $action, 'expression' => $expr, 'enabled' => $enabled, 'ratelimit' => array( 'period' => 10 ) ) ) ) ); };
$lcc      = static function ( $on ) use ( $okres ) { return $okres( null === $on ? array() : array( 'enabled' => $on ) ); };
$full     = static function ( $m, $r, $l ) use ( $settings, $dnssec, $custom ) { return array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $settings, $dnssec, $custom, $m, $r, $l ); };
$keys     = static function ( $rec ) { return array_column( sn_cf_posture_findings( $rec ), 'key' ); };
$check    = static function ( $label ) { foreach ( sn_cf_posture_model()['checks'] as $c ) { if ( $label === $c['label'] ) { return $c; } } return null; };

echo "Group: all three in place\n";
$rec = $full( $managed(), $rl(), $lcc( true ) );
ok( true === $rec['managed']['deployed'] && true === $rec['managed']['enabled'], 'the Free Managed Ruleset is deployed and enabled' );
ok( 1 === $rec['ratelimit']['count'] && true === $rec['ratelimit']['enabled'] && 'block' === $rec['ratelimit']['action'] && true === $rec['ratelimit']['has_expression'], 'the rate-limit rule: one, enabled, block, expression set' );
ok( true === $rec['leaked']['enabled'], 'leaked credentials detection on' );
ok( array() === sn_cf_posture_findings( $rec ), 'nothing to report' );
$GLOBALS['__opt']['sn_cf_posture'] = $rec;
$c = $check( 'Rate-limit rule' );
ok( is_array( $c ) && true === $c['ok'] && false !== strpos( $c['value'], 'blocked' ), 'the model has a rate-limit row with the action in words: ' . ( $c['value'] ?? '' ) );
ok( true === ( $check( 'Free Managed Ruleset' )['ok'] ?? null ) && 'on' === ( $check( 'Leaked credentials detection' )['value'] ?? null ), 'managed and leaked rows read ok' );

echo "\nGroup: the slug never leaves Cloudflare\n";
ok( false === strpos( json_encode( $rec ), 'door-k7' ), 'the stored record carries neither the expression nor the description' );
ok( false === strpos( json_encode( sn_cf_posture_model() ), 'door-k7' ), 'the model neither' );
ok( false === strpos( json_encode( function_exists( "snt_ability_cloudflare_status_posture" ) ? snt_ability_cloudflare_status_posture( $rec ) : "door-k7" ), 'door-k7' ), 'the sn-status posture neither' );

echo "\nGroup: off or missing is a finding\n";
ok( array( 'managed' ) === $keys( $full( $okres( array( 'rules' => array() ) ), $rl(), $lcc( true ) ) ), 'no managed rule: a finding' );
ok( array( 'managed' ) === $keys( $full( $managed( false ), $rl(), $lcc( true ) ) ), 'a disabled managed rule: a finding' );
ok( array( 'managed' ) === $keys( $full( $managed( true, 'c2e184081120413c86c3ab7e14069605' ), $rl(), $lcc( true ) ) ), 'another managed ruleset is not the Free one' );
ok( array() === $keys( $full( $managed( true, 'efb7b8c949ac4650a09736fc376e9aee' ), $rl(), $lcc( true ) ) ), 'the full Cloudflare Managed Ruleset covers it' );
$rec = $full( $noentry, $noentry, $lcc( true ) );
ok( true === $rec['managed']['available'] && false === $rec['managed']['needs_permission'] && false === $rec['managed']['deployed'], 'a 404 entrypoint (even with code 10001) is "none deployed", not a refusal' );
ok( 0 === $rec['ratelimit']['count'] && array( 'managed', 'ratelimit' ) === $keys( $rec ), 'no rate-limit entrypoint: missing, a finding' );
ok( array( 'ratelimit' ) === $keys( $full( $managed(), $rl( '(true)', false ), $lcc( true ) ) ), 'a disabled rate-limit rule: a finding' );
$rec = $full( $managed(), $rl( '' ), $lcc( true ) );
ok( false === $rec['ratelimit']['has_expression'] && array( 'ratelimit' ) === $keys( $rec ), 'an empty expression (matched nothing until 2026-09-27): a finding' );
$GLOBALS['__opt']['sn_cf_posture'] = $rec;
ok( false === ( $check( 'Rate-limit rule' )['ok'] ?? true ) && false !== strpos( $check( 'Rate-limit rule' )['value'], 'matches nothing' ), 'and the row says it matches nothing' );
ok( array( 'leaked' ) === $keys( $full( $managed(), $rl(), $lcc( false ) ) ), 'leaked credentials detection off: a finding' );
ok( array() === $keys( $full( $managed(), $rl(), $lcc( null ) ) ), 'enabled absent (optional in the API): unknown, never "off"' );

echo "\nGroup: a refusal is unavailable, never off\n";
$rec = $full( $refused, $refused, $refused );
foreach ( array( 'managed', 'ratelimit', 'leaked' ) as $w ) {
	ok( true === $rec[ $w ]['needs_permission'] && false !== strpos( $rec[ $w ]['error'], 'unavailable (needs Zone › Zone WAF › Read)' ), $w . ' 403 reads unavailable, naming Zone WAF Read' );
}
ok( array( 'managed', 'ratelimit', 'leaked' ) === $keys( $rec ), 'three refusals are three findings' );
$GLOBALS['__opt']['sn_cf_posture'] = $rec;
$m = sn_cf_posture_model();
ok( 3 === count( $m['refused'] ) && null === $check( 'Rate-limit rule' ), 'the model says unavailable three times and paints no on/off row' );
$rec = $full( $down, $down, $down );
ok( array() === $keys( $rec ), 'a transport failure is not a finding' );

echo "\nGroup: older records and the refresh\n";
$old = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $settings, $dnssec, $custom );
ok( ! array_key_exists( 'managed', $old ) && array() === sn_cf_posture_findings( $old ), 'a record from before these reads raises nothing new' );
$GLOBALS['__calls'] = array(); $GLOBALS['__http'] = array();
sn_cf_posture_refresh();
ok( array( '/zones/zone123/rulesets/phases/http_request_firewall_managed/entrypoint', '/zones/zone123/rulesets/phases/http_ratelimit/entrypoint', '/zones/zone123/leaked-credential-checks' ) === array_slice( $GLOBALS['__calls'], 3 ), 'the refresh reads the three documented endpoints after the first three' );

echo "\nGroup: Site Health\n";
$GLOBALS['__opt']['sn_cf_posture'] = $full( $managed(), $rl( '' ), $lcc( false ) );
$c = sn_health_check_cf_edge_posture();
ok( 2 === $c['count'] && null === $c['skipped'], 'the empty expression and detection off are Site Health findings' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $down, $down, $down, $down, $down, $down );
ok( null !== sn_health_check_cf_edge_posture()['skipped'], 'every read down: skipped, never a quiet pass' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
