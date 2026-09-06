<?php
/**
 * Native window leaf: AI → Copilot Usage (apps/sn-dashboard/parts/leaves/ai-copilot-usage.php).
 *
 * The oracle is the classic leaf (inc/ai-tool-invocation-log.php,
 * `snt_ai_tool_invocations_render()`): read-only, no forms, no actions — the
 * suite pins the empty state, the rich state (summary + ranked rows), the
 * deterministic tie-break order, and that a hostile tool name is escaped.
 *
 * Run: php tests/os-leaf-ai-copilot-usage.php
 */
require_once __DIR__ . '/lib/os-leaf-harness.php';

// The classic file's compat-layer dependency (dual action registration).
if ( ! function_exists( 'snt_os_compat_add_action' ) ) {
	function snt_os_compat_add_action( $old_hook, $new_hook, $cb ) {
		add_action( $old_hook, $cb );
		add_action( $new_hook, $cb );
	}
}

require SNT_PATH . 'inc/ai-tool-invocation-log.php';
require SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-copilot-usage.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

ok( isset( \SignalNoise\OpenStationHost\Dashboard\painters()['ai/copilot-usage'] ), 'the painter is registered under ai/copilot-usage' );

// ── Empty state: nothing recorded yet.
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array();
$classic = snt_leaf_classic_html( 'snt_ai_tool_invocations_render' );
$kit     = snt_leaf_paint( 'ai', 'copilot-usage' );
ok( '' !== $kit, 'the kit leaf paints' );
ok( array() === snt_leaf_names( $classic ) && array() === snt_leaf_names( $kit ), 'no form field names on either leaf (read-only)' );
ok( array() === snt_leaf_actions( $classic ) && array() === snt_leaf_actions( $kit ), 'no sn_action on either leaf (read-only)' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup survives: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok( false !== strpos( $kit, 'No Ask AI tool calls recorded yet' ), 'the empty state renders its own fixed copy, not an empty list' );
ok( false === strpos( $kit, '<os-stat' ) && false === strpos( $kit, 'calls across' ), 'the empty state does not paint the summary line' );

// ── Rich state: three tools, ranked by count desc, ties broken alphabetically.
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array(
	'export_audit_log' => array( 'n' => 7, 'first' => 100, 'last' => 200 ),
	'zeta_tool'         => array( 'n' => 7, 'first' => 100, 'last' => 200 ), // Tie with export_audit_log: 'e' < 'z'.
	'search_posts'      => array( 'n' => 12, 'first' => 50, 'last' => 300 ),
);
$classic = snt_leaf_classic_html( 'snt_ai_tool_invocations_render' );
$kit     = snt_leaf_paint( 'ai', 'copilot-usage' );
ok( array() === snt_leaf_classic_markers( $kit ), 'no wp-admin markup in the rich state: ' . implode( ',', snt_leaf_classic_markers( $kit ) ) );
ok(
	false !== strpos( $kit, '<strong>26</strong> calls across <strong>3</strong> tools' )
	&& false !== strpos( $classic, '<strong>26</strong> calls across <strong>3</strong> tools' ),
	'the summary line reads 26 calls across 3 tools, matching the classic renderer'
);
ok( false !== strpos( $kit, 'search_posts' ) && false !== strpos( $kit, '12' ), 'the top-ranked tool (search_posts, 12 calls) is shown with its count' );
ok( false !== strpos( $kit, '<os-code' ) && false !== strpos( $kit, 'search_posts' ), 'tool names are painted as os-code, matching the classic <code> treatment' );
$pos_export = strpos( $kit, 'export_audit_log' );
$pos_zeta   = strpos( $kit, 'zeta_tool' );
ok( false !== $pos_export && false !== $pos_zeta && $pos_export < $pos_zeta, 'a count tie is broken alphabetically (export_audit_log before zeta_tool)' );
ok( strpos( $kit, 'search_posts' ) < $pos_export, 'the higher count (search_posts) ranks before the tied pair' );
ok(
	strpos( $classic, 'search_posts' ) < strpos( $classic, 'export_audit_log' )
	&& strpos( $classic, 'export_audit_log' ) < strpos( $classic, 'zeta_tool' ),
	'the classic renderer orders the same three tools the same way (oracle check)'
);

// ── Escaping: a hostile tool name never reaches the markup raw.
$GLOBALS['__options'][ SN_AI_TOOL_INVOCATIONS_OPT ] = array(
	'"><script>x</script>' => array( 'n' => 1, 'first' => 1, 'last' => 1 ),
);
$kit = snt_leaf_paint( 'ai', 'copilot-usage' );
ok( false === strpos( $kit, '<script>' ) && false !== strpos( $kit, '&lt;script&gt;' ), 'a hostile tool name is escaped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
