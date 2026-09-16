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
ok( 2 === count( $m['rules'] ) && false === $m['rules'][1]['enabled'] && 'Guard' === $m['rules'][1]['name'], 'rules by name with their state' );
$GLOBALS['__opt'] = array();
ok( 'never' === sn_cf_posture_model()['state'], 'no record reads never' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
