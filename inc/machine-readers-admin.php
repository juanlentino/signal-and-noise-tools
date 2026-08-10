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

	// One tracker read serves the strip chip AND the feed table (R4: the feed
	// half of the machine audience; the two counts are never summed).
	$sn_mr_feed  = function_exists( 'sn_rss_tracker_window_stats_multi' ) ? (array) sn_rss_tracker_window_stats_multi( array( 7, 30 ) ) : array();
	$sn_mr_feed_total = isset( $sn_mr_feed['windows'][30]['total'] ) ? (int) $sn_mr_feed['windows'][30]['total'] : null;

	echo '<div class="sn-an-settings-leaf">';

	// ── The hero: Sensor status first, exactly like Analytics' Pipeline
	// status — connection state before data, natural .sn-fieldset cap.
	echo '<div class="sn-fieldset sn-an-pipeline">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Sensor status', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'Edge sensor → Analytics Engine → this tab. Presence checks only, secret values are never shown.', 'signal-and-noise-tools' ) . '</p>';
	$sn_mr_info   = snt_mr_sensor_info();
	$sn_mr_status = snt_mr_crawler_list_status();
	echo snt_mr_render_sensor_status( snt_mr_sensor_pills( $sn_mr_info, $sn_mr_status, $result ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every value (fixture-pinned).
	echo '</div>';

	echo '<div class="sn-2up">';

	// ── Left card: the readership data, stacked sections in ONE card.
	echo '<div class="sn-fieldset sn-mr-data">';
	// v10.44.0: the closing clause read "…and whether declared AI-training
	// crawlers actually read the rights declarations that apply to them", which
	// implied the rights only reach a crawler that goes and fetches them. Since
	// rights-signals worker v1.5.0 the reservation rides every response, so the
	// direct-fetch count stopped being a coverage measure and this copy stopped
	// framing it as one.
	echo '<p class="sn-an-settings-help">' . esc_html__( 'What machine readers do with the site: which crawler families read it and which machine surfaces they touch. The rights reservation rides every response, so declared AI-training crawlers receive it whether or not they fetch the rights files directly: a non-zero direct-fetch count means a crawler went looking for the declarations on purpose.', 'signal-and-noise-tools' ) . '</p>';
	if ( ! empty( $result['ok'] ) ) {
		$rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : array();
		echo snt_mr_render_summary_chips( $rows, $days, $sn_mr_feed_total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every value (fixture-pinned).
		// v10.79.0: purpose first. `family` answers "which crawler", which is
		// the axis that has always been here and is frozen; `purpose` answers
		// "what for", which is the axis the published claims actually run
		// along, so it leads.
		echo snt_mr_render_purpose_table( $rows, $days ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo snt_mr_render_vendor_purpose_table( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo snt_mr_render_family_table( $rows, $days ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo snt_mr_render_surface_table( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo snt_mr_render_compliance( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		// v10.79.0: the frozen family count and the purpose count, side by side.
		// The gap between them is the over-count the family enum carries, shown
		// rather than reconciled away.
		echo snt_mr_render_ai_reconciliation( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		echo snt_mr_render_feed_table( $sn_mr_feed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).

		// v10.79.0 (RULE 2): the unclassified bucket, made inspectable. A
		// SECOND outbound call, unlike the delta cards, because it is a
		// different Analytics Engine query rather than a different reading of
		// the same rows. Gated on the aggregate read having succeeded, so a
		// down sensor still costs exactly one failed request.
		$sn_mr_unknown = snt_mr_fetch( $days, 'unknown' );
		if ( ! empty( $sn_mr_unknown['ok'] ) ) {
			echo snt_mr_render_unknown_agents( $sn_mr_unknown['rows'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		}

		// v10.79.0 (RULE 3): the rights-surface events in full. Logging them and
		// providing no way to read them would repeat the failure RULE 2 fixes.
		$sn_mr_rights = snt_mr_fetch( $days, 'rights' );
		if ( ! empty( $sn_mr_rights['ok'] ) ) {
			echo snt_mr_render_rights_detail( $sn_mr_rights['rows'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		}

		// v10.2.0 delta cards, from the SAME fetch (never a second outbound
		// call — the API layer caches only success, so a down sensor would
		// cost a live request on every admin page load).
		if ( function_exists( 'snt_mr_split_windows' ) && function_exists( 'snt_mr_family_delta_cards' ) ) {
			$sn_mr_win   = snt_mr_split_windows( $rows, 15, gmdate( 'Y-m-d' ) );
			$sn_mr_cards = snt_mr_family_delta_cards( $sn_mr_win['current'] ?? array(), $sn_mr_win['prior'] ?? array(), 15 );
			if ( ! empty( $sn_mr_cards ) ) {
				echo snt_mr_render_delta_cards( $sn_mr_cards ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every field (fixture-pinned).
			}
		}
	} else {
		echo '<p class="sn-mr-empty">' . esc_html__( 'No readership data yet: the Sensor status card above says why.', 'signal-and-noise-tools' ) . '</p>';
		// The feed tracker is local WP data — it stays honest even when the
		// edge sensor is unreachable.
		if ( function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
			echo snt_mr_render_feed_table( $sn_mr_feed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pure renderer escapes every cell (fixture-pinned).
		}
	}
	echo '</div>';

	// ── Right card: the read-only readout over the writable fold.
	echo '<div class="sn-fieldset">';
	echo '<h3 class="sn-fieldset-h">' . esc_html__( 'Edge sensor', 'signal-and-noise-tools' ) . '</h3>';
	echo '<p class="sn-an-settings-help">' . esc_html__( 'The deployed rights-signals Worker, from its version endpoint. Cached for up to 15 minutes, so a fresh deploy can take that long to appear here — purge caches to read it now.', 'signal-and-noise-tools' ) . '</p>';
	// The Analytics Edge-worker readout treatment (native notice-info), not a
	// bespoke bar: same vocabulary as sn_worker_version_render_data().
	echo '<div class="notice notice-info notice-alt inline">';
	echo '<p><strong>' . esc_html__( 'Worker', 'signal-and-noise-tools' ) . '</strong> <code>sn-rights-signals</code>';
	if ( is_array( $sn_mr_info ) && isset( $sn_mr_info['version'] ) ) {
		echo ' <code>v' . esc_html( (string) $sn_mr_info['version'] ) . '</code>';
	}
	echo '</p>';
	if ( is_array( $sn_mr_info ) && '' !== (string) ( $sn_mr_info['deployed_at'] ?? '' ) ) {
		echo '<p><strong>' . esc_html__( 'Deployed:', 'signal-and-noise-tools' ) . '</strong> ' . esc_html( (string) $sn_mr_info['deployed_at'] ) . '</p>';
	}
	// The age line, not a freshness claim. Absent fetched_at (an entry cached
	// before v10.70.2) prints nothing at all: an unknown read time and a read
	// time of "just now" are different answers, and inventing one to fill the
	// slot is how a stale panel passes for a live one.
	if ( is_array( $sn_mr_info ) && isset( $sn_mr_info['fetched_at'] ) ) {
		$sn_mr_age = human_time_diff( (int) $sn_mr_info['fetched_at'], time() );
		echo '<p><strong>' . esc_html__( 'Read:', 'signal-and-noise-tools' ) . '</strong> '
			/* translators: %s: human-readable duration, e.g. "5 mins". */
			. esc_html( sprintf( __( '%s ago', 'signal-and-noise-tools' ), $sn_mr_age ) ) . '</p>';
	}
	echo '<p><em>' . esc_html__( 'Source:', 'signal-and-noise-tools' ) . '</em> <code>' . esc_html( defined( 'SN_MR_VERSION_ENDPOINT' ) ? SN_MR_VERSION_ENDPOINT : '' ) . '</code></p>';
	echo '</div>';
	snt_mr_render_settings_form();
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
