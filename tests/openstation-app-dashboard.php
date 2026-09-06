<?php
/**
 * The S&N Dashboard native window (apps/sn-dashboard): the definition and the frame.
 *
 * What v13.104.0 shipped painted the classic page's wp-admin markup inside a
 * framework window with the tab in state; the owner's verdict on live was
 * "nothing was ported, I've even lost the tabs". This suite pins the rebuild:
 * the framework's tabs from the registry, one session per tab, the kit frame
 * around every leaf, and -- the port-complete guard -- a kit painter for EVERY
 * leaf of every tab, so the classic scaffold cannot ship.
 *
 * Run: php tests/openstation-app-dashboard.php
 */

// The framework stubs the app file needs.
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
		public function window_action( $id, array $a ) { return $this; }
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
		public $view = ''; public $params = array(); public $toasts = array(); public $badges = array(); public $opened = array(); public $refresh = 0;
		public function param( $k, $f = null ) { return $this->params[ $k ] ?? $f; }
		public function toast( $m ) { $this->toasts[] = $m; return $this; }
		public function badge( $n ) { $this->badges[] = $n; return $this; }
		public function open_url( $u, $t = '', $i = '' ) { $this->opened[] = $u; return $this; }
		public function refresh_menu() { $this->refresh++; return $this; }
	}
}
namespace {
	require_once __DIR__ . '/lib/os-leaf-harness.php';
	// The host seam the app requires, stubbed to the pieces the definition touches.
	if ( ! function_exists( 'snt_os_host_resolve_sub' ) ) { function snt_os_host_resolve_sub( $tab, $sub ) { return (string) $sub; } }
	if ( ! function_exists( 'snt_os_host_params' ) ) { function snt_os_host_params( array $in ) { $out = array(); foreach ( $in as $k => $v ) { if ( 0 === strpos( (string) $k, 'sn_' ) ) { $out[ $k ] = $v; } } return $out; } }
	if ( ! function_exists( 'snt_os_host_expand' ) ) { function snt_os_host_expand( array $a ) { return $a; } }
	if ( ! function_exists( 'snt_os_host_is_admin_url' ) ) { function snt_os_host_is_admin_url( $u ) { return 0 === strpos( (string) $u, 'https://example.test/wp-admin/' ); } }
	if ( ! function_exists( 'snt_os_host_notice' ) ) { function snt_os_host_notice( $flash ) { return '' === $flash ? null : array( 'success', 'Saved: ' . $flash ); } }
	if ( ! function_exists( 'snt_os_host_toast_text' ) ) { function snt_os_host_toast_text( $n ) { return strip_tags( (string) $n[1] ); } }
	$GLOBALS['__replays'] = array();
	if ( ! function_exists( 'snt_os_host_replay' ) ) {
		function snt_os_host_replay( array $values, $page, array $get = array(), $pipeline = '' ) {
			$GLOBALS['__replays'][] = compact( 'values', 'page', 'get', 'pipeline' );
			return array( 'ok' => true, 'flash' => 'purged', 'target' => array( 'tab' => $get['tab'], 'sub' => $get['sub'], 'anchor' => '' ), 'reason' => '', 'detail' => '', 'pipeline' => 'shared', 'params' => array(), 'post' => array() );
		}
	}
	if ( ! defined( 'SNT_OS_HOST_NONCE' ) ) { define( 'SNT_OS_HOST_NONCE', 'sn_theme_options_nonce' ); }
	// inc/openstation-host.php is not loaded (it drags the capture); the app file requires it, so satisfy the require with the loaded frame.
	$GLOBALS['__snt_host_stub'] = true;
	set_include_path( get_include_path() );
	$app_file = SNT_PATH . 'apps/sn-dashboard/sn-dashboard.os.php';
	$src      = (string) file_get_contents( $app_file );
	$src      = str_replace( "require_once dirname( __DIR__, 2 ) . '/inc/openstation-host.php';", "require_once dirname( __DIR__, 2 ) . '/inc/openstation-host-assets.php';", $src );
	$src      = str_replace( "require_once __DIR__ . '/parts/nav.php';", '', $src );
	$src      = str_replace( "require_once __DIR__ . '/parts/frame.php';", '', $src );
	$src      = str_replace( 'require_once $sn_dashboard_leaf_file;', 'require_once $sn_dashboard_leaf_file; // painters', $src );
	$src      = str_replace( '<?php', '', $src );
	$tmp      = tempnam( sys_get_temp_dir(), 'snt-app-' ) . '.php';
	file_put_contents( $tmp, '<?php ' . str_replace( '__DIR__', "'" . dirname( $app_file ) . "'", $src ) );
	$app = require $tmp;
	unlink( $tmp );

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

	echo "Group 1: the window\n";
	ok( 'sn-dashboard' === $app->id && 'S&N Dashboard' === $app->title && 'dashicons-shield-alt' === $app->icon && 'dock' === $app->placement, 'the same id, title, shield and dock tile as before -- the placement carry keys on the id' );
	ok( array( 'sub', 'anchor', 'flash', 'notice', 'params', 'post' ) === array_keys( $app->state ), 'state has NO tab: the tab is the session (the framework`s), only the leaf, the anchor and the last write are state' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen' ) === array_keys( $app->actions ), 'five actions: go, post, door, refresh, reopen' );
	$tabs = array();
	foreach ( sn_admin_top_tabs() as $t ) { if ( 'dashboard' !== $t['tab'] ) { $tabs[ $t['tab'] ] = $t['label']; } }
	ok( array_keys( $tabs ) === array_keys( $app->tabs ) && array_values( $tabs ) === array_column( $app->tabs, 'label' ), 'the framework`s tabs are the registry`s seven tabs after Dashboard, in order, with the registry`s labels: ' . implode( ', ', array_keys( $app->tabs ) ) );
	$positions = array_column( $app->tabs, 'position' ); $sorted = $positions; sort( $sorted );
	ok( $positions === $sorted && count( array_unique( $positions ) ) === 7 && ! in_array( false, array_map( 'is_callable', array_column( $app->tabs, 'view' ) ), true ), 'each tab has a view callable and an ascending, distinct position' );
	ok( is_callable( $app->view ), 'the main view is the Dashboard tab' );
	$args = snt_os_host_window_args( array( 'styles' => array(), 'scripts' => array() ), 'sn-dashboard' );
	ok( 'Dashboard' === ( $args['main_tab_label'] ?? null ), 'the first tab is labelled Dashboard through the window args, not the window title' );
	ok( in_array( 'snt-os-app', $args['styles'], true ) && in_array( 'snt-sn-dashboard-app', $args['styles'], true ) && in_array( 'snt-os-kit', $args['scripts'], true ) && ! in_array( 'snt-os-host', $args['styles'], true ), 'the window carries the shared app sheet, its own sheet and the companion script -- and NOT the light-canvas sheet' );

	echo "\nGroup 2: the frame\n";
	$os = new \OpenStation\App\Os();
	ok( 'dashboard' === \SignalNoise\OpenStationHost\Dashboard\current_tab( $os ), 'no view names the Dashboard tab' );
	$os->view = 'main';
	ok( 'dashboard' === \SignalNoise\OpenStationHost\Dashboard\current_tab( $os ), 'main IS the Dashboard tab' );
	$os->view = 'monitoring';
	ok( 'monitoring' === \SignalNoise\OpenStationHost\Dashboard\current_tab( $os ), 'a tab slug is itself' );
	ok( array( 'identity-and-seo', 'front-end', 'performance', 'redirects' ) === array_keys( \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'site' ) ), 'a tab`s leaves come from the registry in its order' );
	ok( array() === \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'dashboard' ), 'the Dashboard tab has no leaves' );
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'redirects' ) );
	ok( 'redirects' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'site', $st ), 'the state`s sub is the active leaf when the tab has it' );
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'health' ) );
	ok( 'identity-and-seo' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'site', $st ), 'a sub from another tab falls back to the tab`s first leaf' );
	ok( '' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'dashboard', $st ), 'a landing tab has no sub' );
	ok( array( 'sn_action' => 'full_reset', '_wpnonce' => 'n1' ) === \SignalNoise\OpenStationHost\Dashboard\posted_values( array( 'action' => 'full_reset', 'nonce' => 'n1' ) ), 'a one-click button`s action + nonce become the two fields the classic form carried' );
	ok( array( 'sn_action' => 'x', 'login_slug' => 'y' ) === \SignalNoise\OpenStationHost\Dashboard\posted_values( array( 'values' => array( 'sn_action' => 'x', 'login_slug' => 'y' ) ) ), 'an os-form`s values pass through' );

	echo "\nGroup 3: the actions on a tab session\n";
	$os = new \OpenStation\App\Os(); $os->view = 'site';
	$st = new \OpenStation\App\State( $app->state );
	$app->actions['go']( $st, $os, array( 'sub' => 'redirects', 'anchor' => 'sn-sec-x', 'sn_page' => '2' ) );
	ok( 'redirects' === $st->get( 'sub' ) && 'sn-sec-x' === $st->get( 'anchor' ) && array( 'sn_page' => '2' ) === $st->get( 'params' ) && null === $st->get( 'notice' ), 'go sets the leaf, the anchor and the sn_* params on this tab`s session and drops the notice' );
	$app->actions['post']( $st, $os, array( 'action' => 'purge_caches', 'nonce' => 'n1' ) );
	$replay = end( $GLOBALS['__replays'] );
	ok( array( 'sn_action' => 'purge_caches', '_wpnonce' => 'n1' ) === $replay['values'] && 'site' === $replay['get']['tab'] && 'redirects' === $replay['get']['sub'], 'a one-click write replays through the shared pipeline with THIS tab and leaf as the page query' );
	ok( array( 'success', 'Saved: purged' ) === $st->get( 'notice' ) && 1 === count( $os->toasts ) && 1 === $os->refresh, 'the flash becomes the notice and a toast, and the menu refreshes' );
	$os2 = new \OpenStation\App\Os(); $os2->view = 'security';
	$app->actions['door']( $st, $os2, array( 'url' => 'https://example.test/wp-admin/update-core.php' ) );
	$app->actions['door']( $st, $os2, array( 'url' => 'https://evil.test/x' ) );
	ok( array( 'https://example.test/wp-admin/update-core.php' ) === $os2->opened, 'a door opens an admin URL and refuses anything else' );
	$os3 = new \OpenStation\App\Os(); $os3->view = 'monitoring'; $os3->params = array( 'sub' => 'health', 'anchor' => 'sn-sec-y' );
	$st3 = new \OpenStation\App\State( $app->state );
	$app->actions['reopen']( $st3, $os3, array() );
	ok( 'health' === $st3->get( 'sub' ) && 'sn-sec-y' === $st3->get( 'anchor' ), 'reopen reads the leaf and the anchor (an element id, as given) from the window params on this tab' );

	echo "\nGroup 4: PORT COMPLETE -- every leaf has a kit painter\n";
	foreach ( (array) glob( SNT_PATH . 'apps/sn-dashboard/parts/leaves/*.php' ) as $leaf_file ) { require_once $leaf_file; }
	$painters = \SignalNoise\OpenStationHost\Dashboard\painters();
	ok( isset( $painters['dashboard/'] ), 'the Dashboard tab is painted' );
	$missing = array();
	$count   = 0;
	foreach ( sn_admin_top_tabs() as $t ) {
		foreach ( array_keys( (array) ( $t['sub_tabs'] ?? array() ) ) as $slug ) {
			++$count;
			if ( ! isset( $painters[ $t['tab'] . '/' . $slug ] ) ) { $missing[] = $t['tab'] . '/' . $slug; }
		}
	}
	ok( $count >= 33, "VACUITY: the registry has $count static leaves to paint (plus the two spliced ones)" );
	ok( array() === $missing, 'every registry leaf has a painter -- the classic scaffold cannot ship' . ( $missing ? ' -- MISSING ' . count( $missing ) . ': ' . implode( ', ', $missing ) : '' ) );
	$spliced = array( 'monitoring/search-console', 'monitoring/machine-readers' );
	$spliced_missing = array_diff( $spliced, array_keys( $painters ) );
	ok( array() === $spliced_missing, 'the two spliced leaves (Search Console, Machine Readers) have painters too' . ( $spliced_missing ? ' -- MISSING: ' . implode( ', ', $spliced_missing ) : '' ) );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
