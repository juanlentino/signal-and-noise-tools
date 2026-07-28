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
	$enabled = function_exists( 'get_option' )
		? (bool) get_option( SN_MR_PREVIEW_OPT, false )
		: false;
	if ( function_exists( 'apply_filters' ) ) {
		$enabled = (bool) apply_filters( 'sn_machine_readers_preview_enabled', $enabled );
	}
	return $enabled;
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
 */
function snt_mr_render_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$days   = 30;
	$result = snt_mr_fetch( $days );

	// Owner ask (2026-07-28): the deployed sensor version, visible in-admin.
	echo snt_mr_render_sensor_card( snt_mr_sensor_info() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped pure renderer.

	echo '<p class="sn-prose">What machine readers do with the site: which crawler families read it, which machine surfaces they touch, and whether declared AI-training crawlers actually read the rights declarations that apply to them.</p>';

	if ( empty( $result['ok'] ) ) {
		if ( 'not_configured' === ( $result['error'] ?? '' ) ) {
			echo '<div class="notice notice-warning notice-alt inline"><p><strong>Sensor not configured.</strong> Save the read token below (or define <code>SN_MR_READ_TOKEN</code> in wp-config.php) to read the rights-signals sensor.</p></div>';
		} else {
			echo '<div class="notice notice-error notice-alt inline"><p><strong>Sensor read failed</strong> (<code>' . esc_html( (string) ( $result['error'] ?? 'unknown' ) ) . '</code>). The worker may be unreachable or answering with an unexpected shape; the panel retries on the next load.</p></div>';
		}
	} else {
		$rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		echo '<div class="sn-2up">';
		echo '<div class="sn-fieldset">' . snt_mr_render_family_table( $rows, $days ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '<div class="sn-fieldset">' . snt_mr_render_surface_table( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo '</div>';
		echo '<div class="sn-fieldset">' . snt_mr_render_compliance( $rows ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
	}

	snt_mr_render_crawler_status_card();
	snt_mr_render_settings_form();
}

/**
 * The crawler-list card: the worker's public crawler-list-status document
 * (no auth), fetched through the shared outbound gate with a short transient
 * by snt_mr_crawler_list_status(). Degrades to a quiet dash on any failure.
 * Values are worker JSON, so both halves of every row are escaped here.
 */
function snt_mr_render_crawler_status_card() {
	$status = function_exists( 'snt_mr_crawler_list_status' ) ? snt_mr_crawler_list_status() : null;
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Crawler list</h2>';
	if ( ! is_array( $status ) || empty( $status ) ) {
		echo '<p class="sn-field-helper">Crawler-list status: &mdash;</p>';
		echo '</div>';
		return;
	}
	echo '<table class="form-table sn-status-table"><tbody>';
	foreach ( $status as $key => $value ) {
		echo '<tr><th>' . esc_html( ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) ) ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
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

	echo '<form method="post" class="sn-fieldset"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="machine-readers">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<input type="hidden" name="sn_action" value="machine_readers_save">';
	echo '<h2 class="sn-fieldset-h">Sensor settings</h2>';

	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mr_worker_url">Worker URL</label>';
	echo '<input type="url" class="regular-text" id="sn_mr_worker_url" name="sn_mr_worker_url" value="' . esc_attr( $stored_url ) . '" placeholder="' . esc_attr( $default_url ) . '"' . ( $url_locked ? ' disabled' : '' ) . '>';
	echo '<p class="sn-field-helper">' . ( $url_locked ? 'Locked by the <code>SN_MR_WORKER_URL</code> constant in wp-config.php.' : 'Blank uses the built-in live endpoint. A <code>SN_MR_WORKER_URL</code> constant in wp-config.php overrides both.' ) . '</p></div>';

	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mr_read_token">Read token ' . ( $token_locked ? '<span class="sn-pill sn-pill--ok">constant</span>' : ( $has_token ? '<span class="sn-pill sn-pill--ok">set</span>' : '<span class="sn-pill sn-pill--warn">not set</span>' ) ) . '</label>';
	echo '<input type="password" class="regular-text" id="sn_mr_read_token" name="sn_mr_read_token" value="" autocomplete="new-password"' . ( $token_locked ? ' disabled' : '' ) . '>';
	echo '<p class="sn-field-helper">' . ( $token_locked ? 'Locked by the <code>SN_MR_READ_TOKEN</code> constant in wp-config.php.' : 'Write-only: the stored token is never shown here. Leave blank to keep the current value.' ) . '</p></div>';

	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save sensor settings</button></div>';
	echo '</form>';
}
