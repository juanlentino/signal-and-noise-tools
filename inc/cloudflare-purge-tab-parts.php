<?php
/**
 * Signal & Noise: Cloudflare cache purge, the probe schedule, the Cache sentence and the Cloudflare tab (one 216-line hook callback, moved whole).
 *
 * Split from inc/cloudflare-purge.php (house line cap, 2026-10-15); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check the note's page SN_CF_PROBE_DELAY seconds after its purge, once:
 * three saves in ten minutes probe once, after the last (v11.10.0). Runs
 * after the URL list of an older theme and after a note's tag purge.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function sn_cf_schedule_probe( $post_id ) {
	if ( ! function_exists( 'wp_schedule_single_event' ) || ! defined( 'SN_CF_PROBE_HOOK' ) ) {
		return;
	}
	$args = array( (int) $post_id );
	$next = wp_next_scheduled( SN_CF_PROBE_HOOK, $args );
	if ( $next ) {
		wp_unschedule_event( $next, SN_CF_PROBE_HOOK, $args );
	}
	wp_schedule_single_event( time() + SN_CF_PROBE_DELAY, SN_CF_PROBE_HOOK, $args );
}

/**
 * The Cache box's sentence, the same on both surfaces (2026-10-12 audit: it
 * pointed at docs/CACHING.md, a file in the repository, not on this site).
 *
 * @return string
 */
function sn_cf_caching_sentence() {
	return __( "Auto-purges Cloudflare's edge cache when content changes. A purge only matters once a Cache Rule in the Cloudflare dashboard caches HTML at the edge; without one, origin pages are not cached there and a purge clears nothing useful.", 'signal-and-noise-tools' );
}

/**
 * Admin UI for the Cloudflare tab. Lets the user save the API token +
 * zone ID and trigger a manual full-zone purge.
 *
 * Hooked to the dedicated `sn_admin_cloudflare_tab` action emitted by
 * inc/admin-page.php when the user selects the Cloudflare tab. This UI
 * was once a card on the Dashboard tab via `sn_admin_dashboard_extras`,
 * moved to its own tab as the Dashboard grew unwieldy and each subsystem
 * earned a dedicated tab.
 */
add_action( 'sn_admin_cloudflare_tab', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// POST handling lives in sn_handle_admin_post() (admin_init, PRG).
	// This callback is render-only.
	$token            = sn_cf_get_token();
	$zone             = sn_cf_get_zone();
	$token_obscured   = sn_mask_secret( $token );
	$token_const_set  = defined( 'SN_CLOUDFLARE_API_TOKEN' );
	$zone_const_set   = defined( 'SN_CLOUDFLARE_ZONE_ID' );
	$both_locked      = $token_const_set && $zone_const_set;
	$last_purge       = get_option( SN_CF_LAST_PURGE_OPT, array() );
	$is_configured    = sn_cf_is_configured();
	$acct_const_set   = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) constant( 'SN_CF_ACCOUNT_ID' );
	$acct_value       = function_exists( 'sn_cf_get_account_id' ) ? (string) sn_cf_get_account_id() : '';

	echo '<p class="sn-prose">' . esc_html( sn_cf_caching_sentence() ) . '</p>';

	// Phase 3 (v6.45.0): full-width two-column shell — credentials (the work) in
	// the main column, the module status + manual-purge action in the rail.
	sn_admin_shell_open();

	// ── MAIN: WHERE THE CREDENTIALS LIVE (15.2.0: Connections › Credentials) ──
	// The token, zone and account are set on the keyring with every other key;
	// this leaf reads them and says where each comes from.
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Credentials</h2>';
	echo '<p class="sn-field-helper">Set with every other key under <a href="' . esc_url( admin_url( 'admin.php?page=sn-tools&tab=connections&sub=credentials' ) ) . '">Connections › Credentials</a>.</p>';
	$sources = array(
		'API token'  => $token_const_set ? 'locked by SN_CLOUDFLARE_API_TOKEN' : ( '' !== $token ? 'saved (' . $token_obscured . ')' : 'not set' ),
		'Zone ID'    => $zone_const_set ? 'locked by SN_CLOUDFLARE_ZONE_ID' : ( '' !== $zone ? $zone : 'not set' ),
		'Account ID' => $acct_const_set ? 'locked by SN_CF_ACCOUNT_ID' : ( '' !== $acct_value ? $acct_value : 'not set' ),
	);
	echo '<table class="widefat"><tbody>';
	foreach ( $sources as $k => $v ) {
		echo '<tr><th scope="row">' . esc_html( $k ) . '</th><td class="sn-mono">' . esc_html( $v ) . '</td></tr>';
	}
	echo '</tbody></table>';
	// 14.10.0: the grant list, one place to compare against the token summary.
	if ( function_exists( 'sn_cf_required_grants' ) ) {
		echo '<h3 class="sn-fieldset-h">Grants this token needs</h3><ul>';
		foreach ( sn_cf_required_grants() as $g ) {
			echo '<li><code>' . esc_html( $g['scope'] . ' › ' . $g['grant'] ) . '</code> · ' . esc_html( $g['for'] ) . ' <em>' . esc_html( $g['status'] ) . '</em></li>';
		}
		echo '</ul>';
	}
	echo '</div>';

	// ── POST-PURGE PROBES ────────────────────────────────────────────────
	// v13.71.2. Shipped one release earlier into the RAIL, with three class
	// names (.sn-table, .sn-rail-h, .sn-rail-note) that do not exist in
	// assets/admin.css — so it rendered as an unstyled browser-default table,
	// twenty full URLs wide, inside a column the shell sizes at 1fr against the
	// main column's 1.7fr. Owner-reported: "no format at all", and the rail
	// "a bit crammed". Both were the same mistake: inventing a component for a
	// surface that already has one, and putting a twenty-row data grid in the
	// summary column.
	//
	// It lives in the MAIN column now and uses core's widefat/striped, the same
	// classes the analytics tables in this plugin already use — real formatting,
	// no new CSS, and it looks like wp-admin because it IS wp-admin. The host is
	// stripped from each row: it repeats twenty times and eats the width that
	// the path actually needs.
	//
	// Worth knowing while reading these: this detector HAS been wrong wholesale
	// before. Until v11.29.1 it compared whole documents, and Breeze injects a
	// script on the cache-busted fetch only, so every probe read stale and the
	// log showed 11 of 11 — not a stale edge, a detector that could not return
	// anything else. Rows carry the algorithm that produced them for exactly
	// that reason, and a pre-v11.29.1 row says so rather than being counted.
	$probe_log = defined( 'SN_CF_PROBE_LOG_OPT' ) ? get_option( SN_CF_PROBE_LOG_OPT, array() ) : array();
	$probe_log = is_array( $probe_log ) ? $probe_log : array();
	if ( $is_configured && $probe_log ) {
		// v13.72.1: FOLDED, and open only when it is the task. Twenty rows sat
		// expanded under a two-field credentials form nobody comes here to read
		// them from — the panel answers "did purges work", and the answer is one
		// line until it isn't. Uses the shared .sn-disclosure caret rather than
		// the browser triangle; the summary carries the counts so a collapsed
		// fold still says what it is hiding.
		$probe_stale  = 0;
		$probe_newest = '';
		foreach ( $probe_log as $r_i => $r_row ) {
			if ( ! is_array( $r_row ) ) {
				continue;
			}
			if ( '' === $probe_newest ) {
				$probe_newest = (string) ( $r_row['result'] ?? '' );
			}
			if ( 'stale' === (string) ( $r_row['result'] ?? '' ) ) {
				++$probe_stale;
			}
		}
		// Open when the NEWEST probe is stale: that is a live condition. A stale
		// count with a fresh newest is history, and history stays folded.
		echo '<details class="sn-fieldset sn-disclosure"' . ( 'stale' === $probe_newest ? ' open' : '' ) . '>';
		echo '<summary><span class="sn-fieldset-h">Post-purge probes</span> '
			. '<span class="sn-pill ' . ( 'stale' === $probe_newest ? 'sn-pill--warn' : 'sn-pill--muted' ) . '">'
			. esc_html( sprintf(
				/* translators: 1: probes retained, 2: how many of them were stale */
				_n( '%1$d retained, %2$d stale', '%1$d retained, %2$d stale', count( $probe_log ), 'signal-and-noise-tools' ),
				count( $probe_log ),
				$probe_stale
			) . ( function_exists( 'snt_cf_probe_status' ) && snt_cf_probe_status( $probe_log )['retired'] ? ' · ' . __( 'no longer runs', 'signal-and-noise-tools' ) : '' ) ) . '</span></summary>';
		$probe_retired = function_exists( 'snt_cf_probe_retired_note' ) ? snt_cf_probe_retired_note( $probe_log ) : '';
		echo '<p class="sn-fieldset-intro">' . ( '' !== $probe_retired ? esc_html( $probe_retired ) . ' ' : '' ) . esc_html( snt_cf_probe_intro( (int) SN_CF_PROBE_DELAY ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col" class="manage-column column-primary">When</th><th scope="col">Result</th><th scope="col">Page</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $probe_log, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$r_time  = (int) ( $row['time'] ?? 0 );
			$r_res   = (string) ( $row['result'] ?? '' );
			$r_stale = 'stale' === $r_res;
			$r_pill  = $r_stale ? 'sn-pill sn-pill--warn' : ( 'fresh' === $r_res ? 'sn-pill sn-pill--ok' : 'sn-pill sn-pill--muted' );
			$r_label = '' !== $r_res ? $r_res : 'unknown';
			$r_esc = snt_cf_probe_escalation_label( $row );
			if ( '' !== $r_esc ) {
				$r_label .= ' ' . $r_esc;
			}
			// The URL minus the host: twenty identical origins is twenty times
			// the width and none of the information.
			$r_url  = (string) ( $row['url'] ?? '' );
			$r_path = '' !== $r_url ? (string) wp_parse_url( $r_url, PHP_URL_PATH ) : '';
			if ( '' === $r_path ) {
				// A manual zone purge names no single page; say which purge it was.
				$r_path = '' !== (string) ( $row['source'] ?? '' ) ? (string) $row['source'] : __( 'not recorded', 'signal-and-noise-tools' );
			}
			$r_old = defined( 'SN_CF_PROBE_ALGO' ) && (int) ( $row['algo'] ?? 1 ) < SN_CF_PROBE_ALGO;
			echo '<tr>';
			echo '<td class="column-primary">' . esc_html( $r_time ? human_time_diff( $r_time, time() ) . ' ago' : __( 'not recorded', 'signal-and-noise-tools' ) ) . '</td>';
			echo '<td data-colname="Result"><span class="' . esc_attr( $r_pill ) . '">' . esc_html( $r_label ) . '</span>'
				. ( $r_old ? ' <span class="sn-pill sn-pill--muted">retired detector</span>' : '' ) . '</td>';
			echo '<td data-colname="Page"><code>' . esc_html( $r_path ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</details>';
	}

	// ── RAIL: module status + manual purge action ──
	sn_admin_shell_rail( 'Cache status' );

	if ( $is_configured ) {
		$last_line = '';
		if ( ! empty( $last_purge['time'] ) ) {
			$ago       = human_time_diff( (int) $last_purge['time'], time() );
			$kind      = sn_cf_last_purge_kind( (array) $last_purge );
			$last_line = ' Last purge: ' . esc_html( $ago ) . ' ago (' . esc_html( $kind ) . ').';
		}
		$last_line .= ' ' . esc_html__( 'Breeze page cache:', 'signal-and-noise-tools' ) . ' ' . esc_html( sn_cf_breeze_page_cache_words( get_option( 'sn_last_purge_report', array() ) ) ) . '.';
		echo '<div class="sn-status-box">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Configured: auto-purge active</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $last_line is static text plus esc_html()-escaped values.
		echo '<p class="sn-status-box-body">' . esc_html( __( 'Cache purges fire automatically', 'signal-and-noise-tools' ) . ' ' . sn_cf_auto_purge_when() . '.' ) . $last_line . '</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--ok">Active</span>';
		echo '</div>';

	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Not configured</p>';
		echo '<p class="sn-status-box-body">Auto-purge disabled. Set both the API token and zone ID under Credentials to activate.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	// ── 14.9.0: THE MONITOR (token, zone, firewall) ──
	// The native leaf paints the full reading (connections-cloudflare-monitor.php);
	// the classic page keeps the one-line summary and the same Refresh action,
	// so the two leaves offer the same actions (the leaf suite pins parity).
	if ( function_exists( 'sn_cf_monitor_api_row' ) ) {
		$mon = sn_cf_monitor_api_row( sn_cf_monitor_read(), (array) get_option( SN_CF_LAST_PURGE_OPT, array() ), time() );
		echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-card sn-card--narrow">';
		wp_nonce_field( 'sn_cf_monitor_refresh' );
		echo '<strong>Monitor</strong>';
		echo '<p class="sn-helper">' . esc_html( $mon['value'] ) . '</p>';
		echo '<button type="submit" name="action" value="sn_cf_monitor_refresh" class="button"' . ( $is_configured ? '' : ' disabled' ) . '>Refresh now</button>';
		echo '</form>';
	}

	// ── CLOUDWAYS PURGE STATUS (render hardening FIX 3b) ──
	// Retired 2026-10-14 (Varnish is off for this app; the row says so). It
	// was surfaced here so a failed purge (e.g. a Cloudways API 422
	// field-validation error) showed next to the rest of the pipeline instead
	// of a silent ok:false in an option no screen read; the row now reads
	// Retired.
	if ( function_exists( 'sn_cloudways_is_configured' ) && sn_cloudways_is_configured() && function_exists( 'sn_admin_cloudways_leg' ) ) {
		// 2026-10-12 audit: the Cloudways leaf's own verdict (sn_admin_cloudways_leg()),
		// so the two leaves call one record by one name.
		$cw_last = defined( 'SNT_CW_LAST_PURGE_OPT' ) ? get_option( SNT_CW_LAST_PURGE_OPT, array() ) : array();
		$cw_leg  = sn_admin_cloudways_leg( is_array( $cw_last ) ? $cw_last : array(), sn_admin_cloudways_on() );
		$cw_line = '' !== $cw_leg['value'] ? esc_html( $cw_leg['value'] ) . '.' : '';
		if ( $cw_leg['time'] > 0 ) {
			$cw_line .= ' Last attempt: ' . esc_html( human_time_diff( $cw_leg['time'], time() ) ) . ' ago.';
		}
		$cw_line .= ' ' . wp_kses_post( $cw_leg['meta'] );
		$cw_mod   = in_array( $cw_leg['kind'], array( 'warn', 'err' ), true ) ? ' sn-status-box--' . $cw_leg['kind'] : '';
		echo '<div class="sn-status-box' . esc_attr( $cw_mod ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Cloudways purge</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cw_line is esc_html()-escaped values plus the builder's kses-filtered meta.
		echo '<p class="sn-status-box-body">' . trim( $cw_line ) . '</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $cw_leg['kind'] ) . '">' . esc_html( $cw_leg['text'] ) . '</span>';
		echo '</div>';
	}

	sn_admin_shell_close();
} );
