<?php
/**
 * Signal & Noise Tools — forms, fields and triggers painted from the kit.
 *
 * A leaf's classic `<form method="post">` becomes `<os-form os-action="post">`:
 * the kit collects every `[name]` descendant (kit fields included) and the
 * runtime ships them as `$args['values']`, which the host's replay pipeline
 * already understands — `sn_action`, the nonce, the handler table, the flash.
 * A single maintenance button becomes an `<os-button os-action="post">` that
 * carries the same two values as arguments.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic page's nonce, the one the replay verifies.
 *
 * @return string
 */
function snt_kit_nonce() {
	$action = defined( 'SNT_OS_HOST_NONCE' ) ? SNT_OS_HOST_NONCE : 'sn_theme_options_nonce';
	return function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( $action ) : '';
}

/**
 * `<os-form os-action="post">` around painted fields, carrying `sn_action` and
 * the nonce as hidden inputs. Options: submit (label), columns (auto|1|2|3),
 * confirm (question), danger, pipeline (`shared`|`admin-post`|`rss`|`inline`), class.
 *
 * @param string              $sn_action The handler's action name.
 * @param string              $inner     Painted fields.
 * @param array<string,mixed> $opts      Options.
 * @return string
 */
function snt_kit_form( $sn_action, $inner, array $opts = array() ) {
	$hidden = snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_action', 'value' => (string) $sn_action ) )
		. snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => snt_kit_nonce() ) );
	foreach ( (array) ( $opts['hidden'] ?? array() ) as $name => $value ) {
		$hidden .= snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => (string) $name, 'value' => (string) $value ) );
	}
	return snt_kit_tag(
		'os-form',
		array(
			'class'             => trim( 'snt-form ' . (string) ( $opts['class'] ?? '' ) ),
			'os-action'         => 'post',
			'os-arg-pipeline'   => isset( $opts['pipeline'] ) ? (string) $opts['pipeline'] : null,
			'submit-label'      => (string) ( $opts['submit'] ?? __( 'Save', 'signal-and-noise-tools' ) ),
			'show-reset'        => 'false',
			'columns'           => (string) ( $opts['columns'] ?? '1' ),
			'os-confirm'        => isset( $opts['confirm'] ) ? (string) $opts['confirm'] : null,
			'os-confirm-danger' => ! empty( $opts['danger'] ),
		),
		$inner . $hidden
	);
}

/**
 * One labelled field: `<os-field-row>` around the kit control for `$type`
 * (text|email|url|password|number|textarea|select|switch|checkbox|hidden).
 *
 * @param string              $type  Control type.
 * @param string              $name  Field name.
 * @param string              $label Label.
 * @param mixed               $value Current value (bool for switch/checkbox).
 * @param array<string,mixed> $opts  hint, placeholder, required, disabled, readonly, options (select: value => label), min, max, step, rows, maxlength, description (switch), error.
 * @return string
 */
function snt_kit_field( $type, $name, $label, $value = '', array $opts = array() ) {
	$type   = (string) $type;
	$common = array(
		'name'     => (string) $name,
		'disabled' => ! empty( $opts['disabled'] ),
		'readonly' => ! empty( $opts['readonly'] ),
	);
	switch ( $type ) {
		case 'hidden':
			return snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => (string) $name, 'value' => (string) $value ) );
		case 'textarea':
			$control = snt_kit_tag( 'os-textarea', $common + array( 'value' => (string) $value, 'rows' => (string) ( $opts['rows'] ?? 4 ), 'placeholder' => $opts['placeholder'] ?? null, 'maxlength' => $opts['maxlength'] ?? null ) );
			break;
		case 'number':
			$control = snt_kit_tag( 'os-number-field', $common + array( 'value' => (string) $value, 'min' => $opts['min'] ?? null, 'max' => $opts['max'] ?? null, 'step' => $opts['step'] ?? null, 'placeholder' => $opts['placeholder'] ?? null ) );
			break;
		case 'select':
			$options = '';
			foreach ( (array) ( $opts['options'] ?? array() ) as $option_value => $option_label ) {
				$options .= snt_kit_tag( 'os-option', array( 'value' => (string) $option_value ), snt_kit_esc( $option_label ) );
			}
			$control = snt_kit_tag( 'os-select', $common + array( 'value' => (string) $value, 'placeholder' => $opts['placeholder'] ?? null ), $options );
			break;
		case 'switch':
			return snt_kit_tag( 'os-switch', $common + array( 'value' => '1', 'checked' => (bool) $value, 'label' => (string) $label, 'description' => $opts['description'] ?? $opts['hint'] ?? null ) );
		case 'checkbox':
			return snt_kit_tag( 'os-checkbox-label', $common + array( 'value' => (string) ( $opts['value'] ?? '1' ), 'checked' => (bool) $value, 'label' => (string) $label ) );
		default:
			$control = snt_kit_tag( 'os-text-field', $common + array( 'type' => in_array( $type, array( 'email', 'url', 'password', 'search' ), true ) ? $type : 'text', 'value' => (string) $value, 'placeholder' => $opts['placeholder'] ?? null, 'maxlength' => $opts['maxlength'] ?? null, 'autocomplete' => $opts['autocomplete'] ?? null, 'reveal' => 'password' === $type ) );
	}
	return snt_kit_tag(
		'os-field-row',
		array(
			'label'    => (string) $label,
			'hint'     => isset( $opts['hint'] ) ? (string) $opts['hint'] : null,
			'error'    => isset( $opts['error'] ) ? (string) $opts['error'] : null,
			'required' => ! empty( $opts['required'] ),
		),
		$control
	);
}
