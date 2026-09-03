<?php
/**
 * Signal & Noise Tools — the IPv6 criterion, stored daily.
 *
 * WHY A STORE AND NOT A LIVE READ. The criterion is a pre-committed gauge that
 * names its own decision, which makes it an ideal watch: act only when it says
 * `build_ranges`. But `sn_ability_login_defense_ipv6_criterion()` computes it
 * from `sn_analytics_query()`, which does not cache — every call is a live
 * analytics-engine query.
 *
 * A watch is read by the morning brief AND by `sn-status{watches}`, which is
 * meant to be a cheap "what is outstanding?" question. Wiring the live gauge
 * into it would make that read fire an outbound query every time, changing a
 * cheap call's cost profile silently — the same class of surprise as a
 * diagnostic that moves when you operate it.
 *
 * So it follows the pattern this codebase already uses for exactly this:
 * `family_drift` and `search_coverage` are stored reports that never fetch on
 * read. A daily cron computes the decision once; the watch reads the record.
 *
 * The LIVE section is untouched — anyone calling `sn-status{ipv6_criterion}`
 * wants the current answer and should pay for it. This store exists so a WATCH
 * can be cheap, not to replace the gauge.
 *
 * @package SignalNoiseTools
 * @since   13.91.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The stored reading: decision, reason and when it was taken. */
const SNT_IPV6_CRITERION_OPTION = 'snt_ipv6_criterion_last';

/** Daily recompute. */
const SNT_IPV6_CRITERION_HOOK = 'snt_ipv6_criterion_refresh';

/**
 * Compute the criterion once and store the parts a watch needs.
 *
 * Stores ONLY on a real reading. The gauge returns `decision: 'unknown'` with a
 * reason when the measurement is unavailable, and overwriting a good record
 * with that would turn a transient analytics outage into a watch that forgets
 * what it knew — the same rule the purge report keeps: a broken sensor must not
 * blank a value that was true an hour ago.
 *
 * @return bool True when a reading was stored.
 */
function snt_ipv6_criterion_refresh() {
	if ( ! function_exists( 'sn_ability_login_defense_ipv6_criterion' ) ) {
		return false;
	}
	$v = sn_ability_login_defense_ipv6_criterion();
	if ( ! is_array( $v ) ) {
		return false;
	}
	$decision = (string) ( $v['decision'] ?? 'unknown' );
	if ( '' === $decision || 'unknown' === $decision ) {
		return false; // Not a reading. Keep whatever was true before.
	}

	update_option(
		SNT_IPV6_CRITERION_OPTION,
		array(
			'decision'    => $decision,
			'reason'      => (string) ( $v['reason'] ?? '' ),
			'measured_at' => time(),
		),
		false
	);
	return true;
}

/**
 * The stored reading, or null when nothing has been stored.
 *
 * Null is NOT "the criterion says no". It means nothing has measured it yet,
 * and a watch reading null stays quiet rather than claiming either way.
 *
 * @return array{decision:string,reason:string,measured_at:int}|null
 */
function snt_ipv6_criterion_stored() {
	$v = get_option( SNT_IPV6_CRITERION_OPTION, null );
	if ( ! is_array( $v ) || '' === (string) ( $v['decision'] ?? '' ) ) {
		return null;
	}
	return array(
		'decision'    => (string) $v['decision'],
		'reason'      => (string) ( $v['reason'] ?? '' ),
		'measured_at' => (int) ( $v['measured_at'] ?? 0 ),
	);
}

/**
 * Schedule the daily recompute. On init so an installed site self-heals a lost
 * event, matching every other scheduler here.
 *
 * @return void
 */
function snt_ipv6_criterion_schedule() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( SNT_IPV6_CRITERION_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SNT_IPV6_CRITERION_HOOK );
	}
}
add_action( 'init', 'snt_ipv6_criterion_schedule' );
add_action( SNT_IPV6_CRITERION_HOOK, 'snt_ipv6_criterion_refresh' );
