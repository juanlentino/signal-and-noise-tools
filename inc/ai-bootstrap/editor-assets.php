<?php
/**
 * Signal & Noise — AI bootstrap: the editor and status script registrations.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_register_status_script(), snt_ai_enqueue_editor_script()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the shared `snt-status` JS utility — exposes window.sntSetStatus.
 *
 * Replaces 4 byte-identical setStatus() copies that lived in
 * ai-meta-description.js, ai-excerpt.js, ai-og-card-title.js, and
 * health-suggest-actions.js pre-v4.1.6 (audit finding U-15).
 *
 * Registration only (NOT enqueue) — callers declare 'snt-status' in their
 * deps array and WP chains the load. Registering here in ai-bootstrap (which
 * is required early in the plugin bootstrap, before any AI feature file)
 * guarantees the handle exists at the time the consumer scripts enqueue.
 *
 * MUST be unconditional. health-suggest-actions.js — one of the consumers that
 * declares 'snt-status' as a dep — is enqueued UNCONDITIONALLY on the Health +
 * Tools tabs (since v4.5.2), because it also drives the NON-AI pattern-adoption
 * and block-migration Suggest buttons, which render with no AI gate. If
 * 'snt-status' is missing, WP_Dependencies::all_deps() silently DROPS the whole
 * dependent script (the handle is never queued → never printed → every Suggest
 * button is dead with no console error). The pre-v6.47.x gate on
 * snt_ai_is_available() therefore left those buttons inert on any no-AI /
 * broken-provider / WP<7.0 install — the same dead-button class v4.5.1 fixed at
 * the enqueue layer but not at the dependency layer. Registration != enqueue:
 * a registered-but-unenqueued handle is never output, so registering it on
 * every admin page is free.
 *
 * @since 4.1.6
 */
function snt_register_status_script() {
	wp_register_script(
		'snt-status',
		plugins_url( 'assets/snt-status.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array(),
		SNT_VERSION,
		true
	);
}

/**
 * Shared editor-asset enqueue for the gen-1 per-post AI buttons (v9.81.0).
 *
 * ai-excerpt.php, ai-meta-description.php, and ai-og-card-title.php each
 * carried a near-identical ~35-line admin_enqueue_scripts closure that had
 * already drifted (inconsistent function_exists guards around the AI gate).
 * This helper is the single copy: post-edit screens only, gated on
 * snt_ai_is_available() + edit_posts, base dep set (wp-api-fetch, wp-i18n,
 * snt-status for window.sntSetStatus, snt-ability-run for the ability
 * transport) plus per-caller extras, localization, and script translations.
 *
 * @param string $hook_suffix The admin_enqueue_scripts hook suffix.
 * @param string $handle      Script handle to register/enqueue.
 * @param string $file        Filename under assets/.
 * @param string $object_name JS object name for wp_localize_script.
 * @param array  $data        Localized data.
 * @param array  $extra_deps  Per-caller deps appended to the base set.
 * @return void
 *
 * @since 9.81.0
 */
function snt_ai_enqueue_editor_script( $hook_suffix, $handle, $file, $object_name, $data, $extra_deps = array() ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	if ( ! snt_ai_is_available() ) {
		return; // Skip enqueue entirely — no button, no JS, no overhead.
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_register_script(
		$handle,
		plugins_url( 'assets/' . $file, SNT_PATH . 'signal-and-noise-tools.php' ),
		array_merge( array( 'wp-api-fetch', 'wp-i18n', 'snt-status', 'snt-ability-run' ), $extra_deps ),
		SNT_VERSION,
		true
	);

	wp_localize_script( $handle, $object_name, $data );
	wp_enqueue_script( $handle );

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'signal-and-noise-tools' );
	}
}
