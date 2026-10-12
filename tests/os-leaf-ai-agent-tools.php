<?php
/**
 * AI › Agent tools (15.6.0): every tool an agent can call, by door, and
 * whether it does. Both painters from one model: the bridge's five tools
 * with calls and outcomes (reported by browsers), the documents they read
 * with a verdict each, the MCP doors with their call log, Copilot's ranked
 * tools (the retired Copilot Usage leaf, whose assertions live on here).
 *
 * Run: php tests/os-leaf-ai-agent-tools.php
 *
 * @since 15.6.0
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';
if ( ! function_exists( 'snt_os_compat_add_action' ) ) { function snt_os_compat_add_action( $a, $b, $cb ) { add_action( $a, $cb ); add_action( $b, $cb ); } }
$GLOBALS['__mr'] = array( 'ok' => true, 'rows' => array(), 'webmcp' => array( 'calls' => 0, 'by_tool' => array(), 'outcomes' => array() ) );
function snt_mr_fetch( $days, $view = 'aggregate' ) { $GLOBALS['__mr_days'] = $days; return $GLOBALS['__mr']; }
$GLOBALS['__posts'] = array();
function get_posts( $a ) { return $GLOBALS['__posts']; }
function sn_mcp_telemetry_usage() { return $GLOBALS['__usage'] ?? null; }
function sn_mcp_telemetry_table_exists() { return ! empty( $GLOBALS['__usage'] ); }
function snt_ml_related_for_post( $id, $limit ) { return $GLOBALS['__related'] ?? null; }
function wp_get_post_tags( $id, $a = array() ) { return array(); }
function get_the_title( $id ) { return 'Note ' . $id; }
function get_permalink( $id ) { return 'https://x.test/notes/' . $id . '/'; }
const SN_RIGHTS_ANCHOR_STATE_OPT = 'snt_rights_anchor_drift';
const SNT_ML_REBUILD_HOOK = 'snt_ml_rebuild'; const SNT_ML_REBUILD_ASYNC_HOOK = 'snt_ml_rebuild_async'; const SN_SITE_MAP_TEST = true;
require SNT_PATH . 'inc/admin-glance.php';
require SNT_PATH . 'inc/admin-forms/mcp-connect.php';
require SNT_PATH . 'inc/admin-forms/mcp-usage-block.php';
require SNT_PATH . 'inc/ai-tool-invocation-log.php';
require SNT_PATH . 'inc/ml-related-manifest.php';
require SNT_PATH . 'inc/site-map-json.php';
require SNT_PATH . 'inc/agent-tools.php';
require SNT_PATH . 'inc/agent-tools-admin.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-mcp-connect.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-copilot-usage.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-agent-tools.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function rows_of( $kit ) { preg_match( '/os-prop-data="([^"]+)"/', $kit, $m ); return json_decode( html_entity_decode( $m[1] ?? '[]', ENT_QUOTES, 'UTF-8' ), true ); }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['ai/agent-tools'] ), 'the painter is registered under ai/agent-tools' );
ok( ! isset( \SignalNoise\OpenStationHost\Dashboard\painters()['ai/copilot-usage'] ), 'the Copilot Usage painter is retired (folded in here)' );

// ── Nothing yet: no calls, no site map, no note, no Copilot calls.
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array();
$classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
$kit     = snt_leaf_paint( 'ai', 'agent-tools' );
ok( '' !== $kit && '' !== $classic, 'both leaves paint' );
ok( array() === snt_leaf_names( $kit ) && array() === snt_leaf_actions( $kit ) && array() === snt_leaf_classic_markers( $kit ), 'read-only: no field, no action, no wp-admin markup' );
ok( 30 === $GLOBALS['__mr_days'], 'reads the sensor over 30 days through the shared fetch' );
$rows = rows_of( $kit );
ok( array( 'verify-page', 'get-rights-terms', 'related-notes', 'get-site-map', 'get-citation' ) === array_column( $rows, 'tool' ), 'the five registered tools have rows in registration order, with zero calls' );
ok( '0' === $rows[0]['calls'] && '0' === $rows[0]['ok'], 'zero calls read as zero, not as missing rows' );
ok( false !== strpos( $kit, 'Reported by browsers' ) && false !== strpos( $classic, 'Reported by browsers' ), 'the caption says what the figure is, on both leaves' );
ok( false !== strpos( $kit, 'not built yet' ) && false !== strpos( $kit, 'snt-dot--err' ) && false !== strpos( $classic, '<strong>missing</strong>' ), 'no site map: the document reads not built with a red dot; classic says missing' );
ok( false !== strpos( $kit, 'no published note to carry one' ), 'no note: the related manifest says so' );
ok( false !== strpos( $kit, 'served bytes match the anchored record' ), 'no drift remembered: the bridge reads anchored' );
// 2026-10-11 (owner rule, no collapsible sections): the four doors were one
// fold inside Through MCP, each tool list a fold inside that. They are open
// sections of the leaf now, after the call log, never nested in it; the read
// and write doors stand alone (their lists are tall), the adapter and the
// resources stay paired.
ok( array() === snt_leaf_folds( $kit ), 'nothing yet: the leaf paints no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
$mcp_open = (int) strpos( $kit, '<os-section heading="Through MCP"' );
$mcp_box  = substr( $kit, $mcp_open, (int) strpos( $kit, '</os-section>', $mcp_open ) - $mcp_open );
ok( false !== strpos( $mcp_box, 'The call log table is not installed yet' ) && false === strpos( $mcp_box, 'Door 1' ) && false !== strpos( $kit, '<os-section heading="Door 1: the native MCP server"' ) && strpos( $kit, 'heading="Door 1: the native MCP server"' ) > $mcp_open && 2 === substr_count( $kit, snt_leaf_row() ) && strpos( $kit, snt_leaf_row() ) < strpos( $kit, 'heading="Door 1: the native MCP server"' ) && strpos( $kit, 'heading="Door 1b: the native write door"' ) < strpos( $kit, snt_leaf_row(), strpos( $kit, snt_leaf_row() ) + 1 ), 'Through MCP: the call log open in the section, the doors open sections after it (not nested), two pairs: the read door beside the write door (494px and 709px live), then the adapter beside the resources' );
ok( 1 === preg_match( '#<os-button variant="link" os-action="go" os-arg-sub="mcp-connect">Connect a client: MCP Clients</os-button>#', $mcp_box ), 'Through MCP ends on a visible in-window link to MCP Clients, where a client is set up' );
ok( false !== strpos( $kit, 'The read-door allowlist is not readable here' ) && false !== strpos( $kit, 'The write-door allowlist is not readable here' ) && false === strpos( $kit, '0 read-only tools' ), 'no allowlist reader loaded: each door says its list is not readable, never "0 tools"' );
ok( false === strpos( $kit, 'heading="Tool usage"' ), 'the call log is not a second section or a fold of its own here' );
ok( false !== strpos( $kit, 'heading="Through Copilot"' ) && false !== strpos( $kit, 'No Ask AI tool calls recorded yet' ), 'Through Copilot: the retired leaf\'s empty state' );
ok( strpos( $kit, 'heading="On the page, 30 days"' ) < strpos( $kit, 'heading="Through MCP"' ) && strpos( $kit, 'heading="Through MCP"' ) < strpos( $kit, 'heading="Through Copilot"' ), 'doors in order: the page, MCP, Copilot' );

// ── Rich: calls with outcomes, an unregistered tool seen, a site map, a note with related, a drift, Copilot calls.
$GLOBALS['__mr']['webmcp'] = array( 'calls' => 17, 'by_tool' => array( 'related-notes' => 9, 'verify-page' => 6, 'search-notes' => 2 ), 'outcomes' => array( 'related-notes' => array( 'ok' => 7, 'absent' => 2 ), 'verify-page' => array( 'ok' => 5, 'error' => 1 ), 'search-notes' => array( 'ok' => 2 ) ) );
$GLOBALS['__options']['sn_site_map'] = array( 'built_at' => gmdate( 'c', time() - 2 * 3600 ), 'counts' => array( 'notes' => 55, 'pages' => 12 ) );
$GLOBALS['__posts'] = array( 41 );
$GLOBALS['__related'] = array( array( 'post_id' => 40, 'score' => 0.5 ), array( 'post_id' => 39, 'score' => 0.4 ) );
$GLOBALS['__options']['snt_rights_anchor_drift'] = array( 'webmcp-bridge' => array( 'hash' => 'x', 'first_seen' => time() - 3 * 3600 ) );
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array( 'search_posts' => array( 'n' => 12, 'first' => 50, 'last' => 300 ), 'export_audit_log' => array( 'n' => 7, 'first' => 100, 'last' => 200 ) );
$classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
$kit     = snt_leaf_paint( 'ai', 'agent-tools' );
$rows    = rows_of( $kit );
$by      = array_column( $rows, null, 'tool' );
ok( array() === snt_leaf_folds( $kit ), 'rich: the leaf paints no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
ok( '9' === $by['related-notes']['calls'] && '7' === $by['related-notes']['ok'] && '2' === $by['related-notes']['absent'] && '0' === $by['related-notes']['error'], 'related-notes: 9 calls, 7 ok, 2 absent, 0 error' );
ok( '6' === $by['verify-page']['calls'] && '1' === $by['verify-page']['error'], 'verify-page: 6 calls, 1 error' );
ok( isset( $by['search-notes *'] ) && '2' === $by['search-notes *']['calls'] && false !== strpos( $by['search-notes *']['what'], 'Not in the registered list' ), 'a tool seen in the rows but not registered is painted, starred, and says so' );
ok( 6 === count( $rows ) && '0' === $by['get-citation']['calls'], 'six rows: the five registered plus the seen one; the quiet ones stay at zero' );
ok( false !== strpos( $kit, '55 notes, 12 pages, built 1 hour ago' /* the harness stubs human_time_diff */ ) && false !== strpos( $kit, 'on the latest note, 2 related' ), 'documents: the site map with its counts and age; the manifest on the latest note' );
ok( false !== strpos( $kit, 'have not matched their anchor for 1 hour' ), 'a remembered drift on the bridge reads as a drift with its age' );
ok( false !== strpos( $classic, '<td class="sn-mono">related-notes</td><td>The notes the kernel ranks closest to this one</td><td>9</td><td>7</td><td>2</td><td>0</td>' ), 'the classic table paints the same row' );
ok( false !== strpos( $kit, '<strong>19</strong> calls across <strong>2</strong> tools' ) && false !== strpos( $classic, '<strong>19</strong> calls across <strong>2</strong> tools' ), 'Through Copilot: the ranked summary (19 calls across 2 tools) on both leaves' );
ok( false !== strpos( $kit, 'search_posts' ) && strpos( $kit, 'search_posts' ) < strpos( $kit, 'export_audit_log' ), 'Copilot tools ranked by count' );

// ── Live-shaped preview: the live allowlists (51 read, 18 rw, the pinned door
// manifest) with each ability's real category, and a call log of ~400 door
// calls over 25 tools across 71 measured days of the 90-day window, so both
// ledgers cap. The readers are declared HERE, at run time, so every state
// above painted without them (the not-readable paths).
$live = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mcp-door-manifest.json' ), true );
if ( ! function_exists( 'sn_mcp_allowlist' ) ) {
	function sn_mcp_allowlist() { return $GLOBALS['__live']['read']; }
	function sn_mcp_rw_allowlist() { return $GLOBALS['__live']['write']; }
	function sn_mcp_get_ability( $slug ) {
		$short = substr( $slug, strlen( 'signal-noise/' ) );
		$cat   = in_array( $short, array( 'draft-echoes', 'keyword-candidates', 'topic-clusters', 'sn-posts', 'sn-scan', 'sn-validate', 'sn-apply' ), true ) ? 'tools'
			: ( in_array( $short, array( 'get-analytics-summary', 'login-defense-ipv6-criterion', 'get-machine-readers-crosstab', 'get-rights-reads', 'sn-metrics' ), true ) ? 'analytics'
			: ( in_array( $short, array( 'ai-link-apply', 'ai-pair-suggest' ), true ) ? 'ai-generation'
			: ( in_array( $short, array( 'prune-unused-tags', 'describe-tags', 'apply-tag-description', 'forms-spam-apply' ), true ) ? 'content'
			: ( in_array( $slug, $GLOBALS['__live']['write'], true ) ? 'maintenance' : 'diagnostics' ) ) ) );
		return new class( $cat ) { private $c; public function __construct( $c ) { $this->c = $c; } public function get_category() { return $this->c; } };
	}
	function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
	function sn_mcp_namespace() { return 'signal-noise/v1'; }
	function wp_has_ability_category( $slug ) { return true; }
	function wp_get_ability_category( $slug ) {
		$labels = array( 'diagnostics' => 'Diagnostics', 'tools' => 'Tools', 'analytics' => 'Analytics', 'maintenance' => 'Maintenance', 'content' => 'Content', 'ai-generation' => 'AI Generation' );
		return new class( $labels[ $slug ] ?? $slug ) { private $l; public function __construct( $l ) { $this->l = $l; } public function get_label() { return $this->l; } };
	}
}
$GLOBALS['__live'] = $live;
$called = array_merge( array( 'signal-noise/sn-status', 'signal-noise/sn-posts', 'signal-noise/get-analytics-summary', 'signal-noise/search-performance', 'signal-noise/get-health-scan' ), array_slice( array_diff( $live['read'], array( 'signal-noise/sn-status', 'signal-noise/sn-posts', 'signal-noise/get-analytics-summary', 'signal-noise/search-performance', 'signal-noise/get-health-scan', 'signal-noise/get-deploy-status' ) ), 0, 15 ), array_slice( $live['write'], 0, 5 ) );
$calls  = array( 96, 61, 44, 33, 27, 22, 18, 15, 13, 11, 9, 8, 7, 6, 5, 4, 4, 3, 3, 2, 2, 1, 1, 1, 1 );
$by_tool = array();
foreach ( $called as $i => $slug ) {
	$rw = in_array( $slug, $live['write'], true );
	$by_tool[ 'signal-noise__' . substr( $slug, 13 ) ] = array( 'calls' => $calls[ $i ] + ( 0 === $i ? 4100 : 0 ), 'door_calls' => $calls[ $i ], 'direct_calls' => 0 === $i ? 4100 : 0, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - $i * 9000 ), 'doors' => $rw ? array( 'rw' ) : ( 0 === $i ? array( 'read', 'agent', 'direct' ) : array( 'read' ) ) );
}
$zero = array();
foreach ( array_values( array_diff( array_merge( $live['read'], $live['write'] ), $called ) ) as $i => $slug ) {
	$verdict = 'signal-noise/get-deploy-status' === $slug ? 'first_party_only' : ( 3 === $i ? 'unreachable' : ( 7 === $i ? 'undetermined' : 'unused' ) );
	$zero[]  = array( 'slug' => $slug, 'verdict' => $verdict, 'calls' => 'first_party_only' === $verdict ? 28689 : 0 );
}
$GLOBALS['__usage'] = array( 'measured_since' => '2026-08-01 00:04:12', 'measured_days' => 71, 'window_days' => 90, 'complete' => false, 'total_rows' => 72579, 'by_door' => array( 'direct' => 72182, 'read' => 300, 'rw' => 60, 'agent' => 37 ), 'by_tool' => $by_tool, 'zero_call' => $zero );
$GLOBALS['__mr']['webmcp'] = array( 'calls' => 212, 'by_tool' => array( 'related-notes' => 118, 'verify-page' => 61, 'get-citation' => 21, 'get-site-map' => 12 ), 'outcomes' => array( 'related-notes' => array( 'ok' => 109, 'absent' => 9 ), 'verify-page' => array( 'ok' => 60, 'error' => 1 ), 'get-citation' => array( 'ok' => 21 ), 'get-site-map' => array( 'ok' => 12 ) ) );
$kit = snt_leaf_paint( 'ai', 'agent-tools' );
ok( array() === snt_leaf_folds( $kit ), 'live-shaped preview: the leaf paints no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
// Review on #2005 (P2): every figure the classic block lists stays painted:
// all 25 called tools with their calls, and every uncalled slug by verdict.
ok( false !== strpos( $kit, '397 calls came through a door since 2026-08-01; sn-status took 24% of them.' ) && false === strpos( $kit, 'more tools, busiest first' ), 'live-shaped preview: 397 door calls over 25 tools, every tool listed (the classic lists every one)' );
$__zero_at = strpos( $kit, 'No calls in this window' );
$__zero    = false === $__zero_at ? '' : substr( $kit, $__zero_at, strpos( $kit, '</os-section>', $__zero_at ) - $__zero_at ); // the section only, not the door bands after it
ok( false !== strpos( $kit, '44 allowlisted tools had no call through a door: 1 cannot be projected; 1 with reachability unknown; 41 retirement candidates; 1 called only by the plugin itself.' ) && false === strpos( $kit, 'more tools with no door calls' ), 'live-shaped preview: the 44 uncalled tools split by verdict, none cut' );
ok( 44 === count( array_filter( $zero, static fn( $e ) => false !== strpos( $__zero, (string) substr( $e['slug'], 13 ) ) ) ), 'live-shaped preview: every one of the 44 uncalled slugs is painted in its section' );
ok( false !== strpos( $__zero, 'label="Retirement candidates (41)"' ) && false !== strpos( $__zero, '28,689' ), 'live-shaped preview: the retirement candidates are one band of chips, and the plugin-only tool keeps its 28,689 calls' );
ok( false !== strpos( $kit, '51 read-only tools across 3 categories; Diagnostics holds 40 of them.' ) && false !== strpos( $kit, '18 read-write tools across 4 categories; Maintenance holds 11 of them.' ), 'live-shaped preview: each door leads with its count and its largest category, built from the abilities' );
ok( 69 === substr_count( $kit, '<os-chip>' ) - substr_count( $__zero, '<os-chip>' ) && false !== strpos( $kit, '<os-fact label="Diagnostics (40)">' ), 'live-shaped preview: all 69 tools are chips in their door bands, by category' );
snt_leaf_preview( 'ai-agent-tools', $kit );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
