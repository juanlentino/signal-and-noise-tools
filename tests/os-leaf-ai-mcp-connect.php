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
// mcp-usage-block.php and the port's mcp_connect_usage_html() guard behind
// function_exists(), so without these stubs neither side paints it at all —
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
function ai_leaf_both() { return snt_leaf_paint( 'ai', 'mcp-connect' ) . snt_leaf_paint( 'ai', 'agent-tools' ); }
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
ok( false !== strpos( $kit, '3 read-only tools exposed' ), 'the read-door disclosure carries the live count (3)' );
ok( false !== strpos( $kit, '2 read-write tools exposed' ) && false !== strpos( $kit, '4 withheld' ), 'the write-door disclosure carries the live rw count (2) and the withheld count (4)' );
ok( false !== strpos( $kit, 'signal-noise/run-cron-event' ) && false !== strpos( $kit, 'signal-noise/ai-orphan-apply' ), 'the withheld slugs (from the classic data function) are listed' );
ok( false !== strpos( $kit, 'MacBook' ), 'the bound Application Password name (MacBook) is shown' );
ok( false !== strpos( $kit, 'Bound to' ), 'the RW-binding status reads Bound to …' );
ok( false !== strpos( $kit, 'Bridge ready' ), 'the remote-door card reads Bridge ready' );
ok( false !== strpos( $kit, 'Not installed' ), 'the adapter door reads Not installed (no adapter class declared)' );
ok( false !== strpos( $kit, '<os-select' ) && false !== strpos( $kit, 'name="sn_mcp_rw_uuid"' ), 'the credential picker is a kit select carrying the bound uuid selection' );
ok( false !== strpos( $kit, '<os-checkbox-label' ) && false !== strpos( $kit, 'name="sn_remote_enabled"' ), 'the remote toggle is a kit checkbox' );
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
// mcp_connect_usage_html() — without the stubs above every one of these was
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
ok( false !== strpos( $kit, '20 days of a 30-day window' ) && false !== strpos( $kit, '3 tools with no calls through a door' ) && false !== strpos( $classic, '3 tools with no calls through a door' ), 'usage state: the summary counts tools with no calls THROUGH A DOOR, on both surfaces' );
ok( false !== strpos( $kit, 'sn-status' ) && false !== strpos( $kit, '42' ) && false !== strpos( $kit, 'sn-apply' ) && false !== strpos( $kit, '1 (30,000 direct)' ) && false !== strpos( $classic, '1 (30,000 direct)' ), 'usage state: the Calls cell is door calls with the direct count in parentheses, on both surfaces' );
$door_split = '75,874 calls: 72,182 first-party (direct), 1,446 read door, 1,042 rw, 1,204 agent';
ok( false !== strpos( $kit, $door_split ) && false !== strpos( $classic, $door_split ), 'usage state: the door-split sentence paints with the same words on both surfaces' );
$first_party = "the plugin's own surfaces called it 28,689 times";
ok( false !== strpos( html_entity_decode( $kit, ENT_QUOTES, 'UTF-8' ), $first_party ) && false !== strpos( html_entity_decode( $classic, ENT_QUOTES, 'UTF-8' ), $first_party ) && false !== strpos( $kit, 'only for the door allowlist' ), 'usage state: a first-party-only tool is labelled with its direct count and as an allowlist (not code) candidate, on both surfaces' );
ok( false !== strpos( $kit, 'No calls in this window' ), 'usage state: the zero-call list keeps its heading' );
ok( false !== strpos( $kit, 'predate the sensor' ), 'usage state: the partial-window caveat keeps its interpretive sentence' );
ok( false !== strpos( $kit, 'retirement candidate' ) && false !== strpos( $kit, 'evidence for removal' ), 'usage state: the zero-call trailing paragraph survives' );

// ── Faithfulness oracle beyond names/actions/markers: every classic sentence
// of meaningful length must survive into the kit render (as text or in an
// attribute), normalized so tag/entity noise doesn't matter. This is the
// assertion that would have caught every prose-reduction finding above —
// mutation-proven: deleting mcp_connect_resources_prompts_html(),
// mcp_connect_deep_links_html() or mcp_connect_door_adapter_html()'s body
// (replacing the call with `$out .= '';` in ai-mcp-connect.php) reds this
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
	preg_match_all( '/<[a-z-]+[^>]*\b(?:heading|hint|description|label)="([^"]+)"/i', (string) $html, $attr_m );
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
foreach ( ai_mcp_connect_split_sentences( preg_replace( '#<table.*?</table>#s', '', $classic ) ) as $sentence ) {
	$sentence = trim( (string) $sentence );
	if ( strlen( $sentence ) <= 40 ) {
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

ok( 3 === substr_count( $kit, snt_leaf_row() ), 'three paired rows are painted across the two leaves (two door pairs on Agent tools, the footer pair on MCP Clients) -- ' . substr_count( $kit, snt_leaf_row() ) );

// Each pair, by the two headings it must hold, in order.
$pairs = array(
	array( 'Door 1: the native MCP server', 'Door 1b: the native write door' ),
	array( 'Door 2: the Abilities-registry adapter', 'Resources &amp; prompts' ),
);
foreach ( $pairs as $i => $pair ) {
	// 15.6.0: MCP Clients paints first (its footer pair is row 0); the two door
	// pairs follow on Agent tools.
	if ( preg_match_all( '/' . preg_quote( snt_leaf_row(), '/' ) . '(.*?)<\/os-grid>/s', $kit, $m ) && isset( $m[1][ $i + 1 ] ) ) {
		$row = $m[1][ $i + 1 ];
		ok(
			false !== strpos( $row, $pair[0] ) && false !== strpos( $row, $pair[1] ),
			'row ' . ( $i + 1 ) . ' pairs ' . $pair[0] . ' with ' . $pair[1]
		);
	} else {
		ok( false, 'row ' . ( $i + 1 ) . ' exists' );
	}
}

// The task path keeps the width: it carries a JSON config block and a CLI
// command, and wrapping either is worse than the space costs.
ok(
	false === strpos( preg_replace( '/' . preg_quote( snt_leaf_row(), '/' ) . '.*?<\/os-grid>/s', '', $kit ), snt_leaf_row() )
	&& false !== strpos( $kit, 'Connect a client' ),
	'Connect a client is NOT paired -- the code block keeps the full width'
);

// 17.4.4 (#1602): the two setup sequences are the kit's <os-steps>, not a
// browser <ol>. Three owner steps in Connect a client, four inside the Claude
// desktop app fold; the fold's hint still counts them.
$mcp = snt_leaf_paint( 'ai', 'mcp-connect' );
ok( 2 === substr_count( $mcp, '<os-steps>' ) && 7 === substr_count( $mcp, '<os-step>' ), 'two <os-steps> and seven <os-step> paint the Connect a client and Claude desktop app sequences -- ' . substr_count( $mcp, '<os-steps>' ) . '/' . substr_count( $mcp, '<os-step>' ) );
ok( false === strpos( $mcp, '<ol' ), 'no browser ordered list survives on MCP Clients' );
$fold      = strpos( $mcp, 'hint="Show the 4 setup steps"' );
$fold_end  = false !== $fold ? strpos( $mcp, '</os-disclosure>', $fold ) : false;
$fold_html = false !== $fold_end ? substr( $mcp, $fold, $fold_end - $fold ) : '';
ok( false !== $fold && 4 === substr_count( $fold_html, '<os-step>' ) && false !== strpos( $fold_html, 'Install Node.js' ), 'the Claude desktop app fold holds its four steps as <os-step> inside its </os-disclosure>, starting with Install Node.js -- ' . substr_count( $fold_html, '<os-step>' ) );
ok( false !== strpos( $mcp, '<os-step>Create an <os-button variant="link" os-action="door" os-arg-url="https://example.test/wp-admin/profile.php#application-passwords-section"' ), 'the first owner step keeps the Application Password door inside the step body' );

// Nothing was dropped in the rearrangement: every section still paints.
foreach ( array( 'Status at a glance', 'Bind the write-door credential', 'Connect a client', 'Door 1: the native MCP server', 'Door 1b: the native write door', 'Resources &amp; prompts', 'Door 2: the Abilities-registry adapter', 'More' ) as $heading ) {
	ok( false !== strpos( $kit, $heading ), "still painted after the regroup: $heading" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
