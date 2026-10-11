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
 * Register the tab's stylesheet, once. Shared by the classic page and the S&N
 * Dashboard host window (inc/openstation-host.php), which sits outside the
 * `admin_enqueue_scripts` gate below: the desktop page carries none of the
 * hook suffixes sn_admin_page_hooks() names, so without a registrar the host
 * can call, Measurement -> Machine Readers painted with every .sn-mr-* rule
 * missing. Same precedent as sn_prov_admin_register_assets().
 *
 * @return void
 */
function snt_mr_admin_register_style() {
	if ( wp_style_is( 'sn-machine-readers', 'registered' ) ) {
		return;
	}
	wp_register_style(
		'sn-machine-readers',
		plugins_url( 'assets/machine-readers.css', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'sn-admin' ),
		SNT_VERSION
	);
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
	snt_mr_admin_register_style();
	wp_enqueue_style( 'sn-machine-readers' );
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
	$leaf = array(
		'label'  => 'Machine Readers',
		'render' => 'snt_mr_render_tab',
		'wide'   => true,
	);
	// v10.47.0: INSERT after 'rss' rather than append. Measurement now reads
	// "what was recorded" (Analytics, RSS, Machine Readers) then "what it means"
	// (Insights, Health); appending stranded the third recording surface after the
	// two interpretive ones. Falls back to appending if 'rss' is ever absent, so
	// this can never drop the leaf.
	$out = array();
	foreach ( $tabs as $slug => $def ) {
		$out[ $slug ] = $def;
		if ( 'rss' === $slug ) {
			$out['machine-readers'] = $leaf;
		}
	}
	if ( ! isset( $out['machine-readers'] ) ) {
		$out['machine-readers'] = $leaf;
	}
	return $out;
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
 * Render the Machine Readers leaf (the registry's declared entrypoint).
 *
 * v10.2.3 (owner UAT, third pass): the tab IS the Analytics leaf silhouette —
 * not its widgets rearranged, its SKELETON: one capped .sn-an-pipeline hero
 * card first (Sensor status = Pipeline status), then ONE .sn-2up of two flat
 * cards, and every line of content inside a card. Left card = the readership
 * data as stacked sections (KPI strip, then the four tables — the same
 * many-sections-one-card pattern as Analytics' Edge worker / salt window /
 * Configured elsewhere column). Right card = the Edge sensor readout (the
 * native notice-info treatment sn_worker_version_render_data() uses) over the
 * settings fold. The pure renderers return pre-escaped HTML (fixture-pinned);
 * the settings form NEVER echoes the stored token (write-only contract).
 */
function snt_mr_render_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Configuration only: the sensor read serves its pills; every reading is
	// an S&N Analytics view (inc/analytics-machine-views.php).
	$result = snt_mr_fetch( 30 );
	$info   = snt_mr_sensor_info();
	$status = snt_mr_crawler_list_status();

	// The settings fields echo; capture them so the composer stays pure. Open,
	// never folded: configuration is this leaf's question now.
	ob_start();
	snt_mr_render_settings_fields(
		defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL,
		defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN,
		function_exists( 'sn_setting' ) ? (string) sn_setting( 'machine_readers.worker_url', '' ) : '',
		function_exists( 'sn_setting' ) && '' !== (string) sn_setting( 'machine_readers.read_token', '' ),
		defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : ''
	);
	$settings_html = (string) ob_get_clean();

	$verdicts = array();
	if ( function_exists( 'sn_family_drift_health' ) && function_exists( 'sn_family_drift_report' ) ) {
		$verdicts[ __( 'Crawler-family drift', 'signal-and-noise-tools' ) ] = sn_family_drift_health( sn_family_drift_report(), time() );
	}
	if ( function_exists( 'snt_ml_reader_anomalies_health' ) ) {
		$verdicts[ __( 'Reader behaviour', 'signal-and-noise-tools' ) ] = snt_ml_reader_anomalies_health( function_exists( 'snt_ml_reader_anomalies' ) ? snt_ml_reader_anomalies() : null );
	}

	echo snt_mr_compose_tab( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure composer; every fragment escapes its own values (fixture-pinned).
		'sensor_status_html' => snt_mr_render_sensor_status( snt_mr_sensor_pills( $info, $status, $result ) ),
		'verdicts'           => $verdicts,
		'edge_readout_html'  => snt_mr_render_edge_readout( $info ),
		'settings_form_html' => $settings_html,
	) );
	// Unreleased: rights evidence (held months, their payloads, the lift).
	if ( function_exists( 'sn_admin_render_rights_evidence' ) ) {
		sn_admin_render_rights_evidence();
	}
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
	echo '<form method="post" action="' . esc_url( sn_admin_post_url() ) . '" class="sn-mr-settings"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="machine-readers">';
	wp_nonce_field( 'sn_machine_readers_save' );
	echo '<input type="hidden" name="action" value="sn_machine_readers_save">';

	echo '<div class="sn-field"><label class="sn-field-label" for="sn_mr_worker_url">Worker URL</label>';
	echo '<input type="url" class="regular-text" id="sn_mr_worker_url" name="sn_mr_worker_url" value="' . esc_attr( $stored_url ) . '" placeholder="' . esc_attr( $default_url ) . '"' . ( $url_locked ? ' disabled' : '' ) . '>';
	echo '<p class="sn-field-helper">' . ( $url_locked ? 'Locked by the <code>SN_MR_WORKER_URL</code> constant in wp-config.php.' : 'Blank uses the built-in live endpoint. A <code>SN_MR_WORKER_URL</code> constant in wp-config.php overrides both.' ) . '</p></div>';

	// 15.2.0: the read token is a row on the keyring (Connections › Credentials),
	// a shared secret with the sensor and not a Cloudflare token; the field left
	// with the mistake it invited. This form keeps the URL.
	echo '<div class="sn-field"><span class="sn-field-label">Read token ' . ( $token_locked ? '<span class="sn-pill sn-pill--ok">constant</span>' : ( $has_token ? '<span class="sn-pill sn-pill--ok">set</span>' : '<span class="sn-pill sn-pill--warn">not set</span>' ) ) . '</span>';
	echo '<p class="sn-field-helper">' . ( $token_locked ? 'Locked by the <code>SN_MR_READ_TOKEN</code> constant in wp-config.php.' : 'Set under <a href="' . esc_url( admin_url( 'admin.php?page=sn-tools&tab=connections&sub=credentials' ) ) . '">Connections › Credentials</a>: a shared secret with the sensor, the same value as the worker\'s <code>SN_MR_READ_TOKEN</code>. Not a Cloudflare token.' ) . '</p></div>';

	echo '<div class="sn-fieldset-actions"><button type="submit" class="button button-primary">Save sensor settings</button></div>';
	echo '</form>';
}
