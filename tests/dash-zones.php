<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }

require __DIR__ . '/../inc/dash-zones.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard zones — state derivation\n\n";

$green = array( 'label' => 'Health', 'value' => '0', 'pill' => array( 'kind' => 'ok', 'text' => 'all clear' ) );
$warn  = array( 'label' => 'Caches', 'value' => '1/3', 'pill' => array( 'kind' => 'warn', 'text' => 'stale' ) );
$err   = array( 'label' => 'Health', 'value' => '3', 'pill' => array( 'kind' => 'err', 'text' => 'findings' ) );
$cold  = array( 'label' => 'Edge', 'value' => '—', 'measured' => false );
$optout = array( 'label' => 'Cache', 'value' => '—', 'pill' => array( 'kind' => 'warn', 'text' => 'warming' ), 'attention' => false );

ok( sn_dash_zone_state( array( $green, $green ) ) === 'ok', 'all green is ok' );
ok( sn_dash_zone_state( array( $green, $warn ) ) === 'attention', 'a warn makes the zone need attention' );
ok( sn_dash_zone_state( array( $green, $err ) ) === 'attention', 'an err makes the zone need attention' );
ok( sn_dash_zone_state( array() ) === 'ok', 'an empty zone is ok, not unknown' );

// The precedence rule from the spec: unknown outranks attention.
ok( sn_dash_zone_state( array( $cold, $err ) ) === 'unknown', 'UNKNOWN BEATS ATTENTION — you cannot triage what you did not measure' );
ok( sn_dash_zone_state( array( $green, $cold ) ) === 'unknown', 'one unmeasured probe makes the whole zone unknown' );

// measured=false is the ONLY unknown signal. A measured zero is measured.
$zero = array( 'label' => 'Blocks', 'value' => '0', 'measured' => true, 'pill' => array( 'kind' => 'ok', 'text' => 'none' ) );
ok( sn_dash_zone_state( array( $zero ) ) === 'ok', 'a probe that ran and returned 0 is measured, not unknown' );

// The attention opt-out is honoured, same as the existing glance sort.
ok( sn_dash_zone_state( array( $green, $optout ) ) === 'ok', 'a warn card that opted out of attention does not promote the zone' );

// ── open/closed ─────────────────────────────────────────────────────────────
$z_ok      = array( 'id' => 'fleet', 'state' => 'ok' );
$z_att     = array( 'id' => 'attention', 'state' => 'attention' );
$z_unknown = array( 'id' => 'fleet', 'state' => 'unknown' );

ok( sn_dash_zone_is_open( $z_ok, array() ) === false, 'an ok zone is closed by default' );
ok( sn_dash_zone_is_open( $z_unknown, array() ) === false, 'an unknown zone is closed by default' );
ok( sn_dash_zone_is_open( $z_att, array() ) === true, 'an attention zone is open by default' );
ok( sn_dash_zone_is_open( $z_ok, array( 'fleet' ) ) === true, 'a pin opens an ok zone' );
ok( sn_dash_zone_is_open( $z_unknown, array( 'fleet' ) ) === true, 'a pin opens an unknown zone' );

// THE SAFETY PROPERTY. A pin is a view convenience; it must never hide a problem.
ok( sn_dash_zone_is_open( $z_att, array() ) === true, 'an attention zone is open with no pins' );
ok( sn_dash_zone_is_open( $z_att, array( 'other' ) ) === true, 'A PIN CANNOT CLOSE AN ATTENTION ZONE' );
ok( sn_dash_zone_is_open( array( 'id' => 'x' ), array() ) === false, 'a zone with no state is closed, not open' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
