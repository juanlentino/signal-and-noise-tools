<?php
/**
 * Standalone fixture tests for inc/schedule-pages.php (the native-future-posts
 * surfacing helper).
 *
 * Task 7 of the scheduled-content subsystem: a thin, READ-ONLY adapter that
 * lists WordPress posts/pages currently in `future` status (scheduled by core
 * to auto-publish) so Task 8's admin table can fold native scheduling in beside
 * the signal-noise/scheduled fragment queue.
 *
 * The KEY constraint this file guards: the plugin ALREADY purges a post's
 * Cloudflare URL on publish (inc/cloudflare-purge.php hooks wp_after_insert_post
 * at priority 30, which core fires on the scheduled future -> publish path too).
 * A second purge / transition hook here would DOUBLE-PURGE. So this module must
 * register NO purge or status-transition hook at all. The behavioral guard test
 * below loads the module under an add_action/add_filter recorder and asserts
 * NONE of the forbidden hooks were registered.
 *
 * Two test groups:
 *   1. Shape: an input-aware get_posts stub asserts the query args
 *      (post_status => 'future', the two post types) and returns fake future
 *      posts; the helper returns the normalized shape with the right fields.
 *   2. Guard (the important one): the add_action/add_filter recorder proves no
 *      purge / transition hook is registered, plus a static source scan for the
 *      forbidden purge identifiers.
 *
 * Run: php tests/schedule-pages.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module. CLI / WP-CLI
// only, mirroring tests/schedule-sync.php.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ─── add_action / add_filter recorder ─────────────────────────────────
// The guard test's primary mechanism: record EVERY ( hook, callback ) the
// module registers at file load, so we can assert no purge / transition hook
// was wired. Defined BEFORE the require so the require's registrations land here.
$GLOBALS['__test_registered'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
		$GLOBALS['__test_registered'][] = array( 'hook' => (string) $hook, 'callback' => $callback );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
		$GLOBALS['__test_registered'][] = array( 'hook' => (string) $hook, 'callback' => $callback );
		return true;
	}
}

// ─── WP function stubs the helper needs ───────────────────────────────
// INPUT-AWARE get_posts: it records the args it was called with (so the shape
// test can assert post_status / post_type) and returns the fake future posts a
// case stages in __test_future_posts. A handler that calls get_posts with the
// wrong status / types is visible to the assertions; a handler that does not
// call get_posts gets nothing back, so a missing call FAILS rather than passes.
$GLOBALS['__test_get_posts_args'] = null;
$GLOBALS['__test_future_posts']   = array();
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$GLOBALS['__test_get_posts_args'] = $args;
		return $GLOBALS['__test_future_posts'];
	}
}
// get_edit_post_link is stubbed (the helper normalizes an edit link). 'raw'
// context is asserted so the link does not carry HTML-escaped ampersands that
// would break a later wp_safe_redirect / programmatic use.
$GLOBALS['__test_edit_ctx'] = null;
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, $context = 'display' ) {
		$GLOBALS['__test_edit_ctx'] = $context;
		return 'https://example.com/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
	}
}
// get_the_title resolves the post title. Input-aware via the staged post object.
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post ) {
		if ( is_object( $post ) && isset( $post->post_title ) ) {
			return (string) $post->post_title;
		}
		return '';
	}
}
// get_post_time: 'U' / GMT true => the UTC unix timestamp; a string format =>
// gmdate of that. Driven off the post object's post_date_gmt so the helper's
// datetime output is derived from the real field, not a canned value.
if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( $format, $gmt = false, $post = null ) {
		$gmt_str = is_object( $post ) && isset( $post->post_date_gmt ) ? (string) $post->post_date_gmt : '';
		$ts      = $gmt_str ? strtotime( $gmt_str . ' UTC' ) : 0;
		if ( 'U' === $format ) {
			return $ts;
		}
		return $ts ? gmdate( $format, $ts ) : '';
	}
}

require_once __DIR__ . '/../inc/schedule-pages.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

/** A minimal future-post object: the fields the helper reads. */
function sp_post( $id, $title, $date_gmt ) {
	return (object) array(
		'ID'            => $id,
		'post_title'    => $title,
		'post_status'   => 'future',
		'post_date_gmt' => $date_gmt,
	);
}

echo "schedule-pages: native future-posts surfacing helper\n\n";

// ─── Group: shape ─────────────────────────────────────────────────────
echo "Group: returns the normalized future-posts shape\n";

$GLOBALS['__test_future_posts'] = array(
	sp_post( 101, 'A scheduled note',  '2026-07-01 09:00:00' ),
	sp_post( 202, 'A scheduled page',  '2026-08-15 18:30:00' ),
);

$out = sn_schedule_future_posts();

ok( is_array( $out ), 'returns an array' );
ok( count( $out ) === 2, 'one normalized item per future post' );

// The query args: assert get_posts was called with the future status + both
// post types. This is the load-bearing contract Task 8 depends on.
$args = $GLOBALS['__test_get_posts_args'];
ok( is_array( $args ), 'get_posts was actually called (args recorded)' );
ok( isset( $args['post_status'] ) && 'future' === $args['post_status'], "get_posts called with post_status => 'future'" );
$types = isset( $args['post_type'] ) ? (array) $args['post_type'] : array();
ok( in_array( 'post', $types, true ) && in_array( 'page', $types, true ), "get_posts called for both 'post' and 'page'" );
ok( isset( $args['numberposts'] ) && -1 === $args['numberposts'], 'get_posts called with numberposts => -1 (no truncation)' );
ok( isset( $args['suppress_filters'] ) && false === $args['suppress_filters'], 'get_posts called with suppress_filters => false' );

// The normalized item shape: id, title, scheduled datetime, edit link.
$first = $out[0];
ok( is_array( $first ), 'each item is a normalized array' );
ok( isset( $first['id'] ) && 101 === $first['id'], 'item carries the post id' );
ok( isset( $first['title'] ) && 'A scheduled note' === $first['title'], 'item carries the post title' );
ok( isset( $first['edit_link'] ) && false !== strpos( $first['edit_link'], 'post=101' ), 'item carries an edit link for the post' );
ok( 'raw' === $GLOBALS['__test_edit_ctx'], "edit link requested in 'raw' context (no HTML-escaped ampersands)" );

// The scheduled datetime: a UTC unix timestamp AND a canonical MySQL UTC string,
// both derived from post_date_gmt, so Task 8 can sort and render without a second
// round-trip to the post.
ok( isset( $first['scheduled_ts'] ) && is_int( $first['scheduled_ts'] ), 'item carries an integer scheduled_ts' );
ok( $first['scheduled_ts'] === strtotime( '2026-07-01 09:00:00 UTC' ), 'scheduled_ts is the post_date_gmt UTC instant' );
ok( isset( $first['scheduled_gmt'] ) && '2026-07-01 09:00:00' === $first['scheduled_gmt'], 'item carries the MySQL UTC scheduled_gmt string' );

// ─── Group: empty result ──────────────────────────────────────────────
echo "\nGroup: no future posts -> empty array\n";
$GLOBALS['__test_future_posts'] = array();
$empty = sn_schedule_future_posts();
ok( is_array( $empty ) && count( $empty ) === 0, 'no future posts yields an empty array (not null / not a warning)' );

// ─── Group: GUARD, no purge / status-transition hook registered ───────
echo "\nGroup: GUARD, module registers no purge / transition hook\n";

// The behavioral guard (primary): nothing the module registered at file load may
// be a purge or status-transition hook. If a future edit reintroduces a
// transition_post_status / future_to_publish / publish_post / save_post /
// wp_after_insert_post handler here, this FAILS, catching the double-purge
// regression at test time.
$forbidden_hooks = array(
	'transition_post_status',
	'future_to_publish',
	'publish_post',
	'publish_page',
	'save_post',
	'wp_after_insert_post',
	'pre_post_update',
);
$registered_hooks = array_map( function ( $r ) { return $r['hook']; }, $GLOBALS['__test_registered'] );
$leaked = array_values( array_intersect( $registered_hooks, $forbidden_hooks ) );
ok(
	empty( $leaked ),
	'no forbidden hook registered' . ( empty( $leaked ) ? '' : ' (LEAKED: ' . implode( ', ', $leaked ) . ')' )
);
// Stronger: this surface-only module should register NO hooks at all. The helper
// is called on demand by Task 8's renderer; it has no file-load side effects.
ok( count( $GLOBALS['__test_registered'] ) === 0, 'module registers zero hooks (pure read-only surface)' );

// Belt-and-suspenders static scan: the file source must not even mention the
// purge helpers or a status-transition hook string. A behavioral recorder can
// miss a hook added inside a function that has not been called; the source scan
// can not.
$src = file_get_contents( __DIR__ . '/../inc/schedule-pages.php' );
$forbidden_strings = array(
	'sn_cf_purge',
	'sn_schedule_purge_urls',
	'transition_post_status',
	'future_to_publish',
	'publish_post',
	'wp_after_insert_post',
);
$found_strings = array();
foreach ( $forbidden_strings as $needle ) {
	if ( false !== strpos( $src, $needle ) ) {
		$found_strings[] = $needle;
	}
}
ok(
	empty( $found_strings ),
	'source contains no purge / transition identifier' . ( empty( $found_strings ) ? '' : ' (FOUND: ' . implode( ', ', $found_strings ) . ')' )
);

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
