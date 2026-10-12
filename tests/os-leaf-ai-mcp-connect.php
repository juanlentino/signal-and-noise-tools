<?php
/**
 * Native window leaf: AI → MCP Clients (apps/sn-dashboard/parts/leaves/ai-mcp-connect.php).
 *
 * The oracle is the classic leaf (inc/admin-forms/mcp-connect.php +
 * inc/admin-forms/mcp-connect-status.php): same two forms
 * (bind_mcp_rw_credential, remote_toggle), same field names, same live
 * allowlist counts, same withheld-abilities list, none of wp-admin's markup.
 *
 * Run: php tests/os-leaf-ai-mcp-connect.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// ── The leaf's own readers — every one guarded by function_exists()/class_exists()
// in both the classic file and the port, so a missing stub degrades the same
// way on both sides.
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function sn_mcp_namespace() { return 'signal-noise/v1'; }
function get_edit_profile_url() { return 'https://example.test/wp-admin/profile.php'; }
function sn_mcp_allowlist() { return $GLOBALS['__read_slugs'] ?? array( 'signal-noise/sn-status', 'signal-noise/sn-posts', 'signal-noise/sn-scan' ); }
function sn_mcp_rw_allowlist() { return $GLOBALS['__rw_slugs'] ?? array( 'signal-noise/sn-apply', 'signal-noise/purge-all-caches' ); }
function sn_mcp_rw_bound_uuid() { return $GLOBALS['__rw_bound_uuid'] ?? ''; }
function sn_mcp_rw_kill_switch_constant_disabled() { return ! empty( $GLOBALS['__rw_constant_killed'] ); }
function sn_mcp_rw_enabled_option() { return $GLOBALS['__rw_option_on'] ?? true; }
function sn_mcp_remote_kill_switch_engaged() { return $GLOBALS['__remote_kill_engaged'] ?? true; }
function sn_bridge_secret() { return $GLOBALS['__bridge_secret'] ?? ''; }
// The optional usage-telemetry lane: same two accessors both the classic
// mcp-usage-block.php and the port's agent_tools_usage_html() guard behind
// function_exists(), so without these stubs neither side paints it at all;
// declaring them is what makes that whole section exercisable.
function sn_mcp_telemetry_usage() { return $GLOBALS['__usage'] ?? null; }
function sn_mcp_telemetry_table_exists() { return ! empty( $GLOBALS['__telemetry_installed'] ); }

$GLOBALS['__passwords'] = array();
class WP_Application_Passwords {
	public static function get_user_application_passwords( $user_id ) {
		return $GLOBALS['__passwords'];
	}
}

require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/admin-forms/mcp-connect.php';
require SNT_PATH . 'inc/admin-forms/mcp-usage-block.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-mcp-connect.php';
// 15.6.0: the door inventories and the call log moved to AI › Agent tools. The
// two leaves are painted as ONE string here (and the two classic sections as
// one), so every assertion below still reads the whole of what MCP Clients
// used to say; the split itself is pinned in tests/os-leaf-ai-agent-tools.php.
if ( ! function_exists( 'snt_mr_fetch' ) ) { function snt_mr_fetch( $d ) { return array( 'ok' => false, 'error' => 'no sensor in this suite' ); } }
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $a ) { return array(); } }
if ( ! function_exists( 'snt_ai_tool_invocations_render' ) ) { function snt_ai_tool_invocations_render() { echo ''; } }
require SNT_PATH . 'inc/agent-tools.php';
require SNT_PATH . 'inc/agent-tools-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-agent-tools.php';
// 2026-10-11 (owner rule, no collapsible sections): every paint of the two
// leaves is checked for a fold, and the tally is asserted at the end.
// 2026-10-12 (owner: "Some places earn it, other don't"): Connect a client
// is the one earned fold on these leaves; any other fold is a finding.
// 2026-10-12 audit: Agent tools' three door lists are earned too (reference;
// the summary states the count and the calls; tests/sn-dashboard-no-folds.php).
function ai_unearned_folds( $html ) {
	return snt_leaf_folds( preg_replace( '/<os-disclosure heading="(Connect a client|Read door: the native MCP server|Write door: the same server, read-write|Remote door: through the Cloudflare Worker)"/', '<os-section heading="$1"', (string) $html ) );
}
function ai_leaf_both() {
	$html                    = snt_leaf_paint( 'ai', 'mcp-connect' ) . snt_leaf_paint( 'ai', 'agent-tools' );
	$GLOBALS['__fold_hits']  = array_merge( $GLOBALS['__fold_hits'] ?? array(), ai_unearned_folds( $html ) );
	$GLOBALS['__fold_paint'] = ( $GLOBALS['__fold_paint'] ?? 0 ) + 1;
	return $html;
}
function ai_classic_both() { return snt_leaf_classic_html( 'sn_admin_render_mcp_connect_section' ) . snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['ai/mcp-connect'] ), 'the painter is registered under ai/mcp-connect' );

// ── Normal / rich fixture: one bound password, non-trivial allowlists.
$GLOBALS['__passwords']    = array( array( 'uuid' => 'uuid-1', 'name' => 'MacBook', 'created' => time() - 100000, 'last_used' => time() - 3600 ) );
$GLOBALS['__rw_bound_uuid'] = 'uuid-1';
$GLOBALS['__read_slugs']   = array( 'signal-noise/sn-status', 'signal-noise/sn-posts', 'signal-noise/sn-scan' );
$GLOBALS['__rw_slugs']     = array( 'signal-noise/sn-apply', 'signal-noise/purge-all-caches' );
$GLOBALS['__remote_kill_engaged'] = false;
$GLOBALS['__bridge_secret']       = 'shh';

$classic = ai_classic_both();
$kit     = ai_leaf_both();
ok( '' !== $kit, 'the kit leaf paints' );
// The classic remote-toggle form's submit_button() emits a stray name="submit"
// input — bog-standard wp-admin boilerplate no handler ever reads (sn_handle_remote_toggle()
// only looks at sn_remote_enabled) — so it is excluded from the oracle rather than
// faked: it carries no meaning to port.
$classic_names = array_values( array_diff( snt_leaf_names( $classic ), array( 'submit' ) ) );
ok( $classic_names === snt_leaf_names( $kit ), 'field names match the classic form (excluding the meaningless submit_button() "submit" input): kit=' . implode( ',', snt_leaf_names( $kit ) ) . ' classic=' . implode( ',', $classic_names ) );
ok( snt_leaf_actions( $classic ) === snt_leaf_actions( $kit ) && array( 'bind_mcp_rw_credential', 'remote_toggle' ) === snt_leaf_actions( $kit ), 'both actions (bind_mcp_rw_credential, remote_toggle) are offered, matching the classic leaf' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );

// ── Rich-fixture readouts: specific numbers/labels, not "non-empty".
// 2026-10-11 (no folds): the counts that headed the two tool-list folds lead
// open findings now, and every slug the folds hid is a chip in its door band.
ok( array() === ai_unearned_folds( $kit ), 'rich fixture: no fold but the earned one: ' . implode( ',', ai_unearned_folds( $kit ) ) );
ok( false !== strpos( $kit, '3 read-only tools' ) && false === strpos( $kit, 'show the list' ), 'the read door count (3) leads an open finding, not a fold heading' );
ok( false !== strpos( $kit, '3 read-only tools; their categories are not readable here, because the Abilities API did not answer for them.' ) && false !== strpos( $kit, '<os-fact label="Category not readable (3)">' ), 'no Abilities API here: the band says the categories are not readable and why, never a guessed category' );
ok( false !== strpos( $kit, '2 read-write tools' ) && false !== strpos( $kit, '4 abilities never reach this door: 1 permanently, 3 pending an explicit future opt-in.' ), 'the write door count (2) and the withheld count (4, built from the data) are painted open' );
$chips = array_filter( array_merge( $GLOBALS['__read_slugs'], $GLOBALS['__rw_slugs'] ), static function ( $slug ) use ( $kit ) { return false === strpos( $kit, '<os-chip>' . substr( $slug, strlen( 'signal-noise/' ) ) . '</os-chip>' ); } );
ok( array() === $chips, 'every allowlisted slug (3 read, 2 rw) is a chip in its door band, the prefix dropped: missing ' . implode( ',', $chips ) );
$withheld = array();
foreach ( snt_leaf_tables( $kit ) as $t ) {
	if ( 'signal-noise/run-cron-event' === ( $t['data'][0]['slug'] ?? '' ) ) {
		$withheld = $t;
	}
}
ok( 4 === count( $withheld['data'] ?? array() ) && 'signal-noise/ai-orphan-apply' === $withheld['data'][1]['slug'] && 'never' === $withheld['data'][0]['badge']['text'] && false !== strpos( (string) $withheld['data'][1]['reason'], 'skips the trash' ) && array( 'Ability', 'Status', 'Why' ) === array_column( $withheld['columns'], 'label' ), 'the withheld abilities are a 4-row kit table (Ability, Status, Why) from the classic data function' );
ok( false !== strpos( $kit, 'MacBook' ), 'the bound Application Password name (MacBook) is shown' );
ok( false !== strpos( $kit, 'Bound to' ), 'the RW-binding status reads Bound to …' );
ok( false !== strpos( $kit, 'Bridge ready' ), 'the remote-door card reads Bridge ready' );
ok( false !== strpos( $kit, 'Not installed' ), 'the adapter door reads Not installed (no adapter class declared)' );
ok( false !== strpos( $kit, '<os-select' ) && false !== strpos( $kit, 'name="sn_mcp_rw_uuid"' ), 'the credential picker is a kit select carrying the bound uuid selection' );
ok( false !== strpos( $kit, '<os-checkbox-label' ) && false !== strpos( $kit, 'name="sn_remote_enabled"' ), 'the remote toggle is a kit checkbox' );
// Live check on #2022: Unbind was an empty-bodied form under Save (a blank
// band) styled exactly like Save. Both now sit in one row: Save primary,
// Unbind outlined danger, each its own form's data-snt-form-submit button;
// Unbind's form holds only the empty credential and sn_mcp_rw_unbind.
$__bind = preg_match( '#<div class="snt-bind-row">(<os-form .*?</os-form>)(<os-form .*?</os-form>)</div>#s', $kit, $__bm ) ? $__bm : array( '', '', '' );
ok( false !== strpos( $__bm[1] ?? '', 'snt-form--saves-inline' ) && false !== strpos( $__bm[1] ?? '', '<os-cluster class="snt-save-row" gap="8"><os-button variant="primary" data-snt-form-submit>Save write-door credential</os-button></os-cluster>' ) && false !== strpos( $__bm[1] ?? '', 'name="sn_mcp_rw_uuid"' ) && false === strpos( $__bm[1] ?? '', 'sn_mcp_rw_unbind' ), 'bound: the Save form holds the picker and its own Save, in the row' );
$__css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/\.snt-bind-row > os-form:first-child \{\s*flex: 1 1 16rem;\s*min-width: 0;\s*\}/', $__css ) && 1 === preg_match( '/\.snt-bind-row > os-form:last-child \{\s*flex: 0 0 auto;\s*width: auto;\s*\}/', $__css ) && 1 === preg_match( '/\.snt-bind-row \{\s*display: flex;\s*flex-wrap: wrap;\s*align-items: flex-end;/', $__css ), 'the Save form grows and the Unbind form keeps its button\'s width, so both buttons share one line (live re-measure: Unbind wrapped under Save at 542px)' );
ok( 1 === preg_match( '#^<os-form class="snt-form snt-form--saves-inline"[^>]*os-confirm="Unbind the write-door credential\? Every write-door call is denied until you bind one again\."[^>]*os-confirm-danger><os-button variant="danger" data-snt-form-submit>Unbind</os-button><input type="hidden" name="action"[^>]*><input type="hidden" name="_wpnonce"[^>]*><input type="hidden" name="sn_mcp_rw_uuid" value=""><input type="hidden" name="sn_mcp_rw_unbind" value="1"></os-form>$#', $__bm[2] ?? '' ), 'bound: Unbind is beside Save, outlined danger, its own confirmed form with no visible body but the button' );
ok( false !== strpos( $kit, 'claude mcp add --transport http' ) && false !== strpos( $kit, '@automattic/mcp-wordpress-remote' ), 'both client configs (Claude Code one-liner, proxy JSON) survive' );

// ── Escaping: a hostile Application Password name never reaches the markup raw.
$GLOBALS['__passwords'] = array( array( 'uuid' => 'uuid-1', 'name' => '"><script>x</script>', 'created' => time(), 'last_used' => 0 ) );
$kit = ai_leaf_both();
ok( false === strpos( $kit, '<script>x</script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile Application Password name is escaped' );

// ── State: no Application Passwords yet — the binding form is skipped, a door offered instead.
$GLOBALS['__passwords']     = array();
$GLOBALS['__rw_bound_uuid'] = '';
$classic = ai_classic_both();
$kit     = ai_leaf_both();
ok( ! in_array( 'sn_mcp_rw_uuid', snt_leaf_names( $kit ), true ) && ! in_array( 'sn_mcp_rw_uuid', snt_leaf_names( $classic ), true ), 'no-passwords state: neither leaf offers the credential picker' );
ok( 1 === preg_match( '/<os-disclosure heading="Connect a client"[^>]*\sopen[\s=>]/', $kit ), 'no-passwords state: nothing can have connected yet, so Connect a client opens itself' );
ok( false !== strpos( $kit, 'no Application Passwords yet' ) && ! in_array( 'bind_mcp_rw_credential', snt_leaf_actions( $kit ), true ), 'no-passwords state: explained, and the bind action is not offered' );

// ── State: bound credential unresolvable (UUID matches no owned password).
$GLOBALS['__passwords']     = array( array( 'uuid' => 'uuid-2', 'name' => 'Other', 'created' => time(), 'last_used' => 0 ) );
$GLOBALS['__rw_bound_uuid'] = 'uuid-does-not-exist';
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'no longer matches any of your own Application Passwords' ), 'unresolvable state: the mismatch is explained' );

// ── State: write door switched off (option_off) vs remote bridge_ready toggled off.
$GLOBALS['__remote_kill_engaged'] = true;
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'Switched off' ), 'remote-door state: option-off reads Switched off' );
$GLOBALS['__remote_kill_engaged'] = false;

// ── The usage-telemetry states: sn_mcp_telemetry_usage() is guarded by
// function_exists() in both the classic mcp-usage-block.php and the port's
// agent_tools_usage_html() (mcp_connect_usage_html() until 2026-10-11): without the stubs above every one of these was
// invisible to the suite (a mutation replacing the whole port function with
// `return '';` still passed 21/21 before this block existed).
$GLOBALS['__usage']               = null;
$GLOBALS['__telemetry_installed'] = false;
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'not installed yet' ), 'usage state: table not installed yet reads "not installed yet"' );

$GLOBALS['__telemetry_installed'] = true;
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'could not be read' ), 'usage state: table installed but unreadable reads "could not be read"' );

$GLOBALS['__usage'] = array( 'measured_since' => null );
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'has recorded nothing' ), 'usage state: installed with zero rows reads "has recorded nothing"' );

// ── Populated usage fixture: 2 tools, a partial window, one unused + one
// unreachable + one first-party-only zero-call entry, the live door split of
// 2026-09-20 (#1587).
$GLOBALS['__usage'] = array(
	'measured_since' => '2026-01-01',
	'measured_days'  => 20,
	'window_days'    => 30,
	'complete'       => false,
	'total_rows'     => 75874,
	'by_door'        => array( 'direct' => 72182, 'read' => 1446, 'rw' => 1042, 'agent' => 1204 ),
	'by_tool'        => array(
		'sn-status' => array( 'calls' => 42, 'door_calls' => 42, 'direct_calls' => 0, 'last_seen' => '2026-02-01', 'doors' => array( 'read' ) ),
		'sn-apply'  => array( 'calls' => 30001, 'door_calls' => 1, 'direct_calls' => 30000, 'last_seen' => '2026-01-20', 'doors' => array( 'rw', 'direct' ) ),
	),
	'zero_call'      => array(
		array( 'slug' => 'signal-noise/unused-thing', 'verdict' => 'unused', 'calls' => 0 ),
		array( 'slug' => 'signal-noise/broken-thing', 'verdict' => 'unreachable', 'calls' => 0 ),
		array( 'slug' => 'signal-noise/get-deploy-status', 'verdict' => 'first_party_only', 'calls' => 28689 ),
	),
);
ok( array_sum( $GLOBALS['__usage']['by_door'] ) === $GLOBALS['__usage']['total_rows'], 'usage fixture: by_door sums to total_rows, as the reader pins for a live read' );
$classic = ai_classic_both();
$kit     = ai_leaf_both();
// 2026-10-11 (no folds): the classic summary line becomes a tile row and two
// findings on the kit leaf; every figure it carried is still painted.
$zero_finding = '3 allowlisted tools had no call through a door: 1 cannot be projected; 1 retirement candidate; 1 called only by the plugin itself.';
ok( false !== strpos( $kit, '20 days of a 30-day window' ) && false !== strpos( $classic, '20 days of a 30-day window' ) && false !== strpos( $kit, $zero_finding ) && false !== strpos( $classic, $zero_finding ), 'usage state: the measured window (20 of 30 days) and the tools with no calls THROUGH A DOOR (3, split by verdict) are painted, in the same words on both surfaces' );
ok( 3 === substr_count( substr( $kit, (int) strpos( $kit, 'heading="Through MCP"' ) ), '<os-stat ' ) && false !== strpos( $kit, 'value="43" label="Calls through a door"' ) && false !== strpos( $kit, 'value="2" label="Tools called through a door"' ) && false !== strpos( $kit, 'value="3" label="Tools with no door calls"' ), 'usage state: three tiles read the door calls (43), the tools called (2) and the tools with none (3)' );
ok( false !== strpos( $kit, '43 calls came through a door since January 1, 2026; sn-status took 98% of them.' ) && false !== strpos( $classic, '43 calls came through a door since January 1, 2026; sn-status took 98% of them.' ), 'usage state: the call-log finding is a lead sentence built from the data, its date in the site\'s format, on both surfaces' );
ok( false === strpos( $kit, 'more tools, busiest first' ) && false === strpos( $kit, 'more tools with no door calls' ), 'usage state: 2 called and 3 uncalled tools sit under both caps, so neither ledger says "+N more"' );
ok( false !== strpos( $kit, 'sn-status' ) && false !== strpos( $kit, '42' ) && false !== strpos( $kit, 'sn-apply' ) && false !== strpos( $kit, '1 (30,000 direct)' ) && false !== strpos( $classic, '1 (30,000 direct)' ), 'usage state: the Calls cell is door calls with the direct count in parentheses, on both surfaces' );
// 2026-10-12 audit: the doors by the names the screen gives them, never rw/agent/direct.
$door_split = '75,874 calls in all: 1,446 through the read door, 1,042 the write door, 0 the remote door, 1,204 from Desktop Mode agents, and 72,182 by the plugin&#039;s own surfaces.';
ok( false !== strpos( $kit, $door_split ) && false !== strpos( $classic, $door_split ), 'usage state: the door-split sentence paints with the same words on both surfaces' );
$first_party = "the plugin's own surfaces called it 28,689 times";
ok( false !== strpos( html_entity_decode( $kit, ENT_QUOTES, 'UTF-8' ), $first_party ) && false !== strpos( html_entity_decode( $classic, ENT_QUOTES, 'UTF-8' ), $first_party ) && false !== strpos( $kit, 'only for the door allowlist' ), 'usage state: a first-party-only tool is labelled with its direct count and as an allowlist (not code) candidate, on both surfaces' );
// 2026-10-12 audit: the uncalled tools are marked on their own chips in the
// door lists; no second section repeats every slug (the count stays a sentence).
$__mcp_at = (int) strpos( $kit, '<os-section heading="Through MCP"' );
ok( false === strpos( $kit, 'heading="No calls in this window"' ) && strpos( $kit, $zero_finding ) > $__mcp_at && strpos( $kit, $zero_finding ) < (int) strpos( $kit, '</os-section>', $__mcp_at ), 'usage state: the zero-call count is a sentence inside Through MCP, not a section of its own' );
ok( false !== strpos( $kit, 'predate the sensor' ), 'usage state: the partial-window caveat keeps its interpretive sentence' );
ok( false !== strpos( $kit, 'retirement candidate' ) && false !== strpos( $kit, 'evidence for removal' ), 'usage state: the zero-call trailing paragraph survives' );

// ── Faithfulness oracle beyond names/actions/markers: every classic sentence
// of meaningful length must survive into the kit render (as text or in an
// attribute), normalized so tag/entity noise doesn't matter. This is the
// assertion that would have caught every prose-reduction finding above —
// mutation-proven: deleting the resources-and-prompts, deep-links or adapter
// body (agent_tools_resources_prompts_html(), mcp_connect_footer_html(),
// agent_tools_door_adapter_html() since 2026-10-11) reds this
// block even though the older names/actions/markers assertions stayed green.
function ai_mcp_connect_normalize_text( $html ) {
	// Insert a sentence boundary at block edges FIRST: the kit and the classic
	// leaf lay the same prose out in different elements (a <label>, a heading,
	// a notice), so without this a run of un-punctuated block text (a checkbox
	// label immediately followed by the next section's heading) would merge
	// into one "sentence" that can never match across two different layouts.
	$text = (string) preg_replace( '/<\s*(p|li|h[1-6]|label|button|div|section|td|th|br|span|code|os-code|pre)\b[^>]*>/i', '. ', (string) $html );
	$text = preg_replace( '/<\/\s*(p|li|h[1-6]|label|button|div|section|td|th|span|code|os-code|pre)\s*>/i', '. ', $text );
	$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/[\x{2018}\x{2019}]/u', "'", $text );
	$text = preg_replace( '/[\x{201C}\x{201D}]/u', '"', $text );
	// Collapse the double punctuation the block-boundary markers above can
	// introduce next to a sentence that already ended in its own punctuation
	// (e.g. classic's <p>…Worker.</p> vs the kit's plain <span>…Worker.</span>
	// — only one of the two sides gets an inserted boundary marker there).
	$text = preg_replace( '/\s*([.!?])(?:\s*[.!?])+/', '$1', $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( (string) $text );
}
function ai_mcp_connect_split_sentences( $html ) {
	// A tile's caption is painted text too (2026-10-12: the call log's tiles are on both surfaces).
	preg_match_all( '/<[a-z-]+[^>]*\b(?:heading|hint|description|label|caption)="([^"]+)"/i', (string) $html, $attr_m );
	$attrs = array_map( 'ai_mcp_connect_normalize_text', array_filter( $attr_m[1] ) );
	$body  = ai_mcp_connect_normalize_text( $html );
	$parts = preg_split( '/(?<=[.!?])\s+/', $body );
	return array_merge( $attrs, $parts );
}
$kit_norm = ai_mcp_connect_normalize_text( $kit ) . ' ' . implode( ' ', ai_mcp_connect_split_sentences( $kit ) );
$missing  = '';
// 15.6.0: Agent tools' data tables (the bridge's tool rows, the documents)
// are pinned cell by cell in tests/os-leaf-ai-agent-tools.php; the kit paints
// them as an <os-table> attribute the normalizer strips with the tag, so the
// prose oracle reads the classic side without them.
// 2026-10-11 (no folds): the same holds for the lists the kit now paints as
// tables (the withheld abilities, the tools with no door calls); a fold's
// <summary> is the fold's own chrome ("... show the list"), and every figure
// it carried is pinned on its own above. Two sentences the kit rewrote are
// matched to their new words instead: the withheld count is built from the
// data, and the desktop-app step no longer points "below" at a config that
// sits above it.
$classic_prose = preg_replace( array( '#<table.*?</table>#s', '#<summary>.*?</summary>#s', '#<ul class="sn-mcp-tool-list">.*?</ul>#s', '#<ul class="sn-mcp-usage-zero">.*?</ul>#s' ), '', $classic );
$reworded      = array(
	'Four abilities never reach this door' => '4 abilities never reach this door: 1 permanently, 3 pending an explicit future opt-in.',
	// 2026-10-12 audit: the classic steps say the kit's words now (no "config
	// below" or "above"), so the oracle below holds them to it directly.
);
foreach ( $reworded as $new ) {
	ok( false !== strpos( $kit_norm, $new ), 'the kit paints its reworded sentence: ' . $new );
}
foreach ( ai_mcp_connect_split_sentences( $classic_prose ) as $sentence ) {
	$sentence = trim( (string) $sentence );
	if ( strlen( $sentence ) <= 40 || array() !== array_filter( array_keys( $reworded ), static function ( $old ) use ( $sentence ) { return 0 === strpos( $sentence, $old ); } ) ) {
		continue;
	}
	$haystack = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $kit_norm ) );
	$needle   = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $sentence ) );
	if ( '' !== $needle && false === strpos( $haystack, $needle ) ) {
		$missing = $sentence;
		break;
	}
}
ok( '' === $missing, 'every classic sentence over 40 chars survives into the kit render: first missing = "' . $missing . '"' );

// ── State: the remote door killed by the wp-config constant wins unconditionally
// (constants can't be un-defined, so this runs last).
define( 'SN_MCP_REMOTE_DISABLED', true );
$kit = ai_leaf_both();
ok( false !== strpos( $kit, 'Killed in wp-config' ) && false !== strpos( $kit, 'disabled' ), 'remote-door state: the wp-config kill switch reads Killed in wp-config and disables the toggle' );


// ── The reference half is a 2x2 grid, not a 1,000px vertical essay. ──
// The tile row at the top of this leaf already says there are FOUR doors and
// puts them side by side; the body used to explain the same four one under the
// other, each in a full-width section whose prose used under half of it.
// Measured live 2026-09-10: 2,852px -> 2,136px once paired, cards at 865px.
$kit = ai_leaf_both();

// 2026-10-12, measured live: the read door (494px) and the write door (709px)
// share a row (1.4x); the adapter and the resources stay paired. MCP Clients
// paints no pair of the leaf-row shape (its grids are its tiles, its two
// controls and the two clients), so both rows are Agent tools'.
// 2026-10-12 audit: the three door lists are folds one under the other
// (closed, one summary line each); the adapter and the resources stay paired.
ok( 1 === substr_count( $kit, snt_leaf_row() ), 'one paired row is painted across the two leaves, on Agent tools -- ' . substr_count( $kit, snt_leaf_row() ) );
$pairs = array(
	array( 'Core MCP adapter: the Abilities-registry door', 'Resources &amp; prompts' ),
);
foreach ( $pairs as $i => $pair ) {
	if ( preg_match_all( '/' . preg_quote( snt_leaf_row(), '/' ) . '(.*?)<\/os-grid>/s', $kit, $m ) && isset( $m[1][ $i ] ) ) {
		$row = $m[1][ $i ];
		ok( false !== strpos( $row, $pair[0] ) && false !== strpos( $row, $pair[1] ), 'row ' . $i . ' pairs ' . $pair[0] . ' with ' . $pair[1] );
	} else {
		ok( false, 'row ' . $i . ' exists' );
	}
}

// The task path keeps the width: it carries a JSON config block and a CLI
// command, and wrapping either is worse than the space costs.
ok(
	false === strpos( preg_replace( '/' . preg_quote( snt_leaf_row(), '/' ) . '.*?<\/os-grid>/s', '', $kit ), snt_leaf_row() )
	&& false !== strpos( $kit, 'Connect a client' ),
	'Connect a client is NOT paired -- the code block keeps the full width'
);

// 2026-10-12 redesign (owner: "The MCP clients page is also weird. You need
// to do better."). The four doors are tiles; each control sits with its door;
// Connect a client names both addresses, Claude Code beside the Claude desktop
// app, and the config they paste under both; no sentence points at a "Door 1",
// a "Door 2" or "below" that the page does not have.
$mcp = snt_leaf_paint( 'ai', 'mcp-connect' );
foreach ( array( 'Three MCP doors', 'Door 1 above', 'Application Password below', 'since v9.51.0', 'Status at a glance' ) as $gone ) {
	ok( false === strpos( $mcp, $gone ), "MCP Clients no longer says: $gone" );
}
// Agent tools names the doors as MCP Clients' tiles do, and says no "above" or
// "below" about a door: the read and write doors now share a row.
$tools_leaf = snt_leaf_paint( 'ai', 'agent-tools' );
foreach ( array( 'Door 1', 'Door 2', 'door below', 'door above', 'tools above', 'since v9.' ) as $gone ) {
	ok( false === strpos( $tools_leaf, $gone ), "Agent tools no longer says: $gone" );
}
ok( false !== strpos( $tools_leaf, 'heading="Read door: ' ) && false !== strpos( $tools_leaf, 'heading="Write door: ' ) && false !== strpos( $tools_leaf, 'heading="Core MCP adapter: ' ), 'Agent tools heads each door with the name its MCP Clients tile uses' );
ok( false !== strpos( $mcp, 'Four MCP doors can answer for this site' ) && false !== strpos( $mcp, 'That inbound grant is the Application Password each client signs in with.' ), 'the intro counts the four doors the tiles show, and the footnote points at the grant it means' );
ok( 4 === substr_count( $mcp, '<os-stat ' ) && false !== strpos( $mcp, 'label="Read door"' ) && false !== strpos( $mcp, 'label="Write door"' ) && false !== strpos( $mcp, 'label="Remote door"' ) && false !== strpos( $mcp, 'label="Core MCP adapter"' ), 'the four doors are four tiles' );
$ctl = strpos( $mcp, '<os-section heading="Write-door credential"' );
$rem = strpos( $mcp, '<os-section heading="Remote door"' );
ok( false !== strpos( $mcp, snt_leaf_row( 2, 320, 18 ) . '<os-section heading="Write-door credential"' ) && false !== $rem && $rem > $ctl && false === strpos( substr( $mcp, $ctl, $rem - $ctl ), '</os-grid>' ), 'the write-door credential and the remote door share one row' );
ok( false !== strpos( substr( $mcp, (int) $rem ), 'name="sn_remote_enabled"' ) && strpos( $mcp, 'name="sn_remote_enabled"' ) > $rem, 'the remote switch sits inside the Remote door, not floating under the tiles' );
$con = strpos( $mcp, '<os-disclosure heading="Connect a client"' );
$con_html = false !== $con ? substr( $mcp, $con ) : '';
ok( false !== strpos( $con_html, '<os-fact label="Read door">' ) && false !== strpos( $con_html, '<os-fact label="Write door">' ) && false !== strpos( $con_html, '/mcp-rw' ), 'Connect a client names both door addresses' );
ok( false !== strpos( $con_html, '<p class="snt-prose">Create an <os-button variant="link" os-action="door" os-arg-url="https://example.test/wp-admin/profile.php#application-passwords-section"' ), 'Connect a client opens with the Application Password door' );
ok( 1 === substr_count( $mcp, '<os-steps>' ) && 4 === substr_count( $mcp, '<os-step>' ), 'one <os-steps> of four: the Claude desktop app -- ' . substr_count( $mcp, '<os-steps>' ) . '/' . substr_count( $mcp, '<os-step>' ) );
ok( false === strpos( $mcp, '<ol' ), 'no browser ordered list survives on MCP Clients' );
ok( array() === ai_unearned_folds( $mcp ), 'MCP Clients folds nothing but Connect a client: ' . implode( ',', ai_unearned_folds( $mcp ) ) );
ok( 1 === preg_match( '/<os-disclosure heading="Connect a client" hint="Claude Code, the Claude desktop app, or any client through @automattic\/mcp-wordpress-remote, with both door addresses"/', $mcp ), 'closed, the Connect a client fold still says what is inside' );
ok( 3 === substr_count( $con_html, '<h4 class="snt-h">' ) && false === strpos( $con_html, '<b>Claude' ), 'each client inside Connect a client is a sub-heading, not a bold paragraph' );
$app      = strpos( $mcp, '<h4 class="snt-h">Claude desktop app</h4>' );
$app_html = false !== $app ? substr( $mcp, $app, (int) strpos( $mcp, '</os-grid>', $app ) - $app ) : '';
ok( false !== $app && 4 === substr_count( $app_html, '<os-step>' ) && false !== strpos( $app_html, 'Install Node.js' ) && false !== strpos( $app_html, 'Fully restart the Claude app' ) && false !== strpos( $app_html, 'Add custom connector' ), 'the Claude desktop app holds its four steps open, from Install Node.js to the restart, and the connector warning -- ' . substr_count( $app_html, '<os-step>' ) );
ok( false !== strpos( $app_html, '<os-code>claude_desktop_config.json</os-code>' ) && false !== strpos( $app_html, '<os-code>~/Library/Application Support/Claude/</os-code>' ) && false !== strpos( $app_html, '<os-code>%APPDATA%\\Claude\\</os-code>' ), 'the config file and both paths are inline code in the second step' );
$__cg = strpos( $mcp, '<os-grid min-item-width="320" gap="24" style="--snt-grid-items:2"><os-stack' );
ok( false !== $__cg && false !== strpos( $mcp, '<h4 class="snt-h">Claude Code</h4>' ) && strpos( $mcp, '<h4 class="snt-h">Claude Code</h4>' ) < $__cg && strpos( $mcp, 'claude mcp add --transport http' ) < $__cg, 'Claude Code takes the full width, before the pair (its command fits on one line)' );
ok( false !== $__cg && 2 === preg_match_all( '#<os-stack[^>]*>#', substr( $mcp, $__cg, (int) strpos( $mcp, '</os-grid>', $__cg ) - $__cg ) ) && $app > $__cg && strpos( $mcp, '@automattic/mcp-wordpress-remote@latest' ) > $app, 'the desktop app\'s steps and the config they paste are the pair, two grid items (loose siblings would each take a track)' );
ok( false === strpos( $mcp, 'config below' ) && false === strpos( $mcp, 'config above' ), 'no step says where the config is: the pair stacks on a phone' );
ok( 1 === preg_match( '#<os-button variant="link" os-action="go" os-arg-sub="agent-tools">[^<]{20,}</os-button>#', $mcp ), 'MCP Clients points at Agent tools for the door lists with a visible in-window link, not a second copy of them' );

// Nothing was dropped in the rearrangement: every section still paints.
foreach ( array( 'Write-door credential', 'Remote door', 'Connect a client', 'Read door: the native MCP server', 'Write door: the same server, read-write', 'Resources &amp; prompts', 'Core MCP adapter: the Abilities-registry door', 'Not the same as Connector Approvals' ) as $heading ) {
	ok( false !== strpos( $kit, $heading ), "still painted after the regroup: $heading" );
}

ok( $GLOBALS['__fold_paint'] >= 10 && array() === array_unique( $GLOBALS['__fold_hits'] ), 'no unearned fold on either leaf in any of the ' . $GLOBALS['__fold_paint'] . ' states this suite paints: ' . implode( ',', array_unique( $GLOBALS['__fold_hits'] ) ) );

// ── Live-shaped preview: two Application Passwords with the write door bound
// to one, the remote bridge ready, the live allowlists (51 read, 18 rw).
$live = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mcp-door-manifest.json' ), true );
$GLOBALS['__read_slugs']    = $live['read'];
$GLOBALS['__rw_slugs']      = $live['write'];
$GLOBALS['__passwords']     = array(
	array( 'uuid' => 'uuid-mac', 'name' => 'MacBook Claude app', 'created' => time() - 40 * 86400, 'last_used' => time() - 2 * 3600 ),
	array( 'uuid' => 'uuid-ci', 'name' => 'CI smoke checks', 'created' => time() - 90 * 86400, 'last_used' => time() - 86400 ),
);
$GLOBALS['__rw_bound_uuid'] = 'uuid-mac';
$mcp = snt_leaf_paint( 'ai', 'mcp-connect' );
ok( array() === ai_unearned_folds( $mcp ) && false !== strpos( $mcp, '<h4 class="snt-h">Claude desktop app</h4>' ) && false !== strpos( $mcp, 'MacBook Claude app' ), 'live-shaped preview: only the earned fold, the desktop-app steps inside it, the bound password named' );
ok( 0 === preg_match( '/<os-disclosure heading="Connect a client"[^>]*\sopen[\s=>]/', $mcp ), 'with Application Passwords present, Connect a client starts closed' );
snt_leaf_preview( 'ai-mcp-connect', $mcp );

// 2026-10-12, from the live preview: usage said twice (tile and section, and
// the two rounded the same hour differently), the remote section's subtitle
// repeating its tile, and four ready doors painted in the alarm-red accent.
$mcp = \SignalNoise\OpenStationHost\Dashboard\Leaves\paint_ai_mcp_connect( array() );
preg_match_all( '/<os-stat\b[^>]*\bcaption="([^"]*)"/', $mcp, $caps );
ok( 4 === count( $caps[1] ) && array() === preg_grep( '/\b(?:used|calls?)\b/i', $caps[1] ), 'no door tile carries a usage line: usage lives once, in the section that controls the door' );
ok( 1 === preg_match( '/<os-section\b[^>]*heading="Remote door"[^>]*description="([^"]*)"/', $mcp, $rd ) && 1 === preg_match( '/<os-stat\b[^>]*value="([^"]*)"[^>]*label="Remote door"/', $mcp, $rt ) && $rt[1] !== $rd[1] && '' !== $rd[1], 'the Remote door section says what its switch decides, not the state its tile already shows' );
$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/os-app.css' );
ok( 1 === preg_match( '/\.snt-app os-stat \{\s*--os-ui-stat-value-color: var\( --os-ui-fg \);/', $css ), 'every S&N tile takes the foreground color, not the admin accent (red here: "0 findings" read as an alarm)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
