<?php
/**
 * inc/cloudflare-posture.php: the edge posture read (15.4.0).
 *
 * Pure over fixtures: a strict zone reads clean; Flexible, Development Mode
 * on, TLS 1.0 and a pending DNSSEC are drifts; a 403 is a REFUSAL naming the
 * documented scope (never an empty pass); the abilities rule is found by
 * name or expression and its disabled state is a fact; the refresh calls the
 * three documented endpoints and stores one non-autoloaded option; the model
 * both painters read says the same thing as the findings.
 *
 * Run: php tests/cloudflare-posture.php
 *
 * @since 15.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_CF_API_BASE', 'https://api.cloudflare.com/client/v4' );
define( 'SN_CF_MONITOR_HOOK', 'sn_cf_monitor_daily' );
define( 'SN_CF_FW_ABILITIES_RULE', 'abilities' );
$GLOBALS['__opt'] = array(); $GLOBALS['__autoload'] = array(); $GLOBALS['__calls'] = array(); $GLOBALS['__http'] = array(); $GLOBALS['__actions'] = array(); $GLOBALS['__configured'] = true;
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = array( $cb, $p ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function sn_cf_is_configured() { return ! empty( $GLOBALS['__configured'] ); }
function sn_cf_get_token() { return 'tok'; }
function sn_cf_get_zone() { return 'zone123'; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function human_time_diff( $a, $b ) { return ( (int) $b / 60 ) . ' mins'; }
function sn_cf_api_get( $path, $token = null ) { $GLOBALS['__calls'][] = $path; return $GLOBALS['__http'][ $path ] ?? array( 'http' => 404, 'body' => array(), 'error' => '' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/cloudflare-posture.php';

$okres = static function ( $result ) { return array( 'http' => 200, 'body' => array( 'success' => true, 'errors' => array(), 'result' => $result ), 'error' => '' ); };
$refused = array( 'http' => 403, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 10000, 'message' => 'Authentication error' ) ), 'result' => null ), 'error' => '' );
$settings = static function ( array $over = array() ) use ( $okres ) {
	$base = array( 'ssl' => 'strict', 'min_tls_version' => '1.2', 'always_use_https' => 'on', 'development_mode' => 'off', 'tls_1_3' => 'on', 'security_level' => 'medium', 'browser_check' => 'on', 'hotlink_protection' => 'off', 'email_obfuscation' => 'on', 'automatic_https_rewrites' => 'on', 'opportunistic_encryption' => 'on', 'challenge_ttl' => 1800, 'irrelevant' => 'x' );
	$rows = array();
	foreach ( array_merge( $base, $over ) as $id => $v ) { $rows[] = array( 'id' => $id, 'value' => $v ); }
	return $okres( $rows );
};
$dnssec = static function ( $status ) use ( $okres ) { return $okres( array( 'status' => $status ) ); };
$rules  = static function ( array $list ) use ( $okres ) { return $okres( array( 'id' => 'rs1', 'phase' => 'http_request_firewall_custom', 'rules' => $list ) ); };
$abil   = array( 'id' => '55a1a3', 'description' => 'Block Basic-auth on abilities API', 'action' => 'block', 'enabled' => true, 'expression' => '(http.request.uri.path contains "wp-abilities" and http.request.headers["authorization"][0] contains "Basic")' );
$other  = array( 'id' => 'a4e393', 'description' => 'Skip known scanners', 'action' => 'skip', 'enabled' => true, 'expression' => 'ip.src in $known' );

echo "Group: a clean zone\n";
$rec = sn_cf_posture_from( $settings(), $dnssec( 'active' ), $rules( array( $abil, $other ) ) );
ok( true === $rec['settings']['available'] && 'strict' === $rec['settings']['values']['ssl'], 'settings read; ssl value kept' );
ok( ! array_key_exists( 'irrelevant', $rec['settings']['values'] ), 'settings not in the list are not stored' );
ok( 'active' === $rec['dnssec']['status'], 'dnssec status kept' );
ok( 2 === count( $rec['rules']['rules'] ) && 'Block Basic-auth on abilities API' === $rec['rules']['rules'][0]['description'] && true === $rec['rules']['rules'][0]['enabled'], 'rules kept with name, action, enabled' );
ok( array() === sn_cf_posture_findings( $rec ), 'a strict zone with DNSSEC active has no findings' );
$r = sn_cf_posture_abilities_rule( $rec );
ok( is_array( $r ) && '55a1a3' === $r['id'], 'the abilities rule is found by its name' );

echo "\nGroup: drifts\n";
$rec = sn_cf_posture_from( $settings( array( 'ssl' => 'flexible', 'development_mode' => 'on', 'min_tls_version' => '1.0' ) ), $dnssec( 'pending' ), $rules( array( $other ) ) );
$keys = array_column( sn_cf_posture_findings( $rec ), 'key' );
ok( array( 'ssl', 'min_tls_version', 'development_mode', 'dnssec' ) === $keys, 'Flexible, TLS 1.0, dev mode on and pending DNSSEC are the findings, in order: ' . implode( ',', $keys ) );
ok( null === sn_cf_posture_abilities_rule( $rec ), 'no abilities rule in the ruleset reads null (not a rule)' );
$rec2 = sn_cf_posture_from( $settings( array( 'min_tls_version' => '1.3' ) ), $dnssec( 'active' ), $rules( array() ) );
ok( array() === sn_cf_posture_findings( $rec2 ), 'a minimum TLS ABOVE 1.2 is not a drift (compare, not equal)' );
$rec3 = sn_cf_posture_from( $settings( array( 'ssl' => 'full' ) ), $dnssec( 'active' ), $rules( array() ) );
ok( array( 'ssl' ) === array_column( sn_cf_posture_findings( $rec3 ), 'key' ), 'Full without strict is a drift (the origin certificate is not validated)' );

echo "\nGroup: the abilities rule by expression, and its state\n";
$byexpr = array( 'id' => 'x1', 'description' => 'Guard', 'action' => 'block', 'enabled' => false, 'expression' => 'http.request.uri.path contains "wp-abilities"' );
$rec = sn_cf_posture_from( $settings(), $dnssec( 'active' ), $rules( array( $other, $byexpr ) ) );
$r = sn_cf_posture_abilities_rule( $rec );
ok( is_array( $r ) && 'x1' === $r['id'] && false === $r['enabled'], 'found by expression when the name says nothing; disabled is kept as a fact' );

echo "\nGroup: Always Online is information, never a finding\n";
$note = 'on, so Cloudflare ignores stale-while-revalidate and stale-if-error; the edge lifetime still applies';
$rec  = sn_cf_posture_from( $settings( array( 'always_online' => 'on' ) ), $dnssec( 'active' ), $rules( array() ) );
ok( 'on' === $rec['settings']['values']['always_online'], 'always_online is read from the same settings answer (no new endpoint, no new scope) and stored as the plain value' );
ok( null === sn_cf_posture_settings()['always_online'], 'it is a reading: no expected value' );
ok( array() === sn_cf_posture_findings( $rec ), 'without the theme\'s cache headers: no finding' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + $rec;
$m = sn_cf_posture_model();
ok( in_array( array( 'label' => 'Always Online', 'value' => 'on' ), $m['also'], true ) && ! in_array( 'Always Online', array_column( $m['checks'], 'label' ), true ), 'and the painters show plain "on" on the "also" line' );
// Declared here, conditionally, so PHP does not hoist it above the lines that need it absent.
if ( ! function_exists( 'sn_edge_cdn_cache_control' ) ) {
	function sn_edge_cdn_cache_control( $kind ) { return $GLOBALS['__edge_header'] ?? 'max-age=86400, stale-while-revalidate=86400, stale-if-error=604800'; }
}
// Owner, 2026-10-03: Always Online is on and stays on (the working fallback).
ok( array() === sn_cf_posture_findings( $rec ), 'ON while the theme sends stale directives: still NO finding (nothing turns the card or the health scan yellow)' );
ok( null === sn_cf_posture_settings()['always_online'], 'and still no expected value' );
$m = sn_cf_posture_model();
ok( ! in_array( 'Always Online', array_column( $m['checks'], 'label' ), true ) && array() === array_filter( $m['checks'], static fn( $c ) => ! $c['ok'] ), 'it is not a judged row, and no row reads drift' );
ok( in_array( array( 'label' => 'Always Online', 'value' => $note ), $m['also'], true ), 'the "also" line says what that means, in plain words: ' . $note );
ok( 'on' === $GLOBALS['__opt']['sn_cf_posture']['settings']['values']['always_online'], 'the stored record keeps the bare value for abilities' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $settings( array( 'always_online' => 'off' ) ), $dnssec( 'active' ), $rules( array() ) );
ok( in_array( array( 'label' => 'Always Online', 'value' => 'off' ), sn_cf_posture_model()['also'], true ), 'off reads off, with no sentence' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + $rec;
foreach ( array( 'max-age=86400', 'max-age=86400, stale-while-revalidate=0, stale-if-error=0', '' ) as $header ) {
	$GLOBALS['__edge_header'] = $header;
	ok( in_array( array( 'label' => 'Always Online', 'value' => 'on' ), sn_cf_posture_model()['also'], true ), 'no positive stale directive in "' . $header . '": plain "on", the sentence would not be true' );
}
$GLOBALS['__edge_header'] = 'max-age=86400, stale-if-error=604800';
ok( in_array( array( 'label' => 'Always Online', 'value' => $note ), sn_cf_posture_model()['also'], true ), 'one stale directive is enough for the sentence' );
unset( $GLOBALS['__edge_header'] );
$GLOBALS['__opt'] = array();

echo "\nGroup: a refusal is a verdict\n";
$rec = sn_cf_posture_from( $refused, $refused, $refused );
ok( true === $rec['settings']['needs_permission'] && false !== strpos( $rec['settings']['error'], 'Zone › Zone Settings › Read' ), 'settings 403 names Zone Settings Read' );
ok( false !== strpos( $rec['dnssec']['error'], 'Zone › DNS › Read' ), 'dnssec 403 names DNS Read' );
ok( false !== strpos( $rec['rules']['error'], 'Zone › Zone WAF › Read' ), 'rules 403 names Zone WAF Read' );
ok( 3 === count( sn_cf_posture_findings( $rec ) ), 'three refused reads are three findings, never zero' );
ok( null === sn_cf_posture_abilities_rule( $rec ), 'a refused ruleset read is null, not "no rule"' );
$rec = sn_cf_posture_from( array( 'http' => 200, 'body' => array( 'success' => false, 'errors' => array( array( 'code' => 9109, 'message' => 'Unauthorized to access requested resource' ) ) ), 'error' => '' ), $dnssec( 'active' ), $rules( array() ) );
ok( true === $rec['settings']['needs_permission'], 'a 200 with error 9109 is a refusal too (Cloudflare does not always 403)' );
$rec = sn_cf_posture_from( array( 'http' => 0, 'body' => array(), 'error' => 'timeout' ), $dnssec( 'active' ), $rules( array() ) );
ok( false === $rec['settings']['available'] && false === $rec['settings']['needs_permission'] && 'timeout' === $rec['settings']['error'], 'a transport failure is neither available nor refused' );
ok( array() === sn_cf_posture_findings( $rec ), 'a transport failure is not a drift finding (the check reports it as skipped)' );

echo "\nGroup: the refresh\n";
$GLOBALS['__http'] = array( '/zones/zone123/settings' => $settings(), '/zones/zone123/dnssec' => $dnssec( 'active' ), '/zones/zone123/rulesets/phases/http_request_firewall_custom/entrypoint' => $rules( array( $abil ) ) );
$stored = sn_cf_posture_refresh();
ok( array( '/zones/zone123/settings', '/zones/zone123/dnssec', '/zones/zone123/rulesets/phases/http_request_firewall_custom/entrypoint' ) === $GLOBALS['__calls'], 'the three documented endpoints, in order' );
ok( true === $stored['configured'] && $stored === get_option( 'sn_cf_posture' ) && false === $GLOBALS['__autoload']['sn_cf_posture'], 'stored in sn_cf_posture, never autoloaded' );
ok( 'Block Basic-auth on abilities API' === sn_cf_posture_rule_name( '55a1a3' ) && '' === sn_cf_posture_rule_name( 'nope' ), 'rule names resolve from the stored read; unknown ids read empty' );
$hooked = array_filter( $GLOBALS['__actions']['sn_cf_monitor_daily'] ?? array(), static fn( $h ) => 'sn_cf_posture_refresh' === $h[0] && 30 === $h[1] );
ok( 1 === count( $hooked ), 'rides the monitor hook at priority 30 (after the event log)' );
$GLOBALS['__configured'] = false; $GLOBALS['__calls'] = array();
$stored = sn_cf_posture_refresh();
ok( false === $stored['configured'] && array() === $GLOBALS['__calls'], 'unconfigured: nothing is fetched, the record says so' );
$GLOBALS['__configured'] = true;

echo "\nGroup: the model both painters read\n";
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $settings( array( 'development_mode' => 'on' ) ), $refused, $rules( array( $abil, $byexpr ) ) );
$m = sn_cf_posture_model();
ok( 'read' === $m['state'], 'state read' );
$labels = array_column( $m['checks'], 'label' );
ok( array( 'SSL mode', 'Minimum TLS', 'Always use HTTPS', 'Development mode' ) === $labels, 'the judged settings are the checks, in words: ' . implode( ', ', $labels ) );
ok( 'Full (strict)' === $m['checks'][0]['value'] && true === $m['checks'][0]['ok'] && 'TLS 1.2' === $m['checks'][1]['value'], 'values are words, not API ids' );
ok( 'on' === $m['checks'][3]['value'] && false === $m['checks'][3]['ok'], 'development mode on reads not ok, the same verdict the findings give' );
ok( 1 === count( $m['refused'] ) && false !== strpos( $m['refused'][0], 'DNS › Read' ), 'the refused DNSSEC read is one sentence naming the scope' );
ok( 8 === count( $m['also'] ) && '30 mins' === $m['also'][7]['value'], 'the readings are the "also" line; challenge TTL is a duration' );
ok( 'on' === $m['also'][0]['value'], 'TLS 1.3 on reads on' );
$GLOBALS['__opt']['sn_cf_posture'] = array( 'fetched_at' => time(), 'configured' => true ) + sn_cf_posture_from( $settings( array( 'tls_1_3' => 'zrt' ) ), $dnssec( 'active' ), $rules( array() ) );
ok( 'on (0-RTT)' === sn_cf_posture_model()['also'][0]['value'], '15.4.1: zrt, the live zone\'s value, reads "on (0-RTT)" not the API id' );
ok( 2 === count( $m['rules'] ) && false === $m['rules'][1]['enabled'] && 'Guard' === $m['rules'][1]['name'], 'rules by name with their state' );
$GLOBALS['__opt'] = array();
ok( 'never' === sn_cf_posture_model()['state'], 'no record reads never' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
