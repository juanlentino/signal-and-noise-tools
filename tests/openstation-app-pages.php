<?php
/**
 * Standalone test: the Signal & Noise app's PAGES section (#1068).
 *
 * Pages and Notes are one surface over two post types, built by the shared
 * parts/post-items.php. This suite drives the half that is NOT Notes, and
 * every pin here exists because the two types differ somewhere:
 *
 *   - the query: `post_type page` plus the signing opt-in meta, never the
 *     `category_name` a page has no taxonomy for;
 *   - the author scope on BOTH queries, in its TWO halves: `'perm' =>
 *     'readable'` narrows PRIVATE posts, and a `posts_where` clause narrows
 *     draft, pending and scheduled, which `readable` leaves alone;
 *   - the publish capability, read from the post type object: `publish_posts`
 *     is the POST cap and would have silently over-granted for a page;
 *   - the provenance gate, which is now the SUBJECT KIND -- so an opted-in
 *     page paints its chain and an unsigned one is refused even if a chain
 *     exists;
 *   - the public verifier, which takes `?note=` and is offered for a note only.
 *
 * THE QUERY STUB IS ARG-AWARE. The suite that shipped before this one used a
 * WP_Query stub that ignored its args entirely, so a Pages section could have
 * painted the three Notes fixtures and gone green while filtering nothing.
 * Every items pin below is negative-controlled against the note fixture.
 * Run: php tests/openstation-app-pages.php
 *
 * @since 13.102.0
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
		public function can( $c, ...$a ) { $GLOBALS['__can_calls'][] = array( $c, $a ); return (bool) ( $GLOBALS['__caps'][ $c ] ?? false ); }
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
	// A REAL remove_filter, because the author scope is a filter added around
	// one query and removed after it: a stub that forgets would let the clause
	// leak onto the next section's query and the suite would never notice.
	function remove_filter( $hook, $cb, $prio = 10 ) {
		foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $i => $one ) { if ( $one === $cb ) { unset( $GLOBALS['__filters'][ $hook ][ $i ] ); } }
		return true;
	}
	function apply_filters( $hook, $value, ...$rest ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$rest ); } return $value; }
	function get_current_user_id() { return (int) ( $GLOBALS['__uid'] ?? 5 ); }
	// The clause names the posts table off $wpdb, so the branch that reads it runs.
	$wpdb = (object) array( 'posts' => 'wp_posts' );
	function __( $s, $d = null ) { return $s; }
	function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
	function get_bloginfo( $k ) { return 'Juan'; }
	function home_url( $p = '' ) { return 'https://example.test' . $p; }
	function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	// An Editor: both edit caps and publish_pages, but NOT publish_posts --
	// so a page asking the POST capability would read as refused, and a page
	// asking its own would read as allowed. The two are separable here.
	$GLOBALS['__caps'] = array(
		'edit_posts'     => true,
		'edit_pages'     => true,
		'edit_post'      => true,
		'delete_post'    => true,
		'publish_pages'  => true,
		'publish_posts'  => false,
		'manage_options' => false,
	);
	$GLOBALS['__cap_calls'] = array();
	function current_user_can( $cap, ...$a ) { $GLOBALS['__cap_calls'][] = array( $cap, $a ); return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false ); }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__meta'][ $id ][ $key ] ?? ''; }
	function get_post( $id ) { foreach ( $GLOBALS['__posts'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
	function get_post_status_object( $s ) { return (object) array( 'label' => array( 'publish' => 'Published', 'future' => 'Scheduled', 'draft' => 'Draft' )[ $s ] ?? ucfirst( $s ) ); }
	function get_the_date( $f, $post ) { return substr( $post->post_date, 0, 10 ); }
	function get_post_time( $f, $gmt, $post ) { return str_replace( ' ', 'T', $post->post_date ) . '+00:00'; }
	function get_the_post_thumbnail_url( $post, $size ) { return $post->thumb ?? ''; }
	function get_permalink( $post ) { return 'https://example.test/?p=' . $post->ID; }
	function get_edit_post_link( $id, $ctx = '' ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
	// Both per-type capabilities WordPress maps on the object: `publish_posts`
	// and `edit_others_posts` are the KEYS, and their VALUES are the page's own
	// names. Reading the key as the capability is the over-grant this suite
	// exists to catch.
	function get_post_type_object( $post_type ) {
		$caps = array(
			'post' => array( 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ),
			'page' => array( 'publish_posts' => 'publish_pages', 'edit_others_posts' => 'edit_others_pages' ),
		);
		return isset( $caps[ (string) $post_type ] )
			? (object) array( 'name' => (string) $post_type, 'cap' => (object) $caps[ (string) $post_type ] )
			: null;
	}
	// The one gate: a post in the Notes category is a note, a page that opted
	// in is a page, and anything else is not a provenance subject at all.
	function sn_prov_subject_kind( $post ) {
		$type = (string) ( $post->post_type ?? '' );
		if ( 'post' === $type ) { return 'note'; }
		if ( 'page' === $type ) { return '' !== (string) ( $GLOBALS['__meta'][ (int) $post->ID ]['_sn_prov_sign'] ?? '' ) ? 'page' : ''; }
		return '';
	}
	function sn_prov_get_chain( $id ) { return $GLOBALS['__chains'][ $id ] ?? array(); }
	function sn_discography_get() { return array( 'entries' => array() ); }

	// ARG-AWARE, AND FILTER-AWARE. The real WP_Query orders, bounds, and runs
	// the `posts_where` clauses somebody attached; a stub that does none of
	// those three cannot fail on a section that forgot any of them. So this one
	// honours `orderby` (date DESC, then ID DESC), honours `posts_per_page`,
	// and ASKS for a posts_where clause the way core does -- then obeys it: a
	// clause naming `post_author = N` drops every NON-publish row belonging to
	// somebody else, which is exactly the SQL the section appends.
	class WP_Query {
		public $posts = array(); public $found_posts = 0;
		public function __construct( array $args ) {
			$GLOBALS['__queries'][] = $args;
			$types = array_map( 'strval', (array) ( $args['post_type'] ?? 'post' ) );
			$where = (string) apply_filters( 'posts_where', '', $this );
			$GLOBALS['__wheres'][] = $where;
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
			// found_posts is the total BEFORE the bound, the way core reports it.
			$this->found_posts = count( $found );
			$per               = (int) ( $args['posts_per_page'] ?? -1 );
			if ( $per > 0 ) { $found = array_slice( $found, 0, $per ); }
			$this->posts = 'ids' === ( $args['fields'] ?? '' ) ? array() : $found;
		}
	}

	function page_row( $id, $title, $status = 'publish', $date = '2026-07-01 09:00:00', $author = 5 ) { return (object) array( 'ID' => $id, 'post_title' => $title, 'post_status' => $status, 'post_date' => $date, 'post_type' => 'page', 'thumb' => '', 'post_author' => $author ); }

	$GLOBALS['__queries'] = array();
	$GLOBALS['__wheres']  = array();
	// DELIBERATELY NOT IN DATE ORDER. Fixture order and date order disagree, so
	// "newest first" is a property of the query and not of this array literal.
	// Author 5 is the reader; author 9 is somebody else.
	$GLOBALS['__posts']   = array(
		page_row( 32, 'Colophon', 'draft', '2026-06-02 09:00:00' ),
		// Another author's PUBLISHED page: visible to everyone. The whole reason
		// `'perm' => 'editable'` was not the fix -- it would have hidden this.
		page_row( 34, 'Field notes', 'publish', '2026-05-01 09:00:00', 9 ),
		// The note the Pages query must NOT return.
		(object) array( 'ID' => 11, 'post_title' => 'The signer keeps moving', 'post_status' => 'publish', 'post_date' => '2026-08-14 10:00:00', 'post_type' => 'post', 'thumb' => '', 'post_author' => 5 ),
		page_row( 31, 'Start here' ),
		// Another author's DRAFT page: the unpublished half this scope narrows.
		page_row( 35, 'A neighbour draft', 'draft', '2026-08-01 09:00:00', 9 ),
		// Opted OUT, and it has a chain anyway: a page that once carried one
		// must still be refused, or "signed" would mean "has a row somewhere".
		page_row( 33, 'Stats' ),
		// Another author's DRAFT note, for the same pair on the Notes query.
		(object) array( 'ID' => 13, 'post_title' => 'A neighbour note', 'post_status' => 'draft', 'post_date' => '2026-08-02 10:00:00', 'post_type' => 'post', 'thumb' => '', 'post_author' => 9 ),
	);
	$GLOBALS['__meta']   = array(
		11 => array( '_sn_prov_uid' => 'sn:note:11' ),
		31 => array( '_sn_prov_sign' => '1', '_sn_prov_uid' => 'sn:page:31' ),
		32 => array( '_sn_prov_sign' => '1' ),
		33 => array( '_sn_prov_uid' => 'sn:page:33' ),
		34 => array( '_sn_prov_sign' => '1' ),
		35 => array( '_sn_prov_sign' => '1' ),
	);
	$GLOBALS['__chains'] = array(
		11 => array( array( 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-08-14T10:00:00Z', 'content_hash' => 'cccccccccccccccccccccccc' ) ),
		31 => array(
			array( 'version' => 1, 'status' => 'genesis', 'committed_at' => '2026-06-01T10:00:00Z', 'content_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaa' ),
			array( 'version' => 2, 'status' => 'confirmed', 'committed_at' => '2026-07-01T09:00:00Z', 'content_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbb' ),
		),
		33 => array( array( 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-05-01T10:00:00Z', 'content_hash' => 'dddddddddddddddddddddddd' ) ),
	);

	require_once __DIR__ . '/../inc/openstation-app.php';
	$app = require __DIR__ . '/../apps/signal-noise/signal-noise.os.php';

	$pass = 0; $fail = 0;
	function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
	function payload( $app, array $state ) { return ( $app->data )( new \OpenStation\App\State( $app->state, $state ), new \OpenStation\App\Os() ); }
	/** The recorded query whose post_type is $type, or null. */
	function query_for( $type ) { foreach ( $GLOBALS['__queries'] as $q ) { if ( $type === (string) ( $q['post_type'] ?? '' ) ) { return $q; } } return null; }

	echo "openstation-app -- the Pages section (#1068)\n\nGroup 1: the descriptor\n";
	$sections = snt_os_app_sections();
	$pages    = null;
	foreach ( $sections as $s ) { if ( 'pages' === $s['id'] ) { $pages = $s; } }
	ok( is_array( $pages ), 'the Pages section registers through the same filter as every other' );
	ok( 'Pages' === $pages['label'] && 'dashicons-admin-page' === $pages['icon'] && 12 === $pages['position'], 'label, icon and position 12 -- between Notes (10) and Discography (20)' );
	ok( 'post' === $pages['kind'] && 'wp/v2/pages' === $pages['restPath'], 'kind post with a restPath: the control surface and the drag-out are gated on exactly that pair' );
	ok( 'page' === $pages['post_type'], 'the descriptor names its post type, and the four server actions read it from here' );
	ok( 'edit_pages' === $pages['capability'], 'the section is gated on edit_pages, not on the app\'s edit_posts' );
	ok( true === $pages['hasDossier'], 'the descriptor says it HAS a dossier; the client no longer asks for a section id' );
	ok( 'publish' === $pages['default_status'] && array( 'publish', 'future', 'draft', 'pending', 'private' ) === array_column( $pages['statuses'], 'value' ), 'the five status pills, defaulting to Published' );
	ok( array( 'versions', 'anchor' ) === array_column( $pages['columns'], 'key' ), 'the same two list columns Notes carries: they read the same two facts off the chain' );
	ok( is_callable( $pages['count'] ) && is_callable( $pages['items'] ) && is_callable( $pages['edit_url'] ), 'count, items and edit_url are all callable -- a descriptor without a callable items() is silently dropped' );

	echo "\nGroup 2: the query -- the opt-in meta, readable, and NOT a category\n";
	$GLOBALS['__queries'] = array();
	$p = payload( $app, array( 'section' => 'pages' ) );
	$q = query_for( 'page' );
	ok( is_array( $q ) && 'page' === $q['post_type'], 'the section queries post_type page' );
	ok( array( 'publish', 'future', 'draft', 'pending', 'private' ) === $q['post_status'], 'every editable status, as Notes lists them' );
	ok( '_sn_prov_sign' === $q['meta_key'] && '1' === $q['meta_value'], 'the predicate is the signing opt-in meta: a page is in this section because its author opted it in' );
	ok( ! isset( $q['category_name'] ), 'no category_name: `category` is a POST-ONLY taxonomy, so asking for one would have returned nothing forever' );
	ok( 'readable' === $q['perm'], 'perm readable narrows PRIVATE posts to what this user may read; the author clause narrows the rest -- two mechanisms, because WordPress has two' );
	$qn = query_for( 'post' );
	ok( is_array( $qn ) && 'readable' === $qn['perm'], 'the NOTES query carries perm readable too: the same reasoning, the same fix, both sections' );
	ok( isset( $qn['category_name'] ) && ! isset( $qn['meta_key'] ), '   ...and it still filters by category, never by the sign meta' );
	ok( array( 'date' => 'DESC', 'ID' => 'DESC' ) === $q['orderby'] && (int) $q['posts_per_page'] > 1, 'the items query orders newest first with ID as the tie-break, and carries the item bound' );

	echo "\nGroup 3: the items -- the negative control first\n";
	$ids = array_column( $p['items'], 'id' );
	ok( ! in_array( '11', $ids, true ), 'NEGATIVE CONTROL: the note fixture does not come back for the pages query (the old stub ignored its args and would have shipped it)' );
	ok( array( '31', '32', '34' ) === $ids, 'the opted-in pages this reader may see, newest first -- and the fixture array is NOT in date order, so this is the query ordering and not the literal' );
	ok( ! in_array( '33', $ids, true ), '   ...even though page 33 has a chain in the ledger. Opting in is the predicate, not having a row.' );
	ok( 3 === (int) $p['sections'][ array_search( 'pages', array_column( $p['sections'], 'id' ), true ) ]['count'], 'the root folder tile counts three, from a count query -- not from building every item' );

	$item = $p['items'][0];
	ok(
		array( 'id', 'title', 'subtitle', 'thumbnail', 'icon', 'status', 'statusLabel', 'date', 'dateLabel', 'badge', 'canEdit', 'canDelete', 'canPublish', 'unanchored', 'link', 'columns', 'detail' ) === array_keys( $item ),
		'a page item carries EVERY key a note item carries, in the same order: one builder, so the client cannot tell the two sections apart'
	);
	ok( 'dashicons-admin-page' === $item['icon'], 'the page icon, not the note\'s dashicons-edit-page' );
	ok( 'Start here' === $item['title'] && 'Published' === $item['statusLabel'] && 'https://example.test/?p=31' === $item['link'], 'title, status label and the public link' );

	echo "\nGroup 3b: the unpublished half belongs to its author\n";
	// `perm => readable` narrows PRIVATE posts and nothing else -- core puts
	// only `private` on that path -- so draft, pending and scheduled were
	// EVERYONE'S through this window until v13.102.1. The fix is a posts_where
	// clause, and these pins measure the clause's two edges: another author's
	// draft is gone, another author's PUBLISHED page is not.
	$clause = (string) end( $GLOBALS['__wheres'] );
	ok( false !== strpos( $clause, "wp_posts.post_status = 'publish' OR wp_posts.post_author = 5" ), 'a reader without edit_others_pages queries with an author clause, naming the posts table and their OWN id' );
	ok( ! in_array( '35', $ids, true ), 'another author\'s DRAFT page is not listed: the unpublished half is scoped to its author' );
	ok( in_array( '34', $ids, true ), '   ...while another author\'s PUBLISHED page IS listed. This is the whole reason perm => editable was not the fix: it would have hidden this row too.' );
	ok( 1 === (int) $p['sections'][ array_search( 'notes', array_column( $p['sections'], 'id' ), true ) ]['count'], 'the NOTES count is scoped the same way: another author\'s draft note is not counted into this reader\'s folder tile' );
	ok( array() === array_values( (array) ( $GLOBALS['__filters']['posts_where'] ?? array() ) ), 'the clause is added AROUND the query and removed after it: nothing is left on posts_where for the next query in the request' );

	$GLOBALS['__caps']['edit_others_pages'] = true;
	$GLOBALS['__caps']['edit_others_posts'] = true;
	$GLOBALS['__wheres'] = array();
	$pe  = payload( $app, array( 'section' => 'pages' ) );
	$eids = array_column( $pe['items'], 'id' );
	ok( array( '35', '31', '32', '34' ) === $eids, 'an EDITOR -- edit_others_pages held -- sees the other author\'s draft too, newest first: the scope is the capability, never a hard-coded author' );
	ok( '' === (string) end( $GLOBALS['__wheres'] ), '   ...and no clause is attached at all for such a reader: the filter is not added, not added-and-neutralised' );
	ok( 2 === (int) $pe['sections'][ array_search( 'notes', array_column( $pe['sections'], 'id' ), true ) ]['count'], '   ...and the Notes count widens with edit_others_posts, its OWN per-type capability' );
	unset( $GLOBALS['__caps']['edit_others_pages'], $GLOBALS['__caps']['edit_others_posts'] );

	echo "\nGroup 4: provenance -- the gate is the SUBJECT KIND\n";
	ok( array( 'text' => 'v2', 'tone' => 'success', 'title' => 'Anchored' ) === $item['badge'], 'a signed page wears its version badge: snt_os_app_note_provenance() answers for every subject kind, not only for a note' );
	ok( array( '2', 'Anchored' ) === array_values( $item['columns'] ), 'the versions and anchor columns are the page\'s own' );
	$d = $item['detail'];
	ok( 'table' === $d['blocks'][0]['kind'] && 'v2' === $d['blocks'][0]['rows'][0]['version'] && 'v1' === $d['blocks'][0]['rows'][1]['version'], 'the signed chain is a table, newest first' );
	ok( 'code' === $d['blocks'][1]['kind'] && 'sn:page:31' === $d['blocks'][1]['text'], 'the ledger UID is a code block, read from the page\'s own meta' );
	ok( null === snt_os_app_note_provenance( 33 ), 'a page that never opted in is REFUSED even though a chain exists for it -- the gate asks the subject kind, never "is there a row?"' );
	ok( is_array( snt_os_app_note_provenance( 31 ) ) && is_array( snt_os_app_note_provenance( 11 ) ), '   ...while both a signed page and a note answer, so the refusal above is a gate and not a broken reader' );
	$d32 = $p['items'][1]['detail'];
	ok( null === $p['items'][1]['badge'] && 'text' === $d32['blocks'][0]['kind'] && 'A page is signed when it is published.' === $d32['blocks'][0]['text'], 'an opted-in DRAFT page has no chain yet and says so in its own type\'s words -- never "a note"' );

	echo "\nGroup 5: the verifier is for notes\n";
	$labels = array_column( $d['actions'], 'label' );
	ok( ! in_array( 'Verify', $labels, true ), 'no Verify action for a page: the public verifier takes ?note=<uid> and the sibling theme owns whether a page record is reachable there' );
	ok( in_array( 'Re-check now', $labels, true ), '   ...and the UID branch DID run -- Re-check now is offered -- so the absence above is a decision, not a chain that failed to load' );
	ok( array( 'Open in editor', 'Re-check now', 'View on site' ) === $labels, 'the page\'s actions: the editor, the re-check, the site' );

	echo "\nGroup 6: the publish capability comes from the type object\n";
	ok( 'publish_pages' === \SignalNoise\OpenStationApp\post_publish_cap( 'page' ) && 'publish_posts' === \SignalNoise\OpenStationApp\post_publish_cap( 'post' ), 'each type answers with its own publish capability' );
	ok( '' === \SignalNoise\OpenStationApp\post_publish_cap( 'nope' ), 'an unregistered type answers nothing, and an unknown right is a refusal' );
	ok( true === $p['items'][1]['canPublish'], 'the draft page may be published: publish_pages is held' );
	ok( in_array( array( 'publish_pages', array() ), $GLOBALS['__cap_calls'], true ), '   ...and publish_pages is the capability that was actually asked' );
	ok( ! in_array( array( 'publish_posts', array() ), $GLOBALS['__cap_calls'], true ), '   ...while publish_posts -- the POST cap, held by nobody here -- was never asked for a page. It would have over-granted for an Author.' );
	ok( false === $item['canPublish'], 'a published page is not publishable, whatever the capability says' );

	echo "\nGroup 7: the section owns its post type\n";
	ok( false !== strpos( \SignalNoise\OpenStationApp\pages_edit_url( '31' ), 'post.php?post=31' ), 'the editor URL for one of this section\'s pages' );
	ok( '' === \SignalNoise\OpenStationApp\pages_edit_url( '11' ), 'a NOTE id asked of the Pages section opens nothing: the section owns the type, not the client' );
	ok( '' === \SignalNoise\OpenStationApp\notes_edit_url( '31' ), '   ...and the reverse, so neither section is a door into the other' );
	$os = new \OpenStation\App\Os();
	$st = new \OpenStation\App\State( $app->state, array( 'section' => 'pages' ) );
	ok( 'page' === \SignalNoise\OpenStationApp\section_post_type( $st ), 'the actions read the post type from the OPEN SECTION\'s descriptor' );
	ok( 'post' === \SignalNoise\OpenStationApp\section_post_type( new \OpenStation\App\State( $app->state, array( 'section' => 'notes' ) ) ), '   ...post for Notes' );
	ok( 'post' === \SignalNoise\OpenStationApp\section_post_type( new \OpenStation\App\State( $app->state, array( 'section' => 'nope' ) ) ), '   ...and post -- the narrower of the two -- when no section resolves: a default that refuses, never one that grants' );
	ok( true === \SignalNoise\OpenStationApp\note_allowed( $os, 31, 'edit', 'page' ), 'a page is editable under the Pages section' );
	ok( false === \SignalNoise\OpenStationApp\note_allowed( $os, 31, 'edit', 'post' ), '   ...and refused under the Notes section: an id outside the open section\'s type is not one of its items' );
	ok( true === \SignalNoise\OpenStationApp\note_allowed( $os, 32, 'publish', 'page' ) && false === \SignalNoise\OpenStationApp\note_allowed( $os, 11, 'publish', 'post' ), 'publish asks each type\'s own capability: publish_pages holds here, publish_posts does not' );

	echo "\nGroup 8: the payload's section descriptor reaches the client\n";
	ok( 'pages' === $p['section']['id'] && 'post' === $p['section']['kind'] && 'wp/v2/pages' === $p['section']['restPath'], 'the open section carries its id, kind and REST path' );
	ok( true === $p['section']['hasDossier'], 'hasDossier travels: this is the field the client\'s two dossier gates read' );
	$pn = payload( $app, array( 'section' => 'notes' ) );
	ok( true === $pn['section']['hasDossier'], 'Notes says so too -- phase one and two behaviour is unchanged' );
	$pd = payload( $app, array() );
	ok( null === $pd['section'], 'no section open: no descriptor travels' );

	echo "\nResult: $pass passed, $fail failed.\n";
	exit( $fail > 0 ? 1 : 0 );
}
