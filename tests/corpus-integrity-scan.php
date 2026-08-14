<?php
/**
 * Standalone fixture tests for the corpus-integrity scan (v11.4.0).
 *
 * Three independent deterministic checks over post bodies — no AI:
 *   (a) intra_post_duplication — near-duplicate paragraph/heading pairs
 *       within one post (similarity > 0.80, both sides >= 40 chars);
 *   (b) splice_artifact — a lowercase word fused to a period with no
 *       space (/[a-z]{2}\.[a-z]{3,}/), the signature of a mid-sentence
 *       paste/splice, with exclusions for domains, file names, versions,
 *       inline code, and wp:html / wp:code blocks;
 *   (c) date_coherence — an in-body date LATER than post_date; WARNING
 *       when the sentence carries a past-tense event verb, INFO otherwise.
 *
 * FIXTURE PROVENANCE: the positive fixtures are the REAL corpus defects
 * found by hand on 2026-08-14 (posts 1495 splice, 1570 duplication, 1549
 * backdated dates), trimmed but not cleaned up — fixture shapes come from
 * the emitter, never from what reads nicely in a test.
 *
 * @since plugin v11.4.0
 */

// SECURITY: CLI-only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
    http_response_code( 404 );
    exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ─── WP stubs (mirrors tests/block-migrations-detect.php) ─────────────
$GLOBALS['__test_posts']      = array();
$GLOBALS['__test_post_meta']  = array();
$GLOBALS['__test_transients'] = array();

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$GLOBALS['__test_get_posts_args'] = $args;
		return array_values( $GLOBALS['__test_posts'] );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$val = $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? array();
		return $single ? ( is_array( $val ) ? ( $val[0] ?? '' ) : $val ) : $val;
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) { return json_encode( $block ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) { return $GLOBALS['__test_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $val, $ttl ) { $GLOBALS['__test_transients'][ $key ] = $val; return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) { return 'https://example.test/?p=' . (int) $post_id; }
}

// Fixture helper. Status + post_date settable (date coherence needs both).
function _ci_post( $id, $blocks_array, $status = 'publish', $post_date = '2026-05-01 12:00:00' ) {
	$post = new stdClass();
	$post->ID           = $id;
	$post->post_status  = $status;
	$post->post_type    = 'post';
	$post->post_title   = "Fixture $id";
	$post->post_date    = $post_date;
	$post->post_content = json_encode( $blocks_array );
	$GLOBALS['__test_posts'][ $id ] = $post;
}
function _ci_para( $text ) {
	return array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>' . $text . '</p>', 'innerContent' => array( '<p>' . $text . '</p>' ) );
}

require_once __DIR__ . '/../inc/block-fingerprint-engine.php';
$sut        = __DIR__ . '/../inc/corpus-integrity-scan.php';
$sut_exists = file_exists( $sut );
if ( $sut_exists ) {
	require_once $sut;
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function ci_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function ci_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
function _ci_reset() {
	$GLOBALS['__test_posts'] = array();
	$GLOBALS['__test_post_meta'] = array();
	$GLOBALS['__test_transients'] = array();
}
function _ci_by_check( $candidates, $check ) {
	return array_values( array_filter( $candidates, static function ( $c ) use ( $check ) {
		return ( $c['check'] ?? '' ) === $check;
	} ) );
}

echo "Corpus-integrity scan suite — plugin v11.4.0\n";
ci_true( $sut_exists, '0.1 inc/corpus-integrity-scan.php exists' );
ci_true( function_exists( 'snt_corpus_integrity_compute' ), '0.2 snt_corpus_integrity_compute() exists' );

if ( ! function_exists( 'snt_corpus_integrity_compute' ) ) {
	echo "\n0 passed, 99 failed\n"; // hard stop: nothing below can run
	exit( 1 );
}

// ─── Test 1: the REAL 1570 duplication is flagged (check a) ──────────
echo "\nTest 1: intra-post duplication (real 1570 shape)\n";
_ci_reset();
$dup_a = 'Most discussions of music provenance assume the artist signing a recording is somebody the system already knows about. They are on a label. They are registered with a performance rights organization. They have a distributor account.';
$dup_b = 'Most discussions of music provenance assume the artist signing a recording is somebody the system already knows about. They\'re on a label. They\'re registered with a performance rights organization. They have a distributor account.';
_ci_post( 100, array( _ci_para( $dup_a ), _ci_para( 'Unrelated middle paragraph long enough to clear the forty character floor easily.' ), _ci_para( $dup_b ) ) );
$out  = snt_corpus_integrity_compute();
$dups = _ci_by_check( $out['candidates'], 'intra_post_duplication' );
ci_eq( 1, count( $dups ), 'Test 1.1: near-exact paragraph pair flagged once' );
ci_true( ( $dups[0]['ratio'] ?? 0 ) > 0.8, 'Test 1.2: ratio reported > 0.80' );
ci_eq( '0/0', $dups[0]['block_path_a'] ?? '', 'Test 1.3: first block path reported' );
ci_eq( '0/2', $dups[0]['block_path_b'] ?? '', 'Test 1.4: second block path reported' );
ci_true( '' !== ( $dups[0]['block_fingerprint'] ?? '' ) && '' !== ( $dups[0]['block_fingerprint_b'] ?? '' ), 'Test 1.5: both fingerprints minted' );
ci_eq( 'warning', $dups[0]['severity'] ?? '', 'Test 1.6: severity is warning, never error' );
ci_true( '' !== ( $dups[0]['text_a'] ?? '' ) && '' !== ( $dups[0]['text_b'] ?? '' ), 'Test 1.7: both texts reported for at-a-glance judging' );

// ─── Test 2: duplication floor + non-duplicates ──────────────────────
echo "\nTest 2: duplication floor and negatives\n";
_ci_reset();
_ci_post( 101, array(
	_ci_para( 'Short repeat.' ),
	_ci_para( 'Short repeat.' ),
	_ci_para( 'A perfectly ordinary paragraph about provenance that shares no body with its neighbor.' ),
	_ci_para( 'Completely different prose discussing distributors, royalties, and metadata pipelines instead.' ),
) );
$out  = snt_corpus_integrity_compute();
$dups = _ci_by_check( $out['candidates'], 'intra_post_duplication' );
ci_eq( 0, count( $dups ), 'Test 2.1: sub-40-char repeats ignored; dissimilar pairs not flagged' );

// ─── Test 3: the REAL 1495 splice artifact is flagged (check b) ──────
echo "\nTest 3: splice artifact (real 1495 shape)\n";
_ci_reset();
$splice = 'A standard that assumes metadata persists won\'t survive that pipeline. The mechanics of music distribution are actively hostile to it.mance rights organization. Each one re-encodes, repackages, or strips metadata as a matter of course.';
_ci_post( 102, array( _ci_para( $splice ) ) );
$out      = snt_corpus_integrity_compute();
$splices  = _ci_by_check( $out['candidates'], 'splice_artifact' );
ci_eq( 1, count( $splices ), 'Test 3.1: it.mance flagged' );
ci_true( false !== strpos( (string) ( $splices[0]['sentence'] ?? '' ), 'it.mance' ), 'Test 3.2: surrounding sentence reported' );
ci_eq( 'warning', $splices[0]['severity'] ?? '', 'Test 3.3: severity warning' );
ci_eq( '0/0', $splices[0]['block_path'] ?? '', 'Test 3.4: block path reported' );

// ─── Test 4: splice exclusions (domains, files, versions, code) ──────
echo "\nTest 4: splice-check exclusions\n";
_ci_reset();
_ci_post( 103, array(
	_ci_para( 'The site at juanlentino.com serves the ledger, and archive.org holds nothing.' ),
	_ci_para( 'Rename styles.css and functions.php before the deploy lands tonight.' ),
	_ci_para( 'Use <code>options.reload</code> from the console to trigger it manually.' ),
	array( 'blockName' => 'core/code', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<pre><code>config.notify = true;</code></pre>', 'innerContent' => array( '<pre><code>config.notify = true;</code></pre>' ) ),
	array( 'blockName' => 'core/html', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<svg><text>metadata.strip happens here</text></svg>', 'innerContent' => array( '<svg><text>metadata.strip happens here</text></svg>' ) ),
) );
$out     = snt_corpus_integrity_compute();
$splices = _ci_by_check( $out['candidates'], 'splice_artifact' );
ci_eq( 0, count( $splices ), 'Test 4.1: domains, filenames, inline code, wp:code and wp:html all excluded' );

// ─── Test 5: the REAL 1549 date incoherence (check c) ────────────────
echo "\nTest 5: date coherence (real 1549 shape)\n";
_ci_reset();
_ci_post( 104, array(
	_ci_para( 'Spotify announced "Verified by Spotify" the week of June 17, 2026. The framing was clear.' ),
	_ci_para( 'Spotify shipped the first stamp the week of June 29, 2026. That is the easier of the two problems.' ),
), 'publish', '2026-05-09 18:33:32' );
$out   = snt_corpus_integrity_compute();
$dates = _ci_by_check( $out['candidates'], 'date_coherence' );
ci_eq( 2, count( $dates ), 'Test 5.1: both post-dated in-body dates flagged' );
ci_eq( 'warning', $dates[0]['severity'] ?? '', 'Test 5.2: past-tense event verb (announced) escalates to warning' );
ci_true( false !== strpos( (string) ( $dates[0]['sentence'] ?? '' ), 'June 17, 2026' ), 'Test 5.3: sentence reported' );
ci_eq( '2026-05-09', $dates[0]['post_date'] ?? '', 'Test 5.4: post_date reported for the comparison' );

// ─── Test 6: forward-looking dates stay INFO; past dates not flagged ─
echo "\nTest 6: date-coherence calibration\n";
_ci_reset();
_ci_post( 105, array(
	_ci_para( 'The EU AI Act requires machine-readable provenance markings on AI-generated audio as of August 2026.' ),
	_ci_para( 'The genesis anchor confirmed in March 2026, weeks before this note.' ),
), 'publish', '2026-05-15 10:00:00' );
$out   = snt_corpus_integrity_compute();
$dates = _ci_by_check( $out['candidates'], 'date_coherence' );
ci_eq( 1, count( $dates ), 'Test 6.1: only the future date flagged (past date is coherent)' );
ci_eq( 'info', $dates[0]['severity'] ?? '', 'Test 6.2: forward-looking regulatory date with no past-tense verb stays INFO' );

// ─── Test 7: status scope — publish, future, draft, pending ──────────
echo "\nTest 7: status scope\n";
_ci_reset();
_ci_post( 106, array( _ci_para( $splice ) ), 'draft' );
$out = snt_corpus_integrity_compute();
$arg = (array) ( $GLOBALS['__test_get_posts_args']['post_status'] ?? array() );
foreach ( array( 'publish', 'future', 'draft', 'pending' ) as $st ) {
	ci_true( in_array( $st, $arg, true ), "Test 7.x: get_posts walks status={$st}" );
}
ci_eq( 1, count( _ci_by_check( $out['candidates'], 'splice_artifact' ) ), 'Test 7.5: a draft post\'s finding is reported' );

// ─── Test 8: envelope + counts + no-error guarantee + cache split ────
echo "\nTest 8: envelope, purity split, cache\n";
_ci_reset();
_ci_post( 107, array( _ci_para( $splice ) ) );
$computed = snt_corpus_integrity_compute();
ci_true( isset( $computed['counts']['intra_post_duplication'], $computed['counts']['splice_artifact'], $computed['counts']['date_coherence'], $computed['counts']['posts_affected'] ), 'Test 8.1: counts envelope carries all three checks + posts_affected' );
ci_eq( 0, count( $GLOBALS['__test_transients'] ), 'Test 8.2: compute() writes NO transient' );
$ran = snt_corpus_integrity_run_scan();
ci_eq( 1, count( $GLOBALS['__test_transients'] ), 'Test 8.3: run_scan() writes exactly one user-scoped transient' );
ci_eq( json_encode( $ran ), json_encode( snt_corpus_integrity_last_scan() ), 'Test 8.4: last_scan() round-trips run_scan()' );
$sev = array_unique( array_column( $ran['candidates'], 'severity' ) );
ci_true( ! in_array( 'error', $sev, true ), 'Test 8.5: no finding is ever an ERROR (warning/info only)' );

// ─── Test 9: dismiss filter (same shape as sibling scans) ────────────
echo "\nTest 9: dismiss filter\n";
_ci_reset();
_ci_post( 108, array( _ci_para( $splice ) ) );
$first = snt_corpus_integrity_compute();
$key   = ( $first['candidates'][0]['check'] ?? '' ) . ':' . ( $first['candidates'][0]['block_fingerprint'] ?? '' );
$GLOBALS['__test_post_meta'][108]['_snt_corpus_integrity_dismissed'] = array( $key );
$second = snt_corpus_integrity_compute();
ci_eq( 0, count( $second['candidates'] ), 'Test 9.1: dismissed fingerprint excluded on re-scan' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
