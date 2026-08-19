<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }

// v11.28.1: the shared opt-out predicate lives with its original owner.
require __DIR__ . '/../inc/admin-glance.php';
require __DIR__ . '/../inc/dash-zones.php';
require __DIR__ . '/../inc/dash-zone-attention.php';
require __DIR__ . '/../inc/dash-zone-fleet.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "dashboard zone builders\n\n";

$green = array( 'label' => 'Health', 'pill' => array( 'kind' => 'ok', 'text' => 'clear' ) );
$bad   = array( 'label' => 'Health', 'pill' => array( 'kind' => 'err', 'text' => '3 findings' ) );

$z = sn_dash_zone_attention( array( $green, $green ) );
ok( $z['id'] === 'attention', 'the attention zone has a stable id' );
ok( $z['state'] === 'ok', 'all-green attention zone is ok' );
ok( false !== stripos( $z['summary'], 'nothing needs attention' ), 'and says nothing needs attention' );

$z = sn_dash_zone_attention( array( $green, $bad ) );
ok( $z['state'] === 'attention', 'one bad card flips the zone' );
ok( false !== strpos( $z['summary'], '1' ), 'the summary counts what needs attention' );

$z = sn_dash_zone_attention( array( $bad, $bad ) );
ok( false !== strpos( $z['summary'], '2' ), 'and counts two' );

// The COUNT must agree with the STATE about what counts. A cold probe keeps its
// amber pill and opts out of promotion (v11.16.0); if the count ignores that
// opt-out the summary says "2 need attention" on a zone that considers 1 needy —
// the same cold-caches-lead-the-dashboard regression, back in the summary line.
$cold = array( 'label' => 'Edge cache', 'pill' => array( 'kind' => 'warn', 'text' => 'warming' ), 'attention' => false );
$z    = sn_dash_zone_attention( array( $bad, $cold ) );
ok( $z['state'] === 'attention', 'a real finding beside a cold probe still needs attention' );
ok( false !== strpos( $z['summary'], '1' ) && false === strpos( $z['summary'], '2' ),
	'THE COUNT HONOURS THE OPT-OUT — a cold probe does not inflate it' );

$z = sn_dash_zone_fleet( array( 'theme' => '11.12.0', 'plugin' => '11.27.0' ), '28 minutes ago' );
ok( $z['id'] === 'fleet', 'the fleet zone has a stable id' );
ok( $z['state'] === 'ok', 'all components present is ok' );
ok( false !== strpos( $z['detail'], '28 minutes ago' ), 'the last deploy shows in the detail line' );
ok( false !== strpos( $z['summary'], '2' ), 'the summary counts components' );
ok( count( $z['cards'] ) === 2, 'one card per component' );

// A component that was never probed makes the fleet unknown, not current.
$z = sn_dash_zone_fleet( array( 'theme' => '11.12.0', 'edge' => null ), '28 minutes ago' );
ok( $z['state'] === 'unknown', 'a never-probed component makes the fleet UNKNOWN' );
ok( false !== stripos( $z['summary'], 'not measured' ), 'and the summary says so rather than claiming current' );

// An empty-string version is not a version. A probe that returned '' measured
// nothing, and rendering it as current would claim knowledge we do not have.
$z = sn_dash_zone_fleet( array( 'theme' => '11.12.0', 'worker' => '' ), '' );
ok( $z['state'] === 'unknown', 'an EMPTY-STRING version is unmeasured, not current' );

// No deploy time means no detail line — not a dangling "deploy" label.
ok( $z['detail'] === '', 'an absent deploy time renders no detail line at all' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
