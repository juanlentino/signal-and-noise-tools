<?php
/**
 * Tests for the 'topic-clusters' pipeline wrapper + its ability door
 * (v10.21.0 — the review's coverage gap, closed pre-merge): every sibling
 * pipeline asserts both layers directly, and this one now does too.
 *   - registry: snt_ml_run('topic-clusters') resolves;
 *   - reader ABSENT → snt_ml_unavailable (500) — the module guard, exercised
 *     for real because the stub reader is defined conditionally AFTER;
 *   - reader null → snt_ml_not_built (503);
 *   - clusters → { ok, clusters, cluster_count, built_at } with built_at
 *     sourced from the stored option (0 when the option shape drifts);
 *   - the ability wrapper delegates through snt_ml_run verbatim.
 * Run: php tests/ml-pipelines-topics.php
 * @since plugin v10.21.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function apply_filters( $tag, $value ) { return $value; }
function __( $s, $d = null ) { return (string) $s; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {}
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
// The wrapper reads the artifacts module's option constant + get_option; the
// artifacts module itself is deliberately NOT loaded (that absence is phase 1).
define( 'SNT_ML_TOPICS_OPT', 'snt_ml_topics' );
$GLOBALS['__options'] = array();
function get_option( $key, $default = false ) { return $GLOBALS['__options'][ $key ] ?? $default; }

require __DIR__ . '/../inc/ml-pipelines.php';
require __DIR__ . '/../inc/abilities-corpus.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: module-absent guard (the reader is genuinely undefined here)\n";
ok( ! function_exists( 'snt_ml_topics_get' ), 'precondition: the artifacts reader is absent' );
$gone = snt_ml_run( 'topic-clusters', array() );
ok( is_wp_error( $gone ) && 'snt_ml_unavailable' === $gone->get_error_code() && 500 === ( $gone->get_error_data()['status'] ?? 0 ), 'reader absent → snt_ml_unavailable (500)' );

// Conditionally defined AFTER the absence test (a bare top-level declaration
// would HOIST at compile time and make the guard test unsatisfiable).
if ( ! function_exists( 'snt_ml_topics_get' ) ) {
	function snt_ml_topics_get() { return $GLOBALS['__topics']; }
}

echo "\nGroup: not built vs built\n";
$GLOBALS['__topics'] = null;
$unbuilt = snt_ml_run( 'topic-clusters', array() );
ok( is_wp_error( $unbuilt ) && 'snt_ml_not_built' === $unbuilt->get_error_code() && 503 === ( $unbuilt->get_error_data()['status'] ?? 0 ), 'null from the reader → snt_ml_not_built (503) — never conflated with a clusterless corpus' );

$GLOBALS['__topics'] = array();
$empty = snt_ml_run( 'topic-clusters', array() );
ok( is_array( $empty ) && true === $empty['ok'] && array() === $empty['clusters'] && 0 === $empty['cluster_count'], '[] from the reader is an ANSWER: ok with zero clusters' );

$GLOBALS['__topics'] = array( array( 'members' => array( 1, 2 ), 'label' => 'x · y' ) );
$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = array( 'built_at' => 1234, 'threshold' => 0.35, 'clusters' => $GLOBALS['__topics'] );
$built = snt_ml_run( 'topic-clusters', array() );
ok( is_array( $built ) && 1 === $built['cluster_count'] && $GLOBALS['__topics'] === $built['clusters'], 'clusters pass through verbatim with the count' );
ok( 1234 === $built['built_at'], 'built_at reads from the stored option' );

$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = 'corrupted-not-an-array';
$drift = snt_ml_run( 'topic-clusters', array() );
ok( is_array( $drift ) && 0 === $drift['built_at'], 'a drifted option shape yields built_at 0, never a notice or fabricated stamp' );
$GLOBALS['__options'][ SNT_ML_TOPICS_OPT ] = array( 'built_at' => 1234, 'threshold' => 0.35, 'clusters' => $GLOBALS['__topics'] );

echo "\nGroup: the ability door delegates verbatim\n";
$via_ability = snt_ability_corpus_topic_clusters( null );
ok( $via_ability === snt_ml_run( 'topic-clusters', array() ), 'ability wrapper output === registry output (single dispatch seam)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
