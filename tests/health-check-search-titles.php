<?php
/**
 * inc/health-check-search-titles.php (15.9.0): notes whose title tag is the
 * aphorism alone. Pure judge + the three registries.
 *
 * Run: php tests/health-check-search-titles.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}
function get_permalink( $id ) { return "https://x.test/notes/$id/"; }
function admin_url( $p ) { return 'https://x.test/wp-admin/' . $p; }
class SN_Fake_Wpdb { public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $rows; public $sql = '';
	public function get_results( $sql, $out = null ) { $this->sql = $sql; return $this->rows; } }
require_once __DIR__ . '/../inc/health-check-search-titles.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "health-check-search-titles — 15.9.0\n\nGroup 1: what counts as query-shaped\n";
$h1 = 'Two kinds of provenance';
ok( true === sn_health_search_title_is_shaped( 'The estate cannot sign: key succession and music provenance', 'The estate cannot sign' ), 'the ranking shape passes: aphorism, colon, plain words' );
ok( true === sn_health_search_title_is_shaped( 'Why provenance records do not survive lossy encoding', 'The guarantee ends at the codec' ), 'a plain alternative title written in full passes (the owner\'s own shape on three scheduled notes)' );
ok( false === sn_health_search_title_is_shaped( '', $h1 ), 'no override is not shaped' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance', $h1 ), 'an override that repeats the aphorism is not shaped' );
ok( false === sn_health_search_title_is_shaped( '  two kinds of  provenance ', $h1 ), 'nor when it differs only in case and spacing' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance:', $h1 ), 'a colon with nothing after it is not shaped' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance: provenance', $h1 ), 'a one-word subtitle is not shaped (two words minimum)' );
ok( true === sn_health_search_title_is_shaped( 'Two kinds of provenance: authorship vs behavioral', $h1 ), 'two words after the colon is enough' );
ok( false === sn_health_search_title_is_shaped( 'Provenance', $h1 ), 'a single word is not a title for search' );
ok( true === sn_health_search_title_is_shaped( '  Five layers, one system: ISRC, ISWC and music identifiers  ', 'Five layers, one system' ), 'whitespace around the override is ignored' );

echo "\nGroup 1b: a title that is itself the query (16.3.1)\n";
$q = 'Where AI actually saves time in record production';
ok( true === sn_health_title_is_query_shaped( $q ) && true === sn_health_title_is_query_shaped( 'How a music file gets corrected' ), 'a title opening with a searcher\'s word, six or more words, no colon, no stop, is a query' );
ok( false === sn_health_title_is_query_shaped( 'Payment systems pay what they can name' ) && false === sn_health_title_is_query_shaped( 'The pen is not the notary' ) && false === sn_health_title_is_query_shaped( 'Who vouches for the independent artist?' ) && false === sn_health_title_is_query_shaped( 'Why platforms wait: streaming' ) && false === sn_health_title_is_query_shaped( 'Why wait' ), 'seven aphoristic words, a question mark, a colon, or too few words: not a query (word count alone cannot separate these)' );
ok( true === sn_health_search_title_is_shaped( '', $q ) && true === sn_health_search_title_is_shaped( $q, $q ), 'THE PIN: a query-shaped title passes with no override AND with an override equal to itself (it was flagged both ways)' );
ok( false === sn_health_search_title_is_shaped( '', 'Two kinds of provenance' ) && false === sn_health_search_title_is_shaped( 'Two kinds of provenance', 'Two kinds of provenance' ), 'an aphorism still needs its second name' );

echo "\nGroup 2: the judge\n";
$rows = array(
	array( 'ID' => 1, 'post_title' => 'The estate cannot sign', 'seo_title' => 'The estate cannot sign: key succession and music provenance', 'permalink' => 'https://x.test/notes/1/', 'edit_url' => 'e1' ),
	array( 'ID' => 2, 'post_title' => 'Two kinds of provenance', 'seo_title' => '', 'permalink' => 'https://x.test/notes/2/', 'edit_url' => 'e2' ),
	array( 'ID' => 3, 'post_title' => 'The pen is not the notary', 'seo_title' => 'The pen is not the notary', 'permalink' => 'https://x.test/notes/3/', 'edit_url' => 'e3' ),
	'junk',
);
$f = sn_health_search_titles_judge( $rows );
ok( 2 === count( $f ), 'two of three notes are findings; the shaped one is not; junk is dropped' );
ok( array( 2, 3 ) === array_column( $f, 'subject_id' ), 'findings keep the row order and carry the post id' );
ok( 'Two kinds of provenance' === $f[0]['subject_label'] && 'https://x.test/notes/2/' === $f[0]['subject_url'] && 'e2' === $f[0]['edit_url'], 'a finding carries label, url and edit link' );
ok( false !== strpos( $f[0]['note'], 'No SEO title' ) && false !== strpos( $f[1]['note'], 'repeats the aphorism' ), 'THE PIN: no override and an override that repeats the aphorism get different notes (different repairs)' );
ok( array() === sn_health_search_titles_judge( array() ) && array() === sn_health_search_titles_judge( 'nope' ), 'no rows, or a non-array, judges to no findings' );

echo "\nGroup 2b: the ceiling (16.1.2)\n";
ok( 65 === SN_HEALTH_SEARCH_TITLE_MAX, 'the ceiling is 65: Bing flags past 70, Google shows about 60' );
ok( 89 === sn_health_search_title_length( 'Music provenance: cryptographic authorship records instead of AI detection — Juan Lentino' ), 'length counts characters, not bytes (the em dash is one; wc -c said 91)' );
$long  = 'Payment systems pay what they can name: music royalties and missing credits';
$short = 'Payment systems pay what they can name: royalties and credits';
ok( sn_health_search_title_is_long( $long ) && ! sn_health_search_title_is_long( $short ) && ! sn_health_search_title_is_long( str_repeat( 'a', 65 ) ) && sn_health_search_title_is_long( str_repeat( 'a', 66 ) ), '75 is long, 61 is not; 65 passes, 66 does not' );
$f = sn_health_search_titles_judge( array(
	array( 'ID' => 4, 'post_title' => 'Payment systems pay what they can name', 'seo_title' => $long, 'permalink' => 'p4', 'edit_url' => 'e4' ),
	array( 'ID' => 5, 'post_title' => 'Provenance As Substrate', 'post_type' => 'page', 'seo_title' => 'Provenance As Substrate: cryptographic identity for music files and rights', 'permalink' => 'p5', 'edit_url' => 'e5' ),
	array( 'ID' => 6, 'post_title' => 'Contact', 'post_type' => 'page', 'seo_title' => '', 'permalink' => 'p6', 'edit_url' => 'e6' ),
	array( 'ID' => 7, 'post_title' => 'Two kinds of provenance', 'seo_title' => 'Two kinds of provenance', 'permalink' => 'p7', 'edit_url' => 'e7' ),
) );
ok( array( 4, 5, 7 ) === array_column( $f, 'subject_id' ), 'a long note, a long page override and an unshaped note are findings; a page WITHOUT an override is not' );
ok( false !== strpos( $f[0]['note'], '75 characters' ) && 'page' === $f[1]['subject_type'] && false !== strpos( $f[2]['note'], 'repeats the aphorism' ), 'the length note says the count; a page finding says page; the shape note is unchanged' );

echo "\nGroup 3: the check through the query\n";
$GLOBALS['wpdb'] = new SN_Fake_Wpdb();
$GLOBALS['wpdb']->rows = array( array( 'ID' => 7, 'post_title' => 'Nobody signs a live take', 'seo_title' => '' ) );
$r = sn_health_check_search_titles();
ok( 1 === $r['count'] && null === $r['skipped'], 'one bare note is one finding, and the check RAN' );
ok( 'https://x.test/notes/7/' === $r['findings'][0]['subject_url'] && false !== strpos( $r['findings'][0]['edit_url'], 'post=7' ), 'the query row gains its permalink and edit link' );
ok( false !== strpos( $GLOBALS['wpdb']->sql, "post_status IN ( 'publish', 'future' )" ) && false !== strpos( $GLOBALS['wpdb']->sql, "post_type = 'post' OR ( p.post_type = 'page' AND pm.meta_value IS NOT NULL )" ) && false !== strpos( $GLOBALS['wpdb']->sql, '_sn_seo_title' ), 'the query reads published AND scheduled posts, plus pages that carry an override, and the override meta (a note carries its title before it goes out)' );
ok( false !== strpos( $r['fix_hint'], 'H1 stays the aphorism' ), 'the fix hint says the voice is untouched' );
$GLOBALS['wpdb']->rows = null;
$r = sn_health_check_search_titles();
ok( 0 === $r['count'] && is_string( $r['skipped'] ), 'a failed read is SKIPPED, never a pass' );

echo "\nGroup 4: the three registries\n";
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-checks.php' ), "'search_titles'" ), 'the scan runs it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-surfaces.php' ), "'search_titles'" ), 'a surface owns it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/inc/health-check-families.php' ), "'search_titles'" ), 'a family maps it' );
ok( false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/signal-and-noise-tools.php' ), "health-check-search-titles.php" ), 'the loader requires it' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
