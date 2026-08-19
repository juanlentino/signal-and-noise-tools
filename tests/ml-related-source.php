<?php
/**
 * Tests: the related-notes lexical source swap (item 8).
 *
 * Run: php tests/ml-related-source.php
 *
 * The swap replaces ONE term in a blend. These pin the properties that would
 * fail silently: an all-or-nothing fallback, a clamp that stops centred
 * cosine's negative values acting as a penalty, and a public page that no
 * longer claims something untrue.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$root = dirname( __DIR__ );
$art  = (string) file_get_contents( $root . '/inc/ml-artifacts.php' );
$page = (string) file_get_contents( $root . '/inc/ml-maturity-page.php' );
$set  = (string) file_get_contents( $root . '/inc/settings.php' );

echo "Group: the fallback is ALL-OR-NOTHING\n";
// Embedding cosine and TF-IDF cosine have different distributions. A ranking
// mixing both scales is meaningless while every number in it still looks fine,
// so one missing vector must disqualify the whole pass, not just that pair.
ok( false !== strpos( $art, '$use_embed = false;' ), 'one failed vector disables embeddings for the ENTIRE build' );
ok( false !== strpos( $art, '$embed_vectors = array();' ), 'and discards the partial set rather than half-using it' );
ok( false !== strpos( $art, 'break;' ), 'stopping the walk immediately' );
ok( false !== strpos( $art, 'snt_ml_cosine( $vectors[ $a ], $vectors[ $b ] )' ), 'TF-IDF remains the fallback path, not deleted' );

echo "\nGroup: only the LEXICAL term is swapped\n";
ok( false !== strpos( $art, 'snt_ml_graph_signals( $profile[ $a ], $profile[ $b ] )' ), 'tag and link graph signals are untouched' );
ok( false !== strpos( $art, "'lexical'     => 0.55" ), 'and keep their existing weights' );
ok( false !== strpos( $art, 'snt_ml_vec_cosine( $embed_vectors[ $a ], $embed_vectors[ $b ] )' ), 'the embedding cosine feeds the lexical slot' );

echo "\nGroup: centred cosine is SIGNED, and the blend must not read that as a penalty\n";
// Centring produces negative similarities meaning "less alike than average".
// Unclamped, a negative lexical term could drag a well-linked pair below zero
// and delete a relationship the graph signals had earned.
ok( false !== strpos( $art, 'max( 0.0, (float) $lexical )' ), 'negative similarity is clamped to zero, never a penalty' );

echo "\nGroup: the build says which source produced it\n";
ok( false !== strpos( $art, 'snt_ml_last_lexical_source' ), 'the pass stamps its lexical source' );
ok( false !== strpos( $art, "? 'embeddings' : 'tfidf'" ), 'so an embeddings build is distinguishable from a fallback one' );

echo "\nGroup: the swap is reversible without a release\n";
ok( false !== strpos( $set, "'related_source'" ), 'a setting selects the source' );
ok( false !== strpos( $art, "sn_setting( 'ml.related_source', 'embeddings' )" ), 'and the build honours it' );

echo "\nGroup: the public page no longer claims what is no longer true\n";
// These three claims became false the moment a hosted model entered the blend.
ok( false === strpos( $page, 'No neural network' ), 'the "no neural network" claim is GONE' );
ok( false === strpos( $page, 'no weights file' ), 'and the "no weights file" claim' );
ok( false === strpos( $page, 'always produces the same answer out' ), 'and the unconditional determinism claim' );

echo "\nGroup: and it states the dependency plainly instead of omitting it\n";
ok( false !== strpos( $page, 'hosted embedding model' ), 'the model is named as hosted' );
ok( false !== strpos( $page, 'neither hosts nor pins' ), 'and the site says it controls neither' );
ok( false !== strpos( $page, 'not guaranteed to give identical output' ), 'with the determinism caveat stated, not buried' );
// The two properties worth keeping are still true and still claimed.
ok( false !== strpos( $page, 'no reader request ever waits' ), 'while the real guarantee — nothing at read time — survives' );
ok( false !== strpos( $page, 'Ranking runs inside the site' ), 'and ranking still runs here' );

echo "\nGroup: the three NEVERS are untouched\n";
foreach ( array( 'Provenance verdicts', 'Reader profiling', "Models in the reader\\'s browser" ) as $never ) {
	ok( false !== strpos( $page, $never ), "still declared never: $never" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
