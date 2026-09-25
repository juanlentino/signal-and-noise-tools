<?php
/**
 * Guard: the resume PDF (docs/RESUME-PDF.md, Phase 2) never changes how /resume
 * displays. Owner, 2026-09-22: "There shouldn't be a change of how the resume is
 * displayed in the page."
 *
 * Relationships, not a frozen body:
 *   1. Filling every PDF-only field leaves the /resume body byte-identical.
 *   2. Before a PDF is generated, the body is byte-identical to the engine
 *      WITHOUT the generator loaded (the pre-Phase-2 path).
 *   3. After generation, the ONLY difference is the Download link's URL: the
 *      hand-set one is replaced by the stable generated one with ?v=<hash>.
 *   4. No PDF-only value (phone above all) appears anywhere in the body.
 *
 * Run: php tests/resume-pdf-page-invariance.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php'; // sn_content_items_normalize (one item per line).
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-sync-engine.php';

$seed = sn_resume_seed_doc();
$doc  = sn_resume_doc_normalize( $seed );
ok( is_array( $doc ) && '' !== $doc['hero']['pdf_url'], 'fixture: the seed normalizes and carries a hand-set PDF URL' );

// 2 (captured first, before the generator exists): the pre-Phase-2 path.
ok( ! function_exists( 'sn_resume_pdf_link' ), 'baseline is rendered before the generator is loaded' );
$before = sn_resume_body_html( $doc );

require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-pdf/generate.php';

echo "\n1. PDF-only fields do not reach the page\n";
$filled        = $seed;
$filled['pdf'] = array(
	'headline'     => 'Unique Headline Marker',
	'tagline'      => 'Unique Tagline Marker',
	'summary'      => 'Unique Summary Marker',
	'phone'        => '(555) 010-4242',
	'email'        => 'marker@example.com',
	'location'     => 'Unique Location Marker',
	'competencies' => "Unique Competency Marker\nSecond Competency",
	'toolkit'      => "Unique Toolkit Marker",
);
$filled_doc = sn_resume_doc_normalize( $filled );
ok( '(555) 010-4242' === $filled_doc['pdf']['phone'] && 2 === count( $filled_doc['pdf']['competencies'] ), 'the PDF fields normalize and keep their values' );
$with = sn_resume_body_html( $filled_doc );
ok( $with === $before, 'filling every PDF-only field leaves the /resume body byte-identical' );
foreach ( array( '(555) 010-4242', 'marker@example.com', 'Unique Headline Marker', 'Unique Tagline Marker', 'Unique Summary Marker', 'Unique Location Marker', 'Unique Competency Marker', 'Unique Toolkit Marker' ) as $v ) {
	ok( false === strpos( $with, $v ), "no PDF-only value on the page: $v" );
}

echo "\n2. Before a PDF is generated\n";
ok( sn_resume_body_html( $doc ) === $before, 'with the generator loaded but no PDF generated, the body is byte-identical to the pre-Phase-2 engine' );

echo "\n3. After generation: only the Download URL changes\n";
$GLOBALS['__options']['sn_resume_pdf'] = array(
	'sha256'    => str_repeat( 'ab12cd34', 8 ),
	'bytes'     => 44000,
	'pages'     => 2,
	'generated' => '2026-09-22T00:00:00+00:00',
	'url'       => 'https://example.test/wp-content/uploads/resume/JuanLentino_Resume.pdf',
);
$after    = sn_resume_body_html( $doc );
$old_url  = esc_url( $doc['hero']['pdf_url'] );
$new_url  = esc_url( 'https://example.test/wp-content/uploads/resume/JuanLentino_Resume.pdf?v=ab12cd34' );
ok( substr_count( $before, $old_url ) >= 2, 'the old URL sits in the link block (' . substr_count( $before, $old_url ) . ' occurrences)' );
ok( false === strpos( $after, $old_url ), 'the hand-set URL is gone once a PDF exists' );
ok( substr_count( $after, $new_url ) === substr_count( $before, $old_url ), 'the generated URL, with ?v=<first 8 of the hash>, takes every one of its places' );
ok( str_replace( $old_url, $new_url, $before ) === $after, 'and NOTHING else in the body changed' );

// Negative control: the equality above can fail.
ok( str_replace( $old_url, $new_url, $before ) !== $after . ' ', 'control: the whole-body equality is sensitive to a single character' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
