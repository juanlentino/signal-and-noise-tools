<?php
/**
 * Tests for inc/health-motion-scan.php — Motion that asks first (check #20,
 * report only). The scan reads the SAME sheet population and rule parser as
 * the contrast usage tier and answers one question per motion declaration:
 * does this animation or transition respect a visitor's reduced-motion
 * setting — either GATED (declared inside prefers-reduced-motion:
 * no-preference, motion that literally asks first) or NEUTRALIZED (a reduce
 * block sets it to none, same sheet or any scanned sheet)?
 *
 * Run: php tests/health-motion-scan.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
function __( $s, $d = null ) { return (string) $s; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}

// The REAL contrast-usage parser — the motion scan rides it; stubbing it here
// would green a scan the live path never runs (the stub-drift trap).
require __DIR__ . '/../inc/health-contrast-usage.php';
require __DIR__ . '/../inc/health-motion-scan.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Motion that asks first — the reduced-motion scan\n\n";

echo "Group: kinds — what counts as motion\n";
ok( array( 'transition' ) === sn_health_motion_kinds( 'transition: color .15s ease;' ), 'a real transition is motion' );
ok( array() === sn_health_motion_kinds( 'transition: none;' ), 'transition none is not motion' );
ok( array() === sn_health_motion_kinds( 'transition: none !important;' ), 'transition none !important is not motion' );
ok( array( 'animation' ) === sn_health_motion_kinds( 'animation: sn-pulse 2s infinite;' ), 'an animation is motion' );
ok( array( 'animation' ) === sn_health_motion_kinds( 'animation-name: sn-pulse;' ), 'animation-name alone is motion' );
ok( array() === sn_health_motion_kinds( 'animation: none;' ), 'animation none is not motion' );
ok( array( 'transition', 'animation' ) === sn_health_motion_kinds( 'transition: opacity .2s; animation: sn-x 1s;' ), 'both kinds detected together' );
ok( array() === sn_health_motion_kinds( 'transition-property: color;' ), 'transition-property alone declares no duration — watched as a limit, not flagged (the shorthand is the house idiom)' );

echo "\nGroup: neutralizers\n";
ok( true === sn_health_motion_neutralizes( 'transition: none;', 'transition' ), 'transition none neutralizes transitions' );
ok( true === sn_health_motion_neutralizes( 'animation: none; transition: none;', 'animation' ), 'a combined reset neutralizes animation' );
ok( false === sn_health_motion_neutralizes( 'transition: none;', 'animation' ), 'a transition reset does NOT neutralize an animation — the kinds are separate claims' );
ok( false === sn_health_motion_neutralizes( 'transition: color .1s;', 'transition' ), 'declaring MORE motion under reduce neutralizes nothing' );

echo "\nGroup: the report over fixture sheets (real parser)\n";
$sheets = array(
	'gated.css'   => '@media (prefers-reduced-motion: no-preference) { .sn-a { transition: opacity .2s; } }',
	'covered.css' => '.sn-b { transition: color .15s; } @media (prefers-reduced-motion: reduce) { .sn-b { transition: none; } }',
	'listed.css'  => '.sn-c { transition: transform .2s; } .sn-d { animation: sn-spin 1s; } @media (prefers-reduced-motion: reduce) { .sn-c, .sn-d { transition: none; animation: none; } }',
	'naked.css'   => '.sn-e { transition: box-shadow .2s; } .sn-f:hover { transition: color .1s; }',
	'cross.css'   => '.sn-g { animation: sn-drift 3s; }',
	'global.css'  => '@media (prefers-reduced-motion: reduce) { * { animation: none; } }',
);
$report = sn_health_motion_report_from_sheets( $sheets );
ok( 6 === $report['scanned'], 'all six fixture sheets scanned' );
ok( 7 === $report['motion_total'], 'seven motion declarations found (gated + covered + two listed + two naked + one cross-sheet)' );
ok( 1 === $report['gated'], 'the no-preference declaration counts as GATED — motion that asks first needs no counterpart' );
ok( 4 === $report['neutralized'], 'same-sheet, selector-list, and CROSS-SHEET universal neutralizers all count (b, c, d, g)' );
$uncovered = array();
foreach ( $report['uncovered'] as $u ) { $uncovered[] = $u['selector']; }
sort( $uncovered );
ok( array( '.sn-e', '.sn-f:hover' ) === $uncovered, 'exactly the two naked declarations are uncovered — including the hover state, because a hover transition is still motion' );
ok( '.sn-e' === $report['uncovered'][0]['selector'] || '.sn-f:hover' === $report['uncovered'][0]['selector'], 'uncovered rows carry their selector' );
ok( 'naked.css' === $report['uncovered'][0]['sheet'], 'and their sheet' );

echo "\nGroup: cross-sheet universal covers animation but not transition\n";
$sheets2 = array(
	'x.css'      => '.sn-h { transition: color .2s; }',
	'global.css' => '@media (prefers-reduced-motion: reduce) { * { animation: none; } }',
);
$r2 = sn_health_motion_report_from_sheets( $sheets2 );
ok( 1 === count( $r2['uncovered'] ), 'a universal animation reset does not cover a TRANSITION — the kinds stay separate claims across sheets too' );

echo "\nGroup: the packed check — report only\n";
$check = sn_health_check_motion_scan();
ok( 0 === $check['count'] && array() === $check['findings'], 'ZERO findings by design — report-first: the report is the deliverable, fixes are a later step' );
ok( isset( $check['report']['coverage'] ) && false !== strpos( $check['report']['coverage'], 'JS' ), 'the coverage sentence names the declared-tier limit: script-driven motion is invisible here' );
ok( isset( $check['report']['motion_total'], $check['report']['uncovered'] ), 'the report carries the totals and the uncovered table' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
