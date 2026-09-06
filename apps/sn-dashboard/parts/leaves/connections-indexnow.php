<?php
/**
 * S&N Dashboard — Connections → IndexNow, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/indexnow.php, `sn_admin_render_indexnow_section()`)
 * paints an intro, a 2-up of the enable form (`sn_action=indexnow_save`, field
 * `indexnow_enabled`, hidden `tab`/`sub`) beside the maintenance card (two
 * one-click writes, `indexnow_ping_now` and `indexnow_regenerate`), then a
 * rail: the status box (disabled / failed / active) and the status table (key
 * file, last submission). Same readers, same forms, same handlers; the kit's
 * parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The module's state, read the way the classic leaf reads it.
 *
 * @return array{enabled:bool,key_url:string,result:array<string,mixed>}
 */
function indexnow_data() {
	return array(
		'enabled' => (bool) \sn_indexnow_is_enabled(),
		'key_url' => (string) \sn_indexnow_key_url(),
		'result'  => (array) get_option( \SN_INDEXNOW_RESULT_OPT, array() ),
	);
}

/**
 * The status box: disabled, else the last submission's error, else active —
 * the classic order of precedence.
 *
 * @param array $d From indexnow_data().
 * @return string
 */
function indexnow_status_html( array $d ) {
	if ( ! $d['enabled'] ) {
		$kind  = 'warn';
		$title = __( 'Disabled', 'signal-and-noise-tools' );
		$body  = \snt_kit_esc( __( 'Enable it in the main column to start notifying search engines.', 'signal-and-noise-tools' ) );
		$pill  = __( 'Off', 'signal-and-noise-tools' );
	} elseif ( ! empty( $d['result']['error'] ) ) {
		$kind  = 'err';
		$title = __( 'Last submission failed', 'signal-and-noise-tools' );
		$body  = \snt_kit_code( (string) $d['result']['error'], false );
		$pill  = __( 'Error', 'signal-and-noise-tools' );
	} else {
		$kind  = 'ok';
		$title = __( 'Active', 'signal-and-noise-tools' );
		$body  = \snt_kit_esc( __( 'Changed URLs are submitted automatically.', 'signal-and-noise-tools' ) );
		$pill  = __( 'On', 'signal-and-noise-tools' );
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_badge( $kind, $pill ) . '<br>' . $body );
}

/**
 * The main column's 2-up: the enable form beside the maintenance actions.
 *
 * @param array $d From indexnow_data().
 * @return string
 */
function indexnow_main_html( array $d ) {
	$toggle = \snt_kit_field( 'checkbox', 'indexnow_enabled', __( 'Notify search engines when content changes', 'signal-and-noise-tools' ), $d['enabled'] );
	$enable = \snt_kit_section(
		__( 'IndexNow', 'signal-and-noise-tools' ),
		\snt_kit_form(
			'indexnow_save',
			$toggle,
			array(
				'submit' => __( 'Save IndexNow settings', 'signal-and-noise-tools' ),
				'hidden' => array( 'tab' => 'connections', 'sub' => 'indexnow' ),
			)
		)
	);
	$maintenance = \snt_kit_section(
		__( 'Maintenance', 'signal-and-noise-tools' ),
		\snt_kit_tag(
			'os-cluster',
			array( 'gap' => '8' ),
			\snt_kit_action_button( __( 'Submit recent content now', 'signal-and-noise-tools' ), 'indexnow_ping_now' )
			. \snt_kit_action_button( __( 'Regenerate key', 'signal-and-noise-tools' ), 'indexnow_regenerate' )
		),
		__( '“Submit recent content now” backfills your existing published posts. “Regenerate key” rotates the key (search engines re-verify on the next submission).', 'signal-and-noise-tools' )
	);
	return '<div class="snt-cols"><section class="snt-col">' . $enable . '</section><section class="snt-col">' . $maintenance . '</section></div>';
}

/**
 * The rail: the status box, then the status facts (key file, last submission).
 *
 * @param array $d From indexnow_data().
 * @return string
 */
function indexnow_rail_html( array $d ) {
	$rows = array(
		array(
			'label' => __( 'Key file', 'signal-and-noise-tools' ),
			'value' => '' !== $d['key_url']
				? \snt_kit_tag(
					'a',
					array(
						'class'  => 'snt-link',
						'href'   => $d['key_url'],
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					),
					\snt_kit_code( $d['key_url'], false )
				)
				: '<em>' . \snt_kit_esc( __( 'not generated yet', 'signal-and-noise-tools' ) ) . '</em>',
			'html'  => true,
		),
	);
	if ( ! empty( $d['result']['time'] ) ) {
		$rows[] = array(
			'label' => __( 'Last submission', 'signal-and-noise-tools' ),
			'value' => sprintf(
				/* translators: 1: how long ago, 2: HTTP status code, 3: number of URLs submitted */
				__( '%1$s ago — HTTP %2$d, %3$d URL(s)', 'signal-and-noise-tools' ),
				human_time_diff( (int) $d['result']['time'], time() ),
				(int) ( $d['result']['code'] ?? 0 ),
				(int) ( $d['result']['count'] ?? 0 )
			),
		);
	}
	return \snt_kit_tag(
		'aside',
		array(
			'col'        => '4',
			'aria-label' => __( 'IndexNow status', 'signal-and-noise-tools' ),
		),
		\snt_kit_tag(
			'os-stack',
			array( 'gap' => '16' ),
			indexnow_status_html( $d ) . \snt_kit_section( __( 'Status', 'signal-and-noise-tools' ), \snt_kit_kv( $rows ) )
		)
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_indexnow( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( 'sn_indexnow_is_enabled' ) || ! defined( 'SN_INDEXNOW_RESULT_OPT' ) ) {
		return \snt_kit_empty( __( 'IndexNow is not available.', 'signal-and-noise-tools' ) );
	}
	$d   = indexnow_data();
	$out = '<p class="snt-prose">' . sprintf(
		/* translators: %s: the IndexNow name, in bold */
		\snt_kit_esc( __( 'Pushes changed URLs to %s (Bing, Yandex, Seznam, Naver… — not Google) on publish, update, and removal so they re-crawl within minutes. The verification key file is served automatically — no upload needed.', 'signal-and-noise-tools' ) ),
		'<strong>IndexNow</strong>'
	) . '</p>';
	$out .= \snt_kit_tag(
		'os-row',
		array( 'gap' => '16' ),
		\snt_kit_tag( 'os-stack', array( 'col' => '8', 'gap' => '12' ), indexnow_main_html( $d ) ) . indexnow_rail_html( $d )
	);
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/indexnow'] = __NAMESPACE__ . '\\paint_connections_indexnow';
		return $painters;
	}
);
