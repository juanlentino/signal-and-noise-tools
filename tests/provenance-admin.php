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
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals ); }
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

// ── Stubs for the manual-sweep trigger + Worker-version readout (Task 12). ──
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
$GLOBALS['__pv_transients'] = array();
$GLOBALS['__pv_http']       = array(); // captured wp_remote_* calls: [method, url, args]
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['__pv_transients'] ) ? $GLOBALS['__pv_transients'][ $k ] : false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__pv_transients'][ $k ] = $v;
		return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) {
		unset( $GLOBALS['__pv_transients'][ $k ] );
		return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 7; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0, $depth = 512 ) {
		return json_encode( $d, $f, $depth ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) {
		return false; }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['response']['code'] ?? 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'] ?? ''; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__pv_http'][] = array( 'POST', $url, $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode(
			$GLOBALS['__pv_sweep_body'] ?? array( 'ok' => true, 'checked' => 3, 'upgraded' => 2, 'stillPending' => 1 )
		) );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__pv_http'][] = array( 'GET', $url, $args );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode(
			$GLOBALS['__pv_version_body'] ?? array( 'worker' => 'sn-provenance', 'version' => '1.1.0' )
		) );
	}
}

require_once SNT_PATH . 'inc/provenance-core.php';
// Loads the REAL sn_prov_pubkey_b64() (unguarded — never stub it: redeclare
// fatal) so the renderer test drives it via the sn_prov_pubkey_b64 option.
require_once SNT_PATH . 'inc/provenance-webhook.php';
// The redesigned section renders the shared first-glance grid (Task 1). Load the
// REAL helper (unguarded — never stub it: redeclare fatal) so the smoke test can
// assert the .sn-glance markup it emits.
require_once SNT_PATH . 'inc/admin-glance.php';
// The section wraps its fieldsets in the shared two-column shell (main + rail).
// Load the REAL primitive (three tiny echo helpers; never stub — redeclare fatal)
// so the smoke test asserts the real .sn-shell markup and column order.
require_once SNT_PATH . 'inc/admin-shell.php';
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

echo "\nTask 5b: section renderer (house pattern — glance + fieldsets + list table)\n";
// The real sn_prov_pubkey_b64() falls back to get_option( 'sn_prov_pubkey_b64' )
// (no wp-config constant in the harness), so seed the published key there.
$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = 'PUBKEYSMOKE';
ob_start();
sn_admin_render_provenance_section();
$html = ob_get_clean();
ad_true( false !== strpos( $html, 'sn-glance' ), 'renders the first-glance hero grid' );
ad_true( false !== strpos( $html, 'sn-fieldset-h' ), 'renders .sn-fieldset section headings' );
ad_true( false !== strpos( $html, '>System<' ), 'System fieldset heading present' );
ad_true( false !== strpos( $html, 'Genesis anchor' ), 'Genesis anchor fieldset heading present' );
ad_true( false !== strpos( $html, '>Commits<' ), 'Commits fieldset heading present' );
ad_true( false !== strpos( $html, 'wp-list-table' ), 'renders the commits wp-list-table' );
ad_true( false !== strpos( $html, 'sn-prov-live' ), 'renders the aria-live commits tbody' );
ad_true( false !== strpos( $html, 'data-endpoint' ), 'live tbody carries the status data-endpoint' );
ad_true( false !== strpos( $html, 'data-nonce' ), 'live tbody carries the wp_rest data-nonce' );
ad_true( false !== strpos( $html, 'PUBKEYSMOKE' ), 'publishes the Ed25519 public key from the option' );
ad_true( false === strpos( $html, 'sn-card-grid' ), 'no foreign card-grid markup remains' );

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

echo "\nTask 8: redesigned section renderer (house pattern + button + secret safety)\n";
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
ad_true( false !== strpos( $html2, 'sn-glance' ), 'still renders the first-glance hero' );
ad_true( false !== strpos( $html2, 'data-endpoint' ), 'live tbody keeps the status data-endpoint' );
ad_true( false !== strpos( $html2, 'data-nonce' ), 'live tbody keeps the wp_rest data-nonce' );
ad_true( false !== strpos( $html2, 'sn-prov-live' ), 'keeps the aria-live commits region' );
ad_true( false !== strpos( $html2, 'sn-fieldset' ), 'renders the .sn-fieldset block layout' );
ad_true( false !== strpos( $html2, 'wp-list-table' ), 'renders the commits list table' );
ad_true( false !== strpos( $html2, 'PUBKEYSMOKE' ), 'surfaces the public key' );
ad_true( false !== strpos( $html2, 'sn_prov_reanchor' ), 'renders the re-anchor form action' );
ad_true( false !== strpos( $html2, 'sn-status-box' ), 'shows the re-anchor result notice' );
ad_true( false === strpos( $html2, 'SUPERSECRETHMAC' ), 'NEVER echoes the HMAC secret' );

echo "\nTask 9: status labels\n";
ad_eq( 'Pending', sn_prov_admin_status_label( 'pending' ), 'label: pending -> Pending' );
ad_eq( 'Confirmed', sn_prov_admin_status_label( 'confirmed' ), 'label: confirmed -> Confirmed' );
ad_eq( 'Unanchored', sn_prov_admin_status_label( 'unanchored' ), 'label: unanchored -> Unanchored' );
ad_eq( 'Genesis', sn_prov_admin_status_label( 'genesis' ), 'label: genesis -> Genesis' );
ad_eq( 'Unsent', sn_prov_admin_status_label( 'unsent' ), 'label: unsent -> Unsent' );
ad_eq( 'Whatever', sn_prov_admin_status_label( 'whatever' ), 'label: unknown -> ucfirst fallback' );

echo "\nTask 10: two-column shell order (glance hero -> Commits [main] -> System -> Genesis [rail])\n";
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'a', 64 ), 'status' => 'pending', 'date' => '2026-06-30' );
ob_start();
sn_admin_render_provenance_section();
$html3       = ob_get_clean();
$glance_pos  = strpos( $html3, 'sn-glance' );
$main_pos    = strpos( $html3, 'sn-shell__main' );
$rail_pos    = strpos( $html3, 'sn-shell__rail' );
$sys_pos     = strpos( $html3, '>System<' );
$gen_pos     = strpos( $html3, 'Genesis anchor' );
$commits_pos = strpos( $html3, '>Commits<' );
ad_true( false !== strpos( $html3, 'sn-shell' ), 'wraps the fieldsets in the two-column shell' );
ad_true( false !== $main_pos && false !== $rail_pos && $main_pos < $rail_pos, 'shell main column precedes the rail' );
ad_true( false !== $glance_pos && false !== $commits_pos && $glance_pos < $commits_pos, 'glance hero renders before the Commits table' );
ad_true( false !== $commits_pos && false !== $sys_pos && $commits_pos < $sys_pos, 'Commits (main column) precedes System (rail)' );
ad_true( false !== $sys_pos && false !== $gen_pos && $sys_pos < $gen_pos, 'System precedes Genesis within the rail' );
ad_true( false !== strpos( $html3, 'wp-list-table' ), 'Commits renders a wp-list-table' );
ad_true( false !== strpos( $html3, 'Pending' ), 'genesis surfaces the capitalized status label' );

echo "\nTask 11: honest re-anchor fail copy (config-aware)\n";
// Fully configured -> the dispatch reached a deployed Worker that rejected it.
$GLOBALS['__pv_meta']    = array();
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/anchor';
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'SUPERSECRETHMAC';
$GLOBALS['__pv_options']['sn_prov_pubkey_b64']  = 'PUBKEYSMOKE';
$_GET['sn_prov_reanchor'] = 'fail';
ob_start();
sn_admin_render_provenance_section();
$html_fail_cfg = ob_get_clean();
ad_true( false !== strpos( $html_fail_cfg, 'Worker rejected' ), 'configured fail: blames the Worker, not the config' );
ad_true( false === strpos( $html_fail_cfg, 'SN_PROV_* constants' ), 'configured fail: does NOT mention unset constants' );
ad_true( false === strpos( $html_fail_cfg, 'SUPERSECRETHMAC' ), 'configured fail still NEVER echoes the HMAC secret' );

// Missing a constant -> the honest cause is unset SN_PROV_* config.
$GLOBALS['__pv_options'] = array();
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/anchor';
$GLOBALS['__pv_options']['sn_prov_pubkey_b64'] = 'PUBKEYSMOKE';
// hmac deliberately absent.
ob_start();
sn_admin_render_provenance_section();
$html_fail_unset = ob_get_clean();
unset( $_GET['sn_prov_reanchor'] );
ad_true( false !== strpos( $html_fail_unset, 'SN_PROV_* constants' ), 'unconfigured fail: points at the SN_PROV_* constants' );
ad_true( false === strpos( $html_fail_unset, 'Worker rejected' ), 'unconfigured fail: does NOT blame the Worker' );

echo "\nTask 12: manual sweep trigger + Worker version readout\n";
$GLOBALS['__pv_meta']       = array();
$GLOBALS['__pv_options']    = array();
$GLOBALS['__pv_options']['sn_prov_worker_url'] = 'https://worker.example/';
$GLOBALS['__pv_transients'] = array();
unset( $_GET['sn_prov_swept'] );

ob_start();
sn_admin_render_provenance_section();
$html12 = ob_get_clean();
ad_true( false !== strpos( $html12, 'name="action" value="sn_prov_runsweep"' ), 'Commits fieldset renders the sweep trigger form' );
ad_true( false !== strpos( $html12, 'Check for confirmations' ), 'sweep button labelled "Check for confirmations"' );
ad_true( false !== strpos( $html12, '<code>1.1.0</code>' ), 'System fieldset shows the Worker version from /_sn/version' );

// Handler registered.
$registered_sweep = false;
foreach ( $GLOBALS['__pv_actions'] as $pair ) {
	if ( 'admin_post_sn_prov_runsweep' === $pair[0] && 'sn_prov_admin_runsweep_handler' === $pair[1] ) { $registered_sweep = true; }
}
ad_true( $registered_sweep, 'handler hooked on admin_post_sn_prov_runsweep' );

// Gating: no manage_options → wp_die (403), no sweep POST.
$GLOBALS['__pv_can']  = false;
$GLOBALS['__pv_died'] = false;
$GLOBALS['__pv_http'] = array();
try { sn_prov_admin_runsweep_handler(); } catch ( SN_Prov_Test_Halt $e ) { /* die */ }
ad_true( $GLOBALS['__pv_died'], 'sweep handler wp_die()s without manage_options' );
ad_true( 0 === count( array_filter( $GLOBALS['__pv_http'], function ( $c ) { return 'POST' === $c[0]; } ) ), 'no sweep POST fires when the cap check fails' );
$GLOBALS['__pv_can'] = true;

// Happy path: cap + config → one signed sweep POST, redirect with ok flag.
$GLOBALS['__pv_options']['sn_prov_hmac_secret'] = 'shh';
$GLOBALS['__pv_http']     = array();
$GLOBALS['__pv_redirect'] = '';
try { sn_prov_admin_runsweep_handler(); } catch ( SN_Prov_Test_Halt $e ) { /* redirect */ }
ad_true( 1 === count( array_filter( $GLOBALS['__pv_http'], function ( $c ) { return 'POST' === $c[0]; } ) ), 'handler fires exactly one sweep POST' );
ad_true( false !== strpos( $GLOBALS['__pv_redirect'], 'sn_prov_swept=ok' ), 'redirects with sn_prov_swept=ok on success' );

// Notice renders the counts from the stashed transient.
$_GET['sn_prov_swept'] = 'ok';
set_transient( 'sn_prov_sweep_result_' . get_current_user_id(), array( 'ok' => true, 'upgraded' => 2, 'still_pending' => 1 ), 60 );
ob_start();
sn_prov_admin_render_sweep_notice();
$notice = ob_get_clean();
ad_true( false !== strpos( $notice, 'Sweep complete' ), 'notice title "Sweep complete"' );
ad_true( false !== strpos( $notice, '2 proofs newly confirmed' ), 'notice reports the newly-confirmed count' );
ad_true( false === get_transient( 'sn_prov_sweep_result_' . get_current_user_id() ), 'notice is one-shot (transient cleared after render)' );
unset( $_GET['sn_prov_swept'] );

// Worker version is cached — a second read does not re-fetch (already fetched in the render above).
$GLOBALS['__pv_http'] = array();
sn_prov_worker_version();
ad_true( 0 === count( array_filter( $GLOBALS['__pv_http'], function ( $c ) { return 'GET' === $c[0]; } ) ), 'worker version served from cache (no second GET)' );

echo "\nTask 13: re-anchor button disabled once the genesis is anchored\n";
$GLOBALS['__pv_meta']       = array();
$GLOBALS['__pv_options']    = array();
$GLOBALS['__pv_options']['sn_prov_worker_url']  = 'https://worker.example/';
$GLOBALS['__pv_transients'] = array();
unset( $_GET['sn_prov_swept'], $_GET['sn_prov_reanchor'] );

// Confirmed → disabled + hint.
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ] = array( 'root' => str_repeat( 'a', 64 ), 'status' => 'confirmed', 'date' => '2026-07-09' );
ob_start();
sn_admin_render_provenance_section();
$h_conf = ob_get_clean();
ad_true( false !== strpos( $h_conf, 'disabled' ), 'confirmed genesis → re-anchor button is disabled' );
ad_true( false !== strpos( $h_conf, 'Already anchored' ), 'confirmed genesis → shows the "already anchored" hint' );

// Unsent → button active (re-anchor is meaningful), no hint.
$GLOBALS['__pv_options'][ SN_PROV_GENESIS_OPT ]['status'] = 'unsent';
ob_start();
sn_admin_render_provenance_section();
$h_unsent = ob_get_clean();
ad_true( false !== strpos( $h_unsent, 'value="sn_prov_reanchor"' ), 'unsent genesis still renders the re-anchor form' );
ad_true( false === strpos( $h_unsent, 'Already anchored' ), 'unsent genesis → no "already anchored" hint (button active)' );

echo "\nTask 14: last-contact timestamps render in ET, not raw UTC ISO\n";
// A summer instant (EDT, UTC-4): 19:31:58Z → 3:31 PM EDT.
ad_eq( 'Jul 9, 2026 3:31 PM EDT', sn_prov_admin_format_ts( '2026-07-09T19:31:58Z' ), 'summer UTC instant → ET with EDT abbreviation' );
// A winter instant (EST, UTC-5) proves it's DST-aware, not a fixed offset.
ad_eq( 'Jan 15, 2026 2:31 PM EST', sn_prov_admin_format_ts( '2026-01-15T19:31:58Z' ), 'winter UTC instant → ET with EST abbreviation' );
// A calendar date (genesis 'date' = YYYY-MM-DD) has no clock — reformat as-is,
// with NO tz shift (converting midnight across zones would roll it a day back).
ad_eq( 'Jul 9, 2026', sn_prov_admin_format_ts( '2026-07-09' ), 'date-only genesis date → date, no time, no day-shift' );
// Degrade gracefully: empty stays empty, unparseable shows verbatim.
ad_eq( '', sn_prov_admin_format_ts( '' ), 'empty last_contact → empty string' );
ad_eq( 'not-a-date', sn_prov_admin_format_ts( 'not-a-date' ), 'unparseable timestamp → returned verbatim' );

// Integration: the Worker glance card shows the ET-formatted contact, never raw ISO.
$et_sys   = array(
	'worker'  => array( 'reachable' => true, 'last_contact' => '2026-07-09T19:31:58Z' ),
	'genesis' => array(),
	'counts'  => array( 'pending' => 0, 'confirmed' => 0 ),
);
$et_cards = sn_prov_admin_glance_cards( $et_sys );
$worker_card = '';
foreach ( $et_cards as $c ) {
	if ( isset( $c['label'] ) && 'Worker' === $c['label'] ) {
		$worker_card = (string) $c['value'];
	}
}
ad_true( false !== strpos( $worker_card, 'Reachable · Jul 9, 2026 3:31 PM EDT' ), 'Worker card renders "Reachable · <ET time>"' );
ad_true( false === strpos( $worker_card, '2026-07-09T19:31:58Z' ), 'Worker card no longer leaks the raw UTC ISO string' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
