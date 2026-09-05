<?php
/**
 * Standalone tests for the Citations readout — the three-way tally.
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '2 hours'; } }

require __DIR__ . '/../inc/citations-core.php';
require __DIR__ . '/../inc/citations-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "citation graph — readout — v11.27.0\n\n";

// ── the empty case: a measured zero, said as one ────────────────────────────
$zero = array_fill_keys( SN_CIT_TIERS, 0 );
$zero['never_checked'] = 0;
$s = sn_cit_summary_sentence( $zero );
ok( false !== stripos( $s, 'No citations recorded' ), 'an empty graph says so plainly' );
ok( false !== stripos( $s, 'measured zero' ), 'and states it is a MEASURED zero, not an unread inbox' );

// ── the tally names every bucket, always ────────────────────────────────────
$c = array( 'verified' => 2, 'unattributed' => 1, 'asserted' => 1, 'unverified' => 3, 'never_checked' => 3 );
$s = sn_cit_summary_sentence( $c );
ok( false !== strpos( $s, '7 claims' ), 'the total counts every tier, including the ones a fraction would hide' );
foreach ( SN_CIT_TIERS as $t ) {
	ok( false !== strpos( $s, $t ), "the readout names the $t tier explicitly" );
}
ok( false !== strpos( $s, '3 have never been checked' ), 'never-checked is called out separately, in its own sentence' );

// A tier at zero must still be PRINTED — a tier missing from a readout is
// indistinguishable from a tier nobody measured. This is the v11.13.0 lesson.
$c2 = array( 'verified' => 4, 'unattributed' => 0, 'asserted' => 0, 'unverified' => 0, 'never_checked' => 0 );
$s2 = sn_cit_summary_sentence( $c2 );
ok( false !== strpos( $s2, '0 asserted' ), 'a tier at zero is printed as 0, not omitted' );
ok( false !== strpos( $s2, '4 claims' ), 'the total still accounts for the whole table' );
ok( false === stripos( $s2, 'never been checked' ), 'with nothing unchecked, no unchecked sentence is added' );

// singular/plural
$c3 = array( 'verified' => 0, 'unattributed' => 0, 'asserted' => 0, 'unverified' => 1, 'never_checked' => 1 );
ok( false !== strpos( sn_cit_summary_sentence( $c3 ), '1 has never been checked' ), 'one unchecked row reads in the singular' );

// a missing key must not fatal or invent a number
$partial = array( 'verified' => 2 );
ok( false !== strpos( sn_cit_summary_sentence( $partial ), '0 asserted' ), 'an absent key reads as 0 rather than exploding' );

// ── never vs a date ─────────────────────────────────────────────────────────
ok( sn_cit_last_checked_label( null ) === 'never', 'NULL renders as "never"' );
ok( sn_cit_last_checked_label( '' ) === 'never', 'an empty value renders as "never" too' );
ok( sn_cit_last_checked_label( '2026-08-19 10:00:00' ) === '2 hours ago', 'a real timestamp renders as elapsed time' );
ok( sn_cit_last_checked_label( null ) !== sn_cit_last_checked_label( '2026-08-19 10:00:00' ), 'never and measured can never render the same' );

// ── the leaf lays itself out: hero + wide fieldset + table (#1055) ──────────
// The first cut wrapped the whole leaf in .sn-card — the 260px stat card — so a
// 1,400px window showed a strip down the left edge with the table overflowing
// its own border. This drives the REAL renderer over a stub $wpdb and pins the
// structure: no stat card, a glance card per tier, the wide fieldset, the table.
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url, $protocols = null, $_context = 'display' ) { return (string) $url; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $data ) { return $data; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $post = 0 ) { return 7 === (int) $post ? 'The signer keeps moving' : ''; } }
if ( ! function_exists( 'sn_cit_endpoint_url' ) ) { function sn_cit_endpoint_url() { return 'https://example.test/wp-json/signal-noise/v1/webmention'; } }

class SN_Cit_Test_WPDB {
	public $prefix = 'wp_';
	public $rows   = array();
	public $counts = array();
	public function get_results( $query ) { return false !== strpos( $query, 'GROUP BY tier' ) ? $this->counts : $this->rows; }
	public function get_var( $query ) { return 1; }
}
$wpdb = new SN_Cit_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
require_once __DIR__ . '/../inc/citations-store.php';
require_once __DIR__ . '/../inc/admin-glance.php';

$row = function ( $tier, $source, $title, $target, $post_id, $checked, $status ) {
	return (object) array( 'tier' => $tier, 'source_url' => $source, 'source_title' => $title, 'target_url' => $target, 'target_post_id' => $post_id, 'first_seen_gmt' => '2026-08-27 10:00:00', 'last_checked_gmt' => $checked, 'last_status' => $status );
};
$wpdb->counts = array( (object) array( 'tier' => 'verified', 'n' => 1 ), (object) array( 'tier' => 'asserted', 'n' => 1 ), (object) array( 'tier' => 'unverified', 'n' => 1 ) );
$wpdb->rows   = array(
	$row( 'verified',   'https://example.com/notes/x',               'Example Domain',    'https://juanlentino.com/notes/the-signer-keeps-moving/', 7, '2026-09-05 13:00:00', 200 ),
	$row( 'asserted',   'https://aggregator.example.com/roundup-34', 'Weekly roundup #34', 'https://juanlentino.com/notes/a-readout/',               0, '2026-09-03 13:00:00', 200 ),
	$row( 'unverified', 'https://fresh.example.dev/mentions/juan',   '',                  'https://juanlentino.com/notes/what-the-ledger/',          0, null,                  0 ),
);
ob_start();
sn_admin_render_citations_section();
$html = ob_get_clean();

echo "\nthe leaf's layout: the stat card is gone, the table has the width\n";
ok( false === strpos( $html, 'class="sn-card"' ), 'the leaf no longer wraps itself in .sn-card (the 260px stat card)' );
ok( false !== strpos( $html, 'class="sn-fieldset sn-fieldset--wide"' ), 'the table lives in the wide fieldset, the kit\'s "a data table earns the full width"' );
ok( 4 === substr_count( $html, '<div class="sn-glance-card"' ), 'one glance card per tier -- four, every tier printed' );
foreach ( SN_CIT_TIERS as $t ) {
	ok( false !== strpos( $html, '<p class="sn-glance-card__label">' . $t . '</p>' ), "the $t tier has its own card" );
}
ok( false !== strpos( $html, 'sn-pill--warn">evidence gone</span>' ), 'an asserted claim earns the warn pill on its card' );
ok( false !== strpos( $html, 'sn-pill--warn">1 never checked</span>' ), 'the unverified card counts the never-checked rows on its pill' );
$verified_card = substr( $html, strpos( $html, '__label">verified' ), 400 );
ok( false === strpos( substr( $verified_card, 0, strpos( $verified_card, '</div>' ) ), 'sn-pill' ), 'a verified card carries no pill -- nothing to act on' );
ok( false !== strpos( $html, '3 claims: 1 verified · 0 unattributed · 1 asserted · 1 unverified. 1 has never been checked.' ), 'the summary sentence still accounts for the whole table' );
ok( 6 === substr_count( $html, '<th scope="col">' ), 'six columns: tier, source, cites, first seen, last checked, HTTP' );
ok( false !== strpos( $html, '<details><summary>What the four tiers mean</summary>' ), 'the legend folds into a details block' );
foreach ( SN_CIT_TIERS as $t ) {
	ok( false !== strpos( $html, esc_html( sn_cit_tier_sentence( $t ) ) ), "the legend keeps the $t sentence" );
}
ok( false === strpos( $html, 'sn-cit-legend' ), 'the unstyled legend class is gone' );
ok( 1 === substr_count( $html, 'https://example.test/wp-json/signal-noise/v1/webmention' ), 'the inbox URL is printed once' );

echo "\nthe rows\n";
ok( false !== strpos( $html, '<span class="sn-pill sn-pill--ok" title="' ), 'a verified row wears the ok pill' );
ok( false !== strpos( $html, '<span class="sn-pill sn-pill--warn" title="A citation was claimed' ), 'an asserted row wears the warn pill and its sentence as the title' );
ok( false !== strpos( $html, '<span class="sn-pill sn-pill--muted" title="' ), 'an unverified row wears the muted pill' );
ok( false !== strpos( $html, '>The signer keeps moving</a><br><span class="description">/notes/the-signer-keeps-moving/</span>' ), 'a row that knows its post cites the Note by title, with the path beneath' );
ok( false !== strpos( $html, '>/notes/a-readout/</a></td>' ), 'a row without a post cites the path itself' );
ok( false !== strpos( $html, '>Example Domain</a><br><span class="description">example.com</span>' ), 'a titled source prints its host beneath the title' );
ok( false !== strpos( $html, '>fresh.example.dev</a></td>' ), 'an untitled source links its host and prints no second line' );
ok( 1 === substr_count( $html, '<td>never</td>' ), 'exactly one row has never been checked, and says never' );
ok( false !== strpos( $html, '<td>—</td>' ), 'no response at all prints as a dash, never as a status' );
ok( false === strpos( $html, 'newest 100' ), 'no cap notice below the cap' );

echo "\nthe empty graph\n";
$wpdb->rows = array();
ob_start();
sn_admin_render_citations_section();
$empty = ob_get_clean();
ok( false !== strpos( $empty, 'Nothing to list yet.' ) && false === strpos( $empty, '<table' ), 'an empty graph says so inside the fieldset and prints no table' );
ok( 4 === substr_count( $empty, '<div class="sn-glance-card"' ), 'the four cards still paint at zero' );

echo "\nthe pure helpers\n";
ok( 'ok' === sn_cit_tier_pill_kind( 'verified' ) && '' === sn_cit_tier_pill_kind( 'unattributed' ) && 'warn' === sn_cit_tier_pill_kind( 'asserted' ) && 'muted' === sn_cit_tier_pill_kind( 'unverified' ), 'pill tones: ok / plain / warn / muted' );
ok( '' === sn_cit_tier_pill_kind( 'bogus' ), 'an unknown tier gets no modifier' );
foreach ( SN_CIT_TIERS as $t ) {
	ok( '' !== sn_cit_tier_gloss( $t ), "the $t tier has a gloss" );
}
ok( 'never' === sn_cit_ago_label( null ) && '2 hours ago' === sn_cit_ago_label( '2026-08-27 10:00:00' ), 'the ago label keeps never and measured apart' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
