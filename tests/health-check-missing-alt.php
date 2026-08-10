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
 *      single-word alt on a content image. These are FINDINGS ONLY — they route
 *      through the same human acceptance as the coverage sweep and never write.
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

ok( 'caption_duplicate' === sn_health_alt_quality_problem(
		'The 1954 ledger, reopened', 'ledger.jpg', 'The 1954 ledger, reopened.' ),
	'an alt duplicating its caption is flagged (punctuation/case insensitive)' );

ok( 'single_word' === sn_health_alt_quality_problem( 'Chart', 'q3-revenue.png', '' ),
	'a single-word alt on a content image is flagged' );

ok( '' === sn_health_alt_quality_problem(
		'Two archivists comparing a printed provenance ledger against a screen',
		'hero-image-2.png',
		'Working session, March 2026' ),
	'a genuinely descriptive alt is NOT flagged' );

ok( '' === sn_health_alt_quality_problem( '', 'hero-image-2.png', '' ),
	'empty alt is not a QUALITY finding — it belongs to the coverage pass (decorative vs missing)' );

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
