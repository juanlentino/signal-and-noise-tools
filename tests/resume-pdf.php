<?php
/**
 * Guard: the resume PDF generator (docs/RESUME-PDF.md, Phase 2).
 *
 * Runs the REAL renderer (inc/resume-pdf/generate.php → template.php → Dompdf
 * from lib/pdf) against fixture data, no WordPress, and asserts on the bytes:
 * a PDF, two pages or fewer at Letter, the metadata the brief names, Lato
 * embedded as real text (not images), and the fixture's key strings present.
 * Text extraction uses pdftotext when the machine has it; the HTML the
 * renderer consumed is checked either way, so a missing tool never passes
 * vacuously on the strings.
 *
 * Run: php tests/resume-pdf.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-pdf/generate.php';

$fixture        = sn_resume_seed_doc();
$fixture['pdf'] = array(
	'headline'     => 'Music Business Development & Strategic Partnerships Leader',
	'tagline'      => 'Artist & Label Relations | Latin American & U.S. Markets | Music Rights, Provenance & AI',
	'phone'        => '(555) 010-4242',
	'email'        => 'juan@example.com',
	'location'     => 'Orlando, FL',
	'competencies' => "Strategic Partnerships & Deal Negotiation\nDistribution & Co-Production Agreements\nArtist Development & A&R\nRelease Strategy & Market Positioning\nLatin American & U.S. Market Expansion\nP&L Ownership & Pricing Strategy",
	'toolkit'      => "Pro Tools\nLogic Pro X\nAbleton Live\nDolby Atmos",
);
$doc = sn_resume_doc_normalize( $fixture );
ok( is_array( $doc ), 'fixture normalizes' );

echo "\nThe renderer\n";
$cache = sys_get_temp_dir() . '/sn-resume-pdf-test-' . getmypid();
$t0    = microtime( true );
$r     = sn_resume_pdf_render( $doc, 'Juan Lentino', $cache );
$ms    = (int) ( ( microtime( true ) - $t0 ) * 1000 );
ok( is_array( $r ) && isset( $r['bytes'] ), 'renders without error (' . ( is_wp_error( $r ) ? $r->code . ': ' . $r->message : $ms . ' ms' ) . ')' );
$bytes = is_array( $r ) ? (string) $r['bytes'] : '';
ok( 0 === strpos( $bytes, '%PDF-' ), 'the output is a PDF (%PDF- header)' );
ok( is_array( $r ) && $r['pages'] >= 1 && $r['pages'] <= 2, 'two pages or fewer (' . ( $r['pages'] ?? '?' ) . ')' );
ok( 1 === preg_match( '#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+792(\.0+)?\s*\]#', $bytes ), 'Letter size (612 x 792 pt)' );
ok( false !== strpos( $bytes, '/FontFile2' ) && false !== strpos( $bytes, 'Lato' ), 'Lato is embedded as a font program (text, not images)' );
ok( false === strpos( $bytes, '/Subtype /Image' ), 'no images at all: every word is text an ATS can read' );
$info = static fn( $key ) => (bool) preg_match( '#/' . $key . '\s*\(#', $bytes ) || (bool) preg_match( '#/' . $key . '\s*<#', $bytes );
ok( $info( 'Title' ) && $info( 'Author' ), 'Title and Author metadata are set' );

echo "\nThe strings\n";
$html = sn_resume_pdf_html( $doc, 'Juan Lentino', '/fonts' );
$want = array( 'JUAN LENTINO', 'MUSIC BUSINESS DEVELOPMENT', 'PROFESSIONAL EXPERIENCE', 'INDEPENDENT PRACTICE', 'CORE COMPETENCIES', 'Strategic Partnerships &amp; Deal Negotiation', 'TECHNICAL TOOLKIT', 'Provenance Over Detection' );
$miss = array_filter( $want, static fn( $w ) => false === strpos( $html, $w ) );
ok( empty( $miss ), 'the rendered page carries the fixture\'s key strings' . ( $miss ? ': missing ' . implode( ' | ', $miss ) : '' ) );
ok( false === stripos( $html, '<img' ) && false === stripos( $html, 'http://' ) && false === stripos( $html, 'url(http' ), 'the template loads nothing remote (isRemoteEnabled stays off)' );

$pdftotext = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
if ( '' !== $pdftotext ) {
	$tmp = $cache . '/out.pdf';
	file_put_contents( $tmp, $bytes );
	$text  = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $tmp ) . ' - 2>/dev/null' );
	$plain = array( 'JUAN LENTINO', 'PROFESSIONAL EXPERIENCE', 'workflow', 'Provenance Over Detection' );
	$gone  = array_filter( $plain, static fn( $w ) => false === strpos( $text, $w ) );
	ok( empty( $gone ), 'pdftotext reads the key strings back out of the PDF (ATS view)' . ( $gone ? ': missing ' . implode( ' | ', $gone ) : '' ) );
} else {
	echo "INFO: pdftotext not installed; the text-layer read-back ran on the HTML above only.\n";
}

echo "\nThe phone (owner, 2026-09-22: no public download with it unless switched on)\n";
ok( false === $doc['pdf']['phone_public'], 'the switch is OFF when the form never posted it (an unchecked box posts nothing)' );
ok( false === strpos( $html, '(555) 010-4242' ), 'the public page render leaves the phone out while the switch is off' );
$priv = sn_resume_pdf_html( $doc, 'Juan Lentino', '/fonts', true );
ok( false !== strpos( $priv, '(555) 010-4242' ), 'the private copy carries it' );
$on               = $fixture;
$on['pdf']['phone_public'] = '1';
$on_doc           = sn_resume_doc_normalize( $on );
ok( true === $on_doc['pdf']['phone_public'], 'the switch turns on from a posted "1"' );
if ( '' !== $pdftotext ) {
	$pub_text = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $cache . '/out.pdf' ) . ' - 2>/dev/null' );
	ok( false === strpos( $pub_text, '(555) 010-4242' ) && false !== strpos( $pub_text, 'Orlando, FL' ), 'the public PDF BYTES carry no phone (the contact line still prints)' );
	$p2 = sn_resume_pdf_render( $doc, 'Juan Lentino', $cache, true );
	file_put_contents( $cache . '/priv.pdf', is_array( $p2 ) ? $p2['bytes'] : '' );
	ok( false !== strpos( (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $cache . '/priv.pdf' ) . ' - 2>/dev/null' ), '(555) 010-4242' ), 'the private PDF bytes carry it (control: the check above can see a phone)' );
}
$gen_src = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/generate.php' );
ok( false !== strpos( $gen_src, 'sn_resume_pdf_render( $doc, $name, $loc[\'dir\'] . \'/.font-cache\', ! empty( $doc[\'pdf\'][\'phone_public\'] ), home_url( \'/\' ) );' ), 'Generate PDF includes the phone ONLY per the switch' );
ok( 1 === preg_match( '/function sn_resume_pdf_private_stream\(\).*?sn_resume_pdf_render\([^;]*,\s*true,\s*home_url\( \'\/\' \)\s*\);.*?header\( .Content-Disposition: attachment/s', $gen_src ) && false === strpos( substr( $gen_src, (int) strpos( $gen_src, 'function sn_resume_pdf_private_stream' ) ), 'file_put_contents' ), 'the private copy streams as an attachment and is never written to disk' );

echo "\nThe contact line (owner, 2026-09-22: location and links were missing)\n";
$bare                    = $fixture;
$bare['pdf']['location'] = '';
$bare_doc                = sn_resume_doc_normalize( $bare );
$web_loc                 = (string) $bare_doc['hero']['contact_line'];
$bare_html               = sn_resume_pdf_html( $bare_doc, 'Juan Lentino', '/fonts', false, 'https://www.juanlentino.com/' );
ok( '' !== $web_loc && false !== strpos( $bare_html, htmlspecialchars( $web_loc, ENT_QUOTES, 'UTF-8' ) ), 'with the PDF location blank, the web contact line prints (' . $web_loc . ')' );
ok( false !== strpos( $bare_html, '<a href="https://www.juanlentino.com/">juanlentino.com</a>' ), 'the site address prints as its bare host, linked' );
ok( false === strpos( sn_resume_pdf_html( $bare_doc, 'Juan Lentino', '/fonts', false, '' ), 'juanlentino.com</a>' ), 'no home URL, no site entry (the fallback invents nothing)' );
if ( '' !== $pdftotext ) {
	$b = sn_resume_pdf_render( $bare_doc, 'Juan Lentino', $cache, false, 'https://www.juanlentino.com/' );
	file_put_contents( $cache . '/bare.pdf', is_array( $b ) ? $b['bytes'] : '' );
	$bt = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $cache . '/bare.pdf' ) . ' - 2>/dev/null' );
	ok( false !== strpos( $bt, $web_loc ) && false !== strpos( $bt, 'juanlentino.com' ) && false !== strpos( $bt, 'linkedin.com/in/' ), 'the PDF bytes carry the location, LinkedIn and the site (read back with pdftotext)' );
}

$web                     = $bare;
$web['pdf']['website']   = 'https://example.org/work';
$web_doc                 = sn_resume_doc_normalize( $web );
$web_html                = sn_resume_pdf_html( $web_doc, 'Juan Lentino', '/fonts', false, 'https://www.juanlentino.com/' );
ok( false !== strpos( $web_html, '<a href="https://example.org/work">example.org</a>' ) && false === strpos( $web_html, 'juanlentino.com</a>' ), 'the Website field wins over the home URL' );

echo "\nWiring\n";
$gen = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/generate.php' );
ok( 1 === preg_match( "/set\(\s*'isRemoteEnabled',\s*false\s*\)/", $gen ), 'isRemoteEnabled is pinned off (no SSRF surface in the renderer)' );
ok( false !== strpos( $gen, "SN_RESUME_PDF_FILE   = 'JuanLentino_Resume.pdf'" ) && false !== strpos( $gen, "trailingslashit( \$up['basedir'] ) . 'resume'" ), 'the file has a stable name under uploads/resume' );
ok( false !== strpos( $gen, "'?v=' . substr( (string) \$meta['sha256'], 0, 8 )" ), 'the Download link carries ?v=<first 8 of the SHA-256>' );
ok( false !== strpos( $gen, 'rename( $tmp, $final )' ), 'the publish is atomic (temp file, then rename)' );
ok( false !== strpos( $gen, 'snt_ability_purge_all_caches(' ), 'generation purges caches the way Purge All Caches does' );
$handler = (string) file_get_contents( __DIR__ . '/../inc/admin-post-handler.php' );
ok( false !== strpos( $handler, "'resume_pdf_generate'        => 'sn_handle_resume_pdf_generate'," ) && false !== strpos( $handler, "check_admin_referer( 'sn_' . \$action )" ) && false !== strpos( $handler, "current_user_can( 'manage_options' )" ), 'the Generate action goes through the dispatcher: its own nonce plus manage_options' );
ok( is_readable( __DIR__ . '/../lib/pdf/vendor/autoload.php' ) && is_readable( __DIR__ . '/../lib/pdf/fonts/OFL.txt' ), 'Dompdf and Lato ship in lib/pdf, with Lato\'s license' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
