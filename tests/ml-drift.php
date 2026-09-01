<?php
/**
 * Corpus drift (R4 4A, v11.2.0): inc/ml-drift.php + the 'corpus-drift'
 * pipeline + the ABSENCES that are the row's actual contract.
 *
 * The kernel arithmetic (per-term movement, the thin gate, determinism) is
 * pinned in tests/ml-kernel.php against the pure fns. This suite owns the glue:
 * the UTC year bucketing over the corpus walk, the adjacent-pair report, and —
 * the pins that earn the file — the row's "shown to the writer, never to a
 * model" boundary. That boundary is an ABSENCE (no ability, no remote twin),
 * and an unpinned absence is one helpful future session away from existing:
 * the #641 lesson (show_in_rest) was exactly a surface nobody meant to ship.
 *
 * Run: php tests/ml-drift.php
 * @since plugin v11.2.0
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
if ( ! function_exists( 'add_action' ) ) { function add_action( $t, $c, $p = 10, $a = 1 ) { return true; } }
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $h, $v ) {
		foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v ); }
		return $v;
	}
}

// The corpus fetch stub mirrors tests/ml-link-isolation.php's: status- and
// type-aware over $GLOBALS['__posts'], because the module's published-only
// choice is one of the assertions below — a stub blind to status would make
// that pin vacuous (test-stub-drift-invents-shapes).
function tf_post( $id, $status, $date_gmt, $content ) {
	$p = new stdClass();
	$p->ID            = $id;
	$p->post_status   = $status;
	$p->post_type     = 'post';
	$p->post_date_gmt = $date_gmt;
	$p->post_content  = $content;
	return $p;
}
$GLOBALS['__posts'] = array();
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$out  = array();
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
require __DIR__ . '/../inc/ml-drift.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Corpus drift — the mirror's glue layer (v11.2.0)\n";

echo "\nGroup: year bucketing\n";
// Enough bodies per year to clear SNT_ML_DRIFT_MIN_DOCS (5). Vocabulary is
// chosen so the movement direction is unambiguous: 'ledger' dominates 2024,
// 'agent' dominates 2025, 'notes' is everywhere (stationary).
$GLOBALS['__posts'] = array();
$fill = function ( $year, $count, $word ) {
	static $id = 0;
	for ( $i = 0; $i < $count; $i++ ) {
		++$id;
		$GLOBALS['__posts'][] = tf_post( $id, 'publish', "$year-03-0" . ( ( $i % 9 ) + 1 ) . ' 12:00:00', "<p>The $word writes notes about the $word daily.</p>" );
	}
};
$fill( 2024, 5, 'ledger' );
$fill( 2025, 5, 'agent' );
$GLOBALS['__posts'][] = tf_post( 900, 'draft', '2025-06-01 12:00:00', '<p>draft agent agent agent</p>' );
$GLOBALS['__posts'][] = tf_post( 901, 'publish', '0000-00-00 00:00:00', '<p>ghost ledger</p>' );
$GLOBALS['__posts'][] = tf_post( 902, 'publish', '2025-06-02 12:00:00', '<!-- wp:spacer --><!-- /wp:spacer -->' );

$buckets = snt_ml_drift_year_buckets();
ok( array( 2024, 2025 ) === array_keys( $buckets ), 'two years bucket, ascending' );
ok( 5 === count( $buckets[2024] ) && 5 === count( $buckets[2025] ), 'five docs per year' );
ok( ! isset( $buckets[2025][900] ), 'a DRAFT is not part of the public vocabulary — published only' );
ok( ! isset( $buckets[0] ), 'a zeroed post_date_gmt names no year: skipped, never bucketed under 0' );
ok( ! isset( $buckets[2025][902] ), 'a markup-only body (zero tokens) is skipped, as in the cousins walk' );

echo "\nGroup: the report\n";
$report = snt_ml_drift_report();
ok( true === $report['ok'], 'the report answers' );
ok( array( array( 'year' => 2024, 'docs' => 5 ), array( 'year' => 2025, 'docs' => 5 ) ) === $report['years'], 'the year ledger carries both sizes' );
ok( 1 === count( $report['pairs'] ), 'two years make one adjacent pair' );
$pair = $report['pairs'][0];
ok( 2024 === $pair['from'] && 2025 === $pair['to'], 'the pair names both years' );
ok( 'ok' === $pair['verdict'], 'five docs a side clears the floor' );
ok( in_array( 'agent', array_column( $pair['entered'], 'term' ), true ), "'agent' ENTERED in 2025" );
ok( in_array( 'ledger', array_column( $pair['silenced'], 'term' ), true ), "'ledger' went SILENT after 2024" );
$stationary = array_merge(
	array_column( $pair['risen'], 'term' ),
	array_column( $pair['fallen'], 'term' ),
	array_column( $pair['entered'], 'term' ),
	array_column( $pair['silenced'], 'term' )
);
ok( ! in_array( 'notes', $stationary, true ), "'notes' held its share and appears in NO list" );

echo "\nGroup: the thin gate travels through the glue\n";
// Drop 2024 to two docs: the pair must refuse, and the refusal must carry
// the sizes — the surface renders WHY, not just "thin".
$GLOBALS['__posts'] = array_values( array_filter( $GLOBALS['__posts'], function ( $p ) {
	return ! ( 'publish' === $p->post_status && 0 === strpos( (string) $p->post_date_gmt, '2024' ) && $p->ID > 2 );
} ) );
$thin_report = snt_ml_drift_report();
ok( 'thin' === $thin_report['pairs'][0]['verdict'], 'a two-doc year refuses to speak through the whole stack' );
ok( 2 === $thin_report['pairs'][0]['docs']['before'], 'and the refusal names the size that disqualified it' );
ok( array() === $thin_report['pairs'][0]['risen'] && array() === $thin_report['pairs'][0]['silenced'], 'a thin pair carries zero term rows' );

echo "\nGroup: degenerate corpora are answers, not errors\n";
$GLOBALS['__posts'] = array( tf_post( 1, 'publish', '2025-01-01 12:00:00', '<p>lonely note</p>' ) );
$one = snt_ml_drift_report();
ok( true === $one['ok'] && 1 === count( $one['years'] ) && array() === $one['pairs'], 'one year: a real answer with no pairs — drift needs two' );
$GLOBALS['__posts'] = array();
$none = snt_ml_drift_report();
ok( true === $none['ok'] && array() === $none['years'] && array() === $none['pairs'], 'an empty corpus is a real answer, not an error' );

echo "\nGroup: the pipeline gate\n";
ok( isset( snt_ml_pipelines()['corpus-drift'] ), "the registry carries 'corpus-drift' (pipeline #9)" );
ok( 10 === count( snt_ml_pipelines() ), 'ten pipelines registered — drift is #9, reading-path #10 (v11.3.0)' );
$via = snt_ml_run( 'corpus-drift', array() );
ok( is_array( $via ) && true === $via['ok'], 'the dispatcher reaches the module' );

echo "\nGroup: THE ABSENCES — shown to the writer, never to a model\n";
// The row's contract is what this surface must NOT be. Grep-pin the codebase:
// no ability file may name the pipeline slug or the report fn, and the remote
// set must not grow a drift twin. File-text pins, same idiom as the kernel's
// purity pin — they red the moment a future session wires the surface it
// should not.
$inc = dirname( __DIR__ ) . '/inc/';
$ability_files = glob( $inc . 'abilities-*.php' );
ok( array() !== $ability_files, 'sanity: the ability files are where this suite expects them' );
$leaks = array();
foreach ( $ability_files as $f ) {
	$text = (string) file_get_contents( $f );
	if ( false !== strpos( $text, 'corpus-drift' ) || false !== strpos( $text, 'snt_ml_drift_report' ) ) {
		$leaks[] = basename( $f );
	}
}
ok( array() === $leaks, 'NO ability wraps the drift pipeline — the mirror faces the writer, never a model (leaked in: ' . implode( ', ', $leaks ) . ')' );
$remote = (string) file_get_contents( $inc . 'abilities-remote-set.php' );
// v13.52.0: this scanned the bare substring 'drift' and matched PROSE — a
// schema description reading "cannot drift from them" tripped it, with zero
// references to the drift pipeline in the file. The invariant is "no drift
// ABILITY is twinned", so scan for the pipeline's own identifiers, the same
// pair the leak check above uses. A pin that fires on the word rather than the
// thing trains people to reword comments, which is worse than no pin.
ok(
	false === strpos( $remote, 'corpus-drift' ) && false === strpos( $remote, 'snt_ml_drift_report' ),
	'and the REMOTE set carries no drift twin — the phone door reads analytics, not the corpus mirror'
);
// Negative control: the scan must still catch a real leak.
ok(
	false !== strpos( "wp_register_ability( 'signal-noise/remote-corpus-drift'", 'corpus-drift' ),
	'and that scan still detects a genuine drift twin (control against the loosening above)'
);
// The absence pin must itself be non-vacuous: prove the grep would catch a
// real registration by scanning a string shaped like one (the
// negative-control-your-own-instruments rule).
$would_leak = "wp_register_ability( 'signal-noise/corpus-drift', array()";
ok( false !== strpos( $would_leak, 'corpus-drift' ), 'NEGATIVE CONTROL: the needle DOES match registration-shaped text, so the empty result above is a real absence' );

echo "\nGroup: no PHP notices anywhere\n";
ok( array() === $GLOBALS['__php_errors'], 'zero notices/warnings/deprecations: ' . implode( ' | ', $GLOBALS['__php_errors'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
