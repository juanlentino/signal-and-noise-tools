<?php
/**
 * Guard: the edges of the two résumés (inc/resume-variants.php,
 * inc/resume-page.php, inc/resume-operations-setup.php, the setup action).
 *
 * A save never leaves the public page (the music view) empty; the operations
 * settings survive the way the form posts them; the experience order matches
 * any case; a block with no headline, summary or sections is not yet set up;
 * a second setup run adds no row twice; and the setup action writes
 * the draft, never the published résumé, with the code that says how it went.
 *
 * Run: php tests/resume-variants-edges.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_date( $f ) { return '2026-11-01'; }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-draft.php';
require_once __DIR__ . '/../inc/admin-post-actions/resume-draft.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-operations-setup.php';

$seed = sn_resume_doc_normalize( sn_resume_seed_doc() );

echo "\nGroup: a save never empties the public page\n";
$ops_only = $seed;
foreach ( array( 'experience', 'publications' ) as $k ) {
	foreach ( $ops_only[ $k ] as $i => $row ) {
		$ops_only[ $k ][ $i ]['variant'] = 'operations';
	}
}
ok( null === sn_resume_doc_normalize( $ops_only ), 'every experience entry and publication operations-only: refused, since the music résumé would show none' );
ok( 'resume_draft_refused' === sn_handle_resume_draft_save( array( 'resume' => $ops_only ) ) && ! isset( $GLOBALS['opts']['sn_resume_draft'] ), 'and the draft save says so, storing nothing' );
$one                                  = $ops_only;
$one['publications'][0]['variant']    = 'music';
ok( null !== sn_resume_doc_normalize( $one ), 'one publication left in the music résumé: saved' );
$log = sys_get_temp_dir() . '/sn-resume-edges-' . getmypid() . '.log';
ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet -- the test reads what the code logs.
$GLOBALS['opts'] = array( 'sn_resume_doc' => $ops_only ); // stored before the rule tightened.
ok( $seed === array_diff_key( sn_resume_doc_get_raw(), array( 'updated' => 1 ) ) && false !== strpos( (string) @file_get_contents( $log ), 'the stored résumé was refused' ), 'a stored résumé the rule now refuses falls back to the seed, and says so in the error log' );
@unlink( $log );
$GLOBALS['opts'] = array();

echo "\nGroup: the operations settings as the form posts them\n";
$v = static fn( $ops ) => sn_resume_normalize_variants( array( 'operations' => $ops ) )['operations'] ?? null;
ok( array( 'summary', 'experience', 'built' ) === ( $v( array( 'headline' => 'H', 'sections' => "Summary\nExperience\nbuilt" ) )['sections'] ?? null ), 'section names in any case are kept' );
ok( null !== $v( array( 'sections' => "Summary\nExperience" ) ), 'a block with only capitalized sections is kept, not dropped' );
ok( null !== $v( array( 'tagline' => 'T', 'location' => 'L' ) ) && null !== $v( array( 'pdf_file' => 'X.pdf' ) ), 'a block with only a subhead, a location or a file name is kept' );
ok( null === $v( array( 'headline' => '', 'links' => '', 'sections' => '' ) ), 'a block with nothing in it is still dropped' );

echo "\nGroup: the experience order matches any case\n";
$ord = sn_resume_pdf_order_entries( array( array( 'org' => 'Obras' ), array( 'org' => 'ÉCOLE NORMALE' ) ), array( 'école normale' ) );
ok( array( 'ÉCOLE NORMALE', 'Obras' ) === array_column( $ord, 'org' ), 'an accented name in another case still sorts first' );

echo "\nGroup: a second setup run adds no row twice\n";
$after = sn_resume_doc_normalize( sn_resume_operations_setup( $seed )['doc'] );
$tc    = static fn( $d ) => count( array_filter( $d['affiliations'], static fn( $r ) => 0 === strpos( $r['title'], 'Team Captain' ) ) );
$osk   = static fn( $d ) => array_column( array_filter( $d['skills'], static fn( $r ) => 'operations' === ( $r['variant'] ?? '' ) ), 'category' ); // the seed has a music "Languages" row too.
$ren   = $after;
foreach ( $ren['affiliations'] as $i => $r ) {
	if ( 0 === strpos( $r['title'], 'Team Captain' ) ) {
		$ren['affiliations'][ $i ]['title'] = 'Team Captain, Music Advocacy Day (Florida)';
	}
}
ok( 1 === $tc( sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $ren ) )['doc'] ) ), 'a renamed operations affiliation is not added again beside it' );
$del               = $after;
$del['affiliations'] = array_values( array_filter( $del['affiliations'], static fn( $r ) => 0 !== strpos( $r['title'], 'Team Captain' ) ) );
ok( 0 === $tc( sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $del ) )['doc'] ) ), 'a deleted one stays deleted' );
$del_sk           = $after;
$del_sk['skills'] = array_values( array_filter( $del_sk['skills'], static fn( $r ) => array( 'Languages', 'operations' ) !== array( $r['category'], $r['variant'] ?? '' ) ) );
ok( array( 'Operations', 'Partnerships', 'AI and tools' ) === $osk( sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $del_sk ) )['doc'] ) ), 'so does a deleted operations skills row' );
$typed             = $seed;
$typed['variants'] = array( 'operations' => array( 'headline' => 'Typed before the setup ran' ) );
$t                 = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $typed ) )['doc'] );
ok( 1 === $tc( $t ) && array( 'Operations', 'Partnerships', 'AI and tools', 'Languages' ) === $osk( $t ), 'settings typed before the first run do not stop it adding its rows' );
$stub             = $seed;
$stub['variants'] = array( 'operations' => array( 'earlier_compact' => true, 'pdf_file' => 'Mine.pdf' ) );
$st               = sn_resume_doc_normalize( sn_resume_operations_setup( sn_resume_doc_normalize( $stub ) )['doc'] );
$so               = $st['variants']['operations'];
ok( '' !== $so['headline'] && '' !== $so['summary'] && array() !== $so['sections'] && 'Mine.pdf' === $so['pdf_file'], 'a block holding only the "Earlier career" box and a file name is not set up: the run writes the headline, summary and sections, and keeps the file name' );
ok( array( 'music' ) === array_values( array_unique( array_map( static fn( $r ) => $r['variant'] ?? '', array_filter( $st['skills'], static fn( $r ) => 'operations' !== ( $r['variant'] ?? '' ) ) ) ) ), 'and marks the existing skills rows "Music only"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
