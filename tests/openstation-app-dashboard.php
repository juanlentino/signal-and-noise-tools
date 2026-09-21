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
	// The capability gate, switchable: the harness's own stub answers true
	// forever, and a pin that cannot set it false cannot watch a guard refuse.
	$GLOBALS['__can'] = true;
	if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return (bool) $GLOBALS['__can']; } }
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
	ok( 'sn-dashboard' === $app->id && 'S&N Home' === $app->title && 'dashicons-shield-alt' === $app->icon && 'dock' === $app->placement, 'the stable id keeps placement while the native surface is named S&N Home' );
	ok( array( 'sub', 'anchor', 'flash', 'notice', 'params', 'post', 'filter' ) === array_keys( $app->state ) && '' === $app->state['filter'], 'state has NO tab: the tab is the session (the framework`s), only the leaf, the anchor, the last write and a leaf`s text filter (#1604, written by os-bind through the built-in set) are state' );
	ok( array( 'go', 'post', 'door', 'refresh', 'reopen', 'poll', 'cron_run', 'cron_unschedule' ) === array_keys( $app->actions ), 'eight actions: go, post, door, refresh, reopen, poll, cron_run, cron_unschedule (#1604)' );
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
	ok( array( 'identity-and-seo', 'front-end', 'performance', 'redirects', 'broken-links' ) === array_keys( \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'site' ) ), 'a tab`s leaves come from the registry in its order' );
	ok( array() === \SignalNoise\OpenStationHost\Dashboard\leaves_for( 'dashboard' ), 'the Dashboard tab has no leaves' );
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'redirects' ) );
	ok( 'redirects' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'site', $st ), 'the state`s sub is the active leaf when the tab has it' );
	$st = new \OpenStation\App\State( $app->state, array( 'sub' => 'health' ) );
	ok( 'identity-and-seo' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'site', $st ), 'a sub from another tab falls back to the tab`s first leaf' );
	ok( '' === \SignalNoise\OpenStationHost\Dashboard\active_sub( 'dashboard', $st ), 'a landing tab has no sub' );
	$view = \SignalNoise\OpenStationHost\Dashboard\tab_view( 'dashboard' );
	ob_start();
	call_user_func( $view, $st, $os );
	$html = (string) ob_get_clean();
	ok( 0 === strpos( $html, '<div class="snt-app" data-os-app="sn-dashboard" data-snt-tab="dashboard" data-snt-layout="dashboard"' )
		&& false !== strpos( $html, '<div class="snt-dashboard-body"><div class="snt-leaf" data-snt-leaf="">' ),
		'the frame follows Station Home: a full-height app shell around one bounded body and leaf' );
	$css = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.css' );
	ok( false !== strpos( $css, 'height: 100%' ) && false !== strpos( $css, '.snt-dashboard-body' )
		&& false !== strpos( $css, 'overflow: auto' ) && false !== strpos( $css, 'scrollbar-gutter: stable' ),
		'the body, not the whole document, owns the scroll' );
	ok( false !== strpos( $css, '.snt-leaf os-section' ) && false !== strpos( $css, 'margin-block-end: 0' )
		&& false !== strpos( $css, '@container snt-dashboard' ),
		'the Dashboard cancels the settings-section margin collision and reflows from its window container' );
	ok( false !== strpos( $css, '.snt-home-heading' ) && false !== strpos( $css, 'data-os-mode="mobile"' )
		&& false !== strpos( $css, 'font-size: 16px' ) && false !== strpos( $css, 'safe-area-inset-bottom' ),
		'S&N Home has an orientation hook and its mobile PWA form and safe-area foundation' );
	ok( array( 'sn_action' => 'full_reset', '_wpnonce' => 'n1' ) === \SignalNoise\OpenStationHost\Dashboard\posted_values( array( 'action' => 'full_reset', 'nonce' => 'n1' ) ), 'a one-click button`s action + nonce become the two fields the classic form carried' );
	ok( array( 'sn_action' => 'x', 'login_slug' => 'y' ) === \SignalNoise\OpenStationHost\Dashboard\posted_values( array( 'values' => array( 'sn_action' => 'x', 'login_slug' => 'y' ) ) ), 'an os-form`s values pass through' );
	ok( array( 'action' => 'sn_prov_runsweep', '_wpnonce' => 'n' ) === \SignalNoise\OpenStationHost\Dashboard\posted_values( array( 'action' => 'sn_prov_runsweep', 'nonce' => 'n', 'pipeline' => 'admin-post' ) ), '#1614: a button that declares the admin-post pipeline names its field `action`, the name the host`s admin-post routing reads' );

	echo "\nGroup 3: the actions on a tab session\n";
	$os = new \OpenStation\App\Os(); $os->view = 'site';
	$st = new \OpenStation\App\State( $app->state, array( 'filter' => 'sweep' ) );
	$app->actions['go']( $st, $os, array( 'sub' => 'redirects', 'anchor' => 'sn-sec-x', 'sn_page' => '2' ) );
	ok( 'redirects' === $st->get( 'sub' ) && 'sn-sec-x' === $st->get( 'anchor' ) && array( 'sn_page' => '2' ) === $st->get( 'params' ) && null === $st->get( 'notice' ), 'go sets the leaf, the anchor and the sn_* params on this tab`s session and drops the notice' );
	ok( '' === $st->get( 'filter' ), 'go clears the leaf filter: a filter typed on one leaf never hides rows on the next (#1604)' );
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

	echo "\nGroup 3b: the poll tick is a no-op on state (#1607)\n";
	// os-poll (App Framework, Experimental at OpenStation 1.1.10) dispatches its
	// action every N ms while the element is painted. A tick on `refresh` would
	// drop the notice and the flash every 30 s, and the Webhooks leaf keys its
	// show-once secret off the `wh_added_` flash; so the tick is `poll`, which
	// only re-reads the badge. Measured here: set every state key a user action
	// writes, dispatch poll, read them back unchanged; then refresh still clears.
	$os4 = new \OpenStation\App\Os(); $os4->view = 'connections';
	$st4 = new \OpenStation\App\State( $app->state, array( 'sub' => 'webhooks', 'anchor' => 'sn-sec-w', 'flash' => 'wh_added_x', 'notice' => array( 'success', 'Saved' ), 'params' => array( 'sn_prov_swept' => 'ok', 'sn_watch' => '1' ), 'post' => array( 'a' => '1' ) ) );
	$before = $st4->all();
	// Guarded so a missing action reads as a red pin, not a dead suite; the
	// guard rides every assertion below so nothing passes vacuously.
	$polled = isset( $app->actions['poll'] );
	if ( $polled ) { $app->actions['poll']( $st4, $os4, array() ); }
	ok( $polled && $before === $st4->all(), 'poll leaves sub, anchor, flash, notice, params and post exactly as the last user action left them: the wh_added_ flash (the show-once secret), the notice, the sn_* params all survive the tick' );
	ok( $polled && array( 0 ) === $os4->badges && array() === $os4->toasts && 0 === $os4->refresh && array() === $os4->opened, 'a tick re-reads the badge and does nothing else: no toast, no menu refresh, no door' );
	$app->actions['refresh']( $st4, $os4, array() );
	ok( null === $st4->get( 'notice' ) && '' === $st4->get( 'flash' ) && array( 'sn_prov_swept' => 'ok', 'sn_watch' => '1' ) === $st4->get( 'params' ), '...while the declared refresh still drops the notice and the flash (and keeps params), which is why the poll cannot ride it' );
	$src_app = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/sn-dashboard.os.php' );
	ok( false !== strpos( $src_app, "'poll'," ) && false !== strpos( $src_app, 'os-poll' ) && false !== strpos( $src_app, 'Experimental' ), 'the poll action names the os-poll seam and its Experimental status in the app file' );
	$src_kit = (string) file_get_contents( SNT_PATH . 'inc/openstation-kit-triggers.php' );
	ok( '<span os-action="poll" os-poll="30000" hidden></span>' === snt_kit_poll(), 'snt_kit_poll() paints the hidden poll trigger on the poll action at 30 s' );
	ok( '<span os-action="poll" os-poll="250" hidden></span>' === snt_kit_poll( 'poll', 10 ), '...and floors the interval at the runtime\'s 250 ms, below which readPolls() drops the element' );
	ok( false !== strpos( $src_kit, 'Experimental' ) && false !== strpos( $src_kit, 'readPolls()' ), 'the helper names the seam\'s status and the runtime function that reads it' );

	echo "\nGroup 3c: the Cron leaf's controls run the registered abilities (#1604)\n";
	// The real inc/abilities-cron.php registers through wp_register_ability();
	// the harness's add_action() records the init callback, fired here. The
	// abilities' execute callbacks are the real ones, so the SN-owned refusal
	// the toast carries is the ability's own sentence, which proves the handler
	// routes through wp_get_ability() and never do_action()s the hook itself.
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error { public $code; public $message; public $data;
			public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
	$GLOBALS['__ab'] = array();
	if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; return true; } }
	if ( ! function_exists( 'snt_cron_is_sn_owned' ) ) { function snt_cron_is_sn_owned( $h ) { return 0 === strpos( (string) $h, 'sn_' ); } }
	$GLOBALS['__ran'] = array(); $GLOBALS['__cleared'] = array();
	if ( ! function_exists( 'snt_cron_run_event_impl' ) ) { function snt_cron_run_event_impl( $hook, $args ) { $GLOBALS['__ran'][] = array( $hook, $args ); return array( 'success' => true, 'elapsed_ms' => 1.0 ); } }
	if ( ! function_exists( 'snt_cron_unschedule_event_impl' ) ) { function snt_cron_unschedule_event_impl( $hook, $args ) { $GLOBALS['__cleared'][] = array( $hook, $args ); return array( 'success' => true, 'hook' => $hook, 'args' => $args, 'cleared' => 2 ); } }
	require_once SNT_PATH . 'inc/abilities-permission-helpers.php';
	require_once SNT_PATH . 'inc/abilities-cron.php';
	foreach ( (array) ( $GLOBALS['__actions']['wp_abilities_api_init'] ?? array() ) as $cb ) { $cb(); }
	$GLOBALS['__perm_asked'] = 0;
	class SNT_Test_Ability {
		private $a;
		public function __construct( array $a ) { $this->a = $a; }
		public function check_permissions( $in ) { $GLOBALS['__perm_asked']++; return call_user_func( $this->a['permission_callback'], $in ); }
		public function execute( $in ) { return call_user_func( $this->a['execute_callback'], $in ); }
	}
	$GLOBALS['__got'] = array();
	if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $slug ) { $GLOBALS['__got'][] = $slug; return isset( $GLOBALS['__ab'][ $slug ] ) ? new SNT_Test_Ability( $GLOBALS['__ab'][ $slug ] ) : null; } }
	ok( isset( $GLOBALS['__ab']['signal-noise/run-cron-event'], $GLOBALS['__ab']['signal-noise/unschedule-cron-event'] ), 'VACUITY: both cron abilities registered through the real file' );
	// Guarded as Group 3b is: a missing action reads as red pins, not a dead suite.
	$has_cron = isset( $app->actions['cron_run'], $app->actions['cron_unschedule'] );
	$os5 = new \OpenStation\App\Os(); $os5->view = 'connections';
	$st5 = new \OpenStation\App\State( $app->state, array( 'sub' => 'cron', 'params' => array( 'sn_watch' => '1' ), 'flash' => 'keep', 'notice' => array( 'success', 'Saved' ) ) );
	$before5 = $st5->all();
	if ( $has_cron ) { $app->actions['cron_run']( $st5, $os5, array( 'hook' => 'sn_cache_sweep', 'args' => '[]' ) ); }
	ok( $has_cron && array( 'signal-noise/run-cron-event' ) === $GLOBALS['__got'] && array() === $GLOBALS['__ran'], 'cron_run resolves the registered run-cron-event ability, and an SN-owned hook never reaches the impl' );
	ok( $has_cron && 1 === count( $os5->toasts ) && false !== strpos( (string) end( $os5->toasts ), 'SN-owned hooks are not dispatchable via this ability' ), 'the toast is the ability`s own SN-owned refusal, in its words: ' . json_encode( $os5->toasts ) );
	if ( $has_cron ) { $app->actions['cron_run']( $st5, $os5, array( 'hook' => 'other_plugin_cron', 'args' => '{"foo":"bar"}' ) ); }
	ok( $has_cron && array( array( 'other_plugin_cron', array( 'foo' => 'bar' ) ) ) === $GLOBALS['__ran'] && 'Dispatched other_plugin_cron.' === end( $os5->toasts ), 'a foreign hook runs through the impl with its os-arg-args JSON decoded back to the scheduled signature, and the toast says so' );
	if ( $has_cron ) { $app->actions['cron_unschedule']( $st5, $os5, array( 'hook' => 'some_orphan_hook', 'args' => '{"foo":"bar"}' ) ); }
	ok( $has_cron && array( array( 'some_orphan_hook', array( 'foo' => 'bar' ) ) ) === $GLOBALS['__cleared'] && 'Unscheduled 2 events on some_orphan_hook.' === end( $os5->toasts ), 'cron_unschedule clears through the impl with the row`s args and toasts the count' );
	ok( $has_cron && array( 'signal-noise/run-cron-event', 'signal-noise/run-cron-event', 'signal-noise/unschedule-cron-event' ) === $GLOBALS['__got'], 'each press resolves its own registered ability, never do_action() on the hook' );
	ok( $has_cron && $before5 === $st5->all(), 'neither control touches state: sub, params (sn_watch), flash and notice survive, so a poll tick between presses changes nothing' );
	if ( $has_cron ) { $app->actions['cron_run']( $st5, $os5, array( 'hook' => '' ) ); }
	ok( $has_cron && 'Missing or empty hook name.' === end( $os5->toasts ) && 1 === count( $GLOBALS['__ran'] ), 'an empty hook is refused by the ability`s own validation, in its words, and nothing runs' );
	// The two gates, each watched failing. check_permissions() is asked once
	// per resolved ability (four dispatches above, four asks); a dispatch by an
	// account without manage_options is refused by may_manage() before any
	// ability is resolved, so the resolved list and the impl log stay as they
	// were. Deleting either guard in the app file turns one of these red.
	ok( $has_cron && 4 === $GLOBALS['__perm_asked'] && 4 === count( $GLOBALS['__got'] ), 'every resolved ability was asked check_permissions() once, not trusted from the menu (' . $GLOBALS['__perm_asked'] . ' asks for ' . count( $GLOBALS['__got'] ) . ' resolutions)' );
	$GLOBALS['__can'] = false;
	$got_before = $GLOBALS['__got']; $ran_before = $GLOBALS['__ran']; $cleared_before = $GLOBALS['__cleared'];
	if ( $has_cron ) { $app->actions['cron_run']( $st5, $os5, array( 'hook' => 'other_plugin_cron', 'args' => '[]' ) ); }
	if ( $has_cron ) { $app->actions['cron_unschedule']( $st5, $os5, array( 'hook' => 'some_orphan_hook', 'args' => '[]' ) ); }
	$GLOBALS['__can'] = true;
	ok( $has_cron && 'Nothing was saved: this account cannot manage options.' === end( $os5->toasts ) && 'Nothing was saved: this account cannot manage options.' === $os5->toasts[ count( $os5->toasts ) - 2 ], 'an account without manage_options is refused on both controls with the capability sentence' );
	ok( $has_cron && $got_before === $GLOBALS['__got'] && $ran_before === $GLOBALS['__ran'] && $cleared_before === $GLOBALS['__cleared'] && 4 === $GLOBALS['__perm_asked'], '...before any ability is resolved: may_manage() closes first, so nothing was looked up, asked or run' );

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

	echo "\nGroup 5: S&N Home UI & unescaped HTML\n";
	require_once SNT_PATH . 'inc/openstation-kit-triggers.php';
	$pulse = \SignalNoise\OpenStationHost\Dashboard\Leaves\pulse_item_html(
		'Active Cache Engine',
		'dashicons-performance',
		'Cloudflare Edge',
		'Operational',
		'admin.php?page=sn-theme-options&tab=site&sub=performance',
		'dashboard'
	);
	ok( false === strpos( $pulse, '&lt;span' ), 'pulse item does not escape HTML tags into entities' );
	ok( false !== strpos( $pulse, '<div class="snt-home__metric-label">' ), 'pulse item contains proper metric label markup' );
	ok( false !== strpos( $pulse, 'class="snt-home__metric snt-go"' ), 'cross-tab pulse item has snt-go class' );
	ok( false !== strpos( $pulse, 'data-snt-tab="site"' ), 'cross-tab pulse item targets tab site' );
	ok( false !== strpos( $pulse, 'data-snt-sub="performance"' ), 'cross-tab pulse item targets sub performance' );

	$in_tab_pulse = \SignalNoise\OpenStationHost\Dashboard\Leaves\pulse_item_html(
		'Tools',
		'dashicons-admin-tools',
		'Reports',
		'',
		'admin.php?page=sn-theme-options&tab=dashboard&sub=tools',
		'dashboard'
	);
	ok( false !== strpos( $in_tab_pulse, 'os-action="go"' ), 'same-tab pulse item has os-action="go"' );
	ok( false === strpos( $in_tab_pulse, '&lt;span' ), 'same-tab pulse item preserves unescaped span' );


	$btn = snt_kit_button( '<b>Click</b>', 'run', array( 'raw' => true ) );
	ok( false !== strpos( $btn, '<b>Click</b>' ), 'snt_kit_button with raw => true does not escape HTML' );
	$btn_esc = snt_kit_button( '<b>Click</b>', 'run' );
	ok( false !== strpos( $btn_esc, '&lt;b&gt;Click&lt;/b&gt;' ), 'snt_kit_button without raw escapes HTML by default' );

	echo "\nGroup 6: Two-column settings leaves\n";
	ok( false !== strpos( $css, '.snt-2up' ) && false !== strpos( $css, 'repeat( 2, minmax( 0, 1fr ) )' ), 'sn-dashboard.css defines .snt-2up two-column grid' );
	ok( false !== strpos( $css, '.snt-leaf:has( .snt-2up )' ), 'sn-dashboard.css uncaps .snt-leaf max-width for .snt-2up' );

	$analytics_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-analytics.php' );
	ok( false !== strpos( $analytics_src, '<div class="snt-2up">' ), 'monitoring/analytics source defines .snt-2up two columns' );
	ok( false !== strpos( $analytics_src, '<div class="snt-2up-col">' ), 'monitoring/analytics source defines .snt-2up-col columns' );
	ok( false !== strpos( $analytics_src, 'analytics_pipeline_html()' ), 'monitoring/analytics positions pipeline status above columns' );



	$shared_css = (string) file_get_contents( SNT_PATH . 'assets/os-app.css' );
	ok( false !== strpos( $shared_css, '.snt-stats {' ) && false !== strpos( $shared_css, 'repeat( auto-fit, minmax( 160px, 1fr ) )' ), 'assets/os-app.css defines .snt-stats responsive grid' );

	$mr_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-machine-readers.php' );
	ok( false !== strpos( $mr_src, '<div class="snt-2up">' ) && false !== strpos( $mr_src, '<div class="snt-2up-col">' ), 'monitoring/machine-readers paints .snt-2up with .snt-2up-col' );
	ok( false === strpos( $mr_src, '<div class="snt-col">' ), 'monitoring/machine-readers removes redundant .snt-col card wrappers' );

	$mr_parts_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-machine-readers-parts.php' );
	ok( false !== strpos( $mr_parts_src, '<os-cluster gap="8">' ), 'machine-readers-parts wraps sensor pills in os-cluster' );

	$models_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/ai-models-budget.php' );
	// 17.4.1 (#1573): rows of comparable height, the tags_pair idiom, no 2up.
	ok( false === strpos( $models_src, 'snt-2up' ) && false !== strpos( $models_src, '<div class="snt-cols"><section class="snt-col">' ), 'ai/models-budget renders .snt-cols rows, not the .snt-2up' );

	$insights_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-insights.php' );
	ok( false !== strpos( $insights_src, "'snt-cols'" ) && false === strpos( $insights_src, '<div class="snt-2up">' ), 'monitoring/insights paints .snt-cols rows, not the .snt-2up column pair (#1573)' );

	$gsc_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/monitoring-search-console.php' );
	ok( false !== strpos( $gsc_src, "'class' => 'snt-cols'" ) && false === strpos( $gsc_src, 'snt-2up' ), 'monitoring/search-console pairs its readouts on one .snt-cols row (17.4.1, #1573), no .snt-2up' );

	ok( false !== strpos( $css, '.snt-leaf os-row' ) && false !== strpos( $css, 'flex-direction: column' ), 'sn-dashboard.css collapses os-row under responsive containers' );

	if ( isset( $painters['connections/cloudflare'] ) ) {
		$cf_html = call_user_func( $painters['connections/cloudflare'], array( 'tab' => 'connections', 'sub' => 'cloudflare' ) );
		// 17.4.1 (#1573): boxes on .snt-cols rows of comparable height, not two stacked columns.
		ok( false !== strpos( $cf_html, '<div class="snt-cols">' ) && false === strpos( $cf_html, 'snt-2up' ), 'connections/cloudflare renders its boxes on a .snt-cols row, no .snt-2up columns' );
	}

	// #1217: two of the pulse tile links on S&N Home pointed at doors that do
	// not exist -- Visits used the removed sn_view=sessions slug (the slug
	// stayed 'visits' by design, see inc/analytics-admin.php), and Provenance
	// anchors pointed at tab=connections when the leaf lives under tab=tools.
	$dashboard_src = (string) file_get_contents( SNT_PATH . 'apps/sn-dashboard/parts/leaves/dashboard.php' );
	ok( false !== strpos( $dashboard_src, 'sn_view=visits&sn_range=7d' ), 'the Visits pulse tile links the live sn_view=visits slug, not the removed sn_view=sessions' );
	ok( false === strpos( $dashboard_src, 'sn_view=sessions' ), '...and sn_view=sessions does not linger anywhere in the leaf' );
	ok( false !== strpos( $dashboard_src, 'tab=tools&sub=provenance' ), 'the Provenance anchors pulse tile links tab=tools, where the provenance sub-tab actually lives' );
	ok( false === strpos( $dashboard_src, 'tab=connections&sub=provenance' ), '...and tab=connections&sub=provenance does not linger anywhere in the leaf' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}


