<?php
/**
 * Standalone tests for the MCP prompts module: prompts/list, prompts/get, and
 * the unknown-name -> null contract (R4). Both prompts are static PHP text —
 * no AI call happens server-side, no ability is called here at all — so this
 * suite needs no wp_get_ability stub. Sub-project B, v9.50.0 (lane PROTO).
 *
 * @since plugin v9.50.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );

require __DIR__ . '/../inc/mcp/mcp-prompts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP prompts — plugin v9.50.0\n\n";

// --- prompts/list: exactly the 2 R3 prompts, each with a name + description ---
$list = sn_mcp_prompts_list();
ok( isset( $list['prompts'] ) && is_array( $list['prompts'] ), 'prompts_list returns a prompts array' );
ok( count( $list['prompts'] ) === 2, 'exactly 2 prompts are advertised' );
$names = array_column( $list['prompts'], 'name' );
ok( in_array( 'weekly-report', $names, true ), 'prompts/list advertises weekly-report' );
ok( in_array( 'content-audit', $names, true ), 'prompts/list advertises content-audit' );
foreach ( $list['prompts'] as $p ) {
	ok( ! empty( $p['description'] ), "prompt '{$p['name']}' carries a non-empty description" );
}

// --- prompts/get: unknown name -> null (caller maps to -32602, R4) ---
ok( null === sn_mcp_prompt_get( 'does-not-exist' ), 'prompts/get on an unknown name returns null' );

// --- weekly-report: shape + content ---
$weekly = sn_mcp_prompt_get( 'weekly-report' );
ok( ! empty( $weekly['description'] ), 'weekly-report result carries a description' );
ok( isset( $weekly['messages'] ) && is_array( $weekly['messages'] ) && count( $weekly['messages'] ) === 1, 'weekly-report returns exactly one message' );
ok( ( $weekly['messages'][0]['role'] ?? '' ) === 'user', 'weekly-report message role is "user"' );
ok( ( $weekly['messages'][0]['content']['type'] ?? '' ) === 'text', 'weekly-report message content type is "text"' );
$weekly_text = $weekly['messages'][0]['content']['text'] ?? '';
foreach ( array( 'get-analytics-summary', 'get-rss-stats', 'uptime-status', 'get-narration', 'get-insights' ) as $tool ) {
	ok( false !== strpos( $weekly_text, $tool ), "weekly-report instructs the agent to call $tool" );
}
ok( stripos( $weekly_text, 'null' ) !== false, 'weekly-report explicitly addresses the null-means-not-generated-yet case' );
// No literal template placeholder (e.g. "{{" or "TODO") should survive into the served text.
ok( false === strpos( $weekly_text, '{{' ) && false === stripos( $weekly_text, 'TODO' ), 'weekly-report text is placeholder-free' );

// --- content-audit: shape + content ---
$audit = sn_mcp_prompt_get( 'content-audit' );
ok( ! empty( $audit['description'] ), 'content-audit result carries a description' );
ok( isset( $audit['messages'][0]['content']['text'] ), 'content-audit returns a text message' );
$audit_text = $audit['messages'][0]['content']['text'];
foreach ( array( 'get-health-scan', 'block-migrations-scan', 'pattern-adoption-scan' ) as $tool ) {
	ok( false !== strpos( $audit_text, $tool ), "content-audit instructs the agent to call $tool" );
}
ok( stripos( $audit_text, 'priorit' ) !== false, 'content-audit asks for a prioritized findings list' );
ok( false === strpos( $audit_text, '{{' ) && false === stripos( $audit_text, 'TODO' ), 'content-audit text is placeholder-free' );

// --- arguments are accepted but unused (v1: neither prompt takes any) ---
$weekly_with_args = sn_mcp_prompt_get( 'weekly-report', array( 'unused' => 'value' ) );
ok( $weekly_with_args === $weekly, 'passing arguments to a no-argument prompt does not change its output' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
