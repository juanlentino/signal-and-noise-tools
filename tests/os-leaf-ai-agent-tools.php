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
const SN_RIGHTS_ANCHOR_STATE_OPT = 'snt_rights_anchor_drift'; const SN_RIGHTS_ANCHOR_CHECKED_OPT = 'snt_rights_anchor_checked'; const SN_RIGHTS_ANCHOR_GRACE = 7200;
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
// 2026-10-12 audit: "no drift entry" read as a match on any install, though the
// entry is written only when the daily check runs and compares the bridge.
ok( false !== strpos( $kit, 'not checked yet' ) && false === strpos( $kit, 'served bytes match' ) && false !== strpos( $kit, 'snt-dot--unknown' ) && false !== strpos( $classic, '<strong>not checked</strong>' ), 'never checked: the bridge reads "not checked yet" with a neutral dot, never a green match; classic says not checked' );
// 2026-10-11 (owner rule, no collapsible sections): the four doors were one
// fold inside Through MCP, each tool list a fold inside that. They are open
// sections of the leaf now, after the call log, never nested in it; the read
// and write doors stand alone (their lists are tall), the adapter and the
// resources stay paired.
ok( array() === snt_leaf_folds( $kit ), 'nothing yet: the leaf paints no fold: ' . implode( ',', snt_leaf_folds( $kit ) ) );
$mcp_open = (int) strpos( $kit, '<os-section heading="Through MCP"' );
$mcp_box  = substr( $kit, $mcp_open, (int) strpos( $kit, '</os-section>', $mcp_open ) - $mcp_open );
// 2026-10-12 audit: the three doors stack (each a fold once its list is
// readable: a summary line closed, the full width for its chips open); the
// adapter and the resources stay paired.
ok( false !== strpos( $mcp_box, 'The call log table is not installed yet' ) && false === strpos( $mcp_box, 'Door 1' ) && false !== strpos( $kit, '<os-section heading="Read door: the native MCP server"' ) && strpos( $kit, 'heading="Read door: the native MCP server"' ) > $mcp_open && 1 === substr_count( $kit, snt_leaf_row() ) && strpos( $kit, 'heading="Remote door: through the Cloudflare Worker"' ) < strpos( $kit, snt_leaf_row() ), 'Through MCP: the call log open in the section, the read, write and remote doors after it (not nested), then the adapter beside the resources' );
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
$GLOBALS['__options']['snt_rights_anchor_checked'] = array( 'at' => time() - 600, 'compared' => array( 'robots-txt', 'webmcp-bridge' ), 'grace' => 7200 );
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
ok( false !== strpos( $kit, 'had not matched their anchor for 1 hour at the last check' ) && false !== strpos( $kit, 'checked 1 hour ago' ), 'a drift past the grace window at the last check reads as a drift with its age then, and says when it was checked' );
ok( false !== strpos( $classic, '<td class="sn-mono">related-notes</td><td>The notes the kernel ranks closest to this one</td><td>9</td><td>7</td><td>2</td><td>0</td>' ), 'the classic table paints the same row' );
ok( false !== strpos( $kit, '<strong>19</strong> calls across <strong>2</strong> tools since January 1, 1970' ) && false !== strpos( $classic, '<strong>19</strong> calls across <strong>2</strong> tools since January 1, 1970' ), 'Through Copilot: the ranked summary (19 calls across 2 tools) says since when the all-time counts run, on both leaves' );
ok( false === strpos( $kit . $classic, 'v9.60.0' ), 'Through Copilot: no version stamp on either leaf' );

// 2026-10-12 audit: the bridge's anchoring has three answers besides a drift.
$GLOBALS['__options']['snt_rights_anchor_drift'] = array();
$__bridge = static fn() => array_values( array_filter( sn_agent_tools_documents(), static fn( $d ) => 'The bridge' === $d['label'] ) )[0];
$b = $__bridge();
ok( true === $b['ok'] && false !== strpos( $b['value'], 'served bytes match the anchored record (checked 1 hour ago)' ), 'checked and compared, no drift: a match, with when it was checked' );
$GLOBALS['__options']['snt_rights_anchor_checked']['compared'] = array( 'robots-txt' );
$b = $__bridge();
ok( null === $b['ok'] && false !== strpos( $b['value'], 'not compared at the last check' ), 'a check that could not fetch the bridge is not a match: neither green nor red' );
$GLOBALS['__options']['snt_rights_anchor_checked']['compared'] = array( 'webmcp-bridge' );
$GLOBALS['__options']['snt_rights_anchor_drift'] = array( 'webmcp-bridge' => array( 'hash' => 'x', 'first_seen' => time() - 600 ) );
$b = $__bridge();
ok( true === $b['ok'] && false !== strpos( $b['value'], 'served bytes had just changed at the last check' ), 'a drift inside the grace window is not red: the health check calls it fine too' );
// Review on #2022: judged against the page view, a change first seen AT the
// daily scan (first_seen = at) turned red two hours later and stayed red all
// day while Health said fine. Judged as of the check, it stays green.
$GLOBALS['__options']['snt_rights_anchor_checked']['at'] = time() - 3 * 3600;
$GLOBALS['__options']['snt_rights_anchor_drift'] = array( 'webmcp-bridge' => array( 'hash' => 'x', 'first_seen' => time() - 3 * 3600 ) );
$b = $__bridge();
ok( true === $b['ok'] && 'ok' === $b['flag'], 'a change first seen at the last check, three hours ago, is not red: the check judged it inside the window' );
$GLOBALS['__options']['snt_rights_anchor_drift'] = array( 'webmcp-bridge' => array( 'hash' => 'x', 'first_seen' => time() - 6 * 3600 ) );
$b = $__bridge();
ok( false === $b['ok'] && 'drifted' === $b['flag'] && false !== strpos( $b['value'], 'at the last check' ), 'a change seen three hours before that check is a drift, said as of the check' );
// Review nit on #2022: the window is filterable (sn_health_rights_anchor_grace)
// and stored with the check; the verdict and the words come from that value.
$GLOBALS['__options']['snt_rights_anchor_checked']['grace'] = 4 * 3600;
$b = $__bridge();
ok( true === $b['ok'] && false !== strpos( $b['value'], 'inside the check\'s grace window of 1 hour' /* the harness stubs human_time_diff */ ) && false === strpos( $b['value'], 'two-hour' ), 'a four-hour grace stored with the check keeps a three-hour change green, and the sentence names the stored window, never a fixed "two-hour"' );
$GLOBALS['__options']['snt_rights_anchor_checked']['grace'] = 7200;
$GLOBALS['__options']['snt_rights_anchor_checked']['at'] = time() - 600;
$GLOBALS['__options']['snt_rights_anchor_drift'] = array( 'webmcp-bridge' => array( 'hash' => 'x', 'first_seen' => time() - 3 * 3600 ) );

// 2026-10-12 audit: the Copilot list was uncapped and kept every retired tool.
$__copilot = array();
for ( $i = 1; $i <= 13; $i++ ) { $__copilot[ 'tool_' . $i ] = array( 'n' => 100 - $i, 'first' => 86400 * 40 + $i, 'last' => 300 ); }
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = $__copilot;
$cop_kit     = snt_leaf_paint( 'ai', 'agent-tools' );
$cop_classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
$cop_at      = (int) strpos( $cop_kit, 'heading="Through Copilot"' );
ok( 10 === substr_count( substr( $cop_kit, $cop_at ), '<os-chip' ) && false !== strpos( $cop_kit, 'tool_1 · 99' ) && false === strpos( $cop_kit, 'tool_11 ·' ) && false !== strpos( $cop_kit, '+3 more tools, busiest first.' ), 'Through Copilot: the ten busiest as chips with their counts, the rest counted out loud' );
ok( 10 === substr_count( $cop_classic, 'sn-ai-usage__row' ) && false !== strpos( $cop_classic, '+3 more tools, busiest first.' ) && false !== strpos( $cop_classic, 'since February 10, 1970' ), 'Through Copilot, classic: the same cap, the same line, and the since date from the earliest first call' );
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array( 'search_posts' => array( 'n' => 12, 'first' => 50, 'last' => 300 ), 'export_audit_log' => array( 'n' => 7, 'first' => 100, 'last' => 200 ) );
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
// The remote door (2026-10-12 audit): listed nowhere until then, its calls
// filed as the plugin's own polling. Two remote twins called, one retired
// tool that still carries door calls, and a raw SQL time on every row.
if ( ! function_exists( 'sn_mcp_remote_slugs' ) ) { function sn_mcp_remote_slugs() { return $GLOBALS['__live']['remote']; } } // conditional, so it is declared here, not hoisted over the not-readable states
foreach ( array_slice( $live['remote'], 0, 2 ) as $i => $slug ) {
	$by_tool[ 'signal-noise__' . substr( $slug, 13 ) ] = array( 'calls' => 40 - $i * 10, 'door_calls' => 40 - $i * 10, 'direct_calls' => 0, 'last_seen' => '2026-10-12 02:38:43.798', 'doors' => array( 'remote' ), 'allowlisted' => true );
}
$by_tool['signal-noise__cadence-flags'] = array( 'calls' => 9, 'door_calls' => 9, 'direct_calls' => 0, 'last_seen' => '2026-10-01 10:00:00', 'doors' => array( 'read' ), 'allowlisted' => false );
$by_tool['signal-noise__content-queue'] = array( 'calls' => 12553, 'door_calls' => 0, 'direct_calls' => 12553, 'last_seen' => '2026-10-12 01:00:00', 'doors' => array( 'direct' ), 'allowlisted' => false );
$GLOBALS['__usage'] = array( 'measured_since' => '2026-08-01 00:04:12', 'recording_began' => '2026-08-01 00:04:12', 'since' => '2026-08-01 00:04:12', 'measured_days' => 71, 'window_days' => 90, 'complete' => false, 'total_rows' => 72649, 'by_door' => array( 'direct' => 72182, 'read' => 300, 'rw' => 60, 'remote' => 70, 'agent' => 37 ), 'by_tool' => $by_tool, 'zero_call' => $zero );
$GLOBALS['__mr']['webmcp'] = array( 'calls' => 212, 'by_tool' => array( 'related-notes' => 118, 'verify-page' => 61, 'get-citation' => 21, 'get-site-map' => 12 ), 'outcomes' => array( 'related-notes' => array( 'ok' => 109, 'absent' => 9 ), 'verify-page' => array( 'ok' => 60, 'error' => 1 ), 'get-citation' => array( 'ok' => 21 ), 'get-site-map' => array( 'ok' => 12 ) ) );
$kit     = snt_leaf_paint( 'ai', 'agent-tools' );
$classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
// 2026-10-12 audit (2,943px live): one inventory per door, each an earned
// fold whose closed summary is its finding and its calls; the door holding a
// tool that cannot be projected opens itself.
$__fold = static function ( $heading ) use ( $kit ) { return preg_match( '/<os-disclosure heading="' . preg_quote( $heading, '/' ) . '" hint="([^"]*)"([^>]*)>/', $kit, $m ) ? array( 'hint' => html_entity_decode( $m[1], ENT_QUOTES ), 'open' => 1 === preg_match( '/\sopen[\s=>]/', $m[2] . '>' ) ) : null; };
$__read   = $__fold( 'Read door: the native MCP server' );
$__write  = $__fold( 'Write door: the same server, read-write' );
$__remote = $__fold( 'Remote door: through the Cloudflare Worker' );
preg_match_all( '/<os-disclosure heading="([^"]+)"/', $kit, $__folds );
ok( array( 'Read door: the native MCP server', 'Write door: the same server, read-write', 'Remote door: through the Cloudflare Worker' ) === $__folds[1] && array( '<os-disclosure' ) === snt_leaf_folds( $kit ), 'live-shaped preview: the three doors are the leaf\'s only folds, all through the kit: ' . implode( ',', $__folds[1] ) );
ok( null !== $__read && 0 === strpos( $__read['hint'], '51 read-only tools across 3 categories; Diagnostics holds 40 of them. In this window 20 called through a door, 31 not. 1 cannot be projected, a bug.' ) && $__read['open'], 'the read door\'s closed summary states its count, its largest category and its calls, and it opens itself: a tool there cannot be projected' );
ok( null !== $__write && false !== strpos( $__write['hint'], '4 abilities withheld from this door.' ), 'the write door\'s closed summary carries the withheld count, as the classic summary does (review on #2022)' );
ok( null !== $__write && false === $__write['open'] && false !== strpos( $__write['hint'], '18 read-write tools across 4 categories' ) && null !== $__remote && false === $__remote['open'] && false !== strpos( $__remote['hint'], '18 read-only tools' ) && false !== strpos( $__remote['hint'], 'In this window 2 called through a door, 16 not.' ), 'the write and remote doors start closed, each summary carrying its count and its calls' );
$__chip = static fn( $short, $text ) => false !== strpos( $kit, '>' . $short . ' · ' . $text . '</os-chip>' );
ok( $__chip( 'sn-status', '96' ) && $__chip( substr( $live['remote'][0], strlen( 'signal-noise/remote-' ) ), '40' ) && $__chip( 'get-deploy-status', 'plugin only, 28,689' ), 'every door tool is a chip carrying its calls through a door: sn-status 96, the busiest remote twin 40 (named without "remote-": the door says it), the plugin-only tool its 28,689 polls' );
// Live check on #2022: remote chips clipped at the kit's 220px and the ellipsis
// ate the count; the remote door already says remote, so its chips drop it.
$__remote_at = (int) strpos( $kit, 'heading="Remote door: through the Cloudflare Worker"' );
$__remote    = substr( $kit, $__remote_at, (int) strpos( $kit, '</os-disclosure>', $__remote_at ) - $__remote_at );
ok( 18 === substr_count( $__remote, '<os-chip' ) && 0 === preg_match( '/<os-chip[^>]*>remote-/', $__remote ) && false !== strpos( $__remote, 'The chips drop the signal-noise/remote- prefix of each ability name.' ), 'the remote door\'s 18 chips drop "remote-", and its hint says so' );
$__doors = substr( $kit, (int) strpos( $kit, 'heading="Read door: the native MCP server"' ), (int) strpos( $kit, snt_leaf_row() ) - (int) strpos( $kit, 'heading="Read door: the native MCP server"' ) );
ok( substr_count( $__doors, '<os-cluster class="snt-door-chips" gap="6">' ) >= 3 && 0 === substr_count( $__doors, '<os-cluster gap="6">' ), 'every door\'s chips sit in the snt-door-chips cluster, whose CSS lifts the kit\'s 220px label cap' );
// Batch 7's positional guard on #2022: the doors name their list and their address, never "below".
$__classic_all = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
ok( false === stripos( $kit . $__classic_all, 'address below' ) && false === stripos( $kit . $__classic_all, 'tool list below' ) && false !== strpos( $kit, 'this door’s tool list is the whole surface' ) && false !== strpos( $__classic_all, 'this door’s tool list is the whole surface' ) && false !== strpos( $kit . $__classic_all, 'at this address:' ), 'no door points below: "this door’s tool list" and "at this address:" on both twins' );
$__css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
ok( 1 === preg_match( '/\.snt-door-chips os-chip \{\s*--os-ui-chip-label-max: none;/', $__css ), 'the stylesheet lifts the chip label cap inside a door, so no count is clipped' );
ok( false !== strpos( $kit, '<ul class="snt-list snt-list--wrap">' ) && 1 === preg_match( '/\.snt-list--wrap \.snt-list__value \{\s*white-space: normal;\s*overflow: visible;/', $__css ), 'What the tools read: its values wrap, so the bridge\'s "(checked 1 hour ago)" is never cut off' );
ok( 1 === preg_match( '/<os-chip tone="danger">[^<]+ · cannot be projected<\/os-chip>/', $kit ) && 41 === preg_match_all( '/<os-chip tone="warning">[^<]+ · no calls<\/os-chip>/', $kit ) - 16 && 1 === preg_match_all( '/ · reachability unknown<\/os-chip>/', $kit ), 'the uncalled tools are marked on their own chips, in words: 1 cannot be projected (danger), 41 retirement candidates and the 16 quiet remote twins with no calls, 1 reachability unknown' );
ok( 69 + 18 === substr_count( $kit, '<os-chip' ) - substr_count( substr( $kit, (int) strpos( $kit, 'heading="Through Copilot"' ) ), '<os-chip' ), 'all 87 door tools are chips: 51 read, 18 write, 18 remote' );
ok( false === strpos( $kit, 'heading="No calls in this window"' ) && false !== strpos( $kit, '44 allowlisted tools had no call through a door: 1 cannot be projected; 1 with reachability unknown; 41 retirement candidates; 1 called only by the plugin itself.' ), 'no second section repeats the uncalled slugs; their count stays one sentence' );
// The ledger: door tools only, ten rows, the doors and times as the screen says them.
$__ledger = array_values( array_filter( snt_leaf_tables( $kit ), static fn( $t ) => in_array( 'Last seen', array_column( $t['columns'], 'label' ), true ) ) )[0] ?? array( 'data' => array() );
$__tools  = array_column( $__ledger['data'], 'tool' );
ok( 10 === count( $__tools ) && 'sn-status' === $__tools[0] && false !== strpos( $kit, '+17 more tools, busiest first; each door&#039;s list carries every tool&#039;s count.' ), 'the ledger is the ten busiest door tools, the other 17 counted out loud (25 read and write, 2 remote)' );
ok( ! in_array( 'cadence-flags', $__tools, true ) && ! in_array( 'content-queue', $__tools, true ) && false !== strpos( html_entity_decode( $kit, ENT_QUOTES ), '9 door calls reached tools no door lists today (retired, or reached only by Desktop Mode agents: cadence-flags), so the ledger leaves them out.' ), 'a retired tool and the plugin\'s own polling stay out of the ledger; the retired one is a sentence' );
ok( in_array( 'October 12, 2026 2:38 am', array_column( $__ledger['data'], 'last_seen' ), true ) && ! in_array( 'not recorded', array_column( $__ledger['data'], 'last_seen' ), true ), 'the log\'s fractional-second UTC time (2026-10-12 02:38:43.798) reads as a date and time, never "not recorded"' );
ok( array() === preg_grep( '/^\d{4}-\d{2}-\d{2}/', array_column( $__ledger['data'], 'last_seen' ) ) && array() === array_diff( array_unique( explode( ', ', implode( ', ', array_column( $__ledger['data'], 'doors' ) ) ) ), array( 'read door', 'write door', 'remote door', 'Desktop Mode agents', 'the plugin itself' ) ), 'Last seen reads in the site\'s format, never a raw SQL time; Doors names the doors as the screen does, never rw/agent/direct' );
ok( false !== strpos( $kit, '467 calls came through a door since August 1, 2026; sn-status took 21% of them.' ) && false !== strpos( $kit, 'value="467" label="Calls through a door"' ) && false !== strpos( $kit, 'value="27" label="Tools called through a door"' ), 'the finding and the tiles count door calls to listed tools only (397 read and write, 70 remote; the retired tool\'s 9 left out), since the window\'s first day in the site\'s date format' );
ok( false !== strpos( $kit, 'Partial window. Recording began August 1, 2026, so this covers 71 of the 90 days asked for.' ), 'the partial window names when recording began, readably' );
ok( false !== strpos( html_entity_decode( $kit, ENT_QUOTES ), '72,649 calls in all: 300 through the read door, 60 the write door, 70 the remote door, 37 from Desktop Mode agents, and 72,182 by the plugin\'s own surfaces.' ), 'the door split names every door as the screen does, the remote door among them' );
$__classic_rows = substr_count( $classic, '<tr><td><code>' );
ok( 10 === $__classic_rows && false !== strpos( $classic, '+17 more tools, busiest first;' ) && false !== strpos( $classic, 'Remote door: through the Cloudflare Worker' ) && false !== strpos( $classic, '<code>' . $live['remote'][0] . '</code>: 40' ), 'classic: the same ten-row ledger, the remote door listed, each slug with its calls' );
snt_leaf_preview( 'ai-agent-tools', $kit );

// 2026-10-12 ruling (owner: unknown is never painted as a verdict): while the
// remote door is off, the reader files its 16 uncalled twins as not measured.
// The chips, the door's closed summary, the count and the classic list say so
// in plain words; none of them reads "no calls" or "retirement candidate".
$__quiet = array_slice( $live['remote'], 2 );
$GLOBALS['__usage']['zero_call'] = array_merge( $zero, array_map( static fn( $slug ) => array( 'slug' => $slug, 'verdict' => 'not_measured', 'reason' => 'the remote door is off now', 'calls' => 0 ), $__quiet ) );
$kit     = snt_leaf_paint( 'ai', 'agent-tools' );
$classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
preg_match( '/<os-disclosure heading="Remote door: through the Cloudflare Worker" hint="([^"]*)"/', $kit, $__rh );
ok( 16 === preg_match_all( '/<os-chip tone="neutral">[^<]+ · not measured<\/os-chip>/', $kit ) && 0 === preg_match_all( '/<os-chip[^>]*>remote-[^<]+ · no calls<\/os-chip>/', $kit ), 'remote door off: its 16 uncalled twins read "not measured" on neutral chips, never "no calls"' );
ok( false !== strpos( html_entity_decode( $__rh[1] ?? '', ENT_QUOTES ), 'In this window 2 called through a door, 0 not. Not measured: the remote door is off now (16 tools).' ), 'the remote door\'s closed summary says, in plain words, why its zeros are not measured' );
ok( false !== strpos( $kit, '60 allowlisted tools had no call through a door: 1 cannot be projected; 1 with reachability unknown; 16 not measured, their door closed; 41 retirement candidates; 1 called only by the plugin itself.' ), 'the count keeps the not-measured tools apart from the retirement candidates' );
ok( false !== strpos( $classic, '<code>' . $__quiet[0] . '</code>: not measured, the remote door is off now</li>' ), 'classic: the same words beside each twin' );
$GLOBALS['__usage']['zero_call'] = $zero;

// 2026-10-12 audit: an unread sensor is not zero calls. Both surfaces list
// the bridge's tools with "not read" in every count cell, never a 0.
$GLOBALS['__mr'] = array( 'ok' => false, 'error' => 'HTTP 502' );
$down_kit     = snt_leaf_paint( 'ai', 'agent-tools' );
$down_classic = snt_leaf_classic_html( 'sn_admin_render_agent_tools_section' );
$down_table   = array_values( array_filter( snt_leaf_tables( $down_kit ), static fn( $t ) => in_array( 'Answers', array_column( $t['columns'], 'label' ), true ) ) );
$cells        = array();
foreach ( $down_table[0]['data'] ?? array() as $row ) {
	foreach ( array( 'calls', 'ok', 'absent', 'error' ) as $k ) {
		$cells[] = is_array( $row[ $k ] ?? null ) ? ( $row[ $k ]['text'] ?? '' ) : (string) ( $row[ $k ] ?? '' );
	}
}
ok( array() !== $cells && array( 'not read' ) === array_values( array_unique( $cells ) ), 'sensor unread: every bridge count cell says not read (' . implode( ',', array_unique( $cells ) ) . ')' );
ok( false !== strpos( $down_kit, 'The sensor could not be read (HTTP 502), so no call counts are shown.' ) && false !== strpos( $down_classic, 'The sensor could not be read (HTTP 502), so no call counts are shown.' ) && false === strpos( $down_classic, '<td>0</td>' ) && false !== strpos( $down_classic, '<td>not read</td>' ), 'sensor unread: both surfaces say so in the same words, and the classic table paints no 0' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
