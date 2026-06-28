<?php
/**
 * Signal & Noise — Automation → IndexNow admin sub-tab.
 *
 * Render-only (POST handled in sn_handle_indexnow_* on admin_init, PRG).
 * Surfaces the enable toggle, the served key-file URL (so the owner can
 * verify it resolves), the last submission result, a "Regenerate key", and
 * a one-shot "Submit recent content now" backfill. Native WP styling only.
 *
 * @package SignalNoiseTools
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_admin_render_indexnow_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$enabled = sn_indexnow_is_enabled();
	$key_url = sn_indexnow_key_url();
	$result  = (array) get_option( SN_INDEXNOW_RESULT_OPT, array() );

	sn_admin_shell_open();

	// ── MAIN: intro + a 2-up of [settings card] + [maintenance card] ──
	echo '<p class="sn-prose">Pushes changed URLs to <strong>IndexNow</strong> (Bing, Yandex, Seznam, Naver&hellip; &mdash; not Google) on publish, update, and removal so they re-crawl within minutes. The verification key file is served automatically &mdash; no upload needed.</p>';

	echo '<div class="sn-2up">';

	// ── ENABLE FORM (carded — IndexNow is a wide leaf, so the section wrapper
	// provides no card; the form must own its chrome, or the .sn-savebar's
	// negative card-bleed margin overflows the bare .sn-section). v6.43.1. ──
	echo '<form method="post" class="sn-fieldset"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="indexnow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="indexnow_save">';
	echo '<h2 class="sn-fieldset-h">IndexNow</h2>';
	echo '<div class="sn-field"><label><input type="checkbox" name="indexnow_enabled" value="1"' . checked( $enabled, true, false ) . '> Notify search engines when content changes</label></div>';
	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save IndexNow settings</button></div></form>';

	// ── ACTIONS (regenerate + backfill) ──
	echo '<form method="post" class="sn-card sn-card--narrow"><input type="hidden" name="tab" value="connections"><input type="hidden" name="sub" value="indexnow">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<strong>Maintenance</strong><p class="sn-helper">&ldquo;Submit recent content now&rdquo; backfills your existing published posts. &ldquo;Regenerate key&rdquo; rotates the key (search engines re-verify on the next submission).</p>';
	echo '<button type="submit" name="sn_action" value="indexnow_ping_now" class="button">Submit recent content now</button> ';
	echo '<button type="submit" name="sn_action" value="indexnow_regenerate" class="button">Regenerate key</button></form>';

	echo '</div>'; // .sn-2up

	// ── RAIL: status pill + status table (v6.42.0) ──
	sn_admin_shell_rail( 'IndexNow status' );

	// ── STATUS ──
	if ( ! $enabled ) {
		echo '<div class="sn-status-box sn-status-box--warn"><div><p class="sn-status-box-title">Disabled</p><p class="sn-status-box-body">Enable it in the main column to start notifying search engines.</p></div><span class="sn-pill sn-pill--warn">Off</span></div>';
	} elseif ( ! empty( $result['error'] ) ) {
		echo '<div class="sn-status-box sn-status-box--err"><div><p class="sn-status-box-title">Last submission failed</p><p class="sn-status-box-body"><code>' . esc_html( (string) $result['error'] ) . '</code></p></div><span class="sn-pill sn-pill--err">Error</span></div>';
	} else {
		echo '<div class="sn-status-box"><div><p class="sn-status-box-title">Active</p><p class="sn-status-box-body">Changed URLs are submitted automatically.</p></div><span class="sn-pill sn-pill--ok">On</span></div>';
	}

	// ── STATUS TABLE ──
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">Status</h2><table class="form-table sn-status-table sn-status-table--full"><tbody>';
	echo '<tr><th>Key file</th><td>' . ( '' !== $key_url ? '<a href="' . esc_url( $key_url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $key_url ) . '</code></a>' : '<em>not generated yet</em>' ) . '</td></tr>';
	if ( ! empty( $result['time'] ) ) {
		echo '<tr><th>Last submission</th><td>' . esc_html( human_time_diff( (int) $result['time'], time() ) ) . ' ago &mdash; HTTP ' . (int) ( $result['code'] ?? 0 ) . ', ' . (int) ( $result['count'] ?? 0 ) . ' URL(s)</td></tr>';
	}
	echo '</tbody></table></div>';

	sn_admin_shell_close();
}
