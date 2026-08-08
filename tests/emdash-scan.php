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
/** Coalesced parentheticals (v10.66.0): ONE candidate carrying both splices. */
function pairs_of( $content ) {
	return array_values( array_filter( snt_emdash_scan_content( $content ), function ( $c ) { return 'prose_pair' === $c['classification']; } ) );
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

echo "\nGroup: a PAIRED parenthetical is ONE candidate carrying BOTH splices\n";
// v10.66.0 CONTRACT CHANGE, and the reason for it. This fixture is the actual
// sentence from the Note "Two kinds of provenance". The scanner used to emit the
// pair as TWO candidates marked paired_open/paired_close, correctly reasoning
// about them as one edit and then leaving the caller to notice the marker and
// group them. Nobody did. Each was applied on its own, so a single logical edit
// wrote twice and — because every publish re-anchors — minted TWO provenance
// ledger versions, v2 being a permanently anchored state where the sentence had
// an opening parenthesis and a closing em-dash.
//
// A pair is now ONE candidate whose `edits` array feeds sn-apply's payload.edits
// directly. Crucially it carries NO top-level phrase/replacement: a caller that
// ignores `edits` and reaches for `phrase` gets an empty string and a loud 422,
// never half a parenthetical. The half-application is unrepresentable rather than
// merely discouraged.
$paired = '<p>the protections developed for the software supply chain — code signing, software bill of materials, SLSA provenance — were designed to verify origin.</p>';
ok( 0 === count( prose_of( $paired ) ), 'a parenthetical no longer emits loose single candidates' );
$p = pairs_of( $paired );
ok( 1 === count( $p ), 'a paired parenthetical yields exactly ONE candidate' );
ok( 'paired' === ( $p[0]['pair'] ?? '' ), 'it is marked as a pair' );
ok( 2 === count( $p[0]['edits'] ?? array() ), 'it carries both splices in `edits`' );
ok( ' (' === ( $p[0]['edits'][0]['replacement'] ?? '' ), 'edit 1 opens the parenthesis' );
ok( ') ' === ( $p[0]['edits'][1]['replacement'] ?? '' ), 'edit 2 closes it' );
ok( ( $p[0]['edits'][0]['position'] ?? -1 ) < ( $p[0]['edits'][1]['position'] ?? -1 ), 'the edits are ordered open-then-close' );
ok( '' === ( $p[0]['phrase'] ?? 'x' ) && '' === ( $p[0]['replacement'] ?? 'x' ), 'it carries NO top-level phrase/replacement — half-applying it is unrepresentable' );
ok( isset( $p[0]['edits'][0]['phrase'], $p[0]['edits'][0]['context_snippet'] ), 'each edit carries what the splice needs' );

// Applying both edits must reproduce exactly the intended sentence.
$after = $paired;
foreach ( array_reverse( $p[0]['edits'] ) as $e ) { // descending, as sn-apply does
	$after = substr_replace( $after, $e['replacement'], $e['position'], strlen( $e['phrase'] ) );
}
ok( false !== strpos( $after, 'supply chain (code signing' ), 'applying the pair opens correctly' );
ok( false !== strpos( $after, 'SLSA provenance) were designed' ), 'applying the pair closes correctly' );
ok( false === strpos( $after, '—' ), 'no em-dash survives the paired apply' );

echo "\nGroup: an UNpaired prose dash is still a plain single candidate\n";
$single_run = '<p>one clause — and the rest of the sentence continues here.</p>';
ok( 1 === count( prose_of( $single_run ) ), 'a lone prose dash is still a single candidate' );
ok( 0 === count( pairs_of( $single_run ) ), 'a lone prose dash is not reported as a pair' );

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
ok( 3 === count( $all ), 'mixed content yields 3 rows (the parenthetical is ONE, plus 2 structural)' );
// The count changed in v10.66.0, but the property it was protecting did not:
// no em-dash is ever silently dropped. A pair row accounts for TWO dashes, so
// assert on dashes ACCOUNTED FOR rather than on rows — that survives the
// coalescing and would still catch a genuinely lost occurrence.
$accounted = 0;
foreach ( $all as $row ) {
	$accounted += isset( $row['edits'] ) ? count( $row['edits'] ) : 1;
}
ok( 4 === $accounted, 'all 4 em-dashes are still accounted for, none silently dropped' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
