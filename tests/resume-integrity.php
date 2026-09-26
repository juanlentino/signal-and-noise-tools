<?php
/**
 * Resume integrity: the rendered /resume copy and the Person schema agree.
 *
 * The /resume page and the generated PDF are built from ONE document (the
 * resume form, inc/resume-page.php), so they cannot drift from each other.
 * What can still drift is the page against the site-wide Person schema, and
 * the copy against itself. These pins run the real renderer over the shipped
 * seed document; the live document is a database option and is not reachable
 * here, so a fresh install's seed stands in for it.
 *
 * Not pinned: an experience group's date range against its roles' dates. Roles
 * carry no dates (roles[]{title,bullets[]}); the group's `dates` is its own
 * field, typed in the form.
 *
 * Run: php tests/resume-integrity.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
function wp_date( $fmt, $ts = null ) { return '1999-12-31'; }
function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function get_option( $k, $d = false ) { return $d; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-sync-engine.php';
require_once __DIR__ . '/../inc/seo-schema.php';

/** Visible text of a rendered body: tags out, entities decoded, whitespace collapsed. */
function resume_visible_text( $html ) {
	$text = html_entity_decode( preg_replace( '/<[^>]+>/', ' ', (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/** Text nodes (between tags) that open with, or carry, a space before punctuation. */
function resume_space_before_punct( $html ) {
	$hits = array();
	foreach ( preg_split( '/<[^>]+>/', (string) $html ) as $node ) {
		$node = html_entity_decode( $node, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/\S\s+[,.;:!?]|^\s+[,.;:!?]/u', $node, $m ) ) {
			$hits[] = trim( $node );
		}
	}
	return $hits;
}

$doc  = sn_resume_doc_normalize( sn_resume_seed_doc() );
$body = sn_resume_body_html( $doc );
$text = resume_visible_text( $body );
ok( '' !== $body, 'the seed document renders' );

echo "\nNo space before punctuation\n";
$hits = resume_space_before_punct( $body );
ok( array() === $hits, 'no text node carries " ," " ." " ;" " :" (found: ' . implode( ' | ', array_slice( $hits, 0, 3 ) ) . ')' );
// Control: the check sees a stray space, both inside a node and at a node's
// start (where it lands after an inline <strong> closes).
ok( array() !== resume_space_before_punct( '<p>markets , spanning</p>' ), 'control: a space before a comma inside a node is caught' );
ok( array() !== resume_space_before_punct( '<p><strong>markets</strong> , spanning</p>' ), 'control: a space before a comma after an inline tag is caught' );

echo "\nThe schema claims only what the page shows\n";
$awards = (array) ( sn_schema_person_credentials()['award'] ?? array() );
ok( array() !== $awards, 'the Person schema declares awards' );
foreach ( $awards as $award ) {
	// "Valedictorian, Full Sail University": the honor is the part before the institution.
	$honor = trim( explode( ',', (string) $award )[0] );
	ok( false !== strpos( $text, $honor ), "award \"$honor\" appears in the visible resume" );
}
ok( false !== strpos( $text, 'Full Sail University' ), 'the awarding institution appears too' );

echo "\nYears of experience read 15+\n";
$years = '';
foreach ( $doc['stats'] as $stat ) {
	if ( false !== stripos( $stat['label'], 'years' ) ) {
		$years = $stat['n'];
	}
}
ok( '15+' === $years, "the years stat is 15+ (got \"$years\")" );
ok( 0 === preg_match( '/\b(?!15\+)\d{2}\+? ?years\b|\b\d{2}-year\b/i', str_replace( '15+ years', '', $text ) ), 'no other years-of-experience figure in the copy ("20+ years", "15-year" and the like)' );
ok( false !== strpos( $text, '15+ years' ), 'the summary states 15+ years' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
