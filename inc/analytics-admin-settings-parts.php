<?php
/**
 * Signal & Noise Tools: analytics-admin.php, the settings section renderer and
 * the empty state.
 *
 * Split from inc/analytics-admin.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Measurement → Analytics settings section. Open-and-wide Phase 2 (v6.44.0):
 * a `.sn-2up` two-column layout that splits the active settings (credentials +
 * own-visit exclusion) from the read-only edge-worker reference (live version,
 * one-time Worker setup). The `analytics` leaf is marked
 * `'wide' => true` in inc/admin-tabs-data.php, so the wrapper emits a bare
 * `.sn-section` and each column owns its own `.sn-fieldset` chrome here (the
 * wide-leaf card-ownership rule). The forms post on the page=sn-theme-options
 * route (the Monitoring sub-tab nav guarantees that slug), so the existing
 * admin-post handler processes analytics_save / _test / _exclude_save / _export
 * unchanged + analytics_tuning_save (v9.36.0) + analytics_funnels_save (S2 §3)
 * — each <form> keeps its own nonce + sn_action button.
 * v9.36.0 (settings hub): pipeline status strip above the columns; engine tuning
 * joins the writable column; read-only mirrors + filter reference join the
 * reference column.
 * S2 §3 (v9.42.0 arc): the session-funnels card joins the writable column,
 * after engine tuning.
 * v9.45.0 (settings-leaf prune, §2): each writable-column card now folds
 * behind snt_an_settings_fold() — a native <details> whose <summary> carries
 * a one-line state snapshot, so collapsing a card never hides whether it's
 * configured. Credentials starts open while the pipeline is incomplete (§3's
 * same sn_analytics_pipeline_complete() seam); everything else defaults
 * closed.
 */
function snt_analytics_render_settings_section() {
	// S2 §6: the leaf-scoped D4 marker — every leaf-scoped token-card rule in
	// analytics-admin.css hangs off this class (the pipeline strip keeps its
	// own .sn-an-pipeline hero treatment; this wrapper never touches it).
	echo '<div class="sn-an-settings-leaf">';

	// v9.36.0 settings hub: pipeline status first — the five presence pills
	// (beacon → worker → read → cron → edge) above the two columns.
	if ( function_exists( 'snt_analytics_render_pipeline_status' ) ) {
		snt_analytics_render_pipeline_status();
	}


	echo '<div class="sn-2up">';
	echo '<div>'; // the left column (15.3.1: the edge card, then the writable fieldset)
	// The edge's seven days head the left column, above the credentials fold.
	if ( function_exists( 'sn_cf_edge_card_render' ) ) {
		sn_cf_edge_card_render();
	}

	// ── Left: writable settings (credentials + exclusion + engine tuning). ──
	echo '<div class="sn-fieldset">';
	echo '<p class="sn-an-settings-help">First-party analytics credentials. The comprehensive read-only dashboard has its own menu, <strong>S&amp;N Analytics</strong>. <a href="' . esc_url( snt_analytics_page_url() ) . '">View dashboard &rarr;</a></p>';
	// v9.45.0 (§2): credentials starts open while the pipeline is incomplete —
	// the same completeness seam the worker-setup conditional (§3) reads.
	snt_an_settings_fold(
		__( 'Credentials', 'signal-and-noise-tools' ),
		snt_an_credentials_snapshot(),
		snt_an_credentials_fold_open(),
		'snt_analytics_render_credentials'
	);
	// v10.46.0: the collector endpoint, moved here from Content → RSS. Sits
	// directly under Credentials because the two describe the same pipeline from
	// both ends — this is where beacons WRITE, those are what READS them — and
	// because the worker-version card in the reference column probes this origin.
	// Opens when unset, on the same "open while incomplete" principle as
	// Credentials; a configured endpoint is a one-line snapshot, not a task.
	if ( function_exists( 'snt_analytics_render_collector' ) ) {
		$__collector_rss = function_exists( 'sn_rss_tracker_settings' ) ? (array) sn_rss_tracker_settings() : array();
		snt_an_settings_fold(
			__( 'Collector endpoint', 'signal-and-noise-tools' ),
			snt_an_collector_snapshot(),
			'' === (string) ( $__collector_rss['collector_url'] ?? '' ),
			'snt_analytics_render_collector'
		);
	}
	// The "Exclude my own visits" role allow-list is a primary analytics setting,
	// so it sits with the credentials in the active-settings column (v6.23.0).
	if ( function_exists( 'snt_analytics_render_exclusion' ) ) {
		snt_an_settings_fold(
			__( 'Exclude my own visits', 'signal-and-noise-tools' ),
			snt_an_exclusion_snapshot(),
			false,
			'snt_analytics_render_exclusion'
		);
	}
	// Unreleased (SN Traffic Lanes): the signed owner marker, beside the exclusion.
	if ( function_exists( 'snt_analytics_render_owner_devices' ) ) {
		snt_an_settings_fold(
			__( 'Owner devices', 'signal-and-noise-tools' ),
			sn_owner_devices_snapshot(),
			false,
			'snt_analytics_render_owner_devices'
		);
	}
	// v9.36.0: the two owner-tunable predictive-engine knobs.
	if ( function_exists( 'snt_analytics_render_engine_tuning' ) ) {
		snt_an_settings_fold(
			__( 'Engine tuning', 'signal-and-noise-tools' ),
			snt_an_tuning_snapshot(),
			false,
			'snt_analytics_render_engine_tuning'
		);
	}
	// 2026-10-16: the publish window's length (inc/publish-window-settings.php).
	if ( function_exists( 'snt_analytics_render_publish_window' ) ) {
		snt_an_settings_fold(
			__( 'Publish windows', 'signal-and-noise-tools' ),
			sn_publish_window_snapshot(),
			false,
			'snt_analytics_render_publish_window'
		);
	}
	// S2 §3 (v9.42.0 arc): owner-defined session funnels, after engine tuning —
	// the writable column's last card.
	if ( function_exists( 'snt_analytics_render_funnels' ) ) {
		snt_an_settings_fold(
			__( 'Session funnels', 'signal-and-noise-tools' ),
			snt_an_funnels_snapshot(),
			false,
			'snt_analytics_render_funnels'
		);
	}
	echo '</div>';

	echo '</div>'; // the left column (15.3.1: the edge card + the writable fieldset)

	// ── Right: read-only reference (worker → mirrors → disclosures). ──
	echo '<div class="sn-fieldset">';
	// The deployed edge-Worker version, read live from /_sn/version (guarded +
	// SWR-cached) — "what's live" above the manual setup steps.
	if ( function_exists( 'sn_worker_version_render_card' ) ) {
		sn_worker_version_render_card();
	}
	// v9.71.0: the rotating identity-salt window — a passive date readout from
	// the same /_sn/version endpoint (worker v1.14.0+), right under the card
	// that already shows what's deployed there. Never an alarm.
	if ( function_exists( 'sn_salt_window_render_card' ) ) {
		sn_salt_window_render_card();
	}
	// v9.36.0: shared config shown read-only with deep links (mirror rule:
	// display-only — one write surface per option, on its own tab).
	if ( function_exists( 'snt_analytics_render_mirrors' ) ) {
		snt_analytics_render_mirrors();
	}
	if ( function_exists( 'snt_analytics_render_filter_reference' ) ) {
		snt_analytics_render_filter_reference();
	}
	snt_analytics_render_worker_setup();
	echo '</div>';

	echo '</div>'; // .sn-2up
	echo '</div>'; // .sn-an-settings-leaf
}

/**
 * Unconfigured gate for the Dashboard → Analytics config gate. Renders via the
 * shared snt_an_gate() primitive (v9.40.0 D4 — was a raw .notice div, and the
 * "Configure analytics →" CTA used to be a second element the caller rendered
 * after this call; it's now folded into the gate itself).
 *
 * D5 §6: dropped the $reason param — its only caller always passed
 * 'unconfigured' and the body never branched on it (dead diagnostic param).
 */
function snt_analytics_render_empty() {
	snt_an_gate(
		__( 'Analytics', 'signal-and-noise-tools' ),
		__( 'Analytics isn\'t receiving data yet. Add your Cloudflare read credentials below to connect the dashboard. You can also set SN_CF_ANALYTICS_TOKEN / SN_CF_ACCOUNT_ID in wp-config.php (see Cloudflare Worker setup below).', 'signal-and-noise-tools' ),
		__( 'Configure analytics →', 'signal-and-noise-tools' ),
		snt_analytics_settings_url(),
		array( 'cta_primary' => true ) // first-run: the page's ONLY action keeps primary weight.
	);
}
