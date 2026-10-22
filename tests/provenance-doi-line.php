<?php
/**
 * The DOI downloads proof line on /provenance: the existing Zenodo sync keeps
 * a site-only total per day (records whose DOI is a site document's), and the
 * public line reads only stored snapshots, hiding rather than guessing.
 *
 * Run: php tests/provenance-doi-line.php
 */

define( 'ABSPATH', __DIR__ . '/' );
const SN_ZENODO_ORCID     = '0009-0006-8151-5920';
const SN_ZENODO_DOI_META  = '_sn_zenodo_doi';
const SN_PROVENANCE_SLUG  = 'provenance';
$GLOBALS['__opt']   = array();
$GLOBALS['__calls'] = array();
$GLOBALS['__pages'] = array();
$GLOBALS['__site']  = array();
$GLOBALS['__ctx']   = array( 'page' => true, 'main' => true, 'loop' => true, 'feed' => false );
$GLOBALS['__purged'] = array();
function __( $s ) { return $s; }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function add_action() {}
function add_filter() {}
function wp_next_scheduled() { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function is_wp_error( $x ) { return false; }
function wp_remote_get( $url ) {
	$GLOBALS['__calls'][] = $url;
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return $GLOBALS['__pages'][ (int) $q['page'] ] ?? array( 'hits' => array( 'total' => 0, 'hits' => array() ) );
}
function wp_remote_retrieve_response_code() { return 200; }
function wp_remote_retrieve_body( $r ) { return json_encode( $r ); }
function get_posts( $args ) { return array_keys( $GLOBALS['__site'] ); }
function sn_zenodo_doi_for( $id ) { return $GLOBALS['__site'][ $id ] ?? ''; }
function is_page( $slug ) { return $GLOBALS['__ctx']['page'] && SN_PROVENANCE_SLUG === $slug; }
function is_main_query() { return $GLOBALS['__ctx']['main']; }
function in_the_loop() { return $GLOBALS['__ctx']['loop']; }
function is_feed() { return $GLOBALS['__ctx']['feed']; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function sn_cf_purge_urls( $urls ) { $GLOBALS['__purged'][] = $urls; return true; }

require __DIR__ . '/../inc/north-star-return.php';
require __DIR__ . '/../inc/provenance-doi-line.php';
require __DIR__ . '/../inc/admin-post-actions/zenodo.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function hit( $id, $doi, $dl ) { return array( 'id' => $id, 'doi' => $doi, 'stats' => array( 'unique_downloads' => $dl, 'unique_views' => 1 ) ); }

echo "Provenance DOI downloads line\n\n";

// ── The fetch: every page walked with one stable query, per-record figures kept, a repeat never counted twice.
$GLOBALS['__pages'] = array(
	1 => array( 'hits' => array( 'total' => 3, 'hits' => array( hit( 1, '10.5281/zenodo.1', 5 ), hit( 2, '10.5281/zenodo.2', 7 ) ) ) ),
	2 => array( 'hits' => array( 'total' => 3, 'hits' => array( hit( 2, '10.5281/zenodo.2', 7 ), hit( 3, '10.5281/zenodo.3', 4 ) ) ) ),
);
$sum = snt_nsm_zenodo_fetch();
ok( is_array( $sum ) && 16 === $sum['downloads'] && 3 === $sum['records'], 'all-ORCID totals: three records, 16 downloads, the repeated record counted once' );
ok( array( '10.5281/zenodo.1' => 5, '10.5281/zenodo.2' => 7, '10.5281/zenodo.3' => 4 ) === ( $sum['by_doi'] ?? null ), 'per-record lifetime unique downloads are kept by DOI' );
ok( 2 === count( $GLOBALS['__calls'] ) && false !== strpos( $GLOBALS['__calls'][1], 'page=2' ), 'the loop walks to the page that completes the total' );
ok( 2 === count( array_filter( $GLOBALS['__calls'], static fn( $u ) => false !== strpos( $u, 'sort=mostrecent' ) && false !== strpos( $u, rawurlencode( 'creators.orcid:"' . SN_ZENODO_ORCID . '"' ) ) ) ), 'every page asks the same query in a stable order' );

// ── Site-only split: only records whose DOI is a site document's count.
$split = snt_nsm_zenodo_with_site( $sum, array( '10.5281/zenodo.1', '10.5281/zenodo.3', '10.5281/zenodo.99' ) );
ok( 9 === $split['site_downloads'] && 2 === $split['site_records'], 'site-only: two site records matched (5 + 4), the non-site record excluded, a site DOI Zenodo did not return adds nothing' );
ok( 16 === $split['downloads'], 'the all-ORCID figure the owner panel reads is unchanged' );
$none = snt_nsm_zenodo_with_site( $sum, array() );
ok( ! isset( $none['site_downloads'] ) && ! isset( $none['site_records'] ), 'no site DOIs known: no site figure is stored, so the line hides' );

// ── The cron: per-record figures only on the latest day; a site total every day.
$GLOBALS['__site'] = array( 11 => '10.5281/zenodo.1', 12 => '10.5281/zenodo.3', 13 => '' );
$old = gmdate( 'Y-m-d', time() - 86400 );
$GLOBALS['__opt'][ SNT_NSM_ZENODO_OPT ] = array( $old => array( 'downloads' => 10, 'views' => 1, 'records' => 3, 'site_downloads' => 6, 'site_records' => 2, 'by_doi' => array( '10.5281/zenodo.1' => 3 ) ) );
$GLOBALS['__calls'] = array();
snt_nsm_zenodo_snapshot();
$snaps = $GLOBALS['__opt'][ SNT_NSM_ZENODO_OPT ];
$today = gmdate( 'Y-m-d' );
ok( isset( $snaps[ $today ]['by_doi'] ) && ! isset( $snaps[ $old ]['by_doi'] ), 'per-record figures stay on the latest snapshot only' );
ok( 9 === ( $snaps[ $today ]['site_downloads'] ?? null ) && 2 === ( $snaps[ $today ]['site_records'] ?? null ) && 6 === $snaps[ $old ]['site_downloads'], 'every day keeps its site-only total' );
ok( array( array( 'https://example.test/provenance/' ) ) === $GLOBALS['__purged'], 'the line changed: the /provenance page is purged once' );

// ── The public reading: stored values only.
$cfg = array( 'enabled' => true, 'label' => '', 'floor' => 0 );
$day = static fn( $n ) => gmdate( 'Y-m-d', strtotime( '2026-10-21 UTC' ) - $n * 86400 );
$s   = static fn( $site, $recs = 62 ) => array( 'downloads' => 500, 'views' => 1, 'records' => 83, 'site_downloads' => $site, 'site_records' => $recs );
ok( null === snt_prov_doi_reading( array(), '2026-10-21', $cfg ), 'missing: no snapshot, no line' );
ok( null === snt_prov_doi_reading( array( $day( 0 ) => array( 'downloads' => 5, 'views' => 1, 'records' => 2 ) ), '2026-10-21', $cfg ), 'missing: a snapshot from before the site split hides the line' );
ok( null === snt_prov_doi_reading( array( $day( 0 ) => $s( 0, 0 ) ), '2026-10-21', $cfg ), 'no site document matched: hidden, never "0 documents"' );
$r = snt_prov_doi_reading( array( $day( 2 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( is_array( $r ) && 143 === $r['all_time'] && 62 === $r['documents'] && null === $r['last_30'], 'a snapshot two days old still shows, all time only' );
ok( null === snt_prov_doi_reading( array( $day( 3 ) => $s( 143 ) ), '2026-10-21', $cfg ), 'stale: a latest snapshot three days old (over 48 hours) hides the line' );
$r = snt_prov_doi_reading( array( $day( 30 ) => $s( 112 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( 31 === $r['last_30'] && 143 === $r['all_time'], '30 days: the difference against the snapshot exactly 30 days back' );
$r = snt_prov_doi_reading( array( $day( 29 ) => $s( 112 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( null === $r['last_30'], '30 days absent: no snapshot exactly 30 days back' );
$r = snt_prov_doi_reading( array( $day( 31 ) => $s( 100 ), $day( 1 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( 43 === $r['last_30'], 'the window ends at the latest snapshot, not at today' );
$r = snt_prov_doi_reading( array( $day( 30 ) => $s( 112, 61 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( null === $r['last_30'] && 143 === $r['all_time'], '30 days hidden when the set of documents changed: a new deposit\'s lifetime downloads are not window downloads' );
$r = snt_prov_doi_reading( array( $day( 30 ) => array( 'downloads' => 400, 'views' => 1, 'records' => 80 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( null === $r['last_30'], '30 days absent: the back snapshot predates the site split, never read as zero' );
$r = snt_prov_doi_reading( array( $day( 30 ) => $s( 150 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', $cfg );
ok( null === $r['last_30'] && 143 === $r['all_time'], 'a drop over 30 days is not a number: the 30-day figure hides' );
$r = snt_prov_doi_reading( array( $day( 30 ) => $s( 112 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', array( 'floor' => 40 ) + $cfg );
ok( null === $r['last_30'] && 143 === $r['all_time'], 'floor: 31 below a floor of 40 shows all time only' );
$r = snt_prov_doi_reading( array( $day( 30 ) => $s( 112 ), $day( 0 ) => $s( 143 ) ), '2026-10-21', array( 'floor' => 31 ) + $cfg );
ok( 31 === $r['last_30'], 'floor: a figure equal to the floor shows' );
ok( null === snt_prov_doi_reading( array( $day( 0 ) => $s( 143 ) ), '2026-10-21', array( 'enabled' => false ) + $cfg ), 'toggle off: no line' );

// ── The line: exact counts, the source linked from the ORCID, the label escaped.
$html = snt_prov_doi_line_html( array( 'documents' => 62, 'all_time' => 143, 'last_30' => 31 ), $cfg );
$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
ok( '62 documents with DOIs · 143 downloads all time · 31 in the last 30 days (Zenodo)' === $text, 'the line reads: ' . $text );
ok( false !== strpos( $html, 'href="https://zenodo.org/search?q=creators.orcid%3A%220009-0006-8151-5920%22"' ), 'Zenodo links to the search for the ORCID' );
ok( 1 === preg_match( '/^<p class="sn-doi-proof">/', $html ) && false === strpos( $html, 'sn-prov-' ) && false === strpos( $html, 'style=' ) && false === strpos( $html, '<script' ), 'one quiet paragraph, its own class, no inline style or script (and no sn-prov- class that would hide the page badge)' );
$text = html_entity_decode( strip_tags( snt_prov_doi_line_html( array( 'documents' => 1, 'all_time' => 1, 'last_30' => null ), $cfg ) ), ENT_QUOTES, 'UTF-8' );
ok( '1 document with a DOI · 1 download all time (Zenodo)' === $text, 'singulars, and no 30-day clause without the figure: ' . $text );
$html = snt_prov_doi_line_html( array( 'documents' => 62, 'all_time' => 143, 'last_30' => null ), array( 'label' => 'Open record' ) + $cfg );
ok( false !== strpos( $html, '<span class="sn-doi-proof__label">Open record</span> 62 documents' ), 'label override: the owner\'s lead text precedes the figures' );
$html = snt_prov_doi_line_html( array( 'documents' => 62, 'all_time' => 143, 'last_30' => null ), array( 'label' => '"><script>x</script>' ) + $cfg );
ok( false === strpos( $html, '<script' ) && false !== strpos( $html, '&quot;&gt;&lt;script&gt;' ), 'render escaping: a hostile label never reaches the page raw' );

// ── Settings: defaults on with floor 0; the save clamps and escapes nothing it should not store.
$GLOBALS['__opt'] = array();
ok( array( 'enabled' => true, 'label' => '', 'floor' => 0 ) === snt_prov_doi_settings(), 'defaults: on, no label, floor 0' );
$GLOBALS['__opt'][ SNT_PROV_DOI_OPT ] = array( 'enabled' => false, 'label' => 'x', 'floor' => -5 );
ok( array( 'enabled' => false, 'label' => 'x', 'floor' => 0 ) === snt_prov_doi_settings(), 'stored values read back, a negative floor as 0' );
ok( 'zenodo_doi_line_saved' === sn_handle_zenodo_doi_line_save( array( 'zenodo_doi_line_label' => ' <b>Open</b> record ', 'zenodo_doi_line_floor' => '12' ) ), 'the save returns its flash code' );
ok( array( 'enabled' => false, 'label' => 'Open record', 'floor' => 12 ) === snt_prov_doi_settings(), 'an unchecked box saves off; the label is plain text; the floor an integer' );
sn_handle_zenodo_doi_line_save( array( 'zenodo_doi_line_enabled' => '1', 'zenodo_doi_line_floor' => 'abc' ) );
ok( array( 'enabled' => true, 'label' => '', 'floor' => 0 ) === snt_prov_doi_settings(), 'checked saves on; a non-number floor saves 0' );

// ── The page: appended on /provenance only, from stored values, with no Zenodo call.
$GLOBALS['__opt'][ SNT_NSM_ZENODO_OPT ] = array( gmdate( 'Y-m-d' ) => $s( 143 ) );
$GLOBALS['__calls'] = array();
$out = snt_prov_doi_append( '<p>body</p>' );
ok( 0 === strpos( $out, '<p>body</p>' ) && false !== strpos( $out, '143 downloads all time' ), 'the line follows the /provenance content' );
ok( array() === $GLOBALS['__calls'], 'no Zenodo call at render (the same counter saw the fetch call it)' );
$GLOBALS['__ctx']['page'] = false;
ok( '<p>body</p>' === snt_prov_doi_append( '<p>body</p>' ), 'any other page is untouched' );
$GLOBALS['__ctx']['page'] = true;
$GLOBALS['__ctx']['feed'] = true;
ok( '<p>body</p>' === snt_prov_doi_append( '<p>body</p>' ), 'the feed is untouched' );
$GLOBALS['__ctx']['feed'] = false;
$GLOBALS['__opt'][ SNT_PROV_DOI_OPT ] = array( 'enabled' => false );
ok( '<p>body</p>' === snt_prov_doi_append( '<p>body</p>' ), 'toggle off: the page is untouched' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
