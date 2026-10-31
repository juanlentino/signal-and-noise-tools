<?php
/**
 * inc/cite-this.php: the reader's citation (APA and BibTeX) and the Google
 * Scholar head meta, built from the Article's own inputs (the title, the
 * provenance commit date through sn_schema_date_modified(), the production DOI
 * through sn_zenodo_doi_for(), the chain head through sn_zenodo_head_commit()).
 *
 * The escape and the key are ports of the WebMCP bridge's snBibEscape and
 * snCiteKey (sn-rights-signals-worker src/webmcp-bridge-client.mjs); the
 * expected strings below are that module's own output, so a drift on either
 * side shows here.
 *
 * Run: php tests/cite-this.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$GLOBALS['__c'] = array( 'meta' => array(), 'posts' => array(), 'chain' => array(), 'singular' => false, 'queried' => 0, 'actions' => array() );
function add_action( $t, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['__c']['actions'][ $t ][] = array( $cb, $p ); return true; }
function get_option( $k, $d = false ) { return $d; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['__c']['meta'][ $id ][ $k ] ?? ''; }
function get_post( $id = null ) { return $GLOBALS['__c']['posts'][ (int) $id ] ?? null; }
function get_the_title( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? $p->post_title : ''; }
function get_permalink( $p ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? 'https://juanlentino.com/notes/' . $p->post_name . '/' : ''; }
function get_post_time( $f, $gmt, $p ) { return gmdate( $f, strtotime( $p->post_date_gmt . ' UTC' ) ); }
function get_post_modified_time( $f, $gmt, $p ) { return gmdate( $f, strtotime( '2026-10-30 12:00:00 UTC' ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function is_singular( $t = '' ) { return 'post' === $t && $GLOBALS['__c']['singular']; }
function get_queried_object_id() { return (int) $GLOBALS['__c']['queried']; }
function sn_prov_get_chain( $id ) { return $GLOBALS['__c']['chain'][ $id ] ?? array(); }

require __DIR__ . '/../inc/zenodo-client.php';
require __DIR__ . '/../inc/zenodo-records.php';
require __DIR__ . '/../inc/seo-schema-identity-parts.php';
require __DIR__ . '/../inc/cite-this.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }
function mknote( $id, $slug, $title, $published, $commit, $chain, $doi = '', $env = 'production', $extra = array() ) {
	$GLOBALS['__c']['posts'][ $id ] = (object) array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $title, 'post_type' => 'post', 'post_status' => 'publish', 'post_date_gmt' => $published );
	$GLOBALS['__c']['meta'][ $id ]  = array( '_sn_prov_last_commit_gmt' => $commit, SN_ZENODO_DOI_META => $doi, SN_ZENODO_ENV_META => $env ) + $extra;
	$GLOBALS['__c']['chain'][ $id ] = $chain;
}
function head_html() { ob_start(); sn_cite_this_head(); return (string) ob_get_clean(); }

echo "Group A: the ports match the bridge\n";
ok( 'ab\\textbackslash{}c \\& 50\\% \\$x \\#1 \\_y \\textasciitilde{}z \\textasciicircum{}w' === sn_cite_this_bib_escape( 'a{b}\\c & 50% $x #1 _y ~z ^w' ), 'BibTeX escape is snBibEscape byte for byte (braces out, backslash once, specials escaped)' );
ok( 'lentino2026credit' === sn_cite_this_key( '2026', 'The credit that has to stay private' ), 'key skips the stopword "the", as snCiteKey does' );
ok( 'lentino2026why' === sn_cite_this_key( '2026', 'Why C2PA isn’t enough for music' ), 'key drops the non-ASCII apostrophe and keeps the first word' );
ok( 'lentino2026note' === sn_cite_this_key( '2026', 'The' ), 'a title of stopwords only falls back to "note"' );

echo "\nGroup B: a revised note with a DOI\n";
mknote( 7, 'the-credit-that-has-to-stay-private', 'The credit that has to stay private', '2026-10-16 20:08:00', '2026-10-17 01:58:28', array( array( 'version' => 1 ), array( 'version' => 2 ) ), '10.5281/zenodo.23426657', 'production', array( SN_ZENODO_VERSION_META => 2 ) );
$c = sn_cite_this_for( 7 );
ok( is_array( $c ) && 2 === $c['version'] && '2026-10-17' === $c['date'] && '2026-10-16' === $c['published'], 'version is the chain head; date is the version\'s commit (UTC), published stays the publish date' );
ok( 'Lentino, J. (2026, October 17). The credit that has to stay private (Version 2). Signal & Noise. https://doi.org/10.5281/zenodo.23426657' === ( $c['apa'] ?? '' ), 'APA is pinned to the version and its date, and ends with the DOI' );
ok( 'https://doi.org/10.5281/zenodo.23426657' === ( $c['doi_url'] ?? '' ) && '10.5281/zenodo.23426657' === ( $c['doi'] ?? '' ), 'the DOI and its resolver URL' );
$want_bib = "@online{lentino2026credit,\n  author = {Lentino, Juan},\n  title = {The credit that has to stay private},\n  organization = {Signal \\& Noise},\n  date = {2026-10-17},\n  year = {2026},\n  month = {10},\n  version = {2},\n  doi = {10.5281/zenodo.23426657},\n  url = {https://juanlentino.com/notes/the-credit-that-has-to-stay-private/},\n  language = {english}\n}";
ok( $want_bib === ( $c['bibtex'] ?? '' ), 'BibTeX: the bridge\'s @online shape, the key from the publish year, date and version pinned, the DOI' );

echo "\nGroup D: the DOI only when it records the version being read (owner, 2026-10-31, option A)\n";
// Each note is deposited once, so a note revised since cites a DOI for an
// older version. The citation then names the version read, with the permalink.
$two = array( array( 'version' => 0, 'genesis' => true, 'committed_at' => '2026-09-30T10:00:00Z' ), array( 'version' => 1, 'committed_at' => '2026-10-01T10:00:00Z' ), array( 'version' => 2, 'committed_at' => '2026-10-20T10:00:00Z' ) );
mknote( 11, 'deposited-at-v1', 'Deposited at v1', '2026-10-01 10:00:00', '2026-10-20 10:00:00', $two, '10.5281/zenodo.111', 'production', array( SN_ZENODO_AT_META => '2026-10-05T00:00:00+00:00' ) );
ok( 1 === sn_zenodo_doi_version( 11 ), 'a deposit with no recorded version records the chain head at its deposit time (v1)' );
$c = sn_cite_this_for( 11 );
ok( 2 === $c['version'] && '' === $c['doi'] && '' === $c['doi_url'], 'read at v2, deposited at v1: no DOI in the citation' );
ok( str_ends_with( $c['apa'], '(Version 2). Signal & Noise. https://juanlentino.com/notes/deposited-at-v1/' ) && ! str_contains( $c['bibtex'], 'doi =' ), 'APA and BibTeX cite version 2 at its permalink' );
$GLOBALS['__c']['singular'] = true; $GLOBALS['__c']['queried'] = 11;
ok( ! str_contains( head_html(), 'citation_doi' ), 'and the head names no DOI for it' );
mknote( 12, 'deposited-at-v2', 'Deposited at v2', '2026-10-01 10:00:00', '2026-10-20 10:00:00', $two, '10.5281/zenodo.112', 'production', array( SN_ZENODO_AT_META => '2026-10-21T00:00:00+00:00' ) );
ok( 2 === sn_zenodo_doi_version( 12 ) && '10.5281/zenodo.112' === sn_cite_this_for( 12 )['doi'], 'deposited after v2: the DOI records the version read, so it is cited' );
mknote( 13, 'recorded-v1', 'Recorded v1', '2026-10-01 10:00:00', '2026-10-20 10:00:00', $two, '10.5281/zenodo.113', 'production', array( SN_ZENODO_VERSION_META => 1, SN_ZENODO_AT_META => '2026-10-21T00:00:00+00:00' ) );
ok( 1 === sn_zenodo_doi_version( 13 ) && '' === sn_cite_this_for( 13 )['doi'], 'a recorded version wins over the deposit time' );
mknote( 14, 'unknown', 'Unknown deposit', '2026-10-01 10:00:00', '2026-10-20 10:00:00', $two, '10.5281/zenodo.114' );
ok( 0 === sn_zenodo_doi_version( 14 ) && '' === sn_cite_this_for( 14 )['doi'], 'a deposit of unknown version is never cited as the version read' );
$records = (string) file_get_contents( __DIR__ . '/../inc/zenodo-records.php' );
ok( false !== strpos( $records, "update_post_meta( \$post_id, SN_ZENODO_VERSION_META, (int) ( \$ready['commit']['version'] ?? 0 ) );" ), 'a new deposit records the version it deposits' );
$GLOBALS['__c']['queried'] = 7;

echo "\nGroup C: what is missing stays missing\n";
mknote( 8, 'a-remix', 'A remix &#8220;inherits&#8221; nothing &amp; more', '2026-10-24 17:06:20', '2026-10-24 17:06:47', array( array( 'version' => 0, 'genesis' => true ) ) );
$c = sn_cite_this_for( 8 );
ok( '' === $c['doi'] && '' === $c['doi_url'] && str_ends_with( $c['apa'], 'Signal & Noise. https://juanlentino.com/notes/a-remix/' ), 'no DOI: the APA ends with the permalink' );
ok( ! str_contains( $c['apa'], '(Version' ) && ! str_contains( $c['bibtex'], 'version =' ) && ! str_contains( $c['bibtex'], 'doi =' ), 'founding snapshot only (version 0): no version is claimed, no doi line' );
ok( str_contains( $c['apa'], '). A remix “inherits” nothing & more. Signal' ), 'the title is decoded text, not entities' );
ok( str_contains( $c['bibtex'], 'title = {A remix “inherits” nothing \\& more}' ), 'the BibTeX title is decoded, then escaped' );
mknote( 9, 'sandbox', 'Sandbox', '2026-10-01 00:00:00', '2026-10-02 00:00:00', array( array( 'version' => 1 ) ), '10.5072/zenodo.1', 'sandbox' );
ok( '' === sn_cite_this_for( 9 )['doi'], 'a sandbox DOI never reaches the citation (sn_zenodo_doi_for decides)' );
mknote( 10, 'unsigned', 'Unsigned', '2026-10-01 00:00:00', '', array() );
$c = sn_cite_this_for( 10 );
ok( 0 === $c['version'] && '2026-10-30' === $c['date'], 'no chain: no version, and the date is the Article\'s dateModified fallback' );
ok( null === sn_cite_this_for( 404 ), 'no post: null' );

echo "\nGroup D: the Scholar head meta\n";
$GLOBALS['__c']['singular'] = true; $GLOBALS['__c']['queried'] = 7;
$h = head_html();
ok( str_contains( $h, '<meta name="citation_title" content="The credit that has to stay private">' ), 'citation_title' );
ok( str_contains( $h, '<meta name="citation_author" content="Lentino, Juan">' ), 'citation_author, surname first' );
ok( str_contains( $h, '<meta name="citation_publication_date" content="2026/10/16">' ), 'citation_publication_date: the publish date, Scholar\'s YYYY/MM/DD' );
ok( str_contains( $h, '<meta name="citation_doi" content="10.5281/zenodo.23426657">' ), 'citation_doi' );
$GLOBALS['__c']['queried'] = 8;
$h = head_html();
ok( str_contains( $h, 'citation_title' ) && ! str_contains( $h, 'citation_doi' ), 'no DOI: no citation_doi tag, the rest stays' );
ok( str_contains( $h, 'content="A remix “inherits” nothing &amp; more"' ), 'attribute values are escaped once' );
$GLOBALS['__c']['singular'] = false;
ok( '' === head_html(), 'off a single note: nothing in the head' );
$hooked = array_filter( $GLOBALS['__c']['actions']['wp_head'] ?? array(), static fn( $a ) => 'sn_cite_this_head' === $a[0] );
ok( 1 === count( $hooked ), 'sn_cite_this_head is hooked on wp_head' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
