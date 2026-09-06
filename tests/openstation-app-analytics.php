<?php
/**
 * The S&N Analytics native window (apps/sn-analytics): the frame, the picks, the port guard.
 *
 * The thirteen views are the framework's tabs, each a session; a control's
 * pick becomes the next query by the classic link rules; the frame paints the
 * chrome pieces in the classic page's order through the painter registry --
 * and, the port-complete guard, every view and every chrome piece has a kit
 * painter, so the classic scaffold cannot ship.
 *
 * Run: php tests/openstation-app-analytics.php
 */
namespace OpenStation {
	class App {
		public $id; public $title; public $icon; public $placement; public $caps = array(); public $state = array();
		public $actions = array(); public $view; public $mount; public $buttons = array(); public $tabs = array(); public $size = array(); public $min = array();
		public static function define( $id ) { $a = new self(); $a->id = $id; return $a; }
		public function title( $t ) { $this->title = $t; return $this; }
		public function icon( $i ) { $this->icon = $i; return $this; }
		public function size( $w, $h ) { $this->size = array( $w, $h ); return $this; }
		public function min_size( $w, $h ) { $this->min = array( $w, $h ); return $this; }
		public function placement( $p ) { $this->placement = $p; return $this; }
		public function capabilities( ...$c ) { $this->caps = $c; return $this; }
		public function state( array $d ) { $this->state = $d; return $this; }
		public function title_bar_button( $id, array $a ) { $this->buttons[ $id ] = $a; return $this; }
		public function tab( $v, array $a = array() ) { $this->tabs[ $v ] = $a; return $this; }
		public function mount( callable $cb ) { $this->mount = $cb; return $this; }
		public function action( $n, callable $cb ) { $this->actions[ $n ] = $cb; return $this; }
		public function view( callable $cb ) { $this->view = $cb; return $this; }
	}
}
namespace OpenStation\App {
	class State {
		private $v;
		public function __construct( array $defaults, array $in = array() ) { $this->v = array_merge( $defaults, $in ); }
		public function get( $k ) { return $this->v[ $k ] ?? null; }
		public function set( $k, $x ) { if ( array_key_exists( $k, $this->v ) ) { $this->v[ $k ] = $x; } return $this; }
		public function all() { return $this->v; }
	}
	class Os {
		public $view = ''; public $params = array(); public $toasts = array(); public $opened = array();
		public function param( $k, $f = null ) { return $this->params[ $k ] ?? $f; }
		public function toast( $m ) { $this->toasts[] = $m; return $this; }
		public function open_url( $u, $t = '', $i = '' ) { $this->opened[] = $u; return $this; }
	}
}
namespace {
	require_once __DIR__ . '/lib/os-leaf-harness.php';
	define( 'SNT_ANALYTICS_PAGE_SLUG', 'sn-analytics' );
	// The page's own resolvers, as the real ones answer for these fixtures.
	function snt_analytics_views() { return array( 'overview' => 'Overview', 'content' => 'Content', 'campaigns' => 'Campaigns', 'posts' => 'Posts', 'technology' => 'Technology', 'geography' => 'Geography', 'engagement' => 'Engagement', 'visits' => 'Sessions', 'quality' => 'Quality', 'search' => 'Search', 'events' => 'Events', 'edge' => 'Traffic & edge', 'login-defense' => 'Login defense' ); }
	function snt_analytics_resolve_view( $v ) { return isset( snt_analytics_views()[ $v ] ) ? $v : 'overview'; }
	function snt_analytics_resolve_class( $c ) { return in_array( $c, array( 'human', 'suspect', 'bot' ), true ) ? $c : 'human'; }
	function snt_analytics_resolve_compare( $c ) { return in_array( $c, array( 'prev', 'yoy' ), true ) ? $c : 'off'; }
	function snt_analytics_resolve_window( $r, $f, $t ) {
		if ( 'custom' === $r && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $t ) ) { return array( 'custom', $f, $t ); }
		$days = in_array( (string) $r, array( '7', '14', '30', '90', '365' ), true ) ? (int) $r : 7;
		return array( (string) $days, gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) );
	}
	function snt_analytics_window_args( $range, $class, $from, $to ) { $a = array( 'sn_range' => (string) $range, 'sn_class' => (string) $class ); if ( 'custom' === (string) $range ) { $a['sn_from'] = (string) $from; $a['sn_to'] = (string) $to; } return $a; }
	function snt_analytics_view_reset_params() { return array( 'sn_view', 'sn_range', 'sn_class', 'sn_from', 'sn_to', 'sn_drill', 'sn_event_prop', 'sn_lg_range' ); }
	function snt_analytics_view_owns_chrome( $v ) { return 'login-defense' === $v; }
	function sn_analytics_drilldown_parse( $raw ) { $p = explode( ':', (string) $raw, 2 ); return 2 === count( $p ) && '' !== $p[0] && '' !== $p[1] ? array( $p[0], $p[1] ) : null; }
	function sn_analytics_granularity( $days ) { return $days > 60 ? 'week' : 'day'; }
	function sn_analytics_config() { return ! empty( $GLOBALS['__configured'] ); }
	function sn_login_defense_resolve_days() { return 7; }
	function snt_analytics_page_url( $args = array() ) { return 'https://example.test/wp-admin/admin.php?page=sn-analytics' . ( $args ? '&' . http_build_query( $args ) : '' ); }
	function snt_os_host_last( $v ) { return is_array( $v ) ? (string) end( $v ) : (string) $v; }
	function snt_os_host_expand( array $a ) { return $a; }
	function snt_os_host_is_admin_url( $u ) { return 0 === strpos( (string) $u, 'https://example.test/wp-admin/' ); }
	function snt_os_host_capture( $cb, array $get = array(), array $post = array() ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }
	function snt_os_host_keep_forms( $html ) { return $html; }
	function snt_os_host_rewrite( $html ) { return $html; }
	function snt_analytics_render_dashboard() { echo '<div class="wrap-classic">classic body</div>'; }
	function remove_all_test_filters() { $GLOBALS['__filters']['snt_os_analytics_painters'] = array(); }
	$GLOBALS['__configured'] = true;

	require_once SNT_PATH . 'inc/openstation-host-assets.php';
	require SNT_PATH . 'apps/sn-analytics/parts/state.php';
	require SNT_PATH . 'apps/sn-analytics/parts/view.php';
	require SNT_PATH . 'apps/sn-analytics/parts/frame.php';
	$app_file = SNT_PATH . 'apps/sn-analytics/sn-analytics.os.php';
	$src      = (string) file_get_contents( $app_file );
	foreach ( array( "require_once dirname( __DIR__, 2 ) . '/inc/openstation-host.php';", "require_once __DIR__ . '/parts/state.php';", "require_once __DIR__ . '/parts/view.php';", "require_once __DIR__ . '/parts/frame.php';" ) as $line ) { $src = str_replace( $line, '', $src ); }
	$src = str_replace( '<?php', '', $src );
	$tmp = tempnam( sys_get_temp_dir(), 'snt-an-' ) . '.php';
	file_put_contents( $tmp, '<?php ' . str_replace( '__DIR__', "'" . dirname( $app_file ) . "'", $src ) );
	$app = require $tmp;
	unlink( $tmp );
	// Group 3 spies by adding a filter; Group 4 must restore the
	// registrations the painter files made at load (require_once cannot
	// re-run them, and wiping the hook made chrome/empty+error look missing).
	$sn_analytics_painter_filters = $GLOBALS['__filters']['snt_os_analytics_painters'] ?? array();

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function st( $app, array $in = array() ) { return new \OpenStation\App\State( $app->state, $in ); }

	echo "Group 1: the views are the tabs\n";
	$views = snt_analytics_views(); unset( $views['overview'] );
	ok( array_keys( $views ) === array_keys( $app->tabs ) && array_values( $views ) === array_column( $app->tabs, 'label' ), 'twelve tabs after Overview, in the registry`s order, with its labels' );
	ok( 'Overview' === snt_os_host_window_args( array( 'styles' => array(), 'scripts' => array() ), 'sn-analytics' )['main_tab_label'], 'the first tab is labelled Overview through the window args' );
	$os = new \OpenStation\App\Os();
	ok( 'overview' === \SignalNoise\OpenStationHost\Analytics\view_slug( $os, st( $app ) ), 'no view + a fresh state = overview' );
	ok( 'quality' === \SignalNoise\OpenStationHost\Analytics\view_slug( $os, st( $app, array( 'view' => 'quality' ) ) ), 'no view (a stub host) = the session`s own view' );
	$os->view = 'main';
	ok( 'overview' === \SignalNoise\OpenStationHost\Analytics\view_slug( $os, st( $app, array( 'view' => 'quality' ) ) ), 'main IS overview, whatever the state said' );
	$os->view = 'edge';
	ok( 'edge' === \SignalNoise\OpenStationHost\Analytics\view_slug( $os ), 'a tab slug is itself' );

	echo "\nGroup 2: a pick is the classic link\n";
	$s = st( $app, array( 'view' => 'content', 'range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31', 'class' => 'bot', 'compare' => 'yoy', 'drill' => 'referrer:x', 'event_prop' => 'p', 'lg_range' => 7 ) );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'range', '30' );
	ok( '30' === $q['sn_range'] && 'bot' === $q['sn_class'] && ! isset( $q['sn_from'] ) && ! isset( $q['sn_to'] ) && 'yoy' === $q['sn_compare'] && 'referrer:x' === $q['sn_drill'] && 'content' === $q['sn_view'], 'a range pick rebuilds the window args (the dates drop with the custom range) and carries everything else, as the classic range link does' );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'class', 'human' );
	ok( 'custom' === $q['sn_range'] && '2026-01-01' === $q['sn_from'] && '2026-01-31' === $q['sn_to'] && 'human' === $q['sn_class'], 'a class pick keeps the custom window and its dates' );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'compare', 'off' );
	ok( ! isset( $q['sn_compare'] ) && 'custom' === $q['sn_range'], 'Compare Off DROPS sn_compare (the classic link omits it) and keeps the window' );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'compare', 'prev' );
	ok( 'prev' === $q['sn_compare'], 'a compare pick sets it' );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'lg_range', '30' );
	ok( '30' === $q['sn_lg_range'] && 'yoy' === $q['sn_compare'], 'any other key sets sn_<key> and carries the rest' );
	$q = \SignalNoise\OpenStationHost\Analytics\picked( $s, 'event_prop; DROP', 'x' );
	ok( isset( $q['sn_event_prop'] ) && 'x' === $q['sn_event_prop'], 'a key is reduced to letters and underscores before it names a parameter' );
	$os = new \OpenStation\App\Os(); $os->view = 'content';
	$app->actions['go']( $s, $os, array( 'key' => 'range', 'value' => '30' ) );
	ok( '30' === $s->get( 'range' ) && '' === $s->get( 'from' ) && 'bot' === $s->get( 'class' ) && 'yoy' === $s->get( 'compare' ) && 'content' === $s->get( 'view' ), 'go with a pick applies the classic next query wholesale and pins the view to the tab' );
	$app->actions['go']( $s, $os, array( 'sn_range' => '7' ) );
	ok( '7' === $s->get( 'range' ) && 'human' === $s->get( 'class' ) && 'off' === $s->get( 'compare' ), 'go with a bare query is still wholesale: absent means the default' );

	echo "\nGroup 3: the frame paints the classic order\n";
	$painted = array();
	$spy = function ( $key ) use ( &$painted ) { return function ( $ctx ) use ( $key, &$painted ) { $painted[] = $key; return '<i data-piece="' . $key . '"></i>'; }; };
	add_filter( 'snt_os_analytics_painters', function ( $p ) use ( $spy, &$painted ) {
		foreach ( array( 'chrome/controls', 'chrome/insights', 'chrome/drilldown', 'chrome/empty', 'chrome/error', 'chrome/login-header', 'view/overview', 'view/edge', 'view/login-defense' ) as $k ) { $p[ $k ] = $spy( $k ); }
		$p['chrome/header'] = function ( $ctx ) use ( &$painted ) { $painted[] = 'chrome/header'; return array( 'html' => '<i data-piece="chrome/header"></i>', 'totals' => array( 'views' => $GLOBALS['__views'] ?? 5 ) ); };
		unset( $p['view/content'] );
		return $p;
	} );
	$paint = function ( $view, array $in = array() ) use ( $app, &$painted ) { $painted = array(); $os = new \OpenStation\App\Os(); $os->view = 'overview' === $view ? 'main' : $view; $cb = 'overview' === $view ? $app->view : $app->tabs[ $view ]['view']; ob_start(); call_user_func( $cb, st( $app, $in ), $os ); return (string) ob_get_clean(); };
	$html = $paint( 'overview', array( 'notice' => array( 'error', 'Broke.' ) ) );
	ok( array( 'chrome/error', 'chrome/insights', 'chrome/controls', 'chrome/header', 'view/overview' ) === $painted, 'overview: diagnostic, insights, controls, header, view -- the composer`s order; no drill-down without a drill: ' . implode( ',', $painted ) );
	ok( 0 === strpos( $html, '<div class="snt-app" data-snt-view="overview" data-snt-query="' ) && false !== strpos( $html, '<os-notice tone="danger">Broke.</os-notice>' ) && strpos( $html, '<os-notice' ) < strpos( $html, 'data-piece="chrome/error"' ), 'the root names the view and the query; the notice paints first, as the kit`s notice' );
	$paint( 'overview', array( 'drill' => 'browser:Firefox' ) );
	ok( in_array( 'chrome/drilldown', $painted, true ) && array_search( 'chrome/drilldown', $painted, true ) < array_search( 'view/overview', $painted, true ), 'a parsed drill paints the drill-down panel before the view' );
	$paint( 'edge' );
	ok( ! in_array( 'chrome/insights', $painted, true ) && in_array( 'chrome/controls', $painted, true ) && in_array( 'view/edge', $painted, true ), 'edge skips the insights band and keeps the controls and header, as the composer does' );
	$paint( 'login-defense' );
	ok( array( 'chrome/error', 'chrome/login-header', 'view/login-defense' ) === $painted, 'login-defense owns its chrome: its own header, no insights, no controls, no header region' );
	$GLOBALS['__views'] = 0;
	$html = $paint( 'overview' );
	ok( false !== strpos( $html, 'No analytics data in this range yet' ), 'zero views in the window paint the classic empty note under the view' );
	$GLOBALS['__views'] = 5;
	$GLOBALS['__configured'] = false;
	$html = $paint( 'overview' );
	ok( array( 'chrome/empty' ) === $painted && false === strpos( $html, 'data-piece="view/overview"' ), 'unconfigured analytics paint the gate and nothing else' );
	$GLOBALS['__configured'] = true;
	$html = $paint( 'content' );
	ok( array() === $painted && false !== strpos( $html, 'class="snt-classic"' ) && false !== strpos( $html, 'classic body' ), 'a view without a painter paints the classic capture as scaffold' );

	echo "\nGroup 4: PORT COMPLETE -- every view and chrome piece has a painter\n";
	$GLOBALS['__filters']['snt_os_analytics_painters'] = $sn_analytics_painter_filters;
	$painters = \SignalNoise\OpenStationHost\Analytics\painters();
	$want = array( 'chrome/controls', 'chrome/header', 'chrome/insights', 'chrome/drilldown', 'chrome/empty', 'chrome/error', 'chrome/login-header' );
	foreach ( array_keys( snt_analytics_views() ) as $slug ) { $want[] = 'view/' . $slug; }
	$missing = array_values( array_diff( $want, array_keys( $painters ) ) );
	ok( array() === $missing, 'every view and chrome piece has a painter -- the classic scaffold cannot ship' . ( $missing ? ' -- MISSING ' . count( $missing ) . ': ' . implode( ', ', $missing ) : '' ) );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
