<?php
/**
 * Tests: inc/abilities-machine-readers.php, the read-only Machine Readers
 * summary ability (v10.1.0).
 *
 * The ability is the agent-readable twin of the Desktop Mode tile route
 * (snt_desktop_machine_readers_payload), so the pins here are mostly PARITY
 * pins: same payload shape, same aggregation math, same honesty about a sensor
 * that never answered. The one thing a schema cannot express and a fixture
 * must: an unconfigured sensor surfaces ok:false with the reason, and does
 * NOT paint a zero, because "no data" is not "no crawlers".
 *
 * Run: php tests/abilities-machine-readers.php
 *
 * @since plugin v10.1.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
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

// ── WP + Abilities API seams ─────────────────────────────────────────
$GLOBALS['__acts'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; }
$GLOBALS['__ab'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; }

// ── The sensor read, the one seam under test control ─────────────────
// Shape copied verbatim from inc/machine-readers-api.php (ok / rows / error),
// including the literal 'not_configured' code, so a contract change over there
// shows up here instead of passing silently against a fixture's idea of it.
$GLOBALS['__mr']      = array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
$GLOBALS['__mr_days'] = null;
function snt_mr_fetch( $days = 30 ) {
	$GLOBALS['__mr_days'] = (int) $days;
	return $GLOBALS['__mr'];
}
$GLOBALS['__sensor'] = null;
function snt_mr_sensor_info() { return $GLOBALS['__sensor']; }
$GLOBALS['__status'] = null;
function snt_mr_crawler_list_status() { return $GLOBALS['__status']; }

// The aggregation helpers are the REAL ones, never stubbed: the ability's
// family / AI-training math has to agree with the tab's, and a stub at that
// boundary would hide exactly the drift this file exists to catch.
// inc/machine-readers-render.php only declares functions at load.
require __DIR__ . '/../inc/machine-readers-render.php';

require __DIR__ . '/../inc/abilities-machine-readers.php';
foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) {
	call_user_func( $cb );
}

$slug = 'signal-noise/get-machine-readers-summary';
$a    = $GLOBALS['__ab'][ $slug ] ?? array();

echo "Group A: registration\n";
ok( isset( $GLOBALS['__ab'][ $slug ] ), 'the ability registers on wp_abilities_api_init' );
ok( is_string( $a['label'] ?? null ) && '' !== ( $a['label'] ?? '' ), 'carries a label' );
ok( is_string( $a['description'] ?? null ) && '' !== ( $a['description'] ?? '' ), 'carries a description' );
ok( 'analytics' === ( $a['category'] ?? null ), 'cites the registered analytics category (no WP 6.9.0 doing_it_wrong)' );

echo "\nGroup B: permission callback identity\n";
// A named string callable, not a closure: the shared manage_options guard the
// sibling read abilities use, and the same cap the DM route gates on.
ok( is_string( $a['permission_callback'] ?? null ), 'permission_callback is a named callable, not an inline closure' );
ok( 'snt_ability_perm_manage_options' === ( $a['permission_callback'] ?? null ), 'permission_callback is the shared manage_options guard' );
ok( 'snt_ability_get_machine_readers_summary' === ( $a['execute_callback'] ?? null ), 'execute_callback names the summary wrapper' );
ok( function_exists( 'snt_ability_get_machine_readers_summary' ), 'the execute callback actually exists' );

echo "\nGroup C: annotations, a pure read (readonly => GET on the agent run-path)\n";
$ann = $a['meta']['annotations'] ?? array();
ok( ! empty( $a['meta']['show_in_rest'] ), 'exposed in REST' );
ok( true === ( $ann['readonly'] ?? null ), 'readonly true (else abilities-api forces POST and the honest GET 405s)' );
ok( true === ( $ann['idempotent'] ?? null ), 'idempotent true' );
ok( empty( $ann['destructive'] ), 'not destructive' );

echo "\nGroup D: input schema, optional days 1..90, default 30\n";
$in = $a['input_schema'] ?? array();
// The object|null union is the GET/null run-path the sibling reads declare;
// desktop-mode's Copilot boundary normalizes it (see desktop_mode_ai_tools).
ok( array( 'object', 'null' ) === ( $in['type'] ?? null ), 'input type is the object|null union (callable with no input)' );
ok( false === ( $in['additionalProperties'] ?? null ), 'additionalProperties false' );
$days_schema = $in['properties']['days'] ?? array();
ok( 'integer' === ( $days_schema['type'] ?? null ), 'days is an integer' );
ok( 30 === ( $days_schema['default'] ?? null ), 'days defaults to 30 (the DM tile window)' );
ok( 1 === ( $days_schema['minimum'] ?? null ), 'days minimum 1' );
ok( 90 === ( $days_schema['maximum'] ?? null ), 'days maximum 90 (the sensor clamp)' );
ok( array( 'days' ) === array_keys( $in['properties'] ?? array() ), 'days is the ONLY input' );

echo "\nGroup E: output schema keys\n";
$props = $a['output_schema']['properties'] ?? array();
ok( 'object' === ( $a['output_schema']['type'] ?? null ), 'output type is object' );
ok(
	array( 'ok', 'days', 'total', 'families', 'ai_training', 'ai_rights', 'sensor_version', 'crawler_list', 'error' ) === array_keys( $props ),
	'schema pins the DM tile payload fields in response order, with error appended'
);
ok( 'boolean' === ( $props['ok']['type'] ?? null ), 'ok is a boolean' );
ok( 'integer' === ( $props['days']['type'] ?? null ), 'days is an integer' );
ok( 'integer' === ( $props['total']['type'] ?? null ), 'total is an integer' );
ok( 'array' === ( $props['families']['type'] ?? null ), 'families is an array' );
ok( 'object' === ( $props['families']['items']['type'] ?? null ), 'families items are objects' );
ok( array( 'family', 'hits' ) === array_keys( $props['families']['items']['properties'] ?? array() ), 'a family row is { family, hits }' );
ok( array( 'string', 'null' ) === ( $props['sensor_version']['type'] ?? null ), 'sensor_version is string|null (null = the version read failed)' );
ok( array( 'string', 'null' ) === ( $props['crawler_list']['type'] ?? null ), 'crawler_list is string|null' );
ok( array( 'string', 'null' ) === ( $props['error']['type'] ?? null ), 'error is string|null' );

echo "\nGroup F: an unconfigured sensor is LOUD, never a fabricated zero\n";
$GLOBALS['__mr'] = array( 'ok' => false, 'rows' => array(), 'error' => 'not_configured' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 14 ) );
ok( is_array( $out ), 'returns an array' );
ok( false === ( $out['ok'] ?? null ), 'ok is strictly false' );
ok( 'not_configured' === ( $out['error'] ?? null ), 'the reason rides along verbatim' );
ok( 14 === ( $out['days'] ?? null ), 'the requested window is echoed back' );
// THE POINT OF THIS FILE. A zero total would read as "no crawler touched this
// site in 14 days", which is a different and false claim from "we never asked".
ok( ! array_key_exists( 'total', $out ), 'no total is invented (absent, not 0)' );
ok( ! array_key_exists( 'families', $out ), 'no families array is invented' );
ok( ! array_key_exists( 'ai_training', $out ), 'no ai_training count is invented' );
ok( array( 'ok', 'error', 'days' ) === array_keys( $out ), 'failure shape matches the DM route exactly' );

$GLOBALS['__mr'] = array( 'ok' => false, 'rows' => array(), 'error' => 'http_502' );
$out = snt_ability_get_machine_readers_summary( null );
ok( false === ( $out['ok'] ?? null ) && 'http_502' === ( $out['error'] ?? null ), 'a transport failure is equally loud (null input path)' );
ok( 30 === ( $out['days'] ?? null ), 'null input falls back to the 30-day default' );

echo "\nGroup G: aggregation over canned rows\n";
$GLOBALS['__mr'] = array(
	'ok'    => true,
	'error' => null,
	'rows'  => array(
		array( 'family' => 'openai',    'surface' => 'llms',   'day' => '2026-07-28', 'hits' => 10 ),
		array( 'family' => 'openai',    'surface' => 'rights', 'day' => '2026-07-28', 'hits' => 5 ),
		array( 'family' => 'anthropic', 'surface' => 'rights', 'day' => '2026-07-27', 'hits' => 7 ),
		array( 'family' => 'search',    'surface' => 'robots', 'day' => '2026-07-27', 'hits' => 20 ),
		array( 'family' => 'seo',       'surface' => 'html',   'day' => '2026-07-26', 'hits' => 3 ),
		array( 'family' => 'other-bot', 'surface' => 'html',   'day' => '2026-07-26', 'hits' => 1 ),
	),
);
$rows_before         = $GLOBALS['__mr']['rows'];
$GLOBALS['__sensor'] = array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28' );
$GLOBALS['__status'] = array( 'last_check_ok' => '1', 'last_check_drift' => '' );

$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( true === ( $out['ok'] ?? null ), 'ok true on a good read' );
ok( 7 === $GLOBALS['__mr_days'], 'the requested window reaches snt_mr_fetch' );
ok( 46 === ( $out['total'] ?? null ), 'total === 46 (every row, every surface)' );
ok(
	array(
		array( 'family' => 'search',    'hits' => 20 ),
		array( 'family' => 'openai',    'hits' => 15 ),
		array( 'family' => 'anthropic', 'hits' => 7 ),
	) === ( $out['families'] ?? null ),
	'families is the top 3 by hits, descending (a glance, not the table)'
);
ok( 22 === ( $out['ai_training'] ?? null ), 'ai_training === 22 (openai 15 + anthropic 7; search/seo/other-bot excluded)' );
ok( 12 === ( $out['ai_rights'] ?? null ), 'ai_rights === 12 (the rights-surface slice of those two)' );
ok( '1.4.0' === ( $out['sensor_version'] ?? null ), 'sensor_version passes through' );
ok( 'in sync' === ( $out['crawler_list'] ?? null ), 'crawler_list verdict: ok + no drift => in sync' );
ok(
	array( 'ok', 'days', 'total', 'families', 'ai_training', 'ai_rights', 'sensor_version', 'crawler_list' ) === array_keys( $out ),
	'success shape matches the DM route exactly'
);
ok( $rows_before === $GLOBALS['__mr']['rows'], 'the fetched rows are never mutated' );

echo "\nGroup H: degraded provenance stays null, verdicts stay honest\n";
$GLOBALS['__status'] = array( 'last_check_ok' => '1', 'last_check_drift' => '1' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( 'drift' === ( $out['crawler_list'] ?? null ), 'ok + drift => drift' );
$GLOBALS['__status'] = array( 'last_check_ok' => '', 'last_check_drift' => '' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( 'check failed' === ( $out['crawler_list'] ?? null ), 'not ok => check failed' );
$GLOBALS['__status'] = null;
$GLOBALS['__sensor'] = null;
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( array_key_exists( 'crawler_list', $out ) && null === $out['crawler_list'], 'no status document => crawler_list null, not a guess' );
ok( array_key_exists( 'sensor_version', $out ) && null === $out['sensor_version'], 'no version document => sensor_version null' );

echo "\nGroup I: the days input is clamped and coerced at the boundary\n";
$GLOBALS['__sensor'] = array( 'version' => '1.4.0', 'deployed_at' => '2026-07-28' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 900 ) );
ok( 90 === ( $out['days'] ?? null ) && 90 === $GLOBALS['__mr_days'], 'days above the ceiling clamps to 90' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 0 ) );
ok( 1 === ( $out['days'] ?? null ) && 1 === $GLOBALS['__mr_days'], 'days below the floor clamps to 1' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => '7' ) );
ok( 7 === ( $out['days'] ?? null ), 'a numeric string coerces to int (REST query params arrive as strings)' );
$input = array( 'days' => 7 );
snt_ability_get_machine_readers_summary( $input );
ok( array( 'days' => 7 ) === $input, 'the input array is never mutated' );

echo "\nGroup J: delegation to the Desktop Mode tile route\n";
// Declared HERE, inside a conditional block so PHP does not hoist it: every
// group above had to exercise the standalone rebuild path, which only runs
// while snt_desktop_machine_readers_payload() is absent.
ok( ! function_exists( 'snt_desktop_machine_readers_payload' ), 'the groups above ran the standalone rebuild path' );
if ( true ) {
	function snt_desktop_machine_readers_payload() {
		$GLOBALS['__dm_calls'] = ( $GLOBALS['__dm_calls'] ?? 0 ) + 1;
		return array( 'ok' => true, 'days' => 30, 'total' => 999, 'families' => array(), 'ai_training' => 0, 'ai_rights' => 0, 'sensor_version' => 'dm', 'crawler_list' => null );
	}
}
$GLOBALS['__dm_calls'] = 0;
$out = snt_ability_get_machine_readers_summary( array( 'days' => 30 ) );
ok( 1 === $GLOBALS['__dm_calls'] && 999 === ( $out['total'] ?? null ), 'days=30 delegates to the DM route (one payload builder, no second copy to drift)' );
$out = snt_ability_get_machine_readers_summary( null );
ok( 2 === $GLOBALS['__dm_calls'], 'the default window delegates too' );
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( 2 === $GLOBALS['__dm_calls'], 'a non-default window does NOT delegate (the DM route hardcodes 30 days)' );
ok( 46 === ( $out['total'] ?? null ) && 7 === ( $out['days'] ?? null ), 'the non-default window rebuilds and answers for the window actually asked for' );

echo "\nGroup K: orchestrator wiring\n";
$reg = (string) file_get_contents( __DIR__ . '/../inc/abilities-registration.php' );
ok( 1 === preg_match( '/^\s*require_once\s+__DIR__\s*\.\s*.\/abilities-machine-readers\.php/m', $reg ), 'inc/abilities-registration.php requires the new file the way its siblings are required' );

// v10.2.0 (verifier finding): the ai_rights DESCRIPTION must not enumerate
// files the COUNT excludes. The surface enum is disjoint — robots and llms are
// their own classes — so naming them here would tell an agent that ai_rights:0
// means "read no declarations", which is false when robots.txt was fetched 40
// times. Pin both directions.
$mr_desc = (string) ( $GLOBALS['__ab']['signal-noise/get-machine-readers-summary']['description'] ?? '' );
ok( false === strpos( $mr_desc, 'robots.txt, TDMRep' ), 'the description does not claim robots.txt is inside ai_rights' );
ok( false !== strpos( $mr_desc, 'are NOT counted here' ), 'and it says explicitly which surfaces are excluded' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
