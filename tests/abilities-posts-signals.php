<?php
/**
 * Standalone test: signal-noise/posts-signals (inc/abilities-posts-signals.php).
 *
 * The ability is the Posts tab as data: it must hand out the SAME rows and the
 * SAME flags the tab paints, with gaps still gaps, timestamps as ISO strings,
 * and machine reads only in the site-wide strip. Run: php tests/abilities-posts-signals.php
 *
 * @since 14.6.1
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
function __( $s, $d = null ) { return $s; }
$GLOBALS['__actions'] = array();
function add_action( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = $c; }
$GLOBALS['__registered'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__registered'][ $slug ] = $args; return true; }
class WP_Error { public $code; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; } }
function snt_ability_perm_manage_options() { return true; }

require __DIR__ . '/../inc/abilities-posts-signals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Group 1: registration\n";
foreach ( $GLOBALS['__actions']['wp_abilities_api_init'] as $cb ) { $cb(); }
$reg = $GLOBALS['__registered']['signal-noise/posts-signals'] ?? null;
ok( is_array( $reg ) && 'snt_ability_perm_manage_options' === $reg['permission_callback'] && 'snt_ability_posts_signals' === $reg['execute_callback'], 'registered, manage_options, the execute callback' );
ok( true === $reg['meta']['annotations']['readonly'] && true === $reg['meta']['annotations']['idempotent'] && false === $reg['meta']['annotations']['open_world_hint'], 'readonly + idempotent + closed world' );
ok( false !== strpos( $reg['description'], 'never a zero' ) && false !== strpos( $reg['description'], 'site-wide ONLY' ), 'the description carries the two reading rules an agent needs: null is not zero; machine reads are site-wide only' );
ok( false === strpos( $reg['description'], "\u{2014}" ), 'no em dash in the description' );

echo "\nGroup 2: without the data layer\n";
$r = snt_ability_posts_signals( null );
ok( $r instanceof WP_Error && 'snt_helper_unavailable' === $r->code, 'module not loaded → WP_Error, never an empty success' );

echo "\nGroup 3: the payload is the tab\n";
// Defined at RUNTIME (inside a block), so Group 2 above really ran without it.
if ( ! function_exists( 'sn_analytics_posts_signals' ) ) {
function sn_analytics_posts_signals() {
	$f = static function ( $v, $why = '' ) { return array( 'value' => $v, 'why' => null === $v ? $why : '' ); };
	return array(
		'rows'   => array( array(
			'id' => 2584, 'title' => 'Nobody can sign an absence', 'permalink' => 'https://x.test/notes/n/', 'publish_ts' => 1788000000, 'modified_ts' => 1789000000, 'body_changed_ts' => 1788500000,
			'age' => $f( 13 ), 'words' => $f( 640 ), 'index' => $f( false ), 'coverage_state' => 'Discovered - currently not indexed', 'last_crawl' => $f( null, 'never crawled' ),
			'impressions' => $f( null, 'not shown by Google in this window' ), 'clicks' => $f( null, 'not shown by Google in this window' ), 'position' => $f( null, 'not shown by Google in this window' ),
			'inbound' => $f( 2 ), 'anchor' => $f( array( 'version' => 2, 'block' => 965180, 'followed_key' => true ) ), 'related' => $f( array( 'count' => 4, 'top' => 0.7 ) ), 'views' => $f( 3 ),
			'flags' => array( 'not_indexed' => true, 'stale_crawl' => false, 'orphaned' => false ),
		) ),
		'counts' => array( 'total' => 1, 'indexed' => 0, 'with_impressions' => 0, 'zero_inbound' => 0, 'stale_crawl' => 0, 'not_indexed' => 1, 'not_inspected' => 0 ),
		'strip'  => array( 'machine_reads' => $f( 71627 ), 'machine_reads_days' => 30, 'machine_reads_stale' => false, 'gsc_window' => array( 'start' => '2026-08-14', 'end' => '2026-09-10' ), 'coverage_run' => array( 'finished_at' => 1788903869, 'inspected' => 40, 'errors' => 0 ) ),
	);
}
}
$r = snt_ability_posts_signals( null );
$row = $r['rows'][0];
ok( true === $r['ok'] && 1 === count( $r['rows'] ) && 2584 === $row['id'], 'ok + one row per note' );
ok( array( 'not_indexed' => true, 'stale_crawl' => false, 'orphaned' => false ) === $row['flags'], 'the flags ride verbatim: an agent reads them, never re-derives them' );
ok( array( 'value' => null, 'why' => 'not shown by Google in this window' ) === $row['impressions'], 'a gap stays {null, why}: not zero' );
ok( '2026-09-10T00:26:40+00:00' === $row['modified_at'] && '2026-09-04T05:33:20+00:00' === $row['body_changed_at'], 'timestamps as ISO strings; body_changed_at rides beside modified_at' );
ok( array( 'value' => null, 'why' => 'never crawled' ) === $row['last_crawl'], 'a null last crawl stays null with its reason' );
ok( 'Discovered - currently not indexed' === $row['coverage_state'], 'coverage_state verbatim' );
ok( ! array_key_exists( 'machine_reads', $row ) && 71627 === $r['strip']['machine_reads']['value'], 'machine reads are in the strip, never on a row' );
ok( ! array_key_exists( 'decay', $row ) && ! array_key_exists( 'refresh_candidate', $row ), 'no decay shape, no refresh candidate' );
ok( false !== strpos( $r['note'], 'Never read a null as zero' ), 'the note says how to read it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
