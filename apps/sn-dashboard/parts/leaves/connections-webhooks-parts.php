<?php
/**
 * S&N Dashboard — Connections → Webhooks: the leaf's parts.
 *
 * Every piece the classic tab paints (inc/webhooks-admin.php), as kit
 * markup: the per-webhook editor (the `webhook_update` form, the
 * `webhook_delete` form with the same confirm, the reveal-once secret, the
 * delivery log), the `webhook_add` form, the `monitoring_save` form's token
 * fields (mirroring sn_uptime_status_token_field_html() and
 * sn_spend_watch_settings_fields_html(), which emit wp-admin markup), the
 * rail's status box and the payload reference.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The webhook whose secret is shown once. The classic page reads
 * `$_GET['new_id']`, which inc/admin-page.php derives from the `wh_added_` /
 * `wh_rotated_` flash code; the window keeps that code in state.
 *
 * @param array<string,mixed> $ctx Leaf context.
 * @return string
 */
function webhooks_new_id( array $ctx ) {
	$state = $ctx['state'] ?? null;
	$flash = is_object( $state ) && method_exists( $state, 'get' ) ? (string) $state->get( 'flash' ) : '';
	foreach ( array( 'wh_added_', 'wh_rotated_' ) as $prefix ) {
		if ( 0 === strpos( $flash, $prefix ) ) {
			return sanitize_text_field( substr( $flash, strlen( $prefix ) ) );
		}
	}
	return '';
}

/**
 * Inline code inside prose (the classic `<code>`).
 *
 * @param string $text Code text.
 * @return string
 */
function webhooks_code( $text ) {
	return \snt_kit_code( $text, false );
}

/**
 * A label, a read-only value and a hint: the shape a field without a name
 * takes (the classic's unnamed readonly inputs).
 *
 * @param string $label      Label text.
 * @param string $value_html Painted value.
 * @param string $hint_html  Painted hint.
 * @return string
 */
function webhooks_static( $label, $value_html, $hint_html ) {
	return '<div class="snt-field-static"><span class="snt-field-static__k">' . \snt_kit_esc( $label ) . '</span>'
		. $value_html . '<span class="snt-field-static__hint">' . $hint_html . '</span></div>';
}

/**
 * A native `<form>` for `webhook_update` / `webhook_add`: the `events[]`
 * group in this form (and, riding along, `enabled`/`rotate_secret`) must be
 * native inputs so a real `FormData` carries them — `<os-form>` collects its
 * values by name, later-wins, and reads every checkbox as a boolean
 * (os-form.ts `getValues()`/`_readField()`, OpenStation 1.1.6), which would
 * collapse four `events[]` boxes into one and turn `enabled`/`rotate_secret`
 * into booleans under a name FormData never sees. Same fix as
 * content-tags-parts.php's `tags_form()` for the same mechanic on another leaf.
 * STYLING GAP, LEFT DELIBERATELY, same as there: `.snt-form--native` and
 * `.snt-submit` carry no rule in either stylesheet (`.snt-list*` DOES —
 * assets/os-app.css:49-92 — so that part of the original comment was wrong),
 * and fixing the native-form gap needs a follow-up pass on assets outside
 * this leaf's allowed files.
 *
 * @param string               $sn_action Handler action.
 * @param array<string,string> $hidden    Extra hidden fields (e.g. webhook_id).
 * @param string               $inner     Painted fields.
 * @param string               $submit    Submit label.
 * @return string
 */
function webhooks_native_form( $sn_action, array $hidden, $inner, $submit ) {
	$fields = \snt_kit_field( 'hidden', 'sn_action', '', $sn_action )
		. \snt_kit_field( 'hidden', '_wpnonce', '', \snt_kit_nonce() );
	foreach ( $hidden as $name => $value ) {
		$fields .= \snt_kit_field( 'hidden', (string) $name, '', (string) $value );
	}
	return \snt_kit_tag(
		'form',
		array(
			'class'     => 'snt-form snt-form--native',
			'method'    => 'post',
			'os-action' => 'post',
		),
		$fields . $inner . \snt_kit_tag( 'button', array( 'type' => 'submit', 'class' => 'snt-submit' ), \snt_kit_esc( $submit ) )
	);
}

/**
 * A native checkbox row: `<label><input type=checkbox …> text</label>`, the
 * shape `FormData` (and the classic `<label><input>…</label>`) both read.
 * No `.snt-list__label` class on the `<label>` itself — that token is
 * `overflow:hidden; text-overflow:ellipsis; white-space:nowrap`
 * (assets/os-app.css:69-77), which truncates the longer event sentences
 * ("post.unpublished. Unpublished (publish → draft/pending/private)") instead
 * of wrapping them the way the classic `.snt-checkbox-row` did. `.snt-list__row`
 * still wraps each event `<li>` for spacing.
 *
 * @param string $name       Field name (with its `[]` where repeated).
 * @param string $value      Value attribute.
 * @param bool   $checked    Checked state.
 * @param string $label_html Painted, already-escaped label HTML.
 * @return string
 */
function webhooks_native_checkbox( $name, $value, $checked, $label_html ) {
	return '<label>'
		. \snt_kit_tag( 'input', array( 'type' => 'checkbox', 'name' => (string) $name, 'value' => (string) $value, 'checked' => (bool) $checked ) )
		. ' ' . $label_html . '</label>';
}

/**
 * The four event checkboxes, as native `events[]` inputs, under one labelled
 * row — see webhooks_native_form() for why these cannot be kit checkboxes.
 *
 * @param string[] $selected Subscribed event keys.
 * @param string   $hint     Row hint.
 * @return string
 */
function webhooks_events_fields( array $selected, $hint ) {
	$boxes = '';
	foreach ( \sn_webhook_events() as $key => $label ) {
		$boxes .= '<li class="snt-list__row">' . webhooks_native_checkbox( 'events[]', $key, in_array( $key, $selected, true ), \snt_kit_esc( $key . '. ' . $label ) ) . '</li>';
	}
	return \snt_kit_tag(
		'os-field-row',
		array( 'label' => __( 'Events', 'signal-and-noise-tools' ), 'hint' => (string) $hint ),
		'<ul class="snt-list">' . $boxes . '</ul>'
	);
}

/**
 * The signing secret: shown once (copyable) for a new or rotated webhook,
 * masked with a rotate checkbox otherwise.
 *
 * @param array<string,mixed> $wh     Webhook entry.
 * @param bool                $is_new Whether the secret is revealed.
 * @return string
 */
function webhooks_secret_html( array $wh, $is_new ) {
	$label = __( 'Signing secret', 'signal-and-noise-tools' );
	if ( $is_new ) {
		return webhooks_static(
			$label,
			\snt_kit_tag( 'os-code', array( 'copy' => true ), \snt_kit_esc( (string) ( $wh['secret'] ?? '' ) ) ),
			'<b>' . \snt_kit_esc( __( 'Copy this now', 'signal-and-noise-tools' ) ) . '</b>' . \snt_kit_esc( __( ': it will not be shown again. Receivers compute ', 'signal-and-noise-tools' ) )
			. webhooks_code( 'HMAC_SHA256(secret, raw_body)' ) . \snt_kit_esc( __( ' and compare against the ', 'signal-and-noise-tools' ) ) . webhooks_code( 'X-SN-Signature' ) . \snt_kit_esc( __( ' header.', 'signal-and-noise-tools' ) )
		);
	}
	return webhooks_static(
		$label,
		webhooks_code( \sn_mask_secret( (string) ( $wh['secret'] ?? '' ) ) ),
		\snt_kit_esc( __( 'Last 4 chars shown. Tick "Rotate" below + save to generate a new secret (invalidates the current one).', 'signal-and-noise-tools' ) )
	) . webhooks_native_checkbox( 'rotate_secret', '1', false, \snt_kit_esc( __( 'Rotate secret on save', 'signal-and-noise-tools' ) ) );
}

/**
 * The delete form: the classic's second submit button in the same form, as
 * its own `<os-form>` carrying the same confirm (question, title, label,
 * danger — the `os-confirm-*` family of the view vocabulary) and the id the
 * handler reads. Hand-tagged because snt_kit_form() has no title/label options.
 *
 * @param string $id Webhook id.
 * @return string
 */
function webhooks_delete_form( $id ) {
	$hidden = \snt_kit_field( 'hidden', 'sn_action', '', 'webhook_delete' )
		. \snt_kit_field( 'hidden', '_wpnonce', '', \snt_kit_nonce() )
		. \snt_kit_field( 'hidden', 'webhook_id', '', $id );
	return \snt_kit_tag(
		'os-form',
		array(
			'class'             => 'snt-form',
			'os-action'         => 'post',
			'submit-label'      => __( 'Delete', 'signal-and-noise-tools' ),
			'show-reset'        => 'false',
			'columns'           => '1',
			'os-confirm'        => __( 'Pending retries will be dropped. This cannot be undone.', 'signal-and-noise-tools' ),
			'os-confirm-title'  => __( 'Delete this webhook?', 'signal-and-noise-tools' ),
			'os-confirm-label'  => __( 'Delete', 'signal-and-noise-tools' ),
			'os-confirm-danger' => true,
		),
		$hidden
	);
}

/**
 * The delivery log, newest first, folded away as the classic `<details>`.
 *
 * The classic table carries `data-webhook-id` and assets/admin-heartbeat.js
 * re-renders it on every Heartbeat tick (T5, v4.9.0) — a live refresh that
 * has no equivalent selector to match once this is `<os-table>`, even though
 * the shell still enqueues `sn-admin-heartbeat` for this window
 * (inc/openstation-host-assets.php:63). The substitute other kit leaves use
 * for the same loss (content-block-migrations.php, tools-provenance.php): a
 * ghost `refresh` button, which repaints the leaf from the server.
 *
 * @param string $id Webhook id.
 * @return string
 */
function webhooks_log_html( $id ) {
	$log = \sn_webhook_log_read( $id );
	if ( empty( $log ) ) {
		return '';
	}
	$rows = array();
	foreach ( array_reverse( $log ) as $entry ) {
		$rows[] = array(
			'fired_at' => wp_date( 'Y-m-d H:i:s', (int) ( $entry['fired_at'] ?? 0 ) ),
			'attempt'  => (string) ( $entry['attempt'] ?? '' ),
			'http'     => (string) ( $entry['response_code'] ?? '' ),
			'status'   => ! empty( $entry['success'] ) ? 'ok' : 'fail',
			'response' => (string) ( $entry['response_excerpt'] ?? '' ),
		);
	}
	$columns = array(
		array( 'key' => 'fired_at', 'label' => __( 'Fired at', 'signal-and-noise-tools' ) ),
		array( 'key' => 'attempt', 'label' => __( 'Attempt', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'http', 'label' => __( 'HTTP', 'signal-and-noise-tools' ), 'align' => 'end' ),
		array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
		array( 'key' => 'response', 'label' => __( 'Response', 'signal-and-noise-tools' ) ),
	);
	return \snt_kit_tag(
		'os-disclosure',
		/* translators: %d delivery-log entries */
		array( 'heading' => sprintf( __( 'Recent deliveries (%d)', 'signal-and-noise-tools' ), count( $log ) ) ),
		\snt_kit_table( $columns, $rows )
			. \snt_kit_button( __( 'Refresh', 'signal-and-noise-tools' ), 'refresh', array( 'variant' => 'ghost' ) )
	);
}

/**
 * One token field of the monitoring form: constant-locked → a read-only
 * `••••` naming the constant (no name, as the classic's disabled input);
 * otherwise the obscured value, paste a fresh one to update.
 *
 * @param string $name        Field name.
 * @param string $label       Label.
 * @param string $const       Locking wp-config constant.
 * @param string $opt         Option holding the token.
 * @param string $help        Hint.
 * @param string $placeholder Placeholder.
 * @return string
 */
function webhooks_token_field( $name, $label, $const, $opt, $help, $placeholder ) {
	if ( defined( $const ) && constant( $const ) ) {
		return webhooks_static(
			$label,
			webhooks_code( '••••' ),
			'<b>' . \snt_kit_esc( __( 'Locked.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( __( 'Set via', 'signal-and-noise-tools' ) ) . ' ' . webhooks_code( $const ) . ' ' . \snt_kit_esc( __( 'in', 'signal-and-noise-tools' ) ) . ' ' . webhooks_code( 'wp-config.php' ) . '.'
		);
	}
	$obscured = function_exists( 'sn_mask_secret' ) ? \sn_mask_secret( (string) get_option( $opt, '' ) ) : '';
	return \snt_kit_field( 'text', $name, $label, $obscured, array( 'placeholder' => (string) $placeholder, 'hint' => (string) $help ) );
}

/**
 * The rail's status box: how many webhooks, how many enabled, and a pill.
 *
 * @param int $total   Configured webhooks.
 * @param int $enabled Enabled webhooks.
 * @return string
 */
function webhooks_status_html( $total, $enabled ) {
	if ( $total > 0 ) {
		$kind  = $enabled > 0 ? 'ok' : 'warn';
		/* translators: %d configured webhooks */
		$title = sprintf( _n( '%d webhook configured', '%d webhooks configured', $total, 'signal-and-noise-tools' ), $total );
		/* translators: 1: enabled webhooks, 2: disabled webhooks */
		$body  = sprintf( __( '%1$d enabled, %2$d disabled. Deliveries fire on each webhook’s subscribed post/page events (published, updated, unpublished, deleted).', 'signal-and-noise-tools' ), $enabled, $total - $enabled );
		$pill  = $enabled > 0 ? __( 'Active', 'signal-and-noise-tools' ) : __( 'Inactive', 'signal-and-noise-tools' );
	} else {
		$kind  = 'warn';
		$title = __( 'No webhooks configured', 'signal-and-noise-tools' );
		$body  = __( 'Add one in the main column to start receiving signed post-publish notifications at your own endpoint.', 'signal-and-noise-tools' );
		$pill  = __( 'Inactive', 'signal-and-noise-tools' );
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_badge( $kind, $pill ) . '<br>' . \snt_kit_esc( $body ) );
}
