<?php
/**
 * Health check: WordPress is still spawning cron from ordinary page requests.
 *
 * ── Why a health check and not a field ────────────────────────────────────
 *
 * The obvious home for this was the cron-health payload, next to
 * `cron_disabled_constant`. That payload is one of the eight remote twins whose
 * output_schemas ARE the versioned MCP contract, pinned by a shape hash in
 * tests/remote-contract-shapes.php. Adding a field there is a contract bump,
 * and a bump the worker has not been redeployed for leaves the door expecting
 * version 5 while version 4 is live. The check surface has no such contract,
 * answers the same question, and is the estate's existing home for "something
 * is wrong and you can fix it".
 *
 * ── What it detects ───────────────────────────────────────────────────────
 *
 * `inc/wp-cron-offload.php` defines `DISABLE_WP_CRON` at plugin load, and
 * records which of five things happened. Two of them leave cron in the request
 * path:
 *
 *   already_false     wp-config defines the constant FALSE. We decline to
 *                     override an explicit decision - and the result is that
 *                     the offload is inert while looking installed.
 *   declined_filter   `snt_offload_wp_cron` returned false. Deliberate, and
 *                     still worth stating, because the cost is real.
 *
 * Neither was visible before. `cron_disabled_constant` could not show it: that
 * field is a PROBLEM FLAG - constant set AND nothing fired recently AND no
 * system cron declared - so it reads false when the constant is absent and
 * false again when everything is working. Two opposite situations, one value.
 * I misread it that way myself within an hour of shipping it (v13.97.3).
 *
 * ── Why it is a defect and not an advisory ────────────────────────────────
 *
 * It is wrong rather than merely improvable: measured on this install, cron in
 * the request path meant a visitor's pageview paying for a job averaging 10.6s
 * and peaking at 51.7s (#1002). It can reach zero and stay there - one
 * wp-config line settles it. And no other surface owns it.
 *
 * @package Signal_And_Noise_Tools
 * @since 13.97.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report when cron is still spawned in-request.
 *
 * @return array { count, findings, label, fix_hint, skipped }
 */
function sn_health_check_wp_cron_request_path() {
	$label    = 'WP-Cron in the request path';
	$fix_hint = 'WordPress is spawning cron from ordinary page requests, so a visitor pays for whatever cron does. Define DISABLE_WP_CRON as true in wp-config.php and make sure something external hits wp-cron.php (Cloudways: the Cron Optimizer installs a 5-minute wget).';

	if ( ! function_exists( 'snt_wp_cron_offload_state' ) ) {
		// NOT a pass: the offload module is absent, so nothing was measured and
		// cron may well be in the request path.
		return sn_health_pack_check( $label, array(), $fix_hint, 'the wp-cron offload module is not loaded' );
	}

	$state = snt_wp_cron_offload_state();
	if ( ! snt_wp_cron_still_in_request_path() ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$note = 'already_false' === $state
		? 'wp-config.php defines DISABLE_WP_CRON as FALSE, so the plugin declines to override it and the offload is inert while looking installed. Change that line to true, or remove it.'
		: 'The snt_offload_wp_cron filter returned false, so cron stays in the request path deliberately. Measured cost on this install: a job averaging 10.6s, peaking at 51.7s, paid for by whichever pageview spawns it.';

	return sn_health_pack_check(
		$label,
		array(
			array(
				'subject_type'  => 'wp_cron',
				'subject_id'    => 0,
				'subject_url'   => '',
				'subject_label' => $state,
				'edit_url'      => '',
				'note'          => $note,
			),
		),
		$fix_hint
	);
}
