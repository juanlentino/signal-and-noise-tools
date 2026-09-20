<?php
/**
 * The Jev meter (16.6.0): the credit cycle, the price, recording (paid,
 * cached, failed), the reading, the one-shot seed, the wrapper's metering
 * through the connector stub, the ability. Run: php tests/jev-meter.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['__j'] = array( 'opt' => array( 'sn_typesafe_api_key' => 'k' ), 'calls' => array(), 'answer' => null, 'actions' => array(), 'abilities' => array(), 'settings' => array(), 'cache' => null );
function __( $s, $d = null ) { return $s; }
function add_action( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__j']['actions'][ $t ][] = $c; return true; }
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__j']['opt'] ) ? $GLOBALS['__j']['opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__j']['opt'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__j']['opt'][ $k ] ); return true; }
function sn_setting( $path, $d = null ) { return $GLOBALS['__j']['settings'][ $path ] ?? $d; }
function wp_register_ability( $slug, $args ) { $GLOBALS['__j']['abilities'][ $slug ] = $args; }
function snt_ability_perm_manage_options() { return true; }
class WP_Error { public $m; public $d; function __construct( $c = '', $m = '', $d = null ) { $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function sn_jev_is_ready() { return '' !== sn_jev_key(); }
require __DIR__ . '/stubs/jev-connector.php';
require __DIR__ . '/../inc/typesafe-client.php';
require __DIR__ . '/../inc/jev-meter.php';
require __DIR__ . '/../inc/abilities-jev.php';
foreach ( $GLOBALS['__j']['actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
$ok200 = static function ( $answers, $in = 1000 ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'model' => 'jev-latest', 'answers' => $answers, 'usage' => array( 'input_tokens' => $in, 'output_tokens' => 5 ) ) ) ); };

// A: the cycle.
$c = sn_jev_cycle( gmmktime( 12, 0, 0, 9, 18, 2026 ), 17 );
ok( '2026-09-17' === $c['key'] && '2026-10-16' === $c['end'] && 29 === $c['days_left'], 'A1 Sep 18 sits in the cycle that began Sep 17 and ends Oct 16; 29 days left' );
ok( '2026-08-17' === sn_jev_cycle( gmmktime( 0, 0, 0, 9, 16, 2026 ), 17 )['key'], 'A2 Sep 16 belongs to the August cycle' );
ok( '2025-12-17' === sn_jev_cycle( gmmktime( 0, 0, 0, 1, 5, 2026 ), 17 )['key'], 'A3 January before the 17th rolls into December of the year before' );
ok( '2026-09-01' === sn_jev_cycle( gmmktime( 0, 0, 0, 9, 30, 2026 ), 1 )['key'] && '2026-09-30' === sn_jev_cycle( gmmktime( 0, 0, 0, 9, 30, 2026 ), 1 )['end'], 'A4 cycle day 1 is the calendar month' );
$GLOBALS['__j']['settings']['theme.jev_cycle_day'] = 40;
ok( 28 === sn_jev_cycle_day(), 'A5 the cycle day clamps to 28' );
$GLOBALS['__j']['settings']['theme.jev_cycle_day'] = 17;

// B: price and recording.
ok( 0.042 === sn_jev_price( 1000000 ) && 0.0 === sn_jev_price( -5 ), 'B1 a million input tokens is $0.042; nothing below zero' );
sn_jev_meter_record( 'notes', 80000, 10 );
sn_jev_meter_record( 'notes', 0, 0, true );
sn_jev_meter_record( 'collision', 11000 );
sn_jev_meter_record( 'collision', 0, 0, false, true );
sn_jev_meter_record( 'nonsense', 500 );
$r = sn_jev_meter_reading();
ok( 5 === $r['requests'] && 1 === $r['cached'] && 1 === $r['failed'] && 91500 === $r['input_tokens'], 'B2 five requests: one cached, one failed, tokens from the paid ones only' );
ok( 2 === $r['by_feature']['notes']['requests'] && 1 === $r['by_feature']['notes']['cached'] && 80000 === $r['by_feature']['notes']['input_tokens'] && 0.00336 === $r['by_feature']['notes']['cost'], 'B3 notes: two requests, one cached, 80k tokens, $0.00336' );
ok( isset( $r['by_feature']['other'] ) && 500 === $r['by_feature']['other']['input_tokens'], 'B4 an unknown feature lands in other' );
ok( 5.0 === $r['credit'] && abs( $r['remaining'] - ( 5.0 - $r['spent'] ) ) < 1e-9 && $r['spent'] > 0.003, 'B5 credit 5 by default; remaining is credit minus spent' );
$GLOBALS['__j']['settings']['theme.jev_credit'] = 2.5;
ok( 2.5 === sn_jev_meter_reading()['credit'], 'B6 the credit is a setting' );

// C: cycles are capped at twelve.
$roll = array(); for ( $i = 1; $i <= 14; $i++ ) { $roll[ sprintf( '2025-%02d-17', ( $i % 12 ) + 1 ) . ( $i > 12 ? 'x' : '' ) ] = array(); }
$GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ] = $roll;
sn_jev_meter_record( 'fit', 10 );
ok( SN_JEV_METER_CYCLES === count( $GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ] ) && isset( $GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ][ sn_jev_cycle()['key'] ] ), 'C1 twelve cycles kept, the current one among them' );

// D: the seed.
$GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ] = array();
$GLOBALS['__j']['opt']['sn_jev_notes'] = array( 'usage' => array( 'input_tokens' => 81000 ) );
$GLOBALS['__j']['opt']['sn_jev_lanes'] = array( 'input_tokens' => 484875 );
$GLOBALS['__j']['opt']['sn_jev_query_fit'] = array( 'usage' => array( 'input_tokens' => 1063 ) );
ok( 'seeded' === sn_jev_meter_seed(), 'D1 seeds from the three stored passes' );
$r = sn_jev_meter_reading();
ok( $r['seeded'] && 3 === $r['requests'] && 566938 === $r['input_tokens'] && abs( $r['spent'] - 0.023811 ) < 3e-6, 'D2 seeded reading: 566,938 tokens, $0.0238 (per-feature rounding at six places), marked seeded' );
ok( 'already' === sn_jev_meter_seed() && 3 === sn_jev_meter_reading()['requests'], 'D3 a second seed is a no-op' );
$GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ] = array(); unset( $GLOBALS['__j']['opt']['sn_jev_notes'], $GLOBALS['__j']['opt']['sn_jev_lanes'], $GLOBALS['__j']['opt']['sn_jev_query_fit'] );
ok( 'empty' === sn_jev_meter_seed() && 0 === sn_jev_meter_reading()['requests'], 'D4 nothing stored: seeded empty, still marked so it never runs again' );

// E: the wrapper meters every call.
$GLOBALS['__j']['opt'][ SN_JEV_METER_OPTION ] = array();
$q = array( 'q' => array( 'type' => 'noul', 'instructions' => 'x' ) );
$GLOBALS['__j']['answer'] = $ok200( array( 'q' => array( 'type' => 'noul', 'noul' => 0.5 ) ), 1200 );
sn_jev_ask( 's', $q, 'fit' );
$GLOBALS['__j']['answer'] = array( 'response' => array( 'code' => 529 ), 'body' => '' );
sn_jev_ask( 's', $q, 'fit' );
sn_jev_ask( 's', $q );
$r = sn_jev_meter_reading();
ok( 2 === $r['by_feature']['fit']['requests'] && 1 === $r['by_feature']['fit']['failed'] && 1200 === $r['by_feature']['fit']['input_tokens'] && 1 === $r['by_feature']['other']['failed'], 'E1 a paid answer and a failure under fit; a call without a feature lands in other' );
$GLOBALS['__j']['answer'] = $ok200( array( 'q' => array( 'type' => 'noul', 'noul' => 0.5 ) ), 700 );
$GLOBALS['__j']['cache'] = true;
sn_jev_ask( 's', $q, 'collision' );
$GLOBALS['__j']['cache'] = null;
$r = sn_jev_meter_reading();
ok( 1 === $r['by_feature']['collision']['cached'] && 0 === $r['by_feature']['collision']['input_tokens'] && 0.0 === (float) $r['by_feature']['collision']['cost'], 'E2 a connector cache hit is counted and costs nothing' );

// F: the ability.
$ab = $GLOBALS['__j']['abilities']['signal-noise/jev-meter'] ?? null;
ok( is_array( $ab ) && true === $ab['meta']['annotations']['readonly'] && 'diagnostics' === $ab['category'], 'F1 jev-meter registers read-only under diagnostics' );
$o = snt_ability_jev_meter();
ok( $o['ok'] && $o['ready'] && 0.042 === $o['price_per_m_input'] && isset( ( (array) $o['by_feature'] )['fit'] ) && 2.5 === $o['credit'], 'F2 the ability hands the reading out with the pinned price' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
