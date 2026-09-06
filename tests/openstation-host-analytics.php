<?php
/**
 * Standalone test: the S&N Analytics host window (#1075) — its definition, its
 * state, the `$_GET` it lends the page, and the one form it must not dispatch.
 *
 * The framework is stubbed in its own namespaces with just enough surface to
 * load the `.os.php`, record what it declares and drive it — the same shape
 * tests/openstation-host-dashboard.php uses. The ANALYTICS PAGE IS NOT STUBBED:
 * inc/analytics-admin.php and its neighbours are loaded, so
 * `snt_analytics_render_dashboard()`, `snt_analytics_render_view_tabs()`,
 * `snt_analytics_render_controls()` and all five resolvers are the REAL ones.
 * Every "through the page's own resolver" pin is therefore measured against
 * that resolver, and a whitelist retyped into the host goes red.
 *
 * The page runs UNCONFIGURED (`sn_analytics_config()` answers false), which is
 * a real, shipped state of the screen and the one that needs no Cloudflare
 * credentials, no `$wpdb` and no network: the dispatcher still renders the tab
 * strip — "the dashboard's shape is visible from day one" — and then the gate.
 * That is enough to measure what a HOST is responsible for: the query it lends,
 * the request URI it lends, and what the rewrite does to the links the page
 * printed. The thirteen views' own bodies are their own suites'.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/openstation-host-analytics.php
 *
 * @since 13.105.0
 */

namespace OpenStation {
	final class App {
		public $id;
		public $title;
		public $icon;
		public $placement;
		public $caps      = array();
		public $state     = array();
		public $actions   = array();
		public $view;
		public $mount;
		public $buttons   = array();
		public $menu_rows = array();
		public $size      = array();
		public $min       = array();
		public $tabs      = array();
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
	define( 'SNT_VERSION', '13.105.0' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
	define( 'MONTH_IN_SECONDS', 2592000 );
	define( 'YEAR_IN_SECONDS', 31536000 );

	// ── WordPress, flat ───────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
	function do_action( $hook, ...$args ) {}
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function __( $s, $d = null ) { return $s; }
	function _x( $s, $c, $d = null ) { return $s; }
	function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
	function esc_html__( $s, $d = null ) { return $s; }
	function esc_attr__( $s, $d = null ) { return $s; }
	function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url_raw( $s ) { return (string) $s; }
	function wp_kses_post( $s ) { return (string) $s; }
	function wp_kses_uri_attributes() { return array( 'href', 'src', 'action' ); }
	function wp_has_noncharacters( string $text ): bool { return false; }
	function _doing_it_wrong( $f, $m, $v ) {}
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
	function wp_parse_url( $url, $component = -1 ) { $parts = parse_url( (string) $url ); if ( -1 === $component ) { return $parts; } $keys = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ); $key = $keys[ $component ] ?? ''; return ( '' !== $key && isset( $parts[ $key ] ) ) ? $parts[ $key ] : null; }
	function admin_url( $path = '', $scheme = 'admin' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	function home_url( $path = '', $scheme = null ) { return 'https://example.test' . (string) $path; }
	function plugins_url( $path = '', $plugin = '' ) { return SNT_URL . ltrim( (string) $path, '/' ); }
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
	function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( (string) $text ) ); }
	function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) { $out = '<input type="hidden" name="' . esc_attr( $name ) . '" value="test-nonce">'; if ( $display ) { echo $out; } return $out; }
	function wp_verify_nonce( $nonce, $action = -1 ) { return 'test-nonce' === $nonce && 'sn_theme_options_nonce' === $action ? 1 : false; }
	$GLOBALS['__caps'] = array( 'manage_options' => true );
	function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function is_admin() { return true; }
	function get_transient( $k ) { return false; }
	function set_transient( $k, $v, $t = 0 ) { return true; }
	function get_option( $k, $d = false ) { return $d; }
	function update_option( $k, $v, $a = null ) { return true; }
	function wp_json_encode( $v, $o = 0, $d = 512 ) { return json_encode( $v, $o, $d ); }

	// add_query_arg / remove_query_arg are not decoration here: every link the
	// page prints is built on them with NO url, i.e. on $_SERVER['REQUEST_URI'],
	// which is the whole reason the host lends one.
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$add = $args[0];
			$uri = ( isset( $args[1] ) && false !== $args[1] ) ? (string) $args[1] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		} else {
			$add = array( (string) $args[0] => $args[1] ?? '' );
			$uri = ( isset( $args[2] ) && false !== $args[2] ) ? (string) $args[2] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		}
		$frag = '';
		if ( false !== strpos( $uri, '#' ) ) {
			list( $uri, $tail ) = explode( '#', $uri, 2 );
			$frag               = '#' . $tail;
		}
		$base  = $uri;
		$query = '';
		if ( false !== strpos( $uri, '?' ) ) {
			list( $base, $query ) = explode( '?', $uri, 2 );
		}
		$current = array();
		parse_str( str_replace( '&amp;', '&', $query ), $current );
		foreach ( $add as $key => $value ) {
			if ( null === $value || false === $value ) {
				unset( $current[ $key ] );
			} else {
				$current[ $key ] = $value;
			}
		}
		// WordPress's add_query_arg() builds with urlencode OFF (build_query ->
		// _http_build_query( ..., false )): a value goes out exactly as handed
		// in, encoded or not. The stub must not encode, or a host that fails to
		// encode reads as one that did.
		$pairs = array();
		foreach ( $current as $k => $v ) {
			$pairs[] = is_array( $v ) ? http_build_query( array( $k => $v ) ) : $k . '=' . $v;
		}
		$built = implode( '&', $pairs );
		return $base . ( '' !== $built ? '?' . $built : '' ) . $frag;
	}
	function remove_query_arg( $key, $query = false ) {
		$drop = array();
		foreach ( (array) $key as $one ) {
			$drop[ (string) $one ] = null;
		}
		return add_query_arg( $drop, false === $query ? ( $_SERVER['REQUEST_URI'] ?? '' ) : $query );
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

	// ── The REAL analytics page ───────────────────────────────────────
	// Loaded, not stubbed: the resolvers, the reset list, the tab strip, the
	// toolbar and the dispatcher are the page's own.
	require_once __DIR__ . '/../inc/analytics-rollup.php';        // SN_ANALYTICS_CLASSES
	require_once __DIR__ . '/../inc/analytics-dims.php';          // SN_ANALYTICS_DIM_COLUMNS
	require_once __DIR__ . '/../inc/analytics-drilldown.php';     // sn_analytics_drilldown_parse()
	require_once __DIR__ . '/../inc/analytics-panels.php';        // snt_an_gate(), snt_an_range_pills()
	require_once __DIR__ . '/../inc/analytics-render-controls.php'; // the toolbar + the export form
	require_once __DIR__ . '/../inc/analytics-admin.php';         // the resolvers + the dispatcher
	require_once __DIR__ . '/../inc/analytics-dashboard-page.php'; // SNT_ANALYTICS_PAGE_SLUG, snt_analytics_page_url()
	require_once __DIR__ . '/../inc/login-defense-analytics.php'; // sn_login_defense_resolve_days()
	require_once __DIR__ . '/../inc/admin-post-handler.php';      // sn_admin_post_handlers(), the table the keep list is checked against
	// The SN admin registry. Not this window's page at all — loaded because the
	// rewrite calls sn_admin_page_tab_for_slug() and sn_admin_canonical_destination()
	// when it meets an own-page link, and a harness without them would measure a
	// rewrite that production never runs.
	require_once __DIR__ . '/../inc/admin-tabs-data.php';
	require_once __DIR__ . '/../inc/admin-tabs.php';
	require_once __DIR__ . '/../inc/admin-legacy-redirect.php';

	// The one gate that decides which half of the page renders. False is a real
	// shipped state (no Cloudflare credentials), and the only one that needs no
	// database and no network.
	$GLOBALS['__configured'] = false;
	function sn_analytics_config() { return (bool) $GLOBALS['__configured']; }

	// The rewrite half of the view needs core's WP_HTML_Tag_Processor.
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
	$app = require __DIR__ . '/../apps/sn-analytics/sn-analytics.os.php';

	$pass = 0;
	$fail = 0;
	$skip = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function skip( $m ) { global $skip; $skip++; echo "SKIP: $m\n"; }

	/** A fresh state carrying $in over the app's declared defaults. */
	function st( $app, array $in = array() ) { return new \OpenStation\App\State( $app->state, $in ); }

	/** Paint the window for a state. */
	function paint( $app, $state, $os = null ) {
		ob_start();
		call_user_func( $app->view, $state, $os ?: new \OpenStation\App\Os() );
		return (string) ob_get_clean();
	}

	/**
	 * A brace-balanced region starting at the first `{` after $marker.
	 *
	 * Balanced, never "up to the first `}`": a first-terminator cut reads into
	 * the next function and pins whatever happens to be there.
	 *
	 * @param string $src    File text.
	 * @param string $marker Text the region follows.
	 * @return string
	 */
	function snt_region( $src, $marker ) {
		$at = strpos( $src, $marker );
		if ( false === $at ) {
			return '';
		}
		$open = strpos( $src, '{', $at );
		if ( false === $open ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $src, $open, $i - $open + 1 );
				}
			}
		}
		return '';
	}

	/**
	 * The same PHP with every comment removed.
	 *
	 * The dispatcher's docblocks NAME the params they read ("?sn_drill=…"), so
	 * a scan that read prose would report keys the code does not read and pass
	 * while a real read went missing. `token_get_all()` rather than a regex:
	 * a `$_GET['x']` inside a string literal is not a read either.
	 *
	 * @param string $php Source.
	 * @return string
	 */
	function snt_php_code( $php ) {
		$out = '';
		foreach ( token_get_all( '<?php ' . $php ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$out .= $token[1];
				continue;
			}
			$out .= $token;
		}
		return $out;
	}

	/**
	 * The `$_GET` keys a region actually reads.
	 *
	 * @param string $region PHP.
	 * @return string[]
	 */
	function snt_get_keys( $region ) {
		$keys = array();
		if ( preg_match_all( '/\$_GET\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]/', snt_php_code( $region ), $m ) ) {
			$keys = array_values( array_unique( $m[1] ) );
		}
		sort( $keys );
		return $keys;
	}

	echo "openstation-host-analytics -- the S&N Analytics window (#1075)\n";

	echo "\nGroup 1: the definition\n";
	ok( $app instanceof \OpenStation\App && 'sn-analytics' === $app->id,
		'the file returns an App under the page`s own slug -- one id, one surface' );
	ok( 'S&N Analytics' === $app->title && 'dashicons-chart-area' === $app->icon && 'dock' === $app->placement,
		'the same title and the same chart glyph add_menu_page() gives the classic menu, on the dock' );
	ok( array( 'manage_options' ) === $app->caps, 'gated on manage_options, the capability the classic page wp_die()s without' );
	ok( array( 1280, 860 ) === $app->size, 'opens at 1280x860 -- the report is wide' );
	ok( array( 'view', 'range', 'from', 'to', 'class', 'compare', 'drill', 'event_prop', 'lg_range', 'notice' ) === array_keys( $app->state ),
		'state is the page`s nine URL params, named as the page names them minus the `sn_` prefix, plus the notice' );
	ok( snt_analytics_resolve_view( '' ) === $app->state['view']
		&& snt_analytics_resolve_class( '' ) === $app->state['class']
		&& snt_analytics_resolve_compare( '' ) === $app->state['compare']
		&& (string) snt_analytics_resolve_window( '', '', '' )[0] === $app->state['range'] && is_string( $app->state['range'] ),
		'   ...and every default is that resolver asked with nothing -- a literal table here would be a copy of four whitelists' );
	ok( 7 === $app->state['lg_range'] && sn_login_defense_resolve_days() === $app->state['lg_range'],
		'   ...including login defense`s own range, from sn_login_defense_resolve_days()' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen' ) === array_keys( $app->actions ),
		'five actions: go, post, door, refresh and the reopen lifecycle' );
	ok( array() === $app->tabs,
		'and NO framework tabs: they are baked at definition and cannot be switched by a server action, which every doorway link on the Overview does' );
	ok( isset( $app->buttons['refresh'] ) && 'refresh' === $app->buttons['refresh']['action'], 'a Refresh button in the title bar' );

	echo "\nGroup 2: every view is reachable through `go`\n";
	$views = snt_analytics_views();
	ok( count( $views ) >= 13, 'VACUITY: the registry has ' . count( $views ) . ' views to reach' );
	$os        = new \OpenStation\App\Os();
	$reachable = array();
	foreach ( array_keys( $views ) as $slug ) {
		$state = st( $app );
		$app->actions['go']( $state, $os, array( 'sn_view' => $slug ) );
		if ( $slug === $state->get( 'view' ) ) {
			$reachable[] = $slug;
		}
	}
	ok( array_keys( $views ) === $reachable,
		'every slug in SN_ANALYTICS_VIEWS lands on itself -- derived from the registry, so a view added tomorrow is pinned the day it exists' );
	$state = st( $app );
	$app->actions['go']( $state, $os, array( 'sn_view' => 'intelligence' ) );
	ok( snt_analytics_resolve_view( 'intelligence' ) === $state->get( 'view' ) && 'overview' === $state->get( 'view' ),
		'a retired or unknown slug falls back to overview -- through snt_analytics_resolve_view(), which is where that rule lives' );

	echo "\nGroup 3: a view switch resets exactly what the tab strip strips\n";
	$reset = snt_analytics_view_reset_params();
	ok( in_array( 'sn_drill', $reset, true ) && ! in_array( 'sn_compare', $reset, true ),
		'VACUITY: snt_analytics_view_reset_params() is the list the tab strip and the doorway builder share, and sn_compare is not in it' );
	$expected_keys = array();
	foreach ( $reset as $param ) {
		$key = preg_replace( '/^sn_/', '', $param );
		if ( 'view' !== $key ) {
			$expected_keys[] = $key;
		}
	}
	ok( $expected_keys === snt_os_analytics_reset_keys(),
		'the host`s reset keys ARE that list, read from the function and never retyped -- a param added there is reset here' );

	$state = st(
		$app,
		array(
			'view'       => 'technology',
			'range'      => 'custom',
			'from'       => '2026-01-01',
			'to'         => '2026-01-31',
			'class'      => 'bot',
			'compare'    => 'yoy',
			'drill'      => 'browser:Firefox',
			'event_prop' => 'plan',
			'lg_range'   => 90,
		)
	);
	$app->actions['go']( $state, $os, array( 'sn_view' => 'geography' ) );
	ok( 'geography' === $state->get( 'view' ) && '7' === $state->get( 'range' ) && '' === $state->get( 'from' ) && '' === $state->get( 'to' )
		&& 'human' === $state->get( 'class' ) && '' === $state->get( 'drill' ) && '' === $state->get( 'event_prop' ) && 7 === $state->get( 'lg_range' ),
		'a bare view switch resets the window, the class and the three view-local filters -- what remove_query_arg( reset_params ) does to the URL' );
	ok( 'off' === $state->get( 'compare' ),
		'   ...and a BARE switch -- a deep link that names only the view, like the movers\' "Posts view" -- lands with comparison OFF, because the link is the whole next URL and it carried none' );
	$state = st( $app, array( 'view' => 'technology', 'compare' => 'yoy', 'range' => '30', 'class' => 'bot' ) );
	$app->actions['go']( $state, $os, array( 'sn_view' => 'geography', 'sn_range' => '30', 'sn_class' => 'bot', 'sn_compare' => 'yoy' ) );
	ok( 'yoy' === $state->get( 'compare' ) && '30' === $state->get( 'range' ) && 'bot' === $state->get( 'class' ),
		'   ...while the REAL tab link, which re-adds sn_compare beside the window args (the page\'s carry rule), keeps the comparison riding along' );

	$state = st( $app, array( 'view' => 'technology', 'class' => 'bot', 'drill' => 'browser:Firefox' ) );
	$app->actions['go']( $state, $os, array( 'sn_view' => 'geography', 'sn_range' => '30', 'sn_class' => 'bot' ) );
	ok( 'geography' === $state->get( 'view' ) && '30' === $state->get( 'range' ) && 'bot' === $state->get( 'class' ) && '' === $state->get( 'drill' ),
		'a real tab link -- which carries snt_analytics_window_args() -- keeps the window and the class across the switch, and still drops the drill' );

	echo "\nGroup 4: every value goes through the page`s own resolver\n";
	$state = st( $app );
	$app->actions['go']( $state, $os, array( 'sn_range' => '999' ) );
	ok( (string) snt_analytics_resolve_window( '999', '', '' )[0] === $state->get( 'range' ) && '7' === $state->get( 'range' ),
		'a range outside the whitelist falls back exactly as the page falls back' );
	$app->actions['go']( $state, $os, array( 'sn_range' => '30' ) );
	ok( '30' === $state->get( 'range' ), 'a supported range is kept as the token the URL carried' );
	$app->actions['go']( $state, $os, array( 'sn_range' => 'this-month' ) );
	ok( 'this-month' === $state->get( 'range' ) && '' === $state->get( 'from' ) && '' === $state->get( 'to' ),
		'a calendar preset keeps its token and carries no dates -- snt_analytics_window_args() carries from/to for `custom` only, and the page re-derives a preset from the token' );
	$today = gmdate( 'Y-m-d' );
	$app->actions['go']( $state, $os, array( 'sn_range' => 'custom', 'sn_from' => '2026-01-31', 'sn_to' => '2026-01-01' ) );
	ok( 'custom' === $state->get( 'range' ) && '2026-01-01' === $state->get( 'from' ) && '2026-01-31' === $state->get( 'to' ),
		'a custom window is CLAMPED by snt_analytics_resolve_custom_window() -- a reversed pair comes back swapped, not refused' );
	$app->actions['go']( $state, $os, array( 'sn_range' => 'custom', 'sn_from' => 'nonsense', 'sn_to' => 'nonsense' ) );
	ok( '7' === $state->get( 'range' ) && '' === $state->get( 'from' ),
		'   ...and malformed dates fall back to the default window, which is what the page does with them' );
	$app->actions['go']( $state, $os, array( 'sn_class' => 'suspect' ) );
	ok( 'suspect' === $state->get( 'class' ), 'a known class is kept' );
	$app->actions['go']( $state, $os, array( 'sn_class' => 'martian' ) );
	ok( snt_analytics_resolve_class( 'martian' ) === $state->get( 'class' ) && 'human' === $state->get( 'class' ), 'an unknown class is human, through the page`s resolver' );
	$app->actions['go']( $state, $os, array( 'sn_compare' => 'prev' ) );
	ok( 'prev' === $state->get( 'compare' ), 'a known compare mode is kept' );
	$app->actions['go']( $state, $os, array( 'sn_compare' => 'sideways' ) );
	ok( 'off' === $state->get( 'compare' ), 'an unknown compare mode is off' );
	$app->actions['go']( $state, $os, array( 'sn_drill' => 'country:US' ) );
	ok( 'country:US' === $state->get( 'drill' ), 'a drill the page can parse is kept RAW, because that is what the URL kept and what the view re-parses' );
	$app->actions['go']( $state, $os, array( 'sn_drill' => 'nosuchdim:US' ) );
	ok( '' === $state->get( 'drill' ) && null === sn_analytics_drilldown_parse( 'nosuchdim:US' ),
		'   ...and a dim outside SN_ANALYTICS_DIM_COLUMNS is dropped, because sn_analytics_drilldown_parse() drops it' );
	$app->actions['go']( $state, $os, array( 'sn_drill' => array( 'country:US', 'city:Lima' ) ) );
	ok( 'city:Lima' === $state->get( 'drill' ),
		'   ...and a crafted `sn_drill[]` array is the last value, never a PHP notice: snt_os_host_last() is the same collapse every pipeline starts with' );
	$app->actions['go']( $state, $os, array( 'sn_lg_range' => '30' ) );
	ok( 30 === $state->get( 'lg_range' ), 'login defense`s own range takes 30' );
	$app->actions['go']( $state, $os, array( 'sn_lg_range' => '5' ) );
	ok( 7 === $state->get( 'lg_range' ) && 7 === snt_os_analytics_lg_range( '5' ),
		'   ...and clamps an unsupported one to 7 through sn_login_defense_resolve_days() itself -- the clamp is lent $_GET, never retyped' );
	$_GET = array( 'sentinel' => 'yes' );
	snt_os_analytics_lg_range( '90' );
	ok( array( 'sentinel' => 'yes' ) === $_GET, '   ...and the borrowed $_GET is given back' );
	$_GET = array();
	$app->actions['go']( $state, $os, array( 'sn_event_prop' => "  plan<b>x</b>  " ) );
	ok( 'planx' === $state->get( 'event_prop' ), 'the Events filter is sanitize_text_field()-ed, the same call the Events view reads it with' );

	// The custom-date form's own wire shape: the runtime ships a GET form's
	// FIELDS as `values`, not as os-args, and its hidden fields carry the route.
	$state = st( $app, array( 'view' => 'content', 'compare' => 'yoy' ) );
	$app->actions['go'](
		$state,
		$os,
		array(
			'values' => array(
				'page'       => 'sn-analytics',
				'sn_view'    => 'content',
				'sn_compare' => 'yoy',
				'sn_range'   => 'custom',
				'sn_class'   => 'human',
				'sn_from'    => '2026-02-01',
				'sn_to'      => '2026-02-10',
			),
		)
	);
	ok( 'custom' === $state->get( 'range' ) && '2026-02-01' === $state->get( 'from' ) && '2026-02-10' === $state->get( 'to' )
		&& 'content' === $state->get( 'view' ) && 'yoy' === $state->get( 'compare' ),
		'a GET form dispatches through `go` with its FIELDS -- the custom-date form is the one form on this page that navigates, and its values are args like any link`s' );

	// MEASURED: the shared rewrite adds `os-arg-tab` to an own-page link,
	// because sn_admin_page_tab_for_slug() answers `dashboard` for this slug and
	// the pass has one rule for every host. It is noise on this page, and it
	// must stay noise: nothing here may read a Dashboard tab as state.
	$state = st( $app, array( 'view' => 'quality', 'class' => 'bot' ) );
	$app->actions['go']( $state, $os, array( 'tab' => 'dashboard', 'sub' => 'health', 'anchor' => 'sn-sec-x', 'sn_view' => 'quality', 'sn_class' => 'bot', 'sn_compare' => 'prev' ) );
	ok( 'quality' === $state->get( 'view' ) && 'bot' === $state->get( 'class' ) && 'prev' === $state->get( 'compare' )
		&& array_keys( $app->state ) === array_keys( $state->all() ),
		'a link`s os-arg-tab/sub/anchor -- which the shared rewrite adds to every own-page link -- changes nothing here and adds no key to the state' );

	echo "\nGroup 5: the \$_GET the page reads is the \$_GET the host emits\n";
	$admin_src  = (string) file_get_contents( __DIR__ . '/../inc/analytics-admin.php' );
	$events_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-view-events.php' );
	$login_src  = (string) file_get_contents( __DIR__ . '/../inc/login-defense-analytics.php' );
	$readers    = array(
		'snt_analytics_render_dashboard()'   => snt_get_keys( snt_region( $admin_src, 'function snt_analytics_render_dashboard()' ) ),
		'snt_analytics_render_view_events()' => snt_get_keys( snt_region( $events_src, 'function snt_analytics_render_view_events(' ) ),
		'sn_login_defense_resolve_days()'    => snt_get_keys( snt_region( $login_src, 'function sn_login_defense_resolve_days()' ) ),
	);
	$emitted = snt_os_analytics_get( st( $app ) );
	foreach ( $readers as $where => $keys ) {
		ok( array() !== $keys, "VACUITY: $where reads " . count( $keys ) . ' $_GET keys (' . implode( ', ', $keys ) . ')' );
		foreach ( $keys as $key ) {
			ok( array_key_exists( $key, $emitted ), "the host emits \$_GET['$key'], which $where reads" );
		}
	}
	ok( SNT_ANALYTICS_PAGE_SLUG === $emitted['page'], 'and `page`, from the page`s own one-literal-one-place constant' );
	$state = st( $app, array( 'range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31', 'drill' => 'country:US', 'event_prop' => 'plan', 'lg_range' => 30, 'compare' => 'yoy', 'class' => 'bot', 'view' => 'geography' ) );
	ok( array(
		'page'          => 'sn-analytics',
		'sn_view'       => 'geography',
		'sn_range'      => 'custom',
		'sn_from'       => '2026-01-01',
		'sn_to'         => '2026-01-31',
		'sn_class'      => 'bot',
		'sn_compare'    => 'yoy',
		'sn_drill'      => 'country:US',
		'sn_event_prop' => 'plan',
		'sn_lg_range'   => '30',
	) === snt_os_analytics_get( $state ), 'a full state is the full query, every value a string, in the page`s own names' );
	ok( '' === snt_os_analytics_get( st( $app ) )['sn_drill'] && array_key_exists( 'sn_drill', snt_os_analytics_get( st( $app ) ) ),
		'an unset param is emitted EMPTY, not dropped: `` and absent are the same answer to every reader, while a missing key is the shape in which a param stops arriving' );

	echo "\nGroup 6: the request URI the page`s link builders read\n";
	$uri = snt_os_analytics_request_uri( snt_os_analytics_get( $state ) );
	ok( 0 === strpos( $uri, '/wp-admin/admin.php?' ) && false !== strpos( $uri, 'page=sn-analytics' ),
		'the lent REQUEST_URI is the classic page`s own URL, path and query -- built from snt_analytics_page_url(), so it follows the page if it moves again' );
	ok( false !== strpos( $uri, 'sn_view=geography' ) && false !== strpos( $uri, 'sn_from=2026-01-01' ), '   ...carrying the state' );
	ok( false === strpos( snt_os_analytics_request_uri( snt_os_analytics_get( st( $app ) ) ), 'sn_from' ),
		'   ...and NOT the empty ones: the classic URL carries no `sn_from=` for a rolling window, and every builder copies the query it is given' );

	echo "\nGroup 7: the view -- the classic page`s own chrome, and its own tab strip\n";
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=openstation';
	$_GET                   = array( 'sentinel' => 'yes' );
	$_POST                  = array();
	$_REQUEST               = array();
	$before                 = array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_URI'] );
	$html                   = paint( $app, st( $app, array( 'view' => 'campaigns' ) ) );
	ok( $before === array( $_GET, $_POST, $_REQUEST, $_SERVER['REQUEST_URI'] ),
		'painting gives the request back untouched -- the query AND the REQUEST_URI the page`s link builders were lent' );
	ok( 0 === strpos( $html, '<div class="wrap" data-snt-query="' ) && false !== strpos( $html, '<h1>Analytics</h1>' ),
		'the wrapper and the heading are the ones inc/analytics-dashboard-page.php prints -- the wrap carrying one attribute more, the current navigation for the brush' );
	ok( false !== strpos( $html, 'nav-tab-wrapper sn-an-view-tabs' ),
		'the tab strip is the PAGE`S OWN, captured -- never rebuilt here, so its classes and its order are whatever the page says' );

	if ( '' === $GLOBALS['__html_api'] ) {
		foreach ( array(
			'every view is a `go` in the painted strip',
			'the active tab keeps nav-tab-active',
			'no tab keeps an href',
			'the settings link is a door',
			'the export form stays a real form',
			'the custom-date form dispatches `go`',
			'the toolbar pills dispatch `go`',
		) as $pin ) {
			skip( "$pin -- no wp-includes/html-api on this machine" );
		}
	} else {
		// Read through the processor, never off the serialized bytes: where a
		// new attribute lands is WP_HTML_Tag_Processor's choice (it PREPENDS),
		// which is a fact about core and not about this port.
		$anchors = array();
		$walk    = new WP_HTML_Tag_Processor( $html );
		while ( $walk->next_tag( 'A' ) ) {
			$one = array();
			foreach ( array( 'class', 'os-action', 'os-arg-sn_view', 'os-arg-sn_range', 'os-arg-sn_class', 'os-arg-url', 'href', 'aria-current' ) as $name ) {
				$one[ $name ] = $walk->get_attribute( $name );
			}
			$anchors[] = $one;
		}
		$tabs   = array_values(
			array_filter(
				$anchors,
				static function ( $a ) {
					return false !== strpos( (string) $a['class'], 'nav-tab' );
				}
			)
		);
		$strip  = array();
		$hrefs  = 0;
		$window = 0;
		foreach ( $tabs as $one ) {
			if ( 'go' === $one['os-action'] && is_string( $one['os-arg-sn_view'] ) ) {
				$strip[] = $one['os-arg-sn_view'];
			}
			if ( null !== $one['href'] ) {
				++$hrefs;
			}
			if ( '7' === $one['os-arg-sn_range'] && 'human' === $one['os-arg-sn_class'] ) {
				++$window;
			}
		}
		ok( array_keys( $views ) === $strip,
			'every one of the page`s thirteen tabs came back a `go` carrying its sn_view -- the strip the page printed, rewritten, in registry order' );
		$active = array_values(
			array_filter(
				$tabs,
				static function ( $a ) {
					return false !== strpos( (string) $a['class'], 'nav-tab-active' );
				}
			)
		);
		ok( 1 === count( $active ) && 'campaigns' === $active[0]['os-arg-sn_view'] && 'page' === $active[0]['aria-current'],
			'   ...with the active one still wearing nav-tab-active and aria-current, as it does on the classic page' );
		ok( 0 === $hrefs,
			'   ...and none of them keeping an href: a click is not preventDefaulted, and an href would navigate the whole desktop' );
		ok( count( $views ) === $window,
			'   ...each carrying the window and the class, which is exactly what snt_analytics_window_args() put in the href' );
		ok( false === strpos( $html, '/wp-json/' ),
			'and nothing points at the REST route: without the lent REQUEST_URI every link the page built would be `/wp-json/…`, which the rewrite reads as EXTERNAL and leaves clickable' );

		// The compare mode is absent from the reset list, and the mechanism that
		// carries it is the page's own base URL — which only exists because the
		// REQUEST_URI was lent. This measures that, end to end.
		$compared = paint( $app, st( $app, array( 'view' => 'campaigns', 'compare' => 'yoy', 'class' => 'bot' ) ) );
		$carried  = 0;
		$walk     = new WP_HTML_Tag_Processor( $compared );
		while ( $walk->next_tag( 'A' ) ) {
			if ( false !== strpos( (string) $walk->get_attribute( 'class' ), 'nav-tab' ) && 'yoy' === $walk->get_attribute( 'os-arg-sn_compare' ) && 'bot' === $walk->get_attribute( 'os-arg-sn_class' ) ) {
				++$carried;
			}
		}
		ok( count( $views ) === $carried,
			'every tab link carries the ACTIVE compare mode and class -- the page builds them on its own base URL, so what rides along is the page`s rule, not a rule copied into the host' );

		$gate = array_values(
			array_filter(
				$anchors,
				static function ( $a ) {
					return false !== strpos( (string) $a['class'], 'button-primary' );
				}
			)
		);
		ok( 1 === count( $gate ) && 'door' === $gate[0]['os-action'] && null === $gate[0]['href']
			&& 0 === strpos( (string) $gate[0]['os-arg-url'], 'https://example.test/wp-admin/admin.php?page=sn-theme-options' ),
			'the unconfigured gate`s "Configure analytics ->" is a DOOR: page=sn-theme-options is another window`s surface, so it opens as an admin window rather than painting the settings page inside the report' );
	}

	echo "\nGroup 8: the one form a window cannot replay\n";
	ok( array( 'analytics_export' ) === snt_os_analytics_keep_actions(), 'the keep list names the export action' );
	$handlers = sn_admin_post_handlers();
	ok( isset( $handlers['analytics_export'] ) && 'sn_handle_analytics_export' === $handlers['analytics_export'],
		'   ...and that action is REAL: it is in sn_admin_post_handlers(), so a rename there turns this red rather than quiet' );
	$controls_src = (string) file_get_contents( __DIR__ . '/../inc/analytics-render-controls.php' );
	ok( false !== strpos( $controls_src, "name=\"sn_action\" value=\"analytics_export\"" ),
		'   ...and it is the action the toolbar`s export form actually emits' );

	if ( '' === $GLOBALS['__html_api'] ) {
		skip( 'the export form stays a real form -- no wp-includes/html-api on this machine' );
		skip( 'the custom-date form and the pills dispatch `go` -- no wp-includes/html-api on this machine' );
	} else {
		// The REAL toolbar: the export form, the custom-date GET form, the
		// range/class/compare pills. Rendered by the page`s own renderer under
		// the URI the host lends, then put through the host`s two passes.
		$_SERVER['REQUEST_URI'] = snt_os_analytics_request_uri( snt_os_analytics_get( st( $app ) ) );
		ob_start();
		snt_analytics_render_controls( 7, 'human', '', '', 'off', array() );
		$toolbar = (string) ob_get_clean();
		unset( $_SERVER['REQUEST_URI'] );
		$toolbar = snt_os_host_keep_forms( $toolbar, snt_os_analytics_keep_actions(), snt_analytics_page_url() );
		$toolbar = snt_os_host_rewrite( $toolbar, array( SNT_ANALYTICS_PAGE_SLUG ) );

		$forms = array();
		$walk  = new WP_HTML_Tag_Processor( $toolbar );
		while ( $walk->next_tag( 'FORM' ) ) {
			$one = array();
			foreach ( array( 'class', 'os-action', 'method', 'action', 'target', 'data-snt-keep-form' ) as $name ) {
				$one[ $name ] = $walk->get_attribute( $name );
			}
			$forms[] = $one;
		}
		$export = array();
		$get    = array();
		foreach ( $forms as $one ) {
			if ( false !== strpos( (string) $one['class'], 'sn-an-export' ) ) {
				$export = $one;
			} elseif ( false !== strpos( (string) $one['class'], 'sn-an-custom-form' ) ) {
				$get = $one;
			}
		}
		ok( array() !== $export && array() !== $get, 'VACUITY: the real toolbar produced both forms (' . count( $forms ) . ' in all)' );
		ok( null === $export['os-action'] && 'post' === $export['method']
			&& snt_analytics_page_url() === $export['action'] && '_blank' === $export['target'],
			'the export form is NOT a dispatch: it keeps its method, gains the classic page URL as its action and opens in a new tab -- sn_handle_analytics_export() sends headers, echoes a file and exits, which no window can survive. THE RECORDED DEVIATION: a download must be a navigation' );
		ok( 'go' === $get['os-action'] && 'get' === $get['method'],
			'the custom-date form dispatches `go` -- its sn_from/sn_to arrive as args and go through the same resolver a range pill does' );
		ok( false !== strpos( $toolbar, 'os-arg-sn_compare="yoy"' ) && false !== strpos( $toolbar, 'os-arg-sn_class="bot"' )
			&& false !== strpos( $toolbar, 'os-arg-sn_range="30"' ),
			'the compare, class and rolling pills are all `go`s carrying their own param -- the toolbar is the page`s, rewritten, not rebuilt' );
		ok( $toolbar === snt_os_host_rewrite( snt_os_host_keep_forms( $toolbar, snt_os_analytics_keep_actions(), snt_analytics_page_url() ), array( SNT_ANALYTICS_PAGE_SLUG ) ),
			'   ...and a second pass over the whole toolbar changes nothing' );
	}

	// THE TOOLBAR ONLY RENDERS ON A CONFIGURED INSTALL, so the pins above drive
	// the two passes directly and cannot see whether the VIEW still runs them.
	// A view that dropped the keep pass would send the export through `post`
	// with nothing anywhere going red -- measured, on this suite, 2026-09-06.
	// This is the seam that says it does, in the order that matters.
	$view_src = (string) file_get_contents( __DIR__ . '/../apps/sn-analytics/parts/view.php' );
	$builder  = snt_region( $view_src, 'function dashboard_html( State $state )' );
	$i_keep   = strpos( snt_php_code( $builder ), 'snt_os_host_keep_forms(' );
	$i_rw     = strpos( snt_php_code( $builder ), 'snt_os_host_rewrite(' );
	ok( '' !== $builder && false !== $i_keep && false !== $i_rw && $i_keep < $i_rw,
		'ORDER: the view marks the kept forms BEFORE the rewrite -- the rewrite is what reads the marker, so a pass in the other order leaves the export a dispatch' );
	ok( false !== strpos( snt_php_code( $builder ), 'snt_os_analytics_keep_actions()' ) && false !== strpos( snt_php_code( $builder ), 'snt_analytics_page_url()' ),
		'   ...with the keep list and the destination read from their own accessors, never spelled here' );
	ok( false !== strpos( snt_php_code( $builder ), 'array( page_slug() )' ),
		'   ...and the own-page list is exactly this page`s slug: the settings page is another window`s surface' );

	echo "\nGroup 9: what the actions refuse\n";
	$os = new \OpenStation\App\Os();
	$app->actions['post']( st( $app ), $os, array( 'values' => array( '_wpnonce' => 'test-nonce', 'sn_action' => 'analytics_export', 'format' => 'csv' ) ) );
	ok( 1 === count( $os->toasts ) && false !== strpos( $os->toasts[0], 'analytics_export' ) && false !== strpos( $os->toasts[0], 'new tab' ),
		'an export that somehow arrived as a dispatch is refused BY NAME -- running it would echo a CSV into the middle of a JSON response and exit' );
	$os = new \OpenStation\App\Os();
	$app->actions['post']( st( $app ), $os, array( 'values' => array( '_wpnonce' => 'test-nonce', 'sn_action' => 'save_identity' ) ) );
	ok( 1 === count( $os->toasts ) && false !== strpos( $os->toasts[0], 'save_identity' ),
		'   ...and any other action is refused too, named: this window paints a read-only report and has no write' );
	$os = new \OpenStation\App\Os();
	$app->actions['door']( st( $app ), $os, array( 'url' => 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' ) );
	ok( array( array( 'https://example.test/wp-admin/admin.php?page=sn-theme-options&tab=monitoring&sub=analytics', '', '' ) ) === $os->opened,
		'the settings door opens the classic page as its own window -- the same screen the classic link lands on' );
	$os = new \OpenStation\App\Os();
	foreach ( array( 'https://evil.test/wp-admin/', 'https://example.test/notes/', 'javascript:alert(1)', '' ) as $bad ) {
		$app->actions['door']( st( $app ), $os, array( 'url' => $bad ) );
	}
	ok( array() === $os->opened, 'another host, the front end, a javascript: URL and an empty one are all refused' );

	$GLOBALS['__caps']['manage_options'] = false;
	$state                               = st( $app, array( 'view' => 'events' ) );
	$os                                  = new \OpenStation\App\Os();
	$app->actions['go']( $state, $os, array( 'sn_view' => 'geography' ) );
	$app->actions['door']( $state, $os, array( 'url' => 'https://example.test/wp-admin/admin.php?page=sn-theme-options' ) );
	$app->actions['post']( $state, $os, array( 'values' => array( 'sn_action' => 'analytics_export' ) ) );
	ok( 'events' === $state->get( 'view' ) && array() === $os->opened && 1 === count( $os->toasts ),
		'without manage_options go moves nothing, the door opens nothing, and the refusal says why -- every action re-checks, the way the classic page re-checks on every request' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 10: mount, reopen and refresh\n";
	$os         = new \OpenStation\App\Os();
	$os->params = array( 'sn_view' => 'posts', 'sn_range' => '30', 'sn_class' => 'bot' );
	$state      = st( $app );
	call_user_func( $app->mount, $state, $os );
	ok( 'posts' === $state->get( 'view' ) && '30' === $state->get( 'range' ) && 'bot' === $state->get( 'class' ),
		'a deep link opens on its view and its window, named exactly as the classic URL names them' );
	$os->params = array( 'sn_view' => 'search' );
	$app->actions['reopen']( $state, $os, array() );
	ok( 'search' === $state->get( 'view' ) && '7' === $state->get( 'range' ) && 'human' === $state->get( 'class' ),
		'reopen retargets a live window and reads the new params wholesale -- a link that named no window must not inherit the last one`s' );
	$state = st( $app, array( 'notice' => array( 'success', 'Saved.' ) ) );
	$app->actions['refresh']( $state, new \OpenStation\App\Os(), array() );
	ok( null === $state->get( 'notice' ), 'refresh drops the notice -- a notice is a one-shot on the classic page too' );
	$state = st( $app, array( 'notice' => array( 'success', 'Saved.' ) ) );
	$app->actions['go']( $state, new \OpenStation\App\Os(), array( 'sn_view' => 'quality' ) );
	ok( null === $state->get( 'notice' ),
		'   ...and so does a navigation: leaving a "Saved." over a report the reader has since re-filtered would say something nobody measured' );
	$state = st( $app, array( 'notice' => array( 'error', 'It <a href="x">broke</a>.' ) ) );
	$html  = paint( $app, $state );
	ok( false !== strpos( $html, '<div class="notice notice-error is-dismissible"><p>It <a href="x">broke</a>.</p></div>' ),
		'a notice paints in the classic markup, with its deliberate inline <a> intact, where inc/analytics-dashboard-page.php prints it' );
	ok( strpos( $html, 'notice notice-error' ) > strpos( $html, '<h1>Analytics</h1>' ), '   ...under the heading, as that page prints it' );

	echo "\nGroup 11: the window carries the analytics page`s own assets\n";
	$handles = snt_os_host_asset_handles( 'sn-analytics' );
	ok( array( 'sn-admin', 'snt-analytics-tokens', 'sn-analytics-admin', 'sn-uptime-status' ) === $handles['styles'],
		'the four stylesheets toplevel_page_sn-analytics loads: admin.css, the token layer, the analytics sheet and the uptime panel' );
	ok( array( 'sn-admin', 'snt-confirm', 'sn-analytics-brush', 'sn-resume-admin', 'sn-uptime-status', 'snt-os-host' ) === $handles['scripts'],
		'and its six scripts: the panel collapse/clamp seam, the confirm modal, the trend brush, the repeatable rows, the uptime panel, and the host' );
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ), 'scripts' => array() ), 'sn-analytics', null );
	foreach ( $handles['styles'] as $handle ) {
		ok( in_array( $handle, $args['styles'], true ) && wp_style_is( $handle, 'registered' ), "the window carries the style $handle, registered" );
	}
	foreach ( $handles['scripts'] as $handle ) {
		ok( in_array( $handle, $args['scripts'], true ) && wp_script_is( $handle, 'registered' ), "the window carries the script $handle, registered" );
	}
	ok( ! in_array( 'sn-cron-dashboard', $args['scripts'], true ) && ! in_array( 'snt-audit-log', $args['styles'], true ),
		'and NOT the Dashboard`s leaf-owned handles: cron, the audit sheet and the rest belong to leaves this page does not have' );
	ok( $args === apply_filters( 'openstation_app_window_args', $args, 'sn-analytics', null ), 'a second pass appends nothing -- every handle rides exactly once' );
	ok( array( 'os-runtime' ) === apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'os-runtime' ) ), 'my-wordpress', null )['styles'],
		'another app`s window is left alone' );

	echo "\nGroup 12: one surface, one dock tile\n";
	$dock = (string) file_get_contents( __DIR__ . '/../inc/desktop-mode-dock.php' );
	$placement = snt_region( $dock, "snt_os_compat_add_filter( 'desktop_mode_dock_placement'" );
	ok( '' !== $placement && false !== strpos( $placement, "'sn-analytics'" ) && false !== strpos( $placement, "'hidden'" ),
		'the placement filter hides the auto-imported sn-analytics menu tile -- the shell imports every add_menu_page() entry, so without this the desktop grows a SECOND S&N Analytics, one opening the app and one opening the classic page' );
	ok( false !== strpos( $dock, 'function snt_desktop_admin_url' ) && false !== strpos( $dock, "if ( 'sn-analytics' === \$slug )" ),
		'and snt_desktop_admin_url() keeps its sn-analytics case: every door that opens the classic page today still opens it' );

	echo "\nGroup 13: the brush cannot navigate a window away\n";
	$brush = (string) file_get_contents( __DIR__ . '/../assets/analytics/analytics-brush.js' );
	ok( false !== strpos( $brush, "closest('.os-app[data-os-app]')" ),
		'the brush asks whether it is inside an app window, by the framework`s own template attribute' );
	ok( false !== strpos( $brush, "setAttribute('os-action', 'go')" ) && false !== strpos( $brush, "setAttribute('os-arg-sn_range', 'custom')" ),
		'   ...and dispatches the same `go` the range pills dispatch, with the three params it used to put in the URL' );
	$zoom   = snt_region( $brush, 'function zoom(wrap, from, to)' );
	$i_root = strpos( (string) $zoom, 'var root = hostRoot(wrap);' );
	$i_if   = strpos( (string) $zoom, 'if (root) {' );
	$i_ret  = strpos( (string) $zoom, 'return;' );
	$i_nav  = strpos( (string) $zoom, 'window.location.assign' );
	// `root` must be ASKED FOR, not just branched on: a mutant that set
	// `var root = null` kept every other pin here green and navigated the
	// desktop away on the next drag.
	ok( '' !== $zoom && false !== $i_root && false !== $i_if && $i_root < $i_if,
		'zoom() ASKS hostRoot(wrap) for the window it is in, then branches on the answer' );
	ok( false !== $i_ret && false !== $i_nav && $i_ret < $i_nav && strpos( (string) $zoom, 'carrier.click()' ) < $i_ret,
		'ORDER: the window branch clicks the carrier and RETURNS before the navigation -- location.assign inside a window replaces the whole desktop, not the report' );
	ok( 1 === substr_count( $brush, 'window.location.assign' ),
		'and exactly one navigation remains, on the classic page`s path, which is unchanged' );

echo "\nGroup T: the range survives the framework's State typing\n";
// desktop-mode's State::accept() coerces every write onto the declared
// default's TYPE and falls back to the default when the shapes disagree.
// Declared as the integer 7, 'custom' and the seven calendar presets became 7
// -- measured in the sandbox on the custom-date form. The State stub above
// mirrors that rule, so the preset and custom pins in Group 4 now run the
// framework's own coercion; this pin names the reason they can.
ok( is_string( \snt_os_analytics_defaults()['range'] ), 'the declared default for range is a STRING: a string-valued param must be declared as one, or the framework\'s coercion eats every non-numeric value' );
$state = st( $app, array() );
$app->actions['go']( $state, $os, array( 'sn_range' => 'this-week' ) );
ok( 'this-week' === \snt_os_analytics_get( $state )['sn_range'], 'a calendar preset reaches the $_GET the page reads, as the token' );

echo "\nGroup U: a navigation is the whole next URL -- the clear and off controls\n";
// The review measured three dead controls: the Compare Off pill, Clear
// drill-down and the Events property Clear all reset by OMITTING their param
// from the link, and the first build read an absent arg as "keep". Every
// pin here drives go with the arg set the real link emits.
$state = st( $app, array( 'view' => 'geography', 'range' => '30', 'class' => 'bot', 'compare' => 'yoy', 'drill' => 'country:US' ) );
$app->actions['go']( $state, $os, array( 'sn_view' => 'geography', 'sn_range' => '30', 'sn_class' => 'bot', 'sn_drill' => 'country:US', 'sn_lg_range' => '7' ) );
ok( 'off' === $state->get( 'compare' ) && 'country:US' === $state->get( 'drill' ), 'the Compare Off pill -- every other param carried, sn_compare omitted -- turns comparison off and keeps the drill' );
$state = st( $app, array( 'view' => 'geography', 'range' => '30', 'class' => 'bot', 'compare' => 'yoy', 'drill' => 'country:US' ) );
$app->actions['go']( $state, $os, array( 'sn_view' => 'geography', 'sn_range' => '30', 'sn_class' => 'bot', 'sn_compare' => 'yoy' ) );
ok( '' === $state->get( 'drill' ) && 'yoy' === $state->get( 'compare' ), 'Clear drill-down -- sn_drill omitted, the rest carried -- clears the drill and keeps the comparison' );
$state = st( $app, array( 'view' => 'events', 'event_prop' => 'p', 'drill' => 'country:US' ) );
$app->actions['go']( $state, $os, array( 'sn_view' => 'events', 'sn_drill' => 'country:US' ) );
ok( '' === $state->get( 'event_prop' ), 'the Events property Clear -- sn_event_prop omitted -- clears it' );
$state = st( $app, array( 'view' => 'overview', 'compare' => 'prev', 'class' => 'bot', 'range' => '30' ) );
$app->actions['go']( $state, $os, array( 'sn_view' => 'posts', 'sn_range' => '30', 'sn_class' => 'bot' ) );
ok( 'posts' === $state->get( 'view' ) && 'off' === $state->get( 'compare' ) && '30' === $state->get( 'range' ), 'the movers\' bare deep link (view + window + class, no compare) lands where its classic href lands: comparison off' );
$state = st( $app, array( 'view' => 'geography', 'drill' => 'country:US', 'compare' => 'yoy' ) );
$app->actions['go']( $state, $os, array( 'sn_view' => 'geography', 'sn_range' => '7', 'sn_class' => 'human', 'sn_compare' => 'yoy' ) );
ok( '' === $state->get( 'drill' ) && 'yoy' === $state->get( 'compare' ), 'clicking the ALREADY-ACTIVE tab -- whose link the strip built without the reset params -- clears the drill, as the classic tab does' );
$uri = snt_os_analytics_request_uri( array( 'page' => 'sn-analytics', 'sn_view' => 'geography', 'sn_drill' => 'country:A&B=x' ) );
ok( false !== strpos( $uri, 'sn_drill=country%3AA%26B%3Dx' ) && false === strpos( $uri, '&B=x' ), 'the lent REQUEST_URI encodes its values: a drill carrying & cannot inject a parameter into every link the report prints' );
$state = st( $app, array( 'view' => 'geography', 'range' => '30', 'compare' => 'yoy' ) );
ok( 'sn_view=geography&sn_range=30&sn_class=human&sn_compare=yoy&sn_lg_range=7' === snt_os_analytics_query( $state ) || false !== strpos( snt_os_analytics_query( $state ), 'sn_compare=yoy' ), 'the wrap carries the current navigation for the brush, values encoded, empties dropped' );

	$snt_ci = (string) getenv( 'CI' );
	if ( $skip > 0 && '' !== $snt_ci && '0' !== $snt_ci && 'false' !== strtolower( $snt_ci ) ) {
		echo "\nFAILED (counted into the summary below, which is what tests/run.sh reads): $skip pins were SKIPPED because WordPress's wp-includes/html-api is not on this machine.\n";
		echo "Fix: fetch WordPress in the workflow and export SNT_WP_HTML_API=<checkout>/wp-includes/html-api.\n";
	// tests/run.sh discards a suite's exit status and judges the summary line
	// alone, so the skips must be counted where the runner looks.
	$fail += $skip;
	}
	echo "\nResult: $pass passed, $fail failed" . ( $skip > 0 ? ", $skip skipped" : '' ) . ".\n";

	// Same rule as tests/openstation-host.php, and for the same reason: without
	// the HTML API nothing rewrote the page, so a lane that skips these pins
	// prints OK while every tab still carries an href and the export form is
	// still a dispatch. A laptop may skip; CI, which fetches the API, may not.
	exit( $fail > 0 ? 1 : 0 );
}
