<?php
/**
 * Render + registry tests for Tools → Connect an MCP client
 * (inc/admin-forms/mcp-connect.php, v9.47.0, widened v9.50.0) — the doc leaf
 * (no form, no side effects) pointing an external MCP client at this site's
 * three doors: the native read door, the new native write door, and the
 * third-party adapter. Drives the REAL sn_mcp_allowlist() AND
 * sn_mcp_rw_allowlist() (both inc/mcp/mcp-capabilities.php, loaded unguarded
 * by the bootstrap, so neither is ever re-stubbed here — redeclare fatal) so
 * neither door's rendered tool list/count can silently drift from what
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

// ---- R9 (v9.51.0, lane SEC-C) stubs: the write-door credential-binding form ----
// In-memory options store — backs the REAL sn_mcp_rw_bound_uuid()/
// sn_mcp_set_rw_bound_uuid() (inc/mcp/mcp-rw-guard.php, required below and
// never stubbed, same "drive the real fn" idiom as sn_mcp_allowlist() above).
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

function get_current_user_id() { return 42; }

// A configurable fixture standing in for the CURRENT user's own Application
// Passwords — WP_Application_Passwords::get_user_application_passwords() is
// itself scoped to one user id in real WP, so a flat fixture list is enough.
$GLOBALS['__app_passwords'] = array();
class WP_Application_Passwords {
	public static function get_user_application_passwords( $user_id ) {
		return $GLOBALS['__app_passwords'];
	}
}

function human_time_diff( $from, $to = 0 ) { return '3 days'; }

// Recording wp_nonce_field()/selected() — real-behavior stand-ins (not just a
// no-op) so "the nonce field is actually rendered" / "the bound option is
// actually marked selected" are provable, not assumed.
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . $name . '" value="test-nonce-for-' . $action . '">';
	if ( $echo ) { echo $html; }
	return $html;
}
function selected( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}

function wp_kses_post( $s ) { return (string) $s; } // admin-glance meta_html pass-through (M3 glance).

require __DIR__ . '/../inc/admin-glance.php';       // sn_admin_glance_grid() — the M3 status glance renders through it.
require __DIR__ . '/../inc/admin-tabs-data.php';
require __DIR__ . '/../inc/mcp/mcp-capabilities.php'; // the REAL sn_mcp_allowlist() + sn_mcp_rw_allowlist() — never stub either.
require __DIR__ . '/../inc/mcp/mcp-endpoint.php';      // the REAL sn_mcp_namespace().
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';      // the REAL sn_mcp_rw_bound_uuid()/sn_mcp_set_rw_bound_uuid() — never stub either.
require __DIR__ . '/../inc/admin-forms/mcp-connect.php';

// Fixture Application Passwords for the binding-form tests below. Populated
// BEFORE the main render drive so the first full-page $html already exercises
// a populated <select> in the (default, fresh-install) unbound state.
$uuid_a = '11111111-1111-1111-1111-111111111111';
$uuid_b = '22222222-2222-2222-2222-222222222222';
$escaping_name = 'Rex\'s "Prod" <admin>';
$GLOBALS['__app_passwords'] = array(
	array( 'uuid' => $uuid_a, 'name' => 'Claude Code', 'created' => 1700000000, 'last_used' => 1700100000 ),
	array( 'uuid' => $uuid_b, 'name' => $escaping_name, 'created' => 1700000000, 'last_used' => null ),
);

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "mcp-connect-render suite: plugin v9.47.0\n\n";

// ── Registry: the leaf lives under AI (v10.46.0 — moved off Tools, which had
// become a junk drawer). MCP is where external AI clients are granted doors, so
// it belongs with the models and the tool-invocation log, not beside a link list. ──
$tabs = sn_admin_top_tabs();
$ai    = null;
$tools = null;
foreach ( $tabs as $top ) {
	if ( 'ai' === $top['tab'] ) { $ai = $top; }
	if ( 'tools' === $top['tab'] ) { $tools = $top; }
}
ok( null !== $ai, 'ai top-tab found in the registry' );
$ai_slugs = null !== $ai ? array_keys( $ai['sub_tabs'] ) : array();
ok( in_array( 'mcp-connect', $ai_slugs, true ), 'mcp-connect leaf is registered under ai' );
ok( ! in_array( 'mcp-connect', array_keys( $tools['sub_tabs'] ?? array() ), true ),
	'mcp-connect no longer appears under tools (a leaf must not be registered on two tabs)' );
ok( ( $ai['sub_tabs']['mcp-connect']['render'] ?? '' ) === 'sn_admin_render_mcp_connect_section',
	'mcp-connect leaf names sn_admin_render_mcp_connect_section' );
ok( function_exists( 'sn_admin_render_mcp_connect_section' ), 'sn_admin_render_mcp_connect_section() is defined' );
ok( empty( $tools['sub_tabs']['mcp-connect']['wide'] ), 'mcp-connect is a capped leaf (like Links), not wide' );

// ── Render drive ──
ob_start();
sn_admin_render_mcp_connect_section();
$html = ob_get_clean();

// The live allowlist — this suite asserts against the REAL function's output
// (never a hardcoded number) so a future addition/removal is caught, not
// hidden, and so this suite doesn't race lane DOORS' own D1 widening (15→23)
// of the same function — that exact count is tests/mcp-capabilities.php's pin.
$slugs = sn_mcp_allowlist();
ok( count( $slugs ) > 0, 'sanity: sn_mcp_allowlist() returns a non-empty read-door list' );
foreach ( $slugs as $slug ) {
	ok( false !== strpos( $html, '<code>' . htmlspecialchars( $slug, ENT_QUOTES ) . '</code>' ), "allowlist slug rendered: $slug" );
}
ok( false !== strpos( $html, (string) count( $slugs ) . ' read-only tools exposed' ), 'read-door tool count is live-rendered from sn_mcp_allowlist(), not hardcoded' );

// ── Both endpoint URLs are esc_url'd from rest_url() ──
$native_url  = 'https://example.test/wp-json/signal-noise/v1/mcp';
$adapter_url = 'https://example.test/wp-json/mcp/mcp-adapter-default-server';
ok( in_array( $native_url, $GLOBALS['__esc_url_calls'], true ), 'native endpoint URL passed through esc_url()' );
ok( in_array( $adapter_url, $GLOBALS['__esc_url_calls'], true ), 'adapter endpoint URL passed through esc_url()' );
ok( false !== strpos( $html, $native_url ), 'native endpoint URL rendered' );
ok( false !== strpos( $html, $adapter_url ), 'adapter endpoint URL rendered' );

// ── Adapter door is honest: not detected in this test env → absent wording ──
// v9.48.1: the old copy attributed the MCP Adapter to the wp.org "AI" plugin.
// Ground-truthed 2026-07-15: the AI plugin does NOT bundle the adapter (its
// MCP integration is "coming soon"); the adapter is a separate GitHub-only
// plugin. The door must say the adapter is absent, name it as its own plugin,
// and never imply the AI plugin provides it.
ok( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'sanity: the adapter class is not defined in this test env' );
ok( stripos( $html, 'No MCP Adapter is installed' ) !== false, 'adapter block states the adapter is absent on this site' );
ok( stripos( $html, 'coming soon' ) !== false, 'adapter block names the AI plugin\'s MCP integration as roadmap-only' );
ok( stripos( $html, 'separate WordPress plugin' ) !== false, 'adapter block names the adapter as its own separate plugin' );
ok( stripos( $html, 'If the wp.org “AI” plugin is active on this site, its MCP Adapter' ) === false, 'REGRESSION: the false "AI plugin ships the adapter" attribution is gone' );

// ── Claude desktop app section (v9.49.0) ──
// The owner connects from the Claude APP, not the CLI. The officially
// documented app path for a Basic-auth (application-password) endpoint is
// the LOCAL config file + stdio proxy; the app's "Add custom connector"
// (remote) flow is OAuth-only — the section must say both, honestly.
ok( false !== strpos( $html, 'claude_desktop_config.json' ), 'app section names the config file' );
ok( false !== strpos( $html, 'Library/Application Support/Claude' ), 'app section gives the macOS config path' );
ok( false !== strpos( $html, '%APPDATA%' ), 'app section gives the Windows config path' );
ok( stripos( $html, 'restart' ) !== false, 'app section says to fully restart the app' );
ok( stripos( $html, 'Node.js' ) !== false, 'app section names the Node.js/npx requirement for the proxy' );
ok( stripos( $html, 'OAuth' ) !== false, 'app section warns the remote custom-connector flow is OAuth-only' );
ok( stripos( $html, 'application password will not work there' ) !== false, 'app section explicitly closes the wrong door' );

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
ok( sn_i18n_seen( 'Door 1: the native MCP server' ), 'Door 1 heading translatable' );
ok( sn_i18n_seen( 'Door 2: the Abilities-registry adapter' ), 'Door 2 heading translatable' );
ok( sn_i18n_seen( 'Connect a client' ), 'owner-steps heading translatable' );
ok( sn_i18n_seen( 'Create an %s under your own WordPress user. MCP clients authenticate as you, over Basic auth, never with your normal password.' ), 'step 1 is a translatable sprintf msgid' );
ok( sn_i18n_seen( 'Copy the endpoint URL for whichever door you’re using. Door 1 above for the read-only tool allowlist, Door 2 for the full Abilities registry.' ), 'step 2 translatable' );
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

// ── Write door (v9.50.0): live-rendered from sn_mcp_rw_allowlist() ──
$rw_slugs = sn_mcp_rw_allowlist();
ok( count( $rw_slugs ) > 0, 'sanity: the rw fixture is non-empty' );
foreach ( $rw_slugs as $slug ) {
	ok( false !== strpos( $html, '<code>' . htmlspecialchars( $slug, ENT_QUOTES ) . '</code>' ), "rw allowlist slug rendered: $slug" );
}
ok( false !== strpos( $html, (string) count( $rw_slugs ) . ' read-write tools exposed' ), 'rw tool count is live-rendered from sn_mcp_rw_allowlist(), not hardcoded' );

$rw_url = 'https://example.test/wp-json/signal-noise/v1/mcp-rw';
ok( in_array( $rw_url, $GLOBALS['__esc_url_calls'], true ), 'write door URL passed through esc_url()' );
ok( false !== strpos( $html, $rw_url ), 'write door URL rendered' );
ok( false !== strpos( $html, 'read-write' ), 'write door carries a read-write badge, not read-only' );
ok( sn_i18n_seen( 'Door 1b: the native write door' ), 'write door heading translatable' );

// ── Write-door honesty: same credentials, content mutation, AI budget ──
ok( stripos( $html, 'same Application Password' ) !== false, 'write door states it uses the SAME Application Password as the read door' );
ok( stripos( $html, 'modify your content' ) !== false, 'write door states it can modify content' );
ok( stripos( $html, 'spend the AI budget' ) !== false, 'write door states it can spend the AI budget' );
// The real rw allowlist includes two PURE-READ, PII-flagged tools
// (get-audit-log/export-audit-log — plaintext usernames) alongside the
// content-mutating/AI-billed ones — a blanket "every tool here mutates or
// spends budget" claim would overclaim, so the copy must not say "every".
ok( in_array( 'signal-noise/get-audit-log', $rw_slugs, true ), 'sanity: the real rw allowlist includes the PII-flagged get-audit-log' );
ok( stripos( $html, 'plaintext usernames' ) !== false, 'write door discloses the plaintext-username audit-log exception honestly' );
ok( stripos( $html, 'every tool here can modify' ) === false, 'write door does not overclaim that EVERY rw tool mutates or spends budget' );

// ── The write door does not duplicate read-door tools; points readers back ──
ok( stripos( $html, 'read door instead' ) !== false, 'write door tells a read-only client to use the read door instead' );

// ── The four withheld slugs, named with one-line reasons (honesty, not a hidden gap) ──
$withheld_slugs = array(
	'signal-noise/run-cron-event',
	'signal-noise/ai-orphan-apply',
	'signal-noise/merge-tags',
	'signal-noise/clear-template-overrides',
);
foreach ( $withheld_slugs as $slug ) {
	ok( false !== strpos( $html, '<code>' . htmlspecialchars( $slug, ENT_QUOTES ) . '</code>' ), "withheld slug named: $slug" );
	ok( ! in_array( $slug, $rw_slugs, true ), "sanity: withheld slug $slug is absent from the (fixture) rw allowlist" );
}
ok( stripos( $html, 'unbounded' ) !== false, 'run-cron-event reason cites the unbounded do_action() risk' );
ok( stripos( $html, 'no undo' ) !== false, 'ai-orphan-apply reason cites no undo' );
ok( stripos( $html, 'sitewide' ) !== false, 'merge-tags reason cites the sitewide blast radius' );
ok( stripos( $html, 'Site Editor' ) !== false, 'clear-template-overrides reason cites Site Editor regression risk' );

// ── Resources & prompts: one sentence each (v9.50.0) ──
ok( sn_i18n_seen( 'Resources & prompts' ), 'resources/prompts heading translatable' );
ok( stripos( $html, 'sn://abilities-catalog' ) !== false, 'resources sentence names sn://abilities-catalog' );
ok( stripos( $html, 'sn://changelog-latest' ) !== false, 'resources sentence names sn://changelog-latest' );
ok( stripos( $html, 'sn://design-tokens' ) !== false, 'resources sentence names sn://design-tokens' );
ok( stripos( $html, 'sn://llms-txt' ) !== false, 'resources sentence names sn://llms-txt' );
ok( stripos( $html, 'weekly-report' ) !== false, 'prompts sentence names weekly-report' );
ok( stripos( $html, 'content-audit' ) !== false, 'prompts sentence names content-audit' );

// ── REGRESSION: the old "both native doors are uniformly read-only" claim must be gone ──
ok( false === strpos( $html, 'Both are read-only' ), 'REGRESSION: intro no longer claims both native doors are read-only' );

// ══════════════════════════════════════════════════════════════════════════
// R9 (v9.51.0, lane SEC-C): the write-door credential-binding form —
// sn_admin_render_mcp_rw_binding(), the ONE deliberate exception to this
// leaf's prior read-only invariant (see the updated MIRROR RULE below). The
// main $html above was rendered with the fixture Application Passwords set
// (see the top of this file) and NOTHING bound yet — the default,
// fresh-install state.
// ══════════════════════════════════════════════════════════════════════════
ok( sn_i18n_seen( 'Bind the write-door credential' ), 'binding-form heading translatable' );

// ── Unbound state (default): the door is explicitly called out as INACTIVE ──
ok( stripos( $html, 'The write door is INACTIVE' ) !== false, 'unbound state: the door is explicitly called out as INACTIVE' );
ok( stripos( $html, 'every call to /mcp-rw is denied' ) !== false, 'unbound state: explains that every call is denied until bound' );

// ── The form mechanics: nonce, sn_action, method=post ──
ok( false !== strpos( $html, 'test-nonce-for-sn_theme_options_nonce' ), 'binding form carries a wp_nonce_field(\'sn_theme_options_nonce\') nonce' );
ok( false !== strpos( $html, '<input type="hidden" name="sn_action" value="bind_mcp_rw_credential">' ), 'binding form carries the sn_action hidden field' );
ok( false !== strpos( $html, '<form method="post">' ), 'binding form is a real <form method="post">' );

// ── The <select> of the current user's own Application Passwords ──
ok( false !== strpos( $html, '<select id="sn_mcp_rw_uuid" name="sn_mcp_rw_uuid">' ), 'binding form renders the Application Password <select>' );
ok( false !== strpos( $html, '<option value="' . $uuid_a . '"' ), 'select carries the first fixture UUID as an option value' );
ok( false !== strpos( $html, '<option value="' . $uuid_b . '"' ), 'select carries the second fixture UUID as an option value' );
ok( false !== strpos( $html, '<option value="">' ), 'select offers an explicit empty-value "Unbind" option' );
ok( stripos( $html, 'Unbind' ) !== false, 'the unbind option is labeled' );
ok( false !== strpos( $html, 'Claude Code' ), 'the first fixture Application Password name is rendered' );

// ── Escaping: a name carrying quotes/angle-brackets must render escaped, never raw ──
ok( false !== strpos( $html, htmlspecialchars( $escaping_name, ENT_QUOTES ) ), 'the escaping-sensitive fixture name is rendered ESCAPED' );
ok( false === strpos( $html, $escaping_name ), 'REGRESSION: the raw unescaped fixture name never reaches the page' );
ok( false === strpos( $html, '<admin>' ), 'REGRESSION: no raw unescaped tag from a password name reaches the page' );

// ── Bound + resolvable: binds to a fixture UUID, re-renders in isolation ──
ok( sn_mcp_set_rw_bound_uuid( $uuid_a ), 'sanity: binding the first fixture UUID succeeds' );
ob_start();
sn_admin_render_mcp_rw_binding();
$bound_html = ob_get_clean();
ok( stripos( $bound_html, 'The write door is INACTIVE' ) === false, 'bound state: the INACTIVE notice is gone' );
ok( false !== strpos( $bound_html, 'bound to <strong>Claude Code</strong>' ), 'bound state: names the bound Application Password by name' );
ok( false !== strpos( $bound_html, 'Last used 3 days ago' ), 'bound state: shows the last-used relative time via human_time_diff()' );
ok( false !== strpos( $bound_html, 'selected="selected"' ), 'bound state: the matching <option> carries selected="selected"' );
ok( false !== strpos( $bound_html, 'Rotate this Application Password' ), 'bound state: carries the rotation reminder' );

// ── Bound but NEVER used (last_used null): "Never used yet", no crash ──
ok( sn_mcp_set_rw_bound_uuid( $uuid_b ), 'sanity: binding the second (never-used) fixture UUID succeeds' );
ob_start();
sn_admin_render_mcp_rw_binding();
$never_used_html = ob_get_clean();
ok( stripos( $never_used_html, 'Never used yet' ) !== false, 'bound-but-never-used state: shows "Never used yet" rather than a bogus relative time' );

// ── Bound but UNRESOLVABLE: the bound UUID matches none of this user's own passwords ──
$unresolvable_uuid = '99999999-9999-9999-9999-999999999999';
ok( sn_mcp_set_rw_bound_uuid( $unresolvable_uuid ), 'sanity: setting an unresolvable (but well-formed) UUID succeeds at the guard layer' );
ob_start();
sn_admin_render_mcp_rw_binding();
$unresolvable_html = ob_get_clean();
ok( stripos( $unresolvable_html, 'INACTIVE' ) === false, 'unresolvable state is NOT rendered as the unbound/INACTIVE case' );
ok( stripos( $unresolvable_html, 'no longer matches any of your own Application Passwords' ) !== false, 'unresolvable state: explains the UUID no longer matches an owned Application Password' );

// ── No Application Passwords at all: points at the profile screen, renders no <select> ──
$GLOBALS['__opts']          = array();
$GLOBALS['__app_passwords'] = array();
ob_start();
sn_admin_render_mcp_rw_binding();
$no_passwords_html = ob_get_clean();
ok( stripos( $no_passwords_html, 'create one under' ) !== false, 'no-passwords state: points the owner at creating one first' );
ok( false === strpos( $no_passwords_html, '<select' ), 'no-passwords state: renders no <select> (nothing to pick from)' );
ok( false === strpos( $no_passwords_html, '<form' ), 'no-passwords state: renders no <form> either (nothing submittable)' );

// Restore the fixture + unbound state so the MIRROR RULE assertion below
// exercises the SAME default rendering the top-of-file $html already used.
$GLOBALS['__opts']          = array();
$GLOBALS['__app_passwords'] = array(
	array( 'uuid' => $uuid_a, 'name' => 'Claude Code', 'created' => 1700000000, 'last_used' => 1700100000 ),
	array( 'uuid' => $uuid_b, 'name' => $escaping_name, 'created' => 1700000000, 'last_used' => null ),
);

// ── MIRROR RULE, updated for R9: the binding form is the ONE deliberate
// write surface on this leaf — assert there is EXACTLY one <form>, and that
// its action is the credential-bind action (never some other, unreviewed
// write surface slipping in unnoticed). ──
ok( 1 === substr_count( $html, '<form' ), 'MIRROR RULE: exactly ONE <form> on the whole leaf (the R9 binding form)' );
ok( 1 === substr_count( $html, '<select' ), 'MIRROR RULE: exactly ONE <select> on the whole leaf' );
ok( 1 === substr_count( $html, '<button' ), 'MIRROR RULE: exactly ONE <button> on the whole leaf' );
ok( false === strpos( $html, '<textarea' ), 'MIRROR RULE: still no <textarea> anywhere in the leaf' );

// ── M1 (IA): bind form + Connect-a-client sit ABOVE both tool lists ──
// Reorder-only increment: the returning-owner job (bind) and the first-run
// job (connect) must appear before the first sn-mcp-tool-list so the owner
// does not scroll past the live slug inventories to reach either. Positions
// are compared against the same full-page $html as every other presence pin.
$bind_pos       = strpos( $html, 'Bind the write-door credential' );
$connect_pos    = strpos( $html, 'Connect a client' );
$first_list_pos = strpos( $html, 'sn-mcp-tool-list' );
ok( false !== $bind_pos && false !== $first_list_pos && $bind_pos < $first_list_pos,
	'M1: binding-form heading appears before the first sn-mcp-tool-list' );
ok( false !== $connect_pos && false !== $first_list_pos && $connect_pos < $first_list_pos,
	'M1: "Connect a client" heading appears before the first sn-mcp-tool-list' );

// ── M2 (IA): both tool inventories fold into closed <details> ──
// Every slug stays in the HTML (the presence pins above iterate the live
// allowlists against the same $html), but the two walls of <li><code> rows sit
// behind explicit closed disclosures whose summaries carry the LIVE counts —
// count() over the allowlist functions, never a hardcoded number.
ok( 2 === substr_count( $html, '<details class="sn-mcp-tools' ), 'M2: exactly two tool-inventory disclosures (read door, write door)' );
ok( false === strpos( $html, '<details class="sn-mcp-tools sn-disclosure" open' ), 'M2: neither inventory disclosure is open by default' );
$read_details_at = strpos( $html, '<details class="sn-mcp-tools' );
$read_summary    = substr( $html, (int) $read_details_at, 300 );
ok( false !== strpos( $read_summary, (string) count( $slugs ) . ' read-only tools' ), 'M2: the read-door summary carries the live read count' );
$rw_details_at = strpos( $html, '<details class="sn-mcp-tools', (int) $read_details_at + 1 );
$rw_summary    = substr( $html, (int) $rw_details_at, 300 );
ok( false !== strpos( $rw_summary, (string) count( $rw_slugs ) . ' read-write tools' ), 'M2: the write-door summary carries the live rw count' );
ok( false !== strpos( $rw_summary, '4 withheld' ), 'M2: the write-door summary names the withheld count beside the tool count' );
// The withheld slugs explain a gap in the WRITE list, so they live inside the
// write-door disclosure — folding them elsewhere would orphan the explanation.
$rw_details_close = strpos( $html, '</details>', (int) $rw_details_at );
$withheld_at      = strpos( $html, 'signal-noise/run-cron-event' );
ok( is_int( $withheld_at ) && is_int( $rw_details_close ) && $rw_details_at < $withheld_at && $withheld_at < $rw_details_close,
	'M2: the withheld slugs sit INSIDE the write-door disclosure' );
// And the read-door slug list sits inside the first disclosure, not before it.
$first_list_in_details = strpos( $html, 'sn-mcp-tool-list' );
$read_details_close    = strpos( $html, '</details>', (int) $read_details_at );
ok( is_int( $first_list_in_details ) && $read_details_at < $first_list_in_details && $first_list_in_details < (int) $read_details_close,
	'M2: the read-door slug list lives inside its fold' );

// ── M3 (IA): the status glance — three cards, above the fold, display-only ──
// The full-page $html above was rendered in the default unbound state with
// the real allowlists loaded, so the glance in it must read: live read count,
// INACTIVE write door, adapter not installed. State-specific wording that
// cannot be driven live in one process (SN_MCP_RW_DISABLED is a constant) is
// pinned through the PURE card builder instead.
ok( function_exists( 'sn_admin_mcp_status_cards' ), 'M3: pure card builder sn_admin_mcp_status_cards() exists' );
$glance_at = strpos( $html, '<div class="sn-glance">' );
ok( is_int( $glance_at ) && is_int( $bind_pos ) && $glance_at < $bind_pos, 'M3: the status glance renders before the binding form' );
ok( false !== strpos( $html, (string) count( $slugs ) . ' tools' ), 'M3: the read-door card counts the live allowlist' );
ok( stripos( $html, 'INACTIVE' ) !== false, 'M3: the unbound write door reads INACTIVE in the glance (same word the binding form uses)' );
ok( stripos( $html, 'Not installed' ) !== false, 'M3: the absent adapter card says Not installed' );

// Pure-state pins: every named write-door state, none inventable live here.
$base_state = array(
	'read_count'     => 38,
	'read_url'       => 'https://example.test/wp-json/signal-noise/v1/mcp',
	'rw_count'       => 36,
	'rw_url'         => 'https://example.test/wp-json/signal-noise/v1/mcp-rw',
	'rw_state'       => 'inactive',
	'rw_name'        => '',
	'rw_last_used'   => 0,
	'adapter_active' => false,
	'adapter_url'    => 'https://example.test/wp-json/mcp/mcp-adapter-default-server',
);
function sn_test_render_status_cards( $state ) {
	ob_start();
	sn_admin_glance_grid( sn_admin_mcp_status_cards( $state ) );
	return ob_get_clean();
}
$killed = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'constant_killed' ) ) );
ok( false !== strpos( $killed, 'SN_MCP_RW_DISABLED' ), 'M3: the constant-killed card NAMES the constant' );
ok( stripos( $killed, 'wp-config' ) !== false, 'M3: and says where it lives (wp-config), display-only' );
ok( stripos( $killed, 'INACTIVE' ) === false, 'M3: constant-killed is NOT presented as unbound/INACTIVE — they are different facts' );
$off = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'option_off' ) ) );
ok( stripos( $off, 'switched off' ) !== false, 'M3: the option kill switch reads as switched off' );
ok( stripos( $off, 'INACTIVE' ) === false, 'M3: option-off is not presented as unbound either' );
$bound = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'bound', 'rw_name' => 'Claude Code', 'rw_last_used' => 1700100000 ) ) );
ok( false !== strpos( $bound, 'Claude Code' ), 'M3: the bound card names the credential' );
// Escaping: the grid esc_html()s card values itself, so the builder must hand
// the name RAW — pre-escaping double-escapes exactly the names this suite's
// $escaping_name fixture exists to catch.
$esc_bound = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'bound', 'rw_name' => $escaping_name, 'rw_last_used' => 0 ) ) );
ok( false !== strpos( $esc_bound, htmlspecialchars( $escaping_name, ENT_QUOTES ) ), 'M3: an escaping-sensitive bound name renders escaped exactly once' );
ok( false === strpos( $esc_bound, '&amp;quot;' ) && false === strpos( $esc_bound, '&amp;#039;' ), 'M3: and never double-escaped' );
ok( false === strpos( $esc_bound, '<admin>' ), 'M3: no raw tag from a credential name reaches the glance' );
$never = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'bound', 'rw_name' => 'Claude Code', 'rw_last_used' => 0 ) ) );
ok( stripos( $never, 'Never used yet' ) !== false, 'M3: unknown last-used stays "Never used yet", never a fake date' );
$unres = sn_test_render_status_cards( array_merge( $base_state, array( 'rw_state' => 'unresolvable' ) ) );
ok( stripos( $unres, 'unresolvable' ) !== false || stripos( $unres, 'no longer matches' ) !== false, 'M3: the unresolvable state is its own named state' );
// Unknown ≠ zero: a missing allowlist function must never read as "0 tools".
$nolist = sn_test_render_status_cards( array_merge( $base_state, array( 'read_count' => null ) ) );
ok( stripos( $nolist, 'allowlist unavailable' ) !== false, 'M3: a missing allowlist prints "allowlist unavailable"' );
ok( false === strpos( $nolist, '0 tools' ), 'M3: and never "0 tools" — unknown is not zero' );
$present = sn_test_render_status_cards( array_merge( $base_state, array( 'adapter_active' => true ) ) );
ok( stripos( $present, 'Present' ) !== false, 'M3: an installed adapter reads Present' );

// The glance is DISPLAY-ONLY: it must add no second write surface. The mirror
// rule above already counts forms/selects/buttons on the full page — re-assert
// here so M3 cannot regress it.
ok( 1 === substr_count( $html, '<form' ), 'M3: still exactly ONE form after the glance landed' );

echo "\n--- $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
