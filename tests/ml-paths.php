<?php
/**
 * Reading paths (R4 4B, v11.3.0): inc/ml-paths.php + inc/ml-paths-render.php.
 *
 * The kernel chain (centrality, greedy NN, determinism) is pinned in
 * tests/ml-kernel.php. This suite owns the reader side: the THREE-WAY resolver
 * contract (null not-built / [] no-path / row), the pre-upgrade-artifact case
 * that motivates the third way — clusters WITHOUT 'path' keys must read as
 * unknown, never as "no path", because the option outlives the code that wrote
 * it — the pipeline envelope, and the renderer's self-gating (including the
 * publicly-viewable gate on neighbours: the artifact rebuild coalesces ~30s
 * behind a retraction, and a chain must not leak a retracted title).
 *
 * Run: php tests/ml-paths.php
 * @since plugin v11.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }

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
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
$GLOBALS['__shortcodes'] = array();
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $cb ) { $GLOBALS['__shortcodes'][ $t ] = $cb; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $p, $f = '' ) { return 'https://x/' . $p; } }
$GLOBALS['__enq'] = array();
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( ...$a ) { $GLOBALS['__enq'][] = $a[0]; } }

// The stored option: the resolver's ONLY input. snt_ml_topics_get() is the
// real fn (ml-artifacts.php required below), reading this stub.
$GLOBALS['__options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
}
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = true ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
// ml-artifacts.php registers hooks + touches meta/cron APIs at load: inert stubs.
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) { return true; } }
if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( ...$a ) { return true; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( ...$a ) { return true; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( ...$a ) { return false; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( ...$a ) { return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( ...$a ) { return true; } }

// Render-context stubs, mutable per group.
$GLOBALS['__ctx'] = array( 'singular' => true, 'id' => 20, 'viewable' => array(), 'titles' => array() );
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return $GLOBALS['__ctx']['singular']; } }
if ( ! function_exists( 'get_the_ID' ) ) { function get_the_ID() { return $GLOBALS['__ctx']['id']; } }
if ( ! function_exists( 'is_post_publicly_viewable' ) ) { function is_post_publicly_viewable( $id ) { return in_array( (int) $id, $GLOBALS['__ctx']['viewable'], true ); } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return $GLOBALS['__ctx']['titles'][ (int) $id ] ?? ''; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return 'https://juanlentino.com/notes/n' . (int) $id . '/'; } }

require __DIR__ . '/../inc/ml-kernel.php';
require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/ml-artifacts.php';
require __DIR__ . '/../inc/ml-paths.php';
require __DIR__ . '/../inc/ml-paths-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Reading paths — the reader side (v11.3.0)\n";

echo "\nGroup: the three-way resolver contract\n";
ok( null === snt_ml_path_for_post( 20 ), 'no artifact at all → null (unknown, never a fabricated no-path)' );

// THE PRE-UPGRADE CASE — the third way that earns the contract. An artifact
// written by pre-11.3.0 code has clusters but NO path keys: the option
// outlives the code that wrote it, and an absent ordering is unknown.
$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = array(
	'built_at' => 1, 'threshold' => 0.35,
	'clusters' => array( array( 'members' => array( 20, 21 ), 'label' => 'old · shape' ) ),
);
ok( null === snt_ml_path_for_post( 20 ), 'PRE-UPGRADE ARTIFACT: clusters without path keys → null, never "no path" — the next rebuild heals it' );

$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = array(
	'built_at' => 2, 'threshold' => 0.35,
	'clusters' => array(
		array( 'members' => array( 10, 11, 12 ), 'label' => 'prov · ledger', 'path' => array( 11, 10, 12 ) ),
		array( 'members' => array( 20, 21 ), 'label' => 'agent · door', 'path' => array( 20, 21 ) ),
	),
);
$row = snt_ml_path_for_post( 10 );
ok( is_array( $row ) && 2 === $row['position'] && 3 === $row['total'], 'a mid-chain member knows its 1-based position and the total' );
ok( 11 === $row['prev'] && 12 === $row['next'], 'and both neighbours, in CHAIN order — not member order' );
ok( 'prov · ledger' === $row['label'], 'the row carries its cluster label' );
$head = snt_ml_path_for_post( 11 );
ok( null === $head['prev'] && 10 === $head['next'], 'the chain head has no prev — null, not a wraparound' );
ok( array() === snt_ml_path_for_post( 99 ), 'a post on no path → [] — a REAL answer, distinct from null' );

echo "\nGroup: the pipeline envelope\n";
ok( isset( snt_ml_pipelines()['reading-path'] ) && 11 === count( snt_ml_pipelines() ), "the registry carries 'reading-path' as pipeline #10 of eleven (reader-anomalies is #11)" );
$e = snt_ml_run( 'reading-path', array() );
ok( is_wp_error( $e ) && 'snt_ml_invalid_args' === $e->get_error_code(), 'missing post_id refuses 400' );
$r = snt_ml_run( 'reading-path', array( 'post_id' => 10 ) );
ok( is_array( $r ) && true === $r['ok'] && 2 === $r['path']['position'], 'the dispatcher resolves a chained post' );
$n = snt_ml_run( 'reading-path', array( 'post_id' => 99 ) );
ok( is_array( $n ) && true === $n['ok'] && null === $n['path'], 'on-no-path is ok:true path:null — an answer, not an error' );
$GLOBALS['__options'] = array();
$nb = snt_ml_run( 'reading-path', array( 'post_id' => 10 ) );
ok( is_wp_error( $nb ) && 'snt_ml_not_built' === $nb->get_error_code(), 'not-built is the 503 — the caller can tell it from no-path' );

echo "\nGroup: the renderer self-gates\n";
$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = array(
	'built_at' => 3, 'threshold' => 0.35,
	'clusters' => array( array( 'members' => array( 20, 21, 22 ), 'label' => 'agent · door', 'path' => array( 21, 20, 22 ) ) ),
);
$GLOBALS['__ctx'] = array( 'singular' => true, 'id' => 20, 'viewable' => array( 21, 22 ), 'titles' => array( 21 => 'The door', 22 => 'The token' ) );
$html = snt_ml_reading_path_shortcode();
ok( false !== strpos( $html, 'Part 2 of 3' ) && false !== strpos( $html, 'agent · door' ), 'the nav states position, total and label' );
ok( false !== strpos( $html, '/notes/n21/' ) && false !== strpos( $html, '/notes/n22/' ), 'both neighbours link in chain order' );
ok( array( 'snt-ml-paths' ) === $GLOBALS['__enq'], 'the stylesheet rides the render' );

$GLOBALS['__enq'] = array();
$GLOBALS['__ctx']['viewable'] = array( 22 );
$html2 = snt_ml_reading_path_shortcode();
ok( false === strpos( $html2, '/notes/n21/' ) && false !== strpos( $html2, '/notes/n22/' ), 'THE VIEWABLE GATE: a retracted neighbour renders as absence — the chain must not leak a title the site no longer publishes' );

$GLOBALS['__enq'] = array();
$GLOBALS['__ctx']['viewable'] = array();
ok( '' === snt_ml_reading_path_shortcode() && array() === $GLOBALS['__enq'], 'both neighbours gated away → empty render AND no stylesheet — a nav with no links is noise' );

$GLOBALS['__ctx'] = array( 'singular' => false, 'id' => 20, 'viewable' => array( 21, 22 ), 'titles' => array( 21 => 'x', 22 => 'y' ) );
ok( '' === snt_ml_reading_path_shortcode(), 'outside a single post the shortcode renders nothing' );
$GLOBALS['__ctx'] = array( 'singular' => true, 'id' => 99, 'viewable' => array(), 'titles' => array() );
ok( '' === snt_ml_reading_path_shortcode(), 'a no-path note renders nothing — absence, not an apology' );
$GLOBALS['__options'] = array();
$GLOBALS['__ctx']['id'] = 20;
ok( '' === snt_ml_reading_path_shortcode(), 'not-built renders nothing for a READER (the 503 is for machine callers)' );

echo "\nGroup: the shortcode is registered\n";
ok( isset( $GLOBALS['__shortcodes']['sn_reading_path'] ), '[sn_reading_path] registers — the tag the THEME will place' );

echo "\nGroup: no PHP notices anywhere\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings/deprecations: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
