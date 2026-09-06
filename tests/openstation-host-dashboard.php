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
		public function set( $k, $v ) { $this->values[ $k ] = $v; return $this; }
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
	function wp_verify_nonce( $nonce, $action = -1 ) { return ( 'good-nonce' === $nonce && 'sn_theme_options_nonce' === $action ) ? 1 : false; }
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
		// phpcs:ignore WordPress.Security.NonceVerification -- Fixture; it exists to report what $_GET held.
		$GLOBALS['__painted'][] = array( 'tab' => $active_tab, 'sub' => $active_sub, 'get' => $_GET );
		echo '<nav class="sn-sub-tabs"><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ) ) . '">Health</a></nav>';
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
	/** Paint the window for a partial state. */
	function paint( $app, array $state, $os = null ) {
		$st = new \OpenStation\App\State( $app->state, $state );
		ob_start();
		call_user_func( $app->view, $st, $os ?: new \OpenStation\App\Os() );
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
	ok( array( 'tab', 'sub', 'anchor', 'flash', 'notice', 'params' ) === array_keys( $app->state )
		&& 'dashboard' === $app->state['tab'] && null === $app->state['notice'] && array() === $app->state['params'],
		'state: tab, sub, anchor, flash, notice, params -- the URL the classic page kept, as a bag' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen' ) === array_keys( $app->actions ),
		'five actions: go, post, door, refresh and the reopen lifecycle -- and NO framework tabs, which cannot be switched by a server action' );
	ok( isset( $app->buttons['refresh'] ) && 'refresh' === $app->buttons['refresh']['action'], 'a Refresh button in the title bar' );

	echo "\nGroup 2: the menu is the registry, not a list kept here\n";
	$expected_rows = array();
	foreach ( sn_admin_top_tabs() as $top ) { $expected_rows[ 'tab-' . $top['tab'] ] = $top['label']; }
	ok( array_keys( $expected_rows ) === array_keys( $app->menu_rows ),
		'one menu row per top tab, in registry order -- the 8-entry dock submenu, derived exactly as it was' );
	ok( $expected_rows === array_map( static function ( $row ) { return $row['label']; }, $app->menu_rows ),
		'   ...with the registry\'s own labels' );
	$first_row = reset( $app->menu_rows );
	ok( 'go' === $first_row['action'] && array( 'tab' => 'dashboard' ) === $first_row['args'],
		'   ...each row a `go` carrying its tab, where the submenu carried a URL' );

	echo "\nGroup 3: mount and reopen read the open-time params\n";
	$os = new \OpenStation\App\Os();
	$os->params = array( 'tab' => 'monitoring', 'sub' => 'health' );
	$st = new \OpenStation\App\State( $app->state );
	call_user_func( $app->mount, $st, $os );
	ok( 'monitoring' === $st->get( 'tab' ) && 'health' === $st->get( 'sub' ), 'a deep link opens on its tab and leaf' );
	ok( array( 2 ) === $os->badges, 'mount sets the badge from snt_desktop_dock_badge() -- the same function the dock item read' );
	ok( array() === $os->menus, '   ...and never pops a context menu: $os->menu() opens one AT THE POINTER, which is not a window menu' );

	$os->params = array( 'tab' => 'site', 'sub' => 'cloudflare' );
	$app->actions['reopen']( $st, $os, array() );
	ok( 'connections' === $st->get( 'tab' ) && 'cloudflare' === $st->get( 'sub' ),
		'reopen retargets a live window, and a moved leaf still lands where it lives now' );

	$GLOBALS['__badge'] = 0;
	$app->actions['reopen']( $st, $os, array() );
	ok( 0 === end( $os->badges ), 'the badge follows the function, not a remembered number' );
	$GLOBALS['__badge'] = 2;

	echo "\nGroup 4: go\n";
	$st = new \OpenStation\App\State( $app->state, array( 'notice' => array( 'success', 'x' ), 'flash' => 'identity_saved' ) );
	$app->actions['go']( $st, $os, array( 'tab' => 'monitoring', 'sub' => 'health', 'anchor' => 'ignored' ) );
	ok( 'monitoring' === $st->get( 'tab' ) && 'health' === $st->get( 'sub' ) && null === $st->get( 'notice' ) && '' === $st->get( 'flash' ),
		'go lands on the leaf and drops the last save\'s notice -- a notice is a one-shot on the classic page too' );
	$app->actions['go']( $st, $os, array( 'tab' => 'no-such-tab' ) );
	ok( 'dashboard' === $st->get( 'tab' ) && '' === $st->get( 'sub' ), 'an unknown tab is the Dashboard, and a landing tab has no leaf' );
	$app->actions['go']( $st, $os, array( 'tab' => 'monitoring' ) );
	ok( 'analytics' === $st->get( 'sub' ), 'a tab with no leaf named lands on its FIRST leaf -- what sn_admin_resolve_active_sub() does with a bare ?tab=' );
	$app->actions['go']( $st, $os, array( 'values' => array( 'tab' => 'content', 'sub' => 'tags', 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4' ), 'junk' => 'x' ) ) );
	ok( 'content' === $st->get( 'tab' ) && 'tags' === $st->get( 'sub' ) && array( 'sn_tag_preview' => '1', 'sn_tag_from' => array( '4' ) ) === $st->get( 'params' ),
		'a GET form dispatches through go with its FIELDS -- the Tags merge preview, whose three params are state on the classic page' );
	$GLOBALS['__caps']['manage_options'] = false;
	$app->actions['go']( $st, $os, array( 'tab' => 'security' ) );
	ok( 'content' === $st->get( 'tab' ), 'go re-checks manage_options -- the window gate is not the only gate' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 5: post\n";
	$os = new \OpenStation\App\Os();
	$st = new \OpenStation\App\State( $app->state, array( 'tab' => 'site', 'sub' => 'identity-and-seo', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity', 'site_name' => 'Signal' ) ) );
	ok( 1 === count( $GLOBALS['__handler_calls'] ) && 'Signal' === $GLOBALS['__handler_calls'][0]['site_name'], 'the handler ran with the posted values' );
	ok( array( 'success', 'Identity settings saved.' ) === $st->get( 'notice' ) && 'identity_saved' === $st->get( 'flash' ),
		'the flash becomes the classic notice, and the CODE is kept (the Webhooks leaf reads an id out of it)' );
	ok( array( 'Identity settings saved.' ) === $os->toasts, '   ...and the same sentence is toasted, as plain text' );
	ok( 'site' === $st->get( 'tab' ) && 'identity-and-seo' === $st->get( 'sub' ) && array() === $st->get( 'params' ),
		'the redirect target is applied to state, and the query params are dropped -- the classic redirect keeps only page/tab/sub/sn_flash' );
	ok( array( 2 ) === $os->badges && 1 === $os->refresh, 'a save re-reads the badge and asks the shell for a fresh payload' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'stale', 'sn_action' => 'save_identity' ) ) );
	ok( array( 'Nothing was saved: the form expired. Reopen the tab and try again.' ) === $os->toasts && null === $st->get( 'notice' ),
		'a stale nonce says so, and paints no notice: a refusal is a verdict, not silence' );

	$os = new \OpenStation\App\Os();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'not_a_handler' ) ) );
	ok( array( 'Nothing was saved.' ) === $os->toasts, 'an action outside the handler table is one toast and nothing else' );

	$GLOBALS['__caps']['manage_options'] = false;
	$os = new \OpenStation\App\Os();
	$GLOBALS['__handler_calls'] = array();
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'save_identity' ) ) );
	ok( array() === $GLOBALS['__handler_calls'] && 1 === count( $os->toasts ), 'without manage_options nothing runs and the reason is said' );
	$GLOBALS['__caps']['manage_options'] = true;

	$os = new \OpenStation\App\Os();
	$st = new \OpenStation\App\State( $app->state, array( 'tab' => 'connections', 'sub' => 'webhooks' ) );
	$app->actions['post']( $st, $os, array( 'values' => array( '_wpnonce' => 'good-nonce', 'sn_action' => 'webhook_add' ) ) );
	ok( 'wh_added_abc123' === $st->get( 'flash' ), 'a webhook add keeps its flash code in state' );
	$painted_before = count( $GLOBALS['__painted'] );
	paint( $app, $st->all() );
	$query = $GLOBALS['__painted'][ $painted_before ]['get'];
	ok( 'abc123' === ( $query['new_id'] ?? '' ),
		'   ...and the next paint lends the leaf ?new_id=abc123, exactly as inc/admin-page.php extracts it -- without it the new secret is never shown, once' );

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
	$GLOBALS['__painted'] = array();
	$html = paint( $app, array( 'tab' => 'monitoring', 'sub' => 'health', 'params' => array( 'sn_tag_preview' => '1' ) ) );
	$call = $GLOBALS['__painted'][0];
	ok( 'monitoring' === $call['tab'] && 'health' === $call['sub'], 'the dispatcher is called with the canonical tab and leaf from state' );
	ok( 'sn-theme-options' === $call['get']['page'] && 'monitoring' === $call['get']['tab'] && 'health' === $call['get']['sub'] && '1' === $call['get']['sn_tag_preview'],
		'   ...under the query the classic URL would have carried, `sn_*` params included' );
	ok( false !== strpos( $html, '<h1 class="sn-page-h1">Signal &amp; Noise</h1>' ), 'the page heading is the classic one, byte for byte' );
	ok( false !== strpos( $html, '<p class="sn-page-subtitle">' . esc_html( sn_admin_page_subtitle_for_tab( 'monitoring' ) ) . '</p>' ),
		'the subtitle comes from the registry, for the tab in state' );
	ok( false !== strpos( $html, '<nav class="nav-tab-wrapper sn-nav-tabs">' ), 'the tab strip keeps the core classes the desktop already styles' );

	$strip = array();
	if ( preg_match_all( '#<a class="nav-tab[^"]*" os-action="go" os-arg-tab="([^"]+)"[^>]*>([^<]+)</a>#', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $one ) { $strip[ $one[1] ] = html_entity_decode( $one[2], ENT_QUOTES ); }
	}
	ok( array_column( sn_admin_top_tabs(), 'label', 'tab' ) === $strip,
		'the tab strip IS sn_admin_top_tabs() -- every slug, every label, in registry order; hardcode one and this goes red' );
	ok( false !== strpos( $html, 'class="nav-tab nav-tab-active" os-action="go" os-arg-tab="monitoring"' ),
		'the active tab wears nav-tab-active, as it does on the classic page' );
	ok( false === strpos( $html, '<a class="nav-tab' ) || false === strpos( $html, 'nav-tab" href' ),
		'no tab-strip link keeps an href -- a click is not preventDefaulted, and an href would navigate the desktop away' );

	if ( '' === $GLOBALS['__html_api'] ) {
		skip( 'the captured leaf came back rewritten -- no wp-includes/html-api on this machine' );
		skip( 'the leaf\'s own sub-tab nav became `go` links -- no wp-includes/html-api on this machine' );
	} else {
		ok( false !== strpos( $html, 'os-action="post"' ) && false === strpos( $html, '<form method="post">' ),
			'the captured leaf came back rewritten: its form dispatches `post`' );
		ok( false !== strpos( $html, '<a os-action="go"' ) && false !== strpos( $html, 'os-arg-sub="health"' ) && false !== strpos( $html, 'os-arg-tab="monitoring"' ),
			'   ...and the sub-tab nav the dispatcher printed became `go` links -- the strip is the leaf\'s, captured, never rebuilt here' );
	}

	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'front-end', 'notice' => array( 'error', 'It <a href="x">broke</a>.' ) ) );
	ok( false !== strpos( $html, '<div class="notice notice-error is-dismissible"><p>It <a href="x">broke</a>.</p></div>' ),
		'the notice is the classic markup, and its deliberate inline <a> survives -- about fifteen flash codes ship one' );
	ok( strpos( $html, 'notice notice-error' ) < strpos( $html, 'nav-tab-wrapper' ), '   ...above the tab strip, where the classic page puts it' );

	$html = paint( $app, array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => 'identity' ) );
	ok( false !== strpos( $html, '<div class="wrap" data-snt-anchor="sn-sec-identity">' ),
		'a post-save anchor is painted as data-snt-anchor, carrying the ELEMENT ID and not the bare slug state keeps -- assets/os-host.js looks the value up as an id, and sn_admin_render_section() emits id="sn-sec-<slug>"' );
	$anchored = paint( $app, array( 'tab' => 'site', 'sub' => 'front-end', 'anchor' => 'front-end' ) );
	ok( preg_match( '#data-snt-anchor="([^"]+)"#', $anchored, $m ) && false !== strpos( $anchored, 'id="' . $m[1] . '"' ),
		'   ...and the id it names is one the SAME paint actually contains: the attribute and the section land together, so a miss is a wrong name' );
	ok( false !== strpos( paint( $app, array( 'tab' => 'site' ) ), '<div class="wrap">' ), 'no anchor, no attribute' );

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
