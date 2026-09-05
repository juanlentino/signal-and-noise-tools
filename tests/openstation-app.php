<?php
/**
 * Standalone test: the Signal & Noise app for OpenStation's App Framework.
 *
 * The framework is stubbed in its own namespaces (OpenStation\App, \App\State,
 * \App\Os, \App\Html\esc) with just enough surface to load the `.os.php`,
 * record what it declares, and drive its views and actions. WordPress is
 * stubbed flat. Run: php tests/openstation-app.php
 *
 * @since 13.98.0
 */

namespace OpenStation {
	final class App {
		public $id; public $title; public $icon; public $placement; public $caps = array(); public $state = array();
		public $actions = array(); public $view; public $tabs = array(); public $buttons = array(); public $watch = array();
		public static function define( $id ) { $a = new self(); $a->id = $id; return $a; }
		public function title( $t ) { $this->title = $t; return $this; }
		public function icon( $i ) { $this->icon = $i; return $this; }
		public function size( $w, $h ) { return $this; }
		public function min_size( $w, $h ) { return $this; }
		public function placement( $p ) { $this->placement = $p; return $this; }
		public function capabilities( ...$c ) { $this->caps = $c; return $this; }
		public function watch( ...$t ) { $this->watch = $t; return $this; }
		public function state( array $d ) { $this->state = $d; return $this; }
		public function title_bar_button( $id, array $a ) { $this->buttons[ $id ] = $a; return $this; }
		public function action( $n, callable $cb ) { $this->actions[ $n ] = $cb; return $this; }
		public function view( callable $cb ) { $this->view = $cb; return $this; }
		public function tab( $v, array $a ) { if ( 'main' === $v || empty( $a['label'] ) || ! is_callable( $a['view'] ?? null ) ) { throw new \InvalidArgumentException( 'bad tab' ); } $this->tabs[ $v ] = $a; return $this; }
	}
}

namespace OpenStation\App {
	class State {
		private $d; private $defaults;
		public function __construct( array $defaults = array(), array $in = array() ) { $this->defaults = $defaults; $this->d = array_merge( $defaults, $in ); }
		public function get( $k, $f = null ) { return array_key_exists( $k, $this->d ) ? $this->d[ $k ] : $f; }
		public function set( $k, $v ) { $this->d[ $k ] = $v; return $this; }
		public function reset( $k ) { $this->d[ $k ] = $this->defaults[ $k ] ?? null; return $this; }
		public function all() { return $this->d; }
	}
	class Os {
		public $opened = array();
		public function open_url( $u, $t = '', $i = '' ) { $this->opened[] = array( $u, $t, $i ); return $this; }
		public function can( $c ) { return true; }
	}
}

namespace OpenStation\App\Html {
	function esc( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
}

namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
	define( 'ABSPATH', '/' );
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );

	// ── WordPress, flat ──────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function __( $s, $d = null ) { return $s; }
	function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
	function number_format_i18n( $n ) { return (string) $n; }
	$GLOBALS['__caps'] = array( 'edit_posts' => true, 'manage_options' => true, 'edit_post' => true );
	function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
	function get_post( $id ) { foreach ( $GLOBALS['__posts'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
	function get_post_status_object( $s ) { return (object) array( 'label' => ucfirst( $s ) ); }
	function get_the_date( $f, $post ) { return substr( $post->post_date, 0, 10 ); }
	function get_the_post_thumbnail_url( $post, $size ) { return $post->thumb ?? ''; }
	function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
	function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
	function sn_prov_is_note( $id ) { return ! empty( $GLOBALS['__chains'][ $id ] ) || in_array( (int) $id, $GLOBALS['__notes'] ?? array(), true ); }
	function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
	function sn_discography_get() { return array( 'entries' => $GLOBALS['__albums'] ?? array() ); }
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
	$GLOBALS['__styles'] = array();
	function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $h ] = array( $src, $deps ); return true; }
	function wp_style_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $h ] ); }
	function openstation_apps_style_handle( $id ) { return 'openstation-app-' . $id; }
	class WP_Query {
		public $posts = array(); public $found_posts = 0;
		public function __construct( array $args ) {
			$GLOBALS['__last_query'] = $args;
			$s = strtolower( (string) ( $args['s'] ?? '' ) );
			$rows = array_values( array_filter( $GLOBALS['__posts'], function ( $p ) use ( $s ) { return '' === $s || false !== strpos( strtolower( $p->post_title ), $s ); } ) );
			$this->found_posts = isset( $GLOBALS['__found'] ) ? $GLOBALS['__found'] : count( $rows );
			$this->posts = $rows;
		}
	}
	function post( $id, $title, $status = 'publish', $date = '2026-08-14 10:00:00' ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => $date, 'post_type' => 'post' ); }

	$GLOBALS['__posts']  = array( post( 11, 'The signer keeps moving' ), post( 12, 'A draft <script>x</script>', 'draft' ) );
	$GLOBALS['__notes']  = array( 12 );
	$GLOBALS['__chains'] = array( 11 => array(
		array( 'version' => 1, 'status' => 'genesis', 'committed_at' => '2026-08-01T10:00:00Z', 'content_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaa' ),
		array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-08-14T10:00:00Z', 'content_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbb' ),
	) );
	$GLOBALS['__meta']   = array( 11 => array( '_sn_prov_uid' => 'sn:note:11' ) );
	$GLOBALS['__albums'] = array(
		array( 'id' => 'r1', 'title' => 'Older', 'artist' => 'Someone', 'year' => 2019, 'image' => 'https://img/1.jpg', 'roles' => array( 'Mixing' ), 'tracks' => array( array( 'title' => 'One', 'roles' => array( 'Mixing' ) ) ), 'spotify_url' => 'https://open.spotify.com/album/1', 'muso_url' => '' ),
		array( 'id' => '', 'title' => 'Newer', 'artist' => 'Band', 'year' => 2024, 'image' => '', 'roles' => array( 'Producer', 'Mixing', 'Mastering' ), 'tracks' => array(), 'spotify_url' => '', 'muso_url' => 'https://muso.ai/x' ),
	);

	require_once __DIR__ . '/../inc/openstation-app.php';
	$app = require __DIR__ . '/../apps/signal-noise/signal-noise.os.php';

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function render( $app, $section, array $state ) {
		$state = array_merge( array( 'section' => 'main' === $section ? '' : $section ), $state );
		ob_start(); $r = ( $app->view )( new \OpenStation\App\State( $app->state, $state ), new \OpenStation\App\Os() ); return ob_get_clean() . (string) $r;
	}

	echo "openstation-app -- plugin v13.98.0\n\nGroup 1: the framework finds the app\n";
	$dirs = apply_filters( 'openstation_apps_directories', array( '/openstation/apps' ) );
	ok( array( '/openstation/apps', rtrim( SNT_PATH, '/' ) . '/apps' ) === $dirs, 'openstation_apps_directories gains this plugin\'s apps/ after OpenStation\'s own' );
	ok( is_file( SNT_PATH . 'apps/signal-noise/signal-noise.os.php' ) && is_file( SNT_PATH . 'apps/signal-noise/signal-noise.css' ), 'the entry and its stylesheet sit where the loader and style() look' );

	echo "\nGroup 2: the definition\n";
	ok( $app instanceof \OpenStation\App && 'signal-noise' === $app->id, 'the file returns an App with id signal-noise' );
	ok( array( 'edit_posts' ) === $app->caps && 'dock' === $app->placement, 'gated on edit_posts; a dock tile' );
	ok( array( 'section', 'item', 'query', 'page' ) === array_keys( $app->state ) && '' === $app->state['section'] && '' === $app->state['item'] && 1 === $app->state['page'], 'state schema: section, item (strings), query, page' );
	foreach ( array( 'section', 'open', 'back', 'search', 'page', 'open-edit' ) as $a ) { ok( isset( $app->actions[ $a ] ), "action '$a' declared" ); }
	ok( is_callable( $app->view ) && array() === $app->tabs, 'one view, no framework tabs: sections are resolved at render time, not frozen at init' );
	ok( array( 'post' ) === $app->watch, 'repaints on post changes anywhere on the desktop' );

	echo "\nGroup 3: the registry is the extension point\n";
	$ids = array_column( snt_os_app_sections(), 'id' );
	ok( array( 'notes', 'discography' ) === $ids, 'two built-in sections, in position order' );
	add_filter( 'snt_os_app_sections', function ( $s ) { $s[] = array( 'id' => 'ledger', 'label' => 'Ledger', 'icon' => 'dashicons-shield', 'position' => 15, 'rows' => function () { return array( 'items' => array( array( 'id' => 'L1', 'title' => 'Entry one' ) ), 'total' => 1 ); } ); $s[] = array( 'id' => 'main', 'label' => 'Bad', 'rows' => function () {} ); $s[] = array( 'id' => 'norows', 'label' => 'Bad too' ); return $s; } );
	$ids = array_column( snt_os_app_sections(), 'id' );
	ok( array( 'notes', 'ledger', 'discography' ) === $ids, 'a third section from another module slots in by position; "main" and a descriptor without rows are dropped' );
	$GLOBALS['__caps']['manage_options'] = false;
	ok( array( 'notes', 'ledger' ) === array_column( snt_os_app_sections(), 'id' ), 'a section whose capability the user lacks is not offered' );
	$GLOBALS['__caps']['manage_options'] = true;
	$ledger = null; foreach ( snt_os_app_sections() as $s ) { if ( 'ledger' === $s['id'] ) { $ledger = $s; } }
	$html = \SignalNoise\OpenStationApp\frame_view( $ledger, new \OpenStation\App\State( $app->state ), new \OpenStation\App\Os() );
	ok( false !== strpos( $html, 'Entry one' ) && false !== strpos( $html, 'os-arg-item="L1"' ), 'the frame paints a foreign section from its rows alone -- no markup needed from the section' );

	echo "\nGroup 4: Notes\n";
	$html = render( $app, 'main', array() );
	ok( preg_match_all( '/os-action="section" os-arg-to="([a-z]+)"/', $html, $m ) === 3 && array( 'notes', 'ledger', 'discography' ) === $m[1], 'the body opens on a switcher with every section the user may use, in order' );
	ok( false !== strpos( $html, 'aria-selected="true" os-action="section" os-arg-to="notes"' ), 'no section chosen: the first one is active' );
	$GLOBALS['__caps']['manage_options'] = false;
	ok( false === strpos( render( $app, 'main', array() ), 'os-arg-to="discography"' ), 'the switcher is gated at RENDER time: drop a capability and the tab is gone on the next paint' );
	$GLOBALS['__caps']['manage_options'] = true;
	ok( false !== strpos( $html, 'The signer keeps moving' ) && false !== strpos( $html, 'A draft &lt;script&gt;x&lt;/script&gt;' ), 'lists both notes; a hostile title is escaped' );
	ok( false !== strpos( $html, 'tone="positive"' ) && false !== strpos( $html, 'v2 · Anchored' ), 'the anchored note wears a v2 · Anchored chip' );
	ok( false !== strpos( $html, 'Select an item' ) && false === strpos( $html, 'data-open' ), 'no selection: the dossier pane is the empty state' );
	ok( 'notes' === $GLOBALS['__last_query']['category_name'] && 'post' === $GLOBALS['__last_query']['post_type'], 'the query is the Notes category over posts (the sn_prov_note_category default)' );
	$html = render( $app, 'main', array( 'item' => '11' ) );
	ok( false !== strpos( $html, 'data-open="1"' ) && false !== strpos( $html, 'aria-current="true"' ), 'a selection opens the dossier and marks the row' );
	ok( preg_match( '/2 signed versions.*v2.*Anchored.*2026-08-14.*bbbbbbbbbbbb.*v1.*Genesis.*aaaaaaaaaaaa/s', $html ) === 1, 'the chain reads newest first: version, status, date, short hash' );
	ok( false !== strpos( $html, 'sn:note:11' ), 'the ledger UID is shown' );
	ok( false !== strpos( $html, 'os-action="open-edit" os-arg-section="notes" os-arg-item="11"' ) && false !== strpos( $html, 'View on site' ), 'Edit dispatches with the section and item; a published note links to the site' );
	$html = render( $app, 'main', array( 'item' => '12' ) );
	ok( false !== strpos( $html, 'No signed chain yet' ) && false === strpos( $html, 'View on site' ), 'a draft without a chain says so, and has no site link' );
	$html = render( $app, 'main', array( 'query' => 'signer' ) );
	ok( false !== strpos( $html, 'The signer keeps moving' ) && false === strpos( $html, 'A draft' ), 'the search narrows the list' );
	$GLOBALS['__found'] = 45;
	$html = render( $app, 'main', array() );
	ok( false !== strpos( $html, 'Page 1 of 2' ) && false !== strpos( $html, 'os-arg-to="2"' ), 'more rows than a page: the pager appears with the next page' );
	unset( $GLOBALS['__found'] );

	echo "\nGroup 5: Discography\n";
	$html = render( $app, 'discography', array() );
	ok( preg_match( '/Newer.*Older/s', $html ) === 1, 'releases list newest year first' );
	ok( false !== strpos( $html, 'src="https://img/1.jpg"' ) && false !== strpos( $html, 'icon="dashicons-album"' ), 'cover art when there is one, the album icon otherwise' );
	$html = render( $app, 'discography', array( 'item' => 'r1' ) );
	ok( false !== strpos( $html, '1 track' ) && false !== strpos( $html, '>One<' ) && false !== strpos( $html, 'https://open.spotify.com/album/1' ) && false === strpos( $html, 'muso.ai' ), 'the dossier lists tracks and only the links the entry has' );
	ok( false === strpos( $html, 'open-edit' ), 'a release has no editor' );
	$html = render( $app, 'discography', array( 'query' => 'mastering' ) );
	ok( false !== strpos( $html, 'Newer' ) && false === strpos( $html, 'Older' ), 'search matches roles too' );
	$html = render( $app, 'discography', array( 'query' => 'zzz' ) );
	ok( false !== strpos( $html, 'No release matches' ), 'no match: the section\'s own empty state' );

	echo "\nGroup 6: actions\n";
	$os = new \OpenStation\App\Os();
	$st = new \OpenStation\App\State( $app->state );
	$app->actions['open']( $st, $os, array( 'item' => '11' ) );
	ok( '11' === $st->get( 'item' ), 'open selects' );
	$app->actions['search']( $st, $os, array() );
	ok( '' === $st->get( 'item' ) && 1 === $st->get( 'page' ), 'search closes the dossier and returns to page 1' );
	$app->actions['page']( $st, $os, array( 'to' => '0' ) );
	ok( 1 === $st->get( 'page' ), 'page never goes below 1' );
	$st->set( 'item', '11' )->set( 'query', 'x' )->set( 'page', 3 );
	$app->actions['section']( $st, $os, array( 'to' => 'discography' ) );
	ok( 'discography' === $st->get( 'section' ) && '' === $st->get( 'item' ) && '' === $st->get( 'query' ) && 1 === $st->get( 'page' ), 'switching section closes the dossier and clears the search and page' );
	$app->actions['open-edit']( $st, $os, array( 'section' => 'notes', 'item' => '11' ) );
	ok( 1 === count( $os->opened ) && false !== strpos( $os->opened[0][0], 'post.php?post=11' ) && 'The signer keeps moving' === $os->opened[0][1], 'open-edit opens the editor window for the note' );
	$app->actions['open-edit']( $st, $os, array( 'section' => 'discography', 'item' => 'r1' ) );
	$app->actions['open-edit']( $st, $os, array( 'section' => 'nope', 'item' => '11' ) );
	ok( 1 === count( $os->opened ), 'nothing opens for a section without an editor or an unknown section' );
	$GLOBALS['__caps']['edit_post'] = false;
	$os2 = new \OpenStation\App\Os();
	$app->actions['open-edit']( $st, $os2, array( 'section' => 'notes', 'item' => '11' ) );
	ok( array() === $os2->opened, 'the capability is re-checked server-side before opening anything' );

	echo "\nGroup 7: the stylesheet survives a symlinked plugin directory\n";
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'openstation-app-runtime', 'openstation-app-signal-noise' ) ), 'signal-noise', null );
	ok( array( 'openstation-app-runtime', 'openstation-app-signal-noise' ) === $args['styles'] && array() === $GLOBALS['__styles'], 'when the framework mapped the sheet itself nothing is added or registered (a plain install loads it once)' );
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'openstation-app-runtime' ) ), 'signal-noise', null );
	ok( array( 'openstation-app-runtime', 'snt-os-app-signal-noise' ) === $args['styles'], 'when it could not (realpath left wp-content), our handle rides the window' );
	ok( isset( $GLOBALS['__styles']['snt-os-app-signal-noise'] ) && SNT_URL . 'apps/signal-noise/signal-noise.css' === $GLOBALS['__styles']['snt-os-app-signal-noise'][0] && array( 'os-variables' ) === $GLOBALS['__styles']['snt-os-app-signal-noise'][1], '   ...registered from the plugin URL, after the shell variables sheet' );
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'openstation-app-runtime' ) ), 'my-wordpress', null );
	ok( array( 'openstation-app-runtime' ) === $args['styles'], 'another app\'s window is left alone' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
