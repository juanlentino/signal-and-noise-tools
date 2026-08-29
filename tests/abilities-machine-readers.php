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
// inc/machine-readers-taxonomy.php is the real snt_mr_taxonomy_absent()
// (never-measured vs measured-zero) — stubbing it would hide the null
// purposes contract the widget now has to honour.
require __DIR__ . '/../inc/machine-readers-taxonomy.php';
require __DIR__ . '/../inc/machine-readers-render.php';
// v10.2.0: the ONE shared builder lives here now (both this ability and the
// desktop tile route call it), so the fixture drives the real thing.
require __DIR__ . '/../inc/machine-readers-summary.php';

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
	// v13.33.0: purposes, ai_training_by_purpose, first_party and taxonomy were
	// in snt_mr_summary_payload()'s return since v10.79.0 but undeclared here,
	// so an agent reading the schema could not know the purpose axis existed.
	// ADDITIVE and in the payload's own order — nothing renamed, nothing moved.
	array( 'ok', 'days', 'total', 'families', 'ai_training', 'ai_rights', 'ai_surfaces', 'purposes', 'ai_training_by_purpose', 'first_party', 'taxonomy', 'sensor_version', 'crawler_list', 'error' ) === array_keys( $props ),
	'schema pins the DM tile payload fields in response order, with error appended'
);
ok( 'boolean' === ( $props['ok']['type'] ?? null ), 'ok is a boolean' );
ok( 'integer' === ( $props['days']['type'] ?? null ), 'days is an integer' );
ok( 'integer' === ( $props['total']['type'] ?? null ), 'total is an integer' );
ok( 'array' === ( $props['families']['type'] ?? null ), 'families is an array' );
ok( 'object' === ( $props['families']['items']['type'] ?? null ), 'families items are objects' );
ok( array( 'family', 'hits' ) === array_keys( $props['families']['items']['properties'] ?? array() ), 'a family row is { family, hits }' );
ok( 'array' === ( $props['ai_surfaces']['type'] ?? null ), 'ai_surfaces is an array' );
ok( 'object' === ( $props['ai_surfaces']['items']['type'] ?? null ), 'ai_surfaces items are objects' );
ok( array( 'surface', 'hits' ) === array_keys( $props['ai_surfaces']['items']['properties'] ?? array() ), 'an ai_surfaces row is { surface, hits }' );
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
// NEW: the per-surface split for AI-training families ONLY (search/seo/other-bot
// excluded, same set ai_training/ai_rights already use). openai llms=10, and the
// rights surface sums openai(5) + anthropic(7) = 12, descending by hits.
ok(
	array(
		array( 'surface' => 'rights', 'hits' => 12 ),
		array( 'surface' => 'llms',   'hits' => 10 ),
	) === ( $out['ai_surfaces'] ?? null ),
	'ai_surfaces splits AI-training hits per surface, descending (rights 12, llms 10)'
);
ok( '1.4.0' === ( $out['sensor_version'] ?? null ), 'sensor_version passes through' );
ok( 'in sync' === ( $out['crawler_list'] ?? null ), 'crawler_list verdict: ok + no drift => in sync' );
ok(
	array( 'ok', 'days', 'total', 'families', 'ai_training', 'ai_rights', 'ai_surfaces', 'purposes', 'ai_training_by_purpose', 'first_party', 'taxonomy', 'sensor_version', 'crawler_list' ) === array_keys( $out ),
	'success shape matches the DM route exactly'
);
ok( $rows_before === $GLOBALS['__mr']['rows'], 'the fetched rows are never mutated' );

// ADDITIVE CONTRACT: every key that existed before ai_surfaces landed must be
// byte-identical once ai_surfaces is removed again — a widget that ignores the
// new key sees exactly the old payload, nothing shifted or recomputed.
$out_minus_new = $out;
unset( $out_minus_new['ai_surfaces'] );
// v10.79.0: the purpose axis is additive on the SAME principle , a consumer
// that ignores the new keys must still see the pre-existing payload untouched.
foreach ( array( 'purposes', 'ai_training_by_purpose', 'first_party', 'taxonomy' ) as $sn_new_key ) {
	// This fixture predates the taxonomy, so each must be NULL , never 0.
	// A 0 here would tell an agent "measured, and none", which is a lie.
	// array_key_exists, NOT ??: null-coalescing conflates "absent" with "present
	// and null", which is precisely the distinction being asserted here.
	ok(
		array_key_exists( $sn_new_key, $out ) && null === $out[ $sn_new_key ],
		"pre-taxonomy sensor reports $sn_new_key as present-and-null, not zero and not missing"
	);
	unset( $out_minus_new[ $sn_new_key ] );
}
ok(
	array(
		'ok'             => true,
		'days'           => 7,
		'total'          => 46,
		'families'       => array(
			array( 'family' => 'search',    'hits' => 20 ),
			array( 'family' => 'openai',    'hits' => 15 ),
			array( 'family' => 'anthropic', 'hits' => 7 ),
		),
		'ai_training'    => 22,
		'ai_rights'      => 12,
		'sensor_version' => '1.4.0',
		'crawler_list'   => 'in sync',
	) === $out_minus_new,
	'minus the new key, the payload is byte-identical to the pre-ai_surfaces shape (before/after deep-compare)'
);

echo "\nGroup G2: ai_surfaces answers the motivating question — did AI crawlers hit robots.txt\n";
// The premise this whole feature exists for: a widget '348' figure used to be
// the search FAMILY total, not the robots SURFACE. Prove a robots-surface hit
// from a declared AI-training family shows up as its own bucket, distinct from
// rights/llms, and that a non-AI family's robots hit is excluded.
$GLOBALS['__mr'] = array(
	'ok'    => true,
	'error' => null,
	'rows'  => array(
		array( 'family' => 'openai',      'surface' => 'robots',   'day' => '2026-07-28', 'hits' => 9 ),
		array( 'family' => 'openai',      'surface' => 'sitemap',  'day' => '2026-07-28', 'hits' => 2 ),
		array( 'family' => 'commoncrawl', 'surface' => 'robots',   'day' => '2026-07-28', 'hits' => 4 ),
		array( 'family' => 'search',      'surface' => 'robots',   'day' => '2026-07-28', 'hits' => 100 ),
	),
);
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok(
	array(
		array( 'surface' => 'robots',  'hits' => 13 ),
		array( 'surface' => 'sitemap', 'hits' => 2 ),
	) === ( $out['ai_surfaces'] ?? null ),
	'robots.txt is its own ai_surfaces bucket (openai 9 + commoncrawl 4 = 13), the search family\'s 100 robots hits excluded'
);

echo "\nGroup G3: no AI-training rows in the window => ai_surfaces is an empty array, never omitted\n";
$GLOBALS['__mr'] = array(
	'ok'    => true,
	'error' => null,
	'rows'  => array(
		array( 'family' => 'search', 'surface' => 'robots', 'day' => '2026-07-28', 'hits' => 5 ),
	),
);
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( array_key_exists( 'ai_surfaces', $out ), 'ai_surfaces key is present even with zero AI-training rows' );
ok( array() === ( $out['ai_surfaces'] ?? null ), 'ai_surfaces is an empty array, not a fabricated bucket' );

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

echo "\nGroup: v10.2.0 — ONE builder, verified against the real thing\n";
// The first draft delegated to the DM route at days=30 and kept a LOCAL COPY
// for every other window — a fork its own review caught. There is now one
// builder (inc/machine-readers-summary.php) that the ability AND the desktop
// route both call, so these pins drive the REAL builder rather than comparing
// key-list literals typed into this file.
$mr_direct = snt_mr_summary_payload( 30 );
$mr_via    = snt_ability_get_machine_readers_summary( array( 'days' => 30 ) );
ok( $mr_direct === $mr_via, 'the ability returns exactly what the shared builder returns' );
$mr_7 = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( 7 === ( $mr_7['days'] ?? null ), 'a non-default window is honored (the old fork existed because the DM route hardcoded 30)' );
ok( array_keys( $mr_direct ) === array_keys( $mr_7 ), 'every window returns the same shape' );
$mr_src = (string) file_get_contents( __DIR__ . '/../inc/abilities-machine-readers.php' );
ok( false === strpos( $mr_src, 'snt_ability_mr_summary_for' ), 'no second copy of the builder remains in the ability file' );
// v10.87.2: the desktop route moved to the payloads module when
// desktop-mode-integration.php was split into a loader plus seven modules. The
// assertion is unchanged — one builder, called by both callers, no second copy
// — only the file that now holds the route.
$mr_dm = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-payloads.php' );
ok( false !== strpos( $mr_dm, 'snt_mr_summary_payload( 30 )' ), 'the desktop route calls the same builder' );

echo "\nGroup: purposes rows pass through when the taxonomy is present\n";
// Real normalized-row shape (snt_mr_normalize_rows + snt_mr_normalize_taxonomy_fields):
// family/surface/day/hits + vendor/agent/purpose/taxonomy_version/
// training_corpus_source/first_party/ua_sample. first_party is a bool.
// A first-party row is excluded from purposes (self-traffic is not readership).
$GLOBALS['__mr'] = array(
	'ok'    => true,
	'error' => null,
	'rows'  => array(
		array(
			'family' => 'openai', 'surface' => 'html', 'day' => '2026-08-16', 'hits' => 40,
			'vendor' => 'openai', 'agent' => 'openai-gptbot', 'purpose' => 'train',
			'taxonomy_version' => '1.0.0', 'training_corpus_source' => true,
			'first_party' => false, 'ua_sample' => 'GPTBot/1.0',
		),
		array(
			'family' => 'search', 'surface' => 'html', 'day' => '2026-08-16', 'hits' => 25,
			'vendor' => 'google', 'agent' => 'googlebot', 'purpose' => 'search',
			'taxonomy_version' => '1.0.0', 'training_corpus_source' => false,
			'first_party' => false, 'ua_sample' => 'Googlebot/2.1',
		),
		array(
			'family' => 'anthropic', 'surface' => 'html', 'day' => '2026-08-16', 'hits' => 12,
			'vendor' => 'anthropic', 'agent' => 'claudebot', 'purpose' => 'retrieval',
			'taxonomy_version' => '1.0.0', 'training_corpus_source' => false,
			'first_party' => false, 'ua_sample' => 'ClaudeBot/1.0',
		),
		array(
			'family' => 'uptime', 'surface' => 'html', 'day' => '2026-08-16', 'hits' => 99,
			'vendor' => 'self', 'agent' => 'betterstack', 'purpose' => 'ops',
			'taxonomy_version' => '1.0.0', 'training_corpus_source' => false,
			'first_party' => true, 'ua_sample' => 'Better Stack',
		),
	),
);
$out = snt_ability_get_machine_readers_summary( array( 'days' => 7 ) );
ok( is_array( $out['purposes'] ?? null ), 'taxonomy-bearing rows report purposes as an array, not null' );
ok(
	array(
		array( 'purpose' => 'train',     'hits' => 40 ),
		array( 'purpose' => 'search',    'hits' => 25 ),
		array( 'purpose' => 'retrieval', 'hits' => 12 ),
	) === ( $out['purposes'] ?? null ),
	'purposes rows are { purpose, hits }, sorted desc, first-party excluded'
);
ok( ! in_array( 'ops', array_column( (array) ( $out['purposes'] ?? array() ), 'purpose' ), true ),
	'the first-party ops row never appears in purposes' );

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
