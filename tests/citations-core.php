<?php
/**
 * Standalone tests for the verified citation graph — pure core.
 * @since plugin v11.27.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }

require __DIR__ . '/../inc/citations-core.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "citation graph — pure core — v11.27.0\n\n";

// ── URL normalisation ───────────────────────────────────────────────────────
ok( sn_cit_normalize_url( 'HTTPS://Example.COM/Post/' ) === 'https://example.com/Post', 'scheme and host fold; a trailing slash drops; the PATH keeps its case' );
ok( sn_cit_normalize_url( 'https://example.com' ) === 'https://example.com/', 'an empty path normalises to /' );
ok( sn_cit_normalize_url( 'https://example.com:443/x' ) === 'https://example.com/x', 'the default https port is dropped' );
ok( sn_cit_normalize_url( 'http://example.com:80/x' ) === 'http://example.com/x', 'the default http port is dropped' );
ok( sn_cit_normalize_url( 'https://example.com:8443/x' ) === 'https://example.com:8443/x', 'a non-default port is KEPT — it is a different origin' );
ok( sn_cit_normalize_url( 'https://example.com/x#frag' ) === 'https://example.com/x', 'the fragment is dropped — it never reaches the server' );
ok( sn_cit_normalize_url( 'https://example.com/?p=12' ) === 'https://example.com/?p=12', 'the query is KEPT — ?p=12 and ?p=13 are different targets' );
ok( sn_cit_normalize_url( '/relative/path' ) === '', 'a relative URL is not a usable absolute target' );
ok( sn_cit_normalize_url( 'javascript:alert(1)' ) === '', 'a non-http(s) scheme is rejected' );
ok( sn_cit_normalize_url( 'ftp://example.com/x' ) === '', 'ftp is rejected too' );
ok( sn_cit_normalize_url( '' ) === '', 'the empty string is not a URL' );

// ── origin ──────────────────────────────────────────────────────────────────
ok( sn_cit_origin( 'https://Example.com/deep/path?q=1' ) === 'https://example.com', 'origin strips path and query' );
ok( sn_cit_origin( 'nonsense' ) === '', 'an unparseable URL has no origin' );

// ── does the source still link here? ────────────────────────────────────────
$target = 'https://juanlentino.com/notes/the-pen-is-not-the-notary/';
ok( sn_cit_html_links_to( '<a href="https://juanlentino.com/notes/the-pen-is-not-the-notary/">x</a>', $target ), 'a plain matching link is found' );
ok( sn_cit_html_links_to( '<a href="https://juanlentino.com/notes/the-pen-is-not-the-notary">x</a>', $target ), 'a missing trailing slash is still the same citation' );
ok( sn_cit_html_links_to( "<a class='u' href='https://JuanLentino.com/notes/the-pen-is-not-the-notary/'>x</a>", $target ), 'single quotes and a mixed-case host still match' );
ok( sn_cit_html_links_to( '<a href="https://juanlentino.com/notes/the-pen-is-not-the-notary/?utm=1">x</a>', $target ) === false, 'a tracking query makes it a DIFFERENT url — not silently the same' );
ok( sn_cit_html_links_to( '<a  rel="nofollow"  href = "https://juanlentino.com/notes/the-pen-is-not-the-notary/" >x</a>', $target ), 'attribute order and loose whitespace do not hide the link' );
ok( sn_cit_html_links_to( '<a href="https://juanlentino.com/notes/the-pen-is-not-the-notary/#section">x</a>', $target ), 'a fragment on the citing link still cites the page' );
ok( sn_cit_html_links_to( '<a href="https://juanlentino.com/notes/other/">x</a>', $target ) === false, 'a link to a DIFFERENT note is not this citation' );
ok( sn_cit_html_links_to( 'see https://juanlentino.com/notes/the-pen-is-not-the-notary/ in prose', $target ) === false, 'a bare mention in prose is not a link' );
ok( sn_cit_html_links_to( '', $target ) === false, 'empty HTML links to nothing' );
ok( sn_cit_html_links_to( '<a href="x">y</a>', '' ) === false, 'an empty target matches nothing' );

// ── does the source publish a discoverable identity? ────────────────────────
ok( sn_cit_html_has_identity( '<link rel="me" href="https://github.com/someone">' ), 'rel=me counts' );
ok( sn_cit_html_has_identity( '<a rel="me noopener" href="#">x</a>' ), 'rel=me inside a multi-value rel counts' );
ok( sn_cit_html_has_identity( '<link rel="webfinger" href="/.well-known/webfinger">' ), 'a webfinger link counts' );
ok( sn_cit_html_has_identity( '<p>did:web:example.com</p>' ), 'a did:web reference counts' );
ok( sn_cit_html_has_identity( '<a rel="nofollow noopener" href="#">x</a>' ) === false, 'unrelated rel values do NOT count as identity' );
ok( sn_cit_html_has_identity( '<a rel="mention" href="#">x</a>' ) === false, 'rel=mention is not rel=me — no substring false positive' );
ok( sn_cit_html_has_identity( '<p>nothing here</p>' ) === false, 'a page with no identity signal has none' );
ok( sn_cit_html_has_identity( '' ) === false, 'empty HTML has no identity' );

// ── the ladder ──────────────────────────────────────────────────────────────
ok( sn_cit_tier( true, true, true ) === 'verified', 'fetched + link + identity = verified' );
ok( sn_cit_tier( true, true, false ) === 'unattributed', 'fetched + link, no identity = unattributed' );
ok( sn_cit_tier( true, false, true ) === 'asserted', 'fetched, link gone = asserted EVEN with a strong identity' );
ok( sn_cit_tier( true, false, false ) === 'asserted', 'fetched, link gone, no identity = asserted' );
// The state the three-tier sketch did not name. Without it a network blip
// convicts a live citation of having dropped its link.
ok( sn_cit_tier( false, true, true ) === 'unverified', 'a failed fetch is unverified — never "asserted"' );
ok( sn_cit_tier( false, false, false ) === 'unverified', 'a failed fetch ignores every other probe' );

// totality: the ladder returns a known tier for all eight input combinations
$seen = array();
foreach ( array( true, false ) as $f ) { foreach ( array( true, false ) as $l ) { foreach ( array( true, false ) as $i ) {
	$seen[ sn_cit_tier( $f, $l, $i ) ] = true;
	ok( in_array( sn_cit_tier( $f, $l, $i ), SN_CIT_TIERS, true ), "tier($f,$l,$i) is a declared tier" );
} } }
ok( count( $seen ) === 4, 'the eight probe combinations reach ALL FOUR tiers and no fifth — the ladder is total' );
ok( ! array_diff( array_keys( $seen ), SN_CIT_TIERS ), 'every reachable tier is a declared one' );

// ── every tier says something, and only the checkable ones go public ────────
foreach ( SN_CIT_TIERS as $t ) {
	ok( '' !== sn_cit_tier_sentence( $t ), "the $t tier carries a sentence explaining itself" );
}
ok( sn_cit_tier_sentence( 'invented' ) === '', 'an unknown tier gets no sentence rather than a misleading one' );
ok( sn_cit_tier_is_public( 'verified' ) && sn_cit_tier_is_public( 'unattributed' ), 'checked tiers are publishable' );
ok( ! sn_cit_tier_is_public( 'asserted' ), 'asserted stays OFF the public list — that is the stock-plugin mistake' );
ok( ! sn_cit_tier_is_public( 'unverified' ), 'unverified stays off the public list too' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
