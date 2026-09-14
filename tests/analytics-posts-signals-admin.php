<?php
/**
 * Standalone test: the Posts tab RENDER layer (inc/analytics-posts-signals-admin.php).
 *
 * Drives the renderer with hand-built signal rows and pins the four rules the
 * screen exists for: every column sortable and no title truncated; a gap is a
 * muted reason, never a zero or a glyph; the queue holds flagged rows only,
 * most urgent first, with labels that are states not verbs; and machine reads
 * appear once, site-wide, labelled as such. Plus the copy rule: no em dashes.
 *
 * Run: php tests/analytics-posts-signals-admin.php
 *
 * @since 14.6.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
const SN_POSTS_LIFECYCLE_MAX = 200;
function __( $s, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ), '-' ) ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }

require_once __DIR__ . '/../inc/analytics-panels.php';
require_once __DIR__ . '/../inc/analytics-posts-signals.php';
require_once __DIR__ . '/../inc/analytics-posts-signals-admin.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function cap( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }
function f( $v, $why = '' ) { return sn_posts_field( $v, $why ); }
function row( $id, $title, array $over = array() ) {
	$r = array_merge( array(
		'id' => $id, 'title' => $title, 'permalink' => "/notes/$id/", 'publish_ts' => 1000, 'modified_ts' => 2000,
		'age' => f( 68 ), 'words' => f( 793 ), 'index' => f( true ), 'coverage_state' => 'Submitted and indexed', 'last_crawl' => f( 3000 ),
		'impressions' => f( 187 ), 'clicks' => f( 0 ), 'position' => f( 4.0 ), 'inbound' => f( 10 ),
		'anchor' => f( array( 'version' => 1, 'block' => 964812, 'followed_key' => true ) ), 'related' => f( array( 'count' => 3, 'top' => 0.81 ) ), 'views' => f( 14 ),
	), $over );
	$r['flags'] = sn_analytics_posts_flags( $r );
	return $r;
}
$rows = array(
	row( 1, 'A very long title that must never be cut: Trust doesn\'t disappear, it relocates' ),
	row( 2, 'Discovered one', array( 'index' => f( false ), 'coverage_state' => 'Discovered - currently not indexed', 'last_crawl' => f( null, 'never crawled' ), 'inbound' => f( 1 ) ) ),
	row( 3, 'Stale one', array( 'last_crawl' => f( 1500 ), 'impressions' => f( null, 'not shown by Google in this window' ), 'clicks' => f( null, 'not shown by Google in this window' ), 'position' => f( null, 'not shown by Google in this window' ) ) ),
	row( 4, 'Crawled one', array( 'index' => f( false ), 'coverage_state' => 'Crawled - currently not indexed', 'anchor' => f( null, 'unsigned' ), 'related' => f( null, 'not in the kernel yet: built before this note' ) ) ),
	row( 5, 'Uninspected <b>hostile</b>', array( 'index' => f( null, 'not inspected in the last run' ), 'coverage_state' => '', 'last_crawl' => f( null, 'not inspected in the last run' ), 'views' => f( 0 ) ) ),
);
$strip = array( 'machine_reads' => f( 71627 ), 'machine_reads_days' => 30, 'machine_reads_stale' => false, 'gsc_window' => array( 'start' => '2026-08-14', 'end' => '2026-09-10' ), 'coverage_run' => array( 'finished_at' => 1788903869, 'inspected' => 40, 'errors' => 0 ) );
$signals = array( 'rows' => $rows, 'counts' => sn_analytics_posts_counts( $rows ), 'strip' => $strip );
$html = cap( function () use ( $signals ) { snt_analytics_render_posts_signals_view( $signals ); } );

echo "Group 1: copy rules\n";
ok( false === strpos( $html, "\u{2014}" ) && false === strpos( $html, '&mdash;' ), 'NO EM DASH anywhere in the rendered tab' );
ok( false === strpos( $html, 'Refresh' ) && false === strpos( $html, 'sustained' ) && false === strpos( $html, 'Evergreen' ) && false === strpos( $html, 'spike' ), 'no shape vocabulary and no imperative pill: Refresh / Evergreen / sustained / spike are gone' );

echo "\nGroup 2: the header strip\n";
ok( 1 === substr_count( $html, '71,627' ), 'machine reads appear exactly ONCE' );
ok( false !== strpos( $html, 'Machine reads, site-wide, 30 days' ) && false !== strpos( $html, 'not counted per note' ), '...labelled site-wide, with the reason they are not per note' );
ok( 1 === substr_count( $html, '<th' ) - substr_count( $html, 'Search Console, 2026-08-14 to 2026-09-10' ) || substr_count( $html, 'Search Console, 2026-08-14 to 2026-09-10' ) >= 1, 'the window is labelled on the table' );
ok( false !== strpos( $html, '2026-09-08' ) && false !== strpos( $html, '40 URLs inspected, 0 errors' ), 'the coverage run date and its size ride the strip' );

echo "\nGroup 3: counts\n";
ok( false !== strpos( $html, '<p class="sn-kpi-label">Notes</p><p class="sn-kpi-value">5</p>' ) && false !== strpos( $html, '<p class="sn-kpi-label">Indexed</p><p class="sn-kpi-value">2</p>' ), 'Notes 5, Indexed 2' );
ok( false !== strpos( $html, '1 not inspected' ), 'the uninspected count rides under Indexed' );
ok( false !== strpos( $html, '<p class="sn-kpi-label">Stale crawls</p><p class="sn-kpi-value">1</p>' ) && false !== strpos( $html, '<p class="sn-kpi-label">Zero inbound links</p><p class="sn-kpi-value">0</p>' ), 'Stale crawls 1, Zero inbound links 0' );

echo "\nGroup 4: the queue\n";
$q = substr( $html, strpos( $html, '<span>Queue</span>' ), strpos( $html, '<span>Show all notes</span>' ) - strpos( $html, '<span>Queue</span>' ) );
ok( false !== strpos( $q, '3 of 5 notes carry a flag' ), 'the queue names its size against the total' );
ok( 3 === substr_count( $q, '<tr><td class="column-primary">' ), 'ONLY flagged notes are queued (3 of 5)' );
$order = array_map( static function ( $m ) { return $m; }, array_slice( preg_split( '/<tr><td class="column-primary">/', $q ), 1 ) );
ok( false !== strpos( $order[0], 'Discovered one' ) && false !== strpos( $order[1], 'Crawled one' ) && false !== strpos( $order[2], 'Stale one' ), 'most urgent first: not indexed + orphaned, not indexed, then stale' );
ok( false !== strpos( $order[0], 'has not crawled it' ) && false !== strpos( $order[1], 'chose not to index it' ), 'Discovered and Crawled get DIFFERENT implied fixes' );
ok( false !== strpos( $order[0], '>Not indexed<' ) && false !== strpos( $order[0], '>Orphaned<' ) && false !== strpos( $order[2], '>Stale crawl<' ), 'flag pills are states: Not indexed / Orphaned / Stale crawl' );
ok( false === strpos( $q, 'A very long title' ), 'an unflagged note is not in the queue' );

echo "\nGroup 5: the table\n";
$t = substr( $html, strpos( $html, '<div class="snt-scroll-table"><table class="widefat striped sn-posts-table" data-sortable>' ) );
ok( false !== strpos( $html, '<div class="snt-scroll-table"><table' ), 'the wide table scrolls inside its own container (thirteen columns never scroll the page)' );
$ths = substr_count( $t, '<th ' );
ok( 13 === $ths && 13 === substr_count( $t, 'data-sort="' ), 'thirteen columns, EVERY one sortable' );
ok( 1 === preg_match( '/class="postbox sn-an-postbox sn-an-collapsed" data-sn-an-collapsible="show-all-notes"/', $html ), 'the full table sits behind "Show all notes", collapsed by default' );
ok( 5 === substr_count( $t, '<tr><td class="column-primary"' ), 'one row per note' );
ok( false !== strpos( $t, 'Trust doesn&#039;t disappear, it relocates</strong>' ), 'the full title, never truncated' );
ok( false !== strpos( $t, 'Uninspected &lt;b&gt;hostile&lt;/b&gt;' ), 'a hostile title is escaped' );
ok( false !== strpos( $t, '<span class="sn-an-muted sn-posts-gap" title="not shown by Google in this window">not shown</span>' ), 'a Search Console gap is the reason, muted, not a 0: short in the cell, full in the title' );
ok( 1 === preg_match( '/<td class="num" data-v=""><span class="sn-an-muted sn-posts-gap"[^>]*>not shown/', $t ), '...and its data-v is EMPTY so it sorts last both ways' );
ok( false !== strpos( $t, 'data-v="0">0</td>' ), 'a real 0 (clicks) renders as 0 with data-v 0: real zeros stay zeros' );
ok( false !== strpos( $t, 'title="Discovered - currently not indexed">Discovered, not indexed</span>' ), 'Index pill for Discovered, coverage_state verbatim in the title' );
ok( false !== strpos( $t, '>Indexed</span>' ) && false !== strpos( $t, 'title="not inspected in the last run">not inspected</span>' ), 'Indexed pill and the uninspected gap' );
ok( 1 === preg_match( '/<span class="sn-posts-stale" title="Edited 1970-01-01, after this crawl">1970-01-01<\/span>/', $t ), 'a stale crawl is marked and says when the edit was' );
ok( false !== strpos( $t, 'title="Anchored by the followed key">v1 at 964,812</span>' ) && false !== strpos( $t, '>unsigned</span>' ), 'Anchor: version and block, green under the followed key; unsigned is a gap' );
ok( false !== strpos( $t, 'title="top similarity 0.81">3</span>' ) && false !== strpos( $t, 'title="not in the kernel yet: built before this note">not in kernel</span>' ), 'Related: kernel count with the top score; not-in-kernel is a gap' );
ok( false !== strpos( $t, 'title="Lifetime human pageviews. A raw count, not a verdict."' ), 'Views is labelled a raw count' );

echo "\nGroup 6: the empty state and the toggle-free queue\n";
$e = cap( function () { snt_analytics_render_posts_signals_view( array( 'rows' => array() ) ); } );
ok( false !== strpos( $e, 'No published notes yet' ), 'no rows → the gate' );
$none = cap( function () use ( $strip ) { $r = array( row( 9, 'Fine' ) ); snt_analytics_render_posts_signals_view( array( 'rows' => $r, 'counts' => sn_analytics_posts_counts( $r ), 'strip' => $strip ) ); } );
ok( false !== strpos( $none, 'No note carries a flag' ), 'no flags → the queue says so instead of listing nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
