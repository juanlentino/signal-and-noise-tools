<?php
/**
 * Tests for inc/analytics-recommendations.php — the Intelligence tab's pure-rules
 * recommendation engine (slice b). Each rule reads a CACHED signal (never a live
 * scan) and returns one deep-linked card or null. Run: php tests/analytics-recommendations.php
 * @since plugin v9.3.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function apply_filters( $t, $v, ...$a ) { return $v; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }

$__pass = 0; $__fail = 0;
function r_true( $c, $m ) { global $__pass, $__fail; if ( $c ) { $__pass++; echo "  PASS: $m\n"; } else { $__fail++; echo "  FAIL: $m\n"; } }
function r_eq( $e, $a, $m ) { global $__pass, $__fail; if ( $e === $a ) { $__pass++; echo "  PASS: $m\n"; } else { $__fail++; echo "  FAIL: $m ($e vs $a)\n"; } }
function r_card( $cards, $id ) { foreach ( $cards as $c ) { if ( ( $c['id'] ?? '' ) === $id ) { return $c; } } return null; }

// Signal stubs (toggled per test).
$GLOBALS['__lifecycle'] = null;
function sn_analytics_posts_lifecycle( $limit = 0 ) { return $GLOBALS['__lifecycle']; }
$GLOBALS['__scan'] = null;
function sn_health_last_scan() { return $GLOBALS['__scan']; }
$GLOBALS['__pages'] = array();
function get_posts( $args ) { return $GLOBALS['__pages']; }
function sn_seo_resolve_singular_description( $post ) { return (string) ( $post->__desc ?? '' ); }
function get_the_title( $p ) { return is_object( $p ) ? ( $p->post_title ?? '' ) : ''; }

require __DIR__ . '/../inc/analytics-recommendations.php';

// ── Refresh rule ──
echo "\nRule: refresh candidates\n";
$GLOBALS['__lifecycle'] = array( 'summary' => array( 'refresh_candidates' => 3 ) );
$cards = sn_analytics_recommendations();
$r = r_card( $cards, 'refresh' );
r_true( is_array( $r ), 'refresh card present when candidates > 0' );
r_eq( 3, $r['count'] ?? 0, 'refresh count = 3' );
r_true( false !== strpos( $r['action_url'] ?? '', 'sn_view=posts' ), 'refresh deep-links to the Posts view' );

$GLOBALS['__lifecycle'] = array( 'summary' => array( 'refresh_candidates' => 0 ) );
r_true( null === r_card( sn_analytics_recommendations(), 'refresh' ), 'no refresh card when candidates = 0' );
$GLOBALS['__lifecycle'] = null;
r_true( null === r_card( sn_analytics_recommendations(), 'refresh' ), 'no refresh card when lifecycle null (no posts)' );

// ── Unlinked-mentions rule (reads the cached Health scan) ──
echo "\nRule: unlinked mentions (from cached scan)\n";
$GLOBALS['__scan'] = array( 'checks' => array( 'unlinked_mentions' => array( 'count' => 4 ) ) );
$u = r_card( sn_analytics_recommendations(), 'unlinked' );
r_true( is_array( $u ), 'unlinked card present when the cached scan has mentions' );
r_eq( 4, $u['count'] ?? 0, 'unlinked count read from the cached scan' );
r_true( false !== strpos( $u['action_url'] ?? '', 'tab=health' ), 'unlinked deep-links to Health' );
$GLOBALS['__scan'] = array( 'checks' => array( 'unlinked_mentions' => array( 'count' => 0 ) ) );
r_true( null === r_card( sn_analytics_recommendations(), 'unlinked' ), 'no unlinked card when count = 0' );
$GLOBALS['__scan'] = null;
r_true( null === r_card( sn_analytics_recommendations(), 'unlinked' ), 'no unlinked card when no scan has run (no live re-scan)' );

echo "\nResult: {$__pass} passed, {$__fail} failed.\n";
exit( $__fail > 0 ? 1 : 0 );
