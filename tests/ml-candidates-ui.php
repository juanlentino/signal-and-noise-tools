<?php
/**
 * Tests for inc/ml-candidates-ui.php — the editor UI enqueue for the ML
 * keyword/link candidate buttons (v10.19.0). The buttons themselves are JS
 * (assets/ml-candidates-ui.js, DOM-built, transported via window.sntAbilityRun
 * — the transport guard in tests/ability-run-client.php covers path
 * discipline); what PHP owns, and what this fixture pins, is the GATE:
 *   - post.php / post-new.php ONLY (no site-wide admin weight),
 *   - post type 'post' ONLY (the ML corpus is posts; pages never mount),
 *   - edit_posts capability,
 *   - NO AI-availability gate — the kernel is deterministic, so the buttons
 *     must appear even where the AI client is absent. The fixture proves it
 *     structurally: snt_ai_is_available() is never defined here, so an impl
 *     that consulted it would FATAL, not just fail an assert.
 * Run: php tests/ml-candidates-ui.php
 * @since plugin v10.19.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }

$GLOBALS['__actions'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; }
$GLOBALS['__registered'] = array();
$GLOBALS['__enqueued']   = array();
function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['__registered'][ $handle ] = array( 'src' => (string) $src, 'deps' => $deps, 'footer' => $in_footer );
	return true;
}
function wp_enqueue_script( $handle ) { $GLOBALS['__enqueued'][] = $handle; }
$GLOBALS['__translated'] = array();
function wp_set_script_translations( $handle, $domain = 'default' ) { $GLOBALS['__translated'][] = $handle; return true; }
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}
$GLOBALS['__can'] = true;
function current_user_can( $cap ) { return $GLOBALS['__can']; }

require __DIR__ . '/../inc/ml-candidates-ui.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

function reset_enq() {
	$GLOBALS['__registered'] = array();
	$GLOBALS['__enqueued']   = array();
	$GLOBALS['__translated'] = array();
}

echo "Group: registration\n";
ok( isset( $GLOBALS['__actions']['admin_enqueue_scripts'] ) && in_array( 'snt_ml_candidates_ui_enqueue', $GLOBALS['__actions']['admin_enqueue_scripts'], true ), 'enqueue callback hooked to admin_enqueue_scripts' );
ok( array() === $GLOBALS['__enqueued'], 'loading the file enqueues nothing' );

echo "\nGroup: the gate\n";
$GLOBALS['typenow'] = 'post';
$GLOBALS['__can']   = true;

reset_enq();
snt_ml_candidates_ui_enqueue( 'index.php' );
ok( array() === $GLOBALS['__enqueued'], 'non-editor screens never enqueue' );

reset_enq();
snt_ml_candidates_ui_enqueue( 'post.php' );
ok( array( 'snt-ml-candidates-ui' ) === $GLOBALS['__enqueued'], 'post.php + type post + edit_posts enqueues the script' );
ok( isset( $GLOBALS['__registered']['snt-ml-candidates-ui'] ), 'script registered by handle' );
$reg = $GLOBALS['__registered']['snt-ml-candidates-ui'];
ok( false !== strpos( $reg['src'], 'assets/ml-candidates-ui.js' ), 'src points at assets/ml-candidates-ui.js' );
ok( in_array( 'snt-ability-run', $reg['deps'], true ) && in_array( 'snt-status', $reg['deps'], true ) && in_array( 'wp-api-fetch', $reg['deps'], true ) && in_array( 'wp-i18n', $reg['deps'], true ), 'deps carry the ability transport + status + apiFetch + i18n' );
ok( true === $reg['footer'], 'loads in the footer' );
ok( array( 'snt-ml-candidates-ui' ) === $GLOBALS['__translated'], 'script translations set' );

reset_enq();
snt_ml_candidates_ui_enqueue( 'post-new.php' );
ok( array( 'snt-ml-candidates-ui' ) === $GLOBALS['__enqueued'], 'post-new.php also enqueues (new drafts get candidates once saved)' );

reset_enq();
$GLOBALS['typenow'] = 'page';
snt_ml_candidates_ui_enqueue( 'post.php' );
ok( array() === $GLOBALS['__enqueued'], "post type 'page' never mounts — the ML corpus is posts" );

reset_enq();
unset( $GLOBALS['typenow'] );
snt_ml_candidates_ui_enqueue( 'post.php' );
ok( array() === $GLOBALS['__enqueued'], 'absent typenow fails closed — no enqueue without a known post type' );

reset_enq();
$GLOBALS['typenow'] = 'post';
$GLOBALS['__can']   = false;
snt_ml_candidates_ui_enqueue( 'post.php' );
ok( array() === $GLOBALS['__enqueued'], 'no edit_posts capability, no script' );
$GLOBALS['__can'] = true;

echo "\nGroup: no AI gate (structural)\n";
ok( ! function_exists( 'snt_ai_is_available' ), 'fixture never defines snt_ai_is_available — the impl reaching for it would FATAL above, proving the deterministic buttons carry no AI dependency' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
