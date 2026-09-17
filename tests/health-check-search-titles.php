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
ok( true === sn_health_search_title_is_shaped( 'The estate cannot sign: key succession and music provenance' ), 'the ranking shape passes: aphorism, colon, plain words' );
ok( false === sn_health_search_title_is_shaped( '' ), 'no override is not shaped' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance' ), 'an override that repeats the aphorism is not shaped' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance:' ), 'a colon with nothing after it is not shaped' );
ok( false === sn_health_search_title_is_shaped( 'Two kinds of provenance: provenance' ), 'a one-word subtitle is not shaped (two words minimum)' );
ok( false === sn_health_search_title_is_shaped( 'Fingerprints, not name tags' ), 'a comma is not the separator' );
ok( true === sn_health_search_title_is_shaped( '  Five layers, one system: ISRC, ISWC and music identifiers  ' ), 'whitespace around the override is ignored' );

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
ok( false !== strpos( $f[0]['note'], 'No SEO title' ) && false !== strpos( $f[1]['note'], 'no subtitle' ), 'THE PIN: no override and an override without a subtitle get different notes (different repairs)' );
ok( array() === sn_health_search_titles_judge( array() ) && array() === sn_health_search_titles_judge( 'nope' ), 'no rows, or a non-array, judges to no findings' );

echo "\nGroup 3: the check through the query\n";
$GLOBALS['wpdb'] = new SN_Fake_Wpdb();
$GLOBALS['wpdb']->rows = array( array( 'ID' => 7, 'post_title' => 'Nobody signs a live take', 'seo_title' => '' ) );
$r = sn_health_check_search_titles();
ok( 1 === $r['count'] && null === $r['skipped'], 'one bare note is one finding, and the check RAN' );
ok( 'https://x.test/notes/7/' === $r['findings'][0]['subject_url'] && false !== strpos( $r['findings'][0]['edit_url'], 'post=7' ), 'the query row gains its permalink and edit link' );
ok( false !== strpos( $GLOBALS['wpdb']->sql, "post_status = 'publish'" ) && false !== strpos( $GLOBALS['wpdb']->sql, "post_type = 'post'" ) && false !== strpos( $GLOBALS['wpdb']->sql, '_sn_seo_title' ), 'the query reads published posts and the override meta' );
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
