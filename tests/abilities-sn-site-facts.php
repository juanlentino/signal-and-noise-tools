<?php
/**
 * Standalone tests for the sn_site_facts consolidated ability (v10.26.0):
 * signal-noise/sn-site-facts. Absorbs 10 of 11 site-facts abilities;
 * get-design-system-summary is RETIRED, not absorbed (left untouched).
 *
 * Stub-fidelity notes: each source ability is a stand-in exposing
 * check_permissions()/execute(), mirroring tests/mcp-tools.php's
 * SN_Test_Ability exactly — this file exercises the REAL dispatch sequence
 * (check_permissions -> execute, degrade to {error:'unavailable'} on either
 * failing) against those stand-ins, not a re-implemented assumption of it.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data( $code = '' ) { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}

// A lightweight source-ability stand-in — same shape as tests/mcp-tools.php's
// SN_Test_Ability (check_permissions + execute), enough for THIS file's
// dispatch-sequence exercise.
class SN_Test_Fact_Ability {
	private $perm; private $result; private $calls_with = array();
	public function __construct( $perm, $result ) { $this->perm = $perm; $this->result = $result; }
	public function check_permissions( $args = null ) { return $this->perm; }
	public function execute( $args = null ) { $this->calls_with[] = $args; return $this->result; }
	public function last_call_args() { return end( $this->calls_with ); }
}

// A FAITHFUL stand-in for the theme's REAL
// signal-and-noise/get-active-template-structure ability (source:
// ~/Projects/signal-and-noise/inc/abilities-diagnostics.php:535-554, per the
// review round 2 finding). The real execute callback resolves $post from
// EITHER post_id OR slug; when NEITHER is present it returns
// WP_Error('post_not_found', ..., array('status'=>404)) — deterministically,
// with no no-args default path. This stub models THAT failure shape, not
// just the success shape (the repo's standing stub-drift trap), so a
// regression back to empty-args dispatch is caught RED, not silently green.
class SN_Test_Theme_Active_Template_Ability {
	private $success; private $calls_with = array();
	public function __construct( $success ) { $this->success = $success; }
	public function check_permissions( $args = null ) { return true; }
	public function execute( $args = null ) {
		$this->calls_with[] = $args;
		$has_target = is_array( $args ) && ( ! empty( $args['post_id'] ) || ! empty( $args['slug'] ) );
		if ( ! $has_target ) {
			return new WP_Error( 'post_not_found', 'No post matches the given post_id or slug.', array( 'status' => 404 ) );
		}
		return $this->success;
	}
	public function last_call_args() { return end( $this->calls_with ); }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/abilities-sn-site-facts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_site_facts (consolidated) — plugin v10.26.0\n\n";

// ─── Fact map: exactly 10 facts, none pointing at the retired ability ───
$map = snt_sn_site_facts_map();
ok( 10 === count( $map ), 'the fact map has exactly 10 entries (get-design-system-summary retired, not absorbed)' );
ok( ! in_array( 'signal-and-noise/get-design-system-summary', $map, true ), 'the retired ability is not a source for any fact' );
$expected_map = array(
	'theme_version'      => 'signal-and-noise/get-theme-version',
	'latest_theme_tag'   => 'signal-and-noise/get-latest-theme-tag',
	'design_tokens'      => 'signal-and-noise/get-design-tokens',
	'block_patterns'     => 'signal-and-noise/list-block-patterns',
	'template_overrides' => 'signal-noise/list-template-overrides',
	'active_template'    => 'signal-and-noise/get-active-template-structure',
	'llms_txt'           => 'signal-and-noise/get-llms-txt',
	'seo_route_meta'     => 'signal-and-noise/get-seo-route-meta',
	'pillars'            => 'signal-and-noise/get-page-notes-pillars',
	'reading_time'       => 'signal-and-noise/get-reading-time-for-slug',
);
ok( $expected_map === $map, 'the fact->source-slug map matches the verified live registrations exactly' );
ok( array( 'reading_time', 'seo_route_meta', 'active_template' ) === snt_sn_site_facts_slug_required(), 'R1 fix: reading_time + seo_route_meta + active_template all require slug (active_template\'s source ability has no no-args default path — see the file docblock)' );

// ─── Input validation errors ─────────────────────────────────────────────
$empty = snt_ability_sn_site_facts( array( 'facts' => array() ) );
ok( is_wp_error( $empty ) && 'snt_site_facts_empty' === $empty->get_error_code(), 'empty facts[] is rejected' );
ok( 422 === ( $empty->get_error_data()['status'] ?? null ), 'empty facts[] carries a 422 status' );

$unknown = snt_ability_sn_site_facts( array( 'facts' => array( 'theme_version', 'not_a_real_fact' ) ) );
ok( is_wp_error( $unknown ) && 'snt_site_facts_unknown' === $unknown->get_error_code(), 'an unrecognized fact name is rejected' );
ok( false !== strpos( $unknown->message, 'not_a_real_fact' ), 'the rejection names the offending fact' );

$missing_slug = snt_ability_sn_site_facts( array( 'facts' => array( 'reading_time' ) ) );
ok( is_wp_error( $missing_slug ) && 'snt_site_facts_missing_slug' === $missing_slug->get_error_code(), 'reading_time without slug is rejected' );
ok( 400 === ( $missing_slug->get_error_data()['status'] ?? null ), 'the missing-slug rejection carries a 400 status (per the coordinator spec, distinct from the 422 input-shape errors)' );

$missing_slug2 = snt_ability_sn_site_facts( array( 'facts' => array( 'seo_route_meta' ) ) );
ok( is_wp_error( $missing_slug2 ) && 'snt_site_facts_missing_slug' === $missing_slug2->get_error_code(), 'seo_route_meta without slug is also rejected' );

// R1 (adversarial review round 2): active_template without slug — the
// original bug's exact trigger — must now take the SAME 400 missing-slug
// path as reading_time/seo_route_meta, not silently dispatch with no args.
$missing_slug3 = snt_ability_sn_site_facts( array( 'facts' => array( 'active_template' ) ) );
ok( is_wp_error( $missing_slug3 ) && 'snt_site_facts_missing_slug' === $missing_slug3->get_error_code(), 'R1: active_template without slug is rejected (400), matching reading_time/seo_route_meta' );
ok( 400 === ( $missing_slug3->get_error_data()['status'] ?? null ), 'R1: the active_template missing-slug rejection carries a 400 status' );
ok( false !== strpos( $missing_slug3->message, 'active_template' ), 'R1: the rejection message names active_template' );

$blank_slug = snt_ability_sn_site_facts( array( 'facts' => array( 'reading_time' ), 'slug' => '   ' ) );
ok( is_wp_error( $blank_slug ), 'a whitespace-only slug is treated as missing (trimmed before the check)' );

// A fact NOT requiring slug never triggers the check even if others would.
$no_slug_needed = snt_ability_sn_site_facts( array( 'facts' => array( 'theme_version' ) ) );
ok( ! is_wp_error( $no_slug_needed ), 'theme_version alone never requires slug' );

// ─── Graceful per-fact degradation — source ability MISSING (theme absent) ──
$GLOBALS['__abilities'] = array(); // nothing registered at all.
$degraded = snt_ability_sn_site_facts( array( 'facts' => array( 'theme_version', 'llms_txt' ) ) );
ok( true === $degraded['ok'], 'the CALL itself succeeds even when every source ability is missing' );
ok( array( 'error' => 'unavailable' ) === $degraded['facts']['theme_version'], 'theme_version degrades to {error:unavailable} when its source ability is unregistered (theme absent)' );
ok( array( 'error' => 'unavailable' ) === $degraded['facts']['llms_txt'], 'llms_txt degrades independently the same way' );

// ─── Graceful per-fact degradation — permission denied ──────────────────
$GLOBALS['__abilities']['signal-and-noise/get-theme-version'] = new SN_Test_Fact_Ability( false, array( 'version' => '10.42.0' ) );
$GLOBALS['__abilities']['signal-and-noise/get-llms-txt']      = new SN_Test_Fact_Ability( true, array( 'content' => 'llms.txt body' ) );
$mixed = snt_ability_sn_site_facts( array( 'facts' => array( 'theme_version', 'llms_txt' ) ) );
ok( array( 'error' => 'unavailable' ) === $mixed['facts']['theme_version'], 'permission-denied source degrades to {error:unavailable}' );
ok( array( 'content' => 'llms.txt body' ) === $mixed['facts']['llms_txt'], 'an UNRELATED fact still returns its real result — one failing source never sinks the whole call' );

// ─── Graceful per-fact degradation — execute() WP_Error ─────────────────
$GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] = new SN_Test_Fact_Ability( true, new WP_Error( 'boom', 'tokens unavailable' ) );
$exec_fail = snt_ability_sn_site_facts( array( 'facts' => array( 'design_tokens', 'llms_txt' ) ) );
ok( array( 'error' => 'unavailable' ) === $exec_fail['facts']['design_tokens'], 'an execute()-level WP_Error degrades to {error:unavailable}, never the raw WP_Error object' );
ok( array( 'content' => 'llms.txt body' ) === $exec_fail['facts']['llms_txt'], 'the sibling fact is unaffected' );

// ─── Real success path + slug threading ──────────────────────────────────
$GLOBALS['__abilities']['signal-and-noise/get-theme-version'] = new SN_Test_Fact_Ability( true, array( 'version' => '10.42.0' ) );
$GLOBALS['__abilities']['signal-and-noise/get-reading-time-for-slug'] = new SN_Test_Fact_Ability( true, array( 'minutes' => 4 ) );
$ok_case = snt_ability_sn_site_facts( array( 'facts' => array( 'theme_version', 'reading_time' ), 'slug' => 'some-note' ) );
ok( true === $ok_case['ok'], 'a fully-satisfied multi-fact call succeeds' );
ok( array( 'version' => '10.42.0' ) === $ok_case['facts']['theme_version'], 'theme_version returns its real result' );
ok( array( 'minutes' => 4 ) === $ok_case['facts']['reading_time'], 'reading_time returns its real result' );
ok( array( 'slug' => 'some-note' ) === $GLOBALS['__abilities']['signal-and-noise/get-reading-time-for-slug']->last_call_args(), 'reading_time\'s source ability is dispatched with {slug: <the provided slug>}' );
ok( null === $GLOBALS['__abilities']['signal-and-noise/get-theme-version']->last_call_args() || array() === $GLOBALS['__abilities']['signal-and-noise/get-theme-version']->last_call_args(), 'a fact that does not need slug is dispatched with no slug arg' );

// ─── R1: active_template, dispatched against the FAITHFUL theme stub ────
// (a) WITHOUT slug already covered above (400, never reaches dispatch).
// (b) WITH slug: the dispatcher must send EXACTLY {slug: <slug>} — the
//     faithful stub 404s on anything less, so this is a real, not
//     hand-waved, proof the fix actually threads the argument through.
$GLOBALS['__abilities']['signal-and-noise/get-active-template-structure'] =
	new SN_Test_Theme_Active_Template_Ability( array( 'template_slug' => 'single', 'blocks' => array() ) );
$at_case = snt_ability_sn_site_facts( array( 'facts' => array( 'active_template' ), 'slug' => 'some-note' ) );
ok( true === $at_case['ok'], 'R1: a satisfied active_template call succeeds' );
ok( array( 'template_slug' => 'single', 'blocks' => array() ) === $at_case['facts']['active_template'], 'R1: the stubbed success result lands under the active_template key (the faithful stub did NOT 404, proving args were non-empty)' );
ok( array( 'slug' => 'some-note' ) === $GLOBALS['__abilities']['signal-and-noise/get-active-template-structure']->last_call_args(), 'R1: the dispatcher sends EXACTLY {slug: <the provided slug>} to the theme ability — pinned, not just "no error"' );

// Direct regression guard on the dispatcher itself: if a future edit ever
// reintroduces empty-args dispatch for active_template, THIS assertion goes
// RED even if the facts[]-required-slug gate above were also somehow
// bypassed — it exercises snt_sn_site_facts_dispatch() directly against the
// faithful stub with the exact empty-args shape the original bug sent.
$regression_probe = snt_sn_site_facts_dispatch( 'signal-and-noise/get-active-template-structure', array() );
ok( array( 'error' => 'unavailable' ) === $regression_probe, 'R1 regression guard: dispatching active_template with EMPTY args against the faithful theme stub 404s and degrades to {error:unavailable} — exactly the bug this fix closes, still reproducible on demand' );

// ─── Ability registration ────────────────────────────────────────────────
$GLOBALS['__abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__abilities'][ $slug ] = $args; }
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }

$a = $GLOBALS['__abilities']['signal-noise/sn-site-facts'] ?? null;
ok( is_array( $a ), 'signal-noise/sn-site-facts is registered' );
ok( 'snt_ability_perm_manage_options' === ( $a['permission_callback'] ?? '' ), 'sn-site-facts gates on manage_options' );
ok( true === ( $a['meta']['annotations']['readonly'] ?? false ) && false === ( $a['meta']['annotations']['destructive'] ?? true ) && true === ( $a['meta']['annotations']['idempotent'] ?? false ), 'sn-site-facts is annotated readonly + non-destructive + idempotent' );
ok( array( 'facts' ) === ( $a['input_schema']['required'] ?? array() ), 'sn-site-facts requires facts' );
ok( 'object' === ( $a['input_schema']['type'] ?? '' ), 'sn-site-facts input type is plain object (required field present, no bodyless-GET union)' );
ok( 10 === count( $a['input_schema']['properties']['facts']['items']['enum'] ?? array() ), 'the advertised facts[] enum lists exactly 10 values' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
