<?php
/**
 * Tests for the spent-migration master sentinel (v9.81.0 structure pass).
 *
 * The 23 one-shot content migrations plus the pillar meta seed used to hang
 * 24 individual admin_init hooks; they now run behind ONE master sentinel
 * check (sn_run_content_migrations). Each spent body stays callable and keeps
 * its own per-migration flag, so a fresh install still seeds idempotently —
 * the master flag only short-circuits the whole set once every individual
 * flag is present.
 *
 * Also asserts the live Now/Uses page-sync engine moved verbatim to
 * inc/page-sync-engine.php and is no longer defined by content-migrations.php.
 *
 * Standalone CLI harness (no PHPUnit): inline WP stubs, a pass/fail counter,
 * and the summary line the CI sweep gates on.
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

// Slugs the migration bodies reference.
foreach ( array(
	'SN_PROVENANCE_SLUG'     => 'provenance',
	'SN_OVER_DETECTION_SLUG' => 'over-detection',
	'SN_AS_SUBSTRATE_SLUG'   => 'as-substrate',
	'SN_ABOUT_SLUG'          => 'about',
	'SN_CONTACT_SLUG'        => 'contact',
	'SN_SERVICES_SLUG'       => 'services',
	'SN_RESUME_SLUG'         => 'resume',
	'SN_MUSIC_SLUG'          => 'music',
	'SN_NOW_SLUG'            => 'now',
	'SN_USES_SLUG'           => 'uses',
	'SN_A11Y_SLUG'           => 'accessibility',
	'SN_PERSONAL_SLUG'       => 'personal',
) as $c => $v ) {
	if ( ! defined( $c ) ) { define( $c, $v ); }
}

// The 23 per-migration flags (real option names from content-surfaces.php).
$sn_test_flags = array(
	'SN_PROV_BODY_MIGRATED_OPT'         => 'sn_provenance_body_migrated_v1',
	'SN_SEED_EXCERPTS_BACKFILL_OPT'     => 'sn_seed_page_excerpts_backfilled_v1',
	'SN_ABOUT_BODY_MIGRATED_OPT'        => 'sn_about_body_migrated_v1',
	'SN_CONTACT_BODY_MIGRATED_OPT'      => 'sn_contact_body_migrated_v1',
	'SN_SERVICES_BODY_MIGRATED_OPT'     => 'sn_services_body_migrated_v1',
	'SN_RESUME_BODY_MERGED_OPT'         => 'sn_resume_body_merged_v1',
	'SN_MUSIC_BODY_MERGED_OPT'          => 'sn_music_body_merged_v1',
	'SN_PROV_REFINE_MIGR_OPT'           => 'sn_provenance_refine_migrated_v1',
	'SN_PROV_BYLINE_RT_MIGR_OPT'        => 'sn_provenance_byline_reading_time_migrated_v1',
	'SN_PROV_SPLIT_MIGR_OPT'            => 'sn_provenance_split_migrated_v1',
	'SN_AS_SUBSTRATE_SEED_OPT'          => 'sn_provenance_as_substrate_seeded_v1',
	'SN_PROV_VERIFY_PAGE_MIGR_OPT'      => 'sn_prov_verify_page_migrated_v1',
	'SN_PROV_CARD2_LF_MIGR_OPT'         => 'sn_provenance_card2_longform_migrated_v1',
	'SN_PROV_RT_DYNAMIC_OPT'            => 'sn_provenance_card_readtimes_dynamic_v1',
	'SN_PROV_CATALOG_NUMBERS_OPT'       => 'sn_provenance_catalog_numbers_v1',
	'SN_AS_DATE_DISPLAYTYPE_OPT'        => 'sn_provenance_as_substrate_date_displaytype_v1',
	'SN_OD_EYEBROW_DYN_OPT'             => 'sn_provenance_over_detection_eyebrow_dynamic_v1',
	'SN_NOTES_TPL_OVERRIDE_CLEARED_OPT' => 'sn_notes_template_override_cleared_v1',
	'SN_NOW_PAGE_MIGRATED_OPT'          => 'sn_now_page_migrated_v1',
	'SN_USES_PAGE_MIGRATED_OPT'         => 'sn_uses_page_migrated_v1',
	'SN_NOW_USES_DOSSIER_REPAIR_OPT'    => 'sn_now_uses_dossier_repair_v1',
	'SN_A11Y_PAGE_MIGRATED_OPT'         => 'sn_accessibility_page_migrated_v1',
	'SN_PERSONAL_PAGE_MIGRATED_OPT'     => 'sn_personal_page_migrated_v1',
);
foreach ( $sn_test_flags as $c => $v ) {
	if ( ! defined( $c ) ) { define( $c, $v ); }
}

$GLOBALS['__opt']      = array();
$GLOBALS['__opt_gets'] = 0;
$GLOBALS['__actions']  = array();

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb = null ) { $GLOBALS['__actions'][] = array( $h, $cb ); return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { ++$GLOBALS['__opt_gets']; return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return null; } }
if ( ! function_exists( 'wp_insert_post' ) ) { function wp_insert_post( $a, $e = false ) { return 1; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { return $a['ID'] ?? 0; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $k = '', $s = false ) { return ''; } }
if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( $id, $k, $v ) { return true; } }
if ( ! function_exists( 'post_type_exists' ) ) { function post_type_exists( $t ) { return false; } }
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $a ) { return array(); } }
if ( ! function_exists( 'wp_delete_post' ) ) { function wp_delete_post( $id, $f = false ) { return true; } }

require_once SNT_PATH . 'inc/content-migrations.php';
require_once SNT_PATH . 'inc/pillar-meta-seed.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "content-migrations master sentinel\n";

// --- structure: engine moved out, master runner exists ---
ok( function_exists( 'sn_run_content_migrations' ), 'master runner sn_run_content_migrations() exists' );
ok( ! function_exists( 'sn_now_sync_page' ), 'page-sync engine no longer defined by content-migrations.php' );
$engine_src = (string) file_get_contents( SNT_PATH . 'inc/page-sync-engine.php' );
foreach ( array( 'sn_dossier_section_html', 'sn_now_dossier_html', 'sn_now_build_body', 'sn_now_upsert_page', 'sn_now_sync_page', 'sn_uses_dossier_html', 'sn_uses_current_body', 'sn_uses_upsert_page', 'sn_uses_sync_page' ) as $fn ) {
	ok( false !== strpos( $engine_src, "function {$fn}(" ), "engine fn {$fn} lives in inc/page-sync-engine.php" );
}

// --- exactly one admin_init hook for the whole spent set ---
$hooked = array();
foreach ( $GLOBALS['__actions'] as $a ) {
	if ( 'admin_init' === $a[0] ) { $hooked[] = $a[1]; }
}
ok( array( 'sn_run_content_migrations' ) === $hooked, 'one admin_init hook (the master runner), not 24' );

// --- master flag set -> whole set short-circuits (single option read) ---
$GLOBALS['__opt'] = array( SN_CONTENT_MIGRATIONS_MASTER_OPT => 123 );
$GLOBALS['__opt_gets'] = 0;
sn_run_content_migrations();
ok( 1 === $GLOBALS['__opt_gets'], 'master flag present -> one get_option, no per-migration checks' );

// --- all individual flags set (no master) -> runner sets the master flag ---
$GLOBALS['__opt'] = array();
foreach ( $sn_test_flags as $name ) { $GLOBALS['__opt'][ $name ] = 1; }
$GLOBALS['__opt'][ SN_PILLAR_SEED_OPTION ] = '9.79.1';
sn_run_content_migrations();
ok( ! empty( $GLOBALS['__opt'][ SN_CONTENT_MIGRATIONS_MASTER_OPT ] ), 'complete individual-flag set -> master flag stamped' );

// --- one individual flag missing -> master NOT set (retry-safe migrations keep retrying) ---
$GLOBALS['__opt'] = array();
foreach ( $sn_test_flags as $name ) { $GLOBALS['__opt'][ $name ] = 1; }
$GLOBALS['__opt'][ SN_PILLAR_SEED_OPTION ] = '9.79.1';
unset( $GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] );
$GLOBALS['__opt'][ SN_NOW_PAGE_MIGRATED_OPT ] = false; // explicit falsy
sn_run_content_migrations();
ok( empty( $GLOBALS['__opt'][ SN_CONTENT_MIGRATIONS_MASTER_OPT ] ), 'incomplete set -> master flag withheld' );

// --- pillar seed is part of the gated set ---
$GLOBALS['__opt'] = array();
foreach ( $sn_test_flags as $name ) { $GLOBALS['__opt'][ $name ] = 1; }
// pillar seed flag absent; runner invokes sn_pillar_meta_seed() which (all
// pages missing here) stamps its own sentinel, so master completes same pass.
sn_run_content_migrations();
ok( ! empty( $GLOBALS['__opt'][ SN_PILLAR_SEED_OPTION ] ), 'runner drives sn_pillar_meta_seed()' );
ok( ! empty( $GLOBALS['__opt'][ SN_CONTENT_MIGRATIONS_MASTER_OPT ] ), 'master stamps once the seed self-flags' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
