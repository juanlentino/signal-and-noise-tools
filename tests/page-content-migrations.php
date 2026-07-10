<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'SN_ABOUT_SLUG' ) ) { define( 'SN_ABOUT_SLUG', 'about' ); }
if ( ! defined( 'SN_ABOUT_BODY_MIGRATED_OPT' ) ) { define( 'SN_ABOUT_BODY_MIGRATED_OPT', 'sn_about_body_migrated_v1' ); }
if ( ! defined( 'SN_CONTACT_SLUG' ) ) { define( 'SN_CONTACT_SLUG', 'contact' ); }
if ( ! defined( 'SN_CONTACT_BODY_MIGRATED_OPT' ) ) { define( 'SN_CONTACT_BODY_MIGRATED_OPT', 'sn_contact_body_migrated_v1' ); }
if ( ! defined( 'SN_SERVICES_SLUG' ) ) { define( 'SN_SERVICES_SLUG', 'services' ); }
if ( ! defined( 'SN_SERVICES_BODY_MIGRATED_OPT' ) ) { define( 'SN_SERVICES_BODY_MIGRATED_OPT', 'sn_services_body_migrated_v1' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

$GLOBALS['__opt']  = array();
$GLOBALS['__page'] = null;
$GLOBALS['__upd']  = array();

if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return $GLOBALS['__page']; } }
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $a ) { $GLOBALS['__upd'][] = $a; return $a['ID']; } }

require_once SNT_PATH . 'inc/content-migrations.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "  PASS: $m\n"; } else { ++$fail; echo "  FAIL: $m\n"; } }

echo "About body migration\n";

ok( '' !== trim( sn_load_about_body() ), 'seed loader returns non-empty About markup' );
ok( false !== strpos( sn_load_about_body(), 'wp:group' ), 'seed markup contains block delimiters' );

// Fresh site, empty Page → seeds body + excerpt, sets flag.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_about_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty Page -> exactly one wp_update_post' );
ok( 42 === $GLOBALS['__upd'][0]['ID'], 'update targets the About Page' );
ok( false !== strpos( $GLOBALS['__upd'][0]['post_content'], 'wp:group' ), 'seeds the body markup' );
ok( '' !== trim( $GLOBALS['__upd'][0]['post_excerpt'] ), 'seeds a non-empty excerpt' );
ok( ! empty( $GLOBALS['__opt'][ SN_ABOUT_BODY_MIGRATED_OPT ] ), 'flag set after seeding' );

// Flag already set → no-op.
$GLOBALS['__upd'] = array(); $GLOBALS['__opt'][ SN_ABOUT_BODY_MIGRATED_OPT ] = 1;
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_about_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'flag set -> never writes again' );

// Non-empty body → never overwrites owner edits, but marks migrated.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '<!-- wp:paragraph --><p>Owner edit</p><!-- /wp:paragraph -->', 'post_excerpt' => 'hand-written' );
sn_migrate_about_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'existing body -> no overwrite' );
ok( ! empty( $GLOBALS['__opt'][ SN_ABOUT_BODY_MIGRATED_OPT ] ), 'existing body -> still marks migrated' );

// Empty body but owner-written excerpt → seeds body, LEAVES the excerpt alone.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => 'owner-written excerpt' );
sn_migrate_about_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty body + owner excerpt -> exactly one wp_update_post (body seeded)' );
ok( ! array_key_exists( 'post_excerpt', $GLOBALS['__upd'][0] ), 'owner excerpt -> post_excerpt NOT in the update args (guard holds)' );

// No Page → marks migrated, no write.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__page'] = null;
sn_migrate_about_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'no Page -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_ABOUT_BODY_MIGRATED_OPT ] ), 'no Page -> marks migrated' );

echo "\nContact body migration\n";

ok( '' !== trim( sn_load_contact_body() ), 'seed loader returns non-empty Contact markup' );
ok( false !== strpos( sn_load_contact_body(), 'wp:group' ), 'seed markup contains block delimiters' );

// Fresh site, empty Page → seeds body + excerpt, sets flag.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_contact_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty Page -> exactly one wp_update_post' );
ok( 42 === $GLOBALS['__upd'][0]['ID'], 'update targets the Contact Page' );
ok( false !== strpos( $GLOBALS['__upd'][0]['post_content'], 'wp:group' ), 'seeds the body markup' );
ok( '' !== trim( $GLOBALS['__upd'][0]['post_excerpt'] ), 'seeds a non-empty excerpt' );
ok( ! empty( $GLOBALS['__opt'][ SN_CONTACT_BODY_MIGRATED_OPT ] ), 'flag set after seeding' );

// Flag already set → no-op.
$GLOBALS['__upd'] = array(); $GLOBALS['__opt'][ SN_CONTACT_BODY_MIGRATED_OPT ] = 1;
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_contact_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'flag set -> never writes again' );

// Non-empty body → never overwrites owner edits, but marks migrated.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '<!-- wp:paragraph --><p>Owner edit</p><!-- /wp:paragraph -->', 'post_excerpt' => 'hand-written' );
sn_migrate_contact_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'existing body -> no overwrite' );
ok( ! empty( $GLOBALS['__opt'][ SN_CONTACT_BODY_MIGRATED_OPT ] ), 'existing body -> still marks migrated' );

// Empty body but owner-written excerpt → seeds body, LEAVES the excerpt alone.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => 'owner-written excerpt' );
sn_migrate_contact_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty body + owner excerpt -> exactly one wp_update_post (body seeded)' );
ok( ! array_key_exists( 'post_excerpt', $GLOBALS['__upd'][0] ), 'owner excerpt -> post_excerpt NOT in the update args (guard holds)' );

// No Page → marks migrated, no write.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__page'] = null;
sn_migrate_contact_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'no Page -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_CONTACT_BODY_MIGRATED_OPT ] ), 'no Page -> marks migrated' );

echo "\nServices body migration\n";

ok( '' !== trim( sn_load_services_body() ), 'seed loader returns non-empty Services markup' );
ok( false !== strpos( sn_load_services_body(), 'wp:group' ), 'seed markup contains block delimiters' );

// Fresh site, empty Page → seeds body + excerpt, sets flag.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_services_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty Page -> exactly one wp_update_post' );
ok( 42 === $GLOBALS['__upd'][0]['ID'], 'update targets the Services Page' );
ok( false !== strpos( $GLOBALS['__upd'][0]['post_content'], 'wp:group' ), 'seeds the body markup' );
ok( '' !== trim( $GLOBALS['__upd'][0]['post_excerpt'] ), 'seeds a non-empty excerpt' );
ok( ! empty( $GLOBALS['__opt'][ SN_SERVICES_BODY_MIGRATED_OPT ] ), 'flag set after seeding' );

// Flag already set → no-op.
$GLOBALS['__upd'] = array(); $GLOBALS['__opt'][ SN_SERVICES_BODY_MIGRATED_OPT ] = 1;
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => '' );
sn_migrate_services_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'flag set -> never writes again' );

// Non-empty body → never overwrites owner edits, but marks migrated.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '<!-- wp:paragraph --><p>Owner edit</p><!-- /wp:paragraph -->', 'post_excerpt' => 'hand-written' );
sn_migrate_services_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'existing body -> no overwrite' );
ok( ! empty( $GLOBALS['__opt'][ SN_SERVICES_BODY_MIGRATED_OPT ] ), 'existing body -> still marks migrated' );

// Empty body but owner-written excerpt → seeds body, LEAVES the excerpt alone.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array();
$GLOBALS['__page'] = (object) array( 'ID' => 42, 'post_content' => '', 'post_excerpt' => 'owner-written excerpt' );
sn_migrate_services_body();
ok( 1 === count( $GLOBALS['__upd'] ), 'empty body + owner excerpt -> exactly one wp_update_post (body seeded)' );
ok( ! array_key_exists( 'post_excerpt', $GLOBALS['__upd'][0] ), 'owner excerpt -> post_excerpt NOT in the update args (guard holds)' );

// No Page → marks migrated, no write.
$GLOBALS['__opt'] = array(); $GLOBALS['__upd'] = array(); $GLOBALS['__page'] = null;
sn_migrate_services_body();
ok( 0 === count( $GLOBALS['__upd'] ), 'no Page -> no write' );
ok( ! empty( $GLOBALS['__opt'][ SN_SERVICES_BODY_MIGRATED_OPT ] ), 'no Page -> marks migrated' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
