<?php
/**
 * Tests: semantic embeddings in SHADOW mode (item 8, slice 1).
 *
 * Run: php tests/ml-embeddings.php
 *
 * The instrument has to be trustworthy before its verdict means anything, so
 * the load-bearing tests here are about REFUSAL and DETERMINISM, not about
 * whether embeddings are good.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function close_to( $a, $b, $e = 0.0001 ) { return abs( (float) $a - (float) $b ) < $e; }

function __( $s, $d = null ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function sn_setting( $p, $d = null ) { return $GLOBALS['__set'][ $p ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_the_title( $p ) { return $GLOBALS['__posts'][ $p ]['title'] ?? ''; }
function get_post_field( $f, $p ) { return $GLOBALS['__posts'][ $p ]['content'] ?? ''; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ $id ][ $k ] = $v; return true; }
class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
$GLOBALS['__http'] = null;
function wp_remote_post( $url, $args ) { $GLOBALS['__last_url'] = $url; $GLOBALS['__last_args'] = $args; return $GLOBALS['__http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require __DIR__ . '/../inc/ml-embeddings.php';
require __DIR__ . '/../inc/ml-embeddings-compare.php';

echo "Group: cosine is real arithmetic, not an assumption about unit vectors\n";
ok( close_to( snt_ml_vec_cosine( array( 1, 0 ), array( 1, 0 ) ), 1.0 ), 'identical vectors -> 1' );
ok( close_to( snt_ml_vec_cosine( array( 1, 0 ), array( 0, 1 ) ), 0.0 ), 'orthogonal -> 0' );
ok( close_to( snt_ml_vec_cosine( array( 1, 0 ), array( -1, 0 ) ), -1.0 ), 'opposite -> -1' );
// A NON-unit pair: if the denominator were skipped (assuming normalised bge
// output), this would return 10, not 1 — silently inflating every score.
ok( close_to( snt_ml_vec_cosine( array( 3, 4 ), array( 6, 8 ) ), 1.0 ), 'parallel NON-unit vectors -> 1, so the magnitude denominator is really applied' );
ok( 0.0 === snt_ml_vec_cosine( array( 0, 0 ), array( 1, 1 ) ), 'zero magnitude -> 0, never a division by zero' );
ok( 0.0 === snt_ml_vec_cosine( array( 1, 2, 3 ), array( 1, 2 ) ), 'mismatched dimensions -> 0, never a partial compare' );
ok( 0.0 === snt_ml_vec_cosine( array(), array() ), 'empty -> 0' );

echo "\nGroup: the API client REFUSES rather than mispairs\n";
$GLOBALS['__set']['ml.embeddings_token'] = '';
ok( is_wp_error( snt_ml_embed( array( 'x' ) ) ), 'unconfigured -> WP_Error, never a silent empty result' );

$GLOBALS['__set']['ml.embeddings_token'] = 'tok';
define( 'SN_CF_ACCOUNT_ID', 'acct123' );

// THE ONE THAT MATTERS: a count mismatch would shift every vector onto the
// wrong note, and every downstream score would still look plausible.
$GLOBALS['__http'] = array( 'code' => 200, 'body' => json_encode( array( 'result' => array( 'data' => array( array( 0.1, 0.2 ) ) ) ) ) );
$r = snt_ml_embed( array( 'a', 'b' ) );
ok( is_wp_error( $r ) && 'snt_ml_embed_shape' === $r->get_error_code(), '1 vector for 2 texts -> refuses to pair them' );
ok( false !== strpos( $r->get_error_message(), 'refusing' ), 'and says so plainly' );

$GLOBALS['__http'] = array( 'code' => 200, 'body' => json_encode( array( 'result' => array( 'data' => array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ) ) ) ) );
$r = snt_ml_embed( array( 'a', 'b' ) );
ok( is_array( $r ) && 2 === count( $r ), 'matching counts -> vectors returned' );
ok( close_to( $r[1][0], 0.3 ), 'in INPUT ORDER — the pairing the API promises' );

$GLOBALS['__http'] = array( 'code' => 403, 'body' => json_encode( array( 'errors' => array( array( 'message' => 'no AI permission' ) ) ) ) );
$r = snt_ml_embed( array( 'a' ) );
ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'no AI permission' ), "an API error carries Cloudflare's own message" );

$GLOBALS['__http'] = array( 'code' => 200, 'body' => json_encode( array( 'result' => array( 'data' => array( array( 1, 0 ) ) ) ) ) );
ok( array() === snt_ml_embed( array( '', '   ' ) ), 'all-empty input -> empty result without calling out' );

echo "\nGroup: the cache keys on CONTENT and MODEL\n";
$GLOBALS['__posts'][7] = array( 'title' => 'T', 'content' => 'body text' );
$GLOBALS['__http'] = array( 'code' => 200, 'body' => json_encode( array( 'result' => array( 'data' => array( array( 0.5, 0.5 ) ) ) ) ) );
$v1 = snt_ml_embedding_for_post( 7, 'hash-a' );
ok( is_array( $v1 ), 'first call embeds' );
$GLOBALS['__http'] = array( 'code' => 500, 'body' => '' ); // any live call now fails
$v2 = snt_ml_embedding_for_post( 7, 'hash-a' );
ok( $v1 === $v2, 'same content hash -> served from cache (the 500 proves no call was made)' );
$v3 = snt_ml_embedding_for_post( 7, 'hash-b' );
ok( is_wp_error( $v3 ), 'a CHANGED content hash re-embeds — an edited note must not keep a stale vector' );

echo "\nGroup: ranking is deterministic\n";
$vectors = array( 1 => array( 1, 0 ), 2 => array( 0.9, 0.1 ), 3 => array( 0, 1 ), 4 => array( 0.9, 0.1 ) );
$rank = snt_ml_embed_rank( $vectors, 1, 3 );
ok( 3 === count( $rank ), 'returns the requested depth' );
ok( 1 !== $rank[0]['post_id'], 'never ranks the post against itself' );
ok( array( 2, 4 ) === array( $rank[0]['post_id'], $rank[1]['post_id'] ), 'ties break on post_id, so a rerun is byte-identical' );
ok( $rank[0]['score'] >= $rank[2]['score'], 'sorted descending' );

echo "\nGroup: the diff reports DISAGREEMENT, which is the whole evidence\n";
$d = snt_ml_embed_diff( array( 1, 2, 3 ), array( 2, 3, 9 ) );
ok( 2 === $d['overlap'], 'counts agreement' );
ok( array( 9 ) === $d['only_embedding'], 'names what ONLY embeddings found — the load-bearing column' );
ok( array( 1 ) === $d['only_tfidf'], 'and what only TF-IDF found' );

$identical = snt_ml_embed_diff( array( 1, 2 ), array( 1, 2 ) );
ok( array() === $identical['only_embedding'], 'total agreement -> nothing only-embedding' );

echo "\nGroup: the summary can report a NULL result honestly\n";
// If item 8's premise is false for this corpus, divergence is 0 and the
// instrument must say so rather than manufacture a reason to adopt.
$s = snt_ml_embed_summary( array( $identical, $identical ) );
ok( 0.0 === $s['divergence'], 'perfect agreement -> divergence 0.0, a real answer meaning "TF-IDF already found these"' );
ok( 4 === $s['ranked_slots'] && 4 === $s['agreed'], 'and the totals reconcile' );

$s2 = snt_ml_embed_summary( array( $d, $d ) );
ok( close_to( $s2['divergence'], 2 / 6 ), 'divergence is only_embedding over ranked slots' );
ok( $s2['posts'] === 2, 'posts counted' );

echo "\nGroup: nothing here is wired into what the site serves\n";
$kernel = (string) file_get_contents( __DIR__ . '/../inc/ml-artifacts.php' );
ok( false === strpos( $kernel, 'snt_ml_embedding_for_post' ), 'the artifact build does NOT call embeddings — shadow mode, by construction' );
ok( false === strpos( $kernel, 'snt_ml_vec_cosine' ), 'and does not use embedding cosine' );
$page = (string) file_get_contents( __DIR__ . '/../inc/ml-maturity-page.php' );
ok( false !== strpos( $page, 'No neural network' ), 'the public page still claims no neural network — TRUE while this stays shadow, and the claim that must change before any swap' );

echo "\nGroup: centering removes the shared mass\n";
// A corpus that is one argument restated shares a large common component.
// Three vectors that all lean the same way plus one that does not:
$corpus = array( 1 => array( 1.0, 0.1 ), 2 => array( 1.0, 0.2 ), 3 => array( 1.0, -0.3 ) );
$c = snt_ml_vec_centroid( $corpus );
ok( close_to( $c[0], 1.0 ), 'centroid averages the shared direction' );
ok( close_to( $c[1], 0.0 ), 'and the differing one' );
$centred = snt_ml_vec_center_all( $corpus );
ok( close_to( $centred[1][0], 0.0 ), 'centering removes the shared component entirely' );
// THE POINT: before centering these three are near-identical (all dominated by
// the shared axis); after, only what distinguishes them remains.
$raw_sim = snt_ml_vec_cosine( $corpus[1], $corpus[3] );
$ctr_sim = snt_ml_vec_cosine( $centred[1], $centred[3] );
ok( $raw_sim > 0.9, 'raw cosine calls two notes on the same subject nearly identical (' . round( $raw_sim, 3 ) . ')' );
ok( $ctr_sim < $raw_sim, 'centred cosine separates them — the shared subject no longer dominates' );
ok( array() === snt_ml_vec_centroid( array() ), 'empty corpus -> empty centroid, never a divide by zero' );
$mixed = snt_ml_vec_center_all( array( 1 => array( 1, 2 ), 2 => array( 1, 2, 3 ) ) );
ok( array( 1, 2, 3 ) === $mixed[2], 'a foreign-dimension vector is left UNTOUCHED rather than corrupted' );

echo "\nGroup: hub stats make hubness measurable\n";
// The metric the first run lacked: divergence said 59.4% and could not see
// that one note occupied half the results.
$hubby = array( 1 => array( 9, 2 ), 2 => array( 9, 3 ), 3 => array( 9, 4 ), 4 => array( 9, 5 ) );
$h = snt_ml_embed_hub_stats( $hubby );
ok( 9 === $h['top_target'], 'names the hub' );
ok( 4 === $h['top_count'] && 1.0 === $h['hub_share'], 'and reports it appearing for every source' );
$even = array( 1 => array( 2, 3 ), 2 => array( 3, 4 ), 3 => array( 4, 5 ) );
ok( snt_ml_embed_hub_stats( $even )['hub_share'] < 1.0, 'an even spread scores lower' );
// distinct_targets alone does NOT discriminate — it depends on the fixture, and
// this one has FEWER distinct targets while being far less hubby. hub_share is
// the metric that separates them, which is why it is the one on screen.
ok( 4 === snt_ml_embed_hub_stats( $even )['distinct_targets'], 'distinct targets is counted accurately (4 here)' );
ok( 5 === $h['distinct_targets'], 'and 5 for the hubby fixture — MORE, despite being worse: the count is context, not a verdict' );
ok( snt_ml_embed_hub_stats( $even )['hub_share'] < $h['hub_share'], 'hub_share is what actually separates the two' );

echo "\nGroup: mutual k-NN breaks the asymmetry hubness is made of\n";
// 9 is everyone's neighbour; 9 does not point back at everyone.
$ranked = array( 1 => array( 9, 2 ), 2 => array( 9, 1 ), 9 => array( 1, 2 ) );
$mut = snt_ml_embed_mutual( $ranked );
ok( in_array( 9, $mut[1], true ), '9 survives for 1, because 9 points back at 1' );
ok( in_array( 2, $mut[1], true ), 'and the reciprocated 1<->2 pair survives' );
$one_way = array( 1 => array( 9 ), 2 => array( 9 ), 9 => array( 1 ) );
$mut2 = snt_ml_embed_mutual( $one_way );
ok( array() === $mut2[2], 'a ONE-WAY link to the hub is dropped — 9 never points back at 2' );
ok( array( 9 ) === $mut2[1], 'while the reciprocated one is kept' );

echo "\nGroup: the three status roles are distinct\n";
$cmp_src = (string) file_get_contents( __DIR__ . '/../inc/ml-embeddings-compare.php' );
ok( false !== strpos( $cmp_src, "array( 'publish', 'future' )" ), 'the CENTROID spans published AND scheduled — 22 unseen notes move exactly that' );
ok( false !== strpos( $cmp_src, 'scheduled_in_centroid' ), 'and the scope is reported, so 33-of-55 can never be silent again' );
ok( false !== strpos( $cmp_src, '$vec_pub' ), 'while ranking stays within the published subset' );

echo "\nGroup: the instrument has a RUNNER, not just parts\n";
// v11.22.0 shipped rank/diff/summary with NOTHING calling them: the comparison
// existed and could not be read. Same shape as the missing token control.
$cmp     = (string) file_get_contents( __DIR__ . '/../inc/ml-embeddings-compare.php' );
$handler = (string) file_get_contents( __DIR__ . '/../inc/admin-post-actions.php' );
$router  = (string) file_get_contents( __DIR__ . '/../inc/admin-post-handler.php' );
$form    = (string) file_get_contents( __DIR__ . '/../inc/admin-forms/ai-settings.php' );
ok( false !== strpos( $cmp, 'function snt_ml_embedding_compare_corpus' ), 'the corpus orchestrator exists' );
ok( false !== strpos( $cmp, 'snt_ml_related_for_post' ), 'and compares against the SHIPPED artifact, not a fresh reimplementation of TF-IDF' );
ok( false !== strpos( $cmp, 'snt_corpus_content_hash' ), 'and keys the cache on the CANONICAL hash, so two definitions of "changed" cannot drift' );
ok( false !== strpos( $handler, 'function sn_handle_ml_embed_compare' ), 'a handler runs it' );
ok( false !== strpos( $router, "'ml_embed_compare'" ), 'the router reaches the handler' );
ok( false !== strpos( $form, 'value="ml_embed_compare"' ), 'and a button reaches the router' );
ok( false !== strpos( $form, 'No divergence at all' ), 'the readout can report a NULL result as a real answer, not as a failure' );

echo "\nGroup: the token has a CONTROL, not just a leaf\n";
// v11.22.0 shipped the setting with no way to set it — the v10.84.0 failure
// repeated. These assertions exist so it cannot ship that way again.
$form    = (string) file_get_contents( __DIR__ . '/../inc/admin-forms/ai-settings.php' );
$handler = (string) file_get_contents( __DIR__ . '/../inc/admin-post-actions.php' );
ok( false !== strpos( $form, 'sn_ml_embeddings_token' ), 'the AI settings form renders a field for the token' );
ok( false !== strpos( $form, 'sn_mask_secret' ), 'and masks it rather than echoing the secret back' );
ok( false !== strpos( $form, 'Workers AI' ) && false !== strpos( $form, 'Read' ), 'and names the exact permission (ACCOUNT scope, Workers AI, Read) — the step that is easy to get wrong' );
ok( false !== strpos( $handler, "sn_setting_update( 'ml.embeddings_token'" ), 'the save handler writes the leaf' );
ok( false !== strpos( $handler, "0 !== strpos( \$embed, '••••' )" ), 'and IGNORES an un-edited mask, so saving the form never overwrites the real token with dots' );
ok( false !== strpos( $handler, "'clear' === strtolower( \$embed )" ), 'with an explicit clear sentinel' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
