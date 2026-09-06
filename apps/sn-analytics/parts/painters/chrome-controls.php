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

	$rolling = array( '7' => '7d', '14' => '14d', '30' => '30d', '90' => '90d', '365' => '1y', 'all' => __( 'All', 'signal-and-noise-tools' ) );
	$range_row = '<span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Range', 'signal-and-noise-tools' ) ) . '</span>';
	foreach ( $rolling as $token => $label ) {
		$range_row .= pick( $label, 'range', $token, $token === $range );
	}
	if ( function_exists( 'snt_analytics_preset_labels' ) ) {
		foreach ( snt_analytics_preset_labels() as $token => $label ) {
			$range_row .= pick( $label, 'range', (string) $token, (string) $token === $range );
		}
	}
	$range_row .= pick( __( 'Custom', 'signal-and-noise-tools' ), 'range', 'custom', 'custom' === $range );

	$class_row = '<span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Class', 'signal-and-noise-tools' ) ) . '</span>';
	foreach ( array( 'human' => __( 'Human', 'signal-and-noise-tools' ), 'suspect' => __( 'Suspect', 'signal-and-noise-tools' ), 'bot' => __( 'Bot', 'signal-and-noise-tools' ) ) as $token => $label ) {
		$class_row .= pick( $label, 'class', $token, $token === $class );
	}

	$compare_row = '<span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Compare', 'signal-and-noise-tools' ) ) . '</span>';
	foreach ( array( 'off' => __( 'Off', 'signal-and-noise-tools' ), 'prev' => __( 'Previous', 'signal-and-noise-tools' ), 'yoy' => __( 'Year over year', 'signal-and-noise-tools' ) ) as $token => $label ) {
		$compare_row .= pick( $label, 'compare', $token, $token === $compare );
	}

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

	return '<div class="snt-toolbar">'
		. '<div class="snt-toolbar__row snt-toolbar__row--range"><div class="snt-toolbar__group">' . $range_row . '</div>' . $custom . '</div>'
		. '<div class="snt-toolbar__row snt-toolbar__row--secondary">'
		. '<div class="snt-toolbar__group">' . $class_row . '</div>'
		. '<div class="snt-toolbar__group">' . $compare_row . '</div>'
		. '<div class="snt-toolbar__group snt-toolbar__export"><span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Export', 'signal-and-noise-tools' ) ) . '</span>' . $export . '</div>'
		. $sep . '</div>'
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/controls'] = __NAMESPACE__ . '\\paint_chrome_controls';
		return $painters;
	}
);
