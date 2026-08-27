<?php
/**
 * Signal & Noise — the Login-defense IPv6 criterion, as a read ability.
 *
 * WHY THIS EXISTS (v12.11.0): the gauge shipped in v10.74.0 and could only be
 * read by a human opening the Login-defense view. The criterion was fixed in
 * advance — worker v1.5.2: build 128-bit denylist ranges when the IPv6 share
 * of block-eligible traffic exceeds 5% sustained over 30 days — specifically
 * so the NUMBER triggers the call instead of reopening the argument. A number
 * nobody can query triggers nothing, and the reading sat undone from the day
 * the question was asked. This is the door.
 *
 * WHY IT RETURNS A NAMED `decision` AND NOT JUST THE SHARE. On 2026-08-22,
 * asked to read this gauge, I derived the window from the worker's v1.5.0 TAG
 * date (2026-07-22) and reported the criterion satisfied. The sensor's first
 * actual row is 2026-07-26; the window held 27 of 30 days. The gauge measures
 * min(timestamp) over rows the sensor really wrote for exactly that reason.
 * A caller handed a bare 51.7% has to re-derive the rule, and re-derivation is
 * what failed. So both halves travel with the answer, and the answer is named.
 *
 * NOT on the remote door. The remote MCP slice is analytics-only by owner
 * direction; login-defense telemetry stays on the desktop read door.
 *
 * @package SignalNoiseTools
 * @since 12.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the IPv6 criterion: the measured share, the measured window, and the
 * decision those two jointly authorise.
 *
 * Zero-vs-null honesty is inherited from the gauge and must survive the port:
 * `sn_analytics_query()` returns an array (measured, possibly zero) or null
 * (failure). A failure reports `measured: false` with a null share — never a
 * reassuring 0%. Never-measured (every row predating the family sensor) is the
 * same three-state answer, not an empty one.
 *
 * @since 12.11.0
 * @param array|null $input Unused; the criterion window is not caller-tunable.
 * @return array
 */
function sn_ability_login_defense_ipv6_criterion( $input = null ) {
	$base = array(
		'measured'        => false,
		'share_pct'       => null,
		'crossed'         => false,
		'v6'              => null,
		'total'           => null,
		'first_seen'      => null,
		'measured_days'   => null,
		'days_covered'    => null,
		'window_complete' => null,
		'pre_sensor_hits' => 0,
		// Both halves of the rule travel with the answer so no caller
		// reconstructs it from memory.
		'threshold_pct'   => SN_LG_IPV6_THRESHOLD_PCT,
		'criterion_days'  => SN_LG_IPV6_CRITERION_DAYS,
		// The re-specced coverage halves (2026-08-27). criterion_days is still
		// the LOOKBACK window; these two are what "sustained" now requires.
		'criterion_min_days_covered' => SN_LG_IPV6_MIN_DAYS_COVERED,
		'criterion_min_observations' => SN_LG_IPV6_MIN_OBSERVATIONS,
		'decision'        => 'unknown',
		'reason'          => '',
	);

	$rows = sn_analytics_query( sn_login_defense_family_share_sql( SN_LG_IPV6_CRITERION_DAYS ) );
	if ( ! is_array( $rows ) ) {
		$base['reason'] = 'measurement unavailable (the analytics query failed)';
		return $base;
	}

	// The real producer, not a second implementation of the same arithmetic.
	$s = sn_login_defense_ipv6_share( $rows, SN_LG_IPV6_CRITERION_DAYS );

	$out = array_merge(
		$base,
		array(
			'measured'        => true,
			'share_pct'       => $s['share_pct'],
			'crossed'         => $s['crossed'],
			'v6'              => $s['v6'],
			'total'           => $s['total'],
			'first_seen'      => $s['first_seen'],
			'measured_days'   => $s['measured_days'],
			'days_covered'    => $s['days_covered'],
			'window_complete' => $s['window_complete'],
			'pre_sensor_hits' => $s['pre_sensor_hits'],
		)
	);

	if ( null === $s['share_pct'] ) {
		$out['measured'] = false;
		$out['decision'] = 'unknown';
		$out['reason']   = 'no checked traffic carrying the family sensor in the window yet';
		return $out;
	}

	if ( true !== $s['window_complete'] ) {
		// The load-bearing branch. A crossed line on an unfinished window is a
		// real share and a decision nobody is authorised to make.
		$out['decision'] = 'withhold_unfinished_window';
		$out['reason']   = null === $s['days_covered']
			? sprintf( 'coverage unknown: the rows carry no day dimension, so the %dd window cannot be assessed', SN_LG_IPV6_CRITERION_DAYS )
			: sprintf(
				'real share, unfinished window: the criterion asks for %d covered days and %d observations; this window holds %d and %d (family sensor since %s)',
				SN_LG_IPV6_MIN_DAYS_COVERED,
				SN_LG_IPV6_MIN_OBSERVATIONS,
				$s['days_covered'],
				$s['total'],
				$s['first_seen']
			);
		return $out;
	}

	$out['decision'] = $s['crossed'] ? 'build_ranges' : 'below_threshold';
	$out['reason']   = sprintf(
		'%s%% over %d covered days and %d observations, against a %d%% criterion',
		$s['share_pct'],
		$s['days_covered'],
		$s['total'],
		SN_LG_IPV6_THRESHOLD_PCT
	);
	return $out;
}

/**
 * Register the ability.
 *
 * @since 12.11.0
 * @return void
 */
function sn_abilities_login_defense_register() {
	wp_register_ability( 'signal-noise/login-defense-ipv6-criterion', array(
		'label'               => 'Login defense: IPv6 criterion',
		'description'         => 'Returns the login guard IPv6-share criterion: the measured share, the window it was MEASURED over (not the one the query asked for), and the decision both halves authorise. '
			. 'decision is one of build_ranges | withhold_unfinished_window | below_threshold | unknown. '
			. 'The rule (worker v1.5.2): build 128-bit denylist ranges when the IPv6 share of block-eligible traffic exceeds 5% sustained over 30 days. '
			. 'Read `decision`, never `crossed` alone — a crossed line on an unfinished window authorises nothing. '
			. 'measured_days is a SPAN (now - first_seen); days_covered is how many days actually wrote rows. On sparse traffic they disagree, and only days_covered answers "sustained". '
			. 'share_pct is null when unmeasured; never-measured is not 0%. Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'sn_ability_login_defense_ipv6_criterion',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array( 'type' => 'object' ),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
}
// v13.1.1: WAS 'abilities_api_init' — a hook that nothing fires. The missing
// wp_ prefix left this the only unregistered ability file of the 36 (the other
// 35 all hook wp_abilities_api_init), so the IPv6-criterion tool was doored,
// projected nowhere, and uncallable since v12.11.0 — the exact "cannot be
// projected — this is a BUG" verdict the MCP usage panel printed about it.
// The suite stayed green because it stubbed add_action inert and drove the
// registrar directly: it tested the callback, never the wiring. It pins the
// wiring now.
add_action( 'wp_abilities_api_init', 'sn_abilities_login_defense_register' );
