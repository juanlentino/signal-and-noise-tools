<?php
/**
 * The inbound pass — v13.68.0.
 *
 * Properties: (1) the selector nominates ONLY notes with zero inbound, caps
 * sources per note, and reports an unbuilt artifact as a fact rather than
 * as "no related notes"; (2) the judge loop classifies every outcome the
 * suggest impl can return — ready / declined / already-linked / error — and
 * an unavailable provider aborts the run as UNAVAILABLE, never as a clean
 * zero; (3) the verdict over the stored report reads those states in order
 * of concern and is never critical; (4) registration + door wiring: read
 * allowlist, sn-status section, a remote verdict, the owned-hook list.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
$GLOBALS['__single'] = array(); $GLOBALS['__next'] = array();
function wp_next_scheduled( $h ) { return $GLOBALS['__next'][ $h ] ?? false; }
function wp_schedule_single_event( $ts, $h ) { $GLOBALS['__single'][] = array( $ts, $h ); $GLOBALS['__next'][ $h ] = $ts; return true; }
function __( $s, $d = null ) { return $s; }
function apply_filters( $h, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
$GLOBALS['__registered'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
class WP_Error { public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function snt_ability_perm_manage_options() { return true; }
function snt_sn_site_facts_dispatch( $slug, $args ) { return array( 'dispatched' => $slug ); }
$GLOBALS['__opts'] = array();
function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

require_once __DIR__ . '/../inc/inbound-pass.php';
require_once __DIR__ . '/../inc/abilities-inbound-pass.php';
require_once __DIR__ . '/../inc/abilities-sn-status.php';
require_once __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
require_once __DIR__ . '/../inc/cron-dashboard.php';

echo "inbound pass — v13.69.0 (past, present, future)\n\n";

// ─── (1) the selector ───
$recent  = array(
	array( 'id' => 10, 'key' => '/notes/unlinked' ),
	array( 'id' => 11, 'key' => '/notes/linked' ),
	array( 'id' => 12, 'key' => '/notes/unbuilt' ),
	array( 'id' => 0,  'key' => '/notes/bad' ),
);
$inbound = array( '/notes/linked' => array( 'inbound' => 1 ) ); // computed, absent = a real zero
$related = array(
	10 => array( array( 'post_id' => 1, 'score' => 0.9 ), array( 'post_id' => 10, 'score' => 0.8 ), array( 'post_id' => 2, 'score' => 0.7 ), array( 'post_id' => 2, 'score' => 0.6 ), array( 'post_id' => 3, 'score' => 0.5 ), array( 'post_id' => 4, 'score' => 0.4 ) ),
	11 => array( array( 'post_id' => 1, 'score' => 0.9 ) ),
	12 => null,
);
$sel = sn_inbound_pass_select( $recent, $inbound, $related )['notes'];
ok( array( 10, 12 ) === array_column( $sel, 'target_id' ), 'PAST: selects every zero-inbound published note (no date window): the linked one is out, the malformed row is out' );
ok( array( 1, 2, 3 ) === $sel[0]['sources'] && 'built' === $sel[0]['artifact'], 'sources: self skipped, duplicate collapsed, capped at ' . SN_INBOUND_PASS_MAX_PER_NOTE . ' in artifact order' );
ok( array() === $sel[1]['sources'] && 'unbuilt' === $sel[1]['artifact'] && 0 === $sel[1]['inbound'], 'an unbuilt artifact is reported as unbuilt — not as "no related notes"' );
ok( array( 'notes' => array(), 'deferred' => 0 ) === sn_inbound_pass_select( array(), $inbound, $related ), 'nothing published → nothing selected, nothing deferred' );
$b = sn_inbound_pass_select( $recent, $inbound, $related, 2 );
ok( array( 1, 2 ) === $b['notes'][0]['sources'] && 1 === $b['deferred'], 'the per-run budget stops judging at N pairs and COUNTS what it deferred — never silently drops it' );

// ─── (2) the judge loop ───
$calls = array();
$stub  = function ( $s, $t ) use ( &$calls ) {
	$calls[] = array( $s, $t );
	if ( 1 === $s ) { return array( 'verdict' => 'link', 'can_apply' => true, 'anchor' => 'a phrase in the prose' ); }
	if ( 2 === $s ) { return array( 'verdict' => 'link', 'can_apply' => false, 'anchor' => '' ); } // advice-only
	if ( 3 === $s ) { return new WP_Error( 'snt_ai_link_already_linked', 'linked', array( 'status' => 409 ) ); }
	return new WP_Error( 'snt_ai_runtime_error', 'boom', array( 'status' => 500 ) );
};
$sel2 = array( array( 'target_id' => 10, 'key' => '/notes/u', 'inbound' => 0, 'artifact' => 'built', 'sources' => array( 1, 2, 3, 4 ) ) );
$j    = sn_inbound_pass_judge( $sel2, $stub );
ok( array( array( 1, 10 ), array( 2, 10 ), array( 3, 10 ), array( 4, 10 ) ) === $calls, 'judges OLDER → NEW: source first, the new note as target, one call per source' );
ok( false === $j['unavailable'] && array( 'notes' => 1, 'pairs' => 4, 'ready' => 1, 'linked' => 1, 'declined' => 1, 'errors' => 1 ) === $j['counts'], 'counts: one ready, one declined (link without a valid anchor is advice-only), one already linked, one error' );
$p = $j['notes'][0]['pairs'];
ok( 'ready' === $p[0]['outcome'] && 'a phrase in the prose' === $p[0]['anchor'] && 'declined' === $p[1]['outcome'] && '' === $p[1]['anchor'] && 'linked' === $p[2]['outcome'] && 'error' === $p[3]['outcome'] && 'snt_ai_runtime_error' === $p[3]['code'], 'each pair row carries its outcome; only a ready pair carries an anchor' );
$dead = function ( $s, $t ) { return new WP_Error( 'snt_ai_unavailable', 'no provider', array( 'status' => 503 ) ); };
$ju   = sn_inbound_pass_judge( $sel2, $dead );
ok( true === $ju['unavailable'] && array() === $ju['notes'] && 0 === $ju['counts']['ready'], 'FAIL-CLOSED: an unavailable provider aborts as unavailable — no note is reported judged' );

// ─── (3) the verdict ───
$h = sn_inbound_pass_health( null );
ok( 'good' === $h['status'] && false !== strpos( $h['summary'], 'not run' ), 'never run → good, saying so' );
$h = sn_inbound_pass_health( array( 'state' => 'unavailable', 'reason' => 'snt_ai_unavailable', 'counts' => array() ) );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'snt_ai_unavailable' ), 'unavailable → recommended, naming the reason (not "nothing to apply")' );
$h = sn_inbound_pass_health( array( 'state' => 'ok', 'published' => 2, 'counts' => array( 'notes' => 1, 'ready' => 2 ) ) );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], '2 anchor(s)' ), 'ready anchors → recommended with the count' );
$h = sn_inbound_pass_health( array( 'state' => 'ok', 'published' => 2, 'counts' => array( 'notes' => 0, 'ready' => 0 ), 'scheduled' => array( array( 'outbound' => 2 ) ) ) );
ok( 'good' === $h['status'] && false !== strpos( $h['summary'], '2 published, 0 with no inbound links, 1 scheduled' ), 'nothing to apply → good, still reporting what was walked' );
$h = sn_inbound_pass_health( array( 'state' => 'ok', 'published' => 2, 'counts' => array( 'notes' => 0, 'ready' => 0 ), 'scheduled' => array( array( 'outbound' => 0 ), array( 'outbound' => 3 ) ) ) );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], '1 scheduled note(s) carry no outbound' ), 'FUTURE: a scheduled note with zero outbound links → recommended (fixable before it publishes)' );

// ─── (3b) the future half ───
$sch = sn_inbound_pass_scheduled( array(
	array( 'id' => 7, 'slug' => 'a', 'date' => '2026-09-04 10:23:46', 'content' => '<p>x <a href="https://juanlentino.com/notes/one/">1</a> and <a href=\'/notes/two\'>2</a> and <a href="https://example.com/notes-not/">no</a></p>' ),
	array( 'id' => 8, 'slug' => 'b', 'date' => '2026-09-06 16:39:52', 'content' => '<p>no links</p>' ),
) );
ok( 2 === $sch[0]['outbound'] && 0 === $sch[1]['outbound'] && 'b' === $sch[1]['slug'] && '2026-09-06 16:39:52' === $sch[1]['date_gmt'], 'scheduled rows count /notes/ hrefs only (absolute or relative), zero when none' );

// ─── (3c) the present half ───
$post = (object) array( 'ID' => 5, 'post_type' => 'post' );
sn_inbound_pass_on_transition( 'publish', 'publish', $post );
sn_inbound_pass_on_transition( 'draft', 'publish', $post );
sn_inbound_pass_on_transition( 'publish', 'future', (object) array( 'ID' => 6, 'post_type' => 'page' ) );
ok( array() === $GLOBALS['__single'], 'PRESENT: update-in-place, unpublish, and a page schedule nothing' );
sn_inbound_pass_on_transition( 'publish', 'future', $post );
sn_inbound_pass_on_transition( 'publish', 'draft', $post );
ok( 1 === count( $GLOBALS['__single'] ) && SN_INBOUND_PASS_PUBLISH_HOOK === $GLOBALS['__single'][0][1] && $GLOBALS['__single'][0][0] >= time() + SN_INBOUND_PASS_PUBLISH_DELAY - 1, 'a transition INTO publish schedules ONE single run after the delay; a second publish coalesces' );
$src = (string) file_get_contents( __DIR__ . '/../inc/inbound-pass.php' );
ok( false === strpos( str_replace( 'Never `critical`', '', $src ), "'critical'" ), 'REGRESSION: never critical — an advisory' );

// ─── (4) registration + doors ───
foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) { $cb(); }
$reg = $GLOBALS['__registered']['signal-noise/inbound-pass'] ?? null;
ok( is_array( $reg ) && 'snt_ability_inbound_pass' === $reg['execute_callback'] && 'snt_ability_perm_manage_options' === $reg['permission_callback'], 'signal-noise/inbound-pass registers, manage_options' );
ok( true === ( $reg['meta']['annotations']['readonly'] ?? null ) && array( 'good', 'recommended' ) === $reg['output_schema']['properties']['status']['enum'], 'readonly; the schema enum has no critical' );
ok( in_array( 'signal-noise/inbound-pass', sn_mcp_allowlist(), true ), 'on the read-door allowlist' );
ok( 'signal-noise/inbound-pass' === ( snt_sn_status_map()['inbound_pass'] ?? null ), 'sn-status routes inbound_pass to it' );
ok( false === ( sn_mcp_remote_verdicts()['inbound_pass']['remote'] ?? null ), 'remote verdict recorded: LOCAL' );
ok( snt_cron_is_sn_owned( SN_INBOUND_PASS_HOOK ), 'the daily hook is SN-owned (the unschedule guard refuses it)' );
ok( array_key_exists( SN_INBOUND_PASS_HOOK, $GLOBALS['__test_actions'] ) && array_key_exists( SN_INBOUND_PASS_PUBLISH_HOOK, $GLOBALS['__test_actions'] ), 'both hooks run sn_inbound_pass_run' );
ok( snt_cron_is_sn_owned( SN_INBOUND_PASS_PUBLISH_HOOK ) && snt_cron_hook_is_on_demand( SN_INBOUND_PASS_PUBLISH_HOOK ) && ! snt_cron_hook_is_on_demand( SN_INBOUND_PASS_HOOK ), 'the after-publish hook is SN-owned AND on-demand (unscheduled is its resting state); the daily one is not on-demand' );
ok( in_array( 'sn_inbound_pass_on_transition', array_map( static fn( $c ) => is_string( $c ) ? $c : '', $GLOBALS['__test_actions']['transition_post_status'] ?? array() ), true ), 'listens on transition_post_status' );
$GLOBALS['__opts'][ SN_INBOUND_PASS_STATUS ] = array( 'state' => 'ok', 'published' => 1, 'counts' => array( 'notes' => 1, 'ready' => 1 ), 'notes' => array(), 'scheduled' => array() );
$out = snt_ability_inbound_pass( null );
ok( 'recommended' === $out['status'] && 'ok' === $out['last']['state'], 'the execute callback reads the stored report and its verdict' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
