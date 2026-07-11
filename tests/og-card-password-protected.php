<?php
/**
 * Tests: password-protected posts never get a content-bearing OG card.
 *
 * Regression for the pre-existing leak surfaced by the D2 code review: the
 * generated card (title + up to ~36 words of post_content via
 * sn_og_card_dek_source()) is served publicly and without auth at
 * wp-content/uploads/sn-og/post-{ID}.png. A password-protected post is still
 * post_status='publish', so the status-keyed gates let it through and its
 * protected content leaks as visible pixels, bypassing the password. Mirrors
 * the D1/D2 credential gate in sn_prov_credential(): '' !== $post->post_password.
 *
 * Proves four things without needing GD/fonts:
 *   1. sn_og_card_allowed_for_post() withholds the card for protected posts.
 *   2. sn_og_image_url_for_post() returns null for a protected post EVEN WHEN
 *      a card PNG already exists on disk (the strong, non-vacuous proof — a
 *      public post with the same file on disk resolves to the card URL).
 *   3. A protected post's featured image is still allowed (gate isn't a
 *      blunt instrument).
 *   4. sn_generate_og_card() bails (returns false, writes nothing) for a
 *      protected post; sn_og_delete_card() removes an existing card.
 *
 * (plugin v9.25.2)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

// --- Real temp uploads dir so the serving/delete paths run end-to-end. ------
$GLOBALS['__base'] = sys_get_temp_dir() . '/sn-og-pwtest-' . getmypid();

$GLOBALS['__posts'] = array(); // id => post object

function get_post( $p = null ) {
	if ( is_object( $p ) ) { return $p; }
	$id = (int) $p;
	return $GLOBALS['__posts'][ $id ] ?? null;
}
function has_post_thumbnail( $post ) { return ! empty( $post->__thumb ); }
function get_the_post_thumbnail_url( $post, $size = 'large' ) { return $post->__thumb ?? false; }
function get_post_modified_time( $fmt = 'U', $gmt = false, $post = null ) { return 1234; }
function wp_upload_dir() {
	return array( 'basedir' => $GLOBALS['__base'], 'baseurl' => 'https://x/uploads' );
}
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_delete_file( $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
function apply_filters( $tag, $value ) { return $value; }
function add_action() {}
function add_filter() {}
$GLOBALS['__options']    = array();
$GLOBALS['__query_ids']  = array();
$GLOBALS['__last_query'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function get_posts( $args = array() ) { $GLOBALS['__last_query'] = $args; return $GLOBALS['__query_ids']; }

require __DIR__ . '/../inc/og-card-generator.php';

// Helper: a bare post object.
function mkpost( $id, $password = '', $thumb = false ) {
	$p = (object) array(
		'ID'            => $id,
		'post_title'    => 'Secret title',
		'post_content'  => 'Confidential body that must never reach a public pixel.',
		'post_excerpt'  => '',
		'post_status'   => 'publish',
		'post_password' => $password,
		'post_type'     => 'post',
	);
	if ( $thumb ) { $p->__thumb = $thumb; }
	$GLOBALS['__posts'][ $id ] = $p;
	return $p;
}

// Place a real card PNG on disk for a given id (as the live backfill would).
function seed_card( $id ) {
	$dir = sn_og_upload_dir(); // creates <base>/sn-og
	file_put_contents( $dir['path'] . '/post-' . (int) $id . '.png', 'PNGDATA' );
	return $dir['path'] . '/post-' . (int) $id . '.png';
}

echo "Group: sn_og_card_allowed_for_post() predicate\n";
ok( true  === sn_og_card_allowed_for_post( mkpost( 1, '' ) ),        'public post is allowed a card' );
ok( false === sn_og_card_allowed_for_post( mkpost( 2, 'secret' ) ),  'password-protected post is withheld' );
ok( false === sn_og_card_allowed_for_post( null ),                   'null post is withheld' );
ok( true  === sn_og_card_allowed_for_post( mkpost( 3, '' ) ),        'empty-string password is allowed' );

echo "\nGroup: sn_og_image_url_for_post() never serves a protected card\n";
$public    = mkpost( 42, '' );
$protected = mkpost( 42, 'secret' ); // same ID overwrites the registry
$card42    = seed_card( 42 );        // card file exists on disk for post 42

// Non-vacuous anchor: with the file present, a PUBLIC post resolves to the card.
ok( 'https://x/uploads/sn-og/post-42.png?v=1234' === sn_og_image_url_for_post( $public ),
	'public post with a card on disk serves the card URL' );
// The security property: SAME file on disk, protected post -> no card served.
ok( null === sn_og_image_url_for_post( $protected ),
	'protected post returns null even though the card PNG exists on disk' );

// The gate is not a blunt instrument: an author-chosen featured image is safe.
$protected_thumb = mkpost( 43, 'secret', 'https://x/featured.png' );
ok( 'https://x/featured.png' === sn_og_image_url_for_post( $protected_thumb ),
	'protected post still serves its author-chosen featured image' );

echo "\nGroup: generation bail + card deletion\n";
$prot77 = mkpost( 77, 'secret' );
$before = $GLOBALS['__base'] . '/sn-og/post-77.png';
ok( false === sn_generate_og_card( 77 ), 'sn_generate_og_card() returns false for a protected post' );
ok( ! file_exists( $before ), 'sn_generate_og_card() writes no PNG for a protected post' );

$card99 = seed_card( 99 );
ok( file_exists( $card99 ), 'seed: card 99 exists before deletion' );
ok( true  === sn_og_delete_card( 99 ), 'sn_og_delete_card() removes an existing card' );
ok( ! file_exists( $card99 ), 'card 99 is gone after deletion' );
ok( false === sn_og_delete_card( 99 ), 'sn_og_delete_card() returns false when nothing to delete' );

echo "\nGroup: save-sync cleanup covers ANY post type (not just post/page)\n";
// A card can be generated for any editable post type via the admin-bar /
// ability / AI-title regen callers, so the delete-on-protect path must not be
// post/page-scoped or a CPT's stale card survives a public->protected switch.
$cpt_card = seed_card( 55 );
$cpt = (object) array( 'ID' => 55, 'post_status' => 'publish', 'post_password' => 'secret', 'post_type' => 'landing_page' );
$GLOBALS['__posts'][55] = $cpt;
ok( false === sn_og_sync_card_on_save( 55, $cpt ), 'save-sync returns false for a protected custom-post-type post' );
ok( ! file_exists( $cpt_card ), 'protected custom-post-type card is deleted on save (not only post/page)' );

$pub_card = seed_card( 56 );
$pub = (object) array( 'ID' => 56, 'post_status' => 'publish', 'post_password' => '', 'post_type' => 'post' );
$GLOBALS['__posts'][56] = $pub;
sn_og_sync_card_on_save( 56, $pub );
ok( file_exists( $pub_card ), 'a public post keeps its card through save-sync' );

echo "\nGroup: purge migration sweeps protected cards of ANY post type\n";
$GLOBALS['__options']   = array(); // purge not yet run
$mig_card               = seed_card( 61 ); // a protected custom-type post's pre-existing card
$GLOBALS['__query_ids'] = array( 61 );
sn_migrate_purge_protected_og_cards();
ok( 'any' === ( $GLOBALS['__last_query']['post_type'] ?? null ),
	'purge query is not restricted to post/page (covers any card-bearing type)' );
ok( true === ( $GLOBALS['__last_query']['has_password'] ?? null ),
	'purge query targets only password-protected posts' );
ok( ! file_exists( $mig_card ), 'purge migration deletes a protected post-type card returned by the query' );
ok( isset( $GLOBALS['__options']['sn_og_protected_purge_completed_v1'] ), 'purge migration sets its completion flag' );

// --- Cleanup. ---------------------------------------------------------------
$dir = $GLOBALS['__base'] . '/sn-og';
if ( is_dir( $dir ) ) {
	foreach ( glob( $dir . '/*' ) as $f ) { unlink( $f ); }
	rmdir( $dir );
}
if ( is_dir( $GLOBALS['__base'] ) ) { rmdir( $GLOBALS['__base'] ); }

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
