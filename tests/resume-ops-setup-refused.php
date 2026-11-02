<?php
/**
 * Guard: a refused setup save leaves the not-found note alone
 * (inc/admin-post-actions/resume-draft.php, sn_handle_resume_operations_setup()).
 *
 * The note describes the setup in the draft, so it is written only after the
 * draft is saved. No document makes the save refuse here (the setup only adds
 * "Music only" marks and operations rows), so the draft store is stubbed.
 *
 * Run: php tests/resume-ops-setup-refused.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed ) { return strip_tags( (string) $s, '<strong><em><a>' ); } }
function sanitize_email( $e ) { return (string) filter_var( (string) $e, FILTER_SANITIZE_EMAIL ); }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
// The draft store, stubbed (inc/resume-draft.php is not loaded): no draft, and every save refused.
function sn_resume_draft_get() { return null; }
function sn_resume_draft_save( $doc ) { return false; }

require_once __DIR__ . '/../inc/admin-post-actions/content.php';
require_once __DIR__ . '/../inc/resume-page.php';
require_once __DIR__ . '/../inc/resume-pdf/template.php';
require_once __DIR__ . '/../inc/resume-operations-setup.php';
require_once __DIR__ . '/../inc/admin-post-actions/resume-draft.php';

echo "Group: a refused setup save\n";
$GLOBALS['opts'] = array( 'sn_resume_ops_setup_missing' => array( 'bullet: from the last setup that was saved' ) );
ok( 'resume_draft_failed' === sn_handle_resume_operations_setup( array() ), 'the action says the draft was not stored (a valid document the store refused: sn_resume_draft_unsaved_code())' );
ok( array( 'bullet: from the last setup that was saved' ) === $GLOBALS['opts']['sn_resume_ops_setup_missing'], 'and the not-found note still describes the setup that was saved' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
