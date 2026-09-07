<?php
/**
 * S&N Analytics — chrome/controls: range, class, compare, custom window, export.
 *
 * Classic: snt_analytics_render_controls() in inc/analytics-render-controls.php.
 * Picks are kit buttons that dispatch `go` as `{ key, value }`. Export stays a
 * real form with target=_blank — a download cannot be a window dispatch.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_chrome_controls( array $ctx ) {
	$range   = (string) ( $ctx['range'] ?? '7' );
	$class   = (string) ( $ctx['class'] ?? 'human' );
	$compare = (string) ( $ctx['compare'] ?? 'off' );
	$from    = (string) ( $ctx['from'] ?? '' );
	$to      = (string) ( $ctx['to'] ?? '' );

	$rolling = array( '7' => __( 'Last 7 days', 'signal-and-noise-tools' ), '14' => __( 'Last 14 days', 'signal-and-noise-tools' ), '30' => __( 'Last 30 days', 'signal-and-noise-tools' ), '90' => __( 'Last 90 days', 'signal-and-noise-tools' ), '365' => __( 'Last year', 'signal-and-noise-tools' ), 'all' => __( 'All time', 'signal-and-noise-tools' ) );
	$ranges  = $rolling;
	if ( function_exists( 'snt_analytics_preset_labels' ) ) {
		foreach ( snt_analytics_preset_labels() as $token => $label ) {
			$ranges[ (string) $token ] = (string) $label;
		}
	}
	$ranges['custom'] = __( 'Custom range…', 'signal-and-noise-tools' );
	$range_options = '';
	foreach ( $ranges as $token => $label ) {
		$range_options .= \snt_kit_tag( 'os-option', array( 'value' => (string) $token ), \snt_kit_esc( $label ) );
	}
	$range_control = \snt_kit_tag(
		'os-select',
		array(
			'class'     => 'snt-filter snt-filter--range',
			'label'     => __( 'Range', 'signal-and-noise-tools' ),
			'value'     => $range,
			'os-bind'   => 'range',
			'os-action' => 'filter',
		),
		$range_options
	);

	$class_row = '';
	foreach ( array( 'human' => __( 'Human', 'signal-and-noise-tools' ), 'suspect' => __( 'Suspect', 'signal-and-noise-tools' ), 'bot' => __( 'Bot', 'signal-and-noise-tools' ) ) as $token => $label ) {
		$class_row .= \snt_kit_tag( 'os-segment', array( 'value' => $token ), \snt_kit_esc( $label ) );
	}
	$class_control = \snt_kit_tag(
		'os-segmented',
		array(
			'class'     => 'snt-filter snt-filter--class',
			'label'     => __( 'Traffic class', 'signal-and-noise-tools' ),
			'value'     => $class,
			'os-bind'   => 'class',
			'os-action' => 'filter',
		),
		$class_row
	);

	$compare_row = '';
	foreach ( array( 'off' => __( 'Off', 'signal-and-noise-tools' ), 'prev' => __( 'Previous', 'signal-and-noise-tools' ), 'yoy' => __( 'Year over year', 'signal-and-noise-tools' ) ) as $token => $label ) {
		$compare_row .= \snt_kit_tag( 'os-option', array( 'value' => $token ), \snt_kit_esc( $label ) );
	}
	$compare_control = \snt_kit_tag(
		'os-select',
		array(
			'class'     => 'snt-filter snt-filter--compare',
			'label'     => __( 'Compare', 'signal-and-noise-tools' ),
			'value'     => $compare,
			'os-bind'   => 'compare',
			'os-action' => 'filter',
		),
		$compare_row
	);

	$hidden = '';
	foreach ( (array) ( $ctx['get'] ?? array() ) as $name => $value ) {
		if ( ! is_scalar( $value ) || in_array( (string) $name, array( 'page', 'sn_range', 'sn_from', 'sn_to' ), true ) ) {
			continue;
		}
		$hidden .= \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => (string) $name, 'value' => (string) $value ) );
	}
	$hidden .= \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_range', 'value' => 'custom' ) );
	$today   = gmdate( 'Y-m-d' );
	$custom  = 'custom' === $range ? \snt_kit_tag(
		'os-form',
		array(
			'class'        => 'snt-form snt-custom-range',
			'os-action'    => 'go',
			'submit-label' => __( 'Apply', 'signal-and-noise-tools' ),
			'show-reset'   => 'false',
			'columns'      => '2',
		),
		$hidden
		. \snt_kit_tag( 'os-field-row', array( 'label' => __( 'From', 'signal-and-noise-tools' ) ), \snt_kit_tag( 'input', array( 'type' => 'date', 'name' => 'sn_from', 'value' => 'custom' === $range ? $from : '', 'max' => $today ) ) )
		. \snt_kit_tag( 'os-field-row', array( 'label' => __( 'To', 'signal-and-noise-tools' ) ), \snt_kit_tag( 'input', array( 'type' => 'date', 'name' => 'sn_to', 'value' => 'custom' === $range ? $to : '', 'max' => $today ) ) )
	) : '';

	$admin = function_exists( 'admin_url' ) ? admin_url( 'admin.php' ) : '';
	$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'sn_theme_options_nonce' ) : '';
	$export_hidden = \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => $nonce ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'page', 'value' => 'sn-theme-options' ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_action', 'value' => 'analytics_export' ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_range', 'value' => $range ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_class', 'value' => $class ) );
	if ( 'custom' === $range ) {
		$export_hidden .= \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_from', 'value' => $from ) )
			. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_to', 'value' => $to ) );
	}
	$export = '<form class="snt-export" method="post" action="' . \snt_kit_esc( $admin ) . '" target="_blank" rel="noopener">'
		. $export_hidden
		. '<button type="submit" name="format" value="csv">CSV</button> '
		. '<button type="submit" name="format" value="json">JSON</button></form>';

	$sep = '';
	$class_totals = function_exists( 'sn_analytics_class_totals' ) ? sn_analytics_class_totals( $from, $to ) : array();
	$bot          = (int) ( $class_totals['bot']['views'] ?? 0 );
	$suspect      = (int) ( $class_totals['suspect']['views'] ?? 0 );
	if ( ( $bot + $suspect ) > 0 ) {
		$sep = '<span class="snt-hint">' . \snt_kit_esc( sprintf(
			/* translators: 1: automated view count, 2: bot view count, 3: suspect view count. */
			__( '%1$s automated filtered (%2$s bot · %3$s suspect)', 'signal-and-noise-tools' ),
			num( $bot + $suspect ),
			num( $bot ),
			num( $suspect )
		) ) . '</span>';
	}

	return '<header class="os-app-list__toolbar snt-report-toolbar">'
		. '<div class="os-app-list__toolbar-left snt-report-toolbar__filters">' . $range_control . $class_control . $compare_control . '</div>'
		. '<div class="os-app-list__toolbar-trailing snt-report-toolbar__trailing">' . $sep . $export . '</div>'
		. '</header>'
		. ( '' !== $custom ? '<div class="snt-custom-panel">' . $custom . '</div>' : '' );
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/controls'] = __NAMESPACE__ . '\\paint_chrome_controls';
		return $painters;
	}
);
