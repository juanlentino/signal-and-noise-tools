<?php
/**
 * Rights evidence on Monitoring > Machine Readers, both twins (19.10.0):
 * inc/admin-forms/rights-evidence.php (classic),
 * apps/sn-dashboard/parts/leaves/monitoring-machine-readers-evidence.php
 * (native) and inc/admin-post-actions/rights-evidence.php (the two actions).
 *
 * Run: php tests/rights-evidence-admin.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The module's readers, from fixtures (the module itself is pinned in tests/rights-evidence.php).
const SN_RIGHTS_EVIDENCE_HOOK = 'sn_rights_evidence_daily';
$GLOBALS['__re'] = array( 'ready' => true, 'sensor' => array( 'version' => '1.29.0', 'reachable' => true ), 'next' => strtotime( '2026-10-01T21:43:00Z' ), 'dry' => 0 );
function sn_rights_evidence_held( $seed = true ) { return $GLOBALS['__options']['sn_rights_evidence_hold'] ?? array( '2026-09' ); }
function sn_rights_evidence_backlog() { return $GLOBALS['__options']['sn_rights_evidence_backlog'] ?? array(); }
function sn_rights_evidence_is_ready() { return $GLOBALS['__re']['ready']; }
function snt_mr_sensor_info() { return $GLOBALS['__re']['sensor']; }
function wp_next_scheduled( $h ) { return SN_RIGHTS_EVIDENCE_HOOK === $h ? $GLOBALS['__re']['next'] : false; }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
function sn_rights_evidence_dry_run( $ym ) { $GLOBALS['__re']['dry']++; return array( 'ok' => true, 'month' => $ym, 'error' => '', 'payloads' => array() ); }

require SNT_PATH . 'inc/admin-post-actions/rights-evidence.php';
require SNT_PATH . 'inc/admin-forms/rights-evidence.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-machine-readers-evidence.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
$native  = static fn() => html_entity_decode( \SignalNoise\OpenStationHost\Dashboard\Leaves\machine_readers_rights_evidence_html(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
$classic = static function () { ob_start(); sn_admin_render_rights_evidence(); return html_entity_decode( (string) ob_get_clean(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); };

// A: the actions are registered with the dispatcher (nonce + capability are its job).
$handler_src = (string) file_get_contents( SNT_PATH . 'inc/admin-post-handler.php' );
ok( str_contains( $handler_src, "'rights_evidence_view'       => 'sn_handle_rights_evidence_view'" ) && str_contains( $handler_src, "'rights_evidence_lift'       => 'sn_handle_rights_evidence_lift'" ), 'A1 both actions are in sn_admin_post_handlers()' );
ok( str_contains( (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions.php' ), "admin-post-actions/rights-evidence.php" ), 'A2 the handler file is loaded' );

// B: both twins paint the same controls.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ), 'sn_rights_evidence_backlog' => array( '2026-08' ) );
$c = $classic(); $n = $native();
foreach ( array( 'classic' => $c, 'native' => $n ) as $twin => $h ) {
	ok( str_contains( $h, 'Held: 2026-09. Backlog: 2026-08. Next pass: 2026-10-01 21:43 UTC.' ), "B1 $twin: the status line names held months, backlog and the next pass" );
	ok( str_contains( $h, 'View September 2026 payloads' ) && str_contains( $h, 'action=sn_rights_evidence_view' ) && str_contains( $h, 'month=2026-09' ) && str_contains( $h, '_wpnonce=nonce-sn_rights_evidence_view' ) && str_contains( $h, 'page=sn-monitoring' ), "B2 $twin: a View door per held month, admin-post GET with its own nonce" );
	ok( str_contains( $h, 'Lift September 2026 hold' ) && str_contains( $h, 'sn_rights_evidence_lift' ) && str_contains( $h, 'append-only ledger' ), "B3 $twin: a Lift button behind a confirm that says what it publishes" );
}
ok( array( 'month', 'sub', 'tab' ) === array_values( array_diff( snt_leaf_names( $n ), array( 'action', '_wpnonce' ) ) ) && snt_leaf_names( $c ) === snt_leaf_names( $n ), 'B4 the native form posts the same names as the classic: ' . implode( ',', snt_leaf_names( $n ) ) );
ok( 0 === $GLOBALS['__re']['dry'], 'B5 painting never composes (no dry run, no sensor reads per family)' );
$GLOBALS['__re']['sensor'] = null;
ok( ! str_contains( $classic(), 'Lift September' ) && ! str_contains( $native(), 'Lift September' ) && str_contains( $native(), 'Lift appears once' ) && str_contains( $classic(), 'View September 2026 payloads' ), 'B6 sensor unreachable: no Lift on either twin, the hint says why, View stays' );
$GLOBALS['__re']['sensor'] = array( 'reachable' => true ); $GLOBALS['__re']['ready'] = false;
ok( ! str_contains( $native(), 'Lift September' ) && ! str_contains( $classic(), 'Lift September' ), 'B7 worker not set up: no Lift' );
$GLOBALS['__re']['ready'] = true;

// C: Lift removes one month from the hold and nothing else.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09', '2026-10' ) );
ok( 'rights_evidence_lifted' === sn_handle_rights_evidence_lift( array( 'month' => '2026-09' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'] && 0 === $GLOBALS['__re']['dry'], 'C1 Lift takes the month off the hold, keeps the rest, and runs nothing' );
ok( 'rights_evidence_not_held' === sn_handle_rights_evidence_lift( array( 'month' => '2026-08' ) ) && 'rights_evidence_not_held' === sn_handle_rights_evidence_lift( array( 'month' => '2026-10\'"' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C2 a month not on hold (or not a month) changes nothing' );
$GLOBALS['__re']['sensor'] = null;
ok( 'rights_evidence_lift_refused' === sn_handle_rights_evidence_lift( array( 'month' => '2026-10' ) ) && array( '2026-10' ) === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C3 the handler re-checks the gate: sensor unreachable, the hold stands' );
$GLOBALS['__re']['sensor'] = array( 'reachable' => true );
unset( $GLOBALS['__options']['sn_rights_evidence_hold'] );
ok( 'rights_evidence_lifted' === sn_handle_rights_evidence_lift( array( 'month' => '2026-09' ) ) && array() === $GLOBALS['__options']['sn_rights_evidence_hold'], 'C4 the seeded default (2026-09, never stored) lifts too, and stays lifted' );
$flash = (string) file_get_contents( SNT_PATH . 'inc/admin-flash-messages.php' );
ok( str_contains( $flash, "'rights_evidence_lifted'" ) && str_contains( $flash, "'rights_evidence_lift_refused'" ) && str_contains( $flash, "'rights_evidence_not_held'" ), 'C5 every code the handlers return has a flash message' );

// D: View refuses a month that is not held before composing anything.
$GLOBALS['__options'] = array( 'sn_rights_evidence_hold' => array( '2026-09' ) );
$_GET = array( 'month' => '2026-07' );
ok( 'rights_evidence_not_held' === sn_handle_rights_evidence_view( array() ) && 0 === $GLOBALS['__re']['dry'], 'D1 View for a month not on hold is refused without a dry run' );
$view_src = (string) file_get_contents( SNT_PATH . 'inc/admin-post-actions/rights-evidence.php' );
ok( ! preg_match( '/wp_remote_post|sn_rights_evidence_post|sn_rights_evidence_run|sn_rights_evidence_send/', $view_src ), 'D2 structurally: neither action can post or run the pass' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail ? 1 : 0 );
