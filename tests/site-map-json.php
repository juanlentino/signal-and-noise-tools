<?php
/**
 * inc/site-map-json.php: /notes/index.json, the machine twin of the site (15.5.0).
 *
 * Over a fixture corpus: pillars from `_sn_pillar` pages in designation order,
 * a note's pillar from its tag, password and noindex posts out, the papers
 * list, feeds and rights pointers; the route matches the path only; never
 * built answers 404; the option is never autoloaded; the rebuild hooks carry it.
 *
 * Run: php tests/site-map-json.php
 *
 * @since 15.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' ); define( 'SN_SITE_MAP_TEST', true );
define( 'SNT_ML_REBUILD_HOOK', 'snt_ml_rebuild' ); define( 'SNT_ML_REBUILD_ASYNC_HOOK', 'snt_ml_rebuild_async' );
$GLOBALS['__opt'] = array(); $GLOBALS['__autoload'] = array(); $GLOBALS['__actions'] = array(); $GLOBALS['__meta'] = array(); $GLOBALS['__tags'] = array(); $GLOBALS['__status'] = array(); $GLOBALS['__filters'] = array();
function __( $s, $d = null ) { return $s; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][] = array( $cb, $p ); }
function apply_filters( $h, $v ) { return isset( $GLOBALS['__filters'][ $h ] ) ? call_user_func( $GLOBALS['__filters'][ $h ], $v ) : $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_bloginfo( $k ) { return 'Signal &amp; Noise'; }
function get_feed_link() { return 'https://x.test/feed/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['__meta'][ $id ][ $k ] ?? ''; }
function wp_get_post_tags( $id, $args = array() ) { return $GLOBALS['__tags'][ $id ] ?? array(); }
function get_the_title( $id ) { return $GLOBALS['__posts'][ $id ]->post_title; }
function get_permalink( $id ) { return $GLOBALS['__posts'][ $id ]->url; }
function get_post_time( $f, $gmt, $p ) { return '2026-09-0' . ( $p->ID % 9 + 1 ) . 'T10:00:00+00:00'; }
function get_post_modified_time( $f, $gmt, $p ) { return '2026-09-16T10:00:00+00:00'; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function sanitize_text_field( $s ) { return $s; } function wp_unslash( $s ) { return $s; }
function status_header( $c ) { $GLOBALS['__status'][] = $c; }
function get_posts( $args ) { $out = array(); foreach ( $GLOBALS['__posts'] as $p ) { if ( $p->post_type === $args['post_type'] ) { $out[] = $p; } } return $out; }
function sn_prov_machine_pointers_manifest( $id ) { return in_array( $id, $GLOBALS['__signed'], true ) ? array( 'x' => 1 ) : null; }
function mk( $id, $type, $title, $url, $pw = '' ) { return (object) array( 'ID' => $id, 'post_type' => $type, 'post_title' => $title, 'url' => $url, 'post_password' => $pw ); }
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/site-map-json.php';

$GLOBALS['__posts'] = array(
	10 => mk( 10, 'page', 'Provenance Over Detection', 'https://x.test/provenance/over-detection/' ),
	11 => mk( 11, 'page', 'Provenance as Substrate', 'https://x.test/provenance/as-substrate/' ),
	12 => mk( 12, 'page', 'Services', 'https://x.test/services/' ),
	13 => mk( 13, 'page', 'Secret', 'https://x.test/secret/', 'pw' ),
	14 => mk( 14, 'page', 'Hidden', 'https://x.test/hidden/' ),
	20 => mk( 20, 'post', 'Two kinds &amp; one', 'https://x.test/notes/two-kinds/' ),
	21 => mk( 21, 'post', 'Detection scales', 'https://x.test/notes/detection/' ),
	22 => mk( 22, 'post', 'Unrelated note', 'https://x.test/notes/unrelated/' ),
);
$GLOBALS['__meta'] = array( 10 => array( '_sn_pillar' => '1', '_sn_pillar_designation' => '1.00' ), 11 => array( '_sn_pillar' => '1', '_sn_pillar_designation' => '2.00' ), 14 => array( '_sn_noindex' => '1' ) );
$GLOBALS['__tags'] = array( 20 => array( 'provenance', 'c2pa' ), 21 => array( 'provenance' ), 22 => array( 'music' ) );
$GLOBALS['__signed'] = array( 20 );

$map = sn_site_map_build();
ok( 1 === $map['version'] && is_string( $map['built_at'] ), 'a versioned, dated map' );
ok( 'Signal & Noise' === $map['site']['name'] && 'https://orcid.org/0009-0006-8151-5920' === $map['site']['orcid'] && 'Juan Lentino' === $map['site']['author'], 'the site block: decoded name, author, ORCID' );
ok( array( 'notes' => 3, 'pages' => 3, 'pillars' => 2 ) === $map['counts'], 'counts: 3 notes, 3 visible pages (password and noindex out), 2 pillars' );
ok( array( 'Provenance Over Detection', 'Provenance as Substrate' ) === array_column( $map['pillars'], 'title' ) && 'provenance' === $map['pillars'][0]['tag'], 'pillars in designation order, their tag from the URL path' );
ok( array( 20, 21 ) === $map['pillars'][0]['notes'] && array() === $map['pillars'][1]['notes'], 'a note joins the first pillar whose tag it carries' );
ok( 'Two kinds & one' === $map['notes'][0]['title'] && 'provenance' === $map['notes'][0]['pillar'] && true === $map['notes'][0]['signed'] && array( 'provenance', 'c2pa' ) === $map['notes'][0]['tags'], 'a note: decoded title, pillar, signed, tags' );
ok( '' === $map['notes'][2]['pillar'] && false === $map['notes'][2]['signed'], 'a note with no pillar tag and no manifest says so' );
ok( array( 'Services' ) === array_values( array_diff( array_column( $map['pages'], 'title' ), array( 'Provenance Over Detection', 'Provenance as Substrate' ) ) ), 'pages: pillars included, password and noindex out' );
ok( 3 === count( $map['papers'] ) && 'in submission' === $map['papers'][2]['status'] && '6402298' === $map['papers'][0]['id'], 'the papers: two SSRN, JAES in submission' );
ok( 'https://x.test/notes/feed/' === $map['feeds']['notes'] && 'https://x.test/tdm-policy/' === $map['rights']['terms'], 'feeds and the rights pointer' );
$GLOBALS['__filters']['sn_site_map_papers'] = static function ( $p ) { $p[] = array( 'title' => 'Third', 'venue' => 'x', 'status' => 'drafting', 'id' => '', 'url' => '' ); return $p; };
ok( 4 === count( sn_site_map_build()['papers'] ), 'sn_site_map_papers is filterable' );
unset( $GLOBALS['__filters']['sn_site_map_papers'] );

echo "\nGroup: store and serve\n";
ok( null === sn_site_map_read(), 'never built reads null' );
ob_start(); sn_site_map_send(); $out = ob_get_clean();
ok( '' === $out && array( 404 ) === $GLOBALS['__status'], 'never built serves a truthful 404, no body' );
$GLOBALS['__status'] = array();
sn_site_map_refresh();
ok( false === $GLOBALS['__autoload']['sn_site_map'] && 3 === sn_site_map_read()['counts']['notes'], 'refresh stores the map, never autoloaded' );
ob_start(); sn_site_map_send(); $out = ob_get_clean();
ok( array( 200 ) === $GLOBALS['__status'] && 3 === json_decode( $out, true )['counts']['notes'] && false !== strpos( $out, 'https://x.test/notes/two-kinds/' ), 'built: 200 with the JSON, slashes unescaped' );
ok( sn_site_map_is_request( '/notes/index.json' ) && sn_site_map_is_request( '/notes/index.json?x=1' ) && ! sn_site_map_is_request( '/notes/' ) && ! sn_site_map_is_request( '/notes/index.jsonx' ), 'the route matches the path only' );
$hooked = array_column( array_merge( $GLOBALS['__actions']['snt_ml_rebuild'] ?? array(), $GLOBALS['__actions']['snt_ml_rebuild_async'] ?? array() ), 0 );
ok( array( 'sn_site_map_refresh', 'sn_site_map_refresh' ) === $hooked, 'rides both ML rebuild hooks' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
