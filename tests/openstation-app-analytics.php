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
	function sn_analytics_signals( $from, $to, $class = 'human', $opts = array() ) { return array(); }
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
	ok( array( 1280, 860 ) === $app->size && array( 360, 360 ) === $app->min, 'Analytics retains its opening size but permits 360x360 desktop resizing' );
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
	$s->set( 'range', '30' )->set( 'class', 'suspect' )->set( 'compare', 'prev' )->set( 'from', '2026-01-01' )->set( 'to', '2026-01-31' );
	$app->actions['filter']( $s, $os, array() );
	ok( '30' === $s->get( 'range' ) && '' === $s->get( 'from' ) && '' === $s->get( 'to' ) && 'suspect' === $s->get( 'class' ) && 'prev' === $s->get( 'compare' ), 'bound native filters are re-resolved server-side and a rolling range sheds stale custom dates' );

	echo "\nGroup 3: the frame paints the classic order\n";
	$painted = array();
	$spy = function ( $key ) use ( &$painted ) { return function ( $ctx ) use ( $key, &$painted ) { $painted[] = $key; return '<i data-piece="' . $key . '"></i>'; }; };
	add_filter( 'snt_os_analytics_painters', function ( $p ) use ( $spy, &$painted ) {
		foreach ( array( 'chrome/controls', 'chrome/insights', 'chrome/drilldown', 'chrome/empty', 'chrome/error', 'chrome/login-header', 'view/overview', 'view/posts', 'view/search', 'view/edge', 'view/login-defense' ) as $k ) { $p[ $k ] = $spy( $k ); }
		$p['chrome/header'] = function ( $ctx ) use ( &$painted ) { $painted[] = 'chrome/header'; return array( 'html' => '<i data-piece="chrome/header"></i>', 'totals' => array( 'views' => $GLOBALS['__views'] ?? 5 ) ); };
		unset( $p['view/content'] );
		return $p;
	} );
	$paint = function ( $view, array $in = array() ) use ( $app, &$painted ) { $painted = array(); $os = new \OpenStation\App\Os(); $os->view = 'overview' === $view ? 'main' : $view; $cb = 'overview' === $view ? $app->view : $app->tabs[ $view ]['view']; ob_start(); call_user_func( $cb, st( $app, $in ), $os ); return (string) ob_get_clean(); };
	foreach ( array( 'posts', 'search' ) as $refresh_view ) {
		ok( 1 === substr_count( $paint( $refresh_view ), 'os-action="refresh"' ), $refresh_view . ': refresh is available without adding unrelated range or class controls' );
	}
	$html = $paint( 'overview', array( 'notice' => array( 'error', 'Broke.' ) ) );
	ok( 1 === substr_count( $html, 'class="snt-report-scroll"' )
		&& strpos( $html, 'class="snt-report-scroll"' ) < strpos( $html, 'data-piece="chrome/controls"' )
		&& substr_count( $html, '<div' ) === substr_count( $html, '</div>' ),
		'configured reports wrap controls and body in one balanced responsive scroll region' );
	ok( array( 'chrome/error', 'chrome/controls', 'chrome/insights', 'chrome/header', 'view/overview' ) === $painted, 'overview: diagnostic, fixed toolbar, then the scrolling report body -- no drill-down without a drill: ' . implode( ',', $painted ) );
	ok( 0 === strpos( $html, '<div class="snt-app os-app-list" data-os-app="sn-analytics" data-snt-view="overview" data-snt-query="' ) && false !== strpos( $html, '<os-notice tone="danger">Broke.</os-notice>' ) && strpos( $html, '<os-notice' ) < strpos( $html, 'data-piece="chrome/error"' ), 'the root adopts the framework list scaffold, names the view and query, and paints the notice first' );
	$paint( 'overview', array( 'drill' => 'browser:Firefox' ) );
	ok( in_array( 'chrome/drilldown', $painted, true ) && array_search( 'chrome/drilldown', $painted, true ) < array_search( 'view/overview', $painted, true ), 'a parsed drill paints the drill-down panel before the view' );
	$paint( 'posts' );
	ok( array( 'chrome/error', 'view/posts' ) === $painted, 'Posts paints its lifetime catalog without ineffective range controls or Overview chrome' );
	$paint( 'edge' );
	ok( array( 'chrome/error', 'chrome/controls', 'view/edge' ) === $painted, 'other focused reports follow the same compact composition' );
	$paint( 'search' );
	ok( array( 'chrome/error', 'view/search' ) === $painted, 'Search omits global controls that its scheduled Google window cannot honor' );
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

	echo "\nGroup 5: the native toolbar stays compact until Custom is chosen\n";
	$rolling_controls = call_user_func( $painters['chrome/controls'], array( 'range' => '7', 'class' => 'human', 'compare' => 'off', 'get' => array() ) );
	$custom_controls  = call_user_func( $painters['chrome/controls'], array( 'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-06', 'class' => 'human', 'compare' => 'off', 'get' => array() ) );
	ok( false !== strpos( $rolling_controls, '<os-select class="snt-filter snt-filter--range"' ) && false !== strpos( $rolling_controls, '<os-option value="custom">Custom range…</os-option>' ) && false === strpos( $rolling_controls, 'snt-custom-range' ), 'one native Range select replaces the button wall and does not reserve space for custom dates' );
	ok( false !== strpos( $custom_controls, 'snt-custom-range' ) && false !== strpos( $custom_controls, 'name="sn_from"' ) && false !== strpos( $custom_controls, 'name="sn_to"' ), 'choosing Custom reveals both date fields in the range row' );
	ok( false !== strpos( $rolling_controls, 'os-app-list__toolbar' ) && false !== strpos( $rolling_controls, 'os-bind="range" os-action="filter"' ) && false !== strpos( $rolling_controls, 'os-bind="class" os-action="filter"' ) && false !== strpos( $rolling_controls, 'os-bind="compare" os-action="filter"' ), 'the framework toolbar owns three bound, server-validated controls' );

	foreach ( array( 'overview', 'events', 'edge', 'login-defense' ) as $refresh_view ) {
		$refresh_html = call_user_func( $painters[ 'login-defense' === $refresh_view ? 'chrome/login-header' : 'chrome/controls' ], array( 'view' => $refresh_view ) );
		ok( 1 === substr_count( $refresh_html, 'os-action="refresh"' ) && false !== strpos( $refresh_html, '>Refresh</os-button>' ), $refresh_view . ': exactly one visible, native in-body Refresh survives hidden mobile titlebars' );
	}
	$refresh_state = st( $app, array( 'view' => 'content', 'range' => '30', 'class' => 'bot', 'compare' => 'prev', 'drill' => 'browser:Firefox', 'notice' => array( 'error', 'Old notice' ) ) );
	$before_refresh = $refresh_state->all();
	$app->actions['refresh']( $refresh_state, new \OpenStation\App\Os(), array() );
	$before_refresh['notice'] = null;
	ok( $before_refresh === $refresh_state->all(), 'Refresh clears the notice without resetting the current report or filters' );
	echo "\nGroup 6: the report surface follows the framework list geometry\n";
	$css = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/sn-analytics.css' );
	ok( false !== strpos( $css, '.snt-app.os-app-list' ) && false !== strpos( $css, '.snt-report-body' ), 'the app adopts the framework list root and a bounded scrolling body' );
	ok( false !== strpos( $css, '.snt-view os-section' ) && false !== strpos( $css, 'margin-block-end: 0' ), 'the report cancels os-section`s Settings-page margin instead of double-spacing every panel' );
	ok( false !== strpos( $css, 'container-type: inline-size' ), 'sn-analytics.css establishes container-type: inline-size on snt-view' );
	ok( false !== strpos( $css, '.snt-report-columns' ) && false !== strpos( $css, '@container ( max-width: 640px )' ), 'independent report columns fold at the 640px window container, not the browser viewport' );
	ok( false !== strpos( $css, 'data-os-mode="mobile"' ) && false !== strpos( $css, 'font-size: 16px' ) && false !== strpos( $css, 'safe-area-inset-bottom' ), 'Analytics carries the mobile PWA form and safe-area foundation' );
	$quality_src = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/parts/painters/view-quality.php' );
	ok( false !== strpos( $quality_src, 'snt-report-columns' ), 'Quality view arranges quality and bot confidence tables in snt-report-columns' );
	$posts_src = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/parts/painters/view-posts.php' );
	ok( false !== strpos( $posts_src, 'snt-report-columns' ), 'Posts view arranges catalog and decay tables in snt-report-columns' );
	$camp_src = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/parts/painters/view-campaigns.php' );
	ok( false !== strpos( $camp_src, 'snt-report-columns' ), 'Campaigns view arranges campaigns and sources in snt-report-columns' );
	$login_src = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/parts/painters/view-login-defense.php' );
	ok( false !== strpos( $login_src, 'snt-report-columns' ), 'Login defense view arranges attacker tables in snt-report-columns' );
	$geo_src = (string) file_get_contents( SNT_PATH . 'apps/sn-analytics/parts/painters/view-geography.php' );
	ok( false !== strpos( $geo_src, '<div class="snt-grid">' ) && false !== strpos( $geo_src, "dim_table( __( 'Countries'" ) && strpos( $geo_src, '<div class="snt-grid">' ) < strpos( $geo_src, "dim_table( __( 'Countries'" ), 'Geography view nests Countries inside snt-grid alongside regional tables' );
	ok( false !== strpos( $css, '.snt-grid' ) && false !== strpos( $css, '@container ( max-width: 640px )' ), 'sn-analytics.css collapses snt-grid under 640px container query' );

	echo "\nGroup 7: visual polish -- card containment, executive insights, and view doors\n";
	ok( false !== strpos( $css, 'os-section::part( body )' ) && false !== strpos( $css, '--os-ui-surface-elevated' ), 'reports enclose section bodies in elevated card surfaces' );
	ok( false !== strpos( $css, '.snt-insights-card' ) && false !== strpos( $css, '.snt-insights-lead' ), 'Insights band styles as an executive briefing card' );
	ok( false !== strpos( $css, '.snt-doors' ) && false !== strpos( $css, 'snt-door-btn' ), 'View doors render as a styled navigation bar with button pills' );
	ok( false !== strpos( $css, '.snt-view > os-empty-state' ) && false !== strpos( $css, 'os-section os-empty-state' ), 'Both standalone views and inner sections define bounded empty state cards' );

	$overview_html = call_user_func( $painters['view/overview'], array( 'from' => '2026-09-01', 'to' => '2026-09-07', 'class' => 'human' ) );
	ok( false !== strpos( $overview_html, 'class="snt-doors snt-toolbar__group"' ) && false !== strpos( $overview_html, 'variant="secondary"' ) && false !== strpos( $overview_html, 'snt-door-btn' ), 'Overview view paints view doors as secondary button pills in an snt-doors group' );

	$insights_html = call_user_func( $painters['chrome/insights'], array( 'from' => '2026-09-01', 'to' => '2026-09-07', 'class' => 'human' ) );
	ok( false !== strpos( $insights_html, 'class="snt-insights-card"' ) && false !== strpos( $insights_html, 'class="snt-prose snt-insights-lead"' ), 'Insights painter renders structured snt-insights-card container' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );

}
