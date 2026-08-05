<?php
/**
 * Tests: the em-dash prose scanner.
 *
 * House style is no em-dashes in PROSE. It is not "no em-dashes anywhere" — several
 * uses are structural and must survive untouched, which is the whole reason this
 * needs a classifier rather than a regex:
 *
 *   - an attribution lead:  "— Juan Lentino, May 7, 2026 · 7 min read"
 *   - the no-value glyph:   a cell whose entire content is "—"
 *   - code and preformatted text, where an em-dash may be literal content
 *   - anything inside a tag or a Gutenberg block comment, which is markup, not copy
 *
 * The v10.48.2 sweep had no such classifier and rewrote AI system prompts and a
 * document-title separator as though they were copy. This scanner exists so the
 * distinction lives in code with tests, instead of in a person's judgement at the
 * moment they run a regex.
 *
 * The scanner is PURE: content in, candidates out. It never writes. Applying a
 * candidate goes through sn-apply's `emdash_replace` type, which reuses
 * drift_replace's locate + fingerprint + splice machinery unchanged.
 *
 * @since plugin v10.51.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  ok  - $m\n"; } else { ++$fail; echo "  FAIL - $m\n"; } }

require __DIR__ . '/../inc/emdash-scan.php';

/** Convenience: only the prose candidates, in document order. */
function prose_of( $content ) {
	return array_values( array_filter( snt_emdash_scan_content( $content ), function ( $c ) { return 'prose' === $c['classification']; } ) );
}
function structural_of( $content ) {
	return array_values( array_filter( snt_emdash_scan_content( $content ), function ( $c ) { return 'structural' === $c['classification']; } ) );
}

echo "Group: STRUCTURAL uses are found but never offered as candidates\n";

// The exact byline from the three pillar essays.
$byline = '<!-- wp:paragraph --><p>— Juan Lentino, May 7, 2026 · 7 min read</p><!-- /wp:paragraph -->';
ok( array() === prose_of( $byline ), 'an attribution lead is not a prose candidate' );
$s = structural_of( $byline );
ok( 1 === count( $s ), 'the attribution lead IS reported, as structural' );
ok( 'attribution_lead' === ( $s[0]['reason'] ?? '' ), 'and it names why: attribution_lead' );

$glyph = '<!-- wp:table --><table><tr><td>Bounce</td><td>—</td></tr></table><!-- /wp:table -->';
ok( array() === prose_of( $glyph ), 'a standalone no-value glyph is not a prose candidate' );
ok( 'no_value_glyph' === ( structural_of( $glyph )[0]['reason'] ?? '' ), 'and it names why: no_value_glyph' );

$code = '<p>Run <code>ots verify — vN.ots</code> to check.</p>';
ok( array() === prose_of( $code ), 'an em-dash inside <code> is not a prose candidate' );
ok( 'code_or_preformatted' === ( structural_of( $code )[0]['reason'] ?? '' ), 'and it names why: code_or_preformatted' );

$pre = "<pre>a — b</pre>";
ok( array() === prose_of( $pre ), 'an em-dash inside <pre> is not a prose candidate' );

$attr = '<p><a href="/x/" title="A — B">link</a> and nothing else.</p>';
ok( array() === prose_of( $attr ), 'an em-dash inside an attribute is not a prose candidate' );
ok( 'inside_markup' === ( structural_of( $attr )[0]['reason'] ?? '' ), 'and it names why: inside_markup' );

$blockcomment = '<!-- wp:group {"note":"a — b"} --><p>Clean sentence.</p><!-- /wp:group -->';
ok( array() === prose_of( $blockcomment ), 'an em-dash inside a Gutenberg block comment is not a prose candidate' );

echo "\nGroup: PROSE candidates, with the replacement the house style implies\n";

// /about/uses/ — explanatory clause, lowercase continuation.
$uses = '<p>The hardware and software I actually reach for — the studio, the instruments, and the tools that keep the signal clean.</p>';
$c = prose_of( $uses );
ok( 1 === count( $c ), 'uses dek yields exactly one prose candidate' );
ok( ' — ' === ( $c[0]['phrase'] ?? '' ), 'the phrase to splice is the spaced em-dash itself' );
ok( ': ' === ( $c[0]['replacement'] ?? '' ), 'lowercase continuation -> colon' );

// A new sentence follows -> period.
$cap = '<p>The cap is reached — AI features are paused until the next calendar month.</p>';
ok( '. ' === ( prose_of( $cap )[0]['replacement'] ?? '' ), 'capitalised continuation -> period' );

// Diagram caption. The owner wants these changed, so a caption is PROSE.
$caption = '<figure><figcaption>Administrative codes — assigned by clerks</figcaption></figure>';
ok( 1 === count( prose_of( $caption ) ), 'a figure caption is prose, not structural' );
ok( ': ' === ( prose_of( $caption )[0]['replacement'] ?? '' ), 'caption gloss -> colon' );

// Unspaced infix.
$tight = '<p>trademarks for voice recordings and performance photos—exactly the linear costs.</p>';
$t = prose_of( $tight );
ok( 1 === count( $t ), 'an unspaced infix em-dash is a prose candidate' );
ok( '—' === ( $t[0]['phrase'] ?? '' ), 'the unspaced phrase is the bare em-dash' );
ok( ', ' === ( $t[0]['replacement'] ?? '' ), 'unspaced infix -> comma' );

echo "\nGroup: a PAIRED parenthetical becomes parentheses, not two separate breaks\n";
$paired = '<p>the protections developed for the software supply chain — code signing, software bill of materials, SLSA provenance — were designed to verify origin.</p>';
$p = prose_of( $paired );
ok( 2 === count( $p ), 'a paired parenthetical yields two linked candidates' );
ok( 'paired_open' === ( $p[0]['pair'] ?? '' ) && 'paired_close' === ( $p[1]['pair'] ?? '' ), 'they are marked open and close' );
ok( ' (' === ( $p[0]['replacement'] ?? '' ), 'the opening dash becomes " ("' );
ok( ') ' === ( $p[1]['replacement'] ?? '' ), 'the closing dash becomes ") "' );

echo "\nGroup: every candidate carries what sn-apply needs to splice it safely\n";
$c = prose_of( $uses )[0];
foreach ( array( 'position', 'phrase', 'replacement', 'context_snippet', 'classification' ) as $k ) {
	ok( array_key_exists( $k, $c ), "candidate carries '$k'" );
}
ok( is_int( $c['position'] ) && $c['position'] > 0, 'position is a raw-content offset' );
ok( substr( $uses, $c['position'], strlen( $c['phrase'] ) ) === $c['phrase'], 'position + phrase address the exact bytes to replace' );
ok( strlen( $c['context_snippet'] ) > 10, 'context_snippet is substantial enough to re-locate after an edit' );

echo "\nGroup: applying a candidate produces the intended prose\n";
$applied = substr_replace( $uses, $c['replacement'], $c['position'], strlen( $c['phrase'] ) );
ok( false !== strpos( $applied, 'reach for: the studio' ), 'splicing the candidate yields the corrected sentence' );
ok( false === strpos( $applied, '—' ), 'and no em-dash remains in that sentence' );

echo "\nGroup: ENTITY forms count too — this is how the CMS actually stores them\n";
// Found by validating against real page content: /about/uses/ and /now/ store the
// dash as &mdash;, not as raw U+2014. A scanner that only matches the raw byte
// sequence reports those pages as clean while the reader plainly sees an em-dash.
$ent = '<p class="sn-uses-dek">The hardware and software I actually reach for &mdash; the studio, the instruments.</p>';
$e = prose_of( $ent );
ok( 1 === count( $e ), '&mdash; is found as a prose candidate' );
ok( ' &mdash; ' === ( $e[0]['phrase'] ?? '' ), 'the phrase spans the whole entity plus its spaces' );
ok( ': ' === ( $e[0]['replacement'] ?? '' ), 'and gets the same colon the raw character would' );
ok( substr( $ent, $e[0]['position'], strlen( $e[0]['phrase'] ) ) === $e[0]['phrase'], 'entity position + phrase address the exact bytes' );
$spliced = substr_replace( $ent, $e[0]['replacement'], $e[0]['position'], strlen( $e[0]['phrase'] ) );
ok( false !== strpos( $spliced, 'reach for: the studio' ), 'splicing an entity candidate yields the corrected sentence' );

$num = '<p>A public answer &#8212; the projects and writing.</p>';
ok( 1 === count( prose_of( $num ) ), 'the numeric entity &#8212; is found too' );
$hex = '<p>A public answer &#x2014; the projects and writing.</p>';
ok( 1 === count( prose_of( $hex ) ), 'the hex entity &#x2014; is found too' );

// Structural rules must apply to entities identically.
$ent_byline = '<p>&mdash; Juan Lentino, May 7, 2026</p>';
ok( array() === prose_of( $ent_byline ), 'an entity attribution lead is still structural' );
$ent_glyph = '<td>&mdash;</td>';
ok( 'no_value_glyph' === ( structural_of( $ent_glyph )[0]['reason'] ?? '' ), 'an entity no-value glyph is still structural' );

echo "\nGroup: the scanner is pure and total\n";
ok( array() === snt_emdash_scan_content( '<p>No dashes at all here.</p>' ), 'content with no em-dash yields no candidates' );
ok( array() === snt_emdash_scan_content( '' ), 'empty content yields no candidates' );
$all = snt_emdash_scan_content( $paired . $byline . $glyph );
ok( 4 === count( $all ), 'mixed content reports every em-dash it saw (2 prose + 2 structural)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
