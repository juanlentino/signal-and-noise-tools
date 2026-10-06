<?php
/**
 * Tests for inc/colophon-page.php — [sn_colophon], the CMS-owned colophon.
 * Pins: content parity items, the maturity loop-closer resolved from the
 * page (linked when resolvable, plain text when not — never a dead link),
 * the Tooling repo link, the Interop bullet and its position, the linked
 * live version footer, the ABSENCE of the dropped /notes line, escaping,
 * and both filter seams (items + urls — a blanked URL degrades to text,
 * never a dead link).
 * Run: php tests/colophon-page.php
 * @since plugin v10.13.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SNT_VERSION', '10.13.0-test' );
function __( $s, $d = null ) { return (string) $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return filter_var( $s, FILTER_VALIDATE_URL ) ? $s : ''; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
// The page resolver, stubbed as a slug map so every branch is testable.
$GLOBALS['__page_urls'] = array(
	'maturity' => 'https://example.com/maturity/',
	'workflow' => 'https://example.com/workflow/',
	'notes'    => 'https://example.com/notes/',
);
function get_page_by_path( $path ) {
	$u = $GLOBALS['__page_urls'][ $path ] ?? '';
	return '' === $u ? null : (object) array( 'post_status' => $GLOBALS['__page_status'][ $path ] ?? 'publish', 'url' => $u );
}
function get_permalink( $p ) { return $p->url; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function sn_maturity_index_resolve_url( $slug ) {
	return isset( $GLOBALS['__page_urls'][ $slug ] ) ? $GLOBALS['__page_urls'][ $slug ] : '';
}
function wp_get_theme() {
	return new class() {
		public function get( $k ) { return 'Version' === $k ? '11.1.10-test' : ''; }
	};
}

require __DIR__ . '/../inc/colophon-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + structure (2026-10-06 rewrite)\n";
ok( isset( $GLOBALS['__shortcodes']['sn_colophon'] ), 'shortcode registered on load' );
$html = sn_colophon_shortcode();
ok( 1 === preg_match( '#^<div class="sn-colophon"><p>I designed and built this site, and I maintain it#', $html ), 'the opening is first person and comes first' );
$h2 = array();
preg_match_all( '#<h2[^>]*>(.*?)</h2>#', $html, $h2 );
ok( array( 'Made with', 'On the page', 'Kept honest' ) === $h2[1], 'three groups, H2 headings, in order (the page title is the H1)' );
ok( false === strpos( $html, '<h1' ) && false === strpos( $html, '<h3' ), 'no other heading levels' );
ok( 3 === preg_match_all( '#font-size:clamp\(2rem, 5vw, 3\.5rem\)#', $html ), 'group headings use the site\'s section-heading scale, not the theme\'s 6rem H2' );
$order = array( 'platform', 'code', 'hosting', 'plugin', 'type', 'appearance', 'records', 'systems', 'ai', 'interop' );
$pos = array_map( static fn( $s ) => strpos( $html, 'sn-colophon-item--' . $s . '"' ), $order );
ok( ! in_array( false, $pos, true ) && $pos === array_values( array_unique( $pos ) ) && $pos == array_values( ( static function ( $p ) { sort( $p ); return $p; } )( $pos ) ), 'the ten rows, in the brief\'s order' );
ok( 10 === substr_count( $html, '<li class="sn-colophon-item--' ), 'exactly ten rows' );
ok( 1 === preg_match( '#<h2[^>]*>Made with</h2><ul class="sn-colophon-items"><li class="sn-colophon-item--platform"#', $html )
	&& 1 === preg_match( '#<h2[^>]*>On the page</h2><ul class="sn-colophon-items"><li class="sn-colophon-item--type"#', $html )
	&& 1 === preg_match( '#<h2[^>]*>Kept honest</h2><ul class="sn-colophon-items"><li class="sn-colophon-item--records"#', $html ), 'each group opens on its first row' );
ok( 10 === preg_match_all( '#<li class="sn-colophon-item--[a-z]+"><strong>[^<]+:</strong> #', $html ), 'every row reads Label: text, a colon after the label' );
ok( false === strpos( $html, '</strong> - ' ) && false === strpos( $html, '—' ) && false === strpos( $html, ' - ' ), 'no hyphen separators and no em dashes' );
foreach ( array( 'simply', 'seamless', 'powerful', 'robust' ) as $w ) {
	ok( false === stripos( $html, $w ), "no marketing word: $w" );
}

echo "\nGroup: the facts\n";
ok( false !== strpos( $html, 'no page builder' ), 'platform says what Full Site Editing means here' );
ok( false !== strpos( $html, 'what is in the public repositories is what runs, with nothing compiled in between' ), 'code says what no build step buys' );
ok( false !== strpos( $html, 'Cloudways' ) && false !== strpos( $html, 'Cloudflare' ), 'hosting names the host and the CDN/DNS provider' );
ok( false !== strpos( $html, 'Bebas Neue' ) && false !== strpos( $html, 'DM Mono' ), 'type names both faces' );
ok( false !== strpos( $html, 'follows your device' ) && false !== strpos( $html, 'toggle' ), 'appearance: dark follows the system, the toggle overrides' );
ok( false !== strpos( $html, 'SHA-256' ) && false !== strpos( $html, 'Ed25519' ) && false !== strpos( $html, 'OpenTimestamps' ) && false !== strpos( $html, 'an edit to the text adds a new signed version' ), 'records names only what the provenance code does (a markup-only save makes no version)' );
ok( false !== strpos( $html, 'engineered with Claude (Anthropic) as a' ) && false !== strpos( $html, 'meaning an AI that helps write the site' ), 'the AI statement is kept, and "pair programmer" is explained in the row' );
foreach ( array( 'stay mine', 'only person', 'exactly the code', 'load quickly', 'which I also wrote', 'sees your visit', 'will never do' ) as $claim ) {
	ok( false === strpos( $html, $claim ), "no promise or claim beyond a checkable fact: '$claim' (owner 2026-10-06)" );
}
ok( 1 === preg_match( '#<strong>Platform:</strong> WordPress with Full Site Editing, so#', $html ), 'the platform row has one colon, after its label' );
ok( false !== strpos( $html, 'OpenStation' ) && false !== strpos( $html, 'readers of the public site never see it' ), 'interop says what OpenStation is and that readers never see it' );

echo "\nGroup: links (every existing destination kept)\n";
ok( false !== strpos( $html, '<a href="https://example.com/workflow/">pair programmer<span class="screen-reader-text">: how I work with AI</span></a>' ) && false === strpos( $html, 'aria-label' ), 'the AI link keeps its visible name plus hidden context, no aria-label' );
ok( false !== strpos( $html, 'href="https://example.com/maturity/"' ), 'systems links the maturity index' );
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise-tools"' ) && false !== strpos( $html, '>Signal &amp; Noise Tools<span class="screen-reader-text"> (opens in a new tab)</span></a>' ), 'companion plugin links its repo, named, and says it opens a new tab' );
ok( substr_count( $html, '<span class="screen-reader-text"> (opens in a new tab)</span></a>' ) === substr_count( $html, 'target="_blank"' ) - 2, 'every new-tab link in the rows and opening says so to a screen reader (the two changelog links in the unchanged version line excepted)' );
ok( false !== strpos( $html, 'href="https://openstation.me/"' ), 'interop links OpenStation' );
ok( false !== strpos( $html, 'href="https://example.com/verify"' ) && false !== strpos( $html, '>Verify a Note</a>' ), 'records links where a reader checks a note' );
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise"' ), 'the opening links the public source' );
ok( substr_count( $html, 'target="_blank" rel="noopener noreferrer"' ) >= 5, 'external links carry the codebase target/rel convention' );
$GLOBALS['__page_urls']['workflow'] = '';
ok( false !== strpos( call_user_func( $GLOBALS['__shortcodes']['sn_colophon'] ), 'as a pair programmer, meaning an AI that helps write the site' ), 'with /workflow unpublished the AI line is plain text, never a dead link' );
$GLOBALS['__page_urls']['workflow'] = 'https://example.com/workflow/';
$GLOBALS['__page_status']['workflow'] = 'draft';
ok( false === strpos( call_user_func( $GLOBALS['__shortcodes']['sn_colophon'] ), 'example.com/workflow' ), 'the /workflow page in draft leaves the line unlinked' );
unset( $GLOBALS['__page_status']['workflow'] );
$GLOBALS['__page_urls']['maturity'] = '';
$plain = sn_colophon_shortcode();
ok( false === strpos( $plain, 'example.com/maturity' ) && false !== strpos( $plain, 'maturity index' ), 'unresolvable maturity index: plain text, never a dead link' );
$GLOBALS['__page_urls']['maturity'] = 'https://example.com/maturity/';

echo "\nGroup: versions (the existing build line, unchanged)\n";
ok( 1 === preg_match( '/<p class="sn-colophon-versions">Theme <a[^>]*>v11\.1\.10-test<\/a> · plugin <a[^>]*>v10\.13\.0-test<\/a><\/p><\/div>$/u', $html ), 'stamp reads Theme vX · plugin vY, numbers linked, last before the wrapper closes' );
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise/blob/main/CHANGELOG.md"' ) && false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise-tools/blob/main/CHANGELOG.md"' ), 'each version links its changelog' );
ok( false === strpos( $html, 'sn-colophon-notes' ) && false === strpos( $html, 'example.com/notes' ), 'no notes line: dropped in 11.10.1, stays dropped' );

echo "\nGroup: the plain-text facts (one source for humans.txt)\n";
$facts = sn_colophon_plain_facts();
ok( 10 === count( $facts ) && 'WordPress' === substr( $facts['Platform'], 0, 9 ), 'ten label => text pairs, keyed by label' );
ok( array() === array_filter( $facts, static fn( $t ) => $t !== strip_tags( $t ) ), 'plain text: no markup in any fact' );
ok( false !== strpos( $facts['Records'], 'Verify a Note' ) && false !== strpos( $facts['Companion plugin'], 'Signal & Noise Tools' ), 'the linked phrases stay in the text, unescaped for a text/plain file' );

echo "\nGroup: escaping + seams\n";
add_filter( 'sn_colophon_items', function ( $items ) {
	$items['records'][1] = 'text with <b>markup</b> ' . $items['records'][1];
	return $items;
} );
$f = sn_colophon_shortcode();
ok( false === strpos( $f, 'with <b>' ), 'filtered items are escaped at build: markup never survives' );
ok( false !== strpos( $f, 'text with &lt;b&gt;markup' ), 'the items seam reaches the rendered row' );
add_filter( 'sn_colophon_urls', function ( $urls ) {
	$urls['plugin_repo'] = '';
	$urls['openstation'] = '';
	$urls['theme_changelog'] = '';
	return $urls;
} );
$u = sn_colophon_shortcode();
ok( false === strpos( $u, 'github.com/juanlentino/signal-and-noise-tools"' ) && false !== strpos( $u, '<strong>Companion plugin:</strong> Signal &amp; Noise Tools adds' ), 'blanked repo URL: the plugin row degrades to plain text' );
ok( false === strpos( $u, 'openstation.me' ) && false !== strpos( $u, 'inside OpenStation, a free' ), 'blanked OpenStation URL: plain text' );
ok( false === strpos( $u, 'signal-and-noise/blob' ) && false !== strpos( $u, 'Theme v11.1.10-test' ), 'blanked theme changelog → unlinked version, stamp text intact' );
$GLOBALS['__filters'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
