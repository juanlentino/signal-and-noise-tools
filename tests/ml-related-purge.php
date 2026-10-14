<?php
/**
 * Standalone test: after a related-notes rebuild, the notes whose "More on
 * this" changed have their cache tags purged, in one call (2026-10-13).
 * Run: php tests/ml-related-purge.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
const SNT_ML_RELATED_META       = '_snt_ml_related';
const SNT_ML_REBUILD_HOOK       = 'snt_ml_rebuild';
const SNT_ML_REBUILD_ASYNC_HOOK = 'snt_ml_rebuild_async';
define( 'SN_EDGE_CACHE_TAG', 'sn-render' );

$GLOBALS['__hooked'] = array();
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) { $GLOBALS['__hooked'][ $hook ][ $priority ][] = $cb; return true; }
// get_posts filters by status and returns IDs with fields=ids, as core does.
function get_posts( $args ) { return array_keys( array_filter( $GLOBALS['__status'], static fn( $s ) => $s === $args['post_status'] ) ); }
// '' for an absent key: core's shape.
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__meta'][ $id ] ?? ''; }
function sn_cf_is_configured() { return $GLOBALS['__configured']; }
function sn_cf_tagged() { return true; }
function sn_cf_api_send( $endpoint, $body ) { $GLOBALS['__http'][] = $body; $GLOBALS['__last'] = $GLOBALS['__reply']; return 'ok' === $GLOBALS['__reply']; }
function sn_cf_send_last() { return $GLOBALS['__last']; }
function snt_purge_ledger_add( array $row ) { $GLOBALS['__ledger'][] = $row; }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

require_once __DIR__ . '/../inc/ml-related-purge.php';

function rows( array $ids ) { return array_map( static fn( $i ) => array( 'post_id' => $i, 'score' => 0.5 ), $ids ); }

/**
 * One rebuild on $hook: the hooks run around a "build" that writes $after.
 */
function rebuild( array $before, array $after, array $opt = array() ) {
	$opt += array( 'hook' => SNT_ML_REBUILD_ASYNC_HOOK, 'configured' => true, 'reply' => 'ok' );
	$GLOBALS['__last'] = 'failed';
	$GLOBALS['__http'] = array(); $GLOBALS['__ledger'] = array();
	$GLOBALS['__configured'] = $opt['configured']; $GLOBALS['__reply'] = $opt['reply'];
	$GLOBALS['__status'] = array_fill_keys( array_keys( $after + $before ), 'publish' );
	$GLOBALS['__meta']   = $before;
	foreach ( $GLOBALS['__hooked'][ $opt['hook'] ][5] ?? array() as $cb ) { $cb(); }
	$GLOBALS['__meta'] = $after; // The build at priority 10.
	foreach ( $GLOBALS['__hooked'][ $opt['hook'] ][20] ?? array() as $cb ) { $cb(); }
	return array( 'tags' => $GLOBALS['__http'][0]['tags'] ?? null, 'calls' => count( $GLOBALS['__http'] ), 'ledger' => $GLOBALS['__ledger'] );
}

$before = array( 1 => rows( array( 2, 3 ) ), 2 => rows( array( 1, 3 ) ), 3 => rows( array( 1, 2 ) ) );

echo "Group: wired to both rebuilds\n";
foreach ( array( SNT_ML_REBUILD_HOOK, SNT_ML_REBUILD_ASYNC_HOOK ) as $h ) {
	ok( array( 'snt_ml_related_purge_before' ) === ( $GLOBALS['__hooked'][ $h ][5] ?? null ) && array( 'snt_ml_related_purge_after' ) === ( $GLOBALS['__hooked'][ $h ][20] ?? null ), "$h reads the lists before the build (5) and purges after it (20)" );
}
ok( 1 === preg_match( "~require_once __DIR__ \. '/inc/ml-artifacts\.php';[^\n]*\nrequire_once __DIR__ \. '/inc/ml-related-purge\.php';~", (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' ) ), 'the plugin loads the module right after the artifacts' );

echo "\nGroup: a theme without per-note tags\n";
$r = rebuild( $before, array( 1 => rows( array( 3 ) ) ) + $before );
ok( array( 'sn-render' ) === $r['tags'] && 'tag' === ( $r['ledger'][0]['scope'] ?? '' ), 'a changed list with no per-note tag from the theme sends the whole tag' );

echo "\nGroup: only the notes whose list changed\n";
define( 'SN_EDGE_CACHE_LIST_TAG', 'sn-lists' );
// Conditional, so PHP does not hoist it ahead of the group without it.
if ( ! function_exists( 'sn_edge_cache_note_tag' ) ) {
	function sn_edge_cache_note_tag( $id ) { return 'sn-note-' . (int) $id; }
}
$r = rebuild( $before, array( 1 => rows( array( 3, 2 ) ), 2 => rows( array( 1, 3 ) ), 3 => rows( array( 1, 2, 4 ) ), 4 => rows( array( 3 ) ) ) );
ok( array( 'sn-note-1', 'sn-note-3', 'sn-note-4' ) === $r['tags'], 'a reordered list, a longer list and a first list are purged; an unchanged one is not' );
ok( 1 === $r['calls'], 'one call for the whole rebuild (5 tag purges a minute on the Free plan)' );
ok( array( 'related', 'note', true ) === array( $r['ledger'][0]['trigger'] ?? '', $r['ledger'][0]['scope'] ?? '', $r['ledger'][0]['edge'] ?? null ) && 1 === count( $r['ledger'] ), 'one ledger row: trigger related, scope note, edge confirmed' );
$r = rebuild( $before, $before );
ok( 0 === $r['calls'] && array() === $r['ledger'], 'a rebuild that changed no list purges nothing and records nothing' );
$r = rebuild( $before, array( 1 => rows( array( 3 ) ) ) + $before, array( 'hook' => SNT_ML_REBUILD_HOOK ) );
ok( array( 'sn-note-1' ) === $r['tags'], 'the daily rebuild purges what it changed too' );
$r = rebuild( $before, array( 1 => rows( array( 3 ) ) ) + $before, array( 'configured' => false ) );
ok( 0 === $r['calls'], 'no zone configured: nothing read, nothing sent' );
$r = rebuild( $before, array( 1 => rows( array( 3 ) ) ) + $before, array( 'reply' => 'queued' ) );
ok( 1 === count( $r['ledger'] ) && false === $r['ledger'][0]['edge'], 'a throttled purge waits for its retry: the row says edge false' );
$r = rebuild( $before, array( 1 => rows( array( 3 ) ) ) + $before, array( 'reply' => 'failed' ) );
ok( array() === $r['ledger'], 'a purge Cloudflare refused is not recorded as one' );

echo "\nGroup: over 100 notes, the whole tag\n";
$many_b = array(); $many_a = array();
for ( $i = 1; $i <= 101; $i++ ) { $many_b[ $i ] = rows( array( 1000 ) ); $many_a[ $i ] = rows( array( 1001 ) ); }
$r = rebuild( $many_b, $many_a );
ok( array( 'sn-render' ) === $r['tags'] && 'tag' === ( $r['ledger'][0]['scope'] ?? '' ), '101 changed notes: one call with the whole tag, scope tag' );
unset( $many_a[101], $many_b[101] );
ok( 100 === count( rebuild( $many_b, $many_a )['tags'] ), '100 changed notes still go one by one' );

echo "\nGroup: the lists compared\n";
ok( array( 5 ) === snt_ml_related_changed( array( 5 => array( 1 ) ), array( 5 => array( 1, 2 ) ) ), 'snt_ml_related_changed: a longer list is a change' );
ok( array() === snt_ml_related_changed( array( 5 => array( 1, 2 ) ), array( 5 => array( 1, 2 ) ) ), 'snt_ml_related_changed: the same list in the same order is none' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
