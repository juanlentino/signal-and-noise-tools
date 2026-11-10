<?php
/**
 * Standalone test: the content seed sets two core settings once, through core,
 * and then leaves them to core (2026-11-10).
 *
 *   - Default Post Category: pointed at Notes by the one-time seed. It is not
 *     rewritten on every admin load, so a choice made in Settings > Writing
 *     sticks.
 *   - Permalink structure: set with WP_Rewrite::set_permalink_structure(), so
 *     the rules flushed in the same request are built from the new structure.
 *
 * Run: php tests/content-seed-core-settings.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

const SN_NOTES_CATEGORY_SLUG = 'notes';
const SN_PERMALINK_STRUCTURE = '/notes/%postname%/';
const SN_SEED_FLAG_OPTION    = 'sn_content_surfaces_seeded_v1';
const NOTES_TERM_ID          = 7;

$GLOBALS['__opt']     = array();
$GLOBALS['__writes']  = array(); // option names written with update_option().
$GLOBALS['__posts']   = 0;
$GLOBALS['__flushed'] = array(); // the structure WP_Rewrite held at each flush.
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__writes'][] = $k; $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_term_by( $f, $slug, $tax ) { return (object) array( 'term_id' => NOTES_TERM_ID ); }
function wp_count_posts() { return (object) array( 'publish' => $GLOBALS['__posts'] ); }
function flush_rewrite_rules() { $GLOBALS['__flushed'][] = $GLOBALS['wp_rewrite']->permalink_structure; }
foreach ( array( 'notes_category', 'notes_page', 'provenance_page', 'over_detection_page', 'as_substrate_page', 'verify_page' ) as $n ) {
	eval( "function sn_ensure_{$n}() { return 1; }" ); // phpcs:ignore -- test-only stubs for the page seeders.
}
/** The part of WP_Rewrite the seed uses: core's method writes the option and re-reads it. */
class Test_Rewrite {
	public $permalink_structure = '';
	public $calls               = array();
	public function set_permalink_structure( $s ) {
		$this->calls[] = $s;
		update_option( 'permalink_structure', $s );
		$this->permalink_structure = $s;
	}
}
function fresh() {
	$GLOBALS['__opt']      = array();
	$GLOBALS['__writes']   = array();
	$GLOBALS['__posts']    = 0;
	$GLOBALS['__flushed']  = array();
	$GLOBALS['wp_rewrite'] = new Test_Rewrite();
}

$src  = (string) file_get_contents( dirname( __DIR__ ) . '/inc/content-surfaces.php' );
$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $src );
foreach ( array( 'sn_seed_content_surfaces', 'sn_ensure_permalink_structure', 'sn_sync_default_category' ) as $f ) {
	preg_match( '/function ' . $f . '\(\) \{.*?\n}\n/s', $src, $m );
	eval( $m[0] ); // phpcs:ignore -- test-only: lift the functions out of the module.
}

// Default Post Category.
ok( 0 === preg_match( "/add_action\(\s*'[a-z_]+',\s*'sn_sync_default_category'/", $code ), 'no hook rewrites the default category on every load' );
fresh();
sn_seed_content_surfaces();
ok( NOTES_TERM_ID === get_option( 'default_category' ), 'the first seed points Default Post Category at Notes' );
ok( ! empty( get_option( SN_SEED_FLAG_OPTION ) ), 'and spends the seed flag' );
update_option( 'default_category', 12 ); // The owner picks another one in Settings > Writing.
sn_seed_content_surfaces();              // The next admin load.
ok( 12 === get_option( 'default_category' ), "the owner's choice in Settings > Writing sticks" );

// Permalink structure.
ok( false === strpos( $code, "update_option( 'permalink_structure'" ), 'the permalink option is not written directly' );
fresh();
sn_seed_content_surfaces();
ok( array( SN_PERMALINK_STRUCTURE ) === $GLOBALS['wp_rewrite']->calls, 'a fresh site: the structure is set through WP_Rewrite::set_permalink_structure()' );
ok( array( SN_PERMALINK_STRUCTURE ) === $GLOBALS['__flushed'], 'so the flush in the same request builds rules from the new structure' );
fresh();
$GLOBALS['__opt']['permalink_structure'] = SN_PERMALINK_STRUCTURE;
sn_seed_content_surfaces();
ok( array() === $GLOBALS['wp_rewrite']->calls, 'already set: nothing is written' );
fresh();
$GLOBALS['__opt']['permalink_structure'] = '/%year%/%postname%/';
$GLOBALS['__posts']                      = 3;
sn_seed_content_surfaces();
ok( array() === $GLOBALS['wp_rewrite']->calls && '/%year%/%postname%/' === get_option( 'permalink_structure' ), 'a site with published posts keeps its own structure' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
