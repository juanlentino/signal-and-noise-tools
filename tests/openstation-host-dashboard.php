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
	class Os {
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
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
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
	function get_transient( $key ) { return false; }
	function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); }
	function sn_theme_ai_models() { return array(); }
	function sn_theme_ai_vision_models() { return array(); }
	function sn_mask_secret( $value ) { return (string) $value; }
	// One nonce per ACTION: the estate mints four, and a global "valid" would
	// hide the very confusion the pipelines exist to end.
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$minted = array( 'good-nonce' => 'sn_theme_options_nonce', 'prov-nonce' => 'sn_prov_fixture', 'rss-nonce' => 'sn_rss_tracker_action' );
		return ( isset( $minted[ $nonce ] ) && $minted[ $nonce ] === $action ) ? 1 : false;
	}
	// The interceptor needs real hook plumbing, a real wp_redirect (which filters
	// its location before sending) and a real wp_die (which picks its handler
	// through a filter). A registration sink here would let the whole seam pass
	// without running.
	$GLOBALS['__actions'] = array();
	function remove_filter( $hook, $cb, $prio = 10 ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $i => $one ) { if ( $one === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $i ] ); } } return true; }
	function has_action( $hook, $cb = false ) { return ! empty( $GLOBALS['__actions'][ $hook ] ); }
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
	// The RSS leaf's admin_init handler, in its shape: its own field, its own
	// nonce, its own redirect carrying ?sn_rss_ok.
	$GLOBALS['__rss_ran'] = false;
	function sn_rss_tracker_handle_form() {
		// phpcs:ignore WordPress.Security.NonceVerification -- The fixture IS the nonce check.
		if ( empty( $_POST['sn_rss_action'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sn_rss_tracker_action' ) ) { return; }
		$GLOBALS['__rss_ran'] = true;
		wp_safe_redirect( 'https://example.test/wp-admin/themes.php?page=sn-theme-options&tab=rss&sn_rss_ok=reset' );
		exit;
	}
	$GLOBALS['__styles']  = array();
	$GLOBALS['__scripts'] = array();
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__styles'][ $handle ] = $deps; return true; }
	function wp_style_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $handle ] ); }
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) { $GLOBALS['__scripts'][ $handle ] = $deps; return true; }
	function wp_script_is( $handle, $status = 'enqueued' ) { return isset( $GLOBALS['__scripts'][ $handle ] ); }
	function wp_localize_script( $handle, $object_name, $l10n ) { return true; }
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { return true; }
	const SNT_FRESHNESS_CARD_ID = 'snt-freshness-card';
	function snt_freshness_routes() { return array( '/' ); }

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
		echo '<div class="sn-section" id="sn-sec-' . esc_attr( (string) $active_sub ) . '"><form method="post"><input type="hidden" name="sn_action" value="save_identity"></form></div>';
	}
	function sn_handle_save_identity( $post ) {
		$GLOBALS['__handler_calls'][] = $post;
		return 'identity_saved';
	}
	function sn_handle_webhook_add( $post ) { return 'wh_added_abc123'; }
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
	ok( 'S&N Dashboard' === $app->title && 'dashicons-shield-alt' === $app->icon && 'dock' === $app->placement,
		'the same title, the same shield and the same dock placement the manual item had' );
	ok( array( 'manage_options' ) === $app->caps, 'gated on manage_options, the capability the classic page wp_die()s without' );
	ok( array( 1180, 820 ) === $app->size && array( 760, 520 ) === $app->min, 'opens at 1180x820, never smaller than 760x520' );
	ok( array( 'sub', 'anchor', 'flash', 'notice', 'params', 'post' ) === array_keys( $app->state )
		&& '' === $app->state['sub'] && null === $app->state['notice'] && array() === $app->state['params'] && array() === $app->state['post'],
		'state has NO tab: the tab is the session (the framework`s); only the leaf, the anchor and the last write are state' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen' ) === array_keys( $app->actions ),
		'five actions: go, post, door, refresh and the reopen lifecycle' );
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
	$os->params = array( 'sub' => 'health' );
	$st = new \OpenStation\App\State( $app->state );
	call_user_func( $app->mount, $st, $os );
	ok( 'health' === $st->get( 'sub' ), 'a deep link opens on its leaf; the tab is the session' );
	ok( array( 2 ) === $os->badges, 'mount sets the badge from snt_desktop_dock_badge() -- the same function the dock item read' );
	ok( array() === $os->menus, '   ...and never pops a context menu: $os->menu() opens one AT THE POINTER, which is not a window menu' );

	$os->view   = 'connections';
	$os->params = array( 'sub' => 'cloudflare' );
	$app->actions['reopen']( $st, $os, array() );
	ok( 'cloudflare' === $st->get( 'sub' ),
		'reopen retargets a live window onto the leaf the params name' );

	$GLOBALS['__badge'] = 0;
	$app->actions['reopen']( $st, $os, array() );
	ok( 0 === end( $os->badges ), 'the badge follows the function, not a remembered number' );
	$GLOBALS['__badge'] = 2;

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
	$os = new \OpenStation\App\Os();
	$os->view = 'site';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'identity-and-seo', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'site_name' => 'Signal' ) ) );
	ok( 1 === count( $GLOBALS['__handler_calls'] ) && 'Signal' === $GLOBALS['__handler_calls'][0]['site_name'], 'the handler ran with the posted values' );
	ok( array( 'success', 'Identity settings saved.' ) === $st->get( 'notice' ) && 'identity_saved' === $st->get( 'flash' ),
		'the flash becomes the classic notice, and the CODE is kept (the Webhooks leaf reads an id out of it)' );
	ok( array( 'Identity settings saved.' ) === $os->toasts, '   ...and the same sentence is toasted, as plain text' );
	ok( 'identity-and-seo' === $st->get( 'sub' ) && array() === $st->get( 'params' ),
		'the redirect target is applied to this tab`s session, and the query params are dropped -- the classic redirect keeps only page/tab/sub/sn_flash' );
	ok( array( 2 ) === $os->badges && 1 === $os->refresh, 'a save re-reads the badge and asks the shell for a fresh payload' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'stale', 'sn_action' => 'save_identity' ) ) );
	ok( array( 'Nothing was saved: the security token did not verify against sn_theme_options_nonce.' ) === $os->toasts && null === $st->get( 'notice' ),
		'a stale nonce says WHAT WAS MEASURED -- a token that did not verify against the action it was checked against. "The form expired. Reopen the tab and try again." was a cause nobody measured, and it was said to eight forms whose nonce was never this one, where reopening could never help' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce' ) ) );
	ok( array( 'Nothing was saved: the form carried no action.' ) === $os->toasts, 'a submission with no action at all belongs to no pipeline, and the toast says exactly that' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'pipeline' => 'admin-post', 'values' => array( '_wpnonce' => 'good-nonce', 'action' => 'sn_prov_nothing_hooked' ) ) );
	ok( array( 'Nothing was saved: no pipeline in this window handles the action sn_prov_nothing_hooked.' ) === $os->toasts,
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
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'rss-nonce', 'sn_rss_action' => 'reset_defaults' ) ) );
	ok( true === $GLOBALS['__rss_ran'] && array( 'sn_rss_ok' => 'reset' ) === $st->get( 'params' ),
		'the RSS leaf`s own field routes to its own admin_init handler, and ?sn_rss_ok lands in state for the flash the leaf renders itself' );

	echo "\nGroup 5c: the inline form the leaf handles itself\n";
	$os = new \OpenStation\App\Os();
	$os->view = 'security';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'audit-log' ) );
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'audit_prune_now' ) ) );
	ok( array() === $os->toasts && 'audit_prune_now' === $st->get( 'post' )['sn_action'],
		'"Prune now" is kept, not refused: it is not in the handler table because its own leaf handles it, and the window used to toast "Nothing was saved." and prune nothing' );
	paint( $app, array( 'tab' => 'security', 'sub' => 'audit-log' ), $os, $st );
	ok( array() === $st->get( 'post' ), '   ...then the bag is SPENT: a paint that kept it would prune again on the next unrelated repaint' );
	paint( $app, array( 'tab' => 'security', 'sub' => 'audit-log' ), $os, $st );
	ok( array() === $st->get( 'post' ), '   ...and the paint after it still sees an empty bag, as every other paint does' );

	$GLOBALS['__caps']['manage_options'] = false;
	$os = new \OpenStation\App\Os();
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity' ) ) );
	ok( array() === $GLOBALS['__handler_calls'] && 1 === count( $os->toasts ), 'without manage_options nothing runs and the reason is said' );
	$GLOBALS['__caps']['manage_options'] = true;

	$os = new \OpenStation\App\Os();
	$os->view = 'connections';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'webhooks' ) );
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'webhook_add' ) ) );
	ok( 'wh_added_abc123' === $st->get( 'flash' ), 'a webhook add keeps its flash code in state' );
	ok( 'abc123' === ( $st->get( 'params' )['new_id'] ?? substr( (string) $st->get( 'flash' ), strlen( 'wh_added_' ) ) ),
		'   ...and the minted id is still on the state the leaf reads -- the classic page pulled it out of the flash as ?new_id=' );

	echo "\nGroup 6: door\n";
	$os = new \OpenStation\App\Os();
	$app->actions['door']( $st, $os, array( 'url' => 'https://example.test/wp-admin/update-core.php' ) );
	ok( array( array( 'https://example.test/wp-admin/update-core.php', '', '' ) ) === $os->opened,
		'an admin URL of this site opens as its own window -- the sn_force_update_check door still lands on update-core.php' );
	$os = new \OpenStation\App\Os();
	foreach ( array( 'https://evil.test/wp-admin/', 'https://example.test/notes/', 'javascript:alert(1)', '' ) as $bad ) {
		$app->actions['door']( $st, $os, array( 'url' => $bad ) );
	}
	ok( array() === $os->opened, 'another host, the front end, a javascript: URL and an empty one are all refused' );
	$GLOBALS['__caps']['manage_options'] = false;
	$app->actions['door']( $st, $os, array( 'url' => 'https://example.test/wp-admin/update-core.php' ) );
	ok( array() === $os->opened, 'the door re-checks manage_options too' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 7: the view\n";
	$html = paint( $app, array( 'tab' => 'monitoring', 'sub' => 'health', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	ok( 0 === strpos( $html, '<div class="snt-app" data-snt-tab="monitoring"' ), 'the root is the native window`s: the tab it paints, no wp-admin heading' );
	ok( false !== strpos( $html, 'data-snt-leaf="health"' ), 'the active leaf is named on the body' );
	ok( false !== strpos( $html, '<os-tabs' ) && false !== strpos( $html, 'os-bind="sub"' ),
		'a tab with leaves paints the kit sub-strip, bound to sub' );
	ok( false === strpos( $html, '<h1 class="sn-page-h1">' ) && false === strpos( $html, 'nav-tab-wrapper' ),
		'the classic page heading and wp-admin tab strip are gone -- the window chrome is the strip' );

	if ( '' === $GLOBALS['__html_api'] ) {
		skip( 'the captured leaf came back rewritten -- no wp-includes/html-api on this machine' );
		skip( 'the leaf\'s own sub-tab nav became `go` links -- no wp-includes/html-api on this machine' );
	} else {
		ok( false !== strpos( $html, 'os-action="post"' ) && false === strpos( $html, '<form method="post">' ),
			'the captured leaf came back rewritten: its form dispatches `post`' );
		ok( false !== strpos( $html, '<a os-action="go"' ) && false !== strpos( $html, 'os-arg-sub="health"' ) && false !== strpos( $html, 'os-arg-tab="monitoring"' ),
			'   ...and the sub-tab nav the dispatcher printed became `go` links -- the strip is the leaf\'s, captured, never rebuilt here' );
		ok( preg_match( '#<a [^>]*id="lnk-legacy-slug"[^>]*>#', $html, $m ) && false !== strpos( $m[0], 'os-action="go"' ) && false === strpos( $m[0], 'os-action="door"' ) && false === strpos( $m[0], 'href' ),
			'   ...and a link written with a TOP-TAB slug (page=sn-content) is a `go` too: the view passes snt_os_host_own_pages(), DERIVED from both registries, where one literal slug made every leaf-to-leaf link open a second admin window' );
	}

	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'front-end', 'notice' => array( 'error', 'It <a href="x">broke</a>.' ) ) );
	ok( false !== strpos( $html, '<os-notice tone="danger">It <a href="x">broke</a>.</os-notice>' ),
		'the notice is the kit`s notice, and its deliberate inline <a> survives -- about fifteen flash codes ship one' );
	ok( strpos( $html, '<os-notice' ) < strpos( $html, 'snt-leaf' ), '   ...above the leaf, where the classic page puts it under the heading' );

	// State holds an ELEMENT ID, painted unchanged. `section_anchor()` converts
	// the estate's bare slugs at the one place they enter state (a save's
	// redirect target, a deep link's destination), so exactly one rule applies
	// and a fragment that is not `sn-sec-*` survives -- the Dashboard's
	// attention strip links #sn-dash-diagnostics, which prefixing here dropped.
	$os = new \OpenStation\App\Os();
	$os->view = 'site';
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'identity-and-seo' ) );
	$app->actions['go']( $st, $os, array( 'anchor' => 'sn-sec-identity' ) );
	ok( 'sn-sec-identity' === $st->get( 'anchor' ),
		'an anchor enters state as the ELEMENT ID, painted unchanged' );
	$app->actions['go']( $st, $os, array( 'anchor' => 'sn-dash-diagnostics' ) );
	ok( 'sn-dash-diagnostics' === $st->get( 'anchor' ),
		'   ...a link`s own os-arg-anchor is already an id and is kept verbatim -- the attention strip`s #sn-dash-diagnostics is not a section wrapper and never was' );
	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => 'sn-sec-identity' ) );
	ok( false !== strpos( $html, 'data-snt-anchor="sn-sec-identity"' ),
		'the view paints the id UNCHANGED -- assets/os-kit.js looks the value up as an id, and a view that prefixed would double it' );
	ok( false === strpos( paint( $app, array( 'tab' => 'site' ) ), 'data-snt-anchor=' ), 'no anchor, no attribute' );

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
