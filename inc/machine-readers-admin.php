<?php
/**
 * Signal & Noise Tools: Machine Readers admin registration + settings.
 *
 * Session 3, lane 4. Registry-driven registration through the
 * inc/admin-dispatch.php dispatcher (never hardcode $_GET['tab']); the POST
 * slug allowlist contract applies. Preview flag follows the v9.67.0 Overview
 * pattern (an option, `sn_machine_readers_preview`) INCLUDING its lesson: the
 * GA flip in v10.0.0 must remove the option row via the migrate-orphan-options
 * idiom, not just stop reading it (the F3 orphan class).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The feature-flag option row. Absent (default) = the tab does not exist.
const SN_MR_PREVIEW_OPT = 'sn_machine_readers_preview';

/**
 * True iff the Machine Readers preview surface is enabled. Reads the plain
 * option (one WP-CLI line to toggle), then offers the house filter seam (the
 * snt_analytics_landing_preview_enabled idiom, v9.67.0). Guarded for isolated
 * CLI harnesses that load this file without a WP option store.
 *
 * @return bool
 */
function snt_mr_preview_enabled() {
	// v10.0.0 GA: the Machine Readers tab is a permanent surface. The flag is
	// retired (its option row is deleted once by the orphan migration, the
	// v9.68.0 F3 lesson); the filter seam stays so the tab can still be hidden
	// by code if a future deployment needs it.
	$enabled = true;
	if ( function_exists( 'apply_filters' ) ) {
		$enabled = (bool) apply_filters( 'sn_machine_readers_preview_enabled', $enabled );
	}
	return $enabled;
}

/**
 * Enqueue the tab's stylesheet on SN admin pages (the provenance-admin.css
 * precedent: admin_enqueue_scripts gated by sn_admin_page_hooks()).
 *
 * @param string $hook_suffix Current admin page hook.
 */
function snt_mr_admin_enqueue( $hook_suffix ) {
	if ( ! function_exists( 'sn_admin_page_hooks' ) || ! in_array( $hook_suffix, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_style( 'sn-machine-readers', plugins_url( 'assets/machine-readers.css', SNT_PATH . 'signal-and-noise-tools.php' ), array( 'sn-admin' ), SNT_VERSION );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_enqueue_scripts', 'snt_mr_admin_enqueue' );
}

/**
 * Registry callback: add the machine-readers leaf entry when the preview flag
 * (or, post-GA, always) allows it. Receives and returns a sub_tabs-style map
 * (slug => leaf, the sn_admin_top_tabs() leaf shape the dispatcher reads:
 * label + render + optional wide). Flag off: the input is returned unchanged,
 * byte-identical, so the tab exists nowhere (the v9.67.0 off-state contract).
 * Pure: operates on the local copy, never the caller's array.
 *
 * @param array $tabs The dispatcher's tab registry (slug => leaf entry).
 * @return array A new registry; input plus the machine-readers leaf when on.
 */
function snt_mr_admin_register( $tabs ) {
	$tabs = (array) $tabs;
	if ( ! snt_mr_preview_enabled() ) {
		return $tabs;
	}
	// 'wide' matches the Monitoring precedent (analytics/insights/health):
	// the render lane lays out full-width tables + cards, not a lone form.
	$tabs['machine-readers'] = array(
		'label'  => 'Machine Readers',
		'render' => 'snt_mr_render_tab',
		'wide'   => true,
	);
	return $tabs;
}

/**
 * Settings save: worker URL + read token under the `machine_readers` subtree.
 * Pure and immutable: returns a NEW full settings array, every foreign subtree
 * preserved byte-identically (the 4x-bitten whole-option clobber class), input
 * arrays untouched. Token is write-only: a blank field keeps the stored value
 * (the sn_handle_analytics_save masked-secret semantic); the render side must
 * never echo the stored token back into the form. Persistence (update_option,
 * autoload) stays with the POST-handler wrapper, not here.
 *
 * @param array $post     Sanitized POST fields for this form.
 * @param array $settings The full current sn_settings array.
 * @return array The full NEW settings array.
 */
function snt_mr_settings_save( $post, $settings ) {
	$post     = (array) $post;
	$settings = (array) $settings;

	// Start from the stored subtree so keys a later version adds survive a
	// save from an older form (same reason sn_settings_save() re-includes
	// whole subtrees rather than single keys).
	$subtree = ( isset( $settings['machine_readers'] ) && is_array( $settings['machine_readers'] ) )
		? $settings['machine_readers']
		: array();

	// Worker URL: esc_url_raw is the storage-side sanitizer (scheme-validated,
	// never for output). Blank clears the override; the API layer falls back
	// to its built-in default endpoint.
	$subtree['worker_url'] = esc_url_raw( trim( (string) ( $post['worker_url'] ?? '' ) ) );

	// Read token: write-only. Only a non-blank submission replaces the stored
	// value; the form field renders empty, so an untouched save changes nothing.
	$token = trim( (string) ( $post['read_token'] ?? '' ) );
	if ( '' !== $token ) {
		$subtree['read_token'] = $token;
	}

	$out                    = $settings;
	$out['machine_readers'] = $subtree;
	return $out;
}

/**
 * Render the Machine Readers leaf (the registry's declared entrypoint). Wide
 * leaf: the section wrapper emits a bare .sn-section, so every block owns its
 * own card chrome. The pure renderers return pre-escaped HTML
 * (tests/machine-readers-render.php pins the escaping); the settings form is
 * rendered last and NEVER echoes the stored token (write-only contract).
 *
 * v10.2.2 (UI pass): two aligned zones, one width. Zone 1 is the readership
 * data as TWO .sn-2up rows (family|surface, then compliance|feed — no more
 * 820px-capped orphans under full-width rows) with the feed total joining the
 * KPI strip. Zone 2 is the Analytics settings-leaf shape WHOLESALE
 * (.sn-an-settings-leaf wrapper, the .sn-an-pipeline hero strip, both columns
 * as fieldset cards) — the v10.1.1 copy had kept the pills in a capped plain
 * card and left the fold column chromeless beside a lone corner card.
 */
function snt_mr_render_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$days   = 30;
	$result = snt_mr_fetch( $days );

	echo '<p class="sn-prose">What machine readers do with the site: which crawler families read it, which machine surfaces they touch, and whether declared AI-training crawlers actually read the rights declarations that apply to them.</p>';

	// One tracker read serves the strip chip AND the feed table (R4: the feed
	// half of the machine audience; the two counts are never summed).
	$sn_mr_feed  = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? (array) sn_rss_tracker_window_stats_multi( array( 7, 30 ) ) : array();
	$sn_mr_feed_total = isset( $sn_mr_feed['windows'][30]['total'] ) ? (int) $sn_mr_feed['windows'][30]['total'] : null;

	// ── Zone 1: the readership data (what you came for) ──
	if ( ! empty( $result['ok'] ) ) {
		$rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		echo snt_mr_render_summary_chips( $rows, $days, $sn_mr_feed_total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every value (fixture-pinned).
		echo '<div class="sn-2up sn-mr-grid">';
		echo '<div class="sn-fieldset">' . snt_mr_render_family_table( $rows, $days ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '<div class="sn-fieldset">' . snt_mr_render_surface_table( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '</div>';
		// Second aligned row: the compliance read beside the feed fetchers,
		// so no table ever stacks as an 820px orphan under a full-width pair.
		echo '<div class="sn-2up sn-mr-grid">';
		echo '<div class="sn-fieldset">' . snt_mr_render_compliance( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '<div class="sn-fieldset">' . snt_mr_render_feed_table( $sn_mr_feed ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '</div>';
	} else {
		echo '<div class="sn-fieldset"><p class="sn-mr-empty">' . esc_html__( 'No readership data yet — the sensor panel below says why.', 'signal-and-noise-tools' ) . '</p></div>';
		// The feed tracker is local WP data — it stays honest even when the
		// edge sensor is unreachable.
		if ( function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
			echo '<div class="sn-fieldset">' . snt_mr_render_feed_table( $sn_mr_feed ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		}
	}

	// v10.2.0: crawler-family delta cards. Rendered from the SAME fetch the
	// tables above used (never a second outbound call — snt_mr_delta_cards()
	// would fire its own, and inc/machine-readers-api.php caches only success,
	// so a down sensor would cost a live request on every admin page load).
	if ( ! empty( $result['ok'] ) && function_exists( 'snt_mr_split_windows' ) && function_exists( 'snt_mr_family_delta_cards' ) ) {
		$sn_mr_rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		$sn_mr_win  = snt_mr_split_windows( $sn_mr_rows, 15, gmdate( 'Y-m-d' ) );
		$sn_mr_cards = snt_mr_family_delta_cards( $sn_mr_win['current'] ?? array(), $sn_mr_win['prior'] ?? array(), 15 );
		if ( ! empty( $sn_mr_cards ) ) {
			echo snt_mr_render_delta_cards( $sn_mr_cards ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every field (fixture-pinned).
		}
	}

	// ── Zone 2: the Analytics settings-leaf shape wholesale (v10.2.2) — the
	// leaf wrapper scopes the token-card language, the pills ride the
	// .sn-an-pipeline hero strip (full width: this leaf is data-wide above,
	// so the hero earns .sn-fieldset--wide where Analytics keeps the cap),
	// and BOTH columns below are fieldset cards, exactly like Analytics.
	echo '<div class="sn-an-settings-leaf">';

	echo '<div class="sn-fieldset sn-fieldset--wide sn-an-pipeline">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Sensor status', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Edge sensor → Analytics Engine → this tab. Presence checks only, secret values are never shown.', 'signal-and-noise-tools' ) . '</p>';
	$sn_mr_info   = snt_mr_sensor_info();
	$sn_mr_status = snt_mr_crawler_list_status();
	echo snt_mr_render_sensor_status( snt_mr_sensor_pills( $sn_mr_info, $sn_mr_status, $result ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every value (fixture-pinned).
	echo '</div>';

	echo '<div class="sn-2up">';

	echo '<div class="sn-fieldset">';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Where this tab reads from: the Worker endpoint and the credential it presents. Constants in wp-config.php override both.', 'signal-and-noise-tools' ) . '</p>';
	snt_mr_render_settings_form();
	echo '</div>';

	echo '<div class="sn-fieldset">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Edge sensor', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'The deployed rights-signals Worker, read live from its version endpoint.', 'signal-and-noise-tools' ) . '</p>';
	echo '<div class="sn-an-worker-card">';
	echo '<p><strong>' . esc_html__( 'Worker', 'signal-and-noise-tools' ) . '</strong> <code>sn-rights-signals</code>';
	if ( is_array( $sn_mr_info ) && isset( $sn_mr_info['version'] ) ) {
		echo ' <code>v' . esc_html( (string) $sn_mr_info['version'] ) . '</code>';
	}
	echo '</p>';
	if ( is_array( $sn_mr_info ) && '' !== (string) ( $sn_mr_info['deployed_at'] ?? '' ) ) {
		echo '<p><strong>' . esc_html__( 'Deployed:', 'signal-and-noise-tools' ) . '</strong> ' . esc_html( (string) $sn_mr_info['deployed_at'] ) . '</p>';
	}
	echo '<p><em>' . esc_html__( 'Source:', 'signal-and-noise-tools' ) . '</em> <code>' . esc_html( defined( 'SN_MR_VERSION_ENDPOINT' ) ? SN_MR_VERSION_ENDPOINT : '' ) . '</code></p>';
	echo '</div>';
	echo '</div>';

	echo '</div>'; // .sn-2up
	echo '</div>'; // .sn-an-settings-leaf
}


/**
 * The settings sub-form: worker URL override + write-only read token, posted
 * through the house sn_action contract (machine_readers_save, nonce +
 * capability checked by the shared dispatcher). The token field always renders
 * EMPTY: a blank submission keeps the stored value, so the secret never round
 * trips through the page source. wp-config constants win over both fields
 * (snt_mr_config), so locked fields are disabled with a note.
 */
function snt_mr_render_settings_form() {
	$url_locked   = defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL;
	$token_locked = defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN;
	$stored_url   = function_exists( 'sn_setting' ) ? (string) sn_setting( 'machine_readers.worker_url', '' ) : '';
	$has_token    = function_exists( 'sn_setting' ) && '' !== (string) sn_setting( 'machine_readers.read_token', '' );
	$default_url  = defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : '';

	// v10.1.1: the Analytics settings-fold idiom — the summary always carries a
	// one-line snapshot, so collapsing never hides whether it is configured.
	$snapshot = $token_locked
		? __( 'token locked by constant', 'signal-and-noise-tools' )
		: ( $has_token ? __( 'token set', 'signal-and-noise-tools' ) : __( 'no token yet', 'signal-and-noise-tools' ) );
	if ( function_exists( 'snt_an_settings_fold' ) ) {
		snt_an_settings_fold( __( 'Sensor settings', 'signal-and-noise-tools' ), $snapshot, ! $has_token && ! $token_locked, function () use ( $url_locked, $token_locked, $stored_url, $has_token, $default_url ) {
			snt_mr_render_settings_fields( $url_locked, $token_locked, $stored_url, $has_token, $default_url );
		} );
		return;
	}
	snt_mr_render_settings_fields( $url_locked, $token_locked, $stored_url, $has_token, $default_url );
}

/**
 * The fields themselves (v10.1.1 extraction, so the Analytics fold can wrap
 * them without the form markup changing).
 *
 * @param bool   $url_locked   Worker URL locked by constant.
 * @param bool   $token_locked Read token locked by constant.
 * @param string $stored_url   Stored worker URL.
 * @param bool   $has_token    Whether a token is stored.
 * @param string $default_url  Built-in endpoint (placeholder).
 */
function snt_mr_render_settings_fields( $url_locked, $token_locked, $stored_url, $has_token, $default_url ) {
	echo '<form method="post" class="sn-mr-settings"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="machine-readers">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="machine_readers_save">';

	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mr_worker_url">Worker URL</label>';
	echo '<input type="url" class="regular-text" id="sn_mr_worker_url" name="sn_mr_worker_url" value="' . esc_attr( $stored_url ) . '" placeholder="' . esc_attr( $default_url ) . '"' . ( $url_locked ? ' disabled' : '' ) . '>';
	echo '<p class="sn-field-helper">' . ( $url_locked ? 'Locked by the <code>SN_MR_WORKER_URL</code> constant in wp-config.php.' : 'Blank uses the built-in live endpoint. A <code>SN_MR_WORKER_URL</code> constant in wp-config.php overrides both.' ) . '</p></div>';

	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mr_read_token">Read token ' . ( $token_locked ? '<span class="sn-pill sn-pill--ok">constant</span>' : ( $has_token ? '<span class="sn-pill sn-pill--ok">set</span>' : '<span class="sn-pill sn-pill--warn">not set</span>' ) ) . '</label>';
	echo '<input type="password" class="regular-text" id="sn_mr_read_token" name="sn_mr_read_token" value="" autocomplete="new-password"' . ( $token_locked ? ' disabled' : '' ) . '>';
	echo '<p class="sn-field-helper">' . ( $token_locked ? 'Locked by the <code>SN_MR_READ_TOKEN</code> constant in wp-config.php.' : 'Write-only: the stored token is never shown here. Leave blank to keep the current value.' ) . '</p></div>';

	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save sensor settings</button></div>';
	echo '</form>';
}
