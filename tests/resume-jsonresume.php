<?php
/**
 * Tests: the résumé as JSON Resume v1.0.0 (inc/resume-jsonresume.php).
 *
 * The mapper reads the same document the page and the PDF render (the English
 * seed and the Spanish file stand in for the live option here) and its output
 * is validated against the official schema, vendored at
 * tests/fixtures/json-resume/schema-v1.0.0.json (MIT, LICENSE.md beside it).
 * The schema has no `required` keyword anywhere, so the validator is first
 * shown able to fail (extra root key, a free-text date, a bad email) before a
 * green over the real output is trusted.
 *
 * Run: php tests/resume-jsonresume.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_RESUME_JSONRESUME_TEST', true );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function sanitize_email( $s ) { return (string) $s; }
function get_option( $k, $d = false ) { return $d; }
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-es.php';
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$GLOBALS['__page'] = null;
function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return $GLOBALS['__page']; }
function get_bloginfo( $k = '' ) { return 'Juan Lentino'; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function get_permalink( $p = 0 ) { return 'https://x.test/resume/'; }
function get_post_modified_time( $f = 'U', $gmt = false, $p = null ) { return '2026-10-02T20:58:03-04:00'; }
function sn_prov_machine_pointers_manifest( $id ) { return 7 === $id ? array( 'subject' => array( 'uid' => 'u7' ) ) : null; }

require_once __DIR__ . '/../inc/resume-jsonresume-route.php';

/**
 * A draft-04 subset: type, properties, additionalProperties, items, $ref into
 * #/definitions, pattern, format (email, uri, date). What the schema uses.
 */
function jr_validate( $v, $s, $root, $path = '$' ) {
	if ( isset( $s['$ref'] ) ) {
		$s = $root['definitions'][ substr( $s['$ref'], strlen( '#/definitions/' ) ) ];
	}
	$errs = array();
	$type = $s['type'] ?? null;
	$is   = array(
		'object' => is_array( $v ) && ( array() === $v || array_keys( $v ) !== range( 0, count( $v ) - 1 ) ),
		'array'  => is_array( $v ) && array_keys( $v ) === range( 0, count( $v ) - 1 ),
		'string' => is_string( $v ),
		'number' => is_int( $v ) || is_float( $v ),
	);
	if ( $type && ! ( $is[ $type ] ?? false ) ) {
		return array( "$path: not $type" );
	}
	if ( 'object' === $type ) {
		foreach ( $v as $k => $item ) {
			if ( isset( $s['properties'][ $k ] ) ) {
				$errs = array_merge( $errs, jr_validate( $item, $s['properties'][ $k ], $root, "$path.$k" ) );
			} elseif ( false === ( $s['additionalProperties'] ?? true ) ) {
				$errs[] = "$path.$k: additional property";
			}
		}
	}
	if ( 'array' === $type && isset( $s['items'] ) ) {
		foreach ( $v as $i => $item ) {
			$errs = array_merge( $errs, jr_validate( $item, $s['items'], $root, "{$path}[$i]" ) );
		}
	}
	if ( is_string( $v ) && isset( $s['pattern'] ) && ! preg_match( '~' . $s['pattern'] . '~', $v ) ) {
		$errs[] = "$path: pattern";
	}
	$fmt = array(
		'email' => static fn( $x ) => false !== filter_var( $x, FILTER_VALIDATE_EMAIL ),
		'uri'   => static fn( $x ) => false !== filter_var( $x, FILTER_VALIDATE_URL ),
		'date'  => static fn( $x ) => (bool) preg_match( '~^\d{4}-\d{2}-\d{2}$~', $x ),
	);
	if ( is_string( $v ) && isset( $s['format'], $fmt[ $s['format'] ] ) && ! $fmt[ $s['format'] ]( $v ) ) {
		$errs[] = "$path: format {$s['format']}";
	}
	return $errs;
}

$schema = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/json-resume/schema-v1.0.0.json' ), true );
ok( is_array( $schema ) && false === $schema['additionalProperties'], 'the vendored v1.0.0 schema loads' );
ok( false !== strpos( (string) file_get_contents( __DIR__ . '/fixtures/json-resume/LICENSE.md' ), 'MIT' ), 'its MIT license is vendored beside it' );

echo "\nTest: the validator can fail\n";
ok( array() === jr_validate( array( 'basics' => array( 'name' => 'A' ) ), $schema, $schema ), 'a minimal resume passes' );
ok( array() !== jr_validate( array( 'resume' => array() ), $schema, $schema ), 'an unknown root key fails' );
ok( array() !== jr_validate( array( 'work' => array( array( 'startDate' => 'Sep 2022' ) ) ), $schema, $schema ), 'a free-text date fails' );
ok( array() !== jr_validate( array( 'basics' => array( 'email' => 'not-an-email' ) ), $schema, $schema ), 'a bad email fails' );
ok( array() !== jr_validate( array( 'skills' => array( array( 'keywords' => 'a, b' ) ) ), $schema, $schema ), 'keywords as a string, not an array, fails' );

echo "\nTest: dates\n";
ok( '2022-09' === sn_jsonresume_date( 'Sep 2022' ), 'Sep 2022 -> 2022-09' );
ok( '2018-09' === sn_jsonresume_date( 'sep. 2018' ), 'sep. 2018 -> 2018-09 (Spanish)' );
ok( '2026-08' === sn_jsonresume_date( 'agosto de 2026' ), 'agosto de 2026 -> 2026-08' );
ok( '2016' === sn_jsonresume_date( '2016' ), 'a bare year stays a year' );
ok( '' === sn_jsonresume_date( 'Present' ) && '' === sn_jsonresume_date( 'Remote' ), 'Present and non-dates parse to nothing, never a guess' );
ok( array( '2016-01', '' ) === sn_jsonresume_range( 'Jan 2016 - Present' ), 'an open range has no end' );
ok( array( '2025-09', '' ) === sn_jsonresume_range( 'septiembre de 2025 - actualidad' ), 'actualidad is open too' );

$en = sn_resume_doc_normalize( json_decode( (string) file_get_contents( __DIR__ . '/../inc/seed-content/resume-data.json' ), true ) );
$en['pdf']['email']    = 'juan@example.com';
$en['pdf']['headline'] = 'Founder & Music Executive';
$en['pdf']['phone']    = '+1 555 0100';
$ctx = array(
	'name'          => 'Juan Lentino',
	'url'           => 'https://x.test/',
	'canonical'     => 'https://x.test/resume/',
	'last_modified' => '2026-10-02T20:58:03-04:00',
	'version'       => '9.9.9',
	'profiles'      => array( 'https://x.com/juan_lentino', 'https://linkedin.com/in/juanlentino', 'https://orcid.org/0009-0006-8151-5920' ),
	'provenance'    => array( 'verify_url' => 'https://x.test/verify?note=u1', 'note_uid' => 'u1' ),
);
$r = sn_jsonresume_build( $en, $ctx );

echo "\nTest: the English résumé\n";
$errs = jr_validate( $r, $schema, $schema );
ok( array() === $errs, 'validates against JSON Resume v1.0.0' . ( $errs ? ': ' . implode( '; ', $errs ) : '' ) );
ok( 'https://raw.githubusercontent.com/jsonresume/resume-schema/v1.0.0/schema.json' === ( $r['$schema'] ?? '' ), '$schema names the version it validates against' );
$b = $r['basics'] ?? array();
ok( 'Juan Lentino' === ( $b['name'] ?? '' ) && 'Founder & Music Executive' === ( $b['label'] ?? '' ), 'basics: name and the PDF headline as label' );
ok( 'juan@example.com' === ( $b['email'] ?? '' ) && ! isset( $b['phone'] ), 'basics: the public email; the phone stays out while phone_public is off' );
ok( 0 === strpos( $b['summary'] ?? '', '15+ years building studios' ), 'basics: the page summary' );
ok( array( 'city' => 'Orlando', 'region' => 'FL' ) === ( $b['location'] ?? null ), 'basics: Orlando, FL split into city and region' );
$nets = array_column( $b['profiles'] ?? array(), 'network' );
ok( array( 'X', 'LinkedIn', 'ORCID' ) === $nets, 'profiles: the schema sameAs list, LinkedIn not doubled' );
ok( 'juanlentino' === ( $b['profiles'][1]['username'] ?? '' ), 'profiles: username from the URL' );

$w = $r['work'] ?? array();
ok( 6 === count( $w ), 'work: one entry per role, earlier career included' );
ok( 'INDEPENDENT PRACTICE' === ( $w[0]['name'] ?? '' ) && 'Artist Development and Strategy Consultant' === ( $w[0]['position'] ?? '' ), 'work: org verbatim, title without its date suffix' );
ok( '2022-09' === ( $w[0]['startDate'] ?? '' ) && ! isset( $w[0]['endDate'] ) && 'Remote' === ( $w[0]['location'] ?? '' ), 'work: dates from the role, Present has no end' );
ok( '2021-04' === ( $w[2]['endDate'] ?? '' ), 'work: a closed role ends' );
ok( 'OBRAS METÁLICAS S.A.' === ( $w[3]['name'] ?? '' ) && 'BUENOS AIRES, ARGENTINA' === ( $w[3]['location'] ?? '' ), 'work: earlier orgs split from their location' );
ok( '1997-11' === ( $w[5]['startDate'] ?? '' ), 'work: the first role, 1997' );
$hl = implode( ' ', $w[0]['highlights'] ?? array() );
ok( '' !== $hl && false === strpos( $hl, '<' ) && false !== strpos( $hl, 'roughly 110 tracks since 2022' ), 'highlights: bullets as plain text' );

$e = $r['education'] ?? array();
ok( 'MBA' === ( $e[0]['studyType'] ?? '' ) && 'Applied Artificial Intelligence in Business' === ( $e[0]['area'] ?? '' ), 'education: degree and area split' );
ok( 'Westcliff University' === ( $e[0]['institution'] ?? '' ) && '2026-08' === ( $e[0]['endDate'] ?? '' ), 'education: institution and graduation' );
ok( 'B.S. Music Production' === ( $e[1]['studyType'] ?? '' ) && ! isset( $e[1]['area'] ), 'education: a title with no comma stays whole' );

$aw = $r['awards'] ?? array();
$titles = array_column( $aw, 'title' );
ok( in_array( 'Valedictorian', $titles, true ) && in_array( 'Advanced Achiever Award', $titles, true ), 'awards: the education honors' );
ok( 'Full Sail University' === ( $aw[ array_search( 'Valedictorian', $titles, true ) ]['awarder'] ?? '' ), 'awards: awarded by the institution' );
$m = $aw[ array_search( 'Voting Member, The Recording Academy', $titles, true ) ] ?? array();
ok( 'The Recording Academy' === ( $m['awarder'] ?? '' ) && '2025-09' === ( $m['date'] ?? '' ), 'awards: the Recording Academy membership with its start' );
ok( in_array( 'Voting Member, The Latin Recording Academy', $titles, true ), 'awards: the Latin Recording Academy membership' );
$certs = array_column( $r['certificates'] ?? array(), 'name' );
ok( array( 'Google Project Management Certificate', 'Spatial Audio with Dolby Atmos in Logic Pro X' ) === $certs, 'certificates: the single-dated affiliations' );

$p = $r['publications'] ?? array();
ok( 2 === count( $p ) && 'SSRN Working Paper' === ( $p[0]['publisher'] ?? '' ) && '2026-04' === ( $p[0]['releaseDate'] ?? '' ), 'publications: publisher and date from the meta line' );
ok( 'In submission, Journal of the Audio Engineering Society' === ( $p[1]['summary'] ?? '' ) && 'https://ssrn.com/abstract=6730343' === ( $p[1]['url'] ?? '' ), 'publications: the rest of the meta and the URL' );

$sk = $r['skills'] ?? array();
ok( ! in_array( 'Languages', array_column( $sk, 'name' ), true ) && 'Business Development' === ( $sk[0]['name'] ?? '' ), 'skills: the categories, languages pulled out' );
ok( 'Strategic Partnerships' === ( $sk[0]['keywords'][0] ?? '' ), 'skills: keywords as a list' );
ok( array( array( 'language' => 'English', 'fluency' => 'Fluent' ), array( 'language' => 'Spanish', 'fluency' => 'Native' ) ) === ( $r['languages'] ?? null ), 'languages: from the Languages row' );
$en_p = $en;
$en_p['skills'] = array( array( 'category' => 'AI', 'items' => 'Content Provenance Standards (C2PA, TDMRep, RSL), LLM Tooling (Claude, MCP), , PHP' ), array( 'category' => 'Languages', 'items' => 'English (Fluent), Spanish (Native, Rioplatense)' ) );
list( $sk_p, $lang_p ) = sn_jsonresume_skills( $en_p );
ok( array( 'Content Provenance Standards (C2PA, TDMRep, RSL)', 'LLM Tooling (Claude, MCP)', 'PHP' ) === ( $sk_p[0]['keywords'] ?? null ), 'skills: a comma inside parentheses stays in its keyword; blanks are dropped' );
ok( array( 'language' => 'Spanish', 'fluency' => 'Native, Rioplatense' ) === ( $lang_p[1] ?? null ), 'languages: a comma inside the fluency stays with it' );

$meta = $r['meta'] ?? array();
ok( 'https://x.test/resume/' === ( $meta['canonical'] ?? '' ) && '2026-10-02T20:58:03-04:00' === ( $meta['lastModified'] ?? '' ) && '9.9.9' === ( $meta['version'] ?? '' ), 'meta: canonical, lastModified, version' );
ok( array( 'verify_url' => 'https://x.test/verify?note=u1', 'note_uid' => 'u1' ) === ( $meta['x-provenance'] ?? null ), 'meta: the authorship proof pointer' );

ok( 'https://www.parchment.com/lp/award/acd27b60-9389-4b6d-95e9-33c6755f53e5' === ( $r['education'][0]['x-credentialUrl'] ?? '' ) && ! isset( $r['education'][0]['url'] ), 'education: the MBA carries its issuer verification URL as an extension, not as the institution url' );
ok( ! isset( $r['education'][1]['x-credentialUrl'] ), 'education: no URL, no key' );
$en_c = $en; $en_c['affiliations'][2]['credential_url'] = 'https://example.org/cert'; $en_c['affiliations'][0]['credential_url'] = 'https://example.org/member';
$r_c  = sn_jsonresume_build( $en_c, $ctx );
ok( array() === jr_validate( $r_c, $schema, $schema ), 'still validates with credential URLs on a certificate and a membership' );
ok( 'https://example.org/cert' === ( $r_c['certificates'][0]['url'] ?? '' ), 'certificates: the native url field' );
$aw_c = array_column( $r_c['awards'], 'x-credentialUrl', 'title' );
ok( 'https://example.org/member' === ( $aw_c['Voting Member, The Recording Academy'] ?? '' ), 'awards: a membership carries its URL as an extension' );

echo "\nTest: what stays out\n";
$ctx2 = $ctx; $ctx2['provenance'] = null;
ok( ! isset( sn_jsonresume_build( $en, $ctx2 )['meta']['x-provenance'] ), 'no proof, no pointer: absence, not a stub' );
$en2 = $en; $en2['pdf']['phone_public'] = true;
ok( '+1 555 0100' === ( sn_jsonresume_build( $en2, $ctx )['basics']['phone'] ?? '' ), 'the phone only when the owner made it public' );
$en3 = $en; $en3['pdf']['email'] = ''; $en3['pdf']['headline'] = '';
$b3 = sn_jsonresume_build( $en3, $ctx )['basics'];
ok( ! isset( $b3['email'] ) && ! isset( $b3['label'] ), 'blank fields are omitted, never empty strings' );
ok( null === sn_jsonresume_build( null, $ctx ), 'no document, no résumé' );
$en4 = $en; $en4['stats'] = array( array( 'n' => '15+', 'label' => 'years' ), array( 'n' => '', 'label' => 'blank' ), array( 'n' => '110', 'label' => 'tracks' ) );
ok( array( array( 'n' => '15+', 'label' => 'years' ), array( 'n' => '110', 'label' => 'tracks' ) ) === ( sn_jsonresume_build( $en4, $ctx )['meta']['x-highlights'] ?? null ), 'meta: the stats strip as x-highlights, blank stats dropped' );
$en5 = $en; $en5['stats'] = array();
ok( ! isset( sn_jsonresume_build( $en5, $ctx )['meta']['x-highlights'] ), 'no stats, no x-highlights' );

echo "\nTest: the Spanish résumé\n";
$es  = sn_resume_lang( 'es' ) ? sn_resume_es_merge( json_decode( (string) file_get_contents( __DIR__ . '/../inc/seed-content/resume-data-es.json' ), true ), $en, '' ) : null;
$res = sn_jsonresume_build( $es, array_merge( $ctx, array( 'canonical' => 'https://x.test/cv/' ) ) );
sn_resume_lang( 'en' );
$errs = jr_validate( $res, $schema, $schema );
ok( array() === $errs, 'validates against JSON Resume v1.0.0' . ( $errs ? ': ' . implode( '; ', $errs ) : '' ) );
ok( '2018-09' === ( $res['work'][0]['startDate'] ?? '' ), 'work: Spanish month abbreviations parse' );
ok( '2026-08' === ( $res['education'][0]['endDate'] ?? '' ), 'education: agosto de 2026' );
ok( 'https://www.parchment.com/lp/award/acd27b60-9389-4b6d-95e9-33c6755f53e5' === ( $res['education'][0]['x-credentialUrl'] ?? '' ), 'education: the Spanish MBA verifies at the same URL' );
ok( 'Inglés' === ( $res['languages'][0]['language'] ?? '' ) && 'nativo' === ( $res['languages'][1]['fluency'] ?? '' ), 'languages: from the Idiomas row' );
ok( 'juan@example.com' === ( $res['basics']['email'] ?? '' ), 'contact comes from the English document' );

echo "\nTest: the route\n";
ok( 'resume' === sn_jsonresume_route_slug( '/resume/json-resume.json' ), '/resume/json-resume.json is the English résumé' );
ok( 'cv' === sn_jsonresume_route_slug( '/cv/json-resume.json?x=1' ), '/cv/json-resume.json is the Spanish one, query ignored' );
ok( '' === sn_jsonresume_route_slug( '/resume.json' ) && '' === sn_jsonresume_route_slug( '/notes/json-resume.json' ), 'the theme twin and other paths are not ours' );

echo "\nTest: only a published page is served\n";
ok( null === sn_jsonresume_for( 'resume' ), 'no page: nothing to serve' );
$GLOBALS['__page'] = (object) array( 'ID' => 7, 'post_status' => 'draft', 'post_password' => '' );
ok( null === sn_jsonresume_for( 'resume' ), 'a draft page: nothing to serve' );
$GLOBALS['__page'] = (object) array( 'ID' => 7, 'post_status' => 'publish', 'post_password' => 'pw' );
ok( null === sn_jsonresume_for( 'resume' ), 'a password-protected page: nothing to serve' );
$GLOBALS['__page'] = (object) array( 'ID' => 7, 'post_status' => 'publish', 'post_password' => '' );
$live = sn_jsonresume_for( 'resume' );
ok( 'https://x.test/verify?note=u7' === ( $live['meta']['x-provenance']['verify_url'] ?? '' ) && 'https://x.test/resume/' === ( $live['meta']['canonical'] ?? '' ), 'published: the page canonical and its own proof' );
ok( 'https://x.test/' === ( $live['basics']['url'] ?? '' ) && 'Juan Lentino' === ( $live['basics']['name'] ?? '' ), 'published: site URL and name' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
