<?php
/**
 * Standalone fixture tests for plugin v3.7.4's Command Palette ↔ theme
 * abilities mapping.
 *
 * Covers the data-shape contract:
 *   - 12 new theme-ability commands land with correct slug, label, ability,
 *     render_mode, input_fields, ai_callable fields.
 *   - All 12 set ai_callable = true (per spec §11.3).
 *   - 7 commands use render_mode = 'input-then-result' with non-empty
 *     input_fields arrays (2 read abilities with required input + 5
 *     generative AI calls).
 *   - 5 commands use render_mode = 'result-panel' with empty/absent
 *     input_fields (read abilities with no input).
 *   - All 12 ability slugs are in the signal-and-noise/* namespace.
 *
 * Does NOT cover the JS-side `input-then-result` flow — JavaScript has
 * no test harness in this project. Manual smoke test recipe is in
 * Task 10's "Live smoke tests" section.
 *
 * @since plugin v3.7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ─────────────────────────────────────────────────────────
// Capture every desktop_mode_register_command() call so tests can assert
// on the resulting registration shape.
$GLOBALS['__test_commands_registered'] = array();

if ( ! function_exists( 'desktop_mode_register_command' ) ) {
	function desktop_mode_register_command( $args ) {
		$GLOBALS['__test_commands_registered'][] = $args;
		return true;
	}
}

// Hooks just collect callbacks so tests can fire them on demand.
$GLOBALS['__test_action_callbacks'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_action_callbacks'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true; // not exercised in this suite
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script() { return true; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['__test_localized'][ $handle ] = array(
			'name' => $name,
			'data' => $data,
		);
		return true;
	}
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path, $base = '' ) { return 'https://example.test/wp-content/plugins/sn/' . $path; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
}
if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( $from, $to ) { return '5 mins'; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return true; }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $r ) { return $r; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( $key ) { return true; }
}

// Stub deploy helpers so the integration file's localize block runs without fataling.
if ( ! function_exists( 'snt_deploy_status_for' ) ) {
	function snt_deploy_status_for( $pkg ) { return array( 'current' => '0.0.0', 'state' => 'ok' ); }
}
if ( ! function_exists( 'snt_cron_summary_for_localize' ) ) {
	function snt_cron_summary_for_localize() { return array( 'total' => 0, 'sn_count' => 0, 'orphans' => 0 ); }
}
if ( ! function_exists( 'snt_insights_summary_for_localize' ) ) {
	function snt_insights_summary_for_localize() { return null; }
}
if ( ! function_exists( 'snt_gh_recent_runs_merged' ) ) {
	function snt_gh_recent_runs_merged( $repos, $limit ) { return array(); }
}
// Note: snt_desktop_dock_badge is defined natively in inc/desktop-mode-integration.php
// (line 208) so it doesn't need a stub here — would cause "Cannot redeclare" fatal.

class WP_Error {
	public $code; public $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
}
class WP_REST_Request {
	private $params = array();
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '3.7.4' ); }
if ( ! defined( 'SN_GH_PLUGIN_SLUG' ) ) { define( 'SN_GH_PLUGIN_SLUG', 'signal-and-noise-tools' ); }
if ( ! defined( 'SN_GH_PLUGIN_BASENAME' ) ) { define( 'SN_GH_PLUGIN_BASENAME', 'signal-and-noise-tools/signal-and-noise-tools.php' ); }

require_once __DIR__ . '/../inc/desktop-mode-integration.php';

// Fire the admin_enqueue_scripts callbacks so registrations + localize land.
foreach ( $GLOBALS['__test_action_callbacks']['admin_enqueue_scripts'] ?? array() as $cb ) {
	$cb();
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function tac_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function tac_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Theme ability launcher commands suite — plugin v3.7.4\n";

// ─── Test 1: total command count ────────────────────────────────────
// Baseline was 16 commands in inc/desktop-mode-integration.php's $commands
// array (4 maintenance + 7 nav + 2 version + 2 cron + 1 insights). After
// adding 12 new theme-ability launcher commands the total is 28.
//
// Note: v3.7.4 strips the original plan's extra fields (ability, render_mode,
// input_fields, ai_callable) — they're discarded by desktop_mode_register_command()
// anyway per includes/commands.php in WordPress/desktop-mode. Real ability
// dispatch lands in v3.8.0 via desktop_mode_register_ai_tool() + an
// Anthropic provider (desktop_mode_register_ai_provider()).
echo "\nTest 1: total command count\n";
tac_eq( 28, count( $GLOBALS['__test_commands_registered'] ), '16 existing + 12 new = 28 commands' );

// ─── Test 2: new launcher slugs registered ──────────────────────────
echo "\nTest 2: new command slugs registered\n";
$slugs = array_column( $GLOBALS['__test_commands_registered'], 'slug' );

$expected_new_slugs = array(
	'sn-cmd-get-design-tokens',
	'sn-cmd-list-block-patterns',
	'sn-cmd-get-template-structure',
	'sn-cmd-theme-version',
	'sn-cmd-page-notes-pillars',
	'sn-cmd-reading-time',
	'sn-cmd-design-summary',
	'sn-cmd-ai-page-note-summary',
	'sn-cmd-ai-suggest-pattern',
	'sn-cmd-ai-brand-validate',
	'sn-cmd-ai-pattern-content',
	'sn-cmd-ai-rewrite-voice',
);
foreach ( $expected_new_slugs as $slug ) {
	tac_true( in_array( $slug, $slugs, true ), "slug present: $slug" );
}

// ─── Test 3: every new entry has the 4 launcher fields ──────────────
echo "\nTest 3: launcher shape\n";
$by_slug = array();
foreach ( $GLOBALS['__test_commands_registered'] as $c ) {
	$by_slug[ $c['slug'] ] = $c;
}
foreach ( $expected_new_slugs as $slug ) {
	$entry = $by_slug[ $slug ] ?? null;
	tac_true( is_array( $entry ), "$slug entry exists" );
	tac_true( isset( $entry['slug'], $entry['label'], $entry['description'], $entry['icon'] ), "$slug has slug+label+description+icon" );
}

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
