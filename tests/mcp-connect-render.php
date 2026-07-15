<?php
/**
 * Render + registry tests for Tools → Connect an MCP client
 * (inc/admin-forms/mcp-connect.php, v9.47.0) — the read-only doc leaf
 * pointing an external MCP client at this site's two servers. Drives the
 * REAL sn_mcp_allowlist() (inc/mcp/mcp-capabilities.php is loaded unguarded
 * by the bootstrap, so it must never be re-stubbed here — redeclare fatal)
 * so the tool list on the page can never silently drift from what
 * tools/list actually advertises. i18n uses the tests/analytics-i18n.php
 * recording-stub idiom; esc_url use is proven the same way (a recording
 * stub, not a pass-through), so a dropped esc_url() call would fail here.
 *
 * Run: php tests/mcp-connect-render.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true ); // mcp-endpoint.php: skip its rest_api_init/sn_agents_surfaces wiring.

// ---- Recording __-family stubs (return input unchanged = en_US behavior) ----
$GLOBALS['__i18n'] = array();
function sn_test_i18n_record( $s, $d ) { $GLOBALS['__i18n'][] = array( (string) $s, $d ); }
function __( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return $s; }
function esc_html__( $s, $d = null ) { sn_test_i18n_record( $s, $d ); return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
/** True iff $text was routed through a translation fn with the plugin domain. */
function sn_i18n_seen( $text ) {
	foreach ( $GLOBALS['__i18n'] as $c ) {
		if ( $c[0] === $text && 'signal-and-noise-tools' === $c[1] ) { return true; }
	}
	return false;
}

// ---- Real-behavior HTML escaping (not a pass-through) ----
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

// ---- Recording esc_url()/rest_url() stubs — prove the escaping fn actually
// used on each endpoint URL is esc_url(), not some other/no escaping. ----
$GLOBALS['__esc_url_calls'] = array();
function esc_url( $s ) { $GLOBALS['__esc_url_calls'][] = (string) $s; return (string) $s; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function get_edit_profile_url( $user_id = 0 ) { return 'https://example.test/wp-admin/profile.php'; }
function apply_filters( $tag, $value ) { return $value; } // sn_mcp_allowlist() filters through 'sn_mcp_allowlist'; no filters registered here.

require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/mcp/mcp-capabilities.php'; // the REAL sn_mcp_allowlist() — never stub this.
require __DIR__ . '/../inc/mcp/mcp-endpoint.php';      // the REAL sn_mcp_namespace().
require __DIR__ . '/../inc/admin-forms/mcp-connect.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "mcp-connect-render suite — plugin v9.47.0\n\n";

// ── Registry: the leaf exists under Tools, before Links (drives the real data) ──
$tabs = sn_admin_top_tabs();
$tools = null;
foreach ( $tabs as $top ) {
	if ( 'tools' === $top['tab'] ) { $tools = $top; break; }
}
ok( null !== $tools, 'tools top-tab found in the registry' );
$tool_slugs = null !== $tools ? array_keys( $tools['sub_tabs'] ) : array();
ok( in_array( 'mcp-connect', $tool_slugs, true ), 'mcp-connect leaf is registered under tools' );
ok( array_search( 'mcp-connect', $tool_slugs, true ) < array_search( 'links', $tool_slugs, true ),
	'mcp-connect sits before links (links stays last)' );
ok( ( $tools['sub_tabs']['mcp-connect']['render'] ?? '' ) === 'sn_admin_render_mcp_connect_section',
	'mcp-connect leaf names sn_admin_render_mcp_connect_section' );
ok( function_exists( 'sn_admin_render_mcp_connect_section' ), 'sn_admin_render_mcp_connect_section() is defined' );
ok( empty( $tools['sub_tabs']['mcp-connect']['wide'] ), 'mcp-connect is a capped leaf (like Links), not wide' );

// ── Render drive ──
ob_start();
sn_admin_render_mcp_connect_section();
$html = ob_get_clean();

// The live allowlist — 15 tools as of v9.22.0, but this suite asserts against
// the REAL function's output so a future addition/removal is caught, not hidden.
$slugs = sn_mcp_allowlist();
ok( 15 === count( $slugs ), 'sanity: sn_mcp_allowlist() is still the documented 15-slug v1 list' );
foreach ( $slugs as $slug ) {
	ok( false !== strpos( $html, '<code>' . htmlspecialchars( $slug, ENT_QUOTES ) . '</code>' ), "allowlist slug rendered: $slug" );
}

// ── Both endpoint URLs are esc_url'd from rest_url() ──
$native_url  = 'https://example.test/wp-json/signal-noise/v1/mcp';
$adapter_url = 'https://example.test/wp-json/mcp/mcp-adapter-default-server';
ok( in_array( $native_url, $GLOBALS['__esc_url_calls'], true ), 'native endpoint URL passed through esc_url()' );
ok( in_array( $adapter_url, $GLOBALS['__esc_url_calls'], true ), 'adapter endpoint URL passed through esc_url()' );
ok( false !== strpos( $html, $native_url ), 'native endpoint URL rendered' );
ok( false !== strpos( $html, $adapter_url ), 'adapter endpoint URL rendered' );

// ── Adapter door is honest: not detected in this test env → hedged wording ──
ok( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'sanity: the adapter class is not defined in this test env' );
ok( stripos( $html, 'if the wp.org' ) !== false, 'adapter block uses hedged "if…active" phrasing when undetected' );

// ── Placeholders present, never a real-looking secret ──
ok( false !== strpos( $html, '&lt;admin-username&gt;' ), 'WP_API_USERNAME placeholder present' );
ok( false !== strpos( $html, '&lt;application-password&gt;' ), 'WP_API_PASSWORD placeholder present' );
ok( false !== strpos( $html, '&lt;base64 admin-username:application-password&gt;' ), 'Claude Code one-liner placeholder present' );
ok( 0 === preg_match( '/([a-zA-Z0-9]{4}\s){5}[a-zA-Z0-9]{4}/', $html ), 'no real-looking WP application-password pattern (xxxx xxxx… ×6) present' );
ok( false === strpos( $html, 'sk-' ) && false === strpos( $html, 'sk-ant-' ), 'no API-key-shaped string present' );

// ── The application-password deep link ──
ok( false !== strpos( $html, 'https://example.test/wp-admin/profile.php#application-passwords-section' ),
	'links to the current user’s Application Passwords section' );

// ── The disambiguation note ──
ok( sn_i18n_seen( 'Not the same as Connector Approvals' ), 'disambiguation heading translatable' );
ok( false !== strpos( $html, 'Not the same as Connector Approvals' ), 'disambiguation heading rendered' );
ok( stripos( $html, 'Connector Approvals' ) !== false && stripos( $html, 'OUTBOUND' ) !== false, 'disambiguation names Connector Approvals as the OUTBOUND gate' );
ok( stripos( $html, 'Application Password' ) !== false, 'disambiguation points to the Application Password as the real inbound grant' );

// ── i18n recording pins on headings/steps ──
ok( sn_i18n_seen( 'Door 1 — the native MCP server' ), 'Door 1 heading translatable' );
ok( sn_i18n_seen( 'Door 2 — the Abilities-registry adapter' ), 'Door 2 heading translatable' );
ok( sn_i18n_seen( 'Connect a client' ), 'owner-steps heading translatable' );
ok( sn_i18n_seen( 'Create an %s under your own WordPress user — MCP clients authenticate as you, over Basic auth, never with your normal password.' ), 'step 1 is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Copy the endpoint URL for whichever door you’re using — Door 1 above for the read-only tool allowlist, Door 2 for the full Abilities registry.' ), 'step 2 translatable' );
ok( sn_i18n_seen( 'Paste the client config below, swapping in your WordPress username and the Application Password you just created.' ), 'step 3 translatable' );
ok( sn_i18n_seen( 'More' ), 'deep-links heading translatable' );

// ── The <pre> config block is code — untranslated ──
ok( ! sn_i18n_seen( 'mcpServers' ) && ! sn_i18n_seen( 'WP_API_URL' ), 'the JSON config keys never route through i18n' );
ok( false !== strpos( $html, '"command": "npx"' ), 'config block renders the literal proxy shape' );
ok( false !== strpos( $html, '@automattic/mcp-wordpress-remote@latest' ), 'config block names the proxy package' );
ok( false !== strpos( $html, 'claude mcp add --transport http' ), 'the Claude Code one-liner is present' );
// Review fold (v9.47.0): current CLI syntax requires a server-NAME positional
// between the transport and the URL — the v9.22.0 CHANGELOG precedent is
// stale and errors without it. Pin the name token directly before the URL.
ok( false !== strpos( $html, 'claude mcp add --transport http signal-and-noise ' ), 'the one-liner carries the required server-name positional before the URL' );
ok( 1 === substr_count( $html, '<pre>' ), 'exactly ONE <pre> copy-paste block' );

// ── Deep links ──
ok( false !== strpos( $html, 'https://example.test/wp-admin/tools.php' ), 'Abilities Explorer falls back to the generic Tools menu (no guessed slug)' );
ok( false !== strpos( $html, 'https://github.com/juanlentino/signal-and-noise-tools/blob/main/docs/ai-abilities-catalog.md' ), 'links to the Abilities catalog doc' );

// ── MIRROR RULE: read-only, no write surface of any kind ──
ok( strpos( $html, '<input' ) === false && strpos( $html, '<button' ) === false
	&& strpos( $html, '<textarea' ) === false && strpos( $html, '<form' ) === false,
	'READ-ONLY: no input/button/textarea/form anywhere in the leaf' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
