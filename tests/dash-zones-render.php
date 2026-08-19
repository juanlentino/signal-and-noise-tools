<?php
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
$GLOBALS['__grid_calls'] = 0;
function sn_admin_glance_grid( array $cards ) { $GLOBALS['__grid_calls']++; echo '<div class="sn-glance">' . count( $cards ) . '</div>'; }
function sn_admin_glance_sort_by_attention( array $cards ) { return $cards; }

require __DIR__ . '/../inc/dash-zones.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function render( $zone, $pins = array() ) { ob_start(); sn_dash_render_zone( $zone, $pins ); return ob_get_clean(); }
echo "dashboard zones — renderer\n\n";

$card = array( 'label' => 'Health', 'value' => '0' );
$ok_zone = array( 'id' => 'attention', 'state' => 'ok', 'summary' => 'Nothing needs attention', 'detail' => 'health, cron', 'cards' => array( $card ) );

$h = render( $ok_zone );
ok( false !== strpos( $h, '<details' ), 'a zone renders as a details element' );
ok( false === strpos( $h, 'open' ), 'an ok zone is not open' );
ok( false !== strpos( $h, 'Nothing needs attention' ), 'the summary line shows' );
ok( false !== strpos( $h, 'health, cron' ), 'the detail continuation shows' );
ok( false !== strpos( $h, 'sn-dash-zone--ok' ), 'the state is a class hook' );

$att = array( 'id' => 'attention', 'state' => 'attention', 'summary' => '2 need attention', 'detail' => '', 'cards' => array( $card, $card ) );
$h = render( $att );
ok( false !== strpos( $h, 'open' ), 'an attention zone renders open' );
ok( false !== strpos( $h, 'sn-dash-zone--attention' ), 'attention state class' );

$unk = array( 'id' => 'fleet', 'state' => 'unknown', 'summary' => 'Fleet not measured', 'detail' => '2 of 7 never probed', 'cards' => array() );
$h = render( $unk );
ok( false !== strpos( $h, 'sn-dash-zone--unknown' ), 'unknown gets its own class, distinct from ok' );
ok( false === strpos( $h, 'sn-dash-zone--ok' ), 'unknown is NOT styled as ok' );

// The grid helper is reused, not reimplemented.
$GLOBALS['__grid_calls'] = 0;
render( $att, array( 'attention' ) );
ok( $GLOBALS['__grid_calls'] === 1, 'an expanded zone delegates its tiles to sn_admin_glance_grid()' );
$GLOBALS['__grid_calls'] = 0;
render( $ok_zone );
ok( $GLOBALS['__grid_calls'] === 0, 'a collapsed zone does not build a grid it will not show' );

// ── folded body content ─────────────────────────────────────────────────────
// The fleet zone folds the Recent deploys list inside itself, so a zone may
// carry pre-rendered HTML alongside its cards. It is TRUSTED markup built by
// the tab, not user input — but it must only appear when the zone is open.
$folded = array( 'id' => 'fleet', 'state' => 'ok', 'summary' => 'Fleet current', 'detail' => '',
	'cards' => array( $card ), 'body_html' => '<ul class="sn-deploy-list"><li>a deploy</li></ul>' );
ok( false === strpos( render( $folded ), 'sn-deploy-list' ), 'a COLLAPSED zone does not emit its folded body' );
$h = render( $folded, array( 'fleet' ) );
ok( false !== strpos( $h, 'sn-deploy-list' ), 'an expanded zone emits its folded body' );
ok( strpos( $h, 'sn-deploy-list' ) > strpos( $h, '</summary>' ), 'and the folded body sits inside the details, after the summary' );

// A zone with folded content but NO cards still opens a body for it.
$only = array( 'id' => 'fleet', 'state' => 'ok', 'summary' => 's', 'detail' => '',
	'cards' => array(), 'body_html' => '<p class="lonely">no runs</p>' );
ok( false !== strpos( render( $only, array( 'fleet' ) ), 'lonely' ), 'folded content renders even when the zone has no cards' );

// Escaping.
$evil = array( 'id' => 'x', 'state' => 'ok', 'summary' => '<script>alert(1)</script>', 'detail' => '', 'cards' => array() );
ok( false === strpos( render( $evil ), '<script>' ), 'the summary is escaped' );

// The detail line carries probe output (worker names, error text), not just prose.
$evil_detail = array( 'id' => 'x', 'state' => 'ok', 'summary' => 'fine', 'detail' => '<script>alert(2)</script>', 'cards' => array() );
ok( false === strpos( render( $evil_detail ), '<script>' ), 'the detail is escaped too' );

// The id lands in an attribute. Hardcoded today; the renderer must not depend on that.
$evil_id = array( 'id' => 'x" onmouseover="alert(3)', 'state' => 'ok', 'summary' => 'fine', 'detail' => '', 'cards' => array() );
ok( false === strpos( render( $evil_id ), 'onmouseover="' ), 'a hostile zone id cannot break out of the data-zone attribute' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
