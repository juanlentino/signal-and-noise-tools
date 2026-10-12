<?php
/**
 * Dismissing one scan candidate keeps the rest of the cached scan.
 *
 * 2026-10-12 audit: pattern adoption, block migrations and corpus integrity
 * each deleted the user's whole scan transient on dismiss, so the page fell
 * back to "no scan yet" and every other candidate disappeared until the next
 * scan. The real dismiss impls run here against a real cached envelope.
 *
 * Run: php tests/scan-dismiss-keeps-the-scan.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['meta'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['ttl'] = array();
function add_action() { return true; }
function add_filter() { return true; }
function __( $s ) { return $s; }
function get_current_user_id() { return 7; }
function get_post_meta( $id, $k ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; $GLOBALS['ttl'][ $k ] = $t; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
class WP_Error { public function __construct( $c = '', $m = '' ) {} }
$inc = dirname( __DIR__ ) . '/inc/';
require $inc . 'block-fingerprint-engine.php';
foreach ( array( 'pattern-adoption-detect.php', 'pattern-adoption-admin.php', 'block-migrations-detect.php', 'abilities-block-migrations.php', 'corpus-integrity-scan.php' ) as $f ) {
	require_once $inc . $f;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$cases = array(
	'pattern adoption'  => array( 'snt_pattern_adoption_candidates_7', 'pattern_type', 'pull-quote', 'steps-enumerated', array( 'pull_quote' => 2, 'steps_enumerated' => 1, 'posts_affected' => 2 ), static fn( $p, $t, $fp ) => snt_pattern_adoption_dismiss_impl( $p, $t, $fp ) ),
	'block migrations'  => array( 'snt_block_migrations_candidates_7', 'migration_type', 'heading-hierarchy-skip', 'heading-hierarchy-skip', array( 'heading_hierarchy_skip' => 3, 'posts_affected' => 2 ), static fn( $p, $t, $fp ) => snt_block_migrations_dismiss_impl( $p, $fp, $t ) ),
	'corpus integrity'  => array( 'snt_corpus_integrity_candidates_7', 'check', 'splice_artifact', 'date_coherence', array( 'intra_post_duplication' => 0, 'splice_artifact' => 2, 'date_coherence' => 1, 'posts_affected' => 2, 'corrections' => 4 ), static fn( $p, $t, $fp ) => snt_corpus_integrity_dismiss_impl( $p, $fp, $t ) ),
);
foreach ( $cases as $name => list( $key, $field, $a, $b, $counts, $dismiss ) ) {
	$GLOBALS['tr'][ $key ] = array(
		'candidates' => array(
			array( 'post_id' => 10, $field => $a, 'block_fingerprint' => 'fp1' ),
			array( 'post_id' => 11, $field => $a, 'block_fingerprint' => 'fp2' ),
			array( 'post_id' => 11, $field => $b, 'block_fingerprint' => 'fp3' ),
		),
		'counts'     => $counts,
		'scanned_at' => time() - 600,
	);
	$dismiss( 10, $a, 'fp1' );
	$scan = $GLOBALS['tr'][ $key ] ?? null;
	ok( is_array( $scan ), "$name: the cached scan survives a dismiss (the page does not fall back to no scan yet)" );
	ok( is_array( $scan ) && array( 'fp2', 'fp3' ) === array_column( $scan['candidates'], 'block_fingerprint' ), "$name: only the dismissed candidate leaves the list" );
	$k = str_replace( '-', '_', $a );
	ok( is_array( $scan ) && $counts[ $k ] - 1 === $scan['counts'][ $k ] && 1 === $scan['counts']['posts_affected'], "$name: its type count drops by one and posts affected is recounted" );
	ok( ! isset( $counts['corrections'] ) || ( is_array( $scan ) && 4 === $scan['counts']['corrections'] ), "$name: a count that is not about candidates is kept" );
	ok( isset( $GLOBALS['ttl'][ $key ] ) && $GLOBALS['ttl'][ $key ] <= 3000 && $GLOBALS['ttl'][ $key ] > 2900, "$name: the cache keeps its original expiry, not a fresh hour" );
}

// PR #2014 review: corpus integrity can raise two checks on one block (same
// post, same fingerprint); dismissing one must leave the other.
$GLOBALS['tr']['snt_corpus_integrity_candidates_7'] = array(
	'candidates' => array(
		array( 'post_id' => 20, 'check' => 'splice_artifact', 'block_fingerprint' => 'fpX' ),
		array( 'post_id' => 20, 'check' => 'date_coherence', 'block_fingerprint' => 'fpX' ),
	),
	'counts'     => array( 'intra_post_duplication' => 0, 'splice_artifact' => 1, 'date_coherence' => 1, 'posts_affected' => 1, 'corrections' => 0 ),
	'scanned_at' => time(),
);
snt_corpus_integrity_dismiss_impl( 20, 'fpX', 'splice_artifact' );
$same = $GLOBALS['tr']['snt_corpus_integrity_candidates_7'];
ok( array( 'date_coherence' ) === array_column( $same['candidates'], 'check' ) && 1 === $same['counts']['date_coherence'] && 1 === $same['counts']['posts_affected'], 'two checks on one block: dismissing one keeps the other (the match reads the type too)' );

$GLOBALS['tr']['snt_pattern_adoption_candidates_7'] = array( 'stale' => true );
snt_pattern_adoption_dismiss_impl( 10, 'pull-quote', 'fp9' );
ok( ! isset( $GLOBALS['tr']['snt_pattern_adoption_candidates_7'] ), 'a cache that is not a scan envelope is still dropped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
