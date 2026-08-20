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
	'notes'    => 'https://example.com/notes/',
);
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

echo "Group: registration + content parity\n";
ok( isset( $GLOBALS['__shortcodes']['sn_colophon'] ), 'shortcode registered on load' );
$html = sn_colophon_shortcode();
ok( false !== strpos( $html, 'custom WordPress block theme' ), 'intro paragraph present' );
foreach ( array( 'platform', 'type', 'appearance', 'build', 'hosting', 'tooling', 'interop', 'ai', 'trust' ) as $slug ) {
	ok( false !== strpos( $html, 'sn-colophon-item--' . $slug ), "item present: $slug" );
}
ok( false !== strpos( $html, 'pair-programmer' ), 'the AI-assistance credit is kept verbatim' );
ok( strpos( $html, 'sn-colophon-item--type' ) < strpos( $html, 'sn-colophon-item--appearance' )
	&& strpos( $html, 'sn-colophon-item--appearance' ) < strpos( $html, 'sn-colophon-item--build' ),
	'appearance sits between type and build — both are how the page LOOKS, before how it is made' );
ok( false !== strpos( $html, 'dark inversion' ), 'the appearance line names the dark inversion, not just "dark mode"' );
ok( strpos( $html, 'sn-colophon-item--tooling' ) < strpos( $html, 'sn-colophon-item--interop' )
	&& strpos( $html, 'sn-colophon-item--interop' ) < strpos( $html, 'sn-colophon-item--ai' ),
	'interop sits between tooling and AI assistance' );

echo "\nGroup: the maturity loop-closer\n";
ok( false !== strpos( $html, 'href="https://example.com/maturity/"' ), 'trust line links the maturity index when it resolves' );
$GLOBALS['__page_urls']['maturity'] = '';
$plain = sn_colophon_shortcode();
ok( false === strpos( $plain, 'https://example.com/maturity' ) && false !== strpos( $plain, 'maturity index' ), 'unresolvable index → plain text, never a dead link' );
$GLOBALS['__page_urls']['maturity'] = 'https://example.com/maturity/';

echo "\nGroup: outbound references (repo, OpenStation)\n";
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise-tools"' ), 'tooling line links the plugin repo' );
ok( false !== strpos( $html, '>Signal &amp; Noise Tools</a>' ), 'the repo link label is the plugin name, escaped' );
ok( false !== strpos( $html, 'href="https://openstation.me/"' ), 'interop line links OpenStation' );
ok( substr_count( $html, 'target="_blank" rel="noopener noreferrer"' ) >= 4, 'external links carry the codebase target/rel convention' );

echo "\nGroup: versions\n";
ok( false !== strpos( $html, '>v11.1.10-test</a>' ) && false !== strpos( $html, '>v10.13.0-test</a>' ), 'both version numbers are links' );
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise/blob/main/CHANGELOG.md"' ), 'theme version links the theme changelog' );
ok( false !== strpos( $html, 'href="https://github.com/juanlentino/signal-and-noise-tools/blob/main/CHANGELOG.md"' ), 'plugin version links the plugin changelog' );
ok( 1 === preg_match( '/Theme <a[^>]*>v11\.1\.10-test<\/a> · plugin <a[^>]*>v10\.13\.0-test<\/a>/u', $html ), 'stamp text reads Theme vX · plugin vY, numbers linked' );
// The /notes closing line shipped in 11.10.0 and was dropped in 11.10.1:
// /notes is provenance research, not build rationale (owner decision
// 2026-08-16). Pinned absent so it does not drift back in.
ok( false === strpos( $html, 'sn-colophon-notes' ) && false === strpos( $html, 'example.com/notes' ), 'no notes line — dropped in 11.10.1, stays dropped' );
ok( 1 === preg_match( '/<\/p><\/div>$/', $html ), 'the version stamp is the last element before the wrapper closes' );

echo "\nGroup: escaping + seams\n";
add_filter( 'sn_colophon_items', function ( $items ) {
	$items['evil'] = array( '<script>x</script>', 'text with <b>markup</b>' );
	return $items;
} );
$f = sn_colophon_shortcode();
ok( false === strpos( $f, '<script>' ) && false === strpos( $f, 'with <b>' ), 'filtered items are escaped at build — markup never survives' );
ok( false !== strpos( $f, 'sn-colophon-item--evil' ), 'filter seam adds a line without markup changes' );
add_filter( 'sn_colophon_urls', function ( $urls ) {
	$urls['plugin_repo'] = '';
	$urls['openstation'] = '';
	$urls['theme_changelog'] = '';
	return $urls;
} );
$u = sn_colophon_shortcode();
ok( false === strpos( $u, 'github.com/juanlentino/signal-and-noise-tools"' ) && false !== strpos( $u, 'companion plugin Signal &amp; Noise Tools for SEO' ), 'blanked repo URL → tooling line degrades to the original plain text' );
ok( false === strpos( $u, 'openstation.me' ) && false !== strpos( $u, 'runs inside OpenStation' ), 'blanked OpenStation URL → plain text' );
ok( false === strpos( $u, 'signal-and-noise/blob' ) && false !== strpos( $u, 'Theme v11.1.10-test' ), 'blanked theme changelog → unlinked version, stamp text intact' );
$GLOBALS['__filters'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
