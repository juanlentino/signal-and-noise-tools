<?php
/**
 * Unit tests for the ⌘K command-palette localize payload
 * (inc/command-palette.php).
 *
 * v4.11.0 extends the `sntCommandPalette` localize blob the JS reads so the
 * palette can offer New-Note, six tab-jumps, and recent-Notes commands.
 * This suite exercises the OBSERVABLE behavior: it fires the real
 * admin_enqueue_scripts callback and inspects the captured wp_localize_script
 * data — no shape-only registration assertions.
 *
 * Run: php tests/command-palette-localize.php
 *
 * @since plugin v4.11.0
 */

// SECURITY: CLI-only fixture. Direct HTTP GET would leak internal structure.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ─── Swappable get_term_by behavior ─────────────────────────────────────
// Tests flip this between "term exists" and "unseeded" before re-running the
// enqueue callback.
$GLOBALS['__term_by_slug'] = null; // null => return false (unseeded)

// ─── WP / first-party stubs ─────────────────────────────────────────────
$GLOBALS['__test_action_callbacks'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_action_callbacks'][ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script() { return true; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script() { return true; }
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations() { return true; }
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
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		// Honor the swappable fixture: a term object when seeded, false otherwise.
		return $GLOBALS['__term_by_slug'];
	}
}

// First-party SSOT for the 6 top tabs. Mirror the real shape (slug + label).
if ( ! function_exists( 'sn_admin_top_tabs' ) ) {
	function sn_admin_top_tabs() {
		return array(
			array( 'slug' => 'sn-theme-options', 'label' => 'Dashboard' ),
			array( 'slug' => 'sn-site',          'label' => 'Site' ),
			array( 'slug' => 'sn-security',      'label' => 'Security' ),
			array( 'slug' => 'sn-automation',    'label' => 'Automation' ),
			array( 'slug' => 'sn-monitoring',    'label' => 'Monitoring' ),
			array( 'slug' => 'sn-tools',         'label' => 'Tools' ),
		);
	}
}

if ( ! defined( 'SNT_PATH' ) )    { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '4.11.0' ); }
if ( ! defined( 'SN_NOTES_CATEGORY_SLUG' ) ) { define( 'SN_NOTES_CATEGORY_SLUG', 'notes' ); }

require_once __DIR__ . '/../inc/command-palette.php';

// ─── Helper: fire the enqueue callbacks + return the captured payload ────
function cpl_run_enqueue() {
	$GLOBALS['__test_localized'] = array();
	foreach ( $GLOBALS['__test_action_callbacks']['admin_enqueue_scripts'] ?? array() as $cb ) {
		$cb();
	}
	return $GLOBALS['__test_localized']['snt-command-palette']['data'] ?? null;
}

// ─── Harness ─────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function cpl_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function cpl_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Command palette localize payload suite — plugin v4.11.0\n";

// ─── Test 1: unseeded Notes category → notesCategoryId = 0 ──────────────
echo "\nTest 1: payload shape (unseeded Notes category)\n";
$GLOBALS['__term_by_slug'] = false; // get_term_by returns false
$data = cpl_run_enqueue();

cpl_true( is_array( $data ), 'localize payload captured' );

// Existing keys preserved.
cpl_eq( 'signal-noise/v1', $data['restNamespace'] ?? null, 'restNamespace preserved' );
cpl_eq( 'https://example.test/wp-admin/admin.php?page=sn-theme-options', $data['dashboardUrl'] ?? null, 'dashboardUrl preserved' );

// New keys.
cpl_eq( 'https://example.test/wp-admin/post-new.php', $data['newNoteUrl'] ?? null, 'newNoteUrl => post-new.php' );
cpl_eq( 0, $data['notesCategoryId'] ?? 'MISSING', 'notesCategoryId = 0 when unseeded' );
cpl_true( is_int( $data['notesCategoryId'] ?? null ), 'notesCategoryId is an int' );

// ─── Test 2: tabs array mirrors the SSOT ────────────────────────────────
echo "\nTest 2: tabs array mirrors sn_admin_top_tabs()\n";
cpl_true( isset( $data['tabs'] ) && is_array( $data['tabs'] ), 'tabs is an array' );
cpl_eq( 6, is_array( $data['tabs'] ?? null ) ? count( $data['tabs'] ) : -1, 'six tabs (one per top-level tab)' );

$expected = array(
	array( 'label' => 'Dashboard',  'url' => 'https://example.test/wp-admin/admin.php?page=sn-theme-options' ),
	array( 'label' => 'Site',       'url' => 'https://example.test/wp-admin/admin.php?page=sn-site' ),
	array( 'label' => 'Security',   'url' => 'https://example.test/wp-admin/admin.php?page=sn-security' ),
	array( 'label' => 'Automation', 'url' => 'https://example.test/wp-admin/admin.php?page=sn-automation' ),
	array( 'label' => 'Monitoring', 'url' => 'https://example.test/wp-admin/admin.php?page=sn-monitoring' ),
	array( 'label' => 'Tools',      'url' => 'https://example.test/wp-admin/admin.php?page=sn-tools' ),
);
cpl_eq( $expected, $data['tabs'] ?? null, 'tabs label+url mirror the SSOT in order' );

// ─── Test 3: seeded Notes category → notesCategoryId = term id ──────────
echo "\nTest 3: notesCategoryId resolves to the term id when seeded\n";
$GLOBALS['__term_by_slug'] = (object) array( 'term_id' => 42, 'slug' => 'notes' );
$data2 = cpl_run_enqueue();
cpl_eq( 42, $data2['notesCategoryId'] ?? null, 'notesCategoryId = term_id (42)' );
cpl_true( is_int( $data2['notesCategoryId'] ?? null ), 'seeded notesCategoryId is an int' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
