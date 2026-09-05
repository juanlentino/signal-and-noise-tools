<?php
/**
 * Standalone test: the Signal & Noise app for OpenStation's App Framework,
 * the client-view build (#1049).
 *
 * The framework is stubbed in its own namespaces with just enough surface to
 * load the `.os.php`, record what it declares, and drive its data payload and
 * server actions. The client view is JavaScript the browser runs; here it is
 * pinned by file and by the vocabulary it consumes. WordPress is stubbed flat.
 * Run: php tests/openstation-app.php
 *
 * @since 13.98.0
 */

namespace OpenStation {
	final class App {
		public $id; public $title; public $icon; public $placement; public $caps = array(); public $state = array();
		public $actions = array(); public $view; public $data; public $client; public $tabs = array(); public $buttons = array(); public $watch = array();
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
		public function data( callable $cb ) { $this->data = $cb; return $this; }
		public function client( $p ) { $this->client = $p; return $this; }
		public function tab( $v, array $a ) { $this->tabs[ $v ] = $a; return $this; }
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

namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
	define( 'ABSPATH', '/' );
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );

	// ── WordPress, flat ──────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function __( $s, $d = null ) { return $s; }
	function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
	function get_bloginfo( $k ) { return 'Juan &amp; Co'; }
	function home_url( $p = '' ) { return 'https://example.test' . $p; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	$GLOBALS['__caps'] = array( 'edit_posts' => true, 'manage_options' => true, 'edit_post' => true );
	function current_user_can( $cap, ...$a ) { return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
	function get_post( $id ) { foreach ( $GLOBALS['__posts'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
	function get_post_status_object( $s ) { return (object) array( 'label' => array( 'publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft' )[ $s ] ?? ucfirst( $s ) ); }
	function get_the_date( $f, $post ) { return substr( $post->post_date, 0, 10 ); }
	function get_post_time( $f, $gmt, $post ) { return str_replace( ' ', 'T', $post->post_date ) . '+00:00'; }
	function get_the_post_thumbnail_url( $post, $size ) { return $post->thumb ?? ''; }
	function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
	function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
	function sn_prov_is_note( $id ) { return true; }
	function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
	function sn_discography_get() { return array( 'entries' => $GLOBALS['__albums'] ?? array() ); }
	$GLOBALS['__styles'] = array(); $GLOBALS['__scripts'] = array();
	function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $h ] = array( $src, $deps ); return true; }
	function wp_style_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $h ] ); }
	function wp_register_script( $h, $src, $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $h ] = array( $src, $deps, $footer ); return true; }
	function wp_script_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['__scripts'][ $h ] ); }
	function openstation_apps_style_handle( $id ) { return 'openstation-app-' . $id; }
	class WP_Query {
		public $posts = array(); public $found_posts = 0;
		public function __construct( array $args ) { $GLOBALS['__last_query'] = $args; $this->posts = 'ids' === ( $args['fields'] ?? '' ) ? array() : $GLOBALS['__posts']; $this->found_posts = count( $GLOBALS['__posts'] ); }
	}
	function post( $id, $title, $status = 'publish', $date = '2026-08-14 10:00:00', $thumb = '' ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => $date, 'post_type' => 'post', 'thumb' => $thumb ); }

	$GLOBALS['__posts']  = array(
		post( 21, 'The ban failed', 'future', '2026-12-03 10:00:00' ),
		post( 11, 'The signer keeps moving', 'publish', '2026-08-14 10:00:00', 'https://img/11.jpg' ),
		post( 12, 'A draft <script>x</script>', 'draft', '2026-08-01 10:00:00' ),
	);
	$GLOBALS['__chains'] = array( 11 => array(
		array( 'version' => 1, 'status' => 'genesis', 'committed_at' => '2026-08-01T10:00:00Z', 'content_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaa' ),
		array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-08-14T10:00:00Z', 'content_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbb' ),
	) );
	$GLOBALS['__meta']   = array( 11 => array( '_sn_prov_uid' => 'sn:note:11' ) );
	$GLOBALS['__albums'] = array(
		array( 'id' => 'r1', 'title' => 'Older', 'artist' => 'Someone', 'year' => 2019, 'image' => 'https://img/1.jpg', 'roles' => array( 'Mixing' ), 'tracks' => array( array( 'title' => 'One', 'roles' => array( 'Mixing' ) ) ), 'spotify_url' => 'https://open.spotify.com/album/1', 'muso_url' => '' ),
		array( 'id' => '', 'title' => 'Newer', 'artist' => 'Band', 'year' => 2024, 'image' => '', 'roles' => array( 'Producer', 'Mastering' ), 'tracks' => array(), 'spotify_url' => '', 'muso_url' => 'https://muso.ai/x' ),
	);

	require_once __DIR__ . '/../inc/openstation-app.php';
	$app = require __DIR__ . '/../apps/signal-noise/signal-noise.os.php';

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function payload( $app, array $state ) { return ( $app->data )( new \OpenStation\App\State( $app->state, $state ), new \OpenStation\App\Os() ); }

	echo "openstation-app -- the client-view build (#1049)\n\nGroup 1: the framework finds the app, its client view and its stylesheet\n";
	ok( array( '/os/apps', rtrim( SNT_PATH, '/' ) . '/apps' ) === apply_filters( 'openstation_apps_directories', array( '/os/apps' ) ), 'openstation_apps_directories gains this plugin\'s apps/' );
	ok( $app instanceof \OpenStation\App && 'signal-noise' === $app->id, 'the file returns an App with id signal-noise' );
	ok( is_file( (string) $app->client ) && basename( $app->client ) === 'signal-noise-client.js', 'client() names the companion script beside the entry (a client-view app: no view())' );
	ok( null === $app->view && is_callable( $app->data ), 'no server view; data() is the contract' );
	$js = (string) file_get_contents( $app->client );
	ok( false !== strpos( $js, "defineApp( 'signal-noise'" ) && false !== strpos( $js, 'openStationAppsPending' ), 'the client registers through the runtime\'s pending queue under the app id' );
	foreach ( array( '<os-tile', 'statusControl(', '<os-table', '<os-badge', '<os-empty-state', '<os-text-field', '<os-segmented' ) as $part ) { ok( false !== strpos( $js, $part ), "the client paints with the kit: $part" ); }
	ok( false !== strpos( $js, 'wp.os.mode.isMobile' ), 'the client reads the shell\'s mode stamp for the phone layout' );

	echo "\nGroup 2: the definition\n";
	ok( array( 'edit_posts' ) === $app->caps && 'dock' === $app->placement && array( 'post' ) === $app->watch, 'gated on edit_posts; a dock tile; repaints on post changes' );
	ok( array( 'section', 'item', 'status', 'query', 'view' ) === array_keys( $app->state ) && 'icons' === $app->state['view'], 'state schema: section, item, status, query, view' );
	ok( array( 'go', 'edit' ) === array_keys( $app->actions ), 'exactly two server actions: go and edit -- everything else is local in the browser' );

	echo "\nGroup 3: the registry is the extension point\n";
	ok( array( 'notes', 'discography' ) === array_column( snt_os_app_sections(), 'id' ), 'two built-in sections, in position order' );
	add_filter( 'snt_os_app_sections', function ( $s ) { $s[] = array( 'id' => 'ledger', 'label' => 'Ledger', 'icon' => 'dashicons-shield', 'kind' => 'ledger', 'position' => 15, 'items' => function () { return array( array( 'id' => 'L1', 'title' => 'Entry one', 'status' => 'publish', 'detail' => array( 'facts' => array( array( 'Kind', 'ledger' ) ) ) ) ); } ); $s[] = array( 'id' => 'noitems', 'label' => 'Bad' ); return $s; } );
	ok( array( 'notes', 'ledger', 'discography' ) === array_column( snt_os_app_sections(), 'id' ), 'a third section from another module slots in by position; a descriptor without items is dropped' );
	$GLOBALS['__caps']['manage_options'] = false;
	ok( array( 'notes', 'ledger' ) === array_column( snt_os_app_sections(), 'id' ), 'a section whose capability the user lacks is not offered' );
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 4: the payload at the root\n";
	$p = payload( $app, array() );
	ok( 'Juan & Co' === $p['siteName'], 'siteName is decoded once (never an entity)' );
	ok( array( 'notes', 'ledger', 'discography' ) === array_column( $p['sections'], 'id' ) && array( 3, 1, 2 ) === array_column( $p['sections'], 'count' ), 'the root lists every section with its count' );
	ok( null === $p['section'] && array() === $p['items'], 'no section open: no items travel' );
	ok( 'post' === $p['sections'][0]['kind'] && 'dashicons-edit-page' === $p['sections'][0]['icon'], 'a section carries the tile type and icon the client paints with' );

	echo "\nGroup 5: Notes\n";
	$p = payload( $app, array( 'section' => 'notes' ) );
	ok( 'notes' === $p['section']['id'] && 'publish' === $p['section']['defaultStatus'] && 'publish' === $p['section']['statuses'][0]['value'] && true === $p['section']['canEdit'], 'the Notes section declares its pills, its default (Published) and that it has an editor' );
	ok( array( '21', '11', '12' ) === array_column( $p['items'], 'id' ), 'items travel newest first, every status -- the client filters' );
	$n11 = $p['items'][1];
	ok( array( 'text' => 'v2', 'tone' => 'success', 'title' => 'Anchored' ) === $n11['badge'], 'an anchored note wears v2 in the success tone' );
	ok( null === $p['items'][0]['badge'] && 'future' === $p['items'][0]['status'] && 'Scheduled' === $p['items'][0]['statusLabel'], 'a scheduled note has no badge (signed on publish) and carries its status for the ribbon and the pills' );
	ok( 'https://img/11.jpg' === $n11['thumbnail'] && '' === $p['items'][2]['thumbnail'] && 'dashicons-edit-page' === $p['items'][2]['icon'], 'thumbnail when there is one; the note icon otherwise' );
	ok( array( '2', 'Anchored' ) === array_values( $n11['columns'] ), 'list-view cells: versions and anchor' );
	$d = $n11['detail'];
	ok( 'table' === $d['blocks'][0]['kind'] && 'v2' === $d['blocks'][0]['rows'][0]['version'] && 'success' === $d['blocks'][0]['rows'][0]['anchor']['tone'] && 'bbbbbbbbbbbb' === $d['blocks'][0]['rows'][0]['hash']['code'] && 'v1' === $d['blocks'][0]['rows'][1]['version'], 'the chain is a table, newest first, with a coded hash and a toned anchor' );
	ok( 'code' === $d['blocks'][1]['kind'] && 'sn:note:11' === $d['blocks'][1]['text'], 'the ledger UID is a code block' );
	$labels = array_column( $d['actions'], 'label' );
	ok( array( 'Open in editor', 'Verify', 'View on site' ) === $labels && 'edit' === $d['actions'][0]['dispatch'] && '11' === $d['actions'][0]['args']['item'], 'actions: the editor first (a dispatch), the verifier and the site (URLs)' );
	ok( false !== strpos( $d['actions'][1]['url'], '/verify/?note=sn%3Anote%3A11&v=2' ), 'Verify links the public verifier at this uid and version' );
	$d12 = $p['items'][2]['detail'];
	ok( 'text' === $d12['blocks'][0]['kind'] && false !== strpos( $d12['blocks'][0]['text'], 'signed when it is published' ) && array( 'Open in editor' ) === array_column( $d12['actions'], 'label' ), 'a draft says why it has no chain and has no site link' );
	ok( 'A draft <script>x</script>' === $p['items'][2]['title'], 'titles travel as plain text; the client escapes' );
	$GLOBALS['__caps']['edit_post'] = false;
	$p2 = payload( $app, array( 'section' => 'notes' ) );
	ok( array( 'Verify', 'View on site' ) === array_column( $p2['items'][1]['detail']['actions'], 'label' ), 'without edit_post the editor action is not offered' );
	$GLOBALS['__caps']['edit_post'] = true;

	echo "\nGroup 6: Discography\n";
	$p = payload( $app, array( 'section' => 'discography' ) );
	ok( array() === $p['section']['statuses'] && '' === $p['section']['defaultStatus'] && false === $p['section']['canEdit'], 'no pills, no editor' );
	ok( array( 'Newer', 'Older' ) === array_column( $p['items'], 'title' ), 'newest year first' );
	ok( 'https://img/1.jpg' === $p['items'][1]['thumbnail'] && '2019' === $p['items'][1]['badge']['text'], 'cover art as the tile thumbnail; the year as its badge' );
	ok( 'Tracks' === $p['items'][1]['detail']['blocks'][0]['heading'] && 'One' === $p['items'][1]['detail']['blocks'][0]['rows'][0]['title'] && array( 'Open in Spotify' ) === array_column( $p['items'][1]['detail']['actions'], 'label' ), 'the dossier carries the tracks and only the links the entry has' );
	ok( array() === $p['items'][0]['detail']['blocks'] && array( 'Credits on Muso.AI' ) === array_column( $p['items'][0]['detail']['actions'], 'label' ), 'no tracks: no table' );

	echo "\nGroup 7: a foreign section paints from its items alone\n";
	$p = payload( $app, array( 'section' => 'ledger' ) );
	ok( 'ledger' === $p['section']['id'] && 'ledger' === $p['section']['kind'] && 'L1' === $p['items'][0]['id'], 'the payload carries a third section exactly as it carries the built-ins' );

	echo "\nGroup 8: server actions\n";
	$os = new \OpenStation\App\Os();
	$st = new \OpenStation\App\State( $app->state, array( 'item' => 'x', 'query' => 'q', 'status' => 'draft' ) );
	$app->actions['go']( $st, $os, array( 'section' => 'notes' ) );
	ok( 'notes' === $st->get( 'section' ) && '' === $st->get( 'item' ) && '' === $st->get( 'query' ) && 'publish' === $st->get( 'status' ), 'go opens a section on its default pill with the facets cleared' );
	$app->actions['go']( $st, $os, array() );
	ok( '' === $st->get( 'section' ), 'go with no section returns to the root' );
	$app->actions['go']( $st, $os, array( 'section' => 'nope' ) );
	ok( '' === $st->get( 'section' ), 'an unknown section is the root, not an error' );
	$st->set( 'section', 'notes' );
	$app->actions['edit']( $st, $os, array( 'item' => '11', 'title' => 'The signer keeps moving' ) );
	ok( 1 === count( $os->opened ) && false !== strpos( $os->opened[0][0], 'post.php?post=11' ) && 'The signer keeps moving' === $os->opened[0][1] && 'dashicons-edit-page' === $os->opened[0][2], 'edit opens the editor window with the section icon' );
	$GLOBALS['__caps']['edit_post'] = false;
	$app->actions['edit']( $st, $os, array( 'item' => '11' ) );
	ok( 1 === count( $os->opened ), 'the capability is re-checked server-side before opening anything' );
	$GLOBALS['__caps']['edit_post'] = true;
	$st->set( 'section', 'discography' );
	$app->actions['edit']( $st, $os, array( 'item' => 'r1' ) );
	ok( 1 === count( $os->opened ), 'a section without an editor opens nothing' );

	echo "\nGroup 9: the sheet and the script survive a symlinked plugin directory\n";
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'rt', 'openstation-app-signal-noise' ), 'scripts' => array( 'openstation-app-signal-noise-client' ) ), 'signal-noise', null );
	ok( array( 'rt', 'openstation-app-signal-noise' ) === $args['styles'] && array( 'openstation-app-signal-noise-client' ) === $args['scripts'] && array() === $GLOBALS['__styles'] && array() === $GLOBALS['__scripts'], 'when the framework mapped both itself nothing is added or registered' );
	$args = apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'rt' ), 'scripts' => array() ), 'signal-noise', null );
	ok( array( 'rt', 'snt-os-app-signal-noise' ) === $args['styles'] && array( 'snt-os-app-signal-noise-client' ) === $args['scripts'], 'when it could not, our handles ride the window' );
	ok( SNT_URL . 'apps/signal-noise/signal-noise-client.js' === $GLOBALS['__scripts']['snt-os-app-signal-noise-client'][0] && array( 'wp-i18n' ) === $GLOBALS['__scripts']['snt-os-app-signal-noise-client'][1] && true === $GLOBALS['__scripts']['snt-os-app-signal-noise-client'][2], '   ...the script from the plugin URL, after wp-i18n, in the footer' );
	ok( array( 'rt' ) === apply_filters( 'openstation_app_window_args', array( 'styles' => array( 'rt' ) ), 'my-wordpress', null )['styles'], 'another app\'s window is left alone' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
