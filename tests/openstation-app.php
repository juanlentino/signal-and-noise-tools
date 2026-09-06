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
		public $config = array();
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
		public function config( array $c ) { $this->config = $c; return $this; }
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
		public function can( $c, ...$a ) { $GLOBALS['__can_calls'][] = array( $c, $a ); return $GLOBALS['__os_can'] ?? true; }
	}
}

namespace {
	if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
	define( 'ABSPATH', '/' );
	define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
	// The plugin version, which now rides BOTH halves of the window: frozen
	// into the document by config(), live on every dispatch through payload().
	define( 'SNT_VERSION', '13.103.0' );

	// ── WordPress, flat ──────────────────────────────────────────────
	$GLOBALS['__filters'] = array();
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $hook ][] = $cb; return true; }
	// Real, because the author scope is a filter added around one query and
	// removed after it: a forgetful stub would let a clause leak onto the next.
	function remove_filter( $hook, $cb, $prio = 10 ) {
		foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $i => $one ) { if ( $one === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $i ] ); } }
		return true;
	}
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function get_current_user_id() { return (int) ( $GLOBALS['__uid'] ?? 5 ); }
	$wpdb = (object) array( 'posts' => 'wp_posts' );
	function __( $s, $d = null ) { return $s; }
	function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
	function get_bloginfo( $k ) { return 'Juan &amp; Co'; }
	function home_url( $p = '' ) { return 'https://example.test' . $p; }
	function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	$GLOBALS['__caps'] = array( 'edit_posts' => true, 'edit_pages' => true, 'manage_options' => true, 'edit_post' => true );
	function current_user_can( $cap, ...$a ) { $GLOBALS['__cap_calls'][] = array( $cap, $a ); return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
	function get_post( $id ) { foreach ( $GLOBALS['__posts'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
	function get_post_status_object( $s ) { return (object) array( 'label' => array( 'publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft' )[ $s ] ?? ucfirst( $s ) ); }
	function get_the_date( $f, $post ) { return substr( $post->post_date, 0, 10 ); }
	function get_post_time( $f, $gmt, $post ) { return str_replace( ' ', 'T', $post->post_date ) . '+00:00'; }
	function get_the_post_thumbnail_url( $post, $size ) { return $post->thumb ?? ''; }
	function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
	function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
	// The gate snt_os_app_note_provenance() asks is the SUBJECT KIND, not
	// "is this a Note?": a page opts in through the sign meta, a post is a
	// note, anything else is not a subject at all.
	function sn_prov_subject_kind( $post ) {
		$type = (string) ( $post->post_type ?? '' );
		if ( 'post' === $type ) { return 'note'; }
		if ( 'page' === $type ) { return '' !== (string) ( $GLOBALS['__meta'][ (int) $post->ID ]['_sn_prov_sign'] ?? '' ) ? 'page' : ''; }
		return '';
	}
	// The publish capability comes from the type object: publish_posts is the
	// POST cap and a page needs publish_pages.
	function get_post_type_object( $post_type ) {
		$caps = array(
			'post' => array( 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ),
			'page' => array( 'publish_posts' => 'publish_pages', 'edit_others_posts' => 'edit_others_pages' ),
		);
		return isset( $caps[ (string) $post_type ] )
			? (object) array( 'name' => (string) $post_type, 'cap' => (object) $caps[ (string) $post_type ] )
			: null;
	}
	function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
	function sn_note_dossier_verify( $id, $f = null ) { return array( 'post_id' => (int) $id, 'tone' => 'success', 'text' => 'v1 holds.', 'meta' => 'checked', 'checked_at' => '2026-09-05T20:00:00+00:00' ); }
	function sn_discography_get() { return array( 'entries' => $GLOBALS['__albums'] ?? array() ); }
	$GLOBALS['__styles'] = array(); $GLOBALS['__scripts'] = array();
	function wp_register_style( $h, $src, $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $h ] = array( $src, $deps ); return true; }
	function wp_style_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['__styles'][ $h ] ); }
	function wp_register_script( $h, $src, $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $h ] = array( $src, $deps, $footer ); return true; }
	function wp_script_is( $h, $list = 'enqueued' ) { return isset( $GLOBALS['__scripts'][ $h ] ); }
	function openstation_apps_style_handle( $id ) { return 'openstation-app-' . $id; }
	// ARG-AWARE ON PURPOSE. Until v13.102.0 this stub ignored its args and
	// returned every fixture for every query, so a second post section could
	// have painted the Notes fixtures and gone green while filtering nothing.
	// It honours post_type and the meta predicate; the counts pinned below
	// (3 notes, 2 signed pages out of 6 fixtures) are the negative control.
	// It also ORDERS (date DESC, ID DESC), BOUNDS (posts_per_page) and runs the
	// `posts_where` clauses somebody attached -- the three disciplines the real
	// query has, and the three a section can silently stop asking for.
	class WP_Query {
		public $posts = array(); public $found_posts = 0;
		public function __construct( array $args ) {
			$GLOBALS['__last_query'] = $args;
			$types = array_map( 'strval', (array) ( $args['post_type'] ?? 'post' ) );
			$where = (string) apply_filters( 'posts_where', '', $this );
			$mine  = preg_match( '/post_author = (\d+)/', $where, $m ) ? (int) $m[1] : 0;
			$found = array();
			foreach ( $GLOBALS['__posts'] as $p ) {
				if ( ! in_array( (string) $p->post_type, $types, true ) ) { continue; }
				if ( ! empty( $args['meta_key'] ) ) {
					$have = (string) ( $GLOBALS['__meta'][ (int) $p->ID ][ (string) $args['meta_key'] ] ?? '' );
					if ( $have !== (string) ( $args['meta_value'] ?? '' ) ) { continue; }
				}
				if ( $mine > 0 && 'publish' !== (string) $p->post_status && (int) $p->post_author !== $mine ) { continue; }
				$found[] = $p;
			}
			if ( ! empty( $args['orderby'] ) && is_array( $args['orderby'] ) ) {
				usort( $found, static function ( $a, $b ) { return strcmp( (string) $b->post_date, (string) $a->post_date ) ?: ( (int) $b->ID <=> (int) $a->ID ); } );
			}
			$this->found_posts = count( $found );
			$per               = (int) ( $args['posts_per_page'] ?? -1 );
			if ( $per > 0 ) { $found = array_slice( $found, 0, $per ); }
			$this->posts = 'ids' === ( $args['fields'] ?? '' ) ? array() : $found;
		}
	}
	function post( $id, $title, $status = 'publish', $date = '2026-08-14 10:00:00', $thumb = '' ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => $date, 'post_type' => 'post', 'thumb' => $thumb, 'post_author' => 5 ); }

	function page( $id, $title, $status = 'publish', $date = '2026-07-01 09:00:00' ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => $date, 'post_type' => 'page', 'thumb' => '', 'post_author' => 5 ); }

	// DELIBERATELY NOT IN DATE ORDER, so "newest first" below is a property of
	// the query and not of this literal.
	$GLOBALS['__posts']  = array(
		post( 12, 'A draft <script>x</script>', 'draft', '2026-08-01 10:00:00' ),
		// Two pages opted into signing, and one that never did: the Pages
		// section lists the opt-in, not the post type.
		page( 31, 'Start here' ),
		post( 21, 'The ban failed', 'future', '2026-12-03 10:00:00' ),
		page( 33, 'Stats' ),
		post( 11, 'The signer keeps moving', 'publish', '2026-08-14 10:00:00', 'https://img/11.jpg' ),
		page( 32, 'Colophon', 'draft', '2026-06-02 09:00:00' ),
	);
	$GLOBALS['__chains'] = array( 11 => array(
		array( 'version' => 1, 'status' => 'genesis', 'committed_at' => '2026-08-01T10:00:00Z', 'content_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaa' ),
		array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-08-14T10:00:00Z', 'content_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbb' ),
	) );
	$GLOBALS['__meta']   = array( 11 => array( '_sn_prov_uid' => 'sn:note:11' ), 31 => array( '_sn_prov_sign' => '1' ), 32 => array( '_sn_prov_sign' => '1' ) );
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
	foreach ( array( "kind === 'stats'", "kind === 'status'", 'ctx.extra', 'dossierUrl', 'ctx.fetch(', '@os-pick', 'ctx.host.openUrl(', 'data.verdict', 'updated:', 'section.hasDossier' ) as $part ) { ok( false !== strpos( $js, $part ), "the client carries the dossier: $part" ); }
	ok( false === strpos( $js, '/wp-abilities/' ), 'the client never spells the abilities path; it comes from the window config' );
ok( 2 === substr_count( $js, 'section.hasDossier' ) && 0 === substr_count( $js, "section.id === 'notes'" ), 'both the render and the updated() fetch are gated on the DESCRIPTOR field, and neither on the section id: a count, since one copy would keep a presence pin green, and a second post section proved an id cannot answer what a section HAS' );
ok( 1 === substr_count( $js, 'ctx.ui(' ), 'exactly one ctx.ui() bag: the runtime keeps one per mounted view and silently discards every later factory' );
foreach ( array( 'ui.errors', 'ERROR_TTL_MS', 'forgetDossier( ctx, item.id )', "'noopener,noreferrer'", 'fetched_at', 'Try again' ) as $part ) { ok( false !== strpos( $js, $part ), "the client keeps failures out of the cache, refetches after a re-check, opens external doors in a tab, dates the read: $part" ); }

	echo "\nGroup 2: the definition\n";
	ok( array( 'edit_posts' ) === $app->caps && 'dock' === $app->placement && array( 'post' ) === $app->watch, 'gated on edit_posts; a dock tile; repaints on post changes' );
	ok( array( 'section', 'item', 'status', 'query', 'view', 'verdict', 'selected' ) === array_keys( $app->state ) && 'icons' === $app->state['view'] && array() === $app->state['verdict'] && array() === $app->state['selected'], 'state schema: section, item, status, query, view, verdict, selected (two array slots)' );
	ok( array( 'go', 'edit', 'verify', 'jump', 'trash', 'publish', 'purge', 'anchor' ) === array_keys( $app->actions ), 'eight server actions: go, edit, verify, jump and the control surface\'s four -- everything else is local in the browser' );
	ok( 'https://example.test/wp-json/wp-abilities/v1/abilities/signal-noise/note-dossier/run' === ( $app->config['dossierUrl'] ?? '' ), 'the ability run URL rides the window config, so the client never spells the abilities path' );
	ok( SNT_VERSION === ( $app->config['version'] ?? '' ), 'the plugin version rides the window config: the half of the stale-build detector that FREEZES into the document at render' );

	echo "\nGroup 3: the registry is the extension point\n";
	ok( array( 'attention', 'notes', 'pages', 'discography', 'citations', 'schedules' ) === array_column( snt_os_app_sections(), 'id' ), 'the built-in sections, in position order (5, 10, 12, 20, 30, 40) -- Attention FIRST, because it is what the phone opens on' );
	add_filter( 'snt_os_app_sections', function ( $s ) { $s[] = array( 'id' => 'ledger', 'label' => 'Ledger', 'icon' => 'dashicons-shield', 'kind' => 'ledger', 'position' => 15, 'items' => function () { return array( array( 'id' => 'L1', 'title' => 'Entry one', 'status' => 'publish', 'detail' => array( 'facts' => array( array( 'Kind', 'ledger' ) ) ) ) ); } ); $s[] = array( 'id' => 'noitems', 'label' => 'Bad' ); return $s; } );
	ok( array( 'attention', 'notes', 'pages', 'ledger', 'discography', 'citations', 'schedules' ) === array_column( snt_os_app_sections(), 'id' ), 'a section from another module slots in by position (15, between Pages and Discography); a descriptor without items is dropped' );
	$GLOBALS['__caps']['manage_options'] = false;
	ok( array( 'notes', 'pages', 'ledger' ) === array_column( snt_os_app_sections(), 'id' ), 'a section whose capability the user lacks is not offered' );
	$GLOBALS['__caps']['edit_pages'] = false;
	ok( ! in_array( 'pages', array_column( snt_os_app_sections(), 'id' ), true ), '   ...and Pages goes with edit_pages, its own capability, not the app\'s' );
	$GLOBALS['__caps']['edit_pages'] = true;
	$GLOBALS['__caps']['manage_options'] = true;

	echo "\nGroup 4: the payload at the root\n";
	$p = payload( $app, array() );
	ok( 'Juan & Co' === $p['siteName'], 'siteName is decoded once (never an entity)' );
	ok( array( 'attention', 'notes', 'pages', 'ledger', 'discography', 'citations', 'schedules' ) === array_column( $p['sections'], 'id' ) && array( 0, 3, 2, 1, 2, 0, 0 ) === array_column( $p['sections'], 'count' ), 'the root lists every section with its count -- and the counts are the negative control on the query stub: six post fixtures, three notes and two SIGNED pages, never six of each' );
	ok( null === $p['section'] && array() === $p['items'], 'no section open: no items travel' );
	$notes_tile = $p['sections'][ array_search( 'notes', array_column( $p['sections'], 'id' ), true ) ];
	ok( 'post' === $notes_tile['kind'] && 'dashicons-edit-page' === $notes_tile['icon'], 'a section carries the tile type and icon the client paints with' );
	ok( 'entry' === $p['sections'][0]['kind'] && 'dashicons-flag' === $p['sections'][0]['icon'], 'Attention is the first tile, an ENTRY: no drag, no editor, no dossier, by absence' );
	// NONE of the nine readers exists in this fixture -- no integrity state, no
	// probe log, no citations store, no health scan. That is an install without
	// those halves, and it must produce SILENCE: a warning row per absent
	// subsystem would be permanently present, which is the failure mode a queue
	// exists to fix (inc/watches.php:4-23).
	ok( 0 === $p['sections'][0]['count'], '   ...and it counts ZERO with every reader absent: an uninstalled signal makes no claim, and never a standing warning row' );
	ok( SNT_VERSION === ( $p['version'] ?? '' ), 'the payload carries the plugin version: the LIVE half of the detector, recomputed on every dispatch over a path the service worker does not cache' );

	echo "\nGroup 5: Notes\n";
	$p = payload( $app, array( 'section' => 'notes' ) );
	ok( 'notes' === $p['section']['id'] && 'publish' === $p['section']['defaultStatus'] && 'publish' === $p['section']['statuses'][0]['value'] && true === $p['section']['canEdit'], 'the Notes section declares its pills, its default (Published) and that it has an editor' );
	ok( 'wp/v2/posts' === $p['section']['restPath'], 'the Notes section declares the REST path a dragged-out shortcut carries (the shell trashes and reads through it)' );
	ok( array( 'purge', 'anchor' ) === array_keys( $p['can'] ) && is_bool( $p['can']['purge'] ) && is_bool( $p['can']['anchor'] ), 'the payload carries the app-wide rights the menu greys its rows on: purge and anchor' );
	ok( false === $p['can']['purge'] && false === $p['can']['anchor'], '   ...both false when the Cloudflare and provenance halves are not loaded (a right is never assumed)' );
	ok( array( '21', '11', '12' ) === array_column( $p['items'], 'id' ), 'items travel newest first, every status -- and the fixture array is NOT in date order, so this measures the query\'s orderby and not the literal' );
	ok( true === $p['section']['hasDossier'], 'Notes says it HAS a dossier: the field the client\'s two gates read, carried by the descriptor' );
	$n11 = $p['items'][1];
	ok( array( 'text' => 'v2', 'tone' => 'success', 'title' => 'Anchored' ) === $n11['badge'], 'an anchored note wears v2 in the success tone -- the chain is read through the SUBJECT KIND gate, which still answers `note` for a note' );
	ok( null === $p['items'][0]['badge'] && 'future' === $p['items'][0]['status'] && 'Scheduled' === $p['items'][0]['statusLabel'], 'a scheduled note has no badge (signed on publish) and carries its status for the ribbon and the pills' );
	ok( 'https://img/11.jpg' === $n11['thumbnail'] && '' === $p['items'][2]['thumbnail'] && 'dashicons-edit-page' === $p['items'][2]['icon'], 'thumbnail when there is one; the note icon otherwise' );
	ok( array( '2', 'Anchored' ) === array_values( $n11['columns'] ), 'list-view cells: versions and anchor' );
	ok( array( 'canEdit', 'canDelete', 'canPublish', 'unanchored', 'link' ) === array_values( array_intersect( array( 'canEdit', 'canDelete', 'canPublish', 'unanchored', 'link' ), array_keys( $n11 ) ) ), 'an item carries its own rights and its public link, so the menu greys rows instead of hiding them' );
	ok( true === $n11['canEdit'] && false === $n11['canDelete'] && false === $n11['canPublish'], 'the rights are per-post booleans: edit_post here, delete_post and publish_posts refused' );
	ok( false === $n11['unanchored'] && 'https://example.test/?p=11' === $n11['link'], 'a fully anchored chain reports nothing to dispatch; link is the permalink' );
	ok( in_array( array( 'delete_post', array( 11 ) ), $GLOBALS['__cap_calls'] ?? array(), true ), 'delete_post was asked for THAT note, not an app-wide capability' );
	ok( false === $p['items'][0]['canPublish'], 'a scheduled note without publish_posts cannot be published' );
	$d = $n11['detail'];
	ok( 'table' === $d['blocks'][0]['kind'] && 'v2' === $d['blocks'][0]['rows'][0]['version'] && 'success' === $d['blocks'][0]['rows'][0]['anchor']['tone'] && 'bbbbbbbbbbbb' === $d['blocks'][0]['rows'][0]['hash']['code'] && 'v1' === $d['blocks'][0]['rows'][1]['version'], 'the chain is a table, newest first, with a coded hash and a toned anchor' );
	ok( 'code' === $d['blocks'][1]['kind'] && 'sn:note:11' === $d['blocks'][1]['text'], 'the ledger UID is a code block, for every provenance subject kind and not only for a note' );
	$labels = array_column( $d['actions'], 'label' );
	ok( array( 'Open in editor', 'Verify', 'Re-check now', 'View on site' ) === $labels && 'edit' === $d['actions'][0]['dispatch'] && '11' === $d['actions'][0]['args']['item'], 'actions: the editor first (a dispatch), the verifier and the site (URLs), the re-check between them' );
	ok( in_array( array( 'edit_post', array( 11 ) ), $GLOBALS['__cap_calls'] ?? array(), true ), 'the re-check action is offered on edit_post for THAT note (the capability and the id were asked, not an app-wide cap)' );
	ok( false !== strpos( $d['actions'][1]['url'], '/verify/?note=sn%3Anote%3A11&v=2' ), 'Verify links the public verifier at this uid and version' );
	$d12 = $p['items'][2]['detail'];
	ok( 'text' === $d12['blocks'][0]['kind'] && false !== strpos( $d12['blocks'][0]['text'], 'signed when it is published' ) && array( 'Open in editor' ) === array_column( $d12['actions'], 'label' ), 'a draft says why it has no chain and has no site link' );
	ok( in_array( 'Re-check now', array_column( $d['actions'], 'label' ), true ) && 'verify' === $d['actions'][ array_search( 'Re-check now', array_column( $d['actions'], 'label' ), true ) ]['dispatch'], 'a signed note offers Re-check now as a verify dispatch' );
	ok( ! in_array( 'Re-check now', array_column( $d12['actions'], 'label' ), true ), 'a draft with no chain does not' );
	ok( 'A draft <script>x</script>' === $p['items'][2]['title'], 'titles travel as plain text; the client escapes' );
	$GLOBALS['__caps']['edit_post'] = false;
	$p2 = payload( $app, array( 'section' => 'notes' ) );
	ok( array( 'Verify', 'View on site' ) === array_column( $p2['items'][1]['detail']['actions'], 'label' ), 'without edit_post the editor action is not offered' );
	$GLOBALS['__caps']['edit_post'] = true;

	echo "\nGroup 6: Discography\n";
	$p = payload( $app, array( 'section' => 'discography' ) );
	ok( array() === $p['section']['statuses'] && '' === $p['section']['defaultStatus'] && false === $p['section']['canEdit'], 'no pills, no editor' );
	// hasDossier is a DESCRIPTOR field, and the payload projects it for every
	// section -- so a section that declines it says false, never nothing. The
	// client gates the dossier render and the dossier fetch on this one field;
	// a missing key would be falsy by accident rather than by declaration.
	ok( array_key_exists( 'hasDossier', $p['section'] ) && false === $p['section']['hasDossier'], 'Discography declines the dossier, and SAYS so: hasDossier is present and false, not absent' );
	ok( array( 'Newer', 'Older' ) === array_column( $p['items'], 'title' ), 'newest year first' );
	ok( 'https://img/1.jpg' === $p['items'][1]['thumbnail'] && '2019' === $p['items'][1]['badge']['text'], 'cover art as the tile thumbnail; the year as its badge' );
	ok( 'Tracks' === $p['items'][1]['detail']['blocks'][0]['heading'] && 'One' === $p['items'][1]['detail']['blocks'][0]['rows'][0]['title'] && array( 'Open in Spotify' ) === array_column( $p['items'][1]['detail']['actions'], 'label' ), 'the dossier carries the tracks and only the links the entry has' );
	ok( array() === $p['items'][0]['detail']['blocks'] && array( 'Credits on Muso.AI' ) === array_column( $p['items'][0]['detail']['actions'], 'label' ), 'no tracks: no table' );
	// The section had NO count callable, so payload() built every release --
	// cover art, tracks, dossier -- on every root paint, which on the phone is
	// the first screen. The negative control is an entry album_item() cannot
	// render: counting it is fine, building it is not.
	ok( 2 === \SignalNoise\OpenStationApp\albums_count(), 'Discography counts its entries' );
	$GLOBALS['__albums'][] = array( 'id' => 'poison', 'title' => new \stdClass(), 'year' => 0 );
	ok( 3 === \SignalNoise\OpenStationApp\albums_count(), '   ...WITHOUT building them: an entry no item builder could render is still one release in the count' );
	$built = false;
	try { \SignalNoise\OpenStationApp\albums_items(); } catch ( \Throwable $e ) { $built = true; }
	ok( $built, '   ...and the same fixture makes albums_items() throw, so the count above is measurably not going through the builder' );
	array_pop( $GLOBALS['__albums'] );
	$pd = payload( $app, array() );
	ok( 2 === (int) $pd['sections'][ array_search( 'discography', array_column( $pd['sections'], 'id' ), true ) ]['count'], '   ...and the root tile reads that count' );

	echo "\nGroup 7: a foreign section paints from its items alone\n";
	$p = payload( $app, array( 'section' => 'ledger' ) );
	ok( 'ledger' === $p['section']['id'] && 'ledger' === $p['section']['kind'] && 'L1' === $p['items'][0]['id'], 'the payload carries a third section exactly as it carries the built-ins' );
	ok( '' === $p['section']['emptyHeading'] && '' === $p['section']['emptyNote'], 'a section that declared no empty wording says so with two empty strings -- the client falls back to its own text, and a FOREIGN section cannot inherit another\'s' );
	ok( array_key_exists( 'hasDossier', $p['section'] ) && false === $p['section']['hasDossier'], 'a FOREIGN section that declared no hasDossier reads false, never true by omission: another module cannot get a dossier it did not ask for' );

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

	echo "\nGroup 8a: jump -- a section AND an item, which is what an Attention row dispatches\n";
	$st = new \OpenStation\App\State( $app->state, array( 'section' => 'citations', 'item' => 'c7', 'query' => 'q', 'status' => 'verified', 'selected' => array( '11' ), 'verdict' => array( 'post_id' => 11 ) ) );
	$app->actions['jump']( $st, $os, array( 'section' => 'notes', 'item' => '11' ) );
	ok( 'notes' === $st->get( 'section' ) && '11' === $st->get( 'item' ), 'jump sets BOTH keys: go cannot, because it resets item on purpose' );
	ok( '' === $st->get( 'status' ), '   ...and the status is All, NEVER the section\'s default pill: an unanchored note is often a draft, and Notes defaults to Published, which would filter away the very row the reader tapped' );
	ok( '' === $st->get( 'query' ) && array() === $st->get( 'selected' ) && array() === $st->get( 'verdict' ), '   ...with the search, the selection and the last verdict cleared, as go clears them' );
	$app->actions['jump']( $st, $os, array( 'section' => 'nope', 'item' => '11' ) );
	ok( '' === $st->get( 'section' ) && '' === $st->get( 'item' ), 'an unknown section is the root with nothing open -- never a section id the registry does not know, carrying an item into it' );
	$GLOBALS['__caps']['edit_pages'] = false;
	$app->actions['jump']( $st, $os, array( 'section' => 'pages', 'item' => '31' ) );
	ok( '' === $st->get( 'section' ), 'the capability is re-checked SERVER-side: the registry resolves per call, so a jump into a section this user is not offered lands at the root' );
	$GLOBALS['__caps']['edit_pages'] = true;

	echo "\nGroup 8b: the verify action and the verdict in data\n";
	$st = new \OpenStation\App\State( $app->state, array( 'section' => 'notes', 'item' => '11' ) );
	$os = new \OpenStation\App\Os();
	$GLOBALS['__can_calls'] = array();
	$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
	ok( 11 === $st->get( 'verdict' )['post_id'] && 'success' === $st->get( 'verdict' )['tone'], 'verify stores the verdict in the declared state slot' );
	ok( array( 'edit_post', array( 11 ) ) === end( $GLOBALS['__can_calls'] ), 'the server action asked the runtime for edit_post on THAT note, not an app-wide capability' );
	$p = ( $app->data )( $st, $os );
	ok( 11 === $p['verdict']['post_id'] && 'v1 holds.' === $p['verdict']['text'], 'the payload projects the verdict into data' );
	$GLOBALS['__os_can'] = false;
	$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
	ok( array() === $st->get( 'verdict' ), 'without edit_post on THAT note the verdict is cleared, and nothing is fetched' );
	$GLOBALS['__os_can'] = true;
	$app->actions['verify']( $st, $os, array( 'item' => '11' ) );
	$app->actions['go']( $st, $os, array( 'section' => 'notes' ) );
	ok( array() === $st->get( 'verdict' ), 'go clears the verdict' );

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
