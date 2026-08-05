<?php
/**
 * Standalone test: open-and-wide Phase 4b layout (v6.46.0; leaf addresses
 * updated for the v10.46.0 Phase-3 IA regroup).
 *
 * Locks the registry + cross-cutting CSS for the final open-wide chunk:
 *   - every leaf that lays out wide (front-end, performance, rss, tags, music —
 *     now spread across Site / Measurement / Content / Connections)
 *     is marked 'wide' => true in the registry, so the dispatcher emits a bare
 *     full-width .sn-section instead of the 820px-capped .sn-fieldset card;
 *   - .sn-front-end-form .sn-fieldset is a full-width auto-fit field grid (the
 *     same treatment Identity got in Phase 4a — a lone multi-field form earns
 *     width by making its FIELDS the columns);
 *   - .sn-2col is restored to an asymmetric two-column grid at wide viewports
 *     (it was deliberately always-stacked 1fr at the old capped width).
 *
 * Run: php tests/content-tab-layout.php
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
$pass = 0; $fail = 0;
function ct_assert( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
require_once __DIR__ . '/../inc/admin-tabs-data.php';

// v10.46.0: these five leaves still lay themselves out full-width, but four of
// them no longer live on the Content tab — the Phase-3 IA sent Front-End and
// Performance to Site, Music to Connections, and RSS to Measurement. The
// contract being guarded was never "the Content tab is wide"; it was "a leaf
// whose render fn draws a two-column shell or a wide table carries 'wide' so the
// dispatcher gives it a bare .sn-section". So the addresses move and the
// contract stays, now checked wherever each leaf actually lives.
echo "Group: registry — every wide-laying-out leaf still carries 'wide' => true\n";
$by_tab = array();
foreach ( sn_admin_top_tabs() as $t ) { $by_tab[ $t['tab'] ?? '' ] = $t; }
ct_assert( isset( $by_tab['content'] ), 'content tab present in registry' );
foreach ( array(
	'site/front-end',        // auto-fit FIELD grid (the fields are the columns)
	'site/performance',      // two-column sn_admin_shell
	'monitoring/rss',        // activity hero + asymmetric .sn-2col
	'content/tags',          // glance hero over full-width tables
	'connections/music',     // full-width shell since v6.42.0
) as $pair ) {
	list( $tab, $slug ) = explode( '/', $pair );
	ct_assert( ! empty( $by_tab[ $tab ]['sub_tabs'][ $slug ]['wide'] ), "leaf '$pair' is marked wide" );
}

echo "\nGroup: CSS — .sn-front-end-form field grid + .sn-2col restored\n";
$css = (string) file_get_contents( __DIR__ . '/../assets/admin.css' );

// Front-End: the form's section card is a full-width auto-fit field grid.
$fe = strpos( $css, '.sn-front-end-form .sn-fieldset' );
ct_assert( false !== $fe, '.sn-front-end-form .sn-fieldset selector present' );
$fe_block = false !== $fe ? substr( $css, $fe, strpos( $css, '}', $fe ) - $fe ) : '';
ct_assert( false !== strpos( $fe_block, 'auto-fit' ), 'front-end fields lay out in auto-fit columns' );
ct_assert( false !== strpos( $fe_block, 'max-width: none' ) || false !== strpos( $fe_block, 'max-width:none' ), 'front-end card uncaps to full width' );

// RSS: .sn-2col is a genuine two-column grid at wide (not always-stacked 1fr).
$tc = strpos( $css, '.sn-2col {' );
ct_assert( false !== $tc, '.sn-2col rule present' );
$tc_block = false !== $tc ? substr( $css, $tc, strpos( $css, '}', $tc ) - $tc ) : '';
$two_col = ( false !== strpos( $tc_block, '1.7fr' ) ) || ( false !== strpos( $tc_block, '1fr 1fr' ) );
ct_assert( $two_col, '.sn-2col is a real two-column grid (not the old always-stacked 1fr)' );
// And it must still collapse to one column on narrow viewports. Scope to the
// SECOND .sn-2col rule (the one inside the media query) — a whole-file strpos
// for 'grid-template-columns: 1fr' is tautological (it matches the .sn-shell
// collapse elsewhere in the file, so it would pass even if the
// .sn-2col collapse were deleted).
$tc2       = strrpos( $css, '.sn-2col {' );
$tc2_block = ( false !== $tc2 && $tc2 !== $tc ) ? substr( $css, $tc2, strpos( $css, '}', $tc2 ) - $tc2 ) : '';
ct_assert( false !== strpos( $tc2_block, 'grid-template-columns: 1fr' ), '.sn-2col collapses to one column at a narrow breakpoint' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
