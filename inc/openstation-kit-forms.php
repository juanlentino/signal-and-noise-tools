<?php
/**
 * Signal & Noise Tools — forms, fields and triggers painted from the kit.
 *
 * A leaf's classic `<form method="post" action="admin-post.php">` becomes
 * `<os-form os-action="post">`: the kit collects every `[name]` descendant
 * (kit fields included) and the runtime ships them as `$args['values']`,
 * which the host's admin-post pipeline already understands: `action`, the
 * nonce minted for that action, the `admin_post_sn_<action>` hook, the flash.
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
 * The nonce the classic form for `$sn_action` carries, `sn_<action>`: one per
 * action, so a token minted for one write verifies for no other (#1614).
 *
 * @param string $sn_action The handler's action name (a key of sn_admin_post_handlers()).
 * @return string
 */
function snt_kit_nonce( $sn_action ) {
	return function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( snt_kit_hook_action( $sn_action ) ) : '';
}

/**
 * The `admin_post_*` suffix for a handler action: `sn_<key>` for a key of
 * sn_admin_post_handlers(), unchanged for a hook passed with its prefix
 * (`sn_prov_runsweep`). The nonce action is the same string.
 *
 * @param string $sn_action Table key or prefixed hook action.
 * @return string
 */
function snt_kit_hook_action( $sn_action ) {
	$sn_action = (string) $sn_action;
	return 0 === strpos( $sn_action, 'sn_' ) ? $sn_action : 'sn_' . $sn_action;
}

/**
 * `<os-form os-action="post">` around painted fields, carrying the action and
 * its nonce as hidden inputs: `action=sn_<action>` for the admin-post
 * pipeline, or `sn_action=<action>` for a form the leaf handles itself
 * (`pipeline` => `inline`). An `action` field routes to admin-post with no
 * declaration (snt_os_host_pipeline_for()). Options: submit (label), columns
 * (auto|1|2|3), confirm (question), danger, pipeline (`rss`|`inline`), class.
 *
 * @param string              $sn_action The handler's action name.
 * @param string              $inner     Painted fields.
 * @param array<string,mixed> $opts      Options.
 * @return string
 */
function snt_kit_form( $sn_action, $inner, array $opts = array() ) {
	$inline = isset( $opts['pipeline'] ) && 'inline' === (string) $opts['pipeline'];
	$hidden = snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => $inline ? 'sn_action' : 'action', 'value' => $inline ? (string) $sn_action : snt_kit_hook_action( $sn_action ) ) )
		. snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => snt_kit_nonce( $sn_action ) ) );
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
 * A label, a read-only value and a hint: the field a value without a name
 * takes (a constant-locked setting, a reveal-once secret, the current login
 * URL). `<os-field-row label>` around the painted value, the hint as the
 * sibling paragraph the music leaf pairs with its locked fields, so the hint
 * may carry inline code (#1600).
 *
 * @param string $label      Label text.
 * @param string $value_html Painted value (an os-code, a disabled os-text-field, a link).
 * @param string $hint_html  Painted hint, '' for none.
 * @return string
 */
function snt_kit_static( $label, $value_html, $hint_html = '' ) {
	return snt_kit_tag( 'os-field-row', array( 'label' => (string) $label ), (string) $value_html )
		. ( '' !== (string) $hint_html ? '<p class="snt-hint">' . $hint_html . '</p>' : '' );
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
			// os-checkbox-label has no description prop; a hint rides the
			// field row around it, a label-less row being valid (#1600).
			$control = snt_kit_tag( 'os-checkbox-label', $common + array( 'value' => (string) ( $opts['value'] ?? '1' ), 'checked' => (bool) $value, 'label' => (string) $label ) );
			if ( '' === (string) ( $opts['hint'] ?? '' ) ) {
				return $control;
			}
			return snt_kit_tag( 'os-field-row', array( 'hint' => (string) $opts['hint'] ), $control );
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
