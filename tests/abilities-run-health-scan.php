<?php
/**
 * Tests for the signal-noise/run-health-scan ability
 * (inc/abilities-system.php, added v9.78.0) — the one one-shot
 * maintenance action that previously had no ability (and so no ⌘K
 * mirror): the health re-scan lived exclusively behind the admin
 * page button.
 *
 * Covers: registration on wp_abilities_api_init with owner perms +
 * honest annotations, and the execute callback's summary shape
 * (total + flagged from the scan's own accessors; honest false when
 * the scan seam is absent or returns garbage).
 *
 * Run: php tests/abilities-run-health-scan.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

echo "abilities-run-health-scan suite\n";

$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }

require __DIR__ . '/../inc/abilities-system.php';

// ─── Execute callback degrades honestly without the scan seam ────────────
$absent = snt_ability_run_health_scan();
ok( false === $absent['ok'] && 0 === $absent['total'] && 0 === $absent['flagged'],
	'returns ok:false with zeroed counts when sn_health_run_scan is absent' );

// ─── Happy path against stubbed scan accessors ───────────────────────────
function sn_health_run_scan() { return $GLOBALS['__scan']; }
function sn_health_check_total( $scan ) { return 12; }
function sn_health_flagged_checks( $scan ) { return array( 'a' => array(), 'b' => array() ); }

$GLOBALS['__scan'] = false; // a non-array scan result is a failed run, not a clean one
$bad = snt_ability_run_health_scan();
ok( false === $bad['ok'], 'a non-array scan result reports ok:false, never a fake pass' );

$GLOBALS['__scan'] = array( 'checks' => array( 'x' => array() ), 'scanned_at' => 1752660000 );
$res = snt_ability_run_health_scan();
ok( true === $res['ok'] && 12 === $res['total'] && 2 === $res['flagged'],
	'summary carries the scan accessors\' own totals (12 total, 2 flagged)' );

// ─── Registration ────────────────────────────────────────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; return true; }
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { call_user_func( $cb ); }

ok( isset( $GLOBALS['__abilities']['signal-noise/run-health-scan'] ), 'run-health-scan registered on wp_abilities_api_init' );
$reg = $GLOBALS['__abilities']['signal-noise/run-health-scan'];
ok( 'snt_ability_perm_manage_options' === $reg['permission_callback'], 'owner-gated' );
ok( 'maintenance' === $reg['category'], 'registered in the maintenance category' );
ok( 'snt_ability_run_health_scan' === $reg['execute_callback'], 'execute callback wired' );
ok( false === ( $reg['meta']['annotations']['readonly'] ?? null ) && true === ( $reg['meta']['annotations']['idempotent'] ?? null ),
	'annotated non-readonly (POST run-path) + idempotent' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
