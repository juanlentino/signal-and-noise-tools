<?php
/**
 * signal-noise/dismiss-candidate — unified scan-candidate dismiss (v7.7.0).
 *
 * One ability replaces the two fingerprint dismissals:
 *   - surface=block-migrations  → appends candidate_type:block_fingerprint to
 *     _snt_block_migrations_dismissed (same store the scanner filters against)
 *   - surface=pattern-adoption  → delegates to snt_pattern_adoption_dismiss_impl()
 *
 * Also guards the impl extraction: the OLD block-migrations-dismiss wrapper and
 * the NEW dispatcher must share one impl (snt_block_migrations_dismiss_impl),
 * and only the OLD wrapper may emit the deprecation notice.
 *
 * @since 7.7.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── WP stubs ────────────────────────────────────────────────────────
$GLOBALS['__test_actions'] = array();
function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__test_actions'][ $tag ][] = $cb; return true; }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function current_user_can( $cap = '', $id = null ) { return true; }
function get_current_user_id() { return 7; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

// Post-meta store (the REAL contract the scanner reads).
$GLOBALS['__meta'] = array();
function get_post_meta( $post_id, $key, $single = false ) {
	return $GLOBALS['__meta'][ $post_id ][ $key ] ?? '';
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['__meta'][ $post_id ][ $key ] = $value;
	return true;
}

$GLOBALS['__deleted_transients'] = array();
function delete_transient( $key ) { $GLOBALS['__deleted_transients'][] = $key; return true; }

// Registry capture.
$GLOBALS['__ab'] = array();
function wp_register_ability( $name, $config ) { $GLOBALS['__ab'][ $name ] = $config; return true; }

// Deprecation recorder.
$GLOBALS['__dep_calls'] = array();
function _deprecated_function( $fn, $ver, $repl = '' ) { $GLOBALS['__dep_calls'][] = array( $fn, $ver, $repl ); }

// Pattern-adoption impl recorder (real impl lives in inc/pattern-adoption-admin.php).
$GLOBALS['__pa_dismiss_args'] = null;
function snt_pattern_adoption_dismiss_impl( $post_id, $pattern_type, $fingerprint ) {
	$GLOBALS['__pa_dismiss_args'] = array( $post_id, $pattern_type, $fingerprint );
	return array( 'ok' => true, 'message' => 'Candidate dismissed.' );
}

// ─── Load the SUT + fire registrations ───────────────────────────────
$dep_helper = __DIR__ . '/../inc/abilities-deprecations.php';
if ( file_exists( $dep_helper ) ) {
	require_once $dep_helper;
}
require_once __DIR__ . '/../inc/abilities-block-migrations.php';
// v11.4.0: the corpus-integrity dismiss impl lives in the scan module.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
require_once __DIR__ . '/../inc/corpus-integrity-scan.php';
$dismiss_module = __DIR__ . '/../inc/abilities-dismiss.php';
$dismiss_module_exists = file_exists( $dismiss_module );
if ( $dismiss_module_exists ) {
	require_once $dismiss_module;
}

foreach ( $GLOBALS['__test_actions']['wp_abilities_api_init'] ?? array() as $cb ) {
	call_user_func( $cb );
}

// ─── Harness ─────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function t( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}
function t_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $expected, true ) . "\n    Actual:   " . var_export( $actual, true ) . "\n"; }
}

$FP = str_repeat( 'a', 32 );

// ════ registration contract ══════════════════════════════════════════
echo "\nGroup A: dismiss-candidate registration\n";
t( $dismiss_module_exists, 'A.1 inc/abilities-dismiss.php exists' );
$dc = $GLOBALS['__ab']['signal-noise/dismiss-candidate'] ?? null;
t( is_array( $dc ), 'A.2 dismiss-candidate: registered' );
t_eq( 'tools', $dc['category'] ?? '', 'A.3 category tools (deterministic, not ai-generation)' );
t_eq( 'snt_ability_perm_edit_post', $dc['permission_callback'] ?? '', 'A.4 per-resource edit_post gate (no IDOR via blanket cap)' );
t_eq( array( 'surface', 'post_id', 'block_fingerprint', 'candidate_type' ), $dc['input_schema']['required'] ?? null, 'A.5 required quartet' );
t_eq( array( 'block-migrations', 'pattern-adoption', 'corpus-integrity' ), $dc['input_schema']['properties']['surface']['enum'] ?? null, 'A.6 surface enum exact (v11.4.0 adds corpus-integrity)' );
t( ( $dc['meta']['annotations']['idempotent'] ?? null ) === true && ( $dc['meta']['annotations']['destructive'] ?? null ) === false, 'A.7 idempotent + non-destructive' );
t( ( $dc['meta']['show_in_rest'] ?? false ) === true, 'A.8 show_in_rest' );

// ════ block-migrations surface ═══════════════════════════════════════
echo "\nGroup B: surface=block-migrations dispatch\n";
if ( function_exists( 'snt_ability_dismiss_candidate' ) ) {
	$out = snt_ability_dismiss_candidate( array(
		'surface'           => 'block-migrations',
		'post_id'           => 42,
		'block_fingerprint' => $FP,
		'candidate_type'    => 'heading-hierarchy-skip',
	) );
	t( is_array( $out ) && true === ( $out['ok'] ?? false ), 'B.1 returns ok' );
	$stored = $GLOBALS['__meta'][42]['_snt_block_migrations_dismissed'] ?? array();
	t( in_array( 'heading-hierarchy-skip:' . $FP, (array) $stored, true ), 'B.2 writes candidate_type:fingerprint into the REAL scanner store' );
	t( in_array( 'snt_block_migrations_candidates_7', $GLOBALS['__deleted_transients'], true ), 'B.3 invalidates the user-scoped scan transient' );

	snt_ability_dismiss_candidate( array(
		'surface'           => 'block-migrations',
		'post_id'           => 42,
		'block_fingerprint' => $FP,
		'candidate_type'    => 'heading-hierarchy-skip',
	) );
	$stored = (array) ( $GLOBALS['__meta'][42]['_snt_block_migrations_dismissed'] ?? array() );
	t_eq( 1, count( $stored ), 'B.4 idempotent — re-dismiss is a no-op (no duplicate key)' );
} else {
	t( false, 'B.1 snt_ability_dismiss_candidate() exists' );
	t( false, 'B.2 writes candidate_type:fingerprint into the REAL scanner store' );
	t( false, 'B.3 invalidates the user-scoped scan transient' );
	t( false, 'B.4 idempotent — re-dismiss is a no-op' );
}

// ════ pattern-adoption surface ═══════════════════════════════════════
echo "\nGroup C: surface=pattern-adoption dispatch\n";
if ( function_exists( 'snt_ability_dismiss_candidate' ) ) {
	$out = snt_ability_dismiss_candidate( array(
		'surface'           => 'pattern-adoption',
		'post_id'           => 77,
		'block_fingerprint' => 'fp-pa-1',
		'candidate_type'    => 'pull-quote',
	) );
	t_eq( array( 77, 'pull-quote', 'fp-pa-1' ), $GLOBALS['__pa_dismiss_args'], 'C.1 delegates to snt_pattern_adoption_dismiss_impl( post_id, pattern_type, fingerprint )' );
	t( true === ( $out['ok'] ?? false ) && isset( $out['message'] ), 'C.2 impl result passthrough' );

	$out = snt_ability_dismiss_candidate( array(
		'surface'           => 'somewhere-else',
		'post_id'           => 77,
		'block_fingerprint' => 'fp',
		'candidate_type'    => 'x',
	) );
	t( is_wp_error( $out ), 'C.3 unknown surface → WP_Error (defense in depth beyond schema enum)' );
} else {
	t( false, 'C.1 delegates to snt_pattern_adoption_dismiss_impl' );
	t( false, 'C.2 impl result passthrough' );
	t( false, 'C.3 unknown surface → WP_Error' );
}

// ════ shared-impl extraction + canonical silence ═════════════════════
// ════ corpus-integrity surface (v11.4.0) ═════════════════════════════
echo "\nGroup D: surface=corpus-integrity dispatch\n";
if ( function_exists( 'snt_ability_dismiss_candidate' ) && function_exists( 'snt_corpus_integrity_dismiss_impl' ) ) {
	$GLOBALS['__meta'] = array();
	$out = snt_ability_dismiss_candidate( array(
		'surface'           => 'corpus-integrity',
		'post_id'           => 77,
		'block_fingerprint' => str_repeat( 'a', 32 ),
		'candidate_type'    => 'splice_artifact',
	) );
	t( is_array( $out ) && ! empty( $out['ok'] ), 'D.1 corpus-integrity dismiss dispatches to its impl' );
	$stored = $GLOBALS['__meta'][77]['_snt_corpus_integrity_dismissed'] ?? array();
	t( in_array( 'splice_artifact:' . str_repeat( 'a', 32 ), (array) $stored, true ), 'D.2 appends check:fingerprint to _snt_corpus_integrity_dismissed' );
	$out2 = snt_ability_dismiss_candidate( array(
		'surface'           => 'corpus-integrity',
		'post_id'           => 77,
		'block_fingerprint' => str_repeat( 'a', 32 ),
		'candidate_type'    => 'splice_artifact',
	) );
	t( is_array( $out2 ) && 1 === count( (array) ( $GLOBALS['__meta'][77]['_snt_corpus_integrity_dismissed'] ?? array() ) ), 'D.3 idempotent — re-dismiss is a no-op' );
} else {
	for ( $i = 1; $i <= 3; $i++ ) { t( false, "D.$i corpus-integrity dismiss available" ); }
}

// (The v7.x Group D also executed the deprecated per-surface wrapper;
// that surface was removed in v8.0.0 — tests/abilities-removals-v8.php
// guards its absence.)
echo "\nGroup D: shared impl + canonical silence\n";
t( function_exists( 'snt_block_migrations_dismiss_impl' ), 'D.1 block-migrations dismiss logic extracted to a shared impl' );
t_eq( 0, count( $GLOBALS['__dep_calls'] ), 'D.2 dismiss-candidate (canonical) never emits a deprecation notice' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
