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
function sn_mcp_telemetry_usage() { return null; }
function sn_mcp_telemetry_table_exists() { return false; }
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
ok( false !== strpos( $kit, 'heading="Through MCP"' ) && false !== strpos( $kit, 'The call log table is not installed yet' ) && false !== strpos( $kit, '<os-disclosure heading="The doors, and what each exposes">' ) && false !== strpos( $kit, 'Door 1: the native MCP server' ) && 2 === substr_count( $kit, snt_leaf_row() ), 'Through MCP: the call log open under the section, the four doors paired inside one fold' );
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
ok( '9' === $by['related-notes']['calls'] && '7' === $by['related-notes']['ok'] && '2' === $by['related-notes']['absent'] && '0' === $by['related-notes']['error'], 'related-notes: 9 calls, 7 ok, 2 absent, 0 error' );
ok( '6' === $by['verify-page']['calls'] && '1' === $by['verify-page']['error'], 'verify-page: 6 calls, 1 error' );
ok( isset( $by['search-notes *'] ) && '2' === $by['search-notes *']['calls'] && false !== strpos( $by['search-notes *']['what'], 'Not in the registered list' ), 'a tool seen in the rows but not registered is painted, starred, and says so' );
ok( 6 === count( $rows ) && '0' === $by['get-citation']['calls'], 'six rows: the five registered plus the seen one; the quiet ones stay at zero' );
ok( false !== strpos( $kit, '55 notes, 12 pages, built 1 hour ago' /* the harness stubs human_time_diff */ ) && false !== strpos( $kit, 'on the latest note, 2 related' ), 'documents: the site map with its counts and age; the manifest on the latest note' );
ok( false !== strpos( $kit, 'have not matched their anchor for 1 hour' ), 'a remembered drift on the bridge reads as a drift with its age' );
ok( false !== strpos( $classic, '<td class="sn-mono">related-notes</td><td>The notes the kernel ranks closest to this one</td><td>9</td><td>7</td><td>2</td><td>0</td>' ), 'the classic table paints the same row' );
ok( false !== strpos( $kit, '<strong>19</strong> calls across <strong>2</strong> tools' ) && false !== strpos( $classic, '<strong>19</strong> calls across <strong>2</strong> tools' ), 'Through Copilot: the ranked summary (19 calls across 2 tools) on both leaves' );
ok( false !== strpos( $kit, 'search_posts' ) && strpos( $kit, 'search_posts' ) < strpos( $kit, 'export_audit_log' ), 'Copilot tools ranked by count' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
