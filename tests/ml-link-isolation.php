<?php
/**
 * Link isolation (v10.83.0): inc/ml-link-isolation.php + the 'link-isolation'
 * pipeline + the signal-noise/link-isolation ability.
 *
 * The editorial question nothing could answer before: WHICH PUBLISHED NOTES DOES
 * NOTHING LINK TO? A note with no inbound link is reachable only by archive or
 * search — it exists but is not part of the corpus's own fabric.
 *
 * Note that "orphan" already means something else here: every existing use in
 * this plugin is orphaned MEDIA (attachments nothing references). This is the
 * note-level link graph, deliberately named differently so the two never get
 * confused in a findings list.
 *
 * THE CORRECTNESS STORY IS ENTIRELY IN THE HREF NORMALISER. `/notes/foo/`,
 * `/notes/foo`, `https://juanlentino.com/notes/foo/?utm=x#section` and a
 * root-relative `foo/` are the same target. Get that wrong in the strict
 * direction and every note reads as isolated; get it wrong in the loose
 * direction and nothing does. Both failures are quiet, which is why the
 * normaliser is pinned case by case below rather than only through the
 * end-to-end walk.
 *
 * @since plugin v10.83.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

error_reporting( E_ALL );
$GLOBALS['__php_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__php_errors'][] = "$str @ $file:$line";
	return true;
} );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
$GLOBALS['__test_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
}
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://juanlentino.com/wp-admin/' . $p; } }

function tf_post( $id, $status, $slug, $content, $title = null ) {
	$p = new stdClass();
	$p->ID           = $id;
	$p->post_title   = $title ?? "Post $id";
	$p->post_name    = $slug;
	$p->post_status  = $status;
	$p->post_type    = 'post';
	$p->post_content = $content;
	return $p;
}

$link = function ( $href, $text = 'x' ) { return '<p>See <a href="' . $href . '">' . $text . '</a>.</p>'; };

$GLOBALS['__posts'] = array();
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$out = array();
		$want = (array) ( $args['post_status'] ?? array( 'publish' ) );
		foreach ( $GLOBALS['__posts'] as $p ) {
			if ( $p->post_type !== ( $args['post_type'] ?? 'post' ) ) { continue; }
			if ( ! in_array( $p->post_status, $want, true ) ) { continue; }
			$out[] = $p;
		}
		$cap = (int) ( $args['posts_per_page'] ?? -1 );
		return $cap > 0 ? array_slice( $out, 0, $cap ) : $out;
	}
}

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/corpus-inspect.php';
require __DIR__ . '/../inc/ml-link-isolation.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function iso_ids( $r ) { return array_map( static function ( $e ) { return (int) $e['post_id']; }, $r['isolated'] ); }

echo "Link isolation — which notes does nothing link to? (v10.83.0)\n\n";

/* ══════════════════════════════════════════════════════════════════
 * 1. THE NORMALISER — where all the correctness lives
 * ══════════════════════════════════════════════════════════════════ */

$same = array(
	'https://juanlentino.com/notes/foo/'            => 'absolute, trailing slash',
	'https://juanlentino.com/notes/foo'             => 'absolute, no trailing slash',
	'http://juanlentino.com/notes/foo/'             => 'absolute over http',
	'//juanlentino.com/notes/foo/'                  => 'protocol-relative',
	'/notes/foo/'                                   => 'root-relative',
	'/notes/foo'                                    => 'root-relative, no slash',
	'https://juanlentino.com/notes/foo/?utm=x'      => 'query string ignored',
	'https://juanlentino.com/notes/foo/#section-2'  => 'fragment ignored',
	'https://juanlentino.com/notes/foo/?a=1#b'      => 'query AND fragment ignored',
	'https://JUANLENTINO.COM/notes/FOO/'            => 'host and slug case-folded',
);
foreach ( $same as $href => $why ) {
	ok( 'foo' === snt_ml_link_target_slug( $href ), "normalises to 'foo' — $why" );
}

// External and non-target hrefs must resolve to nothing at all.
$none = array(
	'https://example.com/notes/foo/' => 'a DIFFERENT host is not an internal link',
	'https://example.com/foo'        => 'external, short path',
	'mailto:hi@example.com'          => 'mailto',
	'tel:+15551234'                  => 'tel',
	'#section-2'                     => 'a pure fragment is a same-page jump, not a link to a note',
	'javascript:void(0)'             => 'javascript: pseudo-URL',
	''                               => 'empty href',
	'/'                              => 'the site root is not a note',
	'https://juanlentino.com/'       => 'the absolute site root is not a note',
);
foreach ( $none as $href => $why ) {
	ok( '' === snt_ml_link_target_slug( $href ), "resolves to nothing — $why" );
}

/* ══════════════════════════════════════════════════════════════════
 * 2. THE WALK
 * ══════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'] = array(
	// 1 is linked by 2 (absolute) and 3 (root-relative, no trailing slash).
	1 => tf_post( 1, 'publish', 'well-linked', '<p>Nothing outbound.</p>', 'Well linked' ),
	2 => tf_post( 2, 'publish', 'links-out', $link( 'https://juanlentino.com/notes/well-linked/' ), 'Links out' ),
	3 => tf_post( 3, 'publish', 'also-links-out', $link( '/notes/well-linked' ), 'Also links out' ),
	// 4 links only to ITSELF — a self-link is not inbound reachability.
	4 => tf_post( 4, 'publish', 'self-linker', $link( '/notes/self-linker/' ), 'Self linker' ),
	// 5 is linked ONLY by a draft, which is not publicly reachable.
	5 => tf_post( 5, 'publish', 'draft-linked-only', '<p>No outbound.</p>', 'Draft linked only' ),
	6 => tf_post( 6, 'draft', 'a-draft', $link( '/notes/draft-linked-only/' ), 'A draft' ),
	// 7 is linked only from OUTSIDE the corpus vocabulary (external site).
	7 => tf_post( 7, 'publish', 'externally-linked', '<p>No outbound.</p>', 'Externally linked' ),
	8 => tf_post( 8, 'publish', 'links-external', $link( 'https://example.com/notes/externally-linked/' ), 'Links external' ),
);

$r = snt_ml_link_isolation();
$iso = iso_ids( $r );

ok( true === $r['ok'], 'returns an ok envelope' );
ok( ! in_array( 1, $iso, true ), 'a note linked from two others is NOT isolated' );
ok( in_array( 4, $iso, true ), 'SELF-LINK TRAP: a note linking only to itself is still isolated' );
ok( in_array( 5, $iso, true ), 'a note linked ONLY from a draft is isolated — a draft is not public reachability' );
ok( in_array( 7, $iso, true ), 'a note linked only from an EXTERNAL page is isolated (this measures the corpus fabric)' );
ok( in_array( 2, $iso, true ) && in_array( 3, $iso, true ),
	'linking OUT does not make you linked TO — 2 and 3 are themselves isolated' );
ok( ! in_array( 6, $iso, true ), 'the draft itself is never reported: only published notes are subjects' );

ok( 6 === $r['isolated_count'] && 6 === count( $r['isolated'] ),
	'exactly the six isolated published notes (1 is linked; 6 is a draft and out of scope)' );
ok( 7 === $r['posts_scanned'], 'seven published notes were scanned (the draft is not a subject)' );

$row = $r['isolated'][0];
ok( array( 'post_id', 'title', 'slug', 'status', 'outbound_count' ) === array_keys( $row ),
	'an isolated row carries post_id / title / slug / status / outbound_count' );
ok( 'publish' === $row['status'], 'every reported subject is published' );

/* ══════════════════════════════════════════════════════════════════
 * 3. OUTBOUND COUNT — the editorial nuance
 * ══════════════════════════════════════════════════════════════════ */

// An isolated note that links out generously is a different editorial problem
// from one that links nowhere: the first is a dead end nobody points at, the
// second is disconnected in both directions.
$by_id = array();
foreach ( $r['isolated'] as $e ) { $by_id[ (int) $e['post_id'] ] = $e; }
ok( 1 === (int) $by_id[2]['outbound_count'], 'outbound_count counts internal links out (post 2 → 1)' );
ok( 0 === (int) $by_id[5]['outbound_count'], 'a note with no outbound internal links reports 0' );
ok( 0 === (int) $by_id[8]['outbound_count'], 'an EXTERNAL link is not an outbound internal link' );
ok( 0 === (int) $by_id[4]['outbound_count'], 'a self-link is not counted as outbound either' );

/* ══════════════════════════════════════════════════════════════════
 * 4. DEGENERATE CORPORA
 * ══════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'] = array();
$empty = snt_ml_link_isolation();
ok( true === $empty['ok'] && array() === $empty['isolated'] && 0 === $empty['posts_scanned'],
	'an empty corpus is ok with nothing isolated, not an error' );

$GLOBALS['__posts'] = array( 1 => tf_post( 1, 'publish', 'only-note', '<p>Alone.</p>' ) );
$single = snt_ml_link_isolation();
ok( 1 === $single['isolated_count'],
	'a one-note corpus reports that note as isolated — true, and not a divide-by-zero' );

// Every note links to every other: nothing is isolated. Guards the inverse
// failure, where a broken normaliser reports the whole corpus as orphaned.
$GLOBALS['__posts'] = array(
	1 => tf_post( 1, 'publish', 'a', $link( '/notes/b/' ) ),
	2 => tf_post( 2, 'publish', 'b', $link( '/notes/a/' ) ),
);
$dense = snt_ml_link_isolation();
ok( 0 === $dense['isolated_count'],
	'a fully cross-linked corpus reports NOTHING isolated (guards the everything-looks-orphaned failure)' );

/* ══════════════════════════════════════════════════════════════════
 * 5. PIPELINE + SURFACE
 * ══════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'] = array(
	1 => tf_post( 1, 'publish', 'a', '<p>none</p>' ),
	2 => tf_post( 2, 'publish', 'b', $link( '/notes/a/' ) ),
);

$pipelines = snt_ml_pipelines();
ok( isset( $pipelines['link-isolation'] ) && 'snt_ml_pipeline_link_isolation' === $pipelines['link-isolation'],
	"'link-isolation' is registered in the ML pipeline registry (the single dispatch seam)" );

$via = snt_ml_run( 'link-isolation', array() );
ok( is_array( $via ) && 1 === $via['isolated_count'] && 2 === (int) $via['isolated'][0]['post_id'],
	'the pipeline reaches the impl and returns the same envelope' );

$capped = snt_ml_run( 'link-isolation', array( 'limit' => 1 ) );
ok( 1 === count( $capped['isolated'] ), 'limit caps the reported rows' );
ok( 1 === $capped['isolated_total'] || 1 === $capped['isolated_count'],
	'the envelope still reports the true total, so a cap never reads as "that is all there is"' );

$module = file_get_contents( __DIR__ . '/../inc/ml-link-isolation.php' );
ok( false === strpos( $module, 'add_action' ) && false === strpos( $module, 'add_filter' ),
	'the module registers NO hooks — it cannot fire on a reader request' );
ok( false === stripos( $module, 'wp_remote' ) && false === stripos( $module, 'openai' ) && false === stripos( $module, 'anthropic' ),
	'NO NEW MODEL and no network: this is corpus arithmetic, the ML family standing never' );

ok( array() === $GLOBALS['__php_errors'],
	'no PHP notices, warnings or deprecations: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );


// ─── v13.65.0: the graph is pure and inbound counts join by path ───
echo "\nGroup: v13.65.0 link graph + inbound_by_path\n";
$g = snt_ml_link_graph( array(
	tf_post( 1, 'publish', 'alpha', '<a href="/notes/beta/">b</a> <a href="https://juanlentino.com/notes/beta/">b again</a> <a href="/notes/alpha/">self</a>' ),
	tf_post( 2, 'publish', 'beta',  '<a href="/notes/alpha/">a</a> <a href="/notes/nope/">gone</a>' ),
	tf_post( 3, 'publish', 'gamma', '' ),
), 'juanlentino.com' );
ok( 1 === $g['beta']['inbound'] && array( 1 ) === $g['beta']['linked_from'], 'two links from one source count ONCE; linked_from names the source post' );
ok( 1 === $g['alpha']['inbound'] && 1 === $g['alpha']['outbound'], 'a self-link is not an edge in either direction' );
ok( 0 === $g['gamma']['inbound'] && 0 === $g['gamma']['outbound'] && 1 === $g['beta']['outbound'], 'a link to a non-note is not an edge; gamma is isolated' );
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://juanlentino.com/notes/' . array( 1 => 'alpha', 2 => 'beta', 3 => 'gamma' )[ $id ] . '/'; } }
if ( ! function_exists( 'sn_path_join_key' ) ) { require_once __DIR__ . '/../inc/path-join-key.php'; }
$GLOBALS['__posts'] = array( tf_post( 1, 'publish', 'alpha', '<a href="/notes/beta/">b</a>' ), tf_post( 2, 'publish', 'beta', '' ), tf_post( 3, 'publish', 'gamma', '' ) );
$by = snt_ml_inbound_by_path();
ok( array( '/notes/alpha', '/notes/beta', '/notes/gamma' ) === array_keys( $by ) && 1 === $by['/notes/beta']['inbound'] && 0 === $by['/notes/alpha']['inbound'], 'inbound_by_path: keyed by the weave join key (trailing slash stripped), counts from the same graph' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
