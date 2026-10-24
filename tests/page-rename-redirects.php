<?php
/**
 * CLI fixture for inc/page-rename-redirects.php: a published page that changes
 * slug or parent leaves a 301 from its old path (and each published
 * descendant's) in the redirect store, without loops, chains, drafts, or a
 * path another page now holds. Run: php tests/page-rename-redirects.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$fails = 0; $passes = 0;
function ok( $c, $m ) { global $fails, $passes; if ( $c ) { echo "PASS: $m\n"; $passes++; } else { echo "FAIL: $m\n"; $fails++; } }

// ── WP stubs: options, the page tree, hooks ──
$GLOBALS['__opts']    = array();
$GLOBALS['__pages']   = array(); // id => (object) page row, the CURRENT state
$GLOBALS['__actions'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function home_url( $p = '' ) { return 'https://x.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function user_trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][] = array( $hook, $cb, $prio, $args ); return true; }
// Core's get_page_uri(): the passed row's own slug and parent, ancestors as they are now.
function get_page_uri( $page ) {
	$page = is_object( $page ) ? $page : ( $GLOBALS['__pages'][ (int) $page ] ?? null );
	if ( ! $page ) { return false; }
	$uri    = $page->post_name;
	$parent = (int) $page->post_parent;
	while ( $parent && isset( $GLOBALS['__pages'][ $parent ] ) ) {
		$uri    = $GLOBALS['__pages'][ $parent ]->post_name . '/' . $uri;
		$parent = (int) $GLOBALS['__pages'][ $parent ]->post_parent;
	}
	return $uri;
}
// Like core: fetch the pages in the asked statuses, then walk child_of through
// THAT set (get_page_children), so a page under an unfetched parent is missed.
function get_pages( $args = array() ) {
	$statuses = (array) ( $args['post_status'] ?? 'publish' );
	$set      = array_filter( $GLOBALS['__pages'], static function ( $p ) use ( $statuses ) { return in_array( $p->post_status, $statuses, true ); } );
	$out      = array();
	$queue    = array( (int) ( $args['child_of'] ?? 0 ) );
	while ( $queue ) {
		$parent = array_shift( $queue );
		foreach ( $set as $p ) {
			if ( (int) $p->post_parent === $parent ) { $out[] = $p; $queue[] = (int) $p->ID; }
		}
	}
	return $out;
}
function get_page_by_path( $path ) {
	foreach ( $GLOBALS['__pages'] as $p ) {
		if ( get_page_uri( $p ) === trim( (string) $path, '/' ) ) { return $p; }
	}
	return null;
}
function get_post_status( $p ) { return is_object( $p ) ? $p->post_status : false; }

function page( $id, $slug, $parent = 0, $status = 'publish', $type = 'page' ) {
	$GLOBALS['__pages'][ $id ] = (object) array( 'ID' => $id, 'post_name' => $slug, 'post_parent' => $parent, 'post_status' => $status, 'post_type' => $type );
}
// Change a page the way wp_update_post() does, then fire the hook with before/after.
function move( $id, array $changes ) {
	$before = clone $GLOBALS['__pages'][ $id ];
	foreach ( $changes as $k => $v ) { $GLOBALS['__pages'][ $id ]->$k = $v; }
	sn_page_rename_record( $id, clone $GLOBALS['__pages'][ $id ], $before );
}
function to( $source ) { $m = sn_redirects_all(); return $m[ $source ]['to'] ?? null; }

require __DIR__ . '/../inc/redirects-store.php';
$module = __DIR__ . '/../inc/page-rename-redirects.php';
if ( ! file_exists( $module ) ) {
	ok( false, 'inc/page-rename-redirects.php exists (records renamed pages into the redirect store)' );
	echo "\n$passes passed, $fails failed\n";
	exit( 1 );
}
require $module;

$hooked = array_filter( $GLOBALS['__actions'], static function ( $a ) { return 'post_updated' === $a[0] && 'sn_page_rename_record' === $a[1] && 3 === $a[3]; } );
ok( 1 === count( $hooked ), 'hooked on post_updated with all three args, as core hooks its own old-slug check' );

// ── a slug rename, with the subtree ──
page( 1, 'about' );
page( 2, 'team', 1 );
page( 3, 'jane', 2 );
page( 4, 'old-draft', 2, 'draft' );
page( 5, 'wip', 2, 'draft' );
page( 6, 'deep', 5 ); // published under a draft parent: still served at its path
move( 2, array( 'post_name' => 'people' ) );
ok( '/about/people' === to( '/about/team' ), 'slug rename: the old path 301s to the new one' );
ok( '/about/people/jane' === to( '/about/team/jane' ), 'slug rename: a published child follows' );
ok( '/about/people/wip/deep' === to( '/about/team/wip/deep' ), 'slug rename: a published grandchild under a draft parent follows' );
ok( null === to( '/about/team/old-draft' ) && null === to( '/about/team/wip' ), 'slug rename: draft descendants are not recorded' );
$t = sn_redirect_target( '/about/team/jane/' );
ok( array( 'to' => 'https://x.test/about/people/jane/', 'status' => 301 ) === $t, 'the store resolves it: one 301 to the new path' );

// ── a parent change ──
$GLOBALS['__opts'] = array();
page( 10, 'press' );
page( 11, 'kit', 10 );
move( 11, array( 'post_parent' => 1 ) );
ok( '/about/kit' === to( '/press/kit' ), 'parent change: the old path 301s to the new parent' );

// ── what is never recorded ──
$GLOBALS['__opts'] = array();
page( 20, 'draft-page', 0, 'draft' );
move( 20, array( 'post_name' => 'draft-page-2' ) );
page( 21, 'leaving' );
move( 21, array( 'post_name' => 'leaving-2', 'post_status' => 'draft' ) );
page( 22, 'binned' );
move( 22, array( 'post_name' => 'binned__trashed', 'post_status' => 'trash' ) );
page( 23, 'a-post', 0, 'publish', 'post' );
move( 23, array( 'post_name' => 'a-post-2' ) );
page( 24, 'same' );
move( 24, array( 'post_status' => 'publish' ) );
ok( array() === sn_redirects_all(), 'nothing for a draft, an unpublish, a trash, a post (core covers posts), or a save that moved nothing' );
page( 25, 'fresh', 0, 'draft' );
move( 25, array( 'post_name' => 'fresh-final', 'post_status' => 'publish' ) );
ok( array() === sn_redirects_all(), 'nothing when a draft is published under a new slug: its old path was never public' );

// ── a rename back is not a loop ──
$GLOBALS['__opts'] = array();
page( 30, 'alpha' );
move( 30, array( 'post_name' => 'beta' ) );
move( 30, array( 'post_name' => 'alpha' ) );
ok( null === to( '/alpha' ), 'rename back: no redirect sits on the live path' );
ok( '/alpha' === to( '/beta' ), 'rename back: the abandoned path points home' );

// ── a chain collapses to one hop ──
move( 30, array( 'post_name' => 'gamma' ) );
ok( '/gamma' === to( '/beta' ) && '/gamma' === to( '/alpha' ), 'chain: every old path points straight at the newest one' );
$GLOBALS['__opts']['sn_redirects']['/elsewhere'] = array( 'to' => '/gamma', 'status' => 302, 'created_at' => 1 );
move( 30, array( 'post_name' => 'delta' ) );
ok( '/delta' === to( '/elsewhere' ) && 302 === sn_redirects_all()['/elsewhere']['status'], 'chain: an owner entry aimed at the old path follows it and keeps its status' );

// ── a path another live page holds ──
$GLOBALS['__opts'] = array();
page( 40, 'one' );
page( 41, 'two' );
move( 40, array( 'post_name' => 'tmp' ) );
move( 41, array( 'post_name' => 'one' ) );
move( 40, array( 'post_name' => 'two' ) );
ok( null === to( '/one' ) && null === to( '/two' ), 'swap: neither live page is hidden behind a redirect' );
ok( '/two' === to( '/tmp' ), 'swap: the passing path points at where its page landed' );
page( 42, 'held', 0, 'publish' );
page( 43, 'mover' );
$GLOBALS['__pages'][43]->post_name = 'held-2'; // the hook sees a before whose path another published page now holds
sn_page_rename_record( 43, clone $GLOBALS['__pages'][43], (object) array( 'ID' => 43, 'post_name' => 'held', 'post_parent' => 0, 'post_status' => 'publish', 'post_type' => 'page' ) );
ok( null === to( '/held' ), 'an old path another published page now holds is never recorded' );

// ── the legacy map header no longer claims guessing is off ──
$legacy = (string) file_get_contents( __DIR__ . '/../inc/legacy-page-redirects.php' );
ok( false === strpos( $legacy, 'deliberately disabled site-wide' ), 'legacy-page-redirects.php no longer says 404 slug guessing is disabled site-wide' );
$hooks_guess = false;
require_once __DIR__ . '/lib/inc-population.php'; // the one inc/ walker (tests/inc-population-guard.php)
foreach ( snt_test_inc_files() as $f ) {
	$hooks_guess = $hooks_guess || 1 === preg_match( "/add_filter\(\s*'do_redirect_guess_404_permalink'/", (string) file_get_contents( $f ) );
}
ok( ! $hooks_guess, 'and that stays true: no plugin file filters do_redirect_guess_404_permalink' );

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
