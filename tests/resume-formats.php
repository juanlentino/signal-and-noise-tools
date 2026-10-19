<?php
/**
 * Tests: the résumé's other formats, all from the JSON Resume mapper's output
 * so none can drift from the page: the purpose-built Markdown
 * (inc/resume-markdown.php), the vCard (inc/resume-vcard.php), the
 * hasCredential schema (inc/resume-credentials.php) and the routes and
 * discovery (inc/resume-formats-route.php).
 *
 * Run: php tests/resume-formats.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_RESUME_JSONRESUME_TEST', true );
define( 'SN_RESUME_FORMATS_TEST', true );
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function sanitize_email( $s ) { return (string) $s; }
function get_option( $k, $d = false ) { return $d; }
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
$GLOBALS['__page'] = null;
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return $GLOBALS['__page']; }
function get_bloginfo( $k = '' ) { return 'Juan Lentino'; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_permalink( $p = 0 ) { return 'https://x.test/resume/'; }
function get_post_modified_time( $f = 'U', $gmt = false, $p = null ) { return '2026-10-02T20:58:03-04:00'; }

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-es.php';
require_once __DIR__ . '/../inc/resume-jsonresume-route.php';
require_once __DIR__ . '/../inc/resume-formats-route.php';

$en = sn_resume_doc_normalize( json_decode( (string) file_get_contents( __DIR__ . '/../inc/seed-content/resume-data.json' ), true ) );
$en['pdf']['email']    = 'juan@example.com';
$en['pdf']['headline'] = 'Founder & Music Executive';
$ctx = array(
	'name'          => 'Juan Lentino',
	'url'           => 'https://x.test/',
	'canonical'     => 'https://x.test/resume/',
	'last_modified' => '2026-10-02T20:58:03-04:00',
	'version'       => '9.9.9',
	'profiles'      => array( 'https://x.com/juan_lentino', 'https://linkedin.com/in/juanlentino', 'https://orcid.org/0009-0006-8151-5920' ),
	'provenance'    => array( 'verify_url' => 'https://x.test/verify?note=u1', 'note_uid' => 'u1' ),
);
$r       = sn_jsonresume_build( $en, $ctx );
$formats = array(
	'html'        => 'https://x.test/resume/',
	'markdown'    => 'https://x.test/resume.md',
	'pdf'         => 'https://x.test/r.pdf',
	'json_resume' => 'https://x.test/resume/json-resume.json',
	'vcard'       => 'https://x.test/resume/contact.vcf',
);

echo "\nTest: the Markdown\n";
$md = sn_resume_markdown( $en, $r, $formats, 'en' );
ok( 0 === strpos( $md, "---\n" ) && strlen( $md ) > 4 && false !== strpos( $md, "\n---\n", 4 ), 'opens with YAML front matter' );
$end = strlen( $md ) > 4 ? strpos( $md, "\n---\n", 4 ) : false;
$fm  = false === $end ? '' : substr( $md, 4, $end - 4 );
foreach ( array( 'name', 'label', 'location', 'url', 'updated', 'email', 'profiles', 'formats', 'provenance' ) as $k ) {
	ok( (bool) preg_match( '~^' . $k . ':~m', $fm ), "front matter: $k" );
}
ok( false !== strpos( $fm, 'url: "https://x.test/resume/"' ) && false !== strpos( $fm, 'updated: "2026-10-02T20:58:03-04:00"' ), 'front matter: canonical and last change' );
ok( false !== strpos( $fm, 'verify_url: "https://x.test/verify?note=u1"' ) && false !== strpos( $fm, 'note_uid: "u1"' ) && (bool) preg_match( '~^  meaning: "~m', $fm ), 'front matter: the proof, with what it means' );
foreach ( $formats as $k => $u ) {
	ok( false !== strpos( $fm, "  $k: \"$u\"" ), "front matter: format $k" );
}
ok( false === stripos( $md, 'Skip to content' ) && false === strpos( $md, '[](' ), 'no site chrome, no empty links' );
ok( ! preg_match( '~^\s*__\s*$~m', $md ), 'no lone __ lines' );
ok( false !== strpos( $md, "- **15+** Years in the industry\n" ) && false !== strpos( $md, '- **110** Tracks since 2022' ), 'stats keep their labels on the same line' );
ok( false === strpos( $md, '|' ), 'no tables' );
ok( false !== strpos( $md, "- **Business Development:** Strategic Partnerships, Partner Management," ), 'skills as labeled lines' );
ok( false !== strpos( $md, "- English (Fluent)\n- Spanish (Native)" ), 'languages listed' );
$order = array( '## Summary', '## Highlights', '## Experience', '## Research & Publications', '## Education', '## Memberships & Credentials', '## Skills', '## Languages' );
$pos   = array_map( static fn( $h ) => strpos( $md, "\n$h\n" ), $order );
ok( ! in_array( false, $pos, true ) && $pos === array_values( array_unique( $pos ) ) && $pos == ( function ( $p ) { sort( $p ); return $p; } )( $pos ), 'the standard résumé order' );
ok( false !== strpos( $md, '[Provenance Over Detection: A Cryptographic Framework' ) && false !== strpos( $md, '](https://ssrn.com/abstract=6402298)' ), 'publications link out' );
ok( false !== strpos( $md, 'Voting Member, The Recording Academy' ) && false !== strpos( $md, 'Google Project Management Certificate' ), 'memberships and credentials' );
ok( false === strpos( $md, '<strong>' ) && false === strpos( $md, '—' ), 'plain text, no em dashes' );

echo "\nTest: Markdown and JSON Resume agree\n";
$agree = true;
foreach ( $r['work'] as $w ) {
	$line = '**' . $w['position'] . '** · ' . $w['startDate'] . ' to ' . ( $w['endDate'] ?? 'present' );
	$agree = $agree && false !== strpos( $md, '### ' . $w['name'] ) && false !== strpos( $md, $line );
}
ok( 6 === count( $r['work'] ) && $agree, 'every employer, role and date in the JSON Resume is in the Markdown' );
$en_moved = $en; $en_moved['experience'][0]['roles'][0]['title'] = 'Artist Development and Strategy Consultant · Oct 2022 - Present';
$md2 = sn_resume_markdown( $en_moved, sn_jsonresume_build( $en_moved, $ctx ), $formats, 'en' );
ok( false !== strpos( $md2, '2022-10 to present' ) && false === strpos( $md2, '2022-09 to present' ), 'a date edit moves both: one source' );

echo "\nTest: the Spanish Markdown\n";
$es  = sn_resume_es_merge( json_decode( (string) file_get_contents( __DIR__ . '/../inc/seed-content/resume-data-es.json' ), true ), $en, '' );
$mes = sn_resume_markdown( $es, sn_jsonresume_build( $es, $ctx ), $formats, 'es' );
ok( false !== strpos( $mes, "\n## Experiencia\n" ) && false !== strpos( $mes, ' a actualidad' ) && false !== strpos( $mes, "\n## Idiomas\n" ), 'Spanish headings and open ranges' );

echo "\nTest: the vCard\n";
$vc = sn_resume_vcard( $r );
ok( 0 === strpos( $vc, "BEGIN:VCARD\r\nVERSION:3.0\r\n" ) && "END:VCARD\r\n" === substr( $vc, -11 ), 'a vCard 3.0 with CRLF lines' );
ok( false !== strpos( $vc, "\r\nFN:Juan Lentino\r\n" ) && false !== strpos( $vc, "\r\nN:Lentino;Juan;;;\r\n" ), 'name, formatted and structured' );
ok( false !== strpos( $vc, "\r\nTITLE:Founder & Music Executive\r\n" ) && false !== strpos( $vc, "\r\nEMAIL;TYPE=INTERNET:juan@example.com\r\n" ), 'title and the public email' );
ok( false !== strpos( $vc, "\r\nADR;TYPE=WORK:;;;Orlando;FL;;\r\n" ) && false === strpos( $vc, 'TEL' ), 'city and region; no phone that is not public' );
ok( false !== strpos( $vc, "\r\nX-SOCIALPROFILE;TYPE=linkedin:https://linkedin.com/in/juanlentino\r\n" ), 'profiles' );
ok( false !== strpos( $vc, 'https://x.test/verify?note=u1' ) && false !== strpos( $vc, "\r\nURL:https://x.test/\r\n" ), 'site URL and the proof link' );
$long = sn_resume_vcard_line( 'NOTE', str_repeat( 'á', 80 ) );
$ok   = true;
foreach ( explode( "\r\n", rtrim( $long, "\r\n" ) ) as $l ) { $ok = $ok && strlen( $l ) <= 75 && mb_check_encoding( $l, 'UTF-8' ); }
ok( $ok && false !== strpos( $long, "\r\n " ), 'long lines fold at 75 octets without splitting a character' );
ok( 'a\, b\; c\\\\ d\n' === sn_resume_vcard_escape( "a, b; c\\ d\n" ), 'text values are escaped' );

echo "\nTest: credentials in the Person schema\n";
$cred = sn_resume_credentials( $en );
ok( 4 === count( $cred ), 'one credential per degree and certificate (2 + 2)' );
ok( 'EducationalOccupationalCredential' === $cred[0]['@type'] && 'degree' === $cred[0]['credentialCategory'] && 'MBA, Applied Artificial Intelligence in Business' === $cred[0]['name'], 'the MBA as a degree credential' );
ok( array( '@type' => 'CollegeOrUniversity', 'name' => 'Westcliff University' ) === $cred[0]['recognizedBy'] && '2026-08' === $cred[0]['dateCreated'], 'recognized by the university, dated' );
ok( 'certificate' === $cred[2]['credentialCategory'] && ! isset( $cred[2]['recognizedBy'] ), 'a certificate without an invented issuer' );
$creds = sn_resume_person_credentials( array( 'memberOf' => array( 'x' ) ) );
ok( array( 'x' ) === $creds['memberOf'] && ! empty( $creds['hasCredential'] ), 'the filter adds hasCredential and keeps memberOf' );

echo "\nTest: routes and discovery\n";
ok( array( 'md', 'resume' ) === sn_resume_formats_route( '/resume.md' ) && array( 'md', 'cv' ) === sn_resume_formats_route( '/cv.md?x=1' ), '/resume.md and /cv.md' );
ok( array( 'vcf', 'resume' ) === sn_resume_formats_route( '/resume/contact.vcf' ), '/resume/contact.vcf' );
ok( null === sn_resume_formats_route( '/notes/a.md' ) && null === sn_resume_formats_route( '/resume/' ), 'nothing else' );
$GLOBALS['__page'] = (object) array( 'ID' => 7, 'post_status' => 'publish', 'post_password' => '' );
$f = sn_resume_formats( 'resume' );
ok( array( 'html', 'markdown', 'pdf', 'json_resume', 'vcard' ) === array_keys( $f ) && 'https://x.test/resume.md' === $f['markdown'], 'the formats of a published résumé' );
$s = sn_resume_formats_surfaces( array() );
ok( 1 === count( $s ) && 'resume' === $s[0]['type'] && false !== strpos( $s[0]['description'], 'json-resume.json' ), 'the agents manifest lists them' );
$GLOBALS['__page'] = null;
ok( array() === sn_resume_formats( 'resume' ) && array() === sn_resume_formats_surfaces( array() ), 'no page, no formats' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
