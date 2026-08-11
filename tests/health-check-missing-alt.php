<?php
/**
 * Missing-alt check — inline-SVG coverage + alt-text quality (R1).
 *
 * Two gaps this pins, both from docs/r1-prep.md:
 *
 *   1. COVERAGE. Before R1 the check saw inline <img> only. A post whose only
 *      graphic is an inline <svg> was never even SELECTED, because the content
 *      query pre-filtered on `post_content LIKE '%<img%'` — so extending the
 *      parser alone would have produced dead code. The $wpdb stub below
 *      therefore EVALUATES the LIKE clauses (and their AND/OR joiner) against
 *      the corpus rather than returning a fixed row set: if the query stops
 *      selecting SVG-only posts, or joins the two LIKEs with AND, these tests
 *      go red. A stub that just returned every row would make the headline
 *      assertion vacuous.
 *
 *   2. THE SVG TRAP. <svg> has no alt attribute. Its accessible name comes from
 *      a DIRECT-CHILD <title>, or aria-label / aria-labelledby; aria-hidden or
 *      role=presentation makes it decorative. Anything looking for `alt=` on an
 *      <svg> reports 100% failure, and "fixing" it by adding alt="" to an <svg>
 *      is invalid markup that changes nothing for a screen reader.
 *
 *   3. QUALITY. Present-but-useless alt: filename echoes, caption duplicates,
 *      and alt naming a category rather than the picture. These are FINDINGS
 *      ONLY — they route through the same human acceptance as the coverage
 *      sweep and never write.
 *
 * @since plugin v10.77.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

/* ── Corpus ──────────────────────────────────────────────────────────── */
$GLOBALS['__posts']          = array(); // rows: ID, post_title, post_content
$GLOBALS['__att_no_alt']     = array(); // rows for the "attachment has no alt" query
$GLOBALS['__att_with_alt']   = array(); // rows for the "attachment alt quality" query
$GLOBALS['__seen_sql']       = array(); // every SQL string the check issued
$GLOBALS['__wrote']          = false;   // tripped by any write helper

/**
 * A $wpdb that actually evaluates the content query's WHERE clause.
 *
 * It pulls every `post_content LIKE '%needle%'` literal out of the SQL, notes
 * whether they are joined by OR or AND, and selects a corpus row only if the
 * clause genuinely matches. That is what makes "an SVG-only post is selected"
 * a real assertion instead of a restatement of the fixture.
 */
class SnAltWpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';

	public function get_results( $sql, $output = null ) {
		$GLOBALS['__seen_sql'][] = $sql;

		if ( false !== strpos( $sql, "post_type = 'attachment'" ) ) {
			// Two attachment queries share a shape; the missing-alt one asks for NULL/empty.
			return ( false !== strpos( $sql, 'IS NULL' ) )
				? $GLOBALS['__att_no_alt']
				: $GLOBALS['__att_with_alt'];
		}

		// Content query — evaluate the post_content LIKE clause for real.
		$out = array();
		foreach ( $GLOBALS['__posts'] as $row ) {
			if ( self::content_clause_matches( $sql, (string) $row['post_content'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** Evaluate every `post_content LIKE '%x%'` in $sql against $content. */
	private static function content_clause_matches( $sql, $content ) {
		if ( ! preg_match_all( "/post_content\s+LIKE\s+'%([^%']*)%'/i", $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			// No content predicate at all — the query selects everything.
			return true;
		}
		$needles = array();
		foreach ( $m[1] as $hit ) { $needles[] = $hit[0]; }

		// Determine the joiner from the text between the first two clauses.
		$joiner = 'OR';
		if ( count( $m[0] ) > 1 ) {
			$end     = $m[0][0][1] + strlen( $m[0][0][0] );
			$between = substr( $sql, $end, $m[0][1][1] - $end );
			if ( preg_match( '/\bAND\b/i', $between ) ) { $joiner = 'AND'; }
		}

		$hits = 0;
		foreach ( $needles as $n ) {
			if ( '' !== $n && false !== stripos( $content, $n ) ) { ++$hits; }
		}
		return ( 'AND' === $joiner ) ? ( $hits === count( $needles ) ) : ( $hits > 0 );
	}
}
$GLOBALS['wpdb'] = new SnAltWpdb();

/* ── WP stubs ────────────────────────────────────────────────────────── */
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
// Writes must never happen from a health CHECK. If the implementation ever
// reaches for one of these, the "never auto-applies" assertion goes red.
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta() { $GLOBALS['__wrote'] = true; return true; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post() { $GLOBALS['__wrote'] = true; return 1; }
}

require_once __DIR__ . '/../inc/health-alt-quality.php';
require_once __DIR__ . '/../inc/health-check-missing-alt.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "Missing-alt — inline-SVG coverage + alt quality (v10.77.0)\n\n";

/* ═════════════════════════════════════════════════════════════════════
 * 1. THE SVG TRAP — accessible name, not alt=
 * ═════════════════════════════════════════════════════════════════════ */

$svg_title = "<svg role=\"img\" viewBox=\"0 0 24 24\">\n\t<title>A magnifying glass</title>\n\t<path d=\"M0 0h24\"/>\n</svg>";
ok( array() === sn_health_extract_inline_svgs_without_name( $svg_title ),
	'SVG named by a direct-child <title> passes — and the parser spans NEWLINES' );

ok( array() === sn_health_extract_inline_svgs_without_name( '<svg role="img" aria-label="Search"><path d="M0 0"/></svg>' ),
	'SVG named by aria-label passes' );

ok( array() === sn_health_extract_inline_svgs_without_name( '<svg role="img" aria-labelledby="t1"><path d="M0 0"/></svg>' ),
	'SVG named by aria-labelledby passes' );

ok( array() === sn_health_extract_inline_svgs_without_name( '<svg aria-hidden="true"><path d="M0 0"/></svg>' ),
	'SVG marked aria-hidden="true" passes as decorative' );

ok( array() === sn_health_extract_inline_svgs_without_name( '<svg role="presentation"><path d="M0 0"/></svg>' ),
	'SVG with role="presentation" passes as decorative' );

ok( 1 === count( sn_health_extract_inline_svgs_without_name( '<svg viewBox="0 0 24 24"><path d="M0 0"/></svg>' ) ),
	'a bare <svg> with no name and no decorative flag is FLAGGED' );

// The alt= trap, stated as an assertion: alt on an <svg> is invalid markup and
// must not be accepted as a name, or every "fix" would be a no-op for AT.
ok( 1 === count( sn_health_extract_inline_svgs_without_name( '<svg alt="a search icon"><path d="M0 0"/></svg>' ) ),
	'alt="" on an <svg> does NOT count as an accessible name (invalid markup, invisible to AT)' );

ok( 1 === count( sn_health_extract_inline_svgs_without_name( '<svg><g><title>inside a group</title></g><path d="M0 0"/></svg>' ) ),
	'a <title> nested inside a child element does NOT name the root <svg>' );

ok( 1 === count( sn_health_extract_inline_svgs_without_name( '<svg role="img"><title>   </title><path d="M0 0"/></svg>' ) ),
	'an empty/whitespace <title> is not an accessible name' );

ok( 1 === count( sn_health_extract_inline_svgs_without_name( '<svg aria-label="  "><path d="M0 0"/></svg>' ) ),
	'an empty/whitespace aria-label is not an accessible name' );

ok( 2 === count( sn_health_extract_inline_svgs_without_name(
		'<p>a</p><svg><path d="M1"/></svg><p>b</p><svg role="img"><title>Named</title></svg><svg><circle r="2"/></svg>' ) ),
	'multiple SVGs in one body are counted independently (2 bare, 1 named)' );

/* ═════════════════════════════════════════════════════════════════════
 * 2. QUALITY predicate
 * ═════════════════════════════════════════════════════════════════════ */

ok( 'filename_echo' === sn_health_alt_quality_problem( 'hero-image-2.png', 'hero-image-2.png', '' ),
	'a filename-echo alt (hero-image-2.png) is flagged' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'hero image 2', 'hero-image-2.png', '' ),
	'a de-hyphenated filename echo is flagged (separators normalised)' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'DSC_0041.JPG', '', '' ),
	'an alt ending in an image extension is a filename echo even with no filename to compare' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'photo', 'photo-1024x576.jpg', '' ),
	'the WordPress size suffix is stripped before comparing (photo-1024x576.jpg)' );

/* The Services-page regression (v10.81.0). Every image on /services/ is named
 * <thing>-min.png by the build pipeline, so the stem comparison saw
 * "production min" vs "production" and the echo rule MISSED. The finding still
 * fired -- as "single word" -- which named the wrong defect and read to the
 * owner as a false positive. Optimiser and WP suffixes must come off first. */
ok( 'filename_echo' === sn_health_alt_quality_problem(
		'Production', 'https://juanlentino.com/wp-content/uploads/2026/02/production-min.png', '' ),
	'the -min optimiser suffix is stripped before comparing (production-min.png)' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'Portrait', 'portrait-scaled.jpg', '' ),
	'WordPress\'s own -scaled suffix on large uploads is stripped before comparing' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'Hero', 'hero-min-1024x576.png', '' ),
	'stacked suffixes strip repeatedly (hero-min-1024x576.png -> hero)' );

ok( 'filename_echo' === sn_health_alt_quality_problem( 'Cover', 'cover@2x.png', '' ),
	'a retina @2x suffix is stripped before comparing' );

ok( 'caption_duplicate' === sn_health_alt_quality_problem(
		'The 1954 ledger, reopened', 'ledger.jpg', 'The 1954 ledger, reopened.' ),
	'an alt duplicating its caption is flagged (punctuation/case insensitive)' );

/* WORD COUNT WAS THE WRONG TEST (v10.81.0). "a single word cannot describe a
 * content image" is not an accessibility rule -- WCAG asks for an equivalent
 * alternative, and for a portrait, a logo or a planet ONE word is the complete
 * and correct one. What actually fails a screen reader is naming the CATEGORY
 * instead of the content, at any length. Judge the vocabulary, not the count. */
ok( 'generic_alt' === sn_health_alt_quality_problem( 'Chart', 'q3-revenue.png', '' ),
	'an alt naming the category rather than the content is flagged ("Chart")' );

ok( 'generic_alt' === sn_health_alt_quality_problem( 'an image', 'dsc-4471.jpg', '' ),
	'a MULTI-word generic alt is flagged too -- the old word count let this through' );

ok( 'generic_alt' === sn_health_alt_quality_problem( 'Photo 2', 'dsc-4471.jpg', '' ),
	'a trailing index does not rescue a generic alt ("Photo 2")' );

ok( '' === sn_health_alt_quality_problem( 'Saturn', 'dsc-4471.jpg', '' ),
	'a one-word alt that names the SUBJECT is correct and is NOT flagged' );

ok( '' === sn_health_alt_quality_problem( 'Beyonce', 'img-0042.jpg', '' ),
	'a one-word proper noun on a portrait is the complete alternative -- not a finding' );

ok( '' === sn_health_alt_quality_problem(
		'Two archivists comparing a printed provenance ledger against a screen',
		'hero-image-2.png',
		'Working session, March 2026' ),
	'a genuinely descriptive alt is NOT flagged' );

ok( '' === sn_health_alt_quality_problem( '', 'hero-image-2.png', '' ),
	'empty alt is not a QUALITY finding — it belongs to the coverage pass (decorative vs missing)' );

/* ─── NORMALISATION: what a screen reader actually SAYS ───────────────────
 * post_content stores entities, so a heading reads "OPERATIONS &amp; AI
 * STRATEGY". Left undecoded, `&amp;` folds to the word "amp" and the
 * comparison is against a string nobody ever hears. And "&" is spoken "and",
 * so mapping it is not fuzzy matching — it is the spoken form. */
ok( 'operations and ai strategy' === sn_health_normalise_alt_text( 'OPERATIONS &amp; AI STRATEGY' ),
	'entities are decoded before folding, so &amp; does not survive as the word "amp"' );

ok( sn_health_normalise_alt_text( 'Operations and AI Strategy' )
		=== sn_health_normalise_alt_text( 'OPERATIONS & AI STRATEGY' ),
	'"&" and "and" fold together — a screen reader speaks them identically' );

/* ─── HEADING DUPLICATE, and why it outranks the filename echo ─────────── */

ok( 'heading_duplicate' === sn_health_alt_quality_problem(
		'Production', 'production-min.png', '', 'PRODUCTION' ),
	'an alt duplicating the heading beside it beats filename_echo — the more specific reason wins' );

ok( 'heading_duplicate' === sn_health_alt_quality_problem(
		'Operations and AI Strategy', 'operations-ai-strategy-min.png', '', 'OPERATIONS &amp; AI STRATEGY' ),
	'the &-vs-and card is caught by the heading rule, which the filename stem cannot see' );

ok( 'caption_duplicate' === sn_health_alt_quality_problem(
		'beach sunset', 'beach-sunset.jpg', 'beach sunset' ),
	'caption_duplicate also outranks filename_echo: "already announced" is the more useful reason' );

ok( '' === sn_health_alt_quality_problem(
		'Two archivists comparing a printed ledger against a screen',
		'hero-image-2.png', '', 'Methodology' ),
	'an UNRELATED heading below the image is not a duplicate — exact match after folding is required' );

// Filename deliberately unrelated: with production-min.png this would return
// filename_echo and pass for the wrong reason, proving nothing about headings.
ok( '' === sn_health_alt_quality_problem( 'Production', 'dsc-4471.jpg', '', 'Production notes' ),
	'a heading that merely CONTAINS the alt is not a duplicate' );

/* ═════════════════════════════════════════════════════════════════════
 * 3. THE QUERY REGRESSION — an SVG-only post must be SELECTED
 * ═════════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'] = array(
	// No <img> ANYWHERE. Under the old `LIKE '%<img%'` pre-filter this row is
	// invisible, so the SVG parser could never fire on it.
	array( 'ID' => 11, 'post_title' => 'SVG only', 'post_content' => "<p>Intro.</p>\n<svg viewBox=\"0 0 24 24\">\n\t<path d=\"M0 0h24\"/>\n</svg>" ),
	array( 'ID' => 12, 'post_title' => 'Named SVG', 'post_content' => '<svg role="img"><title>A named icon</title><path d="M0"/></svg>' ),
	array( 'ID' => 13, 'post_title' => 'Bare img', 'post_content' => '<img src="https://example.test/a.png">' ),
	array( 'ID' => 14, 'post_title' => 'No graphics', 'post_content' => '<p>Just prose, nothing to see.</p>' ),
);
$GLOBALS['__att_no_alt']   = array();
$GLOBALS['__att_with_alt'] = array();
$GLOBALS['__seen_sql']     = array();

$check = sn_health_check_missing_alt();
$by_type = array();
foreach ( $check['findings'] as $f ) { $by_type[ $f['subject_type'] ][] = (int) $f['subject_id']; }

ok( isset( $by_type['inline_svg'] ) && in_array( 11, $by_type['inline_svg'], true ),
	'REGRESSION GUARD: the SVG-only post (11) is SELECTED BY THE QUERY and flagged' );

ok( ! isset( $by_type['inline_svg'] ) || ! in_array( 12, $by_type['inline_svg'], true ),
	'the post whose SVG has a <title> is selected but not flagged' );

ok( isset( $by_type['inline_img'] ) && in_array( 13, $by_type['inline_img'], true ),
	'the pre-existing inline-<img> case still works (no regression)' );

$all_ids = array();
foreach ( $check['findings'] as $f ) { $all_ids[] = (int) $f['subject_id']; }
ok( ! in_array( 14, $all_ids, true ), 'a post with no graphics raises nothing' );

/* ═════════════════════════════════════════════════════════════════════
 * 4. Quality findings ride the same check, and NEVER write
 * ═════════════════════════════════════════════════════════════════════ */

$GLOBALS['__posts'] = array(
	array(
		'ID' => 20, 'post_title' => 'Echo',
		'post_content' => '<figure class="wp-block-image"><img src="https://example.test/hero-image-2.png" alt="hero-image-2.png"/><figcaption>A working session.</figcaption></figure>',
	),
	array(
		'ID' => 21, 'post_title' => 'Caption dupe',
		'post_content' => '<figure><img src="https://example.test/ledger.jpg" alt="The 1954 ledger, reopened"/><figcaption>The 1954 ledger, reopened.</figcaption></figure>',
	),
	array(
		'ID' => 22, 'post_title' => 'Good',
		'post_content' => '<figure><img src="https://example.test/ledger.jpg" alt="Two archivists comparing a printed ledger against a screen"/><figcaption>Working session.</figcaption></figure>',
	),
);
$GLOBALS['__att_no_alt']   = array();
$GLOBALS['__att_with_alt'] = array(
	array( 'ID' => 90, 'post_title' => 'Att echo', 'guid' => 'https://example.test/uploads/beach-sunset.jpg', 'post_excerpt' => 'On the coast', 'meta_value' => 'beach sunset' ),
	array( 'ID' => 91, 'post_title' => 'Att good',  'guid' => 'https://example.test/uploads/beach-sunset.jpg', 'post_excerpt' => 'On the coast', 'meta_value' => 'Low sun over an empty beach, tide going out' ),
);
$GLOBALS['__wrote']    = false;
$GLOBALS['__seen_sql'] = array();

$q     = sn_health_check_missing_alt();
$qtype = array();
foreach ( $q['findings'] as $f ) { $qtype[ $f['subject_type'] ][] = (int) $f['subject_id']; }

ok( isset( $qtype['inline_img_alt_quality'] ) && in_array( 20, $qtype['inline_img_alt_quality'], true ),
	'inline <img> with a filename-echo alt is flagged as a quality finding' );

ok( isset( $qtype['inline_img_alt_quality'] ) && in_array( 21, $qtype['inline_img_alt_quality'], true ),
	'inline <img> whose alt duplicates its <figcaption> is flagged' );

ok( ! isset( $qtype['inline_img_alt_quality'] ) || ! in_array( 22, $qtype['inline_img_alt_quality'], true ),
	'inline <img> with descriptive alt raises no quality finding' );

ok( isset( $qtype['attachment_alt_quality'] ) && in_array( 90, $qtype['attachment_alt_quality'], true )
	&& ! in_array( 91, $qtype['attachment_alt_quality'], true ),
	'attachment alt quality: the filename echo (90) is flagged, the descriptive one (91) is not' );

ok( false === $GLOBALS['__wrote'],
	'NEVER AUTO-APPLIES: running the check performed no post/meta write of any kind' );

// The two attachment passes must use SEPARATE queries, or the LIMIT budget for
// alt-less attachments gets spent on healthy rows and coverage silently shrinks.
$att_queries = 0;
foreach ( $GLOBALS['__seen_sql'] as $sql ) {
	if ( false !== strpos( $sql, "post_type = 'attachment'" ) ) { ++$att_queries; }
}
ok( 2 === $att_queries,
	'coverage and quality use separate attachment queries (each keeps its own LIMIT budget)' );

$has_finding_only_shape = true;
foreach ( $q['findings'] as $f ) {
	foreach ( array( 'subject_type', 'subject_id', 'subject_label', 'edit_url', 'note' ) as $k ) {
		if ( ! array_key_exists( $k, $f ) ) { $has_finding_only_shape = false; }
	}
}
ok( $has_finding_only_shape, 'every finding (old and new) carries the shared finding shape' );

/* ═════════════════════════════════════════════════════════════════════
 * 5. HEADING DUPLICATE — the /services/ card, in STORED block markup
 *
 * The fixture below is post_content as WordPress actually stores it (page 395,
 * read 2026-08-11), not the rendered HTML. That distinction is the test: between
 * </figure> and <h3> sit FIVE block-delimiter comments and one <p>. A lookahead
 * that counts raw tags, or that fails to ignore `<!-- wp:* -->`, finds nothing
 * here while looking correct against rendered markup.
 * ═════════════════════════════════════════════════════════════════════ */

$services_card = <<<'HTML'
<!-- wp:image {"sizeSlug":"large","className":"sn-service-image"} -->
<figure class="wp-block-image size-large has-custom-border sn-service-image"><img src="https://juanlentino.com/wp-content/uploads/2026/02/production-min.png" alt="Production" class="has-border-color" style="border-color:var(--wp--preset--color--concrete);border-width:1px"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph {"className":"sn-catalog-number"} -->
<p class="sn-catalog-number" style="margin-top:var(--wp--preset--spacing--20)">№ 01</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading has-bone-color has-text-color" style="font-size:1.6rem">PRODUCTION</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p class="has-rust-color has-text-color">From the first idea to the final master, I build the entire sonic architecture of your project.</p>
<!-- /wp:paragraph -->
HTML;

$amp_card = <<<'HTML'
<figure class="wp-block-image sn-service-image"><img src="https://juanlentino.com/wp-content/uploads/2026/03/operations-ai-strategy-min.png" alt="Operations and AI Strategy"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph {"className":"sn-catalog-number"} -->
<p class="sn-catalog-number">№ 05</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">OPERATIONS &amp; AI STRATEGY</h3>
<!-- /wp:heading -->
HTML;

$parsed = sn_health_extract_inline_imgs_with_alt( $services_card );
ok( 1 === count( $parsed ) && 'PRODUCTION' === ( $parsed[0]['heading'] ?? '' ),
	'the heading is found across FIVE block-delimiter comments and one intervening <p>' );

$parsed_amp = sn_health_extract_inline_imgs_with_alt( $amp_card );
ok( 1 === count( $parsed_amp ) && 'OPERATIONS &amp; AI STRATEGY' === ( $parsed_amp[0]['heading'] ?? '' ),
	'the heading is returned RAW — decoding is the normaliser\'s job, not the parser\'s' );

// The heading is a SIBLING of the <figure>, never a descendant. Scanning from
// the <img> without stepping past </figure> would work here by accident; this
// pins that a <figcaption> between them is not mistaken for the heading.
$with_caption = '<figure><img src="/a-min.png" alt="Alpha"/><figcaption>Not a heading.</figcaption></figure><h2>ALPHA</h2>';
ok( 'heading_duplicate' === sn_health_alt_quality_problem(
		'Alpha', '/a-min.png', 'Not a heading.', sn_health_extract_inline_imgs_with_alt( $with_caption )[0]['heading'] ),
	'the scan steps past </figure> — a figcaption in between does not shadow the heading' );

/* THE FALSE-POSITIVE SURFACE. Each of these must find NO heading. */

$far_heading = '<figure><img src="/a.png" alt="Alpha"/></figure><p>One.</p><p>Two.</p><p>Three.</p><h2>ALPHA</h2>';
ok( '' === ( sn_health_extract_inline_imgs_with_alt( $far_heading )[0]['heading'] ?? 'X' ),
	'a heading three elements away is NOT this image\'s heading — the lookahead is bounded' );

$other_image = '<figure><img src="/a.png" alt="Alpha"/></figure><figure><img src="/b.png" alt="Beta"/></figure><h2>ALPHA</h2>';
ok( '' === ( sn_health_extract_inline_imgs_with_alt( $other_image )[0]['heading'] ?? 'X' ),
	'another image intervening ends the scan — that heading belongs to the NEXT figure, not this one' );

// Layout wrappers do not spend the ELEMENT budget, so wrapper-heavy markup needs
// the distance bound too — otherwise the scan crosses whole template regions.
$far_by_distance = '<figure><img src="/a.png" alt="Alpha"/></figure>'
	. str_repeat( '<div class="' . str_repeat( 'x', 80 ) . '">', 30 ) . '<h2>ALPHA</h2>';
ok( '' === ( sn_health_extract_inline_imgs_with_alt( $far_by_distance )[0]['heading'] ?? 'X' ),
	'a heading past the DISTANCE bound is not found, even when no element budget was spent' );

// A bare <img> is not inside a figure, so there is no </figure> to step past.
// Stepping to the first one ANYWHERE ahead teleports the scan into an unrelated
// card and reads ITS heading — found by running this over the rendered page,
// where the header logo was handed the first service card's <h3>.
$bare_then_card = '<p><img src="/logo.png" alt="Alpha"/></p>'
	. '<figure><img src="/b.png" alt="Beta"/></figure><h3>ALPHA</h3>';
ok( '' === ( sn_health_extract_inline_imgs_with_alt( $bare_then_card )[0]['heading'] ?? 'X' ),
	'a bare <img> does NOT inherit the heading of a later figure — the </figure> step needs its own figure' );

$no_heading = '<figure><img src="/a.png" alt="Alpha"/></figure><p>Just prose.</p>';
ok( '' === ( sn_health_extract_inline_imgs_with_alt( $no_heading )[0]['heading'] ?? 'X' ),
	'no heading after the image yields no heading, not the next one on the page' );

/* The real page, end to end: all SIX cards now report heading_duplicate — the
 * two &-vs-and cards included, which no filename-stem comparison could reach. */
$GLOBALS['__posts'] = array(
	array( 'ID' => 395, 'post_title' => 'Services', 'post_content' => $services_card . "\n" . $amp_card ),
);
$GLOBALS['__att_no_alt']   = array();
$GLOBALS['__att_with_alt'] = array();
$GLOBALS['__wrote']        = false;
$GLOBALS['__seen_sql']     = array();

$svc      = sn_health_check_missing_alt();
$reasons  = array();
foreach ( $svc['findings'] as $f ) {
	if ( 'inline_img_alt_quality' === $f['subject_type'] ) { $reasons[] = $f['quality_reason']; }
}
ok( array( 'heading_duplicate', 'heading_duplicate' ) === $reasons,
	'BOTH /services/ cards report heading_duplicate — not filename_echo, and not nothing' );

ok( false !== strpos( sn_health_alt_quality_note( 'heading_duplicate', 'Production' ), 'heading' ),
	'the note names the heading, so the reader is told what is announced twice' );

ok( false === $GLOBALS['__wrote'],
	'the heading pass writes nothing either' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
