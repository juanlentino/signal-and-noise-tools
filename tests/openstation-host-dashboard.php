<?php
/**
 * Standalone test: the S&N Dashboard host window (#1074) — its definition,
 * its four actions, its view, and the dock item it took over.
 *
 * The framework is stubbed in its own namespaces with just enough surface to
 * load the `.os.php`, record what it declares and drive it — the same shape
 * tests/openstation-app.php uses for the Signal & Noise app. The ESTATE is
 * not stubbed: inc/admin-tabs-data.php and the three resolvers are loaded, so
 * every "derived from the registry" pin is measured against the real one and
 * a hardcoded label goes red.
 *
 * The dispatcher is a fixture (`sn_admin_render_active_tab`), because what
 * the view has to prove is that the leaf is painted with the query the
 * classic URL would have carried — which a fixture reports and the real
 * dispatcher, needing 35 leaf renderers, cannot.
 *
 * Run: php tests/openstation-host-dashboard.php
 *
 * @since 13.104.0
 */

namespace OpenStation {
	final class App {
		public $id;
		public $title;
		public $icon;
		public $placement;
		public $caps       = array();
		public $state      = array();
		public $actions    = array();
		public $view;
		public $mount;
		public $buttons    = array();
		public $menu_rows  = array();
		public $size       = array();
		public $min        = array();
		public $tabs       = array();
		public static function define( $id ) { $a = new self(); $a->id = $id; return $a; }
		public function title( $t ) { $this->title = $t; return $this; }
		public function icon( $i ) { $this->icon = $i; return $this; }
		public function size( $w, $h ) { $this->size = array( $w, $h ); return $this; }
		public function min_size( $w, $h ) { $this->min = array( $w, $h ); return $this; }
		public function placement( $p ) { $this->placement = $p; return $this; }
		public function capabilities( ...$c ) { $this->caps = $c; return $this; }
		public function state( array $d ) { $this->state = $d; return $this; }
		public function title_bar_button( $id, array $a ) { $this->buttons[ $id ] = $a; return $this; }
		public function window_action( $id, array $a ) { $this->menu_rows[ $id ] = $a; return $this; }
		public function tab( $v, array $a = array() ) { $this->tabs[ $v ] = $a; return $this; }
		public function mount( callable $cb ) { $this->mount = $cb; return $this; }
		public function action( $n, callable $cb ) { $this->actions[ $n ] = $cb; return $this; }
		public function view( callable $cb ) { $this->view = $cb; return $this; }
	}
}

namespace OpenStation\App {
	class State {
		private $values;
		private $defaults;
		public function __construct( array $defaults = array(), array $in = array() ) { $this->defaults = $defaults; $this->values = array_merge( $defaults, $in ); }
		public function get( $k, $f = null ) { return array_key_exists( $k, $this->values ) ? $this->values[ $k ] : $f; }
		/** The framework's own rule (desktop-mode app/class-state.php accept()): coerce onto the default's type, fall back on disagreement. */
		public static function accept( $default, $value ) {
			if ( is_bool( $default ) ) { return is_bool( $value ) ? $value : ( ( is_string( $value ) || is_int( $value ) ) ? in_array( $value, array( '1', 1, 'true', 'on' ), true ) : $default ); }
			if ( is_int( $default ) ) { return is_numeric( $value ) ? (int) $value : $default; }
			if ( is_float( $default ) ) { return is_numeric( $value ) ? (float) $value : $default; }
			if ( is_string( $default ) ) { return is_scalar( $value ) ? (string) $value : $default; }
			if ( is_array( $default ) ) { return is_array( $value ) ? $value : $default; }
			return ( is_scalar( $value ) || is_array( $value ) || null === $value ) ? $value : $default;
		}
		public function set( $k, $v ) { $this->values[ $k ] = array_key_exists( $k, $this->defaults ) ? self::accept( $this->defaults[ $k ], $v ) : $v; return $this; }
		public function reset( $k ) { $this->values[ $k ] = $this->defaults[ $k ] ?? null; return $this; }
		public function all() { return $this->values; }
	}
	/** The runtime's effects queue (openstation includes/framework/app/class-effects.php): add() merges the type in first, all() lists in order. */
	class Effects {
		public $items = array();
		public function add( $type, array $data = array() ) { $this->items[] = array_merge( array( 'type' => (string) $type ), $data ); return $this; }
		public function all() { return $this->items; }
	}
	class Os {
		public $effects;
		public $toasts  = array();
		public $badges  = array();
		public $opened  = array();
		public $menus   = array();
		public $refresh = 0;
		public $params  = array();
		public $view    = '';
		public function param( $key, $fallback = null ) { return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : $fallback; }
		public function toast( $m ) { $this->toasts[] = $m; return $this; }
		public function badge( $n ) { $this->badges[] = $n; return $this; }
		public function open_url( $u, $t = '', $i = '' ) { $this->opened[] = array( $u, $t, $i ); return $this; }
		public function menu( array $items ) { $this->menus[] = $items; return $this; }
		public function refresh_menu() { $this->refresh++; return $this; }
		public function __construct() { $this->effects = new Effects(); }
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
		http_response_code( 404 );
		exit;
	}

	define( 'ABSPATH', '/' );
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
	define( 'SNT_VERSION', '13.104.0' );

	// ── WordPress, flat ───────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	// Registered, not sunk: the dispatcher hooks admin_post_sn_<action> for
	// every table action at load, and the replay fires that hook (#1614).
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function esc_attr__( $s, $d = null ) { return $s; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function wp_kses_post( $s ) { return (string) $s; }
	function _doing_it_wrong( $f, $m, $v ) {}
	function wp_has_noncharacters( string $text ): bool { return false; }
	function wp_kses_uri_attributes() { return array( 'href', 'src', 'action' ); }
	function wp_parse_url( $url, $component = -1 ) { $parts = parse_url( (string) $url ); if ( -1 === $component ) { return $parts; } $keys = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ); $key = $keys[ $component ] ?? ''; return ( '' !== $key && isset( $parts[ $key ] ) ) ? $parts[ $key ] : null; }
	function admin_url( $path = '', $scheme = 'admin' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	function home_url( $path = '', $scheme = null ) { return 'https://example.test' . (string) $path; }
	function plugins_url( $path = '', $plugin = '' ) { return SNT_URL . ltrim( (string) $path, '/' ); }
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
	function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( (string) $text ) ); }
	function wp_slash( $value ) { if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); } return is_string( $value ) ? addslashes( $value ) : $value; }
	function wp_unslash( $value ) { if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); } return is_string( $value ) ? stripslashes( $value ) : $value; }
	$GLOBALS['__caps'] = array( 'manage_options' => true );
	function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function sn_setting( $path, $default = null ) { return $default; }
	// The identity trio reads core's Settings > General (inc/settings-defaults-parts.php).
	function sn_settings_identity_core( $k ) { return ''; }
	function sn_settings_identity_override( $k ) { return ''; }
	if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } } // 21.0.0: the AI leaf reads the stored model lists.
	function get_transient( $key ) { return false; }
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
	function sn_theme_ai_models() { return array(); }
	function sn_theme_ai_vision_models() { return array(); }
	function sn_mask_secret( $value ) { return (string) $value; }
	// One nonce per ACTION: the estate mints four, and a global "valid" would
	// hide the very confusion the pipelines exist to end.
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$minted = array( 'good-nonce' => 'sn_save_identity', 'brief-nonce' => 'sn_morning_brief_save', 'login-nonce' => 'sn_save_login', 'ret-nonce' => 'sn_audit_save_retention', 'ai-nonce' => 'sn_ai_settings_save', 'reads-nonce' => 'sn_scheduled_reads_save', 'remote-nonce' => 'sn_remote_toggle', 'rw-nonce' => 'sn_bind_mcp_rw_credential', 'read-nonce' => 'sn_bind_mcp_read_credential', 'hook-nonce' => 'sn_webhook_add', 'prune-nonce' => 'sn_audit_prune_now', 'prov-nonce' => 'sn_prov_fixture', 'rss-nonce' => 'sn_rss_reset_defaults', 'theme-nonce' => 'sn_save_theme', 'perf-nonce' => 'sn_perf_save', 'key-nonce' => 'sn_keyring_save', 'zen-nonce' => 'sn_zenodo_env_save', 'line-nonce' => 'sn_zenodo_doi_line_save', 'in-nonce' => 'sn_indexnow_save', 'music-nonce' => 'sn_music_save', 'col-nonce' => 'sn_analytics_collector_save', 'excl-nonce' => 'sn_analytics_exclude_save', 'dev-nonce' => 'sn_analytics_owner_device', 'tune-nonce' => 'sn_analytics_tuning_save', 'fun-nonce' => 'sn_analytics_funnels_save', 'mr-nonce' => 'sn_machine_readers_save', 'ins-nonce' => 'sn_save_insights_settings', 'gsc-nonce' => 'sn_gsc_credential_save', 'prop-nonce' => 'sn_gsc_property_save', 'pw-nonce' => 'sn_analytics_publish_window_save', 'ns-nonce' => 'sn_north_star_save' );
		return ( isset( $minted[ $nonce ] ) && $minted[ $nonce ] === $action ) ? 1 : false;
	}
	// The interceptor needs real hook plumbing, a real wp_redirect (which filters
	// its location before sending) and a real wp_die (which picks its handler
	// through a filter). A registration sink here would let the whole seam pass
	// without running.
	$GLOBALS['__actions'] = $GLOBALS['__actions'] ?? array();
	function remove_filter( $hook, $cb, $prio = 10 ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $i => $one ) { if ( $one === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $i ] ); } } return true; }
	function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['__actions'][ $hook ] ); }
	function add_query_arg( ...$args ) {
		$params = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
		$url    = is_array( $args[0] ) ? (string) ( $args[1] ?? '' ) : (string) ( $args[2] ?? '' );
		$parts  = explode( '#', $url, 2 );
		$base   = $parts[0];
		$query  = array();
		if ( false !== strpos( $base, '?' ) ) { list( $base, $qs ) = explode( '?', $base, 2 ); parse_str( $qs, $query ); }
		foreach ( $params as $k => $v ) { if ( null === $v || false === $v ) { unset( $query[ $k ] ); } else { $query[ $k ] = $v; } }
		return $base . ( array() !== $query ? '?' . http_build_query( $query ) : '' ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
	}
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- This IS the nonce check, in core's own shape.
		if ( ! wp_verify_nonce( isset( $_REQUEST[ $query_arg ] ) ? $_REQUEST[ $query_arg ] : '', $action ) ) {
			wp_die( '<h1>Something went wrong.</h1><p>The link you followed has expired.</p>', 'Something went wrong.', array( 'response' => 403 ) );
		}
		return 1;
	}
	function do_action( $hook, ...$args ) { foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
	function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) { $location = apply_filters( 'wp_redirect', $location, $status ); return (bool) $location; }
	function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) { return wp_redirect( $location, $status ); }
	function wp_die( $message = '', $title = '', $args = array() ) { $handler = apply_filters( 'wp_die_handler', '__snt_default_die' ); call_user_func( $handler, $message, $title, $args ); }
	function __snt_default_die( $message = '', $title = '', $args = array() ) { throw new RuntimeException( 'wp_die was not intercepted' ); }

	// Two admin-post handlers in the shape the five real ones have: a nonce
	// check, then either a redirect + exit or a wp_die.
	$GLOBALS['__admin_post_ran'] = '';
	$GLOBALS['__actions']['admin_post_sn_fixture_redirects'][] = static function () {
		// phpcs:ignore WordPress.Security.NonceVerification -- The fixture IS the nonce check.
		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'sn_prov_fixture' ) ) { wp_die( 'The link you followed has expired.' ); }
		$GLOBALS['__admin_post_ran'] = 'sn_fixture_redirects';
		wp_safe_redirect( 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=automation&sub=cron&sn_prov_swept=ok' );
		exit; // Never reached: the interceptor throws from the wp_redirect filter.
	};
	$GLOBALS['__actions']['admin_post_sn_fixture_dies'][] = static function () {
		wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
	};
	// The RSS leaf's Reset handler, in its shape since 2026-11-10: its own
	// admin-post hook, its own nonce, its own redirect carrying ?sn_rss_ok.
	$GLOBALS['__rss_ran'] = false;
	$GLOBALS['__actions']['admin_post_sn_rss_reset_defaults'][] = static function () {
		// phpcs:ignore WordPress.Security.NonceVerification -- The fixture IS the nonce check.
		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'sn_rss_reset_defaults' ) ) { wp_die( 'The link you followed has expired.' ); }
		$GLOBALS['__rss_ran'] = true;
		wp_safe_redirect( 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=rss&sn_rss_ok=reset' );
		exit; // Never reached: the interceptor throws from the wp_redirect filter.
	};
	$GLOBALS['__styles']  = array();
	$GLOBALS['__scripts'] = array();
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__styles'][ $handle ] = $deps; return true; }
	function wp_style_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $handle ] ); }
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) { $GLOBALS['__scripts'][ $handle ] = $deps; return true; }
	function wp_script_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__scripts'][ $handle ] ); }
	function wp_localize_script( $handle, $object_name, $l10n ) { return true; }
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { return true; }

	// The dock badge the app is required to read rather than recount.
	$GLOBALS['__badge'] = 2;
	function snt_desktop_dock_badge() { return (int) $GLOBALS['__badge']; }

	// ── The REAL estate ───────────────────────────────────────────────
	require_once __DIR__ . '/../inc/admin-tabs-data.php';
	require_once __DIR__ . '/../inc/admin-tabs.php';
	require_once __DIR__ . '/../inc/admin-legacy-redirect.php';
	require_once __DIR__ . '/../inc/admin-flash-messages.php';
	require_once __DIR__ . '/../inc/admin-post-handler.php';

	// The dispatcher, as a fixture that reports the query it was painted with.
	$GLOBALS['__painted'] = array();
	function sn_admin_render_active_tab( $active_tab, $active_sub ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Fixture; it exists to report what the superglobals held.
		$GLOBALS['__painted'][] = array( 'tab' => $active_tab, 'sub' => $active_sub, 'get' => $_GET, 'post' => $_POST, 'request' => $_REQUEST );
		// phpcs:enable WordPress.Security.NonceVerification
		echo '<nav class="sn-sub-tabs"><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ) ) . '">Health</a>';
		// A leaf-to-leaf link written with a TOP-TAB slug, which is what
		// sn_admin_tag_page_url() returns and what made Cancel open a second window.
		echo '<a id="lnk-legacy-slug" href="' . esc_url( admin_url( 'admin.php?page=sn-content&tab=content&sub=tags' ) ) . '">Back to Tags</a></nav>';
		echo '<div class="sn-section" id="sn-sec-' . esc_attr( (string) $active_sub ) . '"><form method="post"><input type="hidden" name="action" value="sn_save_identity"></form></div>';
	}
	function sn_handle_save_identity( $post ) {
		$GLOBALS['__handler_calls'][] = $post;
		return 'identity_saved';
	}
	function sn_handle_webhook_add( $post ) { return 'wh_added_abc123'; }
	function sn_handle_save_login( $post ) { return '' === (string) ( $post['login_slug'] ?? '' ) ? 'login_empty' : 'login_failed'; } // both are plain flashes; the real handler is inc/admin-post-actions/system.php
	function sn_handle_audit_save_retention( $post ) { return 'audit_retention_saved'; }
	function sn_handle_morning_brief_save( $post ) { return isset( $post['snt_morning_brief_test'] ) ? 'morning_brief_test_failed' : 'morning_brief_saved'; }
	// Settings > AI's five actions, each answering a real flash code; the real handlers are in inc/admin-post-actions/.
	function sn_handle_ai_settings_save( $post ) { return 'ai_settings_saved'; }
	function sn_handle_scheduled_reads_save( $post ) { return isset( $post['snt_scheduled_reads_now'] ) ? 'scheduled_reads_ran' : 'scheduled_reads_saved'; }
	function sn_handle_remote_toggle( $post ) { return empty( $post['sn_remote_enabled'] ) ? 'remote_disabled' : 'remote_enabled'; }
	function sn_handle_bind_mcp_rw_credential( $post ) { return '' === (string) ( $post['sn_mcp_rw_uuid'] ?? '' ) ? 'mcp_rw_bind_choose' : 'mcp_rw_bound'; }
	function sn_handle_bind_mcp_read_credential( $post ) { return 'mcp_read_bound'; }
	// Settings > Front end's two actions, each answering its real flash code.
	function sn_handle_save_theme( $post ) { return isset( $post['theme_related_count'] ) ? 'theme_saved' : 'theme_unchanged'; }
	function sn_handle_perf_save( $post ) { return 'perf_saved'; }
	// Settings > Accounts and connections' five saves, each answering its real flash code. The value is an obviously fake string.
	function sn_handle_keyring_save( $post ) { return '' === (string) ( $post['key_value'] ?? '' ) ? 'keyring_unchanged' : 'keyring_saved'; }
	function sn_handle_zenodo_env_save( $post ) { return 'zenodo_env_saved'; }
	function sn_handle_zenodo_doi_line_save( $post ) { return 'zenodo_doi_line_saved'; }
	function sn_handle_indexnow_save( $post ) { return 'indexnow_saved'; }
	function sn_handle_music_save( $post ) { return 'music_saved'; }
	// Settings > Analytics' six saves and the Accounts pane's two Search Console saves (2026-11), each answering its real flash code.
	function sn_handle_analytics_collector_save( $post ) { return 'analytics_collector_saved'; }
	function sn_handle_analytics_exclude_save( $post ) { return 'analytics_exclude_saved'; }
	function sn_handle_analytics_tuning_save( $post ) { return 'analytics_tuning_saved'; }
	function sn_handle_analytics_funnels_save( $post ) { return 'analytics_funnels_saved'; }
	function sn_handle_machine_readers_save( $post ) { return 'machine_readers_saved'; }
	function sn_handle_save_insights_settings( $post ) { return 'insights_settings_saved'; }
	function sn_handle_gsc_credential_save( $post ) { return '' === (string) ( $post['sn_gsc_credential'] ?? '' ) ? 'gsc_credential_unchanged' : 'gsc_credential_saved'; }
	function sn_handle_gsc_property_save( $post ) { return 'gsc_property_saved'; }
	function sn_handle_analytics_publish_window_save( $post ) { return 'analytics_publish_window_saved'; }
	function sn_handle_north_star_save( $post ) { return 'north_star_saved'; }
	function sn_handle_analytics_owner_device( $post ) { return 'analytics_exclude_saved'; }
	$GLOBALS['__handler_calls'] = array();

	// The rewrite half of the view needs core's WP_HTML_Tag_Processor. Found on
	// a machine with WordPress (SNT_WP_HTML_API, or the wp-includes/ above a
	// normally-installed plugin); the two pins that read rewritten markup SKIP
	// where it is absent, rather than passing on markup nothing rewrote.
	$GLOBALS['__html_api'] = '';
	foreach ( array( (string) getenv( 'SNT_WP_HTML_API' ), dirname( __DIR__, 4 ) . '/wp-includes/html-api' ) as $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( '' !== $dir && is_file( $dir . '/class-wp-html-tag-processor.php' ) ) {
			$GLOBALS['__html_api'] = $dir;
			break;
		}
	}
	if ( '' !== $GLOBALS['__html_api'] ) {
		require_once dirname( $GLOBALS['__html_api'] ) . '/class-wp-token-map.php';
		foreach ( array( 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-attribute-token', 'html5-named-character-references', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $part ) {
			require_once $GLOBALS['__html_api'] . '/' . $part . '.php';
		}
	}

	require_once __DIR__ . '/../inc/openstation-host.php';
	$app = require __DIR__ . '/../apps/sn-dashboard/sn-dashboard.os.php';

	$pass = 0;
	$fail = 0;
	$skip = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function skip( $m ) { global $skip; $skip++; echo "SKIP: $m\n"; }
	/**
	 * Paint the window for a partial state.
	 *
	 * `$carry` paints an EXISTING State rather than a fresh one, because the
	 * view spends the one-paint post bag by writing to state, and the runtime
	 * reads state back AFTER the render — so a fresh State would hide it.
	 */
	function paint( $app, array $state, $os = null, $carry = null ) {
		$st  = $carry instanceof \OpenStation\App\State ? $carry : new \OpenStation\App\State( $app->state, $state );
		$os  = $os ?: new \OpenStation\App\Os();
		$tab = (string) ( $state['tab'] ?? '' );
		if ( '' !== $tab && '' === (string) $os->view ) {
			$os->view = 'dashboard' === $tab ? 'main' : $tab;
		}
		$tab = \SignalNoise\OpenStationHost\Dashboard\current_tab( $os );
		$cb  = ( 'dashboard' === $tab ) ? $app->view : ( $app->tabs[ $tab ]['view'] ?? $app->view );
		ob_start();
		call_user_func( $cb, $st, $os );
		return (string) ob_get_clean();
	}

	echo "openstation-host-dashboard -- the S&N Dashboard window (#1074)\n";

	echo "\nGroup 1: the definition\n";
	ok( $app instanceof \OpenStation\App && 'sn-dashboard' === $app->id,
		'the file returns an App under the id the dock item used to carry -- one id, one thing' );
	ok( 'S&N Home' === $app->title && SNT_URL . 'assets/icons/sn-home.svg?ver=' . SNT_VERSION === $app->icon && 'dock' === $app->placement,
		'the same title and dock placement the manual item had, wearing its own S&N Home icon (assets/icons, not a theme slot)' );
	ok( array( 'manage_options' ) === $app->caps, 'gated on manage_options, the capability the classic page wp_die()s without' );
	ok( array( 1180, 820 ) === $app->size && array( 360, 360 ) === $app->min, 'keeps the desktop opening size while allowing narrow and short 360x360 windows' );
	ok( array( 'sub', 'anchor', 'flash', 'notice', 'params', 'post', 'filter', 'saved', 'answered', 'landed' ) === array_keys( $app->state )
		&& '' === $app->state['sub'] && null === $app->state['notice'] && array() === $app->state['params'] && array() === $app->state['post'] && '' === $app->state['filter'] && false === $app->state['saved'] && '' === $app->state['answered'] && '' === $app->state['landed'],
		'state has NO tab: the tab is the session (the framework`s); only the leaf, the anchor, the last write, a leaf`s text filter (#1604), a one-paint "saved" and the params last read (2026-10-12) are state' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen', 'poll', 'cron_run', 'cron_unschedule' ) === array_keys( $app->actions ),
		'eight actions: go, post, door, refresh, the reopen lifecycle, the os-poll tick (#1607) and the Cron leaf`s two controls (#1604)' );
	ok( isset( $app->buttons['refresh'] ) && 'refresh' === $app->buttons['refresh']['action'], 'a Refresh button in the title bar' );

	echo "\nGroup 2: the framework tabs are the registry, not a list kept here\n";
	$expected_tabs = array();
	foreach ( sn_admin_top_tabs() as $top ) {
		if ( 'dashboard' !== $top['tab'] ) {
			$expected_tabs[ $top['tab'] ] = $top['label'];
		}
	}
	ok( array_keys( $expected_tabs ) === array_keys( $app->tabs ),
		'one framework tab per registry tab after Dashboard, in registry order' );
	ok( array_values( $expected_tabs ) === array_column( $app->tabs, 'label' ),
		'   ...with the registry\'s own labels' );
	ok( is_callable( $app->view ) && is_callable( reset( $app->tabs )['view'] ),
		'   ...each tab a view callable; Dashboard is the main view' );

	echo "\nGroup 3: mount and reopen read the open-time params\n";
	$os = new \OpenStation\App\Os();
	$os->view   = 'monitoring';
	// The params a link carries name its tab (assets/os-settings-tab.js passes
	// the classic URL's `tab`); a link that names none is the Dashboard's.
	$os->params = array( 'tab' => 'monitoring', 'sub' => 'health' );
	$st = new \OpenStation\App\State( $app->state );
	call_user_func( $app->mount, $st, $os );
	ok( 'health' === $st->get( 'sub' ), 'a deep link opens on its leaf; the tab is the session' );
	ok( array( 2 ) === $os->badges, 'mount sets the badge from snt_desktop_dock_badge() -- the same function the dock item read' );
	ok( array() === $os->menus, '   ...and never pops a context menu: $os->menu() opens one AT THE POINTER, which is not a window menu' );

	$os->view   = 'connections';
	$os->params = array( 'tab' => 'connections', 'sub' => 'cloudflare' );
	$app->actions['reopen']( $st, $os, array() );
	ok( 'cloudflare' === $st->get( 'sub' ),
		'reopen retargets a live window onto the leaf the params name' );

	$GLOBALS['__badge'] = 0;
	$app->actions['reopen']( $st, $os, array() );
	ok( 0 === end( $os->badges ), 'the badge follows the function, not a remembered number' );
	$GLOBALS['__badge'] = 2;

	echo "\nGroup 3b: a link lands its tab, once (2026-10-12)\n";
	// Measured live: every tab's session reads the params, nothing showed the
	// tab they named, and a dock click (a reopen that hands back the LAST
	// params) snapped every tab to the last link's leaf.
	$land = static function ( $os ) {
		return count( array_filter( $os->effects->all(), static function ( $e ) { return 'snt-land' === $e['type']; } ) );
	};
	$link = array( 'tab' => 'monitoring', 'sub' => 'health', 'land' => 'a1' );
	$sessions = array();
	foreach ( array( 'main', 'monitoring', 'connections' ) as $view ) {
		$o = new \OpenStation\App\Os();
		$o->view   = $view;
		$o->params = $link;
		$s = new \OpenStation\App\State( $app->state );
		call_user_func( $app->mount, $s, $o );
		$sessions[ $view ] = array( $s, $o );
	}
	ok( 'health' === $sessions['monitoring'][0]->get( 'sub' ) && 1 === $land( $sessions['monitoring'][1] ),
		'a cold open lands the named tab`s session on its leaf and queues ONE snt-land, which assets/os-kit.js turns into the visible tab' );
	ok( 0 === $land( $sessions['main'][1] ) && 0 === $land( $sessions['connections'][1] ) && 'cloudflare' === $sessions['connections'][0]->get( 'sub' ),
		'   ...every other tab opens on its first leaf and queues nothing, so only one tab asks to be shown' );

	list( $s, $o ) = $sessions['connections'];
	$s->set( 'sub', 'cron' );
	$o->params = array( 'tab' => 'monitoring', 'sub' => 'health', 'land' => 'b2' );
	$app->actions['reopen']( $s, $o, array() );
	ok( 'cron' === $s->get( 'sub' ) && 0 === $land( $o ),
		'a link to another tab leaves this tab on the leaf the reader chose (it used to reset every tab to its first leaf)' );

	list( $s, $o ) = $sessions['monitoring'];
	$s->set( 'sub', 'rss' );
	$o->effects = new \OpenStation\App\Effects();
	$app->actions['reopen']( $s, $o, array() );
	ok( 'rss' === $s->get( 'sub' ) && 0 === $land( $o ),
		'a dock click (a reopen carrying the params already read) moves nothing and shows nothing' );
	$o->params = array( 'tab' => 'monitoring', 'sub' => 'health', 'land' => 'c3' );
	$app->actions['reopen']( $s, $o, array() );
	ok( 'health' === $s->get( 'sub' ) && 1 === $land( $o ),
		'   ...while the same link clicked again (a new stamp) lands again and shows its tab' );

	$o = new \OpenStation\App\Os();
	$o->view   = 'main';
	$o->params = array( 'land' => 'd4' );
	call_user_func( $app->mount, new \OpenStation\App\State( $app->state ), $o );
	$o2 = new \OpenStation\App\Os();
	$o2->view   = 'monitoring';
	$o2->params = array( 'land' => 'd4' );
	call_user_func( $app->mount, new \OpenStation\App\State( $app->state ), $o2 );
	ok( 1 === $land( $o ) && 0 === $land( $o2 ), 'a link that names no tab is the Dashboard`s, as on the classic page' );
	$o = new \OpenStation\App\Os();
	$o->view   = 'connections';
	$o->params = array( 'tab' => 'cron', 'land' => 'e5' );
	$s = new \OpenStation\App\State( $app->state );
	call_user_func( $app->mount, $s, $o );
	ok( 'cron' === $s->get( 'sub' ) && 1 === $land( $o ), 'a legacy tab lands where the classic 301 sends it (the retired Cron tab is Connections` Cron leaf)' );
	ok( array( array( 'type' => 'snt-land', 'land' => 'e5' ) ) === array_values( array_filter( $o->effects->all(), static function ( $e ) { return 'snt-land' === $e['type']; } ) ),
		'snt-land carries the link`s stamp, so assets/os-kit.js shows the tab once per link and a reload (the shell keeps the params, not the tab) does not pull the window back' );
	$o = new \OpenStation\App\Os();
	$o->view   = 'main';
	$o->params = array( 'tab' => 'no-such-tab', 'land' => 'f6' );
	call_user_func( $app->mount, new \OpenStation\App\State( $app->state ), $o );
	$o2 = new \OpenStation\App\Os();
	$o2->view   = 'monitoring';
	$o2->params = array( 'tab' => 'no-such-tab', 'land' => 'f6' );
	call_user_func( $app->mount, new \OpenStation\App\State( $app->state ), $o2 );
	ok( 1 === $land( $o ) && 0 === $land( $o2 ), 'a tab the window does not have lands on the Dashboard, the classic page`s fallback' );

	echo "\nGroup 4: go\n";
	$os->view = 'monitoring';
	$st = new \OpenStation\App\State( $app->state, array( 'notice' => array( 'success', 'x' ), 'flash' => 'identity_saved' ) );
	$app->actions['go']( $st, $os, array( 'sub' => 'health', 'anchor' => 'sn-sec-health' ) );
	ok( 'health' === $st->get( 'sub' ) && 'sn-sec-health' === $st->get( 'anchor' ) && null === $st->get( 'notice' ) && '' === $st->get( 'flash' ),
		'go lands on the leaf and drops the last save\'s notice -- a notice is a one-shot on the classic page too' );
	$os->view = 'dashboard';
	$app->actions['go']( $st, $os, array( 'sub' => 'no-such-leaf' ) );
	ok( '' === $st->get( 'sub' ), 'a landing tab has no leaf' );
	$os->view = 'monitoring';
	$app->actions['go']( $st, $os, array() );
	ok( 'analytics' === $st->get( 'sub' ), 'a tab with no leaf named lands on its FIRST leaf -- what sn_admin_resolve_active_sub() does with a bare ?tab=' );
	// The WIRE shape, not the already-parsed one: the field is `name="sn_tag_from[]"`
	// (inc/tag-consolidation-admin.php:127,148) and the runtime keys FormData by
	// the literal name. Fed un-bracketed, this pin passed against a `go` that
	// dropped the sources entirely and a merge preview that always said
	// "Nothing to merge".
	$os->view = 'content';
	$app->actions['go']( $st, $os, array( 'values' => array( 'sub' => 'tags', 'sn_tag_preview' => '1', 'sn_tag_from[]' => '4', 'junk' => 'x' ) ) );
	ok( 'tags' === $st->get( 'sub' ) && array( 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4' ) ) === $st->get( 'params' ),
		'a GET form dispatches through go with its FIELDS, expanded the way PHP would have -- the Tags merge preview, whose params are state on the classic page' );
	$app->actions['go']( $st, $os, array( 'values' => array( 'tab' => 'content', 'sub' => 'tags', 'sn_tag_preview' => '1', 'sn_tag_from[]' => array( '4', '9' ) ) ) );
	ok( array( '4', '9' ) === $st->get( 'params' )['sn_tag_from'],
		'   ...two ticked sources arrive as a JS array under the bracketed name and become one PHP list' );
	$app->actions['go']( $st, $os, array( 'tab' => 'monitoring', 'sn_worker_recheck' => '1', '_wpnonce' => 'abc123' ) );
	ok( array( 'sn_worker_recheck' => '1', '_wpnonce' => 'abc123' ) === $st->get( 'params' ),
		'   ...and a nonce-gated GET link carries its _wpnonce into state, where the capture lends it back as $_GET -- sn_worker_version_recheck_requested() needs both' );
	$app->actions['go']( $st, $os, array( 'tab' => 'monitoring' ) );
	ok( array() === $st->get( 'params' ),
		'   ...while the NEXT navigation clears it: params are set wholesale, so a later click cannot silently re-run the re-check' );
	$os->view = 'monitoring';
	$st->set( 'sub', 'health' );
	$GLOBALS['__caps']['manage_options'] = false;
	$app->actions['go']( $st, $os, array( 'sub' => 'rss' ) );
	ok( 'health' === $st->get( 'sub' ), 'go re-checks manage_options -- the window gate is not the only gate' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 5: post\n";
	// Settings > General (Site > Identity & SEO until 2026-11): the one
	// save_identity form, answered on its pane under the settings tab.
	$os = new \OpenStation\App\Os();
	$os->view = 'settings';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'general', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_save_identity', 'site_name' => 'Signal' ) ) );
	ok( 1 === count( $GLOBALS['__handler_calls'] ) && 'Signal' === $GLOBALS['__handler_calls'][0]['site_name'], 'the handler ran with the posted values' );
	ok( array( 'success', 'Identity settings saved.' ) === $st->get( 'notice' ) && 'identity_saved' === $st->get( 'flash' ),
		'the flash becomes the classic notice, and the CODE is kept (the Webhooks leaf reads an id out of it)' );
	ok( array( 'Identity settings saved.' ) === $os->toasts, '   ...and the same sentence is toasted, as plain text' );
	ok( 'general' === $st->get( 'sub' ) && array() === $st->get( 'params' ),
		'the redirect target is applied to this tab`s session, and the query params are dropped -- the classic redirect keeps only page/tab/sub/sn_flash' );
	ok( array( 2 ) === $os->badges && 1 === $os->refresh, 'a save re-reads the badge and asks the shell for a fresh payload' );
	ok( true === $st->get( 'saved' ) && 'sn_save_identity' === $st->get( 'answered' ), 'a save that took marks the next paint saved and names the form it answers, so the host lets only that form\'s typing go' );

	// Settings > Notifications and reports: a save or a test from the pane is
	// answered ON the pane. The tab is the session's (settings), the redirect
	// target keeps its sub, and the flash is the notice, success or failure.
	$os2 = new \OpenStation\App\Os();
	$os2->view = 'settings';
	$st2 = new \OpenStation\App\State( $app->state, array( 'sub' => 'notifications' ) );
	$app->actions['post']( $st2, $os2, array( 'values' => array( '_wpnonce' => 'brief-nonce', 'action' => 'sn_morning_brief_save', 'snt_morning_brief_enabled' => true, 'snt_alerts_enabled' => false ) ) );
	ok( 'notifications' === $st2->get( 'sub' ) && array( 'success', 'Morning brief and alert settings saved.' ) === $st2->get( 'notice' ) && true === $st2->get( 'saved' ),
		'a save from Settings > Notifications and reports lands back on that pane with its saved notice' );
	$app->actions['post']( $st2, $os2, array( 'values' => array( '_wpnonce' => 'brief-nonce', 'action' => 'sn_morning_brief_save', 'snt_morning_brief_test' => '1' ) ) );
	ok( 'notifications' === $st2->get( 'sub' ) && array( 'error', 'Test morning operations brief could not be sent.' ) === $st2->get( 'notice' ) && false === $st2->get( 'saved' ),
		'   ...and a failed test send stays there too, with its failed notice' );

	// Settings > Security: both of its saves are answered on the pane.
	$st3 = new \OpenStation\App\State( $app->state, array( 'sub' => 'security' ) );
	$app->actions['post']( $st3, $os2, array( 'values' => array( '_wpnonce' => 'ret-nonce', 'action' => 'sn_audit_save_retention', 'audit_retention_days' => '30' ) ) );
	ok( 'security' === $st3->get( 'sub' ) && array( 'success', 'Audit retention saved.' ) === $st3->get( 'notice' ) && true === $st3->get( 'saved' ),
		'a retention save from Settings > Security lands back on that pane with its saved notice' );
	$app->actions['post']( $st3, $os2, array( 'values' => array( '_wpnonce' => 'login-nonce', 'action' => 'sn_save_login', 'login_slug' => '' ) ) );
	ok( 'security' === $st3->get( 'sub' ) && array( 'error', 'Login slug cannot be empty.' ) === $st3->get( 'notice' ) && false === $st3->get( 'saved' ),
		'   ...and a refused slug save stays there too, with its notice' );
	$app->actions['post']( $st3, $os2, array( 'values' => array( '_wpnonce' => 'stale', 'action' => 'sn_save_login', 'login_slug' => 'x' ) ) );
	ok( 'security' === $st3->get( 'sub' ) && false === $st3->get( 'saved' ) && 'error' === ( $st3->get( 'notice' )[0] ?? '' ),
		'   ...and a slug save with a wrong nonce is refused on the pane, as it was on the old leaf' );
	// Settings > AI: each of its five saves is answered on the pane, sub=ai
	// under the settings tab, never on the AI tab or the leaf the setting
	// came from, with the notice the handler's flash names.
	$st5 = new \OpenStation\App\State( $app->state, array( 'sub' => 'ai' ) );
	foreach ( array(
		array( 'ai-nonce', 'sn_ai_settings_save', array( 'theme_ai_monthly_budget' => '50' ), 'success', true ),
		array( 'reads-nonce', 'sn_scheduled_reads_save', array( 'snt_scheduled_reads_enabled' => '1' ), 'success', true ),
		array( 'remote-nonce', 'sn_remote_toggle', array( 'sn_remote_enabled' => '1' ), 'success', true ),
		array( 'rw-nonce', 'sn_bind_mcp_rw_credential', array( 'sn_mcp_rw_uuid' => 'a' ), 'success', true ),
		array( 'rw-nonce', 'sn_bind_mcp_rw_credential', array( 'sn_mcp_rw_uuid' => '' ), 'error', false ),
		array( 'read-nonce', 'sn_bind_mcp_read_credential', array( 'sn_mcp_read_uuid' => 'b' ), 'success', true ),
		array( 'stale', 'sn_remote_toggle', array( 'sn_remote_enabled' => '1' ), 'error', false ),
	) as $case ) {
		$app->actions['post']( $st5, $os2, array( 'values' => array( '_wpnonce' => $case[0], 'action' => $case[1] ) + $case[2] ) );
		ok( 'ai' === $st5->get( 'sub' ) && $case[3] === ( $st5->get( 'notice' )[0] ?? '' ) && '' !== (string) ( $st5->get( 'notice' )[1] ?? '' ) && $case[4] === $st5->get( 'saved' ),
			"Settings > AI: $case[1] (nonce $case[0]) lands back on the pane with its " . $case[3] . ' notice' );
	}

	// Settings > Front end: both of its saves are answered on the pane, sub
	// front-end under the settings tab, never on the Site tab the leaf left.
	$st6 = new \OpenStation\App\State( $app->state, array( 'sub' => 'front-end' ) );
	foreach ( array(
		array( 'theme-nonce', 'sn_save_theme', array( 'theme_related_count' => '4' ), array( 'success', 'Front-end settings saved.' ), true ),
		array( 'perf-nonce', 'sn_perf_save', array( 'speculative_loading' => '1' ), array( 'success', 'Performance settings saved.' ), true ),
		array( 'stale', 'sn_perf_save', array( 'speculative_loading' => '1' ), null, false ),
	) as $case ) {
		$app->actions['post']( $st6, $os2, array( 'values' => array( '_wpnonce' => $case[0], 'action' => $case[1] ) + $case[2] ) );
		ok( 'front-end' === $st6->get( 'sub' ) && ( null === $case[3] ? 'error' === ( $st6->get( 'notice' )[0] ?? '' ) : $case[3] === $st6->get( 'notice' ) ) && $case[4] === $st6->get( 'saved' ),
			"Settings > Front end: $case[1] (nonce $case[0]) lands back on the pane with its notice" );
	}
	// Settings > Accounts and connections: each of its five saves is answered
	// on the pane, sub accounts under the settings tab, never on the
	// Connections leaf the form came from, and its notice never repeats the
	// value that was posted.
	$st8 = new \OpenStation\App\State( $app->state, array( 'sub' => 'accounts' ) );
	foreach ( array(
		array( 'key-nonce', 'sn_keyring_save', array( 'key_id' => 'cf_token', 'key_value' => 'fake-value-for-test-0000' ), array( 'success', 'Credentials saved. Press Verify all to check them.' ), true ),
		array( 'zen-nonce', 'sn_zenodo_env_save', array( 'zenodo_env' => 'sandbox', 'zenodo_ledger_doi' => '' ), 'success', true ),
		array( 'line-nonce', 'sn_zenodo_doi_line_save', array( 'zenodo_doi_line_enabled' => '1', 'zenodo_doi_line_label' => '', 'zenodo_doi_line_floor' => '0' ), 'success', true ),
		array( 'in-nonce', 'sn_indexnow_save', array( 'indexnow_enabled' => '1' ), 'success', true ),
		array( 'music-nonce', 'sn_music_save', array( 'sn_muso_profile' => 'profile-fake-1' ), 'success', true ),
		array( 'stale', 'sn_keyring_save', array( 'key_id' => 'cf_token', 'key_value' => 'fake-value-for-test-0000' ), 'error', false ),
	) as $case ) {
		$os8 = new \OpenStation\App\Os(); $os8->view = 'settings';
		$app->actions['post']( $st8, $os8, array( 'values' => array( '_wpnonce' => $case[0], 'action' => $case[1] ) + $case[2] ) );
		$notice = (array) $st8->get( 'notice' );
		ok( 'accounts' === $st8->get( 'sub' ) && ( is_array( $case[3] ) ? $case[3] === $notice : $case[3] === ( $notice[0] ?? '' ) && '' !== (string) ( $notice[1] ?? '' ) ) && $case[4] === $st8->get( 'saved' ) && false === strpos( implode( ' ', $notice ) . implode( ' ', $os8->toasts ), 'fake-value' ),
			"Settings > Accounts and connections: $case[1] (nonce $case[0]) lands back on the pane with its notice, which does not repeat the value" );
	}
	// Settings > Analytics (2026-11): each save on the shared dispatcher (the
	// feed tracking save has its own hook: tests/rss-tracker-admin-post.php) is answered on the
	// pane, sub analytics under the settings tab, never on the Measurement leaf
	// the form came from (the Worker URL form named that leaf in hidden fields
	// until it moved), and no notice repeats a posted URL.
	$st10 = new \OpenStation\App\State( $app->state, array( 'sub' => 'analytics' ) );
	foreach ( array(
		array( 'col-nonce', 'sn_analytics_collector_save', array( 'sn_an_collector_url' => 'https://fake-collector.example.test/_sn/px' ), 'success', true ),
		array( 'excl-nonce', 'sn_analytics_exclude_save', array( 'sn_exclude_roles[0]' => 'administrator', 'sn_exclude_roles[1]' => '' ), 'success', true ),
		array( 'tune-nonce', 'sn_analytics_tuning_save', array( 'sn_signal_baseline_days' => '45', 'sn_anomaly_sensitivity' => 'strict' ), 'success', true ),
		array( 'fun-nonce', 'sn_analytics_funnels_save', array( 'sn_funnels' => 'Home flow: / > /notes/' ), 'success', true ),
		array( 'mr-nonce', 'sn_machine_readers_save', array( 'sn_mr_worker_url' => 'https://fake-sensor.example.test' ), 'success', true ),
		array( 'ins-nonce', 'sn_save_insights_settings', array( 'insights_weekly_cron' => '1' ), 'success', true ),
		array( 'pw-nonce', 'sn_analytics_publish_window_save', array( 'sn_publish_window_minutes' => '90' ), 'success', true ),
		array( 'ns-nonce', 'sn_north_star_save', array( 'sn_nsm_sections[0]' => 'notes', 'sn_nsm_scroll' => '60', 'sn_nsm_dwell_s' => '45' ), 'success', true ),
		// This device, on the pane since 2026-11 (it was on Measurement > Analytics): a press lands back on the pane.
		array( 'dev-nonce', 'sn_analytics_owner_device', array( 'sn_owner_device' => 'mark' ), 'success', true ),
		array( 'stale', 'sn_analytics_collector_save', array( 'sn_an_collector_url' => 'https://fake-collector.example.test/_sn/px' ), 'error', false ),
	) as $case ) {
		$os10 = new \OpenStation\App\Os(); $os10->view = 'settings';
		$app->actions['post']( $st10, $os10, array( 'values' => array( '_wpnonce' => $case[0], 'action' => $case[1] ) + $case[2] ) );
		$notice = (array) $st10->get( 'notice' );
		ok( 'analytics' === $st10->get( 'sub' ) && $case[3] === ( $notice[0] ?? '' ) && '' !== (string) ( $notice[1] ?? '' ) && $case[4] === $st10->get( 'saved' ) && false === strpos( implode( ' ', $notice ) . implode( ' ', $os10->toasts ), 'fake-' ),
			"Settings > Analytics: $case[1] (nonce $case[0]) lands back on the pane with its notice, which does not repeat the URL" );
	}
	// The Accounts pane's two Search Console saves land on that pane too, and
	// the notice says nothing of the pasted key file.
	$st11 = new \OpenStation\App\State( $app->state, array( 'sub' => 'accounts' ) );
	foreach ( array(
		array( 'gsc-nonce', 'sn_gsc_credential_save', array( 'sn_gsc_credential' => '{"type":"service_account","fake":"FAKE-JSON-FOR-TEST"}' ), 'success' ),
		array( 'gsc-nonce', 'sn_gsc_credential_save', array( 'sn_gsc_credential' => '' ), 'info' ),
		array( 'prop-nonce', 'sn_gsc_property_save', array( 'sn_gsc_property' => 'sc-domain:fake.example.test' ), 'success' ),
	) as $case ) {
		$os11 = new \OpenStation\App\Os(); $os11->view = 'settings';
		$app->actions['post']( $st11, $os11, array( 'values' => array( '_wpnonce' => $case[0], 'action' => $case[1] ) + $case[2] ) );
		$notice = (array) $st11->get( 'notice' );
		ok( 'accounts' === $st11->get( 'sub' ) && $case[3] === ( $notice[0] ?? '' ) && '' !== (string) ( $notice[1] ?? '' ) && false === stripos( implode( ' ', $notice ) . implode( ' ', $os11->toasts ), 'fake' ),
			"Settings > Accounts and connections: $case[1] lands back on the pane with a $case[3] notice that says nothing of what was pasted" );
	}
	// In flight across the move: a Measurement-tab session painted before the
	// update still holds the Worker URL form with its hidden tab and sub. Its
	// save runs and is answered on the leaf it was posted from.
	$os12 = new \OpenStation\App\Os(); $os12->view = 'monitoring';
	$st12 = new \OpenStation\App\State( $app->state, array( 'sub' => 'machine-readers' ) );
	$app->actions['post']( $st12, $os12, array( 'values' => array( '_wpnonce' => 'mr-nonce', 'action' => 'sn_machine_readers_save', 'tab' => 'monitoring', 'sub' => 'machine-readers', 'sn_mr_worker_url' => 'https://fake-sensor.example.test' ) ) );
	// (This harness's registry has no Machine Readers leaf, so the tab's first leaf stands in for it.)
	ok( in_array( $st12->get( 'sub' ), array( 'machine-readers', 'analytics' ), true ) && 'success' === ( $st12->get( 'notice' )[0] ?? '' ) && 'Machine Readers sensor settings saved. The panels read with the new credentials on the next load.' === ( $st12->get( 'notice' )[1] ?? '' ), 'a save in flight from the old Measurement > Machine Readers form is still applied and answered on the Measurement tab: ' . $st12->get( 'sub' ) );

	// In flight across the move: a Connections-tab session painted before the
	// update still holds sub=credentials. Its save runs, and the answer paints
	// on that tab's first leaf, never a blank.
	$os9 = new \OpenStation\App\Os();
	$os9->view = 'connections';
	$st9 = new \OpenStation\App\State( $app->state, array( 'sub' => 'credentials' ) );
	$app->actions['post']( $st9, $os9, array( 'values' => array( '_wpnonce' => 'key-nonce', 'action' => 'sn_keyring_save', 'key_id' => 'cf_token', 'key_value' => 'fake-value-for-test-0000' ) ) );
	ok( 'cloudflare' === $st9->get( 'sub' ) && array( 'success', 'Credentials saved. Press Verify all to check them.' ) === $st9->get( 'notice' ),
		'a save in flight from the old Connections > Credentials leaf is still answered, on the Connections tab\'s first leaf' );

	// In flight across the move: a Site-tab session painted before the update
	// still holds sub=front-end. Its save runs, and the answer paints on that
	// tab's first leaf, never a blank.
	$os4 = new \OpenStation\App\Os();
	$os4->view = 'site';
	$st7 = new \OpenStation\App\State( $app->state, array( 'sub' => 'front-end' ) );
	$app->actions['post']( $st7, $os4, array( 'values' => array( '_wpnonce' => 'theme-nonce', 'action' => 'sn_save_theme', 'theme_related_count' => '4' ) ) );
	ok( 'performance' === $st7->get( 'sub' ) && array( 'success', 'Front-end settings saved.' ) === $st7->get( 'notice' ),
		'a save in flight from the old Site > Front-End leaf is still answered, on the Site tab\'s first leaf (Performance now)' );
	// The same for the leaf that left last: a Site session still holding
	// sub=identity-and-seo saves, and is answered on Site's first leaf.
	$os5 = new \OpenStation\App\Os();
	$os5->view = 'site';
	$st8 = new \OpenStation\App\State( $app->state, array( 'sub' => 'identity-and-seo' ) );
	$app->actions['post']( $st8, $os5, array( 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_save_identity', 'site_name' => 'Signal' ) ) );
	ok( 'performance' === $st8->get( 'sub' ) && array( 'success', 'Identity settings saved.' ) === $st8->get( 'notice' ) && true === $st8->get( 'saved' ),
		'a save in flight from the old Site > Identity & SEO leaf is applied and answered, on the Site tab\'s first leaf' );
	// A bare Settings session (no sub named: General, its first pane) saves
	// and stays on the pane.
	$st9 = new \OpenStation\App\State( $app->state, array() );
	$app->actions['post']( $st9, $os2, array( 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_save_identity', 'site_name' => 'Signal' ) ) );
	ok( 'general' === $st9->get( 'sub' ) && 'general' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'settings', $st9 ) && array( 'success', 'Identity settings saved.' ) === $st9->get( 'notice' ),
		'a save from the unnamed Settings tab lands back on General with its notice' );

	// In flight across the move: a Security-tab session painted before the
	// update still holds sub=login. Its save runs (the action is found by
	// name), and the answer paints on that tab's first leaf, never a blank.
	$os3 = new \OpenStation\App\Os();
	$os3->view = 'security';
	$st4 = new \OpenStation\App\State( $app->state, array( 'sub' => 'login' ) );
	$app->actions['post']( $st4, $os3, array( 'values' => array( '_wpnonce' => 'login-nonce', 'action' => 'sn_save_login', 'login_slug' => '' ) ) );
	ok( 'login-defense' === $st4->get( 'sub' ) && array( 'error', 'Login slug cannot be empty.' ) === $st4->get( 'notice' ),
		'a slug save in flight from the old Security > Login URL leaf is still answered, on the Security tab\'s first leaf' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'stale', 'action' => 'sn_save_identity' ) ) );
	// 2026-10-12 audit: the refusal stays on the page as an error notice, not
	// only in a 3.5-second toast; the error tone is what tells the host to
	// keep the typed values (paint_effect()'s `refused`, pinned below).
	$stale = 'Nothing was saved: the form\'s security check did not pass (sn_save_identity). What you typed is still in the form, except any password; press Save again.';
	ok( array( $stale ) === $os->toasts && array( 'error', esc_html( $stale ) ) === $st->get( 'notice' ) && false === $st->get( 'saved' ),
		'a stale nonce says WHAT WAS MEASURED, in plain words: the security check did not pass, for the action in parentheses. "The form expired. Reopen the tab and try again." was a cause nobody measured, and it was said to eight forms whose nonce was never this one, where reopening could never help; and it says the typing is kept and what to press' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce' ) ) );
	ok( array( 'Nothing was saved: the form did not say what to save.' ) === $os->toasts, 'a submission with no action at all belongs to no pipeline, and the toast says exactly that, in plain words' );
	ok( '(no action)' === $st->get( 'answered' ), 'its answer still names a form (a sentinel), so the host shows the refusal again even if the reader closed the last one (review on #2023)' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'pipeline' => 'admin-post', 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_prov_nothing_hooked' ) ) );
	ok( array( 'Nothing was saved: nothing in this window saves that form (sn_prov_nothing_hooked).' ) === $os->toasts,
		'an admin-post action nothing is hooked to is named in the refusal -- do_action() on an unhooked hook is silent, and silence must not paint as a save' );

	echo "\nGroup 5b: the pipelines a form can belong to\n";
	// The five Provenance forms and the three RSS ones are not on the shared
	// pipeline: their own action, their own nonce, their own handler. The window
	// used to answer all eight with "the form expired".
	$os = new \OpenStation\App\Os();
	$os->view = 'tools';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'provenance' ) );
	$GLOBALS['__admin_post_ran'] = '';
	$app->actions['post']( $st, $os, array( 'pipeline' => 'admin-post', 'values' => array( '_wpnonce' => 'prov-nonce', 'action' => 'sn_fixture_redirects' ) ) );
	ok( 'sn_fixture_redirects' === $GLOBALS['__admin_post_ran'],
		'a form whose action attribute pointed at admin-post.php runs its admin_post_ hook -- the rewrite read that attribute before dropping it and shipped it back as os-arg-pipeline' );
	ok( 'provenance' === $st->get( 'sub' ) && array( 'sn_prov_swept' => 'ok' ) === $st->get( 'params' ),
		'   ...a cross-tab redirect does not retarget this session (the tab is the framework`s); the flash query still lands in params' );
	ok( array() === $os->toasts, '   ...with no toast: the handler said nothing through a flash code, so neither does the window' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'pipeline' => 'admin-post', 'values' => array( '_wpnonce' => 'prov-nonce', 'action' => 'sn_fixture_dies' ) ) );
	ok( array( 'error', 'Insufficient permissions.' ) === $st->get( 'notice' ) && array( 'Insufficient permissions.' ) === $os->toasts,
		'a handler that wp_die()s is refused in ITS OWN WORDS, painted as the notice and toasted -- the five Provenance handlers all wp_die() on a failed capability check' );

	$os = new \OpenStation\App\Os();
	$os->view = 'monitoring';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'rss' ) );
	$GLOBALS['__rss_ran'] = false;
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'rss-nonce', 'action' => 'sn_rss_reset_defaults' ) ) );
	ok( true === $GLOBALS['__rss_ran'] && array( 'sn_rss_ok' => 'reset' ) === $st->get( 'params' ),
		'the RSS leaf`s action field routes to its admin-post hook, and ?sn_rss_ok lands in state for the flash the leaf renders itself' );

	echo "\nGroup 5c: the inline form the leaf handles itself\n";
	$os = new \OpenStation\App\Os();
	$os->view = 'security';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'audit-log' ) );
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'prune-nonce', 'sn_action' => 'audit_prune_now' ) ) );
	ok( array() === $os->toasts && 'audit_prune_now' === $st->get( 'post' )['sn_action'],
		'"Prune now" is kept, not refused: it is not in the handler table because its own leaf handles it, and the window used to toast "Nothing was saved." and prune nothing' );
	ok( true === $st->get( 'saved' ) && 'audit_prune_now' === $st->get( 'answered' ), '   ...and an inline save answers its own form too (saved, named by its sn_action)' );
	paint( $app, array( 'tab' => 'security', 'sub' => 'audit-log' ), $os, $st );
	ok( array() === $st->get( 'post' ), '   ...then the bag is SPENT: a paint that kept it would prune again on the next unrelated repaint' );
	paint( $app, array( 'tab' => 'security', 'sub' => 'audit-log' ), $os, $st );
	ok( array() === $st->get( 'post' ), '   ...and the paint after it still sees an empty bag, as every other paint does' );

	$GLOBALS['__caps']['manage_options'] = false;
	$os = new \OpenStation\App\Os();
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_save_identity' ) ) );
	ok( array() === $GLOBALS['__handler_calls'] && 1 === count( $os->toasts ), 'without manage_options nothing runs and the reason is said' );
	$GLOBALS['__caps']['manage_options'] = true;

	$os = new \OpenStation\App\Os();
	$os->view = 'connections';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'webhooks' ) );
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'hook-nonce', 'action' => 'sn_webhook_add' ) ) );
	ok( 'wh_added_abc123' === $st->get( 'flash' ), 'a webhook add keeps its flash code in state' );
	ok( 'abc123' === ( $st->get( 'params' )['new_id'] ?? substr( (string) $st->get( 'flash' ), strlen( 'wh_added_' ) ) ),
		'   ...and the minted id is still on the state the leaf reads -- the classic page pulled it out of the flash as ?new_id=' );

	echo "\nGroup 6: door\n";
	$os = new \OpenStation\App\Os();
	$app->actions['door']( $st, $os, array( 'url' => 'https://example.test/wp-admin/update-core.php' ) );
	ok( array( array( 'https://example.test/wp-admin/update-core.php', '', '' ) ) === $os->opened,
		'an admin URL of this site opens as its own window -- the sn_force_update_check door still lands on update-core.php' );
	$os = new \OpenStation\App\Os();
	// 14.7.5: the FRONT END of this site is a door too (a window; the shell
	// iframes it like a preview). A same-origin target=_blank relaunched the
	// installed PWA, which is why the front end moved from refused to opened.
	$app->actions['door']( $st, $os, array( 'url' => 'https://example.test/notes/' ) );
	ok( array( array( 'https://example.test/notes/', '', '' ) ) === $os->opened, 'a same-origin FRONT-END URL opens as a window' );
	$os->opened = array();
	foreach ( array( 'https://evil.test/wp-admin/', 'https://evil.test/notes/', 'javascript:alert(1)', '' ) as $bad ) {
		$app->actions['door']( $st, $os, array( 'url' => $bad ) );
	}
	ok( array() === $os->opened, 'another host (admin or not), a javascript: URL and an empty one are all refused' );
	$GLOBALS['__caps']['manage_options'] = false;
	$app->actions['door']( $st, $os, array( 'url' => 'https://example.test/wp-admin/update-core.php' ) );
	ok( array() === $os->opened, 'the door re-checks manage_options too' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 7: the view\n";
	$html = paint( $app, array( 'tab' => 'monitoring', 'sub' => 'health', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	ok( 0 === strpos( $html, '<div class="snt-app" data-os-app="sn-dashboard" data-snt-tab="monitoring"' ), 'the root is the native window`s: the tab it paints, no wp-admin heading' );
	ok( false !== strpos( $html, 'data-snt-leaf="health"' ), 'the active leaf is named on the body' );
	ok( false !== strpos( $html, '<os-app-frame>' ) && false === strpos( $html, 'snt-dashboard-body' ) && strpos( $html, '<os-app-frame>' ) < strpos( $html, 'data-snt-leaf="health"' ),
		'the active leaf sits in the kit frame\'s scrolling body, not contained on a leaf tab (#1617)' );
	// 17.4.3: the leaf bar is the native list toolbar's status control, both
	// twins bound to sub; the os-tabs strip (the kit's rose underline beside
	// the chrome's bold white) is gone from this level.
	ok( 1 === preg_match( '#<header class="os-app-list__toolbar snt-subbar"( data-snt-fit="\d+")? slot="toolbar"><div class="os-app-list__toolbar-left"><os-segmented class="os-app-list__status" value="health" os-bind="sub" label="Sections">#', $html )
		&& false !== strpos( $html, '<os-segment value="health">' )
		&& false !== strpos( $html, '<os-select class="os-app-list__status snt-subbar__phone" os-key="subbar-phone" value="health" os-bind="sub" aria-label="Sections">' )
		&& false !== strpos( $html, '<os-option value="health">' ),
		'a tab with leaves paints the native toolbar: a segmented control for the desk and a select for the phone, both bound to sub' );
	ok( false === strpos( $html, '<os-tabs' ) && false === strpos( $html, 'os-app-list__tabs' ),
		'   ...and no os-tabs strip at this level' );
	ok( false !== strpos( $html, '<header class="os-app-list__toolbar' ) && strpos( $html, '<os-app-frame>' ) < strpos( $html, '<header class="os-app-list__toolbar' ) && strpos( $html, '<header class="os-app-list__toolbar' ) < strpos( $html, 'data-snt-leaf="health"' ),
		'   ...in the frame\'s toolbar slot, above the leaf, where the native list puts its toolbar' );
	// Each rule is matched from its own start (a rule boundary or the sheet's
	// start), so a mobile-scoped hide cannot satisfy the off-mobile pin by
	// suffix; comments are stripped first so a rule inside one never counts.
	$css = (string) preg_replace( '#/\\*.*?\\*/#s', '', (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' ) );
	$css = (string) preg_replace( '/\\s+/', ' ', $css );
	$rule = static fn( $sel, $body ) => (bool) preg_match( '/(?:^|\\}) ?' . preg_quote( $sel, '/' ) . ' \\{ ' . preg_quote( $body, '/' ) . ' \\}/', $css );
	ok( $rule( 'html:not([data-os-mode="mobile"]) .snt-subbar:not([data-snt-fit]) .snt-subbar__phone', 'display: none;' )
		&& $rule( 'html[data-os-mode="mobile"] .snt-subbar > .os-app-list__toolbar-left > os-segmented', 'display: none;' )
		&& ! $rule( '.snt-subbar__phone', 'display: none;' ),
		'   ...the phone twin hides only off the shell`s mobile stamp and the desk twin only under it, each by its own rule' );
	// 2026-10-12 audit (cross-cutting #8): nine Connections pills need about
	// 819px, and the hidden scrollbar left Cron and Scheduled out of reach in a
	// half-screen window. A bar the kit measured as wide (data-snt-fit, the
	// container width it needs) switches to the select twin below that width.
	// Review on #2023: range syntax, so no fractional width paints both twins
	// (max-width 920 / min-width 921 left 920.5 to both), and buckets down to
	// 440, so Site's five pills switch too in a narrow window.
	foreach ( array( '440', '600', '760', '920', '1080', '1240' ) as $fit ) {
		ok( 1 === preg_match( '/@container snt-dashboard \( width > ' . $fit . 'px \) \{ html:not\(\[data-os-mode="mobile"\]\) \.snt-subbar\[data-snt-fit="' . $fit . '"\] \.snt-subbar__phone \{ display: none; \} \}/', $css )
			&& 1 === preg_match( '/@container snt-dashboard \( width <= ' . $fit . 'px \) \{ \.snt-subbar\[data-snt-fit="' . $fit . '"\] > \.os-app-list__toolbar-left > os-segmented \{ display: none; \} \}/', $css ),
			"   ...a bar that needs {$fit}px shows the select twin in a window that narrow or narrower, the pills in a wider one" );
	}
	ok( 0 === preg_match( '/@container snt-dashboard \( (min|max)-width: \d+px \) \{[^{}]*snt-subbar/', $css ), '   ...and no leaf-bar switch uses min/max-width, which leave a fractional gap' );
	ok( $rule( '.snt-subbar > .os-app-list__toolbar-left', 'min-width: 0; overflow-x: auto; scrollbar-width: none;' )
		&& $rule( '.snt-subbar os-segment', 'flex: 0 0 auto;' ),
		'   ...and the pill row scrolls sideways in a window narrower than its pills, the parent scrolling and the segments unshrinkable' );
	ok( false === strpos( $html, '<h1 class="sn-page-h1">' ) && false === strpos( $html, 'nav-tab-wrapper' ),
		'the classic page heading and wp-admin tab strip are gone -- the window chrome is the strip' );

	ok( false === strpos( $html, "<form" ),
		"the native health leaf is painted directly, without the classic dispatcher fixture" );
	ok( false === strpos( $html, 'lnk-legacy-slug' ) && false === strpos( $html, 'sn-sub-tabs' ),
		'the native leaf does not retain the classic dispatcher navigation; the framework strip owns navigation' );


	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'performance', 'notice' => array( 'error', 'It <a href="x">broke</a>.' ) ) );
	ok( false !== strpos( $html, '<os-notice tone="danger" role="alert">It <a href="x">broke</a>.</os-notice>' ),
		'the notice is the kit`s notice, and its deliberate inline <a> survives -- about fifteen flash codes ship one' );
	ok( strpos( $html, '<os-notice' ) < strpos( $html, 'snt-leaf' ), '   ...above the leaf, where the classic page puts it under the heading' );

	// State holds an ELEMENT ID, painted unchanged. `section_anchor()` converts
	// the estate's bare slugs at the one place they enter state (a save's
	// redirect target, a deep link's destination), so exactly one rule applies
	// and a fragment that is not `sn-sec-*` survives -- the Dashboard's
	// attention strip links #sn-dash-diagnostics, which prefixing here dropped.
	$os = new \OpenStation\App\Os();
	$os->view = 'settings';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'general' ) );
	$app->actions['go']( $st, $os, array( 'anchor' => 'sn-sec-identity' ) );
	ok( 'sn-sec-identity' === $st->get( 'anchor' ),
		'an anchor enters state as the ELEMENT ID, painted unchanged' );
	$app->actions['go']( $st, $os, array( 'anchor' => 'sn-dash-diagnostics' ) );
	ok( 'sn-dash-diagnostics' === $st->get( 'anchor' ),
		'   ...a link`s own os-arg-anchor is already an id and is kept verbatim -- the attention strip`s #sn-dash-diagnostics is not a section wrapper and never was' );
	// #1609: the id rides the runtime's own effect channel. `$os->effects->add()`
	// queues it; the runtime re-dispatches it on the app root as an
	// `os-app-effect` CustomEvent after the morph, and assets/os-host.js
	// scrolls from that. No attribute is painted, and the view clears the
	// anchor from state once queued (the runtime echoes state after render),
	// so a Refresh or an inline post paints with an empty one: one save
	// scrolls once.
	$os   = new \OpenStation\App\Os();
	$st   = new \OpenStation\App\State( $app->state, array( 'tab' => 'site', 'sub' => 'performance', 'anchor' => 'sn-sec-identity' ) );
	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, $st );
	ok( array( array( 'type' => 'snt-paint', 'anchor' => 'sn-sec-identity', 'refused' => false, 'saved' => false, 'answered' => '' ) ) === $os->effects->all(),
		'the view queues ONE snt-paint effect carrying the id UNCHANGED -- assets/os-host.js looks the value up as an id, and a view that prefixed would double it' );
	ok( false === strpos( $html, 'data-snt-anchor=' ), 'and paints NO data-snt-anchor: the effect is the carrier' );
	ok( '' === (string) $st->get( 'anchor' ), 'and clears the anchor from state once it is queued: the id rides one paint' );
	$os = new \OpenStation\App\Os();
	paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, $st );
	ok( array( array( 'type' => 'snt-paint', 'anchor' => '', 'refused' => false, 'saved' => false, 'answered' => '' ) ) === $os->effects->all(), '   ...so the next paint of the same session (a Refresh, an inline post) queues an EMPTY anchor and scrolls nowhere' );
	$os = new \OpenStation\App\Os();
	paint( $app, array( 'tab' => 'site' ), $os );
	ok( array( array( 'type' => 'snt-paint', 'anchor' => '', 'refused' => false, 'saved' => false, 'answered' => '' ) ) === $os->effects->all(), 'no anchor, the effect still fires with an empty one: every paint is announced, or the leaf scripts never re-arm' );
	// 2026-10-12: a paint that carries an error notice says so, so the host
	// keeps a submitted form's typed text; any other notice lets it go.
	foreach ( array( array( array( 'error', 'Nothing was saved.' ), true ), array( array( 'success', 'Saved.' ), false ), array( null, false ) ) as list( $notice, $want ) ) {
		$os = new \OpenStation\App\Os();
		paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, new \OpenStation\App\State( $app->state, array( 'tab' => 'site', 'sub' => 'performance', 'notice' => $notice ) ) );
		ok( $want === ( $os->effects->all()[0]['refused'] ?? null ), 'the paint effect reports refused=' . var_export( $want, true ) . ' for a ' . ( is_array( $notice ) ? $notice[0] : 'missing' ) . ' notice' );
	}
	// `saved` answers ONE paint after a save that took, then clears; a refusal never says saved.
	$st = new \OpenStation\App\State( $app->state, array( 'tab' => 'site', 'sub' => 'performance', 'saved' => true, 'answered' => 'sn_save_identity', 'notice' => array( 'success', 'Saved.' ) ) );
	$os = new \OpenStation\App\Os();
	paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, $st );
	ok( true === ( $os->effects->all()[0]['saved'] ?? null ) && 'sn_save_identity' === ( $os->effects->all()[0]['answered'] ?? null ) && false === $st->get( 'saved' ) && '' === $st->get( 'answered' ), 'a save that took says saved=true and names its action on the next paint, and only that one' );
	$os = new \OpenStation\App\Os();
	paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, $st );
	ok( false === ( $os->effects->all()[0]['saved'] ?? null ), '   ...a later paint (a tick, an Edit) does not' );
	$os = new \OpenStation\App\Os();
	paint( $app, array( 'tab' => 'site', 'sub' => 'performance' ), $os, new \OpenStation\App\State( $app->state, array( 'tab' => 'site', 'sub' => 'performance', 'saved' => true, 'notice' => array( 'error', 'Login slug cannot be empty.' ) ) ) );
	ok( false === ( $os->effects->all()[0]['saved'] ?? null ) && true === ( $os->effects->all()[0]['refused'] ?? null ), 'an error notice is refused, never saved' );

	echo "\nGroup 8: the leaf never keeps the request\n";
	$_GET     = array( 'sentinel' => 'yes' );
	$_POST    = array();
	$_REQUEST = array();
	$before   = array( $_GET, $_POST, $_REQUEST );
	paint( $app, array( 'tab' => 'ai', 'sub' => 'models-budget' ) );
	ok( $before === array( $_GET, $_POST, $_REQUEST ), 'painting a leaf gives the request back untouched' );

	echo "\nGroup 9: the window carries the admin assets its leaves expect\n";
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ), 'scripts' => array() ), 'sn-dashboard', null );
	$handles = snt_os_host_asset_handles();
	ok( array() === array_diff( $handles['styles'], $args['styles'] ) && array() === array_diff( $handles['scripts'], $args['scripts'] ),
		'every declared handle rides this window (the seam itself is pinned in tests/openstation-host.php)' );
	ok( in_array( 'snt-os-host', $args['scripts'], true ), '   ...including the host script, without which no inline leaf script ever runs' );

	echo "\nGroup 10: the manual dock item is gone\n";
	$dock = file_get_contents( __DIR__ . '/../inc/desktop-mode-dock.php' );
	ok( false === strpos( $dock, "'id'      => 'sn-dashboard'" ) && false === strpos( $dock, "snt_os_compat_add_filter( 'desktop_mode_dock_items'" ),
		'inc/desktop-mode-dock.php no longer registers a dock item -- two registrations under one id would be one id naming two things' );
	ok( function_exists( 'snt_desktop_dock_badge' ) && 2 === snt_desktop_dock_badge(), 'snt_desktop_dock_badge() stays, unchanged, and the app reads it' );
	ok( false !== strpos( $dock, "'desktop_mode_dock_placement'" ),
		'the placement filter STAYS: without it the shell auto-imports our add_menu_page() entry as a second tile' );
	ok( false !== strpos( $dock, 'function snt_desktop_admin_url' ),
		'and so does snt_desktop_admin_url() -- every door that opens the classic page today still opens it' );

	echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";
	exit( $fail > 0 ? 1 : 0 );
}
