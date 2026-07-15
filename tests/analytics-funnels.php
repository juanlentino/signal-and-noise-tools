<?php
/**
 * Session funnels — parser, setting seam, atomic save handler (S2 §3, Task 2).
 *
 * Three groups:
 *   1. Parser: sn_analytics_parse_funnels() — textarea format ("Name: /a > /b")
 *      into the exact funnel shape sn_analytics_session_funnels() returns.
 *   2. Setting seam: sn_analytics_session_funnels() reads analytics.funnels,
 *      falls back to the hardcoded two (byte-identical to pre-S2), and the
 *      'sn_analytics_session_funnels' filter still runs LAST over either source.
 *   3. Save handler: sn_handle_analytics_funnels_save() — no inline nonce check
 *      (the dispatcher already runs check_admin_referer() for every action),
 *      wp_unslash() BEFORE parse, atomic on error, apostrophe regression pin.
 *
 * Run: php tests/analytics-funnels.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

if ( ! function_exists( '__' ) ) { function __( $text, $domain = 'default' ) { return $text; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $k ) ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb = null, $p = 10, $a = 1 ) {} }
// Real stripslashes_deep behavior (NOT an identity stub) — the apostrophe pin
// below is the whole point of this fixture, mirroring tests/settings-save-unslash.php.
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
// apply_filters is a passthrough EXCEPT for 'sn_analytics_session_funnels' when a
// test installs an override callback via $GLOBALS['__filter_cb'] — lets the Group 2
// "filter still wins" pins actually exercise a filter instead of a no-op.
$GLOBALS['__filter_cb'] = null;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( 'sn_analytics_session_funnels' === $tag && is_callable( $GLOBALS['__filter_cb'] ?? null ) ) {
			return call_user_func( $GLOBALS['__filter_cb'], $value );
		}
		return $value;
	}
}

// sn_setting store backed by a plain map (mirrors tests/analytics-tuning-save.php —
// the real sparse-write/deep-merge semantics are covered by
// tests/settings-save-preserves-subtrees.php; here we test the parser + handler).
$GLOBALS['__settings'] = array();
function sn_setting( $path, $default = null ) {
	return array_key_exists( $path, $GLOBALS['__settings'] ) ? $GLOBALS['__settings'][ $path ] : $default;
}
function sn_setting_update( $path, $value ) { $GLOBALS['__settings'][ $path ] = $value; return true; }

require __DIR__ . '/../inc/analytics-sessions.php';
require __DIR__ . '/../inc/admin-post-actions.php';
require __DIR__ . '/../inc/admin-post-handler.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ─────────────────────────────────────────────────────────────────────────
echo "Group: sn_analytics_parse_funnels — happy path + shape\n";
// ─────────────────────────────────────────────────────────────────────────
$res = sn_analytics_parse_funnels( "Home flow: /entry > /step > /goal\nContact: /a > /b" );
ok( array() === $res['errors'], 'two valid lines parse with no errors' );
ok( 2 === count( $res['funnels'] ), 'two funnels parsed' );
ok( 'Home flow' === $res['funnels'][0]['title'], 'title parsed + trimmed' );
ok( array( 'title', 'steps' ) === array_keys( $res['funnels'][0] ),
	'funnel shape has title/steps keys — matches sn_analytics_session_funnels() funnel shape' );
ok( array( 'match', 'value', 'prefix' ) === array_keys( $res['funnels'][0]['steps'][0] ),
	'step shape has match/value/prefix keys — matches sn_analytics_session_funnels() step shape' );
ok( 'path' === $res['funnels'][0]['steps'][0]['match'], 'step match type is "path"' );
ok( false === $res['funnels'][0]['steps'][0]['prefix'], 'step prefix is exact (false) — textarea format has no wildcard syntax' );
ok( '/entry' === $res['funnels'][0]['steps'][0]['value'], 'first step value' );
ok( '/goal' === $res['funnels'][0]['steps'][2]['value'], 'third step value' );
ok( 'Contact' === $res['funnels'][1]['title'], 'second line parses independently' );

echo "\nGroup: sn_analytics_parse_funnels — whitespace + path normalization\n";
$res = sn_analytics_parse_funnels( "  Spacey  :  about   >  /contact  " );
ok( 'Spacey' === $res['funnels'][0]['title'], 'name is trimmed' );
ok( '/about' === $res['funnels'][0]['steps'][0]['value'], 'bare path gets a leading slash' );
ok( '/contact' === $res['funnels'][0]['steps'][1]['value'], 'already-slashed path stays as-is (just trimmed)' );

echo "\nGroup: sn_analytics_parse_funnels — rejections\n";
$res = sn_analytics_parse_funnels( 'no colon here' );
ok( array() === $res['funnels'], 'line without a colon produces no funnel' );
ok( 1 === count( $res['errors'] ), 'one error recorded' );
ok( false !== strpos( $res['errors'][0], 'Line 1' ), 'error names the 1-based line number' );

$res = sn_analytics_parse_funnels( ' : /a > /b' );
ok( array() === $res['funnels'], 'empty name produces no funnel' );
ok( 1 === count( $res['errors'] ), 'empty-name error recorded' );

$res = sn_analytics_parse_funnels( 'One step: /a' );
ok( array() === $res['funnels'], 'fewer than 2 steps produces no funnel' );
ok( 1 === count( $res['errors'] ), 'fewer-than-2-steps error recorded' );

echo "\nGroup: sn_analytics_parse_funnels — T2-review hardening: a double colon does not yield a garbage step\n";
// The bug: 'Name:: /a > /b' splits on the FIRST colon into name="Name",
// steps_raw=": /a > /b" — the leftover ':' used to ride along into the first
// step's value as "/: /a" instead of erroring. Now any step that (after
// leading-slash normalization) contains whitespace or a ':' rejects the WHOLE
// line, since a well-formed path step has neither.
$res = sn_analytics_parse_funnels( 'Name:: /a > /b' );
ok( array() === $res['funnels'], 'a double colon does not produce a garbage step — the line is rejected' );
ok( 1 === count( $res['errors'] ), 'double-colon line records exactly one error' );
ok( false !== strpos( $res['errors'][0], 'Line 1' ), 'double-colon error names line 1' );

$res = sn_analytics_parse_funnels( "Good: /a > /b\nName:: /a > /b\nAlso good: /c > /d" );
ok( 2 === count( $res['funnels'] ), 'a bad double-colon line does not block its neighbors from parsing' );
ok( 1 === count( $res['errors'] ), 'exactly one error recorded for the bad line' );
ok( false !== strpos( $res['errors'][0], 'Line 2' ), 'error names the actual bad line (2), not line 1' );
ok( 'Also good' === $res['funnels'][1]['title'], 'the line AFTER the bad one still parses correctly' );

echo "\nGroup: sn_analytics_parse_funnels — T2-review hardening: a step with internal whitespace is rejected\n";
$res = sn_analytics_parse_funnels( 'Name: /a b > /c' );
ok( array() === $res['funnels'], 'a step containing internal whitespace is rejected (paths have no whitespace)' );
ok( 1 === count( $res['errors'] ), 'whitespace-in-step line records exactly one error' );

echo "\nGroup: sn_analytics_parse_funnels — line numbers across mixed valid/invalid lines\n";
$res = sn_analytics_parse_funnels( "Good: /a > /b\nno colon\nAlso good: /c > /d" );
ok( 2 === count( $res['funnels'] ), 'the two valid lines still parse despite line 2 failing' );
ok( 1 === count( $res['errors'] ), 'exactly one error recorded' );
ok( false !== strpos( $res['errors'][0], 'Line 2' ), 'error names line 2 (the actual bad line), not line 1' );
ok( 'Also good' === $res['funnels'][1]['title'], 'the line AFTER the bad one still parses correctly' );

echo "\nGroup: sn_analytics_parse_funnels — blank lines are skipped, not errors\n";
$res = sn_analytics_parse_funnels( "First: /a > /b\n\n\nSecond: /c > /d" );
ok( 2 === count( $res['funnels'] ), 'blank lines between funnels are ignored' );
ok( array() === $res['errors'], 'blank lines do not produce errors' );

echo "\nGroup: sn_analytics_parse_funnels — clamps\n";
$lines11 = array();
for ( $i = 1; $i <= 11; $i++ ) {
	$lines11[] = "F$i: /a > /b";
}
$res = sn_analytics_parse_funnels( implode( "\n", $lines11 ) );
ok( 10 === count( $res['funnels'] ), 'max 10 funnels — the 11th line is rejected' );
ok( 1 === count( $res['errors'] ), '11th line records exactly one error' );
ok( false !== strpos( $res['errors'][0], 'Line 11' ), 'clamp error names line 11' );

$steps9 = 'F: ' . implode( ' > ', array_fill( 0, 9, '/s' ) );
$res    = sn_analytics_parse_funnels( $steps9 );
ok( array() === $res['funnels'], 'max 8 steps — a 9-step line is rejected entirely' );
ok( 1 === count( $res['errors'] ), '9-step line records one error' );

$steps8 = 'F: ' . implode( ' > ', array_fill( 0, 8, '/s' ) );
$res    = sn_analytics_parse_funnels( $steps8 );
ok( 1 === count( $res['funnels'] ), 'exactly 8 steps is allowed (boundary)' );
ok( array() === $res['errors'], 'no error at the 8-step boundary' );

echo "\nGroup: sn_analytics_parse_funnels — empty/whitespace-only input\n";
// PIN CHANGE (reason-surfacing task): sn_analytics_parse_funnels() now also
// returns 'errors_detail' — structured {line,kind,message} entries parallel
// to the existing 'errors' string list — so the exact-shape pins below widen
// to include the new (always-empty-here) key. Every other assertion in this
// file reads $res['errors']/$res['funnels'] individually and is unaffected.
ok( array(
	'funnels'       => array(),
	'errors'        => array(),
	'errors_detail' => array(),
) === sn_analytics_parse_funnels( '' ), 'empty string → empty shape' );
ok( array(
	'funnels'       => array(),
	'errors'        => array(),
	'errors_detail' => array(),
) === sn_analytics_parse_funnels( "   \n\n  \t " ), 'whitespace-only → empty shape' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_parse_funnels — errors_detail carries the correct KIND + line for each of the six SN_ANALYTICS_FUNNELS_ERR_KINDS\n";
// ─────────────────────────────────────────────────────────────────────────
// Every kind must be independently reachable AND correctly tagged, with the
// structured entry's 'message' staying byte-identical to the flat 'errors'
// string it parallels (single source of truth — see sn_analytics_funnels_error()).
function fk_assert_kind( $raw, $expect_line, $expect_kind, $label ) {
	global $pass, $fail;
	$res = sn_analytics_parse_funnels( $raw );
	ok( 1 === count( $res['errors_detail'] ), "$label: exactly one errors_detail entry" );
	if ( 1 !== count( $res['errors_detail'] ) ) {
		return;
	}
	$d = $res['errors_detail'][0];
	ok( array( 'line', 'kind', 'message' ) === array_keys( $d ), "$label: errors_detail entry shape is {line,kind,message}" );
	ok( $expect_line === $d['line'], "$label: line is $expect_line" );
	ok( $expect_kind === $d['kind'], "$label: kind is '$expect_kind'" );
	ok( $d['message'] === $res['errors'][0], "$label: errors_detail message matches the flat errors[] string (single source)" );
	ok( in_array( $d['kind'], SN_ANALYTICS_FUNNELS_ERR_KINDS, true ), "$label: kind is a member of the closed six-kind enum" );
}

fk_assert_kind( 'no colon here', 1, 'colon', 'colon kind (missing ":")' );
fk_assert_kind( ' : /a > /b', 1, 'name', 'name kind (empty funnel name)' );
fk_assert_kind( str_repeat( 'n', 81 ) . ': /a > /b', 1, 'long', 'long kind (name over 80 chars)' );
fk_assert_kind( 'F: /' . str_repeat( 'p', 200 ) . ' > /b', 1, 'long', 'long kind (steps segment over 200 chars)' );
fk_assert_kind( 'Name:: /a > /b', 1, 'step', 'step kind (stray double colon)' );
fk_assert_kind( 'Name: /a b > /c', 1, 'step', 'step kind (whitespace inside a step)' );
fk_assert_kind( 'One step: /a', 1, 'few', 'few kind (fewer than 2 steps)' );
fk_assert_kind( 'F: ' . implode( ' > ', array_fill( 0, 9, '/s' ) ), 1, 'many', 'many kind (over max steps)' );
$lines11_kind = array();
for ( $i = 1; $i <= 11; $i++ ) {
	$lines11_kind[] = "F$i: /a > /b";
}
$res_many_funnels = sn_analytics_parse_funnels( implode( "\n", $lines11_kind ) );
ok( 1 === count( $res_many_funnels['errors_detail'] ), 'many kind (over max funnels): exactly one errors_detail entry' );
ok( 11 === $res_many_funnels['errors_detail'][0]['line'], 'many kind (over max funnels): line is 11' );
ok( 'many' === $res_many_funnels['errors_detail'][0]['kind'], 'many kind (over max funnels): kind is "many"' );

ok( 6 === count( SN_ANALYTICS_FUNNELS_ERR_KINDS ), 'the enum has exactly six kinds' );
ok( array( 'colon', 'name', 'long', 'step', 'few', 'many' ) === SN_ANALYTICS_FUNNELS_ERR_KINDS, 'the six kind keys, in their stable encoding order' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_session_funnels — hardcoded defaults byte-identical when unset\n";
// ─────────────────────────────────────────────────────────────────────────
$GLOBALS['__settings'] = array();
// Captured verbatim from the pre-S2 hardcoded output (inc/analytics-sessions.php),
// with __() as an identity stub above — this is the byte-identical pin.
$hardcoded_today = array(
	array(
		'title' => 'Home → post → subscribe',
		'steps' => array(
			array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
			array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
			array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
		),
	),
	array(
		'title' => 'Services → contact → email',
		'steps' => array(
			array( 'match' => 'path', 'value' => '/services', 'prefix' => true ),
			array( 'match' => 'path', 'value' => '/contact', 'prefix' => true ),
			array( 'match' => 'ce', 'value' => 'contact-', 'prefix' => true ),
		),
	),
);
ok( $hardcoded_today === sn_analytics_session_funnels(), 'no setting configured → the hardcoded two, byte-identical to the pre-S2 output' );

echo "\nGroup: sn_analytics_session_funnels — non-empty setting IS the default (replaces the hardcoded two)\n";
$GLOBALS['__settings'] = array(
	'analytics.funnels' => array(
		array(
			'title' => 'Custom',
			'steps' => array(
				array( 'match' => 'path', 'value' => '/a', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/b', 'prefix' => false ),
			),
		),
	),
);
$out = sn_analytics_session_funnels();
ok( 1 === count( $out ), 'configured setting IS the default — replaces the hardcoded two, not appended to them' );
ok( 'Custom' === $out[0]['title'], 'the configured funnel is returned' );

echo "\nGroup: sn_analytics_session_funnels — defensive fallback on a corrupt setting\n";
$GLOBALS['__settings'] = array( 'analytics.funnels' => array( 'not-an-array', array( 'title' => 'ok' ) ) );
$out                   = sn_analytics_session_funnels();
ok( $hardcoded_today === $out, 'a corrupt setting (a non-array entry) falls back to the hardcoded defaults wholesale' );

echo "\nGroup: sn_analytics_funnels_resolve_setting — T2-review hardening: an entry missing 'steps' rejects the WHOLE setting\n";
// Every configured entry (not just non-array ones) must carry title + steps
// (steps itself an array) or the setting falls back wholesale — matches the
// existing corrupt-fallback semantics, just widened to the funnel SHAPE, not
// only its PHP type.
$out = sn_analytics_funnels_resolve_setting( array( array( 'title' => 'No steps here' ) ), $hardcoded_today );
ok( $hardcoded_today === $out, 'an entry missing "steps" -> the hardcoded defaults, not a partial/best-effort mix' );
$out = sn_analytics_funnels_resolve_setting( array( array( 'steps' => array() ) ), $hardcoded_today );
ok( $hardcoded_today === $out, 'an entry missing "title" -> the hardcoded defaults' );
$out = sn_analytics_funnels_resolve_setting( array( array( 'title' => 'Bad steps', 'steps' => 'not-an-array' ) ), $hardcoded_today );
ok( $hardcoded_today === $out, 'an entry whose "steps" is not itself an array -> the hardcoded defaults' );
// T3 review: steps' ELEMENTS must be arrays too — ['title'=>'X','steps'=>
// ['junk1','junk2']] used to pass resolve and TypeError-fatal
// sn_funnel_step_matches(array $step, ...) the moment live data reached the
// matcher (sn_funnel_report only calls it when a summary has events).
$out = sn_analytics_funnels_resolve_setting( array( array( 'title' => 'X', 'steps' => array( 'junk1', 'junk2' ) ) ), $hardcoded_today );
ok( $hardcoded_today === $out, 'an entry whose steps ELEMENTS are not arrays -> the hardcoded defaults (would TypeError the matcher under live data)' );

// End-to-end through the public seam + the Visits view's own funnel-building
// loop (mirrors inc/analytics-view-sessions.php:116-121) with a NON-EMPTY
// summaries fixture, so sn_funnel_step_matches actually runs — an empty
// $summaries never reaches the matcher, which is exactly where the corrupt
// string-steps would fatal. Discriminating: without the wholesale fallback
// this loop TypeErrors instead of reporting.
$GLOBALS['__settings'] = array( 'analytics.funnels' => array( array( 'title' => 'X', 'steps' => array( 'junk1', 'junk2' ) ) ) );
$out                   = sn_analytics_session_funnels();
ok( $hardcoded_today === $out, 'sn_analytics_session_funnels(): corrupt string-steps entry falls back to the hardcoded defaults' );
$live_summaries = array(
	array(
		'events' => array(
			array( 'ev' => 'pv', 'path' => '/', 'ce' => '' ),
			array( 'ev' => 'pv', 'path' => '/notes/hello', 'ce' => '' ),
			array( 'ev' => 'ce', 'path' => '/notes/hello', 'ce' => 'subscribe' ),
		),
	),
);
$rendered = array();
foreach ( $out as $def ) {
	$rendered[] = array( 'title' => $def['title'], 'report' => sn_funnel_report( $live_summaries, $def['steps'] ) );
}
ok( 2 === count( $rendered ), 'the Visits-view funnel-building loop completes without a fatal over live-shaped summaries' );
ok( 1 === $rendered[0]['report'][2]['reached'], 'the matcher genuinely ran: the fixture visit completes all 3 steps of the first hardcoded funnel' );

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_funnels_to_text — T3-review: unrepresentable funnels are OMITTED, never emitted as corrupt lines\n";
// ─────────────────────────────────────────────────────────────────────────
// The invariant: to_text only emits a line when parse() would read that line
// back to the SAME funnel. A step value carrying '>' would re-parse as extra
// steps (silent data corruption); a value carrying ':' or whitespace, or a
// title carrying ':' or a newline, would emit a line the parser REJECTS —
// wedging the card (every future save errors on a line the owner never typed).
function t3_step( $v ) { return array( 'match' => 'path', 'value' => $v, 'prefix' => false ); }

$f = array( array( 'title' => 'Bad', 'steps' => array( t3_step( '/a>b' ), t3_step( '/c' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a step value containing ">" -> funnel omitted (would re-parse as 3 steps, silently corrupting)' );

$f = array( array( 'title' => 'Bad', 'steps' => array( t3_step( '/a:b' ), t3_step( '/c' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a step value containing ":" -> funnel omitted (its line would be rejected on save)' );

$f = array( array( 'title' => 'Bad', 'steps' => array( t3_step( '/a b' ), t3_step( '/c' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a step value containing whitespace -> funnel omitted' );

$f = array( array( 'title' => 'Bad', 'steps' => array( t3_step( 'no-slash' ), t3_step( '/c' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a step value without a leading slash -> funnel omitted (would re-parse normalized, not itself)' );

$f = array( array( 'title' => 'A: B', 'steps' => array( t3_step( '/a' ), t3_step( '/b' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a title containing ":" -> funnel omitted (its line would re-split name/steps at the wrong colon)' );

$f = array( array( 'title' => "Two\nlines", 'steps' => array( t3_step( '/a' ), t3_step( '/b' ) ) ) );
ok( '' === sn_analytics_funnels_to_text( $f ), 'a title containing a newline -> funnel omitted (would split into two bogus lines)' );

// A representable sibling still serializes when an unrepresentable one is dropped.
$f = array(
	array( 'title' => 'Bad', 'steps' => array( t3_step( '/a>b' ), t3_step( '/c' ) ) ),
	array( 'title' => 'Fine', 'steps' => array( t3_step( '/a' ), t3_step( '/b' ) ) ),
);
ok( 'Fine: /a > /b' === sn_analytics_funnels_to_text( $f ), 'a representable sibling still emits when the unrepresentable one is dropped' );

// The round trip still holds for representable (parser-produced) funnels.
$rt1 = sn_analytics_parse_funnels( "Home flow: /entry > /step > /goal\nContact: /a > /b" );
$rt2 = sn_analytics_parse_funnels( sn_analytics_funnels_to_text( $rt1['funnels'] ) );
ok( $rt1['funnels'] === $rt2['funnels'], 'round trip still holds for representable funnels' );

echo "\nGroup: sn_analytics_session_funnels — the filter still runs LAST, over BOTH sources\n";
function sn_test_append_funnel_filter( $funnels ) {
	$funnels[] = array(
		'title' => 'Filter-added',
		'steps' => array(),
	);
	return $funnels;
}
$GLOBALS['__settings']   = array();
$GLOBALS['__filter_cb']  = 'sn_test_append_funnel_filter';
$out                     = sn_analytics_session_funnels();
ok( 3 === count( $out ), 'filter appends on top of the hardcoded defaults (2 + 1)' );
ok( 'Filter-added' === end( $out )['title'], 'the filter-added funnel wins the last slot over the hardcoded source' );

$GLOBALS['__settings'] = array(
	'analytics.funnels' => array(
		array(
			'title' => 'Custom',
			'steps' => array(),
		),
	),
);
$out = sn_analytics_session_funnels();
ok( 2 === count( $out ), 'filter appends on top of the CONFIGURED setting too (1 + 1)' );
ok( 'Filter-added' === end( $out )['title'], 'the filter-added funnel wins the last slot over the setting source too' );
$GLOBALS['__filter_cb'] = null;

// ─────────────────────────────────────────────────────────────────────────
echo "\nGroup: dispatch map\n";
// ─────────────────────────────────────────────────────────────────────────
$map = sn_admin_post_handlers();
ok( isset( $map['analytics_funnels_save'] ) && 'sn_handle_analytics_funnels_save' === $map['analytics_funnels_save'],
	'analytics_funnels_save routed to its handler' );

echo "\nGroup: final review — length clamps + array POST + flash-source cap\n";
$long_name = str_repeat( 'n', 81 ) . ': /a > /b';
$res       = sn_analytics_parse_funnels( $long_name );
ok( array() === $res['funnels'] && 1 === count( $res['errors'] ) && false !== strpos( $res['errors'][0], 'too long' ), 'clamp: an 81-char name is rejected with the length error' );
$long_steps = 'F: /' . str_repeat( 'p', 200 ) . ' > /b';
$res        = sn_analytics_parse_funnels( $long_steps );
ok( array() === $res['funnels'] && false !== strpos( $res['errors'][0], 'too long' ), 'clamp: a >200-char steps segment is rejected' );
$res = sn_analytics_parse_funnels( 'F: ' . str_repeat( '/p > ', 3 ) . '/q' );
ok( 1 === count( $res['funnels'] ), 'clamp sanity: an ordinary line is untouched by the length rule' );
$flash = sn_handle_analytics_funnels_save( array( 'sn_funnels' => array( 'crafted' ) ) );
ok( 'analytics_funnels_saved' === (string) $flash, 'array-shaped sn_funnels[] behaves like the missing-field case (empty save, is_string guard, NO PHP warning)' );
$many_bad = array();
for ( $i = 1; $i <= 20; $i++ ) { $many_bad[] = 'bad-line-without-colon'; }
$flash = sn_handle_analytics_funnels_save( array( 'sn_funnels' => implode( "\n", $many_bad ) ) );
// PIN CHANGE (reason-surfacing task): the flash code now encodes (line, kind
// INDEX) pairs — 'colon' is index 0 in SN_ANALYTICS_FUNNELS_ERR_KINDS — instead
// of the old bare-line-number list ('analytics_funnels_invalid_1-2-3-4-5').
// The SOURCE cap (first five bad lines) is unchanged.
ok( 1 === preg_match( '/^analytics_funnels_invalid_1k0-2k0-3k0-4k0-5k0$/', (string) $flash ), 'flash-source cap: only the first FIVE bad lines ride the redirect code, each as a line+kind pair' );

echo "\nGroup: sn_handle_analytics_funnels_save — happy path\n";
$GLOBALS['__settings'] = array();
$flash                 = sn_handle_analytics_funnels_save( array( 'sn_funnels' => 'Home: /a > /b' ) );
ok( 'analytics_funnels_saved' === $flash, 'valid save returns the saved flash' );
ok( 1 === count( $GLOBALS['__settings']['analytics.funnels'] ), 'parsed funnel stored' );
ok( 'Home' === $GLOBALS['__settings']['analytics.funnels'][0]['title'], 'stored funnel carries the parsed title' );

echo "\nGroup: sn_handle_analytics_funnels_save — atomic: parse error saves NOTHING\n";
$prior_funnels          = array(
	array(
		'title' => 'Prior',
		'steps' => array(),
	),
);
$GLOBALS['__settings'] = array( 'analytics.funnels' => $prior_funnels );
$flash                 = sn_handle_analytics_funnels_save( array( 'sn_funnels' => 'no colon here' ) );
ok( 0 === strpos( $flash, 'analytics_funnels_invalid' ), 'parse error returns an "invalid" flash code' );
// PIN CHANGE (reason-surfacing task): the line number now rides inside a
// '<line>k<kindIndex>' pair, not a bare suffix — pin the exact new code.
ok( 'analytics_funnels_invalid_1k0' === $flash, 'flash code carries the 1-based line number + kind index (0 = colon) of the bad line' );
ok( $prior_funnels === $GLOBALS['__settings']['analytics.funnels'], 'the prior setting is UNCHANGED — atomic, nothing partially saved' );

echo "\nGroup: sn_handle_analytics_funnels_save — flash code carries MULTIPLE bad line numbers\n";
$GLOBALS['__settings'] = array( 'analytics.funnels' => $prior_funnels );
$flash                 = sn_handle_analytics_funnels_save( array( 'sn_funnels' => "no colon here\nGood: /a > /b\nalso no colon" ) );
// PIN CHANGE (reason-surfacing task): exact pair-encoded code, not a substring check.
ok( 'analytics_funnels_invalid_1k0-3k0' === $flash, 'flash code names both bad lines (1 and 3), each paired with the colon kind index' );
ok( $prior_funnels === $GLOBALS['__settings']['analytics.funnels'], 'still atomic when only SOME lines are invalid — nothing saved' );

echo "\nGroup: sn_handle_analytics_funnels_save — empty textarea clears the setting (falls back to hardcoded on read)\n";
$GLOBALS['__settings'] = array( 'analytics.funnels' => $prior_funnels );
$flash                 = sn_handle_analytics_funnels_save( array( 'sn_funnels' => '' ) );
ok( 'analytics_funnels_saved' === $flash, 'empty textarea (no errors) saves' );
ok( array() === $GLOBALS['__settings']['analytics.funnels'], 'empty textarea clears the stored funnels' );

echo "\nGroup: sn_handle_analytics_funnels_save — missing sn_funnels field is treated as empty, not fatal\n";
$GLOBALS['__settings'] = array();
$flash                 = sn_handle_analytics_funnels_save( array() );
ok( 'analytics_funnels_saved' === $flash, 'absent field parses as empty input and saves (no fatal, no error)' );

echo "\nGroup: sn_handle_analytics_funnels_save — wp_unslash runs BEFORE parse\n";
// A slashed apostrophe in the raw POST must not leak into the stored title as a
// literal backslash — wp_unslash has to run before the colon/name split.
$GLOBALS['__settings'] = array();
$flash                 = sn_handle_analytics_funnels_save( array( 'sn_funnels' => "O\\'Neil: /a > /b" ) );
ok( 'analytics_funnels_saved' === $flash, 'apostrophe line saves cleanly' );
ok( "O'Neil" === $GLOBALS['__settings']['analytics.funnels'][0]['title'], 'stored title is unslashed — no literal backslash' );

echo "\nGroup: THE APOSTROPHE PIN — two saves through a slash-adding \$_POST simulation, no backslash growth\n";
// Mirror tests/settings-save-unslash.php: simulate wp_magic_quotes() (addslashes)
// on the way into $_POST, exactly as real WP does before any handler runs.
function funnels_test_slash_deep( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'funnels_test_slash_deep', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}
$GLOBALS['__settings'] = array();
$raw                   = "O'Neil: /a > /b";

// Save 1: browser posts clean text, WP slashes it, the handler receives the
// slashed payload — exactly how $_POST arrives in production.
sn_handle_analytics_funnels_save( funnels_test_slash_deep( array( 'sn_funnels' => $raw ) ) );
ok( "O'Neil" === $GLOBALS['__settings']['analytics.funnels'][0]['title'], "save 1: apostrophe stored clean (O'Neil)" );

// Save 2: the settings-card textarea re-echoes the STORED value (not the
// original $raw) — exactly what a real page reload does — and WP slashes it
// again on the way in. A missing/duplicated unslash would double the
// backslash here (n -> 2n+1); the stored name must stay byte-identical.
$echoed_line = $GLOBALS['__settings']['analytics.funnels'][0]['title'] . ': /a > /b';
sn_handle_analytics_funnels_save( funnels_test_slash_deep( array( 'sn_funnels' => $echoed_line ) ) );
ok( "O'Neil" === $GLOBALS['__settings']['analytics.funnels'][0]['title'], "save 2: still O'Neil — no backslash growth across re-saves" );
ok( false === strpos( $GLOBALS['__settings']['analytics.funnels'][0]['title'], '\\' ), 'stored title contains no backslashes at all' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
