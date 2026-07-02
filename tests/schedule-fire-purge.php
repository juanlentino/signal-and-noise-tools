<?php
/**
 * Standalone fixture tests for sn_schedule_fire_purge() (inc/schedule-cache.php).
 *
 * Covers the v7.3.0 purge fixes: fire-time union of the snapshot with the host
 * post's CURRENT permalink (slug-change self-heal), and zone-purge escalation
 * for reused containers (wp_block / FSE templates) whose render surfaces are
 * unenumerable. Run: php tests/schedule-fire-purge.php
 *
 * @since plugin v7.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// ── stubs ──
$GLOBALS['__post_types']  = array(); // id => post_type
$GLOBALS['__permalinks']  = array(); // id => current permalink
$GLOBALS['__purged_urls'] = null;    // captured sn_cf_purge_urls arg
$GLOBALS['__purged_zone'] = 0;       // sn_cf_purge_everything call count
$GLOBALS['__cf_ok']       = true;
if ( ! function_exists( 'get_post_type' ) ) { function get_post_type( $id ) { return $GLOBALS['__post_types'][ (int) $id ] ?? false; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id ) { return $GLOBALS['__permalinks'][ (int) $id ] ?? ''; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'sn_cf_is_configured' ) ) { function sn_cf_is_configured() { return $GLOBALS['__cf_ok']; } }
if ( ! function_exists( 'sn_cf_purge_urls' ) ) { function sn_cf_purge_urls( $urls ) { $GLOBALS['__purged_urls'] = $urls; return true; } }
if ( ! function_exists( 'sn_cf_purge_everything' ) ) { function sn_cf_purge_everything() { $GLOBALS['__purged_zone']++; return true; } }

require __DIR__ . '/../inc/schedule-cache.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// Fragment on a normal post: snapshot ∪ current permalink, de-duplicated.
$GLOBALS['__post_types'] = array( 7 => 'post' );
$GLOBALS['__permalinks'] = array( 7 => 'https://x.test/new-slug/' );
$row = array( 'target_type' => 'fragment', 'target_ref' => '7', 'purge_urls' => '["https://x.test/old-slug/"]' );
ok( true === sn_schedule_fire_purge( $row ), 'post fragment: dispatch true' );
ok( array( 'https://x.test/old-slug/', 'https://x.test/new-slug/' ) === array_values( (array) $GLOBALS['__purged_urls'] ), 'slug change: snapshot AND current permalink purged' );

// Unchanged slug: union de-dupes to one URL.
$GLOBALS['__permalinks'] = array( 7 => 'https://x.test/old-slug/' );
sn_schedule_fire_purge( $row );
ok( array( 'https://x.test/old-slug/' ) === array_values( (array) $GLOBALS['__purged_urls'] ), 'same slug: de-duplicated to one URL' );

// wp_block host: zone purge, NOT per-URL.
$GLOBALS['__post_types']  = array( 9 => 'wp_block' );
$GLOBALS['__purged_urls'] = null; $GLOBALS['__purged_zone'] = 0;
$row2 = array( 'target_type' => 'fragment', 'target_ref' => '9', 'purge_urls' => '["https://x.test/?p=9"]' );
ok( true === sn_schedule_fire_purge( $row2 ), 'wp_block host: dispatch true' );
ok( 1 === $GLOBALS['__purged_zone'], 'wp_block host: zone purge fired' );
ok( null === $GLOBALS['__purged_urls'], 'wp_block host: per-URL purge NOT called' );

// Template-part host escalates too. (Reset the v8.0.0 per-request purge
// memo: the prior wp_block case already zone-purged, and same-request
// coalescing is exactly what the memo is FOR — each case wants isolation.)
sn_schedule_purge_memo_reset();
$GLOBALS['__post_types'] = array( 11 => 'wp_template_part' );
$GLOBALS['__purged_zone'] = 0;
sn_schedule_fire_purge( array( 'target_type' => 'fragment', 'target_ref' => '11', 'purge_urls' => '[]' ) );
ok( 1 === $GLOBALS['__purged_zone'], 'wp_template_part host: zone purge fired' );

// Escalation with CF unconfigured: false (the fire handler's retry contract).
$GLOBALS['__cf_ok'] = false; $GLOBALS['__purged_zone'] = 0;
ok( false === sn_schedule_fire_purge( $row2 ), 'unconfigured CF: escalation returns false (retry)' );
ok( 0 === $GLOBALS['__purged_zone'], 'unconfigured CF: no zone dispatch' );
$GLOBALS['__cf_ok'] = true;

// Non-fragment row: snapshot passthrough, no permalink union.
$GLOBALS['__purged_urls'] = null;
sn_schedule_fire_purge( array( 'target_type' => 'page', 'target_ref' => 'x', 'purge_urls' => '["https://x.test/a/"]' ) );
ok( array( 'https://x.test/a/' ) === array_values( (array) $GLOBALS['__purged_urls'] ), 'non-fragment: snapshot passthrough' );

// Deleted host post (get_post_type false): falls through to snapshot purge.
sn_schedule_purge_memo_reset(); // isolate from earlier same-URL dispatches.
$GLOBALS['__post_types'] = array(); $GLOBALS['__permalinks'] = array();
$GLOBALS['__purged_urls'] = null;
sn_schedule_fire_purge( $row );
ok( array( 'https://x.test/old-slug/' ) === array_values( (array) $GLOBALS['__purged_urls'] ), 'deleted host: snapshot still purged' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
