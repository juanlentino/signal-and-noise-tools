<?php
/**
 * Standalone fixture tests for the /resume page-sync engine
 * (inc/resume-sync-engine.php, plugin v10.33.0).
 *
 * The engine renders the structured resume document (inc/resume-page.php)
 * into the /resume Page body — one wp:html freeform block reproducing the
 * live page's markup (same sn-resume-* and wp-block-* classes, same inline
 * preset styles) so the theme CSS renders it identically, and upserts it into
 * the Page. wp:html blocks carry no validation semantics, so the generated
 * body can never trigger editor block recovery — the drift class of bug the
 * hand-authored page had already hit dies here.
 *
 * Run: php tests/resume-sync-engine.php
 * @since plugin v10.33.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $fmt, $ts = null ) { return '1999-12-31'; } }
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }

// Page fixtures: get_page_by_path returns the fixture; wp_update_post /
// wp_insert_post record their calls.
$GLOBALS['__page']    = null;
$GLOBALS['__updates'] = array();
$GLOBALS['__inserts'] = array();
function get_page_by_path( $path ) { return $GLOBALS['__page']; }
function wp_update_post( $arr ) { $GLOBALS['__updates'][] = $arr; return $arr['ID'] ?? 0; }
function wp_insert_post( $arr, $wp_error = false ) { $GLOBALS['__inserts'][] = $arr; return 777; }
if ( ! defined( 'SN_RESUME_SLUG' ) ) { define( 'SN_RESUME_SLUG', 'resume' ); }

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-sync-engine.php';

$doc = sn_resume_doc_normalize( sn_resume_seed_doc() );

// ── body renderer ──
echo "\nTest: sn_resume_body_html\n";
$body = sn_resume_body_html( $doc );
ok( is_string( $body ) && '' !== $body, 'seed doc renders a non-empty body' );

// v10.33.1: the body is REAL serialized block markup (the wp:html body lost
// the live layout — block themes enqueue core block CSS per-block, and a
// wp:html body renders no columns/file/table blocks). These assertions are
// the drift-proofing now: full block-grammar validation of the generator.
ok( 0 === strpos( $body, '<!-- wp:group' ), 'body opens with a wp:group band' );
ok( false === strpos( $body, 'wp:html' ), 'no wp:html anywhere — real blocks only' );

// Every HTML comment is a valid block delimiter (freestanding comments break Gutenberg).
preg_match_all( '/<!--\s*(.*?)\s*-->/s', $body, $m_comments );
$bad_comments = array();
foreach ( $m_comments[1] as $c ) {
	if ( ! preg_match( '~^/?wp:[a-z][a-z0-9-]*(\s+\{.*\})?\s*/?$~s', $c ) ) {
		$bad_comments[] = $c;
	}
}
ok( array() === $bad_comments, 'every HTML comment is a wp: block delimiter (bad: ' . implode( ' | ', array_slice( $bad_comments, 0, 3 ) ) . ')' );

// Every JSON attribute blob decodes (invalid JSON = instant block recovery).
preg_match_all( '/<!-- wp:[a-z][a-z0-9-]* (\{.*?\}) -->/s', $body, $m_json );
$bad_json = 0;
foreach ( $m_json[1] as $blob ) {
	if ( null === json_decode( $blob, true ) ) {
		$bad_json++;
	}
}
ok( count( $m_json[1] ) > 10 && 0 === $bad_json, 'all ' . count( $m_json[1] ) . ' JSON attribute blobs decode cleanly' );

// Every opener has a matching closer, per block type (the scramble regression check).
$types_ok = true;
foreach ( array( 'group', 'paragraph', 'heading', 'columns', 'column', 'list', 'list-item', 'separator', 'details', 'file', 'table' ) as $t ) {
	$open  = preg_match_all( '/<!-- wp:' . preg_quote( $t, '/' ) . '[ \n]/', $body, $x );
	$close = substr_count( $body, '<!-- /wp:' . $t . ' -->' );
	if ( $open !== $close ) {
		$types_ok = false;
		echo "    (imbalance: wp:$t open=$open close=$close)\n";
	}
}
ok( $types_ok, 'every block type opens and closes the same number of times' );
ok( false !== strpos( $body, 'RESUME' ), 'hero headline present' );
ok( false !== strpos( $body, '20+ years building studios' ), 'hero summary present' );
ok( 4 === substr_count( $body, 'class="sn-resume-stat-n"' ), 'all four stat numbers rendered' );
// v10.35.0 hero rearrange: uniform width stays (owner direction, v10.33.3),
// but the uniform value widens 960px → 1320px so the split hero and bands
// use the viewport instead of stacking left with a dead right half.
ok( false === strpos( $body, '1400px' ) && false === strpos( $body, '960px' ), 'no band deviates from the uniform width' );
ok( substr_count( $body, '"contentSize":"1320px"' ) === substr_count( $body, 'wp:group {"style"' ), 'every band group declares the uniform contentSize' );
// The hero is a two-column editorial split: title block left, summary/
// chips/rail/PDF right. v10.36.1: TOP-aligned — the right column is far
// taller than the title block, so bottom alignment sank the title to the
// column floor and left a hole above it (live regression, owner report).
// Core columns stack on mobile on their own.
ok( false !== strpos( $body, 'sn-resume-hero-split' ), 'hero renders as the split columns composition' );
ok( false !== strpos( $body, 'are-vertically-aligned-top sn-resume-hero-split' ), 'hero columns are top-aligned (v10.36.1 fix)' );
ok( false === strpos( $body, 'are-vertically-aligned-bottom' ), 'no bottom alignment remains in the hero' );
// v10.37.3 owner direction: the summary reads under the title in the LEFT
// column; the right column starts with the credential ledger.
ok( strpos( $body, '20+ years building studios' ) < strpos( $body, 'flex-basis:45%' ), 'summary sits in the left column, before the right column opens' );
// v10.37.4: the eyebrow sits ABOVE the columns (band kicker), so both
// columns start on the same line — title cap left, ledger top rule right.
ok( strpos( $body, 'sn-catalog-eyebrow' ) < strpos( $body, 'sn-resume-hero-split' ), 'eyebrow precedes the hero columns' );
ok( false === strpos( substr( $body, strpos( $body, 'sn-resume-hero-split' ) ), 'sn-catalog-eyebrow">Dossier' ), 'no eyebrow inside the hero columns' );
// v10.37.4: publication cards are flow groups — the constrained layout
// capped card content at the default width and centered it, indenting the
// meta/title and wrapping titles to two lines (owner report).
ok( false !== strpos( $body, '<!-- wp:group {"className":"sn-resume-pub"} -->' ), 'pub cards are plain flow groups' );
ok( false === strpos( $body, 'sn-resume-pub","layout' ), 'no constrained layout on pub cards' );
ok( false !== strpos( $body, 'INDEPENDENT PRACTICE' ) && false !== strpos( $body, 'PANACEA STUDIO' ), 'both experience orgs rendered' );
ok( false !== strpos( $body, '<details class="wp-block-details sn-resume-fold"><summary>Earlier career &middot; 1997 - 2015</summary>' ) || false !== strpos( $body, '<summary>' . esc_html( 'Earlier career · 1997 - 2015' ) . '</summary>' ), 'earlier-career fold rendered as details/summary' );
ok( false !== strpos( $body, 'OBRAS MET' ) && false !== strpos( $body, 'CINERGY STUDIOS' ), 'both earlier-career orgs inside the fold' );
ok( false !== strpos( $body, 'roughly 110 releases since 2022</strong>' ), 'bullet emphasis HTML survives to the body' );
ok( false !== strpos( $body, 'https://ssrn.com/abstract=6402298' ) && false !== strpos( $body, 'https://ssrn.com/abstract=6730343' ), 'both publication links rendered' );
ok( false !== strpos( $body, 'rel="noopener"' ), 'publication links carry rel=noopener' );
ok( 6 === substr_count( $body, '<tr><td>' ), 'six skills table rows' );
ok( false !== strpos( $body, 'P&amp;L Oversight' ), 'skills ampersands escaped exactly once' );
ok( false === strpos( $body, 'P&amp;amp;' ), 'no double-escaping anywhere' );
ok( false !== strpos( $body, 'JuanLentino_Resume.pdf' ) && false !== strpos( $body, 'Download PDF' ), 'PDF download block rendered' );
ok( false !== strpos( $body, 'MBA, Applied Artificial Intelligence in Business' ), 'education entries rendered' );
ok( false !== strpos( $body, 'Voting Member, The Recording Academy' ), 'affiliations rendered' );

// balanced HTML: every <div opens once per </div> (the drift bug regression check)
ok( substr_count( $body, '<div' ) === substr_count( $body, '</div>' ), 'div tags balance' );
ok( substr_count( $body, '<section' ) === substr_count( $body, '</section>' ), 'section tags balance' );

// hero degrades gracefully: no PDF → no file block; no chips → no chips list
$no_pdf = $doc;
$no_pdf['hero']['pdf_url'] = '';
$no_pdf['hero']['chips']   = array();
$html2 = sn_resume_body_html( $no_pdf );
ok( false === strpos( $html2, 'wp-block-file' ), 'absent PDF URL → no file block' );
ok( false === strpos( $html2, 'sn-resume-chips' ), 'no chips → no chips list' );

// unusable doc → '' (never a blank-page body)
ok( '' === sn_resume_body_html( null ), 'null doc → empty string' );
ok( '' === sn_resume_body_html( array() ), 'empty doc → empty string' );

// ── upsert ──
echo "\nTest: sn_resume_upsert_page\n";
ok( 0 === sn_resume_upsert_page( '' ), 'empty body → 0, nothing written' );
ok( array() === $GLOBALS['__updates'] && array() === $GLOBALS['__inserts'], 'empty body touches nothing' );

$GLOBALS['__page'] = (object) array( 'ID' => 1184, 'post_excerpt' => 'Existing excerpt.' );
ok( 1184 === sn_resume_upsert_page( $body ), 'existing page → its ID' );
ok( 1 === count( $GLOBALS['__updates'] ) && $body === $GLOBALS['__updates'][0]['post_content'], 'existing page content replaced' );
ok( ! isset( $GLOBALS['__updates'][0]['post_excerpt'] ), 'a set excerpt is never overwritten' );

$GLOBALS['__page'] = (object) array( 'ID' => 1184, 'post_excerpt' => '  ' );
sn_resume_upsert_page( $body );
ok( isset( $GLOBALS['__updates'][1]['post_excerpt'] ) && '' !== $GLOBALS['__updates'][1]['post_excerpt'], 'blank excerpt gets seeded' );

$GLOBALS['__page'] = null;
$GLOBALS['__inserts'] = array();
ok( 777 === sn_resume_upsert_page( $body ), 'absent page → created' );
ok( 'resume' === ( $GLOBALS['__inserts'][0]['post_name'] ?? '' ) && 'page-resume' === ( $GLOBALS['__inserts'][0]['page_template'] ?? '' ), 'created page bound to the resume slug + template' );

// ── sync ──
echo "\nTest: sn_resume_sync_page\n";
$GLOBALS['__page']    = (object) array( 'ID' => 1184, 'post_excerpt' => 'x' );
$GLOBALS['__updates'] = array();
$GLOBALS['__options'] = array();
sn_resume_sync_page(); // no option → seed fallback still renders a body
ok( 1 === count( $GLOBALS['__updates'] ), 'sync regenerates from the current document (seed fallback included)' );
ok( false !== strpos( (string) $GLOBALS['__updates'][0]['post_content'], 'PANACEA STUDIO' ), 'synced body carries the document content' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
