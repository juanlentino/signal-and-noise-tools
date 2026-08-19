<?php
/**
 * Standalone tests for the citation store + adjudicator (wpdb + transport spies).
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_CIT_TEST', true );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return ( $t instanceof WP_Error_Stub ); } }
class WP_Error_Stub {}

// ── the SSRF guard is stubbed so the suite never touches a resolver ──────────
$GLOBALS['__blocked'] = array( 'internal.local', '169.254.169.254' );
if ( ! function_exists( 'sn_ssrf_host_blocked' ) ) {
	function sn_ssrf_host_blocked( $h ) { return in_array( strtolower( (string) $h ), $GLOBALS['__blocked'], true ); }
}

// ── transport spy: a scripted map of url => [code, body, location] ───────────
$GLOBALS['__http']     = array();
$GLOBALS['__requests'] = array();
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['__requests'][] = array( $url, $args );
	if ( ! isset( $GLOBALS['__http'][ $url ] ) ) { return new WP_Error_Stub(); }
	return $GLOBALS['__http'][ $url ];
}
function wp_remote_retrieve_response_code( $r ) { return $r[0] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r[1] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return ( 'location' === $h ) ? ( $r[2] ?? '' ) : ''; }

// ── wpdb spy ─────────────────────────────────────────────────────────────────
class Test_WPDB {
	public $prefix = 'wp_';
	public $inserts = array();
	public $updates = array();
	public $queries = array();
	public $rows    = array();
	public $var     = null;
	public $last_error = '';
	public function prepare( $sql, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $a ); }
	public function insert( $t, $r ) { $this->inserts[] = array( $t, $r ); return 1; }
	public function update( $t, $r, $w ) { $this->updates[] = array( $t, $r, $w ); return 1; }
	public function query( $s ) { $this->queries[] = $s; return 0; }
	public function get_var( $s ) { $this->queries[] = $s; return $this->var; }
	public function get_results( $s, $o = 'OBJECT' ) { $this->queries[] = $s; return $this->rows; }
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new Test_WPDB();

require __DIR__ . '/../inc/citations-core.php';
require __DIR__ . '/../inc/citations-store.php';
require __DIR__ . '/../inc/citations-verify.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function reset_db() { $GLOBALS['wpdb']->inserts = array(); $GLOBALS['wpdb']->updates = array(); $GLOBALS['wpdb']->queries = array(); $GLOBALS['wpdb']->var = null; $GLOBALS['wpdb']->rows = array(); $GLOBALS['__requests'] = array(); }
echo "citation graph — store + adjudicator — v11.27.0\n\n";

// ── pair identity ───────────────────────────────────────────────────────────
$a = sn_cit_pair_hash( 'https://Example.com/a', 'https://juanlentino.com/notes/x/' );
$b = sn_cit_pair_hash( 'https://example.com/a/', 'https://juanlentino.com/notes/x' );
ok( $a === $b && '' !== $a, 'the same citation under different spellings is ONE pair' );
ok( sn_cit_pair_hash( 'https://example.com/a', 'https://juanlentino.com/notes/y/' ) !== $a, 'a different target is a different pair' );
ok( sn_cit_pair_hash( 'not-a-url', 'https://juanlentino.com/x' ) === '', 'an unusable source has no pair identity' );

// ── recording a claim ───────────────────────────────────────────────────────
reset_db();
ok( sn_cit_record( 'garbage', 'https://juanlentino.com/notes/x/' ) === 'invalid', 'an unusable claim is refused' );
ok( count( $GLOBALS['wpdb']->inserts ) === 0, 'a refused claim writes NOTHING' );

reset_db();
ok( sn_cit_record( 'https://example.com/post', 'https://juanlentino.com/notes/x/', 42 ) === 'created', 'a usable claim is recorded' );
list( $t, $row ) = $GLOBALS['wpdb']->inserts[0];
ok( $row['tier'] === 'unverified', 'a NEW claim lands as unverified — receiving is not confirming' );
ok( ! array_key_exists( 'last_checked_gmt', $row ), 'the insert leaves last_checked_gmt NULL: never measured is not measured-zero' );
ok( $row['target_post_id'] === 42, 'the resolved post id is stored' );
ok( $row['source_url'] === 'https://example.com/post', 'the stored source is the NORMALISED form' );

reset_db();
$GLOBALS['wpdb']->var = 7; // pretend the pair already exists
ok( sn_cit_record( 'https://example.com/post', 'https://juanlentino.com/notes/x/', 42 ) === 'exists', 'a re-ping does not duplicate' );
ok( count( $GLOBALS['wpdb']->inserts ) === 0, 'a re-ping inserts nothing' );

// ── verdicts ────────────────────────────────────────────────────────────────
reset_db();
ok( sn_cit_update_verdict( 1, 'not-a-tier' ) === false, 'an undeclared tier cannot be persisted' );
ok( count( $GLOBALS['wpdb']->updates ) === 0, 'a rejected tier writes nothing' );
reset_db();
sn_cit_update_verdict( 1, 'verified', 200, 'A title' );
list( $t2, $set, $where ) = $GLOBALS['wpdb']->updates[0];
ok( $set['tier'] === 'verified' && $where['id'] === 1, 'a declared tier is persisted against the row' );
ok( ! empty( $set['last_checked_gmt'] ), 'writing a verdict stamps the check time' );

// ── adjudication: the ladder end to end ─────────────────────────────────────
$target = 'https://juanlentino.com/notes/x';
$mkrow  = static function ( $src ) use ( $target ) { return (object) array( 'id' => 1, 'source_url' => $src, 'target_url' => $target ); };

reset_db();
$GLOBALS['__http'] = array( 'https://example.com/p' => array( 200, '<a href="https://juanlentino.com/notes/x/">cite</a><link rel="me" href="#">' ) );
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'verified', 'link present + identity on the page = verified' );

reset_db();
$GLOBALS['__http'] = array(
	'https://example.com/p'                       => array( 200, '<a href="https://juanlentino.com/notes/x/">cite</a>' ),
	'https://example.com/.well-known/did.json'    => array( 404, '' ),
);
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'unattributed', 'link present but no identity anywhere = unattributed' );

reset_db();
$GLOBALS['__http'] = array(
	'https://example.com/p'                    => array( 200, '<a href="https://juanlentino.com/notes/x/">cite</a>' ),
	'https://example.com/.well-known/did.json' => array( 200, '{"id":"did:web:example.com"}' ),
);
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'verified', 'an origin did.json supplies the identity the page lacked' );

reset_db();
$GLOBALS['__http'] = array( 'https://example.com/p' => array( 200, '<p>the link is gone</p>' ) );
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'asserted', 'fetched but the link is gone = asserted' );
ok( count( $GLOBALS['__requests'] ) === 1, 'an asserted row does NOT spend a request probing identity' );

reset_db();
$GLOBALS['__http'] = array(); // every fetch errors
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'unverified', 'an unreachable source is unverified, NEVER asserted' );

reset_db();
$GLOBALS['__http'] = array( 'https://example.com/p' => array( 500, '' ) );
ok( sn_cit_verify_row( $mkrow( 'https://example.com/p' ) ) === 'unverified', 'a 500 is a failed read, not a removed link' );

// ── redirects: followed by hand, re-guarded every hop ───────────────────────
reset_db();
$GLOBALS['__http'] = array(
	'https://example.com/old' => array( 301, '', 'https://example.com/new' ),
	'https://example.com/new' => array( 200, '<a href="https://juanlentino.com/notes/x/">c</a><link rel="me" href="#">' ),
);
ok( sn_cit_verify_row( $mkrow( 'https://example.com/old' ) ) === 'verified', 'a redirect to a page that still cites is verified' );
ok( $GLOBALS['__requests'][0][1]['redirection'] === 0, 'the transport is pinned to redirection => 0 — hops are ours to validate' );

reset_db();
$GLOBALS['__http'] = array(
	'https://example.com/old' => array( 301, '', 'https://internal.local/secret' ),
	'https://internal.local/secret' => array( 200, '<a href="https://juanlentino.com/notes/x/">c</a>' ),
);
ok( sn_cit_verify_row( $mkrow( 'https://example.com/old' ) ) === 'unverified', 'a redirect INTO a blocked host is refused, not followed' );
ok( count( $GLOBALS['__requests'] ) === 1, 'the blocked hop was never requested' );

reset_db();
$hops = array();
for ( $i = 0; $i < 8; $i++ ) { $hops[ "https://example.com/h$i" ] = array( 302, '', 'https://example.com/h' . ( $i + 1 ) ); }
$GLOBALS['__http'] = $hops;
ok( sn_cit_verify_row( $mkrow( 'https://example.com/h0' ) ) === 'unverified', 'a redirect loop terminates as unverified rather than spinning' );
ok( count( $GLOBALS['__requests'] ) <= SN_CIT_MAX_HOPS + 1, 'the hop cap is enforced (' . count( $GLOBALS['__requests'] ) . ' requests)' );

reset_db();
$GLOBALS['__http'] = array( 'https://example.com/old' => array( 301, '', '/relative' ) );
ok( sn_cit_verify_row( $mkrow( 'https://example.com/old' ) ) === 'unverified', 'a relative Location is not resolvable and is refused' );

// ── the source is never fetched when the guard blocks it outright ───────────
reset_db();
$GLOBALS['__http'] = array( 'https://internal.local/p' => array( 200, '<a href="https://juanlentino.com/notes/x/">c</a>' ) );
ok( sn_cit_verify_row( $mkrow( 'https://internal.local/p' ) ) === 'unverified', 'a blocked source host is never read' );
ok( count( $GLOBALS['__requests'] ) === 0, 'and no request left the site' );

// ── title extraction ────────────────────────────────────────────────────────
ok( sn_cit_extract_title( '<title>  Hello   World </title>' ) === 'Hello World', 'the title is trimmed and whitespace-collapsed' );
ok( sn_cit_extract_title( '<title>A &amp; B</title>' ) === 'A & B', 'entities are decoded' );
ok( sn_cit_extract_title( '<p>no title</p>' ) === '', 'a page with no title yields an empty string, not a guess' );

// ── counts name every tier explicitly ───────────────────────────────────────
reset_db();
$GLOBALS['wpdb']->rows = array( (object) array( 'tier' => 'verified', 'n' => 3 ) );
$GLOBALS['wpdb']->var  = 5;
$counts = sn_cit_counts();
foreach ( SN_CIT_TIERS as $tier ) { ok( array_key_exists( $tier, $counts ), "the readout names the $tier tier even at zero" ); }
ok( $counts['verified'] === 3 && $counts['asserted'] === 0, 'measured tiers carry their count; unmeasured ones read an explicit 0' );
ok( $counts['never_checked'] === 5, 'never_checked is reported SEPARATELY from any tier' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
