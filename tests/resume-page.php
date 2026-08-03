<?php
/**
 * Standalone fixture tests for the /resume structured editor data layer
 * (inc/resume-page.php, plugin v10.33.0).
 *
 * The resume document is a structured array (hero / stats / experience with
 * nested roles and bullets / earlier-career fold / education / affiliations /
 * publications / skills) stored in a durable autoload=no option. Unlike the
 * /now and /uses plain-text boxes, the editor is a structured form, so the
 * data layer's job is shape discipline: sn_resume_doc_normalize() canonicalises
 * or refuses, and a refused document is never saved (a bad save can never
 * blank the live /resume page).
 *
 * Run: php tests/resume-page.php
 * @since plugin v10.33.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $fmt, $ts = null ) { return '1999-12-31'; } }
// wp_kses stub: strips every tag except <strong>/<em>/<a>, crude but shaped
// like the real allowlist the bullets ride through.
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$same = isset( $GLOBALS['__options'][ $k ] ) && $GLOBALS['__options'][ $k ] === $v;
	$GLOBALS['__options'][ $k ] = $v;
	$GLOBALS['__last_autoload'] = $autoload;
	return ! $same;
}
function delete_option( $k ) { $had = isset( $GLOBALS['__options'][ $k ] ); unset( $GLOBALS['__options'][ $k ] ); return $had; }

require_once __DIR__ . '/../inc/resume-page.php';

// ── seed file ──
echo "\nTest: sn_resume_seed_doc\n";
$seed = sn_resume_seed_doc();
ok( is_array( $seed ), 'seed JSON parses to an array' );
ok( isset( $seed['experience'][1]['roles'][0]['title'] ) && false !== strpos( $seed['experience'][1]['roles'][0]['title'], 'Founder' ), 'seed carries the live Panacea Founder role' );
$norm_seed = sn_resume_doc_normalize( $seed );
ok( is_array( $norm_seed ), 'seed document survives normalize (non-null)' );
ok( 2 === count( $norm_seed['publications'] ), 'both SSRN papers in the normalized seed' );
ok( 6 === count( $norm_seed['skills'] ), 'all six skills rows in the normalized seed' );
ok( 4 === count( $norm_seed['stats'] ), 'all four stats in the normalized seed' );
ok( false !== strpos( $norm_seed['experience'][1]['roles'][0]['bullets'][2], 'P&amp;L' ), 'bullet HTML entities survive normalize verbatim' );

// ── normalize: shape discipline ──
echo "\nTest: sn_resume_doc_normalize\n";
ok( null === sn_resume_doc_normalize( 'nope' ), 'non-array → null' );
ok( null === sn_resume_doc_normalize( array() ), 'empty array → null' );
ok( null === sn_resume_doc_normalize( array( 'hero' => array( 'summary' => 'x' ) ) ), 'hero alone (no experience, no publications) → refused' );

$min = array(
	'hero'       => array( 'summary' => "  Twenty years.  ", 'chips' => array( ' A ', '', '  ' ), 'linkedin' => 'https://example.com/x', 'pdf_url' => '', 'pdf_label' => '', 'contact_line' => 'Orlando' ),
	'experience' => array(
		array( 'org' => ' ORG ', 'dates' => '2020', 'location' => '', 'roles' => array(
			array( 'title' => ' Role ', 'bullets' => array( ' Did <strong>things</strong>. ', '', '<script>x</script>ok' ) ),
			array( 'title' => '', 'bullets' => array( 'orphan bullet under a blank title' ) ),
		) ),
		array( 'org' => '', 'roles' => array() ),
	),
	'unknown_key' => 'dropped',
);
$doc = sn_resume_doc_normalize( $min );
ok( is_array( $doc ), 'minimal experience-bearing doc accepted' );
ok( ! isset( $doc['unknown_key'] ), 'unknown top-level keys dropped' );
ok( 'Twenty years.' === $doc['hero']['summary'], 'strings trimmed' );
ok( array( 'A' ) === $doc['hero']['chips'], 'blank chips dropped, survivors trimmed and reindexed' );
ok( 1 === count( $doc['experience'] ), 'org-less experience entries dropped' );
ok( 1 === count( $doc['experience'][0]['roles'] ), 'title-less roles dropped (their bullets never orphan in)' );
ok( 'Did <strong>things</strong>.' === $doc['experience'][0]['roles'][0]['bullets'][0], 'bullets keep allowlisted tags' );
// Real wp_kses removes disallowed TAGS but keeps their inner text — 'xok',
// not 'ok'. The stub models that transform (test-stub-drift rule).
ok( 'xok' === $doc['experience'][0]['roles'][0]['bullets'][1], 'script tags stripped from bullets (inner text kept, as real kses does), blank bullets dropped' );
ok( array() === $doc['stats'], 'absent sections normalize to empty arrays, not null' );
ok( is_array( $doc['earlier'] ) && array() === $doc['earlier']['entries'], 'absent earlier fold → label + empty entries' );

// a publications-only doc is also acceptable (experience OR publications anchors it)
$pubs_only = sn_resume_doc_normalize( array( 'publications' => array( array( 'meta' => 'm', 'title' => 't', 'url' => 'https://ssrn.com/x' ) ) ) );
ok( is_array( $pubs_only ), 'publications alone anchor a document' );
// hostile URL schemes are dropped at normalize
$bad_url = sn_resume_doc_normalize( array( 'publications' => array( array( 'meta' => 'm', 'title' => 't', 'url' => 'javascript:alert(1)' ) ) ) );
ok( null === $bad_url || '' === ( $bad_url['publications'][0]['url'] ?? '' ), 'javascript: publication URL neutralised' );

// ── save / get round-trip ──
echo "\nTest: sn_resume_doc_save / sn_resume_doc_get\n";
ok( true === sn_resume_doc_save( $seed ), 'seed save returns true' );
ok( false === ( $GLOBALS['__last_autoload'] ?? true ), 'option stored autoload=no' );
$got = sn_resume_doc_get();
ok( is_array( $got ) && 'PANACEA STUDIO' === ( $got['experience'][1]['org'] ?? '' ), 'saved doc round-trips through get' );
ok( '1999-12-31' === ( $got['updated'] ?? '' ), 'save stamps updated via wp_date (site timezone), not gmdate' );
ok( false === sn_resume_doc_save( $seed ), 're-saving identical content returns false' );
ok( false === sn_resume_doc_save( array( 'hero' => array( 'summary' => 'no anchor' ) ) ), 'refused doc is NOT saved' );
$still = sn_resume_doc_get();
ok( is_array( $still ) && 'PANACEA STUDIO' === ( $still['experience'][1]['org'] ?? '' ), 'refused save leaves the stored doc untouched' );

// with no option saved, get falls back to the seed (form is never empty)
$GLOBALS['__options'] = array();
$fallback = sn_resume_doc_get();
ok( is_array( $fallback ) && 'INDEPENDENT PRACTICE' === ( $fallback['experience'][0]['org'] ?? '' ), 'absent option → get falls back to the seed document' );

// hostile stored shapes degrade to the seed fallback, never fatal
$GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] = 'not-an-array';
$hostile = sn_resume_doc_get();
ok( is_array( $hostile ), 'non-array stored value → seed fallback, no fatal' );
$GLOBALS['__options'] = array();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
