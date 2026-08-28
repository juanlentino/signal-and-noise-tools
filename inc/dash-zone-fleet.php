<?php
/**
 * Signal & Noise — Dashboard fleet zone.
 *
 * Seven component versions and the last deploy, collapsed into one line. A
 * component whose version is null was never probed, which makes the whole zone
 * unknown — "current" is a claim, and an unprobed component cannot support it.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param array<string,string|null|array{version:string,reason:string}> $components
 *        name => version. A plain string or null is a version (null = never probed).
 *        An ARRAY carries the probe's own `reason` so a budget-skipped probe can be
 *        told apart from one that ran and failed.
 * @param string $last_deploy_ago Human string, may be ''.
 * @return array<string,mixed>
 */
function sn_dash_zone_fleet( array $components, $last_deploy_ago = '' ) {
	$cards   = array();
	$unknown = 0;
	$pending = 0;
	foreach ( $components as $name => $component ) {
		// Unwrap FIRST. An array is neither null nor '', so testing the raw value
		// would call it measured and render the literal string "Array".
		$version = is_array( $component ) ? (string) ( $component['version'] ?? '' ) : $component;
		$reason  = is_array( $component ) ? (string) ( $component['reason'] ?? '' ) : '';
		$skew    = is_array( $component ) && ! empty( $component['contract_skew'] );

		$measured = null !== $version && '' !== $version;
		$warming  = ! $measured && 'warming' === $reason;

		if ( $warming ) {
			++$pending;
		}
		if ( ! $measured && ! $warming ) {
			++$unknown;
		}

		$value = $measured ? (string) $version : ( $warming ? __( 'warming…', 'signal-and-noise-tools' ) : '—' );
		if ( $skew ) {
			// The contract comparison the probe carries (v13.22.0) finally
			// renders somewhere: cross-repo skew lands at INSTALL time, and
			// this console is where the install is looked at.
			$value .= sprintf(
				/* translators: 1: live contract version, 2: expected contract version */
				__( ' — contract skew (live %1$s ≠ expected %2$s)', 'signal-and-noise-tools' ),
				'' !== (string) $component['contract_live'] ? (string) $component['contract_live'] : '?',
				(string) $component['contract_expected']
			);
		}

		$cards[] = array(
			'label' => (string) $name,
			'value' => $value,
			// A warming probe is PENDING, not unknown. Marking it unmeasured would
			// force the whole zone to `unknown`, which outranks everything — so one
			// cold cache would report the entire fleet as unmeasurable while the
			// Deploy Status widget beside it listed every version. v11.16.0 settled
			// the same question for the glance sort: cold is not broken.
			'measured'  => $measured || $warming,
			// A VERSION is never an alarm (drift is reported elsewhere) — but a
			// contract skew has no other reporter, so it is the one thing that
			// earns this card attention.
			'attention' => $skew,
		);
	}
	$state = sn_dash_zone_state( $cards );
	$total = count( $components );
	if ( 'unknown' === $state ) {
		$summary = sprintf(
			/* translators: 1: unprobed count, 2: total components */
			__( 'Fleet not measured — %1$d of %2$d never probed', 'signal-and-noise-tools' ),
			$unknown,
			$total
		);
	} elseif ( $pending > 0 ) {
		$summary = sprintf(
			/* translators: 1: total components, 2: count still warming */
			__( 'Fleet current — %1$d components, %2$d warming', 'signal-and-noise-tools' ),
			$total,
			$pending
		);
	} else {
		$summary = sprintf(
			/* translators: %d component count */
			__( 'Fleet current — %d components', 'signal-and-noise-tools' ),
			$total
		);
	}
	$detail = '' !== $last_deploy_ago
		? sprintf( /* translators: %s human time */ __( 'deploy %s', 'signal-and-noise-tools' ), $last_deploy_ago )
		: '';
	return array(
		'id'      => 'fleet',
		'state'   => $state,
		// v11.29.1: the count is already computed here, so return it rather
		// than making callers re-derive it by inspecting card values.
		'pending' => $pending,
		'summary' => $summary,
		'detail'  => $detail,
		'cards'   => $cards,
	);
}

/**
 * Component name => version for the fleet zone.
 *
 * A component the probe has never seen returns null, which makes the zone
 * unknown rather than letting an unprobed worker read as current.
 *
 * NOT snt_deploy_status_for(): that takes 'theme'|'plugin' only and returns a
 * STRUCT, so passing it a worker key returns plugin data and the card renders
 * "Array". Worker versions come from snt_deploy_workers_status(), the same
 * source the glance cards use — `live` is the version actually answering.
 *
 * Theme and plugin are NOT in the worker registry; they arrive as the structs
 * already in scope in snt_dashboard_tab_render().
 *
 * @since 11.28.0
 * @param array<string,mixed> $theme   snt_deploy_status_for( 'theme' ) struct.
 * @param array<string,mixed> $plugin  snt_deploy_status_for( 'plugin' ) struct.
 * @param array<int,array>    $workers snt_deploy_workers_status() rows.
 * @return array<string,string|null>
 */
function snt_dashboard_fleet_components( $theme, $plugin, $workers = array() ) {
	$theme_v  = is_array( $theme ) ? (string) ( $theme['current'] ?? '' ) : '';
	$plugin_v = is_array( $plugin ) ? (string) ( $plugin['current'] ?? '' ) : '';

	$out = array(
		'Theme'  => '' !== $theme_v ? $theme_v : null,
		'Plugin' => '' !== $plugin_v ? $plugin_v : null,
	);

	foreach ( $workers as $worker ) {
		if ( ! is_array( $worker ) ) {
			continue;
		}
		$label = (string) ( $worker['label'] ?? '' );
		if ( '' === $label ) {
			continue;
		}
		// `live` is the version the worker actually answered with. Empty means it
		// did not answer — but WHY matters: a budget-skipped probe is pending,
		// while one that ran and failed is genuinely unknown. Pass the reason
		// through so sn_dash_zone_fleet() can tell them apart instead of
		// reporting our own probe budget as a fact about the fleet.
		$live   = (string) ( $worker['live'] ?? '' );
		$reason = (string) ( $worker['reason'] ?? '' );

		// v13.26.0: a contract SKEW rides along. Only workers with a
		// contract_path carry the keys at all, and only match === false is
		// worth a component's attention — a matching contract is the quiet
		// normal, and absence means "this worker has no contract to check".
		if ( '' !== $live && false === ( $worker['contract_match'] ?? null ) ) {
			$out[ $label ] = array(
				'version'           => $live,
				'reason'            => '',
				'contract_skew'     => true,
				'contract_live'     => (string) ( $worker['contract_live'] ?? '' ),
				'contract_expected' => (string) ( $worker['contract_expected'] ?? '' ),
			);
			continue;
		}

		$out[ $label ] = '' !== $live
			? $live
			: array(
				'version' => '',
				'reason'  => $reason,
			);
	}

	return $out;
}
