<?php
/**
 * Standalone test: the Signal & Noise app's ATTENTION section (#1071, phase
 * four) -- the nine readers, the composition, its sixty-second cache, and the
 * descriptor the client paints the queue from.
 *
 * Every signal is driven through a STUB of the reader the real section calls,
 * because the point under test is what the queue does with an answer, not
 * whether the estate's own readers work (they have their own suites). The
 * stubs are the house's own names (sn_/snt_), and the WordPress ones --
 * wp_count_posts, get_transient, set_transient, delete_transient, get_post,
 * get_the_title, get_permalink, current_user_can, get_current_user_id -- are
 * real WordPress functions, so tools/stub-parity.php can check their shape.
 *
 * THE CLOCK IS HELD at 2026-09-06 12:00:00 UTC through `$GLOBALS['__now']`,
 * which attention_now() reads. The cache's window is sixty seconds; a suite
 * that had to sleep for a minute to reach the second branch is a suite nobody
 * runs, and the branch would go untested.
 *
 * The descriptor is read back through `snt_os_app_sections()` -- the registry's
 * own gate -- rather than out of the array literal.
 * Run: php tests/openstation-app-attention.php
 *
 * @since 13.103.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SN_CF_PROBE_LOG_OPT', 'sn_cf_purge_probe_log' );
define( 'SN_CF_PROBE_ALGO', 2 );
define( 'SN_MR_SNAPSHOT_STALE_AFTER', 6 * 3600 );

// The clock. Everything below is relative to it.
$GLOBALS['__now'] = strtotime( '2026-09-06 12:00:00 UTC' );

// ── WordPress, flat ──────────────────────────────────────────────────

$GLOBALS['__filters'] = array();

/**
 * @param string   $hook Hook name.
 * @param callable $cb   Callback.
 * @param int      $prio Priority.
 * @param int      $args Accepted args.
 * @return bool
 */
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][ $hook ][] = $cb;
	return true;
}

/**
 * @param string $hook    Hook name.
 * @param mixed  $value   Filtered value.
 * @param mixed  ...$rest Extra args.
 * @return mixed
 */
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$rest );
	}
	return $value;
}

/**
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function __( $s, $d = null ) {
	return $s;
}

/**
 * @param string $a Singular.
 * @param string $b Plural.
 * @param int    $n Count.
 * @param string $d Domain.
 * @return string
 */
function _n( $a, $b, $n, $d = null ) {
	return 1 === (int) $n ? $a : $b;
}

$GLOBALS['__caps'] = array(
	'manage_options'    => true,
	'edit_posts'        => true,
	'edit_pages'        => true,
	'edit_others_posts' => true,
	'edit_others_pages' => false,
);

/**
 * @param string $cap  Capability.
 * @param mixed  ...$a Object args.
 * @return bool
 */
function current_user_can( $cap, ...$a ) {
	return (bool) ( $GLOBALS['__caps'][ $cap ] ?? false );
}

/** @return int */
function get_current_user_id() {
	return 5;
}

/**
 * VARIADIC, as WordPress is. The three-argument form -- add_query_arg( $key,
 * $value, $url ) -- is what the Health door uses, and a two-parameter stub
 * swallowed the URL into the query and threw, which is exactly the stub-shape
 * guess tools/stub-parity.php exists to catch.
 *
 * @param mixed ...$args ( $key, $value, $url ) or ( $args, $url ).
 * @return string
 */
function add_query_arg( ...$args ) {
	$url   = (string) array_pop( $args );
	$query = is_array( $args[0] ) ? $args[0] : array( (string) $args[0] => $args[1] );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
}

// The fixtures carry a STATUS, an AUTHOR and their section membership, because
// a jump is offered only when the section that would list the post actually
// holds it: 11 is a note in the category, 31 is a page that opted into signing,
// 23 is a post OUTSIDE the note category (it is in the probe log all the same),
// 44 is a note, 51 a note scheduled by somebody else.
$GLOBALS['__posts'] = array(
	11 => (object) array( 'ID' => 11, 'post_type' => 'post', 'post_title' => 'The signer keeps moving', 'post_status' => 'publish', 'post_author' => 5 ),
	23 => (object) array( 'ID' => 23, 'post_type' => 'post', 'post_title' => 'The ban failed', 'post_status' => 'publish', 'post_author' => 5 ),
	31 => (object) array( 'ID' => 31, 'post_type' => 'page', 'post_title' => 'Start here', 'post_status' => 'publish', 'post_author' => 5 ),
	44 => (object) array( 'ID' => 44, 'post_type' => 'post', 'post_title' => 'The fragment host', 'post_status' => 'publish', 'post_author' => 5 ),
	51 => (object) array( 'ID' => 51, 'post_type' => 'post', 'post_title' => 'Publishing soon', 'post_status' => 'future', 'post_author' => 9 ),
);

// Which fixtures are in the note category, and which pages opted into signing.
// Both are per-post facts the sections' own queries read, and both are what
// makes "the type says notes" different from "Notes lists it".
$GLOBALS['__categories'] = array( 11 => array( 'notes' ), 44 => array( 'notes' ), 51 => array( 'notes' ) );
$GLOBALS['__meta']       = array( 31 => array( '_sn_prov_sign' => '1' ) );

/**
 * @param int $id Post id.
 * @return object|null
 */
function get_post( $id ) {
	return $GLOBALS['__posts'][ (int) $id ] ?? null;
}

/**
 * @param int|string|array $category Category id, slug or name (or a list).
 * @param int|object       $post     Post or id.
 * @return bool
 */
function has_category( $category = '', $post = null ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return in_array( (string) $category, (array) ( $GLOBALS['__categories'][ $id ] ?? array() ), true );
}

/**
 * @param int|object $post Post or id.
 * @return string
 */
function get_the_title( $post ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return isset( $GLOBALS['__posts'][ $id ] ) ? (string) $GLOBALS['__posts'][ $id ]->post_title : '';
}

/**
 * @param int|object $post Post or id.
 * @return string
 */
function get_permalink( $post ) {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return 'https://example.test/?p=' . $id;
}

/**
 * @param int $id      Post id.
 * @param string $key  Meta key.
 * @param bool $single Single.
 * @return string
 */
function get_post_meta( $id, $key, $single = false ) {
	return (string) ( $GLOBALS['__meta'][ (int) $id ][ (string) $key ] ?? '' );
}

/**
 * @param string $post_type Post type.
 * @return object|null
 */
function get_post_type_object( $post_type ) {
	$caps = array(
		'post' => 'edit_others_posts',
		'page' => 'edit_others_pages',
	);
	return isset( $caps[ (string) $post_type ] )
		? (object) array( 'name' => (string) $post_type, 'cap' => (object) array( 'edit_others_posts' => $caps[ (string) $post_type ] ) )
		: null;
}

/**
 * @param string $type Post type.
 * @param string $perm Permission.
 * @return object
 */
function wp_count_posts( $type = 'post', $perm = '' ) {
	if ( ! empty( $GLOBALS['__count_posts_throws'] ) ) {
		throw new \RuntimeException( 'no database' );
	}
	return (object) array( 'pending' => (int) ( $GLOBALS['__pending'][ (string) $type ] ?? 0 ) );
}

// The transient store. Expiry is honoured against the held clock, so the
// composition's OWN age check and the transient's expiry are two mechanisms
// this suite can separate rather than one it conflates.
$GLOBALS['__transients'] = array();

/**
 * @param string $key Transient key.
 * @return mixed
 */
function get_transient( $key ) {
	$row = $GLOBALS['__transients'][ (string) $key ] ?? null;
	if ( ! is_array( $row ) ) {
		return false;
	}
	if ( $row['expires'] > 0 && (int) $GLOBALS['__now'] >= (int) $row['expires'] ) {
		unset( $GLOBALS['__transients'][ (string) $key ] );
		return false;
	}
	return $row['value'];
}

/**
 * @param string $key    Transient key.
 * @param mixed  $value  Value.
 * @param int    $expire Seconds.
 * @return bool
 */
function set_transient( $key, $value, $expire = 0 ) {
	$GLOBALS['__transients'][ (string) $key ] = array(
		'value'   => $value,
		'expires' => (int) $expire > 0 ? (int) $GLOBALS['__now'] + (int) $expire : 0,
	);
	return true;
}

/**
 * @param string $key Transient key.
 * @return bool
 */
function delete_transient( $key ) {
	unset( $GLOBALS['__transients'][ (string) $key ] );
	return true;
}

// ── The estate's readers, stubbed ────────────────────────────────────
// Each records that it was CALLED, so the cache can be measured by reads and
// not by the answer it returns -- a cache that recomputed and got the same
// rows would otherwise look identical to one that did not.

$GLOBALS['__reads'] = array();

/**
 * @param string $signal Reader name.
 * @return void
 */
function t_read( $signal ) {
	$GLOBALS['__reads'][ $signal ] = ( $GLOBALS['__reads'][ $signal ] ?? 0 ) + 1;
}

/** @return array|null */
function sn_prov_integrity_state() {
	t_read( 'integrity' );
	if ( ! empty( $GLOBALS['__integrity_throws'] ) ) {
		throw new \RuntimeException( 'option store unavailable' );
	}
	return $GLOBALS['__integrity'];
}

/**
 * @param string $code Failure code.
 * @return string
 */
function sn_prov_integrity_failure_sentence( $code ) {
	$legs = array(
		'hash_mismatch'      => 'stored payload no longer reproduces the anchored content hash (hash mismatch)',
		'twin_drift'         => 'the published .json twin\'s words no longer match the signed payload (twin drift)',
		'twin_unreachable'   => 'the published .json twin could not be fetched (unreachable: an outage, not drift)',
		'ledger_unreachable' => 'the public ledger could not be reached (unreachable: an outage, not drift)',
	);
	return $legs[ (string) $code ] ?? (string) $code;
}

/**
 * The sweep's own partition, with its three real codes: an outage is a gap in
 * today's evidence, never a claim about the note.
 *
 * @param string $code Failure code.
 * @return bool
 */
function sn_prov_integrity_is_outage( $code ) {
	return in_array( (string) $code, array( 'twin_unreachable', 'ledger_unreachable', 'keys_unreachable' ), true );
}

/** @return array */
function sn_prov_admin_status() {
	t_read( 'anchors' );
	return array( 'pending' => $GLOBALS['__anchors'], 'genesis' => array() );
}

/**
 * @param string $key Option.
 * @param mixed  $d   Default.
 * @return mixed
 */
function get_option( $key, $d = false ) {
	if ( SN_CF_PROBE_LOG_OPT === (string) $key ) {
		t_read( 'edge' );
		return $GLOBALS['__probe_log'];
	}
	return $GLOBALS['__options'][ (string) $key ] ?? $d;
}

/**
 * @param string $last Verdict.
 * @return string
 */
function snt_cf_freshness_headline( $last ) {
	return 'stale' === (string) $last ? 'Edge served a stale render' : 'Edge fresh';
}

/**
 * @param string $last      Verdict.
 * @param int    $last_time Verdict time.
 * @param int    $now       Now.
 * @return string
 */
function snt_cf_freshness_phrase( $last, $last_time, $now ) {
	return 'last verdict ' . (int) ( ( (int) $now - (int) $last_time ) / 60 ) . ' mins ago';
}

// The citations store speaks through $wpdb, and its two readers NEVER throw:
// a failed query returns the zero shapes with last_error set. The stub is the
// real thing's shape, so the reader can be driven through that exact state.
$GLOBALS['wpdb'] = (object) array( 'posts' => 'wp_posts', 'last_error' => '' );

/** @return array */
function sn_cit_counts() {
	t_read( 'citations' );
	if ( ! empty( $GLOBALS['__citations_throws'] ) ) {
		throw new \RuntimeException( 'SQLite refuses UTC_TIMESTAMP()' );
	}
	if ( ! empty( $GLOBALS['__citations_db_error'] ) ) {
		// What wpdb does on a failed read: the zero shape, and an error set.
		$GLOBALS['wpdb']->last_error = (string) $GLOBALS['__citations_db_error'];
		return array_fill_keys( array( 'verified', 'unattributed', 'asserted', 'unverified', 'never_checked' ), 0 );
	}
	return $GLOBALS['__cit_counts'];
}

/**
 * @param int $limit      Rows.
 * @param int $stale_days Window.
 * @return array
 */
function sn_cit_due_for_check( $limit = 10, $stale_days = 7 ) {
	$GLOBALS['__cit_due_args'] = array( (int) $limit, (int) $stale_days );
	if ( ! empty( $GLOBALS['__citations_db_error'] ) ) {
		return array(); // is_array( $rows ) ? $rows : array() -- an empty read.
	}
	return array_slice( (array) $GLOBALS['__cit_due'], 0, (int) $limit );
}

/** @return array */
function sn_schedule_all() {
	return array();
}

/** @return array */
function sn_schedule_future_posts() {
	return array();
}

/**
 * @param array $fragments Fragment rows.
 * @param array $posts     Future post rows.
 * @return array
 */
function sn_admin_schedule_ordered_rows( $fragments, $posts ) {
	t_read( 'schedule' );
	return $GLOBALS['__schedule'];
}

/**
 * @param mixed $starts_at Start boundary.
 * @param mixed $ends_at   End boundary.
 * @return int
 */
function sn_admin_schedule_next_transition_ts( $starts_at, $ends_at ) {
	$out = 0;
	foreach ( array( $starts_at, $ends_at ) as $b ) {
		$b = (string) $b;
		if ( '' === $b ) {
			continue;
		}
		$ts = strtotime( $b . ' UTC' );
		if ( false === $ts || $ts <= (int) $GLOBALS['__now'] ) {
			continue;
		}
		if ( 0 === $out || $ts < $out ) {
			$out = $ts;
		}
	}
	return $out;
}

/** @return array|null */
function sn_health_last_scan() {
	t_read( 'health' );
	return $GLOBALS['__scan'];
}

/**
 * @param array|null $scan Scan.
 * @return array
 */
function sn_health_flagged_checks( $scan ) {
	return is_array( $scan ) ? (array) ( $scan['flagged'] ?? array() ) : array();
}

/**
 * @param int|null   $now  Now.
 * @param array|null $rows Rows.
 * @return array
 */
function snt_watches_ripe( $now = null, $rows = null ) {
	t_read( 'watches' );
	$GLOBALS['__watches_now'] = $now;
	return $GLOBALS['__watches'];
}

/** @return array|null */
function snt_mr_snapshot() {
	t_read( 'readers' );
	return $GLOBALS['__snapshot'];
}

/**
 * @param array|null $snap Snapshot.
 * @return bool|null
 */
function snt_mr_snapshot_is_stale( $snap ) {
	if ( ! is_array( $snap ) || ! is_int( $snap['captured_at'] ?? null ) ) {
		return null;
	}
	return ( (int) $GLOBALS['__now'] - (int) $snap['captured_at'] ) > SN_MR_SNAPSHOT_STALE_AFTER;
}

/**
 * @param string $slug Tab slug.
 * @param string $sub  Leaf slug.
 * @return string
 */
function snt_desktop_admin_url( $slug, $sub = '' ) {
	return 'https://example.test/wp-admin/admin.php?page=' . $slug . ( '' !== $sub ? '&sub=' . $sub : '' );
}

// ── Fixtures ─────────────────────────────────────────────────────────

$now = (int) $GLOBALS['__now'];

/**
 * Every fixture back to its starting value. Called between scenarios so a
 * scenario can never be read as the previous one's leftovers.
 *
 * @return void
 */
function t_fixtures() {
	$now                       = (int) $GLOBALS['__now'];
	$GLOBALS['__integrity']    = array(
		'notes'      => array(
			11 => array( 'uid' => 'sn:note:11', 'title' => 'The signer keeps moving', 'url' => '', 'version' => 2, 'last_checked' => $now - 3600, 'failures' => array( 'hash_mismatch', 'twin_drift' ) ),
			44 => array( 'uid' => 'sn:note:44', 'title' => 'The fragment host', 'url' => '', 'version' => 1, 'last_checked' => $now - 3600, 'failures' => array() ),
		),
		'last_sweep' => array( 'swept_at' => $now - 3600, 'fleet' => 12, 'checked' => 10, 'keys' => 'ok' ),
	);
	$GLOBALS['__anchors']      = array(
		array( 'post_id' => 23, 'note_uid' => 'sn:note:23', 'kind' => 'note', 'ledger_url' => '', 'version' => 4, 'status' => 'unanchored', 'committed_at' => '2026-09-06 09:00:00' ),
		array( 'post_id' => 31, 'note_uid' => 'sn:page:31', 'kind' => 'page', 'ledger_url' => '', 'version' => 2, 'status' => 'pending', 'committed_at' => '2026-09-06 10:00:00' ),
		array( 'post_id' => 51, 'note_uid' => 'sn:note:51', 'kind' => 'note', 'ledger_url' => '', 'version' => 1, 'status' => 'confirmed', 'committed_at' => '2026-09-06 11:00:00' ),
	);
	// Newest first, as the log is written. 23 is stale AND has an older fresh
	// row; 51 is fresh AND has an older stale row -- so "newest per post" is
	// measured in both directions.
	$GLOBALS['__probe_log']    = array(
		array( 'time' => $now - 600, 'post_id' => 23, 'url' => 'https://example.test/?p=23', 'result' => 'stale', 'escalated' => true, 'algo' => 2 ),
		array( 'time' => $now - 900, 'post_id' => 23, 'url' => 'https://example.test/?p=23', 'result' => 'fresh', 'algo' => 2 ),
		array( 'time' => $now - 1200, 'post_id' => 11, 'url' => 'https://example.test/?p=11', 'result' => 'stale', 'algo' => 1 ),
		array( 'time' => $now - 1300, 'post_id' => 44, 'url' => 'https://example.test/?p=44', 'result' => 'stale', 'source' => 'manual_zone_purge', 'algo' => 2 ),
		array( 'time' => $now - 1400, 'post_id' => 51, 'url' => 'https://example.test/?p=51', 'result' => 'fresh', 'algo' => 2 ),
		array( 'time' => $now - 1500, 'post_id' => 51, 'url' => 'https://example.test/?p=51', 'result' => 'stale', 'algo' => 2 ),
	);
	$GLOBALS['__cit_counts']   = array( 'verified' => 4, 'unattributed' => 1, 'asserted' => 0, 'unverified' => 2, 'never_checked' => 3 );
	$GLOBALS['__cit_due']      = array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) );
	$GLOBALS['__schedule']     = array(
		array( 'kind' => 'post', 'row' => array( 'id' => 51, 'title' => 'Publishing soon', 'scheduled_gmt' => gmdate( 'Y-m-d H:i:s', $now + 1800 ) ), 'ts' => $now + 1800 ),
		array( 'kind' => 'fragment', 'row' => array( 'id' => 7, 'target_ref' => 44, 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + 3600 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $now + 999999 ) ), 'ts' => $now + 3600 ),
		array( 'kind' => 'fragment', 'row' => array( 'id' => 8, 'target_ref' => 0, 'starts_at' => gmdate( 'Y-m-d H:i:s', $now - 99999 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $now + 7200 ) ), 'ts' => $now + 7200 ),
		array( 'kind' => 'fragment', 'row' => array( 'id' => 9, 'target_ref' => 0, 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + 259200 ), 'ends_at' => '' ), 'ts' => $now + 259200 ),
		array( 'kind' => 'fragment', 'row' => array( 'id' => 10, 'target_ref' => 0, 'starts_at' => '', 'ends_at' => '' ), 'ts' => 0 ),
	);
	$GLOBALS['__pending']      = array( 'post' => 2, 'page' => 1 );
	$GLOBALS['__scan']         = array(
		'scanned_at' => $now - 7200,
		'flagged'    => array( 'broken_links' => array( 'count' => 3, 'label' => 'Broken links', 'fix_hint' => 'Fix them.', 'skipped' => null, 'findings' => array() ) ),
	);
	$GLOBALS['__watches']      = array(
		array( 'id' => 'origin_503_recheck', 'label' => 'origin 503 recheck', 'read' => 'sn-status{uptime}', 'note' => '19 origin 503s in the last day' ),
	);
	$GLOBALS['__snapshot']     = array( 'captured_at' => $now - 8 * 3600, 'total' => 40 );
	$GLOBALS['__reads']        = array();
	$GLOBALS['__integrity_throws'] = false;
	$GLOBALS['__citations_throws'] = false;
	$GLOBALS['__citations_db_error'] = '';
	$GLOBALS['wpdb']->last_error   = '';
	$GLOBALS['__count_posts_throws'] = false;
	$GLOBALS['__caps']['edit_others_posts'] = true;
	$GLOBALS['__caps']['edit_others_pages'] = false;
	$GLOBALS['__caps']['edit_pages']        = true;
	delete_transient( 'snt_os_attention' );
}

t_fixtures();

require_once __DIR__ . '/../inc/openstation-app.php';
require_once __DIR__ . '/../apps/signal-noise/parts/attention-readers.php';
require_once __DIR__ . '/../apps/signal-noise/parts/attention.php';
// The REAL post sections, so a jump has somewhere to go and the membership
// question is answered by the sections' own `contains` -- a hand-written fake
// section here would have pinned this suite's idea of Notes, not Notes.
require_once __DIR__ . '/../apps/signal-noise/parts/post-items.php';
require_once __DIR__ . '/../apps/signal-noise/parts/notes.php';
require_once __DIR__ . '/../apps/signal-noise/parts/pages.php';

$pass = 0;
$fail = 0;

/**
 * @param bool   $c Condition.
 * @param string $m Message.
 * @return void
 */
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "PASS: $m\n";
	} else {
		++$fail;
		echo "FAIL: $m\n";
	}
}

/**
 * The queue as items, from a cold cache.
 *
 * @return array<int,array<string,mixed>>
 */
function t_items() {
	delete_transient( 'snt_os_attention' );
	return \SignalNoise\OpenStationApp\attention_items();
}

/**
 * One item by id, or null.
 *
 * @param array  $items Items.
 * @param string $id    Item id.
 * @return array|null
 */
function t_item( array $items, $id ) {
	foreach ( $items as $item ) {
		if ( (string) $item['id'] === (string) $id ) {
			return $item;
		}
	}
	return null;
}

echo "openstation-app-attention -- the queue (#1071)\n\nGroup 1: the readers, one signal at a time\n";

$rows = \SignalNoise\OpenStationApp\attention_integrity();
ok( 1 === count( $rows['rows'] ) && 11 === $rows['rows'][0]['post_id'], 'integrity: one row per FAILING note -- the clean note in the same state is not a row' );
ok( 'The signer keeps moving' === $rows['rows'][0]['title'] && 'danger' === $rows['rows'][0]['tone'], '   ...titled with the note, toned danger: a failed triangle leg is a failure, not a warning' );
ok( 'stored payload no longer reproduces the anchored content hash (hash mismatch); the published .json twin\'s words no longer match the signed payload (twin drift)' === $rows['rows'][0]['subtitle'], '   ...subtitled with sn_prov_integrity_failure_sentence(), every failing leg named -- the ONE table the sweep, the health check and the app verdict all read' );
ok( '2026-09-06 11:00:00' === $rows['rows'][0]['stamp'] && '2026-09-06 11:00:00' === $rows['stamp'], '   ...stamped with the NOTE\'s own last_checked (findings accrue across a ten-note rotation), and the reader stamped with the sweep' );

// AN UNREACHABLE CHECK READS UNREACHABLE, NEVER FAILED. The sweep counts a
// note whose codes are all outages in its own `unreachable` bucket, and
// sn_note_dossier_verify() tones exactly this partition warning; the queue
// must not be the one surface that calls a gap a failure.
$GLOBALS['__integrity']['notes'][11]['failures'] = array( 'ledger_unreachable', 'twin_unreachable' );
$rows = \SignalNoise\OpenStationApp\attention_integrity();
ok( 'warning' === $rows['rows'][0]['tone'] && false !== strpos( $rows['rows'][0]['subtitle'], 'an outage, not drift' ), '   ...a note whose failures are ALL outages is a WARNING: a gap in today\'s evidence is not a claim about the note (the sweep\'s own `unreachable` bucket)' );
$GLOBALS['__integrity']['notes'][11]['failures'] = array( 'ledger_unreachable', 'hash_mismatch' );
$rows = \SignalNoise\OpenStationApp\attention_integrity();
ok( 'danger' === $rows['rows'][0]['tone'], '   ...and ONE real code among the outages puts it back to danger: the partition is "all gaps", never "any gap"' );
$GLOBALS['__integrity']['notes'][11]['failures'] = array( 'subject_kind_unresolved' );
$rows = \SignalNoise\OpenStationApp\attention_integrity();
ok( 'warning' === $rows['rows'][0]['tone'], '   ...a named GAP (subject_kind_unresolved) is a warning too, though sn_prov_integrity_is_outage() does not call it an outage: the house\'s own "a gap, never a drift claim"' );
t_fixtures();

$rows = \SignalNoise\OpenStationApp\attention_anchors();
ok( 2 === count( $rows['rows'] ), 'anchors: the unanchored and the pending commits, and NOT the confirmed one' );
ok( 'v4 unanchored since 2026-09-06 09:00:00 UTC' === $rows['rows'][0]['subtitle'] && 'warning' === $rows['rows'][0]['tone'], '   ...an unanchored commit says its version and how long, and warns: the Worker never answered' );
ok( 'v2 pending since 2026-09-06 10:00:00 UTC' === $rows['rows'][1]['subtitle'] && 'neutral' === $rows['rows'][1]['tone'], '   ...a pending one is NEUTRAL: a proof in flight is the system working, and no threshold for "too long" exists in the estate to invent one from' );
ok( '2026-09-06 10:00:00' === $rows['stamp'], '   ...the reader stamps with the NEWEST commit it saw' );
ok( 'The signed commit chain (the newest hundred published subjects with a ledger UID)' === $rows['rows'][0]['source'], '   ...and the source DISCLOSES the window it read: sn_prov_admin_status() sees the newest hundred UID-carrying published subjects, so "the signed commit chain" alone would read as the whole chain' );

$rows = \SignalNoise\OpenStationApp\attention_edge();
ok( 1 === count( $rows['rows'] ) && 23 === $rows['rows'][0]['post_id'], 'edge: only the post whose NEWEST probe row is stale -- 51\'s older stale row is history, and a fresh row after a stale one is not a finding' );
ok( 'Edge served a stale render, last verdict 10 mins ago A zone purge was forced.' === $rows['rows'][0]['subtitle'], '   ...the words come from snt_cf_freshness_headline/phrase, the single producers, and the escalation is said' );
ok( null === t_item( t_items(), 'a-edge-11' ) && null === t_item( t_items(), 'a-edge-44' ), '   ...a row from the OLD detector (algo 1) and a manual zone purge are both skipped: one measured with a broken instrument, the other is the operator moving the diagnostic' );

$rows = \SignalNoise\OpenStationApp\attention_citations();
ok( 2 === count( $rows['rows'] ), 'citations: never-checked and due are TWO rows -- one figure cannot say both' );
ok( '3 citations have never been checked' === $rows['rows'][0]['subtitle'] && 'warning' === $rows['rows'][0]['tone'], '   ...never-checked warns: nobody has looked at those rows at all' );
ok( '2 citations are due for a check (unchecked, or checked over 7 days ago)' === $rows['rows'][1]['subtitle'] && 'neutral' === $rows['rows'][1]['tone'], '   ...due is neutral: the verifier drains ten an hour' );
ok( array( 50, 7 ) === $GLOBALS['__cit_due_args'], '   ...the due read is BOUNDED (50 rows, a 7-day window), never an unbounded scan on a root paint' );
$GLOBALS['__cit_due'] = array_fill( 0, 60, (object) array( 'id' => 1 ) );
$rows = \SignalNoise\OpenStationApp\attention_citations();
ok( false !== strpos( $rows['rows'][1]['subtitle'], '50 or more citations are due' ), '   ...and at the bound it says "or more": reporting the cap as the total would be a number nobody measured' );
$GLOBALS['__cit_due'] = array( (object) array( 'id' => 1 ) );
$rows = \SignalNoise\OpenStationApp\attention_citations();
ok( '1 citation is due for a check (unchecked, or checked over 7 days ago)' === $rows['rows'][1]['subtitle'], '   ...and ONE due citation reads as one: the count carries the verb' );
$GLOBALS['__cit_due'] = array( (object) array( 'id' => 1 ), (object) array( 'id' => 2 ) );

// NEITHER STORE FUNCTION THROWS. A failed wpdb query hands back the zero
// shapes with last_error set, so a broken store is indistinguishable from a
// clean one unless the reader looks -- and a zero nobody measured is exactly
// the reading this queue must never produce.
$GLOBALS['__citations_db_error'] = "Table 'wp_sn_citations' doesn't exist";
$rows = \SignalNoise\OpenStationApp\attention_citations();
ok( ! empty( $rows['unreadable'] ) && array() === $rows['rows'], 'citations: zeros with $wpdb->last_error set are UNREADABLE, never a clean empty read -- the store returns []/0 on a failed query and never throws' );
$items = t_items();
ok( is_array( t_item( $items, 'a-citations-unreadable' ) ) && null === t_item( $items, 'a-citations-never-checked' ), '   ...so the composition carries ONE warning row for the signal, and none of its zeros' );
$GLOBALS['__citations_db_error'] = '';
$GLOBALS['wpdb']->last_error     = '';
// A stale error from an unrelated earlier query must not condemn this reading:
// the reader clears last_error before it calls, the way the house pattern does.
$GLOBALS['wpdb']->last_error = 'Deadlock found when trying to get lock';
$rows = \SignalNoise\OpenStationApp\attention_citations();
ok( empty( $rows['unreadable'] ) && 2 === count( $rows['rows'] ), '   ...and an error left behind by an EARLIER, unrelated query does not: last_error is cleared before the two reads, never merely consulted after' );

$rows = \SignalNoise\OpenStationApp\attention_schedule();
ok( 3 === count( $rows['rows'] ), 'schedule: only the transitions inside 24 hours -- the one three days out and the one with no boundary at all are not rows' );
$subs = array_column( $rows['rows'], 'subtitle' );
ok( 'publishes at ' . gmdate( 'Y-m-d H:i:s', $now + 1800 ) . ' UTC' === $subs[0], '   ...a scheduled post says it publishes, and when' );
ok( 'opens at ' . gmdate( 'Y-m-d H:i:s', $now + 3600 ) . ' UTC' === $subs[1] && 'closes at ' . gmdate( 'Y-m-d H:i:s', $now + 7200 ) . ' UTC' === $subs[2], '   ...a fragment says OPENS or CLOSES, read back off which boundary the ordered row resolved to' );
ok( 'The fragment host' === $rows['rows'][1]['title'] && '(unlinked fragment)' === $rows['rows'][2]['title'], '   ...titled with the host post, or the leaf\'s own word for a fragment that has none' );

// edit_others_pages is FALSE in the fixture, and the pages row is composed all
// the same: the composition is one site-wide transient, so it must not depend
// on who happened to fill it. The gate is the row's own `requires`, applied
// when the queue is READ (Group 5c).
$rows = \SignalNoise\OpenStationApp\attention_pending();
ok( 2 === count( $rows['rows'] ) && array( 'post', 'page' ) === array_column( $rows['rows'], 'key' ), 'pending: BOTH rows are composed, for everyone -- a capability decided inside a shared cache would let whoever filled it decide for whoever reads it' );
ok( '2 posts are pending review' === $rows['rows'][0]['subtitle'] && '1 page is pending review' === $rows['rows'][1]['subtitle'], '   ...each says how many, of what, and one page reads as one' );
ok( array( 'edit_others_posts', 'edit_others_pages' ) === array_column( $rows['rows'], 'requires' ), '   ...and each STATES the right its reader must hold: the type object\'s own edit_others cap, never the literal edit_others_posts' );

$rows = \SignalNoise\OpenStationApp\attention_health();
ok( 1 === count( $rows['rows'] ) && 'Broken links' === $rows['rows'][0]['title'] && 'danger' === $rows['rows'][0]['tone'], 'health: one row per FLAGGED check, titled with the check\'s own label' );
ok( '3 findings. Fix them.' === $rows['rows'][0]['subtitle'] && '2026-09-06 10:00:00' === $rows['rows'][0]['stamp'], '   ...subtitled with the count and the check\'s fix hint, stamped with the SCAN -- the queue reads scanned_at and never runs a scan' );
$GLOBALS['__scan']['flagged']['broken_links']['count'] = 1;
$rows = \SignalNoise\OpenStationApp\attention_health();
ok( '1 finding. Fix them.' === $rows['rows'][0]['subtitle'], '   ...and ONE finding reads as one, hint and all: the branch that carries a hint is pluralised too, not only the bare one' );
t_fixtures();

$rows = \SignalNoise\OpenStationApp\attention_watches();
ok( 1 === count( $rows['rows'] ) && 'origin 503 recheck' === $rows['rows'][0]['title'] && '19 origin 503s in the last day' === $rows['rows'][0]['subtitle'], 'watches: a ripe watch is a row, carrying the evidence its own callback wrote' );
ok( $now === $GLOBALS['__watches_now'], '   ...and the clock is THREADED into snt_watches_ripe(), never re-read inside it' );
ok( false !== strpos( $rows['rows'][0]['source'], 'sn-status{uptime}' ), '   ...its `read` string is its door: a watch has no leaf' );

$rows = \SignalNoise\OpenStationApp\attention_readers();
ok( 1 === count( $rows['rows'] ) && 'warning' === $rows['rows'][0]['tone'] && '2026-09-06 04:00:00' === $rows['rows'][0]['stamp'], 'readers: a snapshot older than six hours is one warning row, stamped with its capture' );
$GLOBALS['__snapshot'] = array( 'captured_at' => $now - 60, 'total' => 40 );
ok( array() === \SignalNoise\OpenStationApp\attention_readers()['rows'], '   ...a fresh snapshot is no row' );
$GLOBALS['__snapshot'] = array( 'captured_at' => null );
ok( array() === \SignalNoise\OpenStationApp\attention_readers()['rows'], '   ...and a snapshot that was NEVER measured is no row either: absent is not stale, and null must never be read as a verdict' );
$GLOBALS['__snapshot'] = array( 'captured_at' => $now - 8 * 3600, 'total' => 40 );

echo "\nGroup 2: a reader that cannot answer says so -- never a zero\n";
$GLOBALS['__citations_throws'] = true;
$items = t_items();
$row   = t_item( $items, 'a-citations-unreadable' );
ok( is_array( $row ) && 'Citations could not be read.' === $row['subtitle'] && 'warning' === $row['badge']['tone'], 'a reader that THROWS yields exactly one warning row that names the signal' );
ok( null === t_item( $items, 'a-citations-never-checked' ) && null === t_item( $items, 'a-citations-due' ), '   ...and none of its rows: a half-read signal is not reported as a reading' );
$GLOBALS['__citations_throws'] = false;

$GLOBALS['__scan'] = null;
$row = t_item( t_items(), 'a-health-unreadable' );
ok( is_array( $row ) && 'Health could not be read.' === $row['subtitle'], 'a reader that returns NULL yields the same row: never scanned is not "nothing wrong"' );
$GLOBALS['__scan'] = array( 'scanned_at' => $now - 7200, 'flagged' => array( 'broken_links' => array( 'count' => 3, 'label' => 'Broken links', 'fix_hint' => 'Fix them.', 'skipped' => null ) ) );

$GLOBALS['__integrity'] = 'not an array at all';
$row = t_item( t_items(), 'a-integrity-unreadable' );
ok( is_array( $row ) && 'not stamped' === $row['dateLabel'] && '' === $row['date'], 'an unreadable row carries NO stamp and SAYS "not stamped": a warning row must not borrow a date it never read' );
t_fixtures();

echo "\nGroup 3: the composition -- order, ids, the cap\n";
$items = t_items();
ok(
	array(
		'a-integrity-11',
		'a-health-broken_links',
		'a-citations-never-checked',
		'a-watches-origin_503_recheck',
		'a-edge-23',
		'a-anchors-23-v4',
		'a-readers-snapshot',
		'a-citations-due',
		'a-pending-post',
		'a-schedule-fragment-7',
		'a-schedule-fragment-8',
		'a-schedule-post-51',
		'a-anchors-31-v2',
	) === array_column( $items, 'id' ),
	'danger, then warning, then neutral; inside a tone the newest stamp first, ties broken by kind then key -- one queue out of nine readers, in one order'
);
ok( 'a-' === substr( $items[0]['id'], 0, 2 ) && 13 === count( array_unique( array_column( $items, 'id' ) ) ), 'every id is a-<kind>-<key>, and all thirteen are distinct' );

// The stamp comparison is the half most easily got backwards: '' sorts FIRST
// in strcmp, which would put every unstamped row at the head of its tone.
$GLOBALS['__watches'][] = array( 'id' => 'no_stamp', 'label' => 'A watch with no reading', 'read' => '', 'note' => '' );
$GLOBALS['__watches'][0]['id'] = 'origin_503_recheck';
$ids = array_column( t_items(), 'id' );
ok( array_search( 'a-watches-origin_503_recheck', $ids, true ) < array_search( 'a-edge-23', $ids, true ), 'a stamped warning row still outranks an older stamped one' );
t_fixtures();

$notes = array();
for ( $i = 1; $i <= 500; $i++ ) {
	$notes[ 1000 + $i ] = array( 'uid' => 'sn:note:' . $i, 'title' => 'Note ' . $i, 'version' => 1, 'last_checked' => $now - $i, 'failures' => array( 'hash_mismatch' ) );
}
$GLOBALS['__integrity']['notes'] = $notes;
ok( (int) SN_OS_APP_ITEM_CAP === count( t_items() ), 'the queue is capped at SN_OS_APP_ITEM_CAP, after the ordering: a cap that sliced an unordered list would drop whichever rows landed late' );
t_fixtures();

echo "\nGroup 4: the sixty-second cache\n";
delete_transient( 'snt_os_attention' );
$GLOBALS['__reads'] = array();
$first = \SignalNoise\OpenStationApp\attention_items();
ok( 1 === ( $GLOBALS['__reads']['health'] ?? 0 ) && 13 === count( $first ), 'the first call runs the readers once' );
$second = \SignalNoise\OpenStationApp\attention_items();
ok( 1 === ( $GLOBALS['__reads']['health'] ?? 0 ) && count( $first ) === count( $second ), 'a second call inside the window reads the transient: the readers do NOT run again' );
ok( (int) SN_OS_APP_ITEM_CAP >= \SignalNoise\OpenStationApp\attention_count() && 13 === \SignalNoise\OpenStationApp\attention_count() && 1 === ( $GLOBALS['__reads']['health'] ?? 0 ), 'count() reads the SAME cache items() reads -- the folder tile and the list cannot disagree, and the tile costs nothing' );
$cached = get_transient( 'snt_os_attention' );
ok( is_array( $cached ) && isset( $cached['rows'], $cached['read_at'] ) && $now === (int) $cached['read_at'], 'the transient holds the composed rows AND the read_at that dates them' );

$GLOBALS['__now'] = $now + 61;
\SignalNoise\OpenStationApp\attention_items();
ok( 2 === ( $GLOBALS['__reads']['health'] ?? 0 ), 'a call after the window recomputes' );
$GLOBALS['__now'] = $now;

// A cache whose read_at has aged out but whose transient has NOT expired: the
// composition's own age check is a second mechanism, and this is the only
// scenario that can tell the two apart.
set_transient( 'snt_os_attention', array( 'rows' => array( array( 'kind' => 'health', 'key' => 'stale-cache', 'title' => 'From an old composition' ) ), 'read_at' => $now - 3600, 'stamp' => '' ), 9999 );
$GLOBALS['__reads'] = array();
$items = \SignalNoise\OpenStationApp\attention_items();
ok( 13 === count( $items ) && 1 === ( $GLOBALS['__reads']['health'] ?? 0 ), 'a cache older than the window is recomputed even when the transient itself has not expired: read_at is the age of the READING, not of the row' );
set_transient( 'snt_os_attention', array( 'rows' => array(), 'read_at' => $now + 4000, 'stamp' => '' ), 9999 );
$GLOBALS['__reads'] = array();
ok( 13 === \SignalNoise\OpenStationApp\attention_count(), 'a read_at in the FUTURE is a clock that moved, not a fresh read: recomputed' );

echo "\nGroup 5: an item as the client paints it\n";
$items = t_items();
$one   = t_item( $items, 'a-integrity-11' );
ok( 'integrity' === $one['status'] && 'Integrity' === $one['statusLabel'], 'the status IS the kind -- the only axis a mixed queue has, and what the pills filter on' );
ok( 'as of 2026-09-06 11:00:00 UTC' === $one['dateLabel'] && '2026-09-06 11:00:00' === $one['date'], 'the date label says AS OF, and names the instant in UTC: a row that cannot say when it was measured is not a reading' );
ok( array( 'text' => 'Integrity', 'tone' => 'danger', 'title' => 'as of 2026-09-06 11:00:00 UTC' ) === $one['badge'], 'the badge carries the kind, the severity tone and the stamp' );
ok( array( 'fact', 'stamp' ) === array_keys( $one['columns'] ) && '2026-09-06 11:00:00 UTC' === $one['columns']['stamp'], 'the two list columns are what statusLabel and dateLabel do NOT already carry' );
ok( array( 'Subject', 'What', 'When', 'Source' ) === array_column( $one['detail']['facts'], 0 ), 'the four facts a queue row knows about itself' );
ok( array() === $one['detail']['blocks'] && '' === $one['detail']['hero'], 'no blocks, no hero: a queue row is a sentence and a date' );

$labels = array_column( $one['detail']['actions'], 'label' );
ok( array( 'Open Trust checks in S&N Dashboard', 'Open the note' ) === $labels, 'a row that names a post offers its leaf AND the note' );
ok( 'jump' === $one['detail']['actions'][1]['dispatch'] && array( 'section' => 'notes', 'item' => '11' ) === $one['detail']['actions'][1]['args'], '   ...through the jump dispatch, carrying the section and the item together' );
ok( false !== strpos( $one['detail']['actions'][0]['url'], 'page=sn-tools&sub=trust' ), '   ...and the door is resolved through the dock registry, never a literal query string' );
$page_jump = t_item( $items, 'a-anchors-31-v2' )['detail']['actions'][1];
ok( array( 'section' => 'pages', 'item' => '31' ) === $page_jump['args'] && 'Open the page' === $page_jump['label'], 'a SIGNED PAGE jumps to Pages and says "Open the page": both the section and the WORD come from what lists the post, not from the presence of a post id' );
ok( false !== strpos( t_item( $items, 'a-health-broken_links' )['detail']['actions'][0]['url'], 'sub=health' ), 'the Health door names its leaf explicitly: sn-monitoring opens on Analytics, so the bare tab URL lands one leaf short' );
ok( 1 === count( t_item( $items, 'a-citations-never-checked' )['detail']['actions'] ), 'a row that names no post offers the door alone' );
ok( array( 'section' => 'notes', 'item' => '' ) === t_item( $items, 'a-pending-post' )['detail']['actions'][0]['args'], 'the pending-review row jumps to the SECTION with no item: it counts posts, it does not name one' );

$GLOBALS['__caps']['edit_pages'] = false;
ok( 1 === count( t_item( t_items(), 'a-anchors-31-v2' )['detail']['actions'] ), 'no jump is offered into a section this user is not offered: the registry re-checks the capability on every call' );
t_fixtures();

echo "\nGroup 5a: a jump is offered only into a section that LISTS the post\n";
// The post TYPE names a candidate section; only the section's own query can
// say whether it holds this post. 23 is a `post` in the probe log that is NOT
// in the note category, so Notes lists it nowhere and "Open the note" would
// land on nothing.
$items = t_items();
ok( array( 'Open Cloudflare in S&N Dashboard' ) === array_column( t_item( $items, 'a-edge-23' )['detail']['actions'], 'label' ), 'a post OUTSIDE the note category offers its leaf and NO jump: Notes lists the category, not the post type' );
ok( 'Open the note' === t_item( $items, 'a-integrity-11' )['detail']['actions'][1]['label'], '   ...while a post that IS in the category still jumps, and says note' );
$GLOBALS['__posts'][32] = (object) array( 'ID' => 32, 'post_type' => 'page', 'post_title' => 'Colophon', 'post_status' => 'pending', 'post_author' => 5 );
$unsigned = \SignalNoise\OpenStationApp\attention_item( \SignalNoise\OpenStationApp\attention_row( array( 'kind' => 'anchors', 'key' => '32-v1', 'title' => 'Colophon', 'post_id' => 32 ) ) );
ok( array() === $unsigned['detail']['actions'], '   ...and a page that never opted into signing is on no list either: Pages lists the opt-in meta, so no jump is offered for it' );
// A row that PRE-SETS its section must pass the same two gates -- offered, and
// containing the post. It is the only kind of row that never went through
// attention_section_for_post(), so it was the one never checked.
$preset = \SignalNoise\OpenStationApp\attention_item( \SignalNoise\OpenStationApp\attention_row( array( 'kind' => 'anchors', 'key' => 'preset', 'title' => 'The ban failed', 'post_id' => 23, 'section' => 'notes' ) ) );
ok( array() === $preset['detail']['actions'], 'a row that names its OWN section is gated too: Notes is offered, but it does not list post 23, so there is no jump' );
$GLOBALS['__caps']['edit_posts'] = false;
ok( 0 === count( t_item( t_items(), 'a-pending-post' )['detail']['actions'] ), '   ...and a pre-set section this user is not offered at all yields no jump: the gate is the same one a resolved section passes' );
$GLOBALS['__caps']['edit_posts'] = true;
unset( $GLOBALS['__posts'][32] );
t_fixtures();

echo "\nGroup 5b: the registry is resolved ONCE per paint, not once per row\n";
// Resolving a section is a full registry pass -- every descriptor, a
// current_user_can() per section, a uasort. Asking per ROW made the cost of
// the queue the number of rows that name a post. Counted here by the filter
// the registry applies, which is exactly one application per resolution.
$GLOBALS['__registry_passes'] = 0;
add_filter(
	'snt_os_app_sections',
	static function ( $sections ) {
		++$GLOBALS['__registry_passes'];
		return $sections;
	}
);
delete_transient( 'snt_os_attention' );
$GLOBALS['__registry_passes'] = 0;
\SignalNoise\OpenStationApp\attention_items();
$with_post = 0;
foreach ( \SignalNoise\OpenStationApp\attention_rows()['rows'] as $row ) {
	if ( (int) ( $row['post_id'] ?? 0 ) > 0 ) {
		++$with_post;
	}
}
ok( $with_post >= 5 && $GLOBALS['__registry_passes'] <= 2, 'one attention_items() call over ' . $with_post . ' post-bearing rows resolves the registry at most twice (' . (int) $GLOBALS['__registry_passes'] . '): the offered sections are threaded down, not re-asked per row' );
t_fixtures();

echo "\nGroup 5c: the cache is site-wide; the CAPABILITY is per reader\n";
delete_transient( 'snt_os_attention' );
$GLOBALS['__caps']['edit_others_pages'] = false;
$ids_without    = array_column( \SignalNoise\OpenStationApp\attention_items(), 'id' );
$count_without  = \SignalNoise\OpenStationApp\attention_count();
$cached_without = get_transient( 'snt_os_attention' );
// The SAME transient, inside its window, read by a user who holds the right.
$GLOBALS['__caps']['edit_others_pages'] = true;
$ids_with    = array_column( \SignalNoise\OpenStationApp\attention_items(), 'id' );
$count_with  = \SignalNoise\OpenStationApp\attention_count();
$cached_with = get_transient( 'snt_os_attention' );
ok( ! in_array( 'a-pending-page', $ids_without, true ) && count( $ids_without ) === $count_without, 'a reader without edit_others_pages sees no pending-pages row, and the tile\'s count agrees with the list' );
ok( in_array( 'a-pending-page', $ids_with, true ) && count( $ids_with ) === $count_with && $count_with === $count_without + 1, '   ...a reader who holds it sees exactly that one row more, and its count agrees too' );
ok( $cached_without === $cached_with && is_array( $cached_with ) && in_array( 'page', array_column( $cached_with['rows'], 'key' ), true ), '   ...out of ONE identical cached composition, which holds the row either way: whoever fills the cache decides nothing for whoever reads it' );
t_fixtures();

echo "\nGroup 6: the empty queue\n";
$GLOBALS['__integrity']['notes'] = array();
$GLOBALS['__anchors']           = array();
$GLOBALS['__probe_log']         = array();
$GLOBALS['__cit_counts']        = array( 'verified' => 4, 'never_checked' => 0 );
$GLOBALS['__cit_due']           = array();
$GLOBALS['__schedule']          = array();
$GLOBALS['__pending']           = array( 'post' => 0, 'page' => 0 );
$GLOBALS['__scan']              = array( 'scanned_at' => $now - 7200, 'flagged' => array() );
$GLOBALS['__watches']           = array();
$GLOBALS['__snapshot']          = array( 'captured_at' => $now - 60 );
delete_transient( 'snt_os_attention' );
ok( 0 === \SignalNoise\OpenStationApp\attention_count() && array() === \SignalNoise\OpenStationApp\attention_items(), 'nine readers that all measured nothing produce no rows -- and no warning rows either' );
$note = \SignalNoise\OpenStationApp\attention_empty_note();
ok( 'The newest reading is from 2026-09-06 12:00:00 UTC. Composed 2026-09-06 12:00:00 UTC.' === $note, 'the empty state says WHEN its readers last looked: an empty queue with no date cannot be told from a queue nobody read' );
$GLOBALS['__scan'] = null;
delete_transient( 'snt_os_attention' );
ok( 1 === \SignalNoise\OpenStationApp\attention_count(), '   ...and an empty queue with ONE unreadable signal is not empty: the warning row is the reading' );
t_fixtures();

echo "\nGroup 7: the descriptor, read back through the registry\n";
$section = null;
foreach ( snt_os_app_sections() as $s ) {
	if ( 'attention' === $s['id'] ) {
		$section = $s;
	}
}
ok( is_array( $section ) && 'attention' === $section['id'] && 'Attention' === $section['label'] && 'dashicons-flag' === $section['icon'], 'the section registers as attention / Attention / dashicons-flag' );
ok( 'entry' === $section['kind'] && 5 === (int) $section['position'] && 'manage_options' === $section['capability'], 'kind entry, position 5 (first at the root, which is what the phone opens on), manage_options' );
ok( ! isset( $section['restPath'] ) && ! isset( $section['edit_url'] ) && ! isset( $section['hasDossier'] ), 'READ-ONLY BY ABSENCE: no restPath, no edit_url, no hasDossier -- the client\'s three opt-ins, all declined, so no drag, no editor, no dossier fetch' );
ok( array_keys( \SignalNoise\OpenStationApp\attention_kinds() ) === array_column( $section['statuses'], 'value' ), 'the pills ARE the kinds, from the one list a row\'s statusLabel also reads' );
ok( array( 'fact', 'stamp' ) === array_column( $section['columns'], 'key' ) && array( 'What', 'Measured' ) === array_column( $section['columns'], 'label' ), 'the list columns are fact and stamp' );
ok( array_column( $section['columns'], 'key' ) === array_keys( t_item( t_items(), 'a-integrity-11' )['columns'] ), '   ...and the descriptor\'s list and the item\'s are the SAME list, in the same order' );
ok( 'Nothing needs you' === $section['empty_heading'], 'the empty heading is declared on the descriptor: the client\'s generic "Nothing here yet." would read as a section that never filled' );
ok( is_callable( $section['empty_note'] ) && is_callable( $section['count'] ) && is_callable( $section['items'] ), 'empty_note, count and items are callables -- the note is composed at PAINT time, after the capability gate, never at registration' );
$GLOBALS['__caps']['manage_options'] = false;
ok( ! in_array( 'attention', array_column( snt_os_app_sections(), 'id' ), true ), 'without manage_options the section is not offered at all' );
$GLOBALS['__caps']['manage_options'] = true;

echo "\nGroup 8: the seam between the two halves\n";
// The readers are the only half that calls the estate. Stated as a rule it is
// a habit; measured, it is a property -- and the measurement is the reason the
// file was split rather than merely made shorter.
$composition = (string) file_get_contents( __DIR__ . '/../apps/signal-noise/parts/attention.php' );
$readers     = (string) file_get_contents( __DIR__ . '/../apps/signal-noise/parts/attention-readers.php' );
$called      = array();
foreach ( array_keys( \SignalNoise\OpenStationApp\attention_kinds() ) as $kind ) {
	if ( false !== strpos( $readers, 'function attention_' . $kind . '(' ) ) {
		$called[] = $kind;
	}
}
ok( array_keys( \SignalNoise\OpenStationApp\attention_kinds() ) === $called, 'every kind has a reader, and every reader lives in parts/attention-readers.php: the registry and the file cannot drift apart' );
// THE BACKSLASH IS OPTIONAL. Requiring it meant an UNQUALIFIED call --
// `snt_watches_ripe()` written inside the namespaced file, which PHP resolves
// to the global function all the same -- passed green, so the pin measured
// the leading backslash rather than the seam. Comments and docblocks are
// stripped first (token_get_all is exact, where a regex over prose is not):
// this file's own docblocks name estate readers, and a pin that a sentence
// can turn red is a pin nobody keeps.
$stripped = '';
foreach ( token_get_all( $composition ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$stripped .= is_array( $token ) ? $token[1] : $token;
}
$seam = '/(?<![A-Za-z0-9_\\$>])\\\\?(sn_|snt_)(?!os_app_section\b|desktop_admin_url\b)[a-z0-9_]+\s*\(/';
ok( 0 === preg_match( $seam, $stripped ), 'the composition half calls NO estate reader, qualified or not -- only the section registry and the admin-dock resolver, which are the app\'s own furniture' );
ok( false === strpos( $composition, 'function attention_integrity' ) && false === strpos( $readers, 'function attention_compose' ), 'and neither half holds the other\'s functions' );

echo "\nGroup 9: the fleet-level key verdict is a queue row, filed under the sweep\n";
$GLOBALS['__integrity_throws'] = false;
$GLOBALS['__integrity'] = array( 'notes' => array(), 'last_sweep' => array( 'swept_at' => $now - 600, 'fleet' => 12, 'checked' => 10, 'keys' => 'key_mismatch' ) );
$r = \SignalNoise\OpenStationApp\attention_integrity();
$keyrow = null; foreach ( $r['rows'] as $row ) { if ( 'keys' === ( $row['key'] ?? '' ) ) { $keyrow = $row; } }
ok( is_array( $keyrow ) && 'danger' === $keyrow['tone'] && false !== strpos( $keyrow['subtitle'], '(key mismatch)' ) && 0 === (int) $keyrow['post_id'], 'key_mismatch on the last sweep is a danger row about the ledger\'s key file, with no note to open' );
$GLOBALS['__integrity']['last_sweep']['keys'] = 'keys_unreachable';
$r = \SignalNoise\OpenStationApp\attention_integrity(); $keyrow = null; foreach ( $r['rows'] as $row ) { if ( 'keys' === ( $row['key'] ?? '' ) ) { $keyrow = $row; } }
ok( is_array( $keyrow ) && 'warning' === $keyrow['tone'] && false !== strpos( $keyrow['subtitle'], 'an outage, not drift' ), 'keys_unreachable is a warning: an outage, said as one' );
$GLOBALS['__integrity']['last_sweep']['keys'] = 'ok';
$r = \SignalNoise\OpenStationApp\attention_integrity(); $keyrow = null; foreach ( $r['rows'] as $row ) { if ( 'keys' === ( $row['key'] ?? '' ) ) { $keyrow = $row; } }
ok( null === $keyrow, 'an ok verdict makes no row' );
$src_findings = file_get_contents( __DIR__ . '/../inc/provenance-integrity.php' );
$src_reader   = file_get_contents( __DIR__ . '/../apps/signal-noise/parts/attention-readers.php' );
$parity = true;
// EACH PHRASE IS UNIQUE TO ITS FINDING, and counted rather than searched.
// 'has 404ed for three consecutive sweeps' occurs TWICE in the source (the
// twin_missing sentence says it too), so that leg could not fail: deleting the
// keys_missing sentence would still have found the twin's copy. The keys
// phrase below appears exactly once on each side, which is also what makes a
// silent duplication of either sentence visible.
foreach ( array( 'no longer serves the published key id with the published key bytes (key mismatch)', 'the key file is absent from the ledger, not blipping', 'could not be reached (unreachable: an outage, not drift, not a key rotation)' ) as $phrase ) {
	if ( 1 !== substr_count( $src_findings, $phrase ) || 1 !== substr_count( $src_reader, $phrase ) ) { $parity = false; }
}
ok( $parity, 'the three fleet sentences are the findings\' own, word for word, each phrase unique to its finding -- the queue says what sn_prov_integrity_findings() says' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
