<?php
/**
 * S&N Dashboard — Monitoring → Machine Readers, painter helpers.
 *
 * The leaf is configuration now: the sensor pills, the worker readout and the
 * settings form. Every reading moved to S&N Analytics (Machine readers and
 * Reader evidence, inc/analytics-machine-views.php). Pure presenters; none of
 * them mutate their input.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * What the configuration leaf reads: the sensor read (for its pills), the
 * worker's version document, the crawler-list status and the settings. The
 * readings live in S&N Analytics (Machine readers, Reader evidence).
 * Data only, no HTML here.
 *
 * @param bool $reread Drop the sensor's cached version reading first (Re-read now).
 * @return array<string,mixed>
 */
function machine_readers_data( $reread = false ) {
	$days   = 30;
	$result = function_exists( '\snt_mr_fetch' ) ? \snt_mr_fetch( $days ) : array( 'ok' => false, 'rows' => array() );

	// Re-read now drops the 15-minute cache first (2026-10-12 audit: the box
	// said "purge caches to read it now", and no purge clears this transient).
	if ( $reread ) {
		\delete_transient( 'sn_mr_sensor_info' );
	}
	$info   = function_exists( '\snt_mr_sensor_info' ) ? \snt_mr_sensor_info() : null;
	$status = function_exists( '\snt_mr_crawler_list_status' ) ? \snt_mr_crawler_list_status() : null;

	return array(
		'days'         => $days,
		'result'       => $result,
		'info'         => $info,
		'status'       => $status,
		'url_locked'   => defined( 'SN_MR_WORKER_URL' ) && '' !== (string) SN_MR_WORKER_URL,
		'token_locked' => defined( 'SN_MR_READ_TOKEN' ) && '' !== (string) SN_MR_READ_TOKEN,
		'stored_url'   => function_exists( '\sn_setting' ) ? (string) \sn_setting( 'machine_readers.worker_url', '' ) : '',
		'has_token'    => function_exists( '\sn_setting' ) && '' !== (string) \sn_setting( 'machine_readers.read_token', '' ),
		'default_url'  => defined( 'SN_MR_DEFAULT_ENDPOINT' ) ? SN_MR_DEFAULT_ENDPOINT : '',
	);
}

/** @param array $pills snt_mr_sensor_pills() output. @return string */
function machine_readers_pills_html( array $pills ) {
	$marks = array(
		'ok'      => '✓',
		'warn'    => '!',
		'unknown' => '?',
	);
	$badges = '';
	foreach ( $pills as $p ) {
		$state   = isset( $marks[ $p[0] ] ) ? $p[0] : 'unknown';
		$badges .= \snt_kit_badge( $state, $marks[ $state ] . ' ' . (string) $p[1] );
	}
	$warns = '';
	foreach ( $pills as $p ) {
		if ( 'warn' === $p[0] && '' !== (string) ( $p[2] ?? '' ) ) {
			$warns .= \snt_kit_notice( 'warn', \snt_kit_esc( $p[1] . ': ' . $p[2] ) );
		}
	}
	return ( '' !== $badges ? '<os-cluster gap="8">' . $badges . '</os-cluster>' : '' ) . $warns;
}

/**
 * The Edge sensor readout: facts rows in the classic readout's words
 * (snt_mr_edge_readout()), or "not read" when the endpoint did not answer;
 * then Re-read now, a `go` back to this leaf that drops the cache first.
 *
 * @param array|null $info snt_mr_sensor_info() shape, or null.
 * @return string
 */
function machine_readers_edge_readout_html( $info ) {
	$rows = function_exists( '\snt_mr_edge_readout' ) ? \snt_mr_edge_readout( $info ) : null;
	if ( null === $rows ) {
		$out = \snt_kit_notice( 'warn', \snt_kit_esc( function_exists( '\snt_mr_edge_not_read' ) ? \snt_mr_edge_not_read() : __( 'Not read.', 'signal-and-noise-tools' ) ) );
	} else {
		$kv = array();
		foreach ( $rows as $row ) {
			$kv[] = isset( $row['ts'] )
				? array( 'label' => $row['label'], 'html' => true, 'value' => \snt_kit_relative_time( (int) $row['ts'] ) )
				: array( 'label' => $row['label'], 'value' => $row['value'] );
		}
		$out = \snt_kit_kv( $kv );
	}
	return $out . '<footer>' . \snt_kit_button( __( 'Re-read now', 'signal-and-noise-tools' ), 'go', array( 'args' => array( 'sub' => 'machine-readers', 'sn_mr_reread' => '1' ) ) ) . '</footer>';
}

/** @param array<string,mixed> $d machine_readers_data() output. @return string */
function machine_readers_settings_html( array $d ) {
	$snapshot = $d['token_locked']
		? __( 'token locked by constant', 'signal-and-noise-tools' )
		: ( $d['has_token'] ? __( 'token set', 'signal-and-noise-tools' ) : __( 'no token yet', 'signal-and-noise-tools' ) );

	$badge = $d['token_locked']
		? \snt_kit_badge( 'info', __( 'constant', 'signal-and-noise-tools' ) )
		: ( $d['has_token'] ? \snt_kit_badge( 'ok', __( 'set', 'signal-and-noise-tools' ) ) : \snt_kit_badge( 'warn', __( 'not set', 'signal-and-noise-tools' ) ) );

	$fields = \snt_kit_field(
		'url',
		'sn_mr_worker_url',
		__( 'Worker URL', 'signal-and-noise-tools' ),
		$d['stored_url'],
		array(
			'placeholder' => $d['default_url'],
			'disabled'    => $d['url_locked'],
			'hint'        => $d['url_locked']
				? __( 'Locked by the SN_MR_WORKER_URL constant in wp-config.php.', 'signal-and-noise-tools' )
				: __( 'Blank uses the built-in live endpoint. A SN_MR_WORKER_URL constant in wp-config.php overrides both.', 'signal-and-noise-tools' ),
		)
	);
	// 15.2.0: the read token is a row on the keyring; a shared secret with the
	// sensor, not a Cloudflare token. This form keeps the URL.
	$fields .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Read token:', 'signal-and-noise-tools' ) ) . ' ' . $badge . ' ' . ( $d['token_locked']
		? \snt_kit_esc( __( 'Locked by the SN_MR_READ_TOKEN constant in wp-config.php.', 'signal-and-noise-tools' ) )
		: \snt_kit_esc( __( 'Set under', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_go( __( 'Connections › Credentials', 'signal-and-noise-tools' ), array( 'tab' => 'connections', 'sub' => 'credentials', 'current' => 'monitoring' ) ) . ': ' . \snt_kit_esc( __( 'a shared secret with the sensor, the same value as the worker\'s SN_MR_READ_TOKEN. Not a Cloudflare token.', 'signal-and-noise-tools' ) ) ) . '</p>';

	$form = \snt_kit_form(
		'machine_readers_save',
		$fields,
		array(
			'submit' => __( 'Save sensor settings', 'signal-and-noise-tools' ),
			'hidden' => array( 'tab' => 'monitoring', 'sub' => 'machine-readers' ),
		)
	);

	// Open, never folded: this is the leaf's question now (configuration).
	return \snt_kit_section( __( 'Sensor settings', 'signal-and-noise-tools' ), $form, $snapshot );
}
