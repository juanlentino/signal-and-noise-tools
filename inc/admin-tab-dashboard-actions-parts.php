<?php
/**
 * Signal & Noise Tools: admin-tab-dashboard.php, the cost and delta
 * formatters, the force-check handler and the override names.
 *
 * Split from inc/admin-tab-dashboard.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format a USD spend estimate with a sub-cent floor (mirrors the Insights tab).
 *
 * @param float $cost
 * @return string
 */
function snt_dashboard_fmt_cost( $cost ) {
	$cost = (float) $cost;
	if ( $cost > 0 && $cost < 0.005 ) {
		return '<$0.01';
	}
	return '$' . number_format_i18n( $cost, 2 );
}

/**
 * Build a pre-escaped week-over-week delta badge from an analytics delta row
 * ({dir, pct}). Returns kses-safe markup for the glance card meta line.
 *
 * @param array $delta {dir:string, pct:?int, current:int, previous:int}
 * @return string Escaped badge markup, or empty string when no comparison.
 */
function snt_dashboard_delta_badge_html( $delta ) {
	$dir = (string) ( $delta['dir'] ?? '' );
	if ( '' === $dir ) {
		return '';
	}
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct ) ? 'flat' : ( ( $pct >= 0 ? '+' : '' ) . (int) $pct . '%' );
	// The glyph is hidden from screen readers, so the direction is said in
	// words; "flat" with no percentage can still be up from a zero week.
	$said = 'up' === $dir ? 'up' : ( 'down' === $dir ? 'down' : 'no change' );
	return '<span class="sn-glance-delta sn-glance-delta--' . esc_attr( $dir ) . '">'
		. '<span aria-hidden="true">' . esc_html( $arrow ) . '</span>'
		. '<span class="screen-reader-text">' . esc_html( $said . ', ' ) . '</span> ' . esc_html( $text . ' WoW' ) . '</span>';
}

// The attention items, the classic strip and the diagnostics block live in
// inc/dash-attention.php (2026.10.4 audit, batch 4).

/* ════════════════════════════════════════════════════════════════════════
 * RENDER HELPERS
 * ════════════════════════════════════════════════════════════════════════ */

/* ════════════════════════════════════════════════════════════════════════
 * FORCE-CHECK HANDLER
 *
 * Stays as a separate admin-post.php handler (rather than folding into
 * sn_handle_admin_post()) because the refresh link in the API summary is
 * a GET (nonce-protected URL), not a POST from a tab form. admin-post.php
 * accepts both POST and GET via $_REQUEST routing.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_post_sn_force_update_check', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_force_update_check', 'sn_force_update_check_nonce' );

	// v4.1.1 (D-01): delegate to the shared impl in desktop-mode-commands.php.
	// Pre-v4.1.1 this 4-line block was duplicated byte-for-byte across this
	// handler and snt_cmd_impl_force_check(). Single source of truth now.
	if ( function_exists( 'snt_cmd_impl_force_check' ) ) {
		snt_cmd_impl_force_check();
	}

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );

// v11.28.0: the Site Health > Info panel moved to inc/dash-debug-info.php and
// took its add_filter with it. It never rendered on this tab — it lived here
// only by history.

/**
 * The override names, `post_type/post_name` each, for both twins' Database
 * overrides list (inc/dash-attention.php, dashboard-operations.php).
 *
 * @since 11.29.0
 * @param array $overrides Override posts.
 * @return array<int,string>
 */
function snt_dashboard_override_names( array $overrides ) {
	$names = array();
	foreach ( $overrides as $tpl ) {
		if ( is_object( $tpl ) && isset( $tpl->post_type, $tpl->post_name ) ) {
			$names[] = (string) $tpl->post_type . '/' . (string) $tpl->post_name;
		}
	}
	return $names;
}
