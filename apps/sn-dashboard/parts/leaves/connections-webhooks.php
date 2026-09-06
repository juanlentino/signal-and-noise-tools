<?php
/**
 * S&N Dashboard — Connections → Webhooks, painted from the kit.
 *
 * The classic leaf (inc/webhooks-admin.php, `sn_webhooks_render_admin_tab()`
 * behind `sn_admin_render_webhooks_section()`) paints an intro, then a
 * two-column shell: one editor per webhook (`webhook_update` + `webhook_delete`
 * on the same fields, the reveal-once secret, the delivery log), the
 * `webhook_add` form and the `monitoring_save` form in the main column; the
 * status box, the Better Stack panel and the payload reference in the rail.
 * Same readers, same forms, same field names, same handlers; the kit's parts
 * instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/connections-webhooks-parts.php';

/**
 * One webhook's editor: the id line, the update form, the delete form, the log.
 *
 * @param array<string,mixed> $wh     Webhook entry.
 * @param bool                $is_new Whether its secret is shown once.
 * @return string
 */
function webhooks_entry_html( array $wh, $is_new ) {
	$id       = (string) ( $wh['id'] ?? '' );
	$selected = isset( $wh['events'] ) ? (array) $wh['events'] : array( 'post.published' );
	$fields   = \snt_kit_field( 'text', 'name', __( 'Name', 'signal-and-noise-tools' ), (string) ( $wh['name'] ?? '' ) )
		. \snt_kit_field( 'url', 'url', __( 'Endpoint URL', 'signal-and-noise-tools' ), (string) ( $wh['url'] ?? '' ), array( 'hint' => __( 'Receiving endpoint. Must respond with 2xx within 10 seconds. 5xx triggers retry (3 attempts, 5min backoff); 4xx is a hard rejection.', 'signal-and-noise-tools' ) ) )
		. \snt_kit_tag( 'os-field-row', array( 'label' => __( 'Status', 'signal-and-noise-tools' ) ), webhooks_native_checkbox( 'enabled', '1', ! empty( $wh['enabled'] ), \snt_kit_esc( __( 'Enabled: deliveries fire on the events below', 'signal-and-noise-tools' ) ) ) )
		. webhooks_events_fields( $selected, __( 'Pick which post-lifecycle events POST to this endpoint. If none are ticked, post.published is used.', 'signal-and-noise-tools' ) )
		. webhooks_secret_html( $wh, $is_new );
	// The classic marks the just-saved fieldset with `.sn-fieldset--new` /
	// `.snt-input-highlight` (inc/webhooks-admin.php:59,96); the kit form has
	// no per-field emphasis, so a badge in the same spot is the nearest
	// faithful substitute for the "you just did this" signal.
	$inner = ( $is_new ? \snt_kit_badge( 'info', __( 'New', 'signal-and-noise-tools' ) ) : '' )
		. '<p class="snt-hint">' . webhooks_code( $id ) . \snt_kit_esc( ': ' . __( 'created', 'signal-and-noise-tools' ) . ' ' . wp_date( 'Y-m-d', (int) ( $wh['created_at'] ?? 0 ) ) ) . '</p>'
		. webhooks_native_form( 'webhook_update', array( 'webhook_id' => $id ), $fields, __( 'Save changes', 'signal-and-noise-tools' ) )
		. webhooks_delete_form( $id )
		. webhooks_log_html( $id );
	return \snt_kit_section( (string) ( $wh['name'] ?? '' ), $inner );
}

/**
 * The add form: name, URL, events (post.published pre-ticked), enabled.
 *
 * @return string
 */
function webhooks_add_html() {
	$fields = \snt_kit_field( 'text', 'name', __( 'Name', 'signal-and-noise-tools' ), '', array( 'placeholder' => 'My n8n flow' ) )
		. \snt_kit_field( 'url', 'url', __( 'Endpoint URL', 'signal-and-noise-tools' ), '', array( 'placeholder' => 'https://', 'hint' => __( 'Must be https://. Receiver should respond with 2xx within 10 seconds.', 'signal-and-noise-tools' ) ) )
		. webhooks_events_fields( array( 'post.published' ), __( 'If none are ticked, post.published is used.', 'signal-and-noise-tools' ) )
		. webhooks_native_checkbox( 'enabled', '1', true, \snt_kit_esc( __( 'Start dispatching immediately', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section(
		__( 'Add a webhook', 'signal-and-noise-tools' ),
		webhooks_native_form( 'webhook_add', array(), $fields, __( 'Add webhook', 'signal-and-noise-tools' ) ),
		__( 'A signing secret is generated automatically and shown once after save.', 'signal-and-noise-tools' )
	);
}

/**
 * The monitoring form: the Better Stack token, then the Spend-watch
 * credentials when that module is present — the fields
 * sn_uptime_status_token_field_html() emits, mirrored.
 *
 * @return string
 */
function webhooks_monitoring_html() {
	$fields = '';
	if ( function_exists( 'sn_uptime_status_token_field_html' ) ) {
		$fields = webhooks_token_field( 'sn_betterstack_token', __( 'Better Stack API token (optional)', 'signal-and-noise-tools' ), 'SN_BETTERSTACK_API_TOKEN', SN_UPTIME_STATUS_TOKEN_OPT, __( 'Uptime API token (read scope is enough). Powers the in-admin status panel: the dashboard widget and the rail on this tab. Leave the obscured value alone to keep the existing token.', 'signal-and-noise-tools' ), __( 'Paste a fresh token to update; type \'clear\' to remove', 'signal-and-noise-tools' ) );
		if ( function_exists( 'sn_spend_watch_settings_fields_html' ) ) {
			$paste   = __( 'Paste a fresh value to update; type \'clear\' to remove', 'signal-and-noise-tools' );
			$fields .= webhooks_token_field( SN_SPEND_GH_TOKEN_OPT, __( 'GitHub billing token (optional)', 'signal-and-noise-tools' ), 'SN_SPEND_GH_TOKEN', SN_SPEND_GH_TOKEN_OPT, __( 'Classic PAT with the user scope. Powers the account-wide Actions-minutes line in the Health widget. Leave the obscured value alone to keep the existing token.', 'signal-and-noise-tools' ), $paste )
				. webhooks_token_field( SN_SPEND_AI_KEY_OPT, __( 'Anthropic admin key (optional)', 'signal-and-noise-tools' ), 'SN_SPEND_AI_ADMIN_KEY', SN_SPEND_AI_KEY_OPT, __( 'Organization admin key for the cost report. Powers the month-to-date AI-spend line. Leave the obscured value alone to keep the existing key.', 'signal-and-noise-tools' ), $paste );
		}
	}
	return \snt_kit_section(
		__( 'Uptime monitoring', 'signal-and-noise-tools' ),
		\snt_kit_form( 'monitoring_save', $fields, array( 'submit' => __( 'Save monitoring', 'signal-and-noise-tools' ) ) ),
		__( 'Better Stack polls this site from outside and reports here. Add a read-scope Uptime API token to power the status rail on this tab and the S&N Uptime dashboard widget.', 'signal-and-noise-tools' )
	);
}

/**
 * The payload reference: headers, body, and the verification hint.
 *
 * @return string
 */
function webhooks_payload_html() {
	$sample = "POST <your URL> HTTP/1.1\nContent-Type: application/json\nX-SN-Event: post.published\nX-SN-Delivery: del_…\nX-SN-Attempt: 1\nX-SN-Signature: sha256=<HMAC_SHA256(secret, body)>\nUser-Agent: SignalNoiseTools/" . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . " webhook\n\n"
		. "{\n  \"event\": \"post.published\",\n  \"delivery_id\": \"del_…\",\n  \"delivered_at\": 1234567890,\n  \"site\": \"" . home_url( '/' ) . "\",\n  \"post\": {\n    \"id\": 123,\n    \"title\": \"…\",\n    \"slug\": \"…\",\n    \"url\": \"…\",\n    \"author_id\": 1,\n    \"published_at\": 1234567890,\n    \"type\": \"post\"\n  }\n}";
	$intro  = '<p class="snt-prose">' . \snt_kit_esc( __( 'Every delivery is a POST with these headers and a JSON body. The ', 'signal-and-noise-tools' ) ) . webhooks_code( 'X-SN-Event' ) . \snt_kit_esc( __( ' header and the body’s ', 'signal-and-noise-tools' ) ) . webhooks_code( 'event' ) . \snt_kit_esc( __( ' field are one of ', 'signal-and-noise-tools' ) )
		. webhooks_code( 'post.published' ) . ', ' . webhooks_code( 'post.updated' ) . ', ' . webhooks_code( 'post.unpublished' ) . \snt_kit_esc( __( ', or ', 'signal-and-noise-tools' ) ) . webhooks_code( 'post.deleted' )
		. \snt_kit_esc( __( '. For unpublished/deleted events the ', 'signal-and-noise-tools' ) ) . webhooks_code( 'post' ) . \snt_kit_esc( __( ' block is a snapshot captured at trigger time (the post may already be gone).', 'signal-and-noise-tools' ) ) . '</p>';
	$hint   = '<p class="snt-hint">' . \snt_kit_esc( __( 'Receivers should verify ', 'signal-and-noise-tools' ) ) . webhooks_code( 'X-SN-Signature' ) . \snt_kit_esc( __( ' before trusting the body. Failures (network or HTTP 5xx) retry up to 3 times with 5-minute backoff; HTTP 4xx is a hard rejection.', 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_section( __( 'Payload reference', 'signal-and-noise-tools' ), $intro . \snt_kit_code( $sample ) . $hint );
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_webhooks( array $ctx ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$webhooks = \sn_webhooks_all();
	$new_id   = webhooks_new_id( $ctx );
	$enabled  = 0;
	$main     = '';
	foreach ( $webhooks as $wh ) {
		$wh       = (array) $wh;
		$enabled += empty( $wh['enabled'] ) ? 0 : 1;
		$main    .= webhooks_entry_html( $wh, $new_id === (string) ( $wh['id'] ?? '' ) );
	}
	$main .= webhooks_add_html() . webhooks_monitoring_html();

	$rail = webhooks_status_html( count( $webhooks ), $enabled );
	if ( function_exists( 'sn_uptime_status_configured' ) && \sn_uptime_status_configured() && function_exists( 'sn_uptime_status_mount_html' ) ) {
		// The same mount the classic paints; the shell ships assets/uptime-status.js, which fills it on `snt:paint`.
		$rail .= \snt_kit_section( __( 'Better Stack status', 'signal-and-noise-tools' ), \sn_uptime_status_mount_html() );
	}
	$rail .= webhooks_payload_html();

	return '<p class="snt-prose">' . \snt_kit_esc( __( 'POST a signed JSON payload to your own endpoints (n8n, Zapier, Pipedream, anything that accepts webhooks) on post/page lifecycle events: published, updated, unpublished, or deleted. Each webhook subscribes to the events you choose. Every delivery is HMAC-SHA256 signed; receivers should verify the ', 'signal-and-noise-tools' ) ) . webhooks_code( 'X-SN-Signature' ) . \snt_kit_esc( __( ' header before acting.', 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_tag(
			'os-row',
			array( 'gap' => '16' ),
			\snt_kit_tag( 'os-stack', array( 'col' => '8', 'gap' => '12' ), $main )
			. \snt_kit_tag( 'aside', array( 'col' => '4', 'aria-label' => __( 'Status & reference', 'signal-and-noise-tools' ) ), \snt_kit_tag( 'os-stack', array( 'gap' => '12' ), $rail ) )
		);
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/webhooks'] = __NAMESPACE__ . '\\paint_connections_webhooks';
		return $painters;
	}
);
