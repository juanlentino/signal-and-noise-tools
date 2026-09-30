<?php
/**
 * Standalone tests for /resume drafts (inc/resume-draft.php).
 *
 * The owner rule: Save writes a draft and NOTHING goes live. Publish is
 * today's save plus a PDF rebuild when a PDF exists; an identical draft
 * publishes nothing; Revert swaps live and previous keeping each one's own
 * `updated` date, so Revert twice returns.
 *
 * Run: php tests/resume-draft.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// ── WP stubs ──
function wp_date( $fmt, $ts = null ) { return '1999-12-31'; }
function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); }
$GLOBALS['__options'] = array();
$GLOBALS['__autoload'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$same = isset( $GLOBALS['__options'][ $k ] ) && $GLOBALS['__options'][ $k ] === $v;
	$GLOBALS['__options'][ $k ]  = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return ! $same;
}
function delete_option( $k ) { $had = isset( $GLOBALS['__options'][ $k ] ); unset( $GLOBALS['__options'][ $k ] ); return $had; }
class WP_Error { public function __construct( $c = '', $m = '' ) {} }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// ── The side effects, counted ──
$GLOBALS['__calls'] = array();
function calls( $k ) { return $GLOBALS['__calls'][ $k ] ?? 0; }
function bump( $k ) { $GLOBALS['__calls'][ $k ] = calls( $k ) + 1; }
function reset_calls() { $GLOBALS['__calls'] = array(); }
function sn_resume_sync_page() { bump( 'sync' ); }
function sn_content_route_purge( $path ) { bump( 'purge:' . $path ); return true; }
$GLOBALS['__pdf_fails'] = false;
function sn_resume_pdf_generate() { bump( 'pdf' ); return $GLOBALS['__pdf_fails'] ? new WP_Error( 'x', 'y' ) : array( 'sha256' => 'abc' ); }
function get_page_by_path( $slug ) { return (object) array( 'ID' => 1184 ); }
function wp_get_post_autosave( $id ) { return (object) array( 'ID' => 9000 + $id ); }
function wp_delete_post_revision( $id ) { bump( 'autosave_delete:' . $id ); }

require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-draft.php';

$seed = sn_resume_doc_normalize( sn_resume_seed_doc() );
$alt  = $seed;
$alt['experience'][0]['org'] = 'DRAFTED PRACTICE';
function fresh( array $opts = array() ) { $GLOBALS['__options'] = $opts; reset_calls(); $GLOBALS['__pdf_fails'] = false; }
$live_a = $seed + array( 'updated' => '2020-01-01' ); // the stored shape: sn_resume_doc_save appends the stamp

echo "\nTest: the draft slot\n";
fresh();
ok( null === sn_resume_draft_get(), 'no draft: draft get is null (never the seed)' );
ok( 'No draft; showing the live résumé.' === sn_resume_draft_status(), 'no draft: the status line says the form shows the live resume' );
ok( false === sn_resume_draft_save( array( 'hero' => array( 'summary' => 'no anchor' ) ) ) && ! isset( $GLOBALS['__options'][ SN_RESUME_DRAFT_OPTION ] ), 'a refused draft is not stored' );

fresh( array( SN_RESUME_DOC_OPTION => $live_a ) );
ok( true === sn_resume_draft_save( $alt ), 'a valid draft saves' );
ok( $live_a === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ], 'draft save leaves sn_resume_doc byte-identical' );
ok( 0 === calls( 'sync' ) && 0 === calls( 'pdf' ) && 0 === calls( 'purge:/resume' ), 'draft save never syncs the Page, builds a PDF or purges' );
ok( false === $GLOBALS['__autoload'][ SN_RESUME_DRAFT_OPTION ], 'the draft is stored autoload=no' );
$d = sn_resume_draft_get();
ok( is_array( $d ) && 'DRAFTED PRACTICE' === $d['experience'][0]['org'] && '1999-12-31' === $d['updated'], 'draft get returns the normalized draft with its own saved date' );
ok( 'Draft saved 1999-12-31; differs from live.' === sn_resume_draft_status() && sn_resume_draft_differs(), 'status: the draft differs from live' );
ok( false === sn_resume_draft_save( array( 'hero' => array( 'summary' => 'no anchor' ) ) ) && 'DRAFTED PRACTICE' === sn_resume_draft_get()['experience'][0]['org'], 'a refused save leaves the earlier draft standing' );

echo "\nTest: publish equals today's save (no PDF generated yet)\n";
fresh( array( SN_RESUME_DOC_OPTION => $live_a ) );
sn_resume_doc_save( $alt );
$todays_save = $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ];
fresh( array( SN_RESUME_DOC_OPTION => $live_a ) );
sn_resume_draft_save( $alt );
ok( 'published' === sn_resume_draft_publish(), 'publish returns published' );
ok( $todays_save === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ], 'the live document is exactly what today\'s save would store' );
ok( $live_a === ( $GLOBALS['__options'][ SN_RESUME_PREV_OPTION ] ?? null ) && false === $GLOBALS['__autoload'][ SN_RESUME_PREV_OPTION ], 'the replaced document is snapshotted to sn_resume_doc_prev with its own date, autoload=no' );
ok( 1 === calls( 'sync' ) && 0 === calls( 'pdf' ) && 1 === calls( 'purge:/resume' ), 'no PDF option: the Page syncs once, no PDF is built, /resume is purged' );
ok( ! isset( $GLOBALS['__options'][ SN_RESUME_DRAFT_OPTION ] ) && 1 === calls( 'autosave_delete:10184' ), 'the draft and the Page autosave are deleted' );

echo "\nTest: publish with a PDF\n";
fresh( array( SN_RESUME_DOC_OPTION => $live_a, 'sn_resume_pdf' => array( 'sha256' => 'old' ) ) );
sn_resume_draft_save( $alt );
ok( 'published' === sn_resume_draft_publish() && 1 === calls( 'pdf' ) && 0 === calls( 'purge:/resume' ), 'a PDF option exists: publish rebuilds the PDF (which purges everything), no separate route purge' );
fresh( array( SN_RESUME_DOC_OPTION => $live_a, 'sn_resume_pdf' => array( 'sha256' => 'old' ) ) );
$GLOBALS['__pdf_fails'] = true;
sn_resume_draft_save( $alt );
ok( 'published_pdf_failed' === sn_resume_draft_publish(), 'a failed PDF rebuild after the page went live reports published_pdf_failed' );
ok( 'DRAFTED PRACTICE' === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ]['experience'][0]['org'] && ! isset( $GLOBALS['__options'][ SN_RESUME_DRAFT_OPTION ] ), 'the page is live and the draft is gone even so (it IS live)' );

echo "\nTest: identical publish is a no-op\n";
fresh( array( SN_RESUME_DOC_OPTION => $live_a, 'sn_resume_pdf' => array( 'sha256' => 'old' ) ) );
sn_resume_draft_save( $seed );
ok( 'Draft saved 1999-12-31; it matches the live résumé.' === sn_resume_draft_status() && ! sn_resume_draft_differs(), 'status: a draft equal to live says so' );
ok( 'identical' === sn_resume_draft_publish(), 'publish reports identical' );
ok( $live_a === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] && ! isset( $GLOBALS['__options'][ SN_RESUME_PREV_OPTION ] ), 'live untouched (its old date kept), no snapshot' );
ok( 0 === calls( 'sync' ) && 0 === calls( 'pdf' ) && 0 === calls( 'purge:/resume' ) && ! isset( $GLOBALS['__options'][ SN_RESUME_DRAFT_OPTION ] ), 'no sync, no PDF, no purge; the draft is deleted' );

echo "\nTest: publish edges\n";
fresh();
ok( 'none' === sn_resume_draft_publish() && array() === $GLOBALS['__options'], 'no draft: publish does nothing' );
fresh();
sn_resume_draft_save( $alt );
ok( 'published' === sn_resume_draft_publish() && ! isset( $GLOBALS['__options'][ SN_RESUME_PREV_OPTION ] ), 'first publish over the seed: nothing stored to snapshot, so no previous version' );

echo "\nTest: revert\n";
$prev_b = $alt + array( 'updated' => '2019-05-05' );
fresh( array( SN_RESUME_DOC_OPTION => $live_a, SN_RESUME_PREV_OPTION => $prev_b ) );
ok( 'reverted' === sn_resume_revert(), 'revert returns reverted' );
ok( $prev_b === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] && '2019-05-05' === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ]['updated'], 'the previous document is live with its ORIGINAL date (not today\'s 1999-12-31 stamp)' );
ok( $live_a === $GLOBALS['__options'][ SN_RESUME_PREV_OPTION ], 'the replaced live document becomes the previous one' );
ok( 1 === calls( 'sync' ) && 1 === calls( 'purge:/resume' ) && 0 === calls( 'pdf' ), 'revert re-syncs the Page and purges /resume (no PDF option)' );
sn_resume_revert();
ok( $live_a === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] && $prev_b === $GLOBALS['__options'][ SN_RESUME_PREV_OPTION ], 'revert twice restores both slots byte-identical' );
fresh( array( SN_RESUME_DOC_OPTION => $live_a, SN_RESUME_PREV_OPTION => $prev_b, 'sn_resume_pdf' => array( 'sha256' => 'old' ) ) );
$GLOBALS['__pdf_fails'] = true;
ok( 'reverted_pdf_failed' === sn_resume_revert() && $prev_b === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ], 'a failed PDF rebuild on revert reports reverted_pdf_failed; the revert stands' );
fresh( array( SN_RESUME_DOC_OPTION => $live_a ) );
ok( 'none' === sn_resume_revert() && $live_a === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ] && 0 === calls( 'sync' ), 'no previous version: revert does nothing' );
fresh( array( SN_RESUME_DOC_OPTION => $live_a, SN_RESUME_PREV_OPTION => array( 'hero' => array() ) ) );
ok( 'none' === sn_resume_revert() && $live_a === $GLOBALS['__options'][ SN_RESUME_DOC_OPTION ], 'a previous version refused by normalize is never made live' );

echo "\nTest: the handlers' flash codes\n";
function wp_unslash( $v ) { return $v; }
require_once __DIR__ . '/../inc/admin-post-actions/resume-draft.php';
require_once __DIR__ . '/../inc/admin-flash-messages.php';
$flashes = sn_admin_flash_messages();
fresh( array( SN_RESUME_DOC_OPTION => $live_a ) );
$seen = array(
	sn_handle_resume_draft_save( array( 'resume' => array( 'hero' => array( 'summary' => 'x' ) ) ) ),
	sn_handle_resume_publish( array() ),
	sn_handle_resume_revert( array() ),
	sn_handle_resume_preview_pdf( array() ),
	sn_handle_resume_preview_page( array() ),
	sn_handle_resume_draft_save( array( 'resume' => $alt ) ),
	sn_handle_resume_publish( array() ),
	sn_handle_resume_revert( array() ),
	sn_handle_resume_discard( array() ),
);
ok( array( 'resume_draft_refused', 'resume_no_draft', 'resume_no_prev', 'resume_no_draft', 'resume_no_draft', 'resume_draft_saved', 'resume_published', 'resume_reverted', 'resume_draft_discarded' ) === $seen, 'each handler maps its outcome to its own flash code: ' . implode( ',', $seen ) );
$all = array( 'resume_publish_failed', 'resume_draft_saved', 'resume_draft_refused', 'resume_no_draft', 'resume_preview_failed', 'resume_nothing_to_publish', 'resume_published', 'resume_published_pdf_failed', 'resume_draft_discarded', 'resume_no_prev', 'resume_reverted', 'resume_reverted_pdf_failed' );
ok( array() === array_diff( $all, array_keys( $flashes ) ), 'every draft flash code has a message: ' . implode( ',', array_diff( $all, array_keys( $flashes ) ) ) );
$words = implode( ' ', array_map( static function ( $c ) use ( $flashes ) { return $flashes[ $c ][1] ?? ''; }, $all ) );
ok( false === strpos( $words, "\u{2014}" ), 'no em dash in the draft messages' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
