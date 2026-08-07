<?php
/**
 * Tests for inc/provenance-maturity-page.php — the [sn_provenance_maturity]
 * static explainer (the analytics-maturity skeleton on the provenance system).
 * Run: php tests/provenance-maturity-page.php
 * @since plugin v10.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', 'test' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__shortcodes'] = array();
function add_shortcode( $tag, $cb ) { $GLOBALS['__shortcodes'][ $tag ] = $cb; }
// Models core: only default keys survive; missing keys take the default.
function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $defaults as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
// Minimal filter engine: one callback per tag is all these tests need.
$GLOBALS['__filters'] = array();
function add_filter( $tag, $cb ) { $GLOBALS['__filters'][ $tag ] = $cb; }
function remove_all_filters( $tag ) { unset( $GLOBALS['__filters'][ $tag ] ); }
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['__filters'][ $tag ] ) ? call_user_func( $GLOBALS['__filters'][ $tag ], $value ) : $value;
}
$GLOBALS['__enq'] = array(); // recorded [handle, src] pairs.
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( $handle, (string) $src );
	return true;
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://example.com/wp-content/plugins/snt/' . ltrim( (string) $path, '/' );
}

require __DIR__ . '/../inc/provenance-maturity-page.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Group: registration + contract\n";
ok( isset( $GLOBALS['__shortcodes']['sn_provenance_maturity'] ) && 'sn_prov_maturity_shortcode' === $GLOBALS['__shortcodes']['sn_provenance_maturity'], 'shortcode registered on load' );
ok( array() === $GLOBALS['__enq'], 'loading the file enqueues nothing — the stylesheet rides the render, not the pageload' );
ok( array( 'canonical', 'signed', 'anchored', 'verifiable' ) === array_keys( sn_prov_maturity_layers() ), 'layer slugs in walk order: canonical, signed, anchored, verifiable' );
ok( array( 'notes' ) === array_keys( sn_prov_maturity_scope() ), 'scope defaults to notes only — planned rows (pages, media) moved to the hub-wide roadmap (v10.55.1)' );

echo "\nGroup: full format (the default)\n";
ob_start();
$html   = sn_prov_maturity_shortcode();
$echoed = ob_get_clean();
ok( '' === $echoed, 'returns, never echoes (shortcode contract)' );
ok( is_string( $html ) && 0 === strpos( $html, '<div class="sn-prov-maturity sn-prov-maturity--full">' ), 'root div carries sn-prov-maturity--full' );
ok( false !== strpos( $html, 'sn-prov-maturity-table' ), 'renders the layer table' );
ok( false !== strpos( $html, '<h2>' ) && false !== strpos( $html, 'sn-prov-maturity-principles' ) && false !== strpos( $html, 'sn-prov-maturity-scope' ), 'full = intro + table + principles + scope' );
foreach ( array( 'Canonical', 'Signed', 'Anchored', 'Verifiable' ) as $layer ) {
	ok( false !== strpos( $html, '>' . $layer . '<' ), "names the $layer layer" );
}
ok( 1 === count( $GLOBALS['__enq'] ), 'render enqueues the stylesheet exactly once' );
ok( 'sn-prov-maturity-front' === $GLOBALS['__enq'][0][0], 'enqueue handle is sn-prov-maturity-front' );
ok( 'assets/provenance-maturity-front.css' === substr( $GLOBALS['__enq'][0][1], -strlen( 'assets/provenance-maturity-front.css' ) ), 'enqueue src points at assets/provenance-maturity-front.css' );
ok( file_exists( SNT_PATH . 'assets/provenance-maturity-front.css' ), 'the stylesheet exists on disk' );

echo "\nGroup: layer copy — verified claims only\n";
// Canonical: the normalization + hash story (sn-normalize-v1, provenance-core.php).
ok( false !== strpos( $html, 'sn-normalize-v1' ), 'canonical names the shipped normalization algo' );
ok( false !== strpos( $html, 'SHA-256' ), 'canonical names the hash' );
ok( false !== strpos( $html, 'any independent implementation can rebuild' ), 'canonical states the rebuildability claim' );
// Signed: Ed25519 + did:web + the external pin (provenance-did.php, v9.72.0).
ok( false !== strpos( $html, 'Ed25519' ) && false !== strpos( $html, 'did:web' ), 'signed names the key type and the DID' );
ok( false !== strpos( $html, 'DNS' ), 'signed names the DNS pin — trust that leaves the site' );
// Anchored: OTS → Bitcoin, append-only chain (worker + ledger).
ok( false !== strpos( $html, 'OpenTimestamps' ) && false !== strpos( $html, 'Bitcoin' ), 'anchored names OTS and Bitcoin' );
ok( false !== strpos( $html, 'public git ledger' ), 'anchored names the public ledger' );
// Verifiable: browser-side /verify (provenance-verify.php + prov-verify-core.js).
ok( false !== strpos( $html, 'reader&#039;s own browser' ), 'verifiable states the client-side rule (escaped apostrophe pinned)' );
ok( false !== strpos( $html, 'signature, content hash, live match, Bitcoin anchor' ), 'verifiable names the four verdicts in verifier order' );

echo "\nGroup: honesty principles — eight, each verifiable\n";
ok( false !== strpos( $html, 'never truth' ) && false !== strpos( $html, 'provably dated' ), 'principle: integrity and time, never truth' );
ok( false !== strpos( $html, 'vouching for itself proves nothing' ), 'principle: verification is client-side' );
ok( false !== strpos( $html, 'quietly swapped key' ), 'principle: external key pin' );
ok( false !== strpos( $html, 'append' ) && false !== strpos( $html, 'never overwrite' ), 'principle: versions append' );
ok( false !== strpos( $html, 'never impersonates cryptographic failure' ), 'principle: network vs crypto failure' );
ok( false !== strpos( $html, 'password-protected' ), 'principle: gated content never reaches the ledger' );
ok( false !== strpos( $html, 'not an inclusion proof' ), 'principle: anchor walk is an attestation, worded per the verifier' );
ok( false !== strpos( $html, 'No certificate authority' ), 'principle: no CA, no annual ritual' );
ok( 8 === substr_count( sn_prov_maturity_principles_html(), '<li>' ), 'exactly 8 principles' );

echo "\nGroup: scope — the expansion flags\n";
$scope_html = sn_prov_maturity_scope_html();
ok( false !== strpos( $scope_html, 'sn-prov-maturity-scope-badge--live' ), 'scope renders the live badge class' );
ok( 1 === substr_count( $scope_html, '--live' ) && 0 === substr_count( $scope_html, '--planned' ), 'defaults: notes live, NO planned badges — the future tense lives on the roadmap page' );
ok( false !== strpos( $scope_html, '>Notes<' ) && false === strpos( $scope_html, '>Pages<' ) && false === strpos( $scope_html, '>Media<' ), 'scope names only the anchored surface — Pages/Media wait on the roadmap page, not here' );
// The expansion seam survives the default's slim-down: the site-provenance
// arcs still ship page-facing rows by FILTER (planned first, then flipped
// live), zero re-coding — the seam accepts what the default no longer lists.
add_filter( 'sn_prov_maturity_scope', function ( $scope ) {
	$scope['pages'] = array( 'Pages', 'planned' );
	return $scope;
} );
$flipped = sn_prov_maturity_scope_html();
ok( 1 === substr_count( $flipped, '--live' ) && 1 === substr_count( $flipped, '--planned' ), 'a filter-added planned row renders — the expansion is an array add, not a re-code' );
// Hostile status value from a filter never reaches the class attribute raw.
add_filter( 'sn_prov_maturity_scope', function ( $scope ) {
	$scope['media'] = array( 'Media', '"><script>alert(1)</script>' );
	return $scope;
} );
$hostile = sn_prov_maturity_scope_html();
ok( false === strpos( $hostile, 'script>' ) && false !== strpos( $hostile, 'sn-prov-maturity-scope-badge--planned' ), 'unknown status falls back to planned — the whitelist guards the class attribute' );
remove_all_filters( 'sn_prov_maturity_scope' );

echo "\nGroup: escaping pins\n";
ok( false === strpos( $html, '<script' ), 'no script tags anywhere' );
ok( false !== strpos( $html, 'provably mine' ), 'first principle present for the escape pin below' );
ok( false === strpos( $html, "reader's own browser" ), 'raw apostrophes never reach the page unescaped' );

echo "\nGroup: static by design\n";
ok( 1 !== preg_match( '/\b\d{2,}(,\d{3})*\s+(notes|commits|anchors)\b/i', $html ), 'no live counts baked into a public page' );

echo "\nGroup: format=table\n";
$t = sn_prov_maturity_shortcode( array( 'format' => 'table' ) );
ok( 0 === strpos( $t, '<div class="sn-prov-maturity sn-prov-maturity--table">' ), 'root div carries sn-prov-maturity--table' );
ok( false !== strpos( $t, '<table class="sn-prov-maturity-table">' ), 'table variant renders the table' );
ok( false === strpos( $t, '<h2>' ) && false === strpos( $t, 'sn-prov-maturity-principles' ) && false === strpos( $t, 'sn-prov-maturity-scope' ) && false === strpos( $t, 'sn-prov-maturity-strip' ), 'table variant renders ONLY the table' );

echo "\nGroup: format=principles\n";
$p = sn_prov_maturity_shortcode( array( 'format' => 'principles' ) );
ok( 0 === strpos( $p, '<div class="sn-prov-maturity sn-prov-maturity--principles">' ), 'root div carries sn-prov-maturity--principles' );
ok( false !== strpos( $p, 'sn-prov-maturity-principles' ) && 8 === substr_count( $p, '<li>' ), 'principles variant renders the 8-item list' );
ok( false !== strpos( $p, '<h3>Honest by construction</h3>' ), 'principles variant keeps the house heading' );
ok( false === strpos( $p, 'sn-prov-maturity-table' ) && false === strpos( $p, '<h2>' ) && false === strpos( $p, 'sn-prov-maturity-scope' ), 'principles variant renders ONLY the principles section' );

echo "\nGroup: format=scope\n";
$s = sn_prov_maturity_shortcode( array( 'format' => 'scope' ) );
ok( 0 === strpos( $s, '<div class="sn-prov-maturity sn-prov-maturity--scope">' ), 'root div carries sn-prov-maturity--scope' );
ok( false !== strpos( $s, 'sn-prov-maturity-scope-badge--live' ), 'scope variant renders the badges' );
ok( false === strpos( $s, 'sn-prov-maturity-table' ) && false === strpos( $s, 'sn-prov-maturity-principles' ) && false === strpos( $s, '<h2>' ), 'scope variant renders ONLY the scope section' );

echo "\nGroup: format=compact (value-pinned whole)\n";
$c                = sn_prov_maturity_shortcode( array( 'format' => 'compact' ) );
$expected_compact = '<div class="sn-prov-maturity sn-prov-maturity--compact">'
	. '<p class="sn-prov-maturity-compact-intro">Every published Note is canonicalized, signed, Bitcoin-anchored, and verifiable in your own browser - provenance honest by construction.</p>'
	. '<div class="sn-prov-maturity-strip">'
	. '<span class="sn-prov-maturity-badge sn-prov-maturity-badge--canonical">Canonical</span>'
	. '<span class="sn-prov-maturity-badge sn-prov-maturity-badge--signed">Signed</span>'
	. '<span class="sn-prov-maturity-badge sn-prov-maturity-badge--anchored">Anchored</span>'
	. '<span class="sn-prov-maturity-badge sn-prov-maturity-badge--verifiable">Verifiable</span>'
	. '</div></div>';
ok( $expected_compact === $c, 'compact variant is byte-identical to the pinned shape (one sentence + badge strip)' );
// The compact-intro margin must WIN its cascade fight (the maturity-front.css
// lesson, pinned here from day one): the generic `.sn-prov-maturity p` rule is
// 0,1,1 and a bare class selector is only 0,1,0 — permanently overridden.
$css = (string) file_get_contents( SNT_PATH . 'assets/provenance-maturity-front.css' );
ok( false !== strpos( $css, '.sn-prov-maturity p.sn-prov-maturity-compact-intro{margin:0 0 .75rem}' ), 'stylesheet carries the compact-intro margin at winning specificity (0,2,1)' );
ok( 0 === preg_match( '/(?<!p)\.sn-prov-maturity-compact-intro\s*\{/', $css ), 'the dead 0,1,0 bare-class form is absent' );
ok( false !== strpos( $css, '.sn-prov-maturity p{margin:0 0 1.25rem}' ), 'the generic paragraph rule exists for the full format' );
ok( false !== strpos( $css, 'sn-prov-maturity-scope-badge--planned' ), 'stylesheet styles the planned scope badge (the expansion flag is visible, not just semantic)' );

echo "\nGroup: whitelist fallback (pinned)\n";
$bogus = sn_prov_maturity_shortcode( array( 'format' => 'bogus' ) );
ok( $bogus === $html, 'unknown format falls back byte-identically to full' );
$inject = sn_prov_maturity_shortcode( array( 'format' => '"><script>alert(1)</script>' ) );
ok( $inject === $html && false === strpos( $inject, 'script>' ), 'a hostile format value hits the whitelist, never the class attribute' );
$empty = sn_prov_maturity_shortcode( '' );
ok( $empty === $html, 'the bare no-atts form (core passes an empty string) renders full' );
$upper = sn_prov_maturity_shortcode( array( 'format' => 'TABLE' ) );
ok( $upper === $html, 'the whitelist is exact-match (case-sensitive, pinned) — TABLE falls back to full' );

echo "\nGroup: every render enqueues (idempotence is core's job)\n";
ok( count( $GLOBALS['__enq'] ) >= 6, 'each render call passed through the enqueue gate' );
$handles = array_unique( array_map( function ( $e ) { return $e[0]; }, $GLOBALS['__enq'] ) );
ok( array( 'sn-prov-maturity-front' ) === array_values( $handles ), 'only the sn-prov-maturity-front handle is ever enqueued' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
