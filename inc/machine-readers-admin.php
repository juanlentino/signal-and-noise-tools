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
	$days   = 30;
	$result = snt_mr_fetch( $days );
	$rows   = ! empty( $result['ok'] ) && is_array( $result['rows'] ?? null ) ? $result['rows'] : array();

	// One tracker read serves the hero chip AND the folded feed table (R4: the
	// feed half of the machine audience; the two counts are never summed).
	$feed       = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? (array) sn_rss_tracker_window_stats_multi( array( 7, 30 ) ) : array();
	$feed_total = isset( $feed['windows'][30]['total'] ) ? (int) $feed['windows'][30]['total'] : null;

	$info   = snt_mr_sensor_info();
	$status = snt_mr_crawler_list_status();

	// The two extra reads stay gated on the aggregate having succeeded, so a down
	// sensor still costs exactly one failed request (unchanged from before the
	// v12.22.0 recomposition).
	$unknown_rows = null;
	$rights_rows  = null;
	$cards        = array();
	if ( ! empty( $result['ok'] ) ) {
		$unknown = snt_mr_fetch( $days, 'unknown' );
		if ( ! empty( $unknown['ok'] ) ) {
			$unknown_rows = $unknown['rows'] ?? array();
		}
		$rights = snt_mr_fetch( $days, 'rights' );
		if ( ! empty( $rights['ok'] ) ) {
			$rights_rows = $rights['rows'] ?? array();
		}
		// Delta cards come from the SAME fetch — never a second outbound call.
		if ( function_exists( 'snt_mr_split_windows' ) && function_exists( 'snt_mr_family_delta_cards' ) ) {
			$win   = snt_mr_split_windows( $rows, 15, gmdate( 'Y-m-d' ) );
			$cards = snt_mr_family_delta_cards( $win['current'] ?? array(), $win['prior'] ?? array(), 15 );
		}
	}

	// The settings form echoes; capture it so the composer stays pure.
	ob_start();
	snt_mr_render_settings_form();
	$settings_html = (string) ob_get_clean();

	echo snt_mr_compose_tab( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure composer; every fragment escapes its own values (fixture-pinned).
		'days'               => $days,
		'ok'                 => ! empty( $result['ok'] ),
		// v13.43.0. The flag has ridden the fetch result since worker v1.23.0
		// and reached no renderer, so a capped read was invisible on the page.
		'truncated'          => ! empty( $result['truncated'] ),
		'rows'               => $rows,
		'feed'               => $feed,
		'feed_total'         => $feed_total,
		'rights_rows'        => $rights_rows,
		'unknown_rows'       => $unknown_rows,
		'delta_cards'        => $cards,
		'sensor_status_html' => snt_mr_render_sensor_status( snt_mr_sensor_pills( $info, $status, $result ) ),
		'edge_readout_html'  => snt_mr_render_edge_readout( $info ),
		'settings_form_html' => $settings_html,
	) );
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
