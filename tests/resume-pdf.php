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
// The site timezone the owner lives in (Orlando), as WordPress would apply it.
function wp_date( $format, $ts ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( $format ); }
function get_option( $k, $d = false ) { return array( 'date_format' => 'F j, Y', 'time_format' => 'g:i a' )[ $k ] ?? $d; }

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
ok( 1 === preg_match( '#/Title\s*\(([^)]*)\)#', $bytes, $tm ) && false === strpos( $tm[1], "\xE2\x80\x94" ) && false === strpos( $tm[1], "\x20\x14" ), 'the Title carries no em dash' );

// Justified text in a Unicode font: Dompdf's Cpdf::addText wrote each word gap
// as \x00\x20)\x00\x20-N\x00\x20(, NUL bytes OUTSIDE the strings. pypdf (the
// Python reader behind many job-site uploads, Icebreaker 2026-10-01) refuses
// the whole file on them. The vendored Cpdf is patched; a Dompdf bump that
// brings the line back turns this red.
$streams = '';
if ( preg_match_all( '#stream\r?\n(.*?)\r?\nendstream#s', $bytes, $sm ) ) {
	foreach ( $sm[1] as $raw ) {
		$inflated = @gzuncompress( $raw );
		$streams .= false === $inflated ? '' : $inflated;
	}
}
ok( 1 === preg_match( '#\x00\x20\)(\x00\x20| )-?\d+(\x00\x20| )\(#', $streams ), 'the fixture has justified Unicode text (word gaps in a TJ array), so the next pin is not vacuous' );
ok( 0 === preg_match( '#\x00\x20\)\x00\x20-?\d+\x00\x20\(#', $streams ), 'justified text writes its word gaps without NUL bytes outside the strings (pypdf reads the file)' );

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
// Drafts: ONE streamer (sn_resume_pdf_stream) serves the private copy and the
// draft preview; each caller only picks the document, the phone and the name.
$stream_src = substr( $gen_src, (int) strpos( $gen_src, 'function sn_resume_pdf_stream(' ) );
ok( false !== strpos( $gen_src, 'function sn_resume_pdf_stream(' ) && 1 === preg_match( '/^function sn_resume_pdf_stream\( \$doc, \$with_phone, \$filename \) \{.*?sn_resume_pdf_render\( \$doc, \$name, [^;]*, \(bool\) \$with_phone, home_url\( \'\/\' \) \);.*?header\( .Content-Disposition: attachment; filename="\' \. \$filename/s', $stream_src ) && false === strpos( $stream_src, 'file_put_contents' ), 'the shared streamer renders with the phone rule it is given, sends an attachment, and nothing after it writes to disk' );
ok( 1 === preg_match( '/function sn_resume_pdf_private_stream\(\) \{\s*return sn_resume_pdf_stream\( [^;]*sn_resume_doc_get\(\)[^;]*, true, \'JuanLentino_Resume_private\.pdf\' \);/', $gen_src ), 'the private copy streams the LIVE document WITH the phone' );
ok( 1 === preg_match( '/function sn_resume_pdf_draft_stream\(\) \{\s*\$doc = [^;]*sn_resume_draft_get\(\)[^;]*;\s*return sn_resume_pdf_stream\( \$doc, ! empty\( \$doc\[\'pdf\'\]\[\'phone_public\'\] \), \'JuanLentino_Resume_draft\.pdf\' \);/', $gen_src ), 'the draft preview streams the DRAFT under the public phone rule, as JuanLentino_Resume_draft.pdf' );

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

echo "\nPublications: venue and date on the title's row\n";
ok( 1 === preg_match( '#<table class="pub"><tr><td><a href="[^"]+">Provenance Over Detection[^<]*</a></td><td class="meta">SSRN Working Paper[^<]*</td></tr></table>#u', $html ), 'each publication is one row: linked title left, venue and date right' );
ok( false === strpos( $html, '&#8212; <span class="meta">' ), 'no dash separator between title and venue' );

echo "\nThe PDF-only professional summary\n";
$sum                     = $bare;
$sum['hero']['summary']  = 'Web summary marker.';
$sum['pdf']['summary']   = 'PDF summary marker.';
$sum_html                = sn_resume_pdf_html( sn_resume_doc_normalize( $sum ), 'Juan Lentino', '/fonts' );
ok( false !== strpos( $sum_html, 'PDF summary marker.' ) && false === strpos( $sum_html, 'Web summary marker.' ), 'the PDF-only summary replaces the web summary in the PDF' );
$sum['pdf']['summary']   = '';
$sum_html                = sn_resume_pdf_html( sn_resume_doc_normalize( $sum ), 'Juan Lentino', '/fonts' );
ok( false !== strpos( $sum_html, 'Web summary marker.' ), 'blank falls back to the web summary' );

echo "\nThe generated time (owner, 2026-09-22: the UTC stamp was not their time)\n";
ok( 'September 22, 2026 at 7:21 pm' === sn_resume_pdf_when( array( 'generated' => '2026-09-22T23:21:54+00:00' ) ), 'the stored UTC time shows in the site timezone and formats (' . sn_resume_pdf_when( array( 'generated' => '2026-09-22T23:21:54+00:00' ) ) . ')' );
ok( '' === sn_resume_pdf_when( false ) && '' === sn_resume_pdf_when( array( 'generated' => 'nonsense' ) ), 'no stamp, no time (never a 1970 date)' );
$both = (string) file_get_contents( __DIR__ . '/../inc/admin-forms/resume-page.php' ) . (string) file_get_contents( __DIR__ . '/../apps/sn-dashboard/parts/leaves/content-resume.php' );
ok( 2 === substr_count( $both, 'sn_resume_pdf_when( $meta )' ) && false === strpos( $both, '(string) $meta[\'generated\']' ), 'both editors show the formatted time, neither the raw stamp' );

echo "\nWiring\n";
$gen = (string) file_get_contents( __DIR__ . '/../inc/resume-pdf/generate.php' );
ok( 1 === preg_match( "/set\(\s*'isRemoteEnabled',\s*false\s*\)/", $gen ), 'isRemoteEnabled is pinned off (no SSRF surface in the renderer)' );
ok( false !== strpos( $gen, "SN_RESUME_PDF_FILE   = 'JuanLentino_Resume.pdf'" ) && false !== strpos( $gen, "trailingslashit( \$up['basedir'] ) . 'resume'" ), 'the file has a stable name under uploads/resume' );
ok( false !== strpos( $gen, "'?v=' . substr( (string) \$meta['sha256'], 0, 8 )" ), 'the Download link carries ?v=<first 8 of the SHA-256>' );
ok( false !== strpos( $gen, 'rename( $tmp, $final )' ), 'the publish is atomic (temp file, then rename)' );
ok( false === strpos( $gen, 'snt_ability_purge_all_caches(' ) && false === strpos( $gen, 'sn_cf_purge_everything' ) && false === strpos( $gen, 'sn_purge_all_caches' ), 'generation no longer purges everything (that emptied Redis and every page to drop one PDF)' );
ok( false !== strpos( $gen, 'sn_cf_purge_urls( sn_resume_pdf_purge_urls( $meta, $issued ) );' ) && false !== strpos( $gen, "if ( function_exists( 'sn_cf_purge_urls' ) ) {" ), 'it purges the file\'s own URLs through the per-URL path, guarded' );
ok( strpos( $gen, 'sn_resume_sync_page();' ) < strpos( $gen, 'sn_cf_purge_urls( sn_resume_pdf_purge_urls(' ) && strpos( $gen, '$previous = get_option( SN_RESUME_PDF_OPTION );' ) < strpos( $gen, 'update_option( SN_RESUME_PDF_OPTION, $meta, false );' ) && false !== strpos( $gen, 'update_option( SN_RESUME_PDF_ISSUED, $issued, false );' ), 'the page is re-saved first (its save purges /resume/); the previous version is read before it is overwritten; the issued list is stored' );
$pdf_url = 'https://example.test/wp-content/uploads/resume/JuanLentino_Resume.pdf';
$a = array( 'url' => $pdf_url, 'sha256' => 'aaaaaaaa1111' ); $b = array( 'url' => $pdf_url, 'sha256' => 'bbbbbbbb2222' ); $c = array( 'url' => $pdf_url, 'sha256' => 'cccccccc3333' );
$after_b = sn_resume_pdf_issued( false, $a, $b );
ok( array( 'aaaaaaaa', 'bbbbbbbb' ) === $after_b, 'the first run seeds the list from the previous option' );
$after_c = sn_resume_pdf_issued( $after_b, $b, $c );
ok( array( $pdf_url, $pdf_url . '?v=aaaaaaaa', $pdf_url . '?v=bbbbbbbb', $pdf_url . '?v=cccccccc' ) === sn_resume_pdf_purge_urls( $c, $after_c ), 'a THIRD generation still purges the first ?v= URL: an old link may have cached newer bytes under it' );
ok( array( 'aaaaaaaa' ) === sn_resume_pdf_issued( false, false, $a ) && array( 'aaaaaaaa' ) === sn_resume_pdf_issued( array( 'aaaaaaaa', 'junk', 7 ), $a, $a ), 'a first generation, or the same bytes again: no duplicate, no junk' );
$many = array(); for ( $n = 0; $n < 40; $n++ ) { $many[] = sprintf( '%08x', $n ); }
$capped = sn_resume_pdf_issued( $many, $b, $c );
ok( SN_RESUME_PDF_ISSUED_MAX === count( $capped ) && 'cccccccc' === end( $capped ) && count( sn_resume_pdf_purge_urls( $c, $capped ) ) <= 30, 'capped, newest kept, and the set fits one Cloudflare purge call' );
ok( array() === sn_resume_pdf_purge_urls( array(), $after_c ) && ! in_array( 'https://example.test/resume/', sn_resume_pdf_purge_urls( $c, $after_c ), true ), 'no URL without a file; /resume/ is NOT in the list (the page save already purged it)' );
$handler = (string) file_get_contents( __DIR__ . '/../inc/admin-post-handler.php' );
ok( false !== strpos( $handler, "'resume_pdf_generate'        => 'sn_handle_resume_pdf_generate'," ) && false !== strpos( $handler, "check_admin_referer( 'sn_' . \$action )" ) && false !== strpos( $handler, "current_user_can( 'manage_options' )" ), 'the Generate action goes through the dispatcher: its own nonce plus manage_options' );
ok( is_readable( __DIR__ . '/../lib/pdf/vendor/autoload.php' ) && is_readable( __DIR__ . '/../lib/pdf/fonts/OFL.txt' ), 'Dompdf and Lato ship in lib/pdf, with Lato\'s license' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
