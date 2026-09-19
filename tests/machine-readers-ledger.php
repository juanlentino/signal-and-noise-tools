<?php
/**
 * Tests: inc/machine-readers-ledger.php + inc/abilities-machine-readers-ledger.php.
 *
 * The folds are pure, so fixtures drive them directly. The abilities are
 * pinned for registration shape and for the one contract a schema cannot
 * express: a sensor that did not answer is ok:false with the reason and no
 * rows, never a zero.
 *
 * Run: php tests/machine-readers-ledger.php
 *
 * @since 17.0.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

$GLOBALS['__acts'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; }
$GLOBALS['__ab'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }
// The sensor seam: one fixture per view, the real signature.
$GLOBALS['__mr'] = array();
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
	$GLOBALS['__mr_calls'][] = array( $days, $view );
	return $GLOBALS['__mr'][ $view ] ?? array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
}

require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-render.php';
require __DIR__ . '/../inc/machine-readers-ledger.php';
require __DIR__ . '/../inc/abilities-machine-readers-ledger.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] as $cb ) {
	call_user_func( $cb );
}

$agg = function ( $family, $purpose, $agent, $surface, $day, $hits ) {
	return compact( 'family', 'purpose', 'agent', 'surface', 'day', 'hits' ) + array( 'taxonomy_version' => '1.4' );
};

echo "Group A: the crosstab fold\n";
$rows = array(
	$agg( 'openai', 'train', 'openai-gptbot', 'html', '2026-09-01', 5 ),
	$agg( 'openai', 'train', 'openai-gptbot', 'rights', '2026-09-02', 1 ),
	$agg( 'openai', 'search', 'openai-searchbot', 'html', '2026-09-01', 9 ),
	$agg( 'google', 'search', 'googlebot', 'html', '2026-09-01', 40 ),
	'not a row',
);
$x = snt_mr_crosstab( $rows );
ok( 3 === count( $x['cells'] ), 'one cell per family x purpose x agent, the non-array row dropped' );
ok( 'google' === $x['cells'][0]['family'] && 40 === $x['cells'][0]['hits'], 'cells descend by hits' );
$oa = $x['cells'][2];
ok( 'openai' === $oa['family'] && 'train' === $oa['purpose'] && 6 === $oa['hits'], 'a family with two purposes is two cells; the train cell sums its rows' );
ok( 2 === $oa['days'], 'days counts distinct days the cell was seen on' );
ok( array( 'html' => 5, 'rights' => 1 ) === $oa['surfaces'], 'surfaces split the cell by surface class, descending' );
ok( 55 === $x['total'] && 2 === $x['families'], 'total and family count' );
ok( array( 'cells' => array(), 'total' => 0, 'families' => 0 ) === snt_mr_crosstab( array() ), 'no rows is an empty crosstab, not an error' );
ok( 'unknown' === snt_mr_crosstab( array( array( 'family' => 'x', 'hits' => 1 ) ) )['cells'][0]['purpose'], 'a row with no purpose lands on unknown' );

echo "Group B: the rights cadence fold\n";
$read = function ( $family, $path, $at, $hits = 1, $vendor = 'v', $purpose = 'train' ) {
	return array( 'observed_at' => $at, 'family' => $family, 'path' => $path, 'hits' => $hits, 'vendor' => $vendor, 'purpose' => $purpose );
};
$reads = array(
	$read( 'openai', '/license.xml', '2026-09-03T00:00:00Z' ),
	$read( 'openai', '/license.xml', '2026-09-01T00:00:00Z' ),
	$read( 'openai', '/license.xml', '2026-09-02T00:00:00Z' ),
	$read( 'openai', '/license.xml', '2026-09-04T00:00:00Z' ),
	$read( 'openai', '/license.xml', '2026-09-05T00:00:00Z' ),
	$read( 'anthropic', '/tdm-policy', '2026-09-01T00:00:00Z', 2 ),
	$read( 'anthropic', '/tdm-policy', '2026-09-01T06:00:00Z' ),
	$read( 'anthropic', '/tdm-policy', '2026-09-20T00:00:00Z' ),
	$read( 'x', '/license.xml', 'garbage' ),
);
$c = snt_mr_rights_cadence( $reads );
ok( 2 === count( $c ), 'one row per family x path; a row with an unparseable timestamp is dropped' );
$o = $c[0];
ok( 'openai' === $o['family'] && 5 === $o['reads'], 'rows descend by reads' );
ok( '2026-09-01T00:00:00+00:00' === $o['first'] && '2026-09-05T00:00:00+00:00' === $o['last'], 'first and last come from time order, not row order' );
ok( 86400 === $o['median_interval_s'] && 0.0 === $o['regularity'], 'a daily fetch: median one day, regularity 0' );
ok( true === $o['poller'], 'five reads a day apart is a poller' );
$a = $c[1];
ok( 4 === $a['reads'], 'reads sum hits, so a 2-hit row counts twice (three rows, four reads)' );
ok( false === $a['poller'] && $a['regularity'] > SN_MR_POLLER_MAX_CV, 'two reads six hours apart then one three weeks later is not a poller' );
ok( array( 'v' ) === $a['vendors'] && array( 'train' ) === $a['purposes'], 'vendors and purposes seen are listed' );
$one = snt_mr_rights_cadence( array( $read( 'f', '/p', '2026-09-01T00:00:00Z' ) ) )[0];
ok( null === $one['median_interval_s'] && null === $one['regularity'] && false === $one['poller'], 'a single read has no interval, no regularity, and is not a poller' );
ok( null === snt_mr_cv( array( 5 ) ) && null === snt_mr_cv( array( 0, 0 ) ), 'cv is null under two values or at a zero mean' );

echo "Group C: the ai_rights pair\n";
$pair = snt_mr_ai_rights_pair(
	array( $agg( 'openai', 'train', 'a', 'rights', '2026-09-01', 3 ), $agg( 'openai', 'train', 'a', 'html', '2026-09-01', 30 ), $agg( 'google', 'search', 'g', 'rights', '2026-09-01', 7 ) ),
	array( $read( 'openai', '/license.xml', '2026-09-01T00:00:00Z', 2 ), $read( 'google', '/license.xml', '2026-09-01T00:00:00Z' ) )
);
ok( array( 'aggregate' => 3, 'detail' => 2 ) === $pair, 'aggregate counts AI families on the rights surface only; detail counts AI families in the stream; neither counts google' );

echo "Group D: registration\n";
foreach ( array( 'signal-noise/get-machine-readers-crosstab' => 'snt_ability_get_machine_readers_crosstab', 'signal-noise/get-rights-reads' => 'snt_ability_get_rights_reads' ) as $slug => $cb ) {
	$r = $GLOBALS['__ab'][ $slug ] ?? array();
	ok( $cb === ( $r['execute_callback'] ?? null ) && function_exists( $cb ), "$slug names an existing callback" );
	ok( 'snt_ability_perm_manage_options' === ( $r['permission_callback'] ?? null ), "$slug is manage_options gated" );
	ok( 'analytics' === ( $r['category'] ?? null ), "$slug cites the analytics category" );
	ok( true === ( $r['meta']['annotations']['readonly'] ?? null ) && ! empty( $r['meta']['show_in_rest'] ), "$slug is readonly and in REST" );
	ok( array( 'object', 'null' ) === ( $r['input_schema']['type'] ?? null ) && 90 === ( $r['input_schema']['properties']['days']['maximum'] ?? null ), "$slug takes the nullable days input, capped at 90" );
	ok( false === strpos( (string) ( $r['description'] ?? '' ), "\u{2014}" ), "$slug description carries no em dash" );
}

echo "Group E: the sensor contract\n";
$GLOBALS['__mr'] = array();
$out = snt_ability_get_machine_readers_crosstab( null );
ok( false === $out['ok'] && 'not_configured' === $out['error'] && ! isset( $out['cells'] ) && ! isset( $out['total'] ), 'crosstab: an unconfigured sensor is ok:false with the reason and no counts' );
$out = snt_ability_get_rights_reads( array( 'days' => 400 ) );
ok( false === $out['ok'] && 90 === $out['days'] && ! isset( $out['reads'] ), 'rights reads: same contract; the window is clamped' );

$GLOBALS['__mr'] = array(
	'aggregate' => array( 'ok' => true, 'rows' => $rows, 'truncated' => true, 'error' => null ),
	'rights'    => array( 'ok' => true, 'rows' => array( $read( 'openai', '/license.xml', '2026-09-01T00:00:00Z' ) + array( 'user_agent' => 'GPTBot/1.0', 'accept' => '*/*' ) ), 'truncated' => false, 'error' => null ),
);
$GLOBALS['__mr_calls'] = array();
$out = snt_ability_get_machine_readers_crosstab( array( 'days' => 7 ) );
ok( true === $out['ok'] && 7 === $out['days'] && 3 === count( $out['cells'] ) && true === $out['truncated'] && false === $out['taxonomy_absent'], 'crosstab: folds the aggregate rows and carries the truncation flag' );
ok( array( array( 7, 'aggregate' ) ) === $GLOBALS['__mr_calls'], 'crosstab reads the aggregate view once' );
$GLOBALS['__mr_calls'] = array();
$out = snt_ability_get_rights_reads( null );
ok( true === $out['ok'] && 1 === count( $out['reads'] ) && 1 === count( $out['cadence'] ), 'rights reads: rows and cadence' );
ok( ! isset( $out['reads'][0]['user_agent'] ) && ! isset( $out['reads'][0]['accept'] ), 'the user-agent string and Accept header never leave the plugin' );
ok( array( 'aggregate' => 1, 'detail' => 1 ) === $out['ai_rights'], 'the ai_rights pair rides along' );
ok( array( array( 30, 'rights' ), array( 30, 'aggregate' ) ) === $GLOBALS['__mr_calls'], 'rights reads read the rights stream then the aggregate, same window' );
unset( $GLOBALS['__mr']['aggregate'] );
$out = snt_ability_get_rights_reads( null );
ok( true === $out['ok'] && null === $out['ai_rights'], 'a failed aggregate read leaves the pair null; the rows still answer' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
