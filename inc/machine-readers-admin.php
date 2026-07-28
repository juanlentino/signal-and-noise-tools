<?php
/**
 * Signal & Noise Tools — Machine Readers: admin registration + settings.
 *
 * SCAFFOLD (Session 3 plan, lane 4). Registry-driven registration through the
 * inc/admin-dispatch.php dispatcher (never hardcode $_GET['tab']); the POST
 * slug allowlist contract applies. Preview flag follows the v9.67.0 Overview
 * pattern (an option, `sn_machine_readers_preview`) INCLUDING its lesson: the
 * GA flip in v10.0.0 must remove the option row via the migrate-orphan-options
 * idiom, not just stop reading it (the F3 orphan class).
 * tests/machine-readers-admin.php is RED against this shell on purpose.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry callback: add the machine-readers tab entry when the preview flag
 * (or, post-GA, always) allows it.
 *
 * @param array $tabs The dispatcher's tab registry.
 * @return array
 */
function snt_mr_admin_register( $tabs ) {
	return $tabs; // Session 3 lane 4.
}

/**
 * Settings save: worker URL + read token under the `machine_readers` subtree.
 * MUST return a NEW settings array that preserves every other subtree
 * (the 4×-bitten whole-option clobber class) — pure, immutably.
 *
 * @param array $post     Sanitized POST fields for this form.
 * @param array $settings The full current sn_settings array.
 * @return array The full NEW settings array.
 */
function snt_mr_settings_save( $post, $settings ) {
	return $settings; // Session 3 lane 4 (token is write-only; never echoed back).
}
