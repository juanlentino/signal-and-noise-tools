<?php
/**
 * Tests for inc/analytics-recommendations.php — the Content-view pure-rules
 * recommendation engine. Each rule reads a CACHED signal (never a live scan) and
 * returns one deep-linked card or null. Run: php tests/analytics-recommendations.php
 * @since plugin v9.6.0
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
function sn_seo_description_for_post( $post ) { return (string) ( $post->__desc ?? '' ); }
$GLOBALS['__noindex'] = array();
function sn_post_settings_get_noindex( $id ) { return ! empty( $GLOBALS['__noindex'][ $id ] ); }
function get_the_title( $p ) { return is_object( $p ) ? ( $p->post_title ?? '' ) : ''; }
function esc_html( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function snt_an_panel_open( $title ) { echo '<div class="sn-an-panel"><span>' . $title . '</span>'; }
function snt_an_panel_close() { echo '</div>'; }

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
r_true( false !== strpos( $u['action_url'] ?? '', 'sub=health' ), 'unlinked deep-links to the current Health sub-tab' );
$GLOBALS['__scan'] = array( 'checks' => array( 'unlinked_mentions' => array( 'count' => 0 ) ) );
r_true( null === r_card( sn_analytics_recommendations(), 'unlinked' ), 'no unlinked card when count = 0' );
$GLOBALS['__scan'] = null;
r_true( null === r_card( sn_analytics_recommendations(), 'unlinked' ), 'no unlinked card when no scan has run (no live re-scan)' );

// ── SEO descriptionless-route rule ──
echo "\nRule: SEO descriptionless pages\n";
$mk = function ( $id, $title, $desc ) { $x = new stdClass(); $x->ID = $id; $x->post_title = $title; $x->__desc = $desc; return $x; };
$GLOBALS['__pages'] = array( $mk( 10, 'About', 'has desc' ), $mk( 11, 'Random', '' ), $mk( 12, 'Ghost', '' ) );
$s = r_card( sn_analytics_recommendations(), 'seo_meta' );
r_true( is_array( $s ), 'seo card present when a page resolves to empty description' );
r_eq( 2, $s['count'] ?? 0, 'counts only the descriptionless pages (11, 12)' );
// v9.23.0: the card names each descriptionless page with its own editor deep-link.
$items = $s['items'] ?? array();
r_eq( 2, count( $items ), 'one item per descriptionless page (not a single first-page button)' );
r_eq( 'Random', $items[0]['label'] ?? '', 'first item labelled by the page title' );
r_true( false !== strpos( $items[0]['url'] ?? '', 'post=11' ), 'first item deep-links to the page 11 editor' );
r_eq( 'Ghost', $items[1]['label'] ?? '', 'second item labelled by the page title' );
r_true( false !== strpos( $items[1]['url'] ?? '', 'post=12' ), 'second item deep-links to the page 12 editor' );
r_true( ! isset( $s['action_url'] ), 'no single first-page button — the per-page list replaces it' );
// Render: the panel lists each page as its own editable link.
$GLOBALS['__lifecycle'] = null; $GLOBALS['__scan'] = null;
ob_start(); snt_analytics_render_recommendations_panel(); $html = (string) ob_get_clean();
r_true( false !== strpos( $html, 'sn-an-rec-items' ), 'render: the SEO card emits an items list' );
r_true( false !== strpos( $html, '>Random</a>' ), 'render: the first page title is a link' );
r_true( false !== strpos( $html, 'post=11' ), 'render: each item links to its own page editor' );
r_true( false !== strpos( $html, '>Ghost</a>' ), 'render: the second page title is a link' );
// v9.22.2: a noindexed descriptionless page is NOT flagged — a page hidden from
// search doesn't need a summary crawlers will never use.
$GLOBALS['__pages']   = array( $mk( 11, 'Random', '' ), $mk( 12, 'Ghost', '' ), $mk( 13, 'Hidden', '' ) );
$GLOBALS['__noindex'] = array( 13 => true );
$sn = r_card( sn_analytics_recommendations(), 'seo_meta' );
r_eq( 2, $sn['count'] ?? 0, 'noindexed descriptionless page (13) is excluded from the count' );
$hidden_listed = false;
foreach ( ( $sn['items'] ?? array() ) as $it ) { if ( 'Hidden' === ( $it['label'] ?? '' ) ) { $hidden_listed = true; } }
r_true( ! $hidden_listed, 'the noindexed page is not named in the list' );
$GLOBALS['__noindex'] = array();
$GLOBALS['__pages'] = array( $mk( 10, 'About', 'has desc' ) );
r_true( null === r_card( sn_analytics_recommendations(), 'seo_meta' ), 'no seo card when every page has a description' );

// ── Cookieless: no card carries a per-person field (all three signals live) ──
echo "\nCookieless: no per-person fields in any card\n";
$GLOBALS['__lifecycle'] = array( 'summary' => array( 'refresh_candidates' => 2 ) );
$GLOBALS['__scan']      = array( 'checks' => array( 'unlinked_mentions' => array( 'count' => 1 ) ) );
$GLOBALS['__pages']     = array( $mk( 11, 'Random', '' ) );
$all = sn_analytics_recommendations();
r_eq( 3, count( $all ), 'all three rules fire when their signals are present' );
foreach ( $all as $c ) {
	r_true( ! isset( $c['visitor'] ) && ! isset( $c['ip'] ) && ! isset( $c['session'] ), 'card ' . $c['id'] . ' carries no per-person field' );
}

// ── v9.33.0: AI-reasoned recommendations behind the seam (rules stay the floor) ──
echo "\nSeam: AI-reasoned brief over the rule cards\n";
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
$GLOBALS['__ai_return'] = null; $GLOBALS['__ai_prompt'] = ''; $GLOBALS['__ai_system'] = '';
if ( ! function_exists( 'snt_ai_generate_with_constraints' ) ) {
	function snt_ai_generate_with_constraints( $prompt, $system, $max = 256, $feature = 'generic' ) {
		$GLOBALS['__ai_prompt'] = $prompt; $GLOBALS['__ai_system'] = $system; $GLOBALS['__ai_feature'] = $feature;
		return $GLOBALS['__ai_return'];
	}
}
$sig_fix = array( array( 'plain_label' => '/notes/x is decaying (-38% over the window)', 'kind' => 'trajectory', 'confidence' => 'medium' ) );
$rec = sn_analytics_recommend( $sig_fix );
r_true( 'fallback' === $rec['source'] && '' === $rec['brief'], 'recommend: AI silent → rules-only fallback (empty brief)' );
r_eq( count( sn_analytics_recommendations() ), count( $rec['cards'] ?? array() ), 'recommend: fallback passes the rule cards through verbatim' );
$GLOBALS['__ai_return'] = 'Refresh the cooling posts first; the decay signal makes them the highest-leverage fix.';
$rec2 = sn_analytics_recommend( $sig_fix );
r_true( 'ai' === $rec2['source'] && false !== strpos( $rec2['brief'], 'highest-leverage' ), 'recommend: AI path fills the priority brief' );
r_eq( json_encode( sn_analytics_recommendations() ), json_encode( $rec2['cards'] ), 'recommend: the AI NEVER alters the deep-linked cards (verbatim rules)' );
r_true( false !== strpos( $GLOBALS['__ai_prompt'], 'cooling post' ) && false !== strpos( $GLOBALS['__ai_prompt'], 'decaying' ) && false !== strpos( (string) $GLOBALS['__ai_system'], 'NEVER invent' ), 'recommend: prompt carries cards + signals; system forbids invention' );
$GLOBALS['__ai_return'] = new WP_Error();
$rec3 = sn_analytics_recommend( $sig_fix );
r_true( 'fallback' === $rec3['source'] && '' === $rec3['brief'], 'recommend: budget-cap WP_Error → rules-only fallback' );
$GLOBALS['__lifecycle'] = null; $GLOBALS['__scan'] = null; $GLOBALS['__pages'] = array();
$GLOBALS['__ai_prompt'] = 'UNTOUCHED';
$rec4 = sn_analytics_recommend( $sig_fix );
r_true( '' === $rec4['brief'] && 'UNTOUCHED' === $GLOBALS['__ai_prompt'], 'recommend: no cards → no AI call, empty brief' );

echo "\nResult: {$__pass} passed, {$__fail} failed.\n";
exit( $__fail > 0 ? 1 : 0 );
