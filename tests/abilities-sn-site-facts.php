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
// ~/Projects/signal-and-noise/inc/abilities-diagnostics.php:535-554, re-read
// live 2026-08-02). The real execute callback resolves $post from EITHER
// post_id OR slug; when NEITHER is present it returns
// WP_Error('post_not_found', ..., array('status'=>404)) — deterministically,
// with no no-args default path. Its slug branch DEFAULTS post_type to 'page'
// (get_page_by_path( $slug, OBJECT, $input['post_type'] ?? 'page' )), so a
// slug belonging to a POST resolves only when the caller sends
// post_type:'post' explicitly — the exact mechanism behind the
// {error:'unavailable'}-for-post-slugs bug this round fixes. Per the repo's
// standing stub-drift trap, this stub models BOTH steady states of that
// contract, not just the success shape: $target_post_type is the post_type
// the slug actually belongs to (null = slug matches nothing at all), and any
// lookup under the WRONG effective post_type 404s exactly like the real
// get_page_by_path() miss does.
class SN_Test_Theme_Active_Template_Ability {
	private $success; private $target_post_type; private $calls_with = array();
	public function __construct( $success, $target_post_type = 'page' ) {
		$this->success = $success; $this->target_post_type = $target_post_type;
	}
	public function check_permissions( $args = null ) { return true; }
	public function execute( $args = null ) {
		$this->calls_with[] = $args;
		$has_target = is_array( $args ) && ( ! empty( $args['post_id'] ) || ! empty( $args['slug'] ) );
		if ( ! $has_target ) {
			return new WP_Error( 'post_not_found', 'No post matches the given post_id or slug.', array( 'status' => 404 ) );
		}
		// Mirror abilities-diagnostics.php:542 — absent post_type means 'page'.
		$effective_post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'page';
		if ( null === $this->target_post_type || $effective_post_type !== $this->target_post_type ) {
			return new WP_Error( 'post_not_found', 'No post matches the given post_id or slug.', array( 'status' => 404 ) );
		}
		return $this->success;
	}
	public function last_call_args() { return end( $this->calls_with ); }
	public function all_call_args() { return $this->calls_with; }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/abilities-sn-site-facts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "sn_site_facts (consolidated) — plugin v10.26.0\n\n";

// ─── Fact map: exactly 10 facts, none pointing at the retired ability ───
$map = snt_sn_site_facts_map();
ok( 12 === count( $map ), 'the fact map has exactly 12 entries (get-design-system-summary retired, not absorbed; scan_telemetry added v10.61.0, tool_telemetry v11.9.0)' );
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
	'scan_telemetry'     => 'internal:scan-telemetry-summary',
	'tool_telemetry'     => 'internal:tool-telemetry-summary',
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

// ─── R1+R2: active_template, dispatched against the FAITHFUL theme stub ──
// (a) WITHOUT slug already covered above (400, never reaches dispatch).
// (b) PAGE slug (target_post_type 'page', the stub default): the dispatcher
//     must send EXACTLY {slug, post_type:'page'} and succeed on the FIRST
//     call — no wasteful second dispatch when the page lookup already hit.
$GLOBALS['__abilities']['signal-and-noise/get-active-template-structure'] =
	new SN_Test_Theme_Active_Template_Ability( array( 'template_slug' => 'page', 'blocks' => array() ), 'page' );
$at_case = snt_ability_sn_site_facts( array( 'facts' => array( 'active_template' ), 'slug' => 'ml-maturity' ) );
ok( true === $at_case['ok'], 'R2: a page-slug active_template call succeeds' );
ok( array( 'template_slug' => 'page', 'blocks' => array() ) === $at_case['facts']['active_template'], 'R2: the page-hit result lands under the active_template key (live-parity case: page slugs keep working)' );
ok( array( array( 'slug' => 'ml-maturity', 'post_type' => 'page' ) ) === $GLOBALS['__abilities']['signal-and-noise/get-active-template-structure']->all_call_args(), 'R2: page hit = EXACTLY ONE dispatch, with EXACTLY {slug, post_type:page} — order and count pinned' );

// (c) POST slug (target_post_type 'post'): the page-first dispatch 404s at
//     the theme, and the dispatcher must retry ONCE with post_type:'post'
//     and return the real result — the fix for the verified live bug where
//     post slugs degraded to {error:'unavailable'} forever.
$GLOBALS['__abilities']['signal-and-noise/get-active-template-structure'] =
	new SN_Test_Theme_Active_Template_Ability( array( 'template_slug' => 'single', 'blocks' => array() ), 'post' );
$at_post = snt_ability_sn_site_facts( array( 'facts' => array( 'active_template' ), 'slug' => 'provenance-signs-the-claim-not-the-truth' ) );
ok( true === $at_post['ok'], 'R2: a post-slug active_template call succeeds' );
ok( array( 'template_slug' => 'single', 'blocks' => array() ) === $at_post['facts']['active_template'], 'R2: the post-slug result is the REAL template structure, not {error:unavailable} — the live bug this round closes' );
ok( array(
	array( 'slug' => 'provenance-signs-the-claim-not-the-truth', 'post_type' => 'page' ),
	array( 'slug' => 'provenance-signs-the-claim-not-the-truth', 'post_type' => 'post' ),
) === $GLOBALS['__abilities']['signal-and-noise/get-active-template-structure']->all_call_args(), 'R2: post hit = EXACTLY page-first then post-retry, both arg shapes pinned' );

// (d) Slug matching NEITHER post type: both dispatches 404 and the fact
//     degrades to the documented {error:'unavailable'} — after exactly two
//     attempts, never more.
$GLOBALS['__abilities']['signal-and-noise/get-active-template-structure'] =
	new SN_Test_Theme_Active_Template_Ability( array( 'template_slug' => 'never-returned' ), null );
$at_miss = snt_ability_sn_site_facts( array( 'facts' => array( 'active_template' ), 'slug' => 'no-such-slug' ) );
ok( true === $at_miss['ok'], 'R2: a nonexistent slug still succeeds at the CALL level (per-fact degradation contract)' );
ok( array( 'error' => 'unavailable' ) === $at_miss['facts']['active_template'], 'R2: both-miss degrades to {error:unavailable} — only when page AND post lookups both fail' );
ok( 2 === count( $GLOBALS['__abilities']['signal-and-noise/get-active-template-structure']->all_call_args() ), 'R2: both-miss made EXACTLY two attempts (page then post), no runaway retries' );

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
ok( 12 === count( $a['input_schema']['properties']['facts']['items']['enum'] ?? array() ), 'the advertised facts[] enum lists exactly 12 values' );

/* ════════════════════════════════════════════════════════════════════════
 * scan_telemetry (v10.61.0) — plugin-internal fact, the active_template
 * special-case precedent: never an ability dispatch.
 * ════════════════════════════════════════════════════════════════════════ */

// Module absent -> uniform degradation, and the sentinel slug must never
// reach wp_get_ability (a dispatch attempt would ALSO degrade to
// unavailable, so pin the call log, not just the shape).
$GLOBALS['__ability_lookups'] = array();
$r_absent = snt_ability_sn_site_facts( array( 'facts' => array( 'scan_telemetry' ) ) );
ok( array( 'error' => 'unavailable' ) === ( $r_absent['facts']['scan_telemetry'] ?? null ), 'scan_telemetry: telemetry module absent -> uniform {error:unavailable}' );
ok( ! in_array( 'internal:scan-telemetry-summary', $GLOBALS['__ability_lookups'], true ), 'scan_telemetry: the internal sentinel is NEVER passed to wp_get_ability' );

// Module present -> the summary is returned verbatim; no slug required.
// CONDITIONAL declaration: an unconditional top-level `function` is hoisted
// at compile time and would exist during the module-absent test above.
if ( ! function_exists( 'snt_scan_telemetry_summary' ) ) {
	function snt_scan_telemetry_summary( $days = 30 ) {
		return array( 'window_days' => $days, 'table_present' => true, 'total_runs' => 2, 'rows' => array( array( 'scan_type' => 'block_migrations', 'outcome' => 'ok', 'runs' => 1 ) ) );
	}
}
/*
 * tool_telemetry (v11.9.0) — same plugin-internal pattern. Asserted BEFORE the
 * summary function is defined below, so the module-absent branch is exercised
 * for real rather than assumed.
 */
$t_absent = snt_ability_sn_site_facts( array( 'facts' => array( 'tool_telemetry' ) ) );
ok( array( 'error' => 'unavailable' ) === ( $t_absent['facts']['tool_telemetry'] ?? null ), 'tool_telemetry: telemetry module absent -> uniform {error:unavailable}' );
ok( ! in_array( 'internal:tool-telemetry-summary', $GLOBALS['__ability_lookups'], true ), 'tool_telemetry: the internal sentinel is NEVER passed to wp_get_ability' );

if ( ! function_exists( 'sn_mcp_telemetry_summary' ) ) {
	function sn_mcp_telemetry_summary( $days = 30 ) {
		return array(
			'window_days'    => $days,
			'table_present'  => true,
			'total_calls'    => 5,
			'by_tool'        => array(),
			'by_change_type' => array( array( 'change_type' => 'link_reshape', 'outcome' => 'conflict', 'calls' => 2 ) ),
			'by_error_code'  => array( array( 'tool_name' => 'signal-noise__sn-apply', 'error_code' => 'snt_sn_apply_fingerprint_stale', 'outcome' => 'conflict', 'calls' => 2 ) ),
		);
	}
}
$t_present = snt_ability_sn_site_facts( array( 'facts' => array( 'tool_telemetry' ) ) );
ok( 5 === ( $t_present['facts']['tool_telemetry']['total_calls'] ?? null ), 'tool_telemetry: summary returned verbatim when the module is loaded' );
ok( 'link_reshape' === ( $t_present['facts']['tool_telemetry']['by_change_type'][0]['change_type'] ?? null ), 'tool_telemetry: by_change_type reaches the caller — the whole point of the v11.8.0 column' );
ok( 'snt_sn_apply_fingerprint_stale' === ( $t_present['facts']['tool_telemetry']['by_error_code'][0]['error_code'] ?? null ), 'tool_telemetry: by_error_code reaches the caller verbatim' );

$r_present = snt_ability_sn_site_facts( array( 'facts' => array( 'scan_telemetry', 'theme_version' ) ) );
ok( 2 === ( $r_present['facts']['scan_telemetry']['total_runs'] ?? null ), 'scan_telemetry: summary returned verbatim when the module is loaded' );
ok( true === ( $r_present['facts']['scan_telemetry']['table_present'] ?? null ), 'scan_telemetry: table_present travels (zero-vs-null: honest empty window vs eaten rows)' );
ok( ! is_wp_error( $r_present ), 'scan_telemetry: needs NO slug (not in the slug-required set)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
