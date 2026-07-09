<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', 'test' );
}

$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_enq']     = array(); // captured wp_enqueue_* handles
$GLOBALS['__pv_routes']  = array(); // captured register_rest_route args
$GLOBALS['__pv_actions'] = array(); // captured add_action( $hook, $cb ) pairs
$GLOBALS['__pv_can']     = true;    // current_user_can( 'manage_options' ) toggle
$GLOBALS['__pv_redirect']        = ''; // last wp_safe_redirect() target
$GLOBALS['__pv_died']            = false; // wp_die() reached
$GLOBALS['__pv_reanchor_called'] = 0;    // sn_prov_genesis_reanchor() invocation count
$GLOBALS['__pv_reanchor_return'] = true; // settable sn_prov_genesis_reanchor() return

// The genesis option constant lives in inc/provenance-genesis.php, which this
// standalone harness deliberately does NOT load (loading it would redeclare its
// unguarded functions). Define the constant to its canonical value so the admin
// module resolves it exactly as production does.
if ( ! defined( 'SN_PROV_GENESIS_OPT' ) ) {
	define( 'SN_PROV_GENESIS_OPT', 'sn_prov_genesis' );
}

// Redirect/die seam: production ends the handler with wp_safe_redirect()+exit
// (or wp_die() on a failed cap). A real exit would kill the test process, so the
// stubs throw a catchable halt AFTER recording — the test wraps the call in
// try/catch and asserts the recorded target, never reaching exit.
class SN_Prov_Test_Halt extends Exception {}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook = '', $cb = null, $prio = 10, $args = 1 ) {
		$GLOBALS['__pv_actions'][] = array( (string) $hook, $cb );
		return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) {
		return $v; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return $GLOBALS['__pv_options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $k, $single = false ) {
		$v = $GLOBALS['__pv_meta'][ $id ][ $k ] ?? null;
		return $single ? ( null === $v ? '' : $v ) : ( null === $v ? array() : array( $v ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $k, $v ) {
		$GLOBALS['__pv_meta'][ $id ][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'get_posts' ) ) {
	// Return every seeded post id that carries the provenance UID meta —
	// mirrors the real meta_key-only query sn_prov_admin_status() runs.
	function get_posts( $args = array() ) {
		$ids = array();
		foreach ( $GLOBALS['__pv_meta'] as $pid => $meta ) {
			if ( isset( $meta[ SN_PROV_UID_META ] ) ) {
				$ids[] = (int) $pid;
			}
		}
		return $ids;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['__pv_can'] ); }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	// Record the route args so the auth gate + handler can be exercised.
	function register_rest_route( $namespace, $route, $args = array() ) {
		$GLOBALS['__pv_routes'][ $namespace . $route ] = $args;
		return true; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $d = null, $s = 200 ) {
			$this->data = $d;
			$this->status = $s; }
	}
}
// Enqueue-gate stubs (Task 5). sn_admin_page_hooks() is NOT loaded in this
// standalone harness (inc/admin-menu.php is never required), so stub it to the
// canonical plugin top-level hook the gate keys off.
if ( ! function_exists( 'sn_admin_page_hooks' ) ) {
	function sn_admin_page_hooks( $set = null ) {
		return array( 'toplevel_page_sn-theme-options' ); }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['__pv_enq'][] = $handle;
		return true; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['__pv_enq'][] = $handle;
		return true; }
}
// Escaping/URL/nonce/i18n stubs for the admin section renderer smoke test.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = 'default' ) {
		return (string) $t; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		return (string) $u; }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . md5( (string) $action ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		return htmlspecialchars( (string) $u, ENT_QUOTES ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
		return $url . $sep . http_build_query( $args ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		$field = '<input type="hidden" name="' . $name . '" value="nonce-' . md5( (string) $action ) . '" />';
		if ( $echo ) {
			echo $field; // phpcs:ignore
		}
		return $field; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( (string) $s ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return $v; }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['__pv_redirect'] = (string) $location;
		throw new SN_Prov_Test_Halt( 'redirect' ); }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		$GLOBALS['__pv_died'] = true;
		throw new SN_Prov_Test_Halt( 'die' ); }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		return true; }
}
// sn_prov_genesis_reanchor() lives in inc/provenance-genesis.php (not loaded
// here). Stub it so the handler test records the call + toggles the return.
if ( ! function_exists( 'sn_prov_genesis_reanchor' ) ) {
	function sn_prov_genesis_reanchor() {
		++$GLOBALS['__pv_reanchor_called'];
		return (bool) $GLOBALS['__pv_reanchor_return']; }
}

require_once SNT_PATH . 'inc/provenance-core.php';
// Loads the REAL sn_prov_pubkey_b64() (unguarded — never stub it: redeclare
// fatal) so the renderer test drives it via the sn_prov_pubkey_b64 option.
require_once SNT_PATH . 'inc/provenance-webhook.php';
require_once SNT_PATH . 'inc/provenance-admin.php';

$pass = 0;
$fail = 0;
function ad_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n";
	}
}
function ad_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "  PASS: $m\n";
	} else {
		++$fail;
		echo "  FAIL: $m\n"; }
}

echo "Provenance admin suite\n\nTask 4: status endpoint\n";
update_post_meta( 11, SN_PROV_UID_META, 'u11' );
update_post_meta( 11, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'content_hash' => 'aa', 'status' => 'pending', 'committed_at' => '2026-07-09T00:00:00Z' ) ) );
$GLOBALS['__pv_options']['sn_prov_genesis'] = array( 'status' => 'pending', 'date' => '2026-07-09' );

$data = sn_prov_admin_status();
ad_true( is_array( $data ) && isset( $data['pending'] ), 'status returns a pending list' );
ad_eq( 1, count( $data['pending'] ), 'one pending commit surfaced' );
ad_eq( 'u11', $data['pending'][0]['note_uid'], 'pending item carries note_uid' );
ad_eq( 'pending', $data['genesis']['status'], 'genesis status included' );

echo "\nTask 4b: REST auth gate + handler\n";
$GLOBALS['__pv_routes'] = array();
sn_prov_admin_register_status_route();
$route = $GLOBALS['__pv_routes']['sn-prov/v1/status'] ?? null;
ad_true( is_array( $route ), 'status route registered under sn-prov/v1/status' );
ad_eq( 'GET', $route['methods'] ?? null, 'route method is GET' );
ad_true( is_callable( $route['permission_callback'] ?? null ), 'permission_callback is callable' );
ad_true( is_callable( $route['callback'] ?? null ), 'callback is callable' );

$GLOBALS['__pv_can'] = true;
ad_eq( true, call_user_func( $route['permission_callback'] ), 'manage_options user passes the gate' );
$GLOBALS['__pv_can'] = false;
ad_eq( false, call_user_func( $route['permission_callback'] ), 'non-manage_options user rejected' );
$GLOBALS['__pv_can'] = true;

$resp = call_user_func( $route['callback'], null );
ad_true( $resp instanceof WP_REST_Response, 'handler returns a WP_REST_Response' );
ad_true( is_array( $resp->data ) && isset( $resp->data['pending'] ), 'handler response data carries a pending list' );

echo "\nTask 5: admin assets gate\n";
$GLOBALS['__pv_enq'] = array();
sn_prov_admin_enqueue( 'toplevel_page_sn-theme-options' );  // a plugin page hook
ad_true( in_array( 'sn-provenance-admin', $GLOBALS['__pv_enq'], true ), 'assets enqueued on the plugin screen' );
$GLOBALS['__pv_enq'] = array();
sn_prov_admin_enqueue( 'edit.php' );
ad_eq( 0, count( $GLOBALS['__pv_enq'] ), 'assets NOT enqueued on foreign screens' );

echo "\nTask 5b: section renderer\n";
// The real sn_prov_pubkey_b64() falls back to get_option( 'sn_prov_pubkey_b64' )
// (no wp-config constant in the harness), so seed the published key there.
$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = 'PUBKEYSMOKE';
ob_start();
sn_admin_render_provenance_section();
$html = ob_get_clean();
ad_true( false !== strpos( $html, 'sn-prov-admin' ), 'renders the sn-prov-admin wrapper' );
ad_true( false !== strpos( $html, 'data-endpoint' ), 'wrapper carries the status data-endpoint' );
ad_true( false !== strpos( $html, 'data-nonce' ), 'wrapper carries the wp_rest data-nonce' );
ad_true( false !== strpos( $html, 'sn-prov-live' ), 'renders the aria-live status region' );
ad_true( false !== strpos( $html, 'PUBKEYSMOKE' ), 'publishes the Ed25519 public key from the option' );

echo "\nTask 6: system status view-model\n";
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
// config: worker_url + pubkey present, hmac deliberately absent.
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/anchor';
$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = 'PUBKEY123';
// chains: one pending, one confirmed, one unanchored (the confirmed one is the
// latest successful dispatch; the unanchored one must NOT count as contact).
update_post_meta( 201, SN_PROV_UID_META, 'u201' );
update_post_meta( 201, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'status' => 'pending', 'committed_at' => '2026-07-01T00:00:00Z' ) ) );
update_post_meta( 202, SN_PROV_UID_META, 'u202' );
update_post_meta( 202, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-07-05T00:00:00Z' ) ) );
update_post_meta( 203, SN_PROV_UID_META, 'u203' );
update_post_meta( 203, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'status' => 'unanchored', 'committed_at' => '2026-07-09T00:00:00Z' ) ) );
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => 'abc123', 'status' => 'pending', 'date' => '2026-06-30' );

$sys = sn_prov_admin_system_status();
ad_eq( true, $sys['config']['worker_url'], 'config.worker_url true when option set' );
ad_eq( false, $sys['config']['hmac'], 'config.hmac false when secret unset' );
ad_eq( true, $sys['config']['pubkey'], 'config.pubkey true when key set' );
ad_eq( 1, $sys['counts']['pending'], 'counts.pending across all chains' );
ad_eq( 1, $sys['counts']['confirmed'], 'counts.confirmed across all chains' );
ad_eq( 1, $sys['counts']['unanchored'], 'counts.unanchored across all chains' );
ad_eq( true, $sys['worker']['reachable'], 'worker inferred reachable when a pending/confirmed commit exists' );
ad_eq( '2026-07-05T00:00:00Z', $sys['worker']['last_contact'], 'worker last_contact is the latest pending/confirmed timestamp' );
ad_eq( 'pending', $sys['genesis']['status'], 'genesis option passed through' );
ad_eq( 'PUBKEY123', $sys['pubkey'], 'public key surfaced (public value, OK to include)' );
ad_true( false !== strpos( (string) $sys['ledger_url'], 'signal-and-noise-provenance' ), 'ledger_url points at the ledger repo' );
ad_true( ! in_array( 'hmac_secret', array_keys( $sys['config'] ), true ), 'config never carries a secret key' );

// Inferred contact is false when EVERY commit is still unanchored.
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
update_post_meta( 301, SN_PROV_UID_META, 'u301' );
update_post_meta( 301, SN_PROV_CHAIN_META, array( array( 'version' => 1, 'status' => 'unanchored', 'committed_at' => '2026-07-09T00:00:00Z' ) ) );
$sys2 = sn_prov_admin_system_status();
ad_eq( false, $sys2['worker']['reachable'], 'worker not reachable when every commit is unanchored' );
ad_eq( '', $sys2['worker']['last_contact'], 'no last_contact when nothing ever dispatched' );
ad_eq( false, $sys2['config']['worker_url'], 'config.worker_url false when option unset' );

echo "\nTask 7: re-anchor handler gating\n";
$registered = false;
foreach ( $GLOBALS['__pv_actions'] as $pair ) {
	if ( 'admin_post_sn_prov_reanchor' === $pair[0] && 'sn_prov_admin_reanchor_handler' === $pair[1] ) {
		$registered = true;
	}
}
ad_true( $registered, 'handler hooked on admin_post_sn_prov_reanchor' );

// Valid nonce + cap → invokes reanchor, redirects with ok.
$GLOBALS['__pv_can']             = true;
$GLOBALS['__pv_reanchor_called'] = 0;
$GLOBALS['__pv_reanchor_return'] = true;
$GLOBALS['__pv_redirect']        = '';
try {
	sn_prov_admin_reanchor_handler();
} catch ( SN_Prov_Test_Halt $e ) {
	unset( $e );
}
ad_eq( 1, $GLOBALS['__pv_reanchor_called'], 'reanchor invoked with cap + valid nonce' );
ad_true( false !== strpos( $GLOBALS['__pv_redirect'], 'sn_prov_reanchor=ok' ), 'redirects with ok result on success' );
ad_true( false !== strpos( $GLOBALS['__pv_redirect'], 'sub=provenance' ), 'redirects back to the Provenance sub-tab' );

// Reanchor failure → redirects with fail.
$GLOBALS['__pv_reanchor_called'] = 0;
$GLOBALS['__pv_reanchor_return'] = false;
$GLOBALS['__pv_redirect']        = '';
try {
	sn_prov_admin_reanchor_handler();
} catch ( SN_Prov_Test_Halt $e ) {
	unset( $e );
}
ad_true( false !== strpos( $GLOBALS['__pv_redirect'], 'sn_prov_reanchor=fail' ), 'redirects with fail result when reanchor fails' );

// No cap → does NOT invoke reanchor (dies 403 first).
$GLOBALS['__pv_can']             = false;
$GLOBALS['__pv_reanchor_called'] = 0;
$GLOBALS['__pv_died']            = false;
try {
	sn_prov_admin_reanchor_handler();
} catch ( SN_Prov_Test_Halt $e ) {
	unset( $e );
}
ad_eq( 0, $GLOBALS['__pv_reanchor_called'], 'reanchor NOT invoked without manage_options' );
ad_true( $GLOBALS['__pv_died'], 'handler dies (403) without the cap' );
$GLOBALS['__pv_can'] = true;

echo "\nTask 8: redesigned section renderer (cards + button + secret safety)\n";
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_options']['sn_prov_pubkey_b64']  = 'PUBKEYSMOKE';
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/anchor';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'SUPERSECRETHMAC';
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'a', 64 ), 'status' => 'pending', 'date' => '2026-06-30' );
$_GET['sn_prov_reanchor'] = 'ok';
ob_start();
sn_admin_render_provenance_section();
$html2 = ob_get_clean();
unset( $_GET['sn_prov_reanchor'] );
ad_true( false !== strpos( $html2, 'sn-prov-admin' ), 'still renders the sn-prov-admin wrapper' );
ad_true( false !== strpos( $html2, 'data-endpoint' ), 'wrapper keeps the status data-endpoint' );
ad_true( false !== strpos( $html2, 'data-nonce' ), 'wrapper keeps the wp_rest data-nonce' );
ad_true( false !== strpos( $html2, 'sn-prov-live' ), 'keeps the aria-live commits region' );
ad_true( false !== strpos( $html2, 'sn-card' ), 'renders the card layout' );
ad_true( false !== strpos( $html2, 'PUBKEYSMOKE' ), 'surfaces the public key' );
ad_true( false !== strpos( $html2, 'sn_prov_reanchor' ), 'renders the re-anchor form action' );
ad_true( false !== strpos( $html2, 'sn-status-box' ), 'shows the re-anchor result notice' );
ad_true( false === strpos( $html2, 'SUPERSECRETHMAC' ), 'NEVER echoes the HMAC secret' );

echo "\nTask 9: status labels + card reflow (9.9.1 design fixes)\n";
ad_eq( 'Pending', sn_prov_admin_status_label( 'pending' ), 'label: pending -> Pending' );
ad_eq( 'Confirmed', sn_prov_admin_status_label( 'confirmed' ), 'label: confirmed -> Confirmed' );
ad_eq( 'Unanchored', sn_prov_admin_status_label( 'unanchored' ), 'label: unanchored -> Unanchored' );
ad_eq( 'Genesis', sn_prov_admin_status_label( 'genesis' ), 'label: genesis -> Genesis' );
ad_eq( 'Unsent', sn_prov_admin_status_label( 'unsent' ), 'label: unsent -> Unsent' );
ad_eq( 'Whatever', sn_prov_admin_status_label( 'whatever' ), 'label: unknown -> ucfirst fallback' );

// Reflow: the Commits card sits OUTSIDE (after) the System+Genesis grid, so the
// grid's closing </div> must appear before the full-width Commits card.
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'a', 64 ), 'status' => 'pending', 'date' => '2026-06-30' );
ob_start();
sn_admin_render_provenance_section();
$html3    = ob_get_clean();
$grid_pos = strpos( $html3, 'sn-card-grid' );
$wide_pos = strpos( $html3, 'sn-prov-card--wide' );
ad_true( false !== $grid_pos && false !== $wide_pos && $wide_pos > $grid_pos, 'Commits card renders after the System+Genesis grid' );
$between = ( false !== $grid_pos && false !== $wide_pos ) ? substr( $html3, $grid_pos, $wide_pos - $grid_pos ) : '';
ad_true( false !== strpos( $between, '</div>' ), 'the card-grid closes before the full-width Commits card' );
ad_true( false !== strpos( $html3, 'Pending' ), 'genesis card uses the capitalized status label' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
