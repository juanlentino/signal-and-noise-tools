<?php
/**
 * S&N Dashboard, Site → Redirects: the ONE form region below the rule table.
 *
 * 2026-10-11 (no folds): the rule `sn_edit` names gets every write here,
 * headed by the rule: its edit form, its confirmed delete, and a Cancel that
 * clears the param. With no rule open, the add form sits here instead. Never
 * one form per rule, and never a form in a table cell. Required by
 * site-redirects.php.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The edit region for one rule: the redirect_update form, the redirect_delete
 * form (confirmed as the classic Delete button was), and a Cancel.
 *
 * @param string              $source Source path (a key the store holds).
 * @param array<string,mixed> $r      to, status, created_at.
 * @return string
 */
function redirects_edit_html( $source, array $r ) {
	$code   = redirects_code( $r );
	$target = (string) ( $r['to'] ?? '' );
	$fields = \snt_kit_field( 'hidden', 'source', '', $source )
		. \snt_kit_field( 'text', 'target', __( 'Redirects to', 'signal-and-noise-tools' ), $target )
		. \snt_kit_field( 'select', 'status', __( 'Type', 'signal-and-noise-tools' ), $code, array( 'options' => redirects_status_options() ) );
	$delete = redirects_form(
		'redirect_delete',
		\snt_kit_field( 'hidden', 'source', '', $source ),
		array(
			'submit'        => __( 'Delete', 'signal-and-noise-tools' ),
			'confirm'       => __( 'This redirect will stop working immediately.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Delete this redirect?', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Delete', 'signal-and-noise-tools' ),
			'danger'        => true,
		)
	);
	$cancel = \snt_kit_button( __( 'Cancel', 'signal-and-noise-tools' ), 'go', array( 'variant' => 'ghost', 'args' => array( 'sub' => 'redirects', 'anchor' => 'snt-edit-list' ) ) );
	return \snt_kit_section(
		/* translators: %s: the rule's source path. */
		sprintf( __( 'Editing %s', 'signal-and-noise-tools' ), $source ),
		\snt_kit_form( 'redirect_update', $fields, array( 'submit' => __( 'Save changes', 'signal-and-noise-tools' ) ) ) . $delete . $cancel,
		/* translators: 1: HTTP status (301/302), 2: the current target, 3: the date the rule was added (Y-m-d). */
		sprintf( __( 'Now a %1$d to %2$s; added %3$s.', 'signal-and-noise-tools' ), $code, $target, redirects_added( $r, __( 'date unknown', 'signal-and-noise-tools' ) ) ),
		array( 'id' => 'snt-edit-region', 'tabindex' => '-1', 'data-snt-focus' => true )
	);
}

/**
 * The add form.
 *
 * @return string
 */
function redirects_add_html() {
	$fields = \snt_kit_field( 'text', 'source', __( 'From (path on this site)', 'signal-and-noise-tools' ), '', array( 'placeholder' => '/old-page', 'hint' => __( 'The path to match, e.g. /old-page. Trailing slash and query string are ignored.', 'signal-and-noise-tools' ) ) )
		. \snt_kit_field( 'text', 'target', __( 'To (path or full URL)', 'signal-and-noise-tools' ), '', array( 'placeholder' => '/new-page  or  https://example.com/page' ) )
		. \snt_kit_field( 'select', 'status', __( 'Type', 'signal-and-noise-tools' ), 301, array( 'options' => redirects_status_options() ) );
	return \snt_kit_section(
		__( 'Add a redirect', 'signal-and-noise-tools' ),
		\snt_kit_form( 'redirect_add', $fields, array( 'submit' => __( 'Add redirect', 'signal-and-noise-tools' ) ) )
	);
}
