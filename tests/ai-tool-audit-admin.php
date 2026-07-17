<?php
/**
 * Fixture tests for inc/ai-tool-audit-admin.php — the TEMPORARY click-in-wp-admin
 * Copilot tool-budget audit (v9.62.0, removed next release).
 *
 * Covers the two things that matter for a shipped admin surface: it is gated
 * (manage_options + nonce), and its measurement reconstructs + sizes the tool
 * list without fataling.
 *
 * Run: php tests/ai-tool-audit-admin.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; }
	else { $fail++; echo "  FAIL— $label\n"; }
}

// ── stubs ────────────────────────────────────────────────────────────
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function wp_json_encode( $d ) { return json_encode( $d ); }
function get_current_user_id() { return 1; }
function apply_filters( $hook, $value ) { return $value; } // identity — measurement only

// The real ability→tool-name transform (verbatim, desktop-mode abilities.php:99).
function desktop_mode_ai_ability_tool_name( $ability_name ) {
	$slug = (string) $ability_name;
	$pos  = strpos( $slug, '/' );
	if ( false !== $pos ) { $slug = substr( $slug, $pos + 1 ); }
	$slug = strtolower( str_replace( '-', '_', $slug ) );
	$slug = preg_replace( '/[^a-z0-9_]+/', '_', $slug );
	return trim( (string) $slug, '_' );
}

class WP_Ability {
	public $desc;
	public $schema;
	public function __construct( $desc, $schema ) { $this->desc = $desc; $this->schema = $schema; }
	public function get_description() { return $this->desc; }
	public function get_input_schema() { return $this->schema; }
}

$GLOBALS['__abilities'] = array(
	'signal-noise/get-analytics-summary' => new WP_Ability( 'Views/visits for a window.', array( 'type' => 'object', 'properties' => array() ) ),
	'other-plugin/search-posts'          => new WP_Ability( 'Search posts.', array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string' ) ) ) ),
);
function desktop_mode_ai_search_ability_names() { return array_keys( $GLOBALS['__abilities'] ); }
function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; }

require_once __DIR__ . '/../inc/ai-tool-audit-admin.php';

echo "\n── the measurement reconstructs + sizes without fataling ──\n";
$out = snt_ai_tool_audit_admin_measure();
ok( is_string( $out ) && $out !== '', 'measure() returns a non-empty report' );
ok( strpos( $out, 'TOOLS: 2' ) !== false, 'it counts the two reconstructed ability tools' );
ok( strpos( $out, 'get_analytics_summary' ) !== false, 'it lists the stripped tool name (namespace removed, as desktop-mode sends it)' );
ok( strpos( $out, 'OURS (SN):' ) !== false && strpos( $out, '1 tools' ) !== false,
	'it attributes our share — 1 of the 2 is ours (signal-noise/)' );
ok( strpos( $out, 'BEFORE the filter' ) !== false && strpos( $out, 'AFTER the filter' ) !== false && strpos( $out, 'DELTA' ) !== false,
	'it reports before / after / delta' );

echo "\n── the panel is GATED (manage_options + nonce) — source-asserted ──\n";
$src = (string) file_get_contents( __DIR__ . '/../inc/ai-tool-audit-admin.php' );
ok( strpos( $src, "current_user_can( 'manage_options' )" ) !== false,
	'the panel bails unless the viewer can manage_options' );
ok( strpos( $src, "check_admin_referer( 'sn_ai_audit_run' )" ) !== false,
	'the audit only runs behind a verified nonce' );
ok( preg_match( '/esc_html\(\s*snt_ai_tool_audit_admin_measure\(\)\s*\)/', $src ) === 1,
	'the report is echoed through esc_html — descriptions/names are never printed raw' );
ok( strpos( $src, 'esc_url(' ) !== false, 'the action link is escaped with esc_url' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
