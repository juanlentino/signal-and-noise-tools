<?php
/**
 * Signal & Noise Tools — Webhooks admin tab.
 *
 * Render-only. Form actions (webhook_add / webhook_update /
 * webhook_delete) route through inc/admin-page.php's
 * sn_handle_admin_post dispatcher — same shared-nonce pattern
 * (sn_theme_options_nonce) and PRG flow as every other SN tab.
 *
 * Uses the bespoke .sn-fieldset / .sn-field / .sn-card-grid design
 * system (matches cloudflare-purge.php, plausible-admin.php).
 *
 * @package SignalNoiseTools
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_webhooks_tab', 'sn_webhooks_render_admin_tab' );

function sn_webhooks_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$webhooks = sn_webhooks_all();
	$new_id   = isset( $_GET['new_id'] ) ? sanitize_text_field( wp_unslash( $_GET['new_id'] ) ) : '';

	// ── INTRO ──
	echo '<p class="sn-prose">POST a signed JSON payload to your own endpoints (n8n, Zapier, Pipedream, anything that accepts webhooks) on post/page lifecycle events — published, updated, unpublished, or deleted. Each webhook subscribes to the events you choose. Every delivery is HMAC-SHA256 signed; receivers should verify the <code>X-SN-Signature</code> header before acting.</p>';

	// Phase 3 (v6.45.0): full-width two-column shell — the webhook editor + add +
	// uptime forms (the work) in the main column; the at-a-glance status and the
	// payload reference in the rail.
	$enabled_count = 0;
	foreach ( $webhooks as $wh ) {
		if ( ! empty( $wh['enabled'] ) ) {
			$enabled_count++;
		}
	}
	$total = count( $webhooks );

	sn_admin_shell_open();

	// ── MAIN: EXISTING WEBHOOKS — one fieldset per entry ──
	foreach ( $webhooks as $wh ) {
		$is_new = ( $new_id === $wh['id'] );

		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="webhook_id" value="' . esc_attr( $wh['id'] ) . '">';

		echo '<div class="sn-fieldset' . ( $is_new ? ' sn-fieldset--new' : '' ) . '">';
		echo '<h2 class="sn-fieldset-h">' . esc_html( $wh['name'] ) . '</h2>';
		echo '<p class="sn-fieldset-intro"><code>' . esc_html( $wh['id'] ) . '</code> — created ' . esc_html( wp_date( 'Y-m-d', (int) ( $wh['created_at'] ?? 0 ) ) ) . '</p>';

		// Name
		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="wh_name_' . esc_attr( $wh['id'] ) . '">Name</label>';
		echo '<input type="text" id="wh_name_' . esc_attr( $wh['id'] ) . '" name="name" value="' . esc_attr( $wh['name'] ) . '">';
		echo '</div>';

		// URL
		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label" for="wh_url_' . esc_attr( $wh['id'] ) . '">Endpoint URL</label>';
		echo '<input type="url" id="wh_url_' . esc_attr( $wh['id'] ) . '" name="url" value="' . esc_attr( $wh['url'] ) . '" class="sn-mono">';
		echo '<p class="sn-field-helper">Receiving endpoint. Must respond with 2xx within 10 seconds. 5xx triggers retry (3 attempts, 5min backoff); 4xx is a hard rejection.</p>';
		echo '</div>';

		// Enabled
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Status</label>';
		echo '<label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $wh['enabled'] ), true, false ) . '> Enabled — deliveries fire on the events below</label>';
		echo '</div>';

		// Events — per-webhook subscription. Default (no events key) = post.published only.
		$selected = isset( $wh['events'] ) ? (array) $wh['events'] : array( 'post.published' );
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Events</label>';
		foreach ( sn_webhook_events() as $event_key => $event_label ) {
			echo '<label class="snt-checkbox-row"><input type="checkbox" name="events[]" value="' . esc_attr( $event_key ) . '"' . checked( in_array( $event_key, $selected, true ), true, false ) . '> <code>' . esc_html( $event_key ) . '</code> — ' . esc_html( $event_label ) . '</label>';
		}
		echo '<p class="sn-field-helper">Pick which post-lifecycle events POST to this endpoint. If none are ticked, <code>post.published</code> is used.</p>';
		echo '</div>';

		// Secret
		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label">Signing secret</label>';
		if ( $is_new ) {
			echo '<input type="text" readonly value="' . esc_attr( $wh['secret'] ) . '" class="sn-mono snt-input-highlight">';
			echo '<p class="sn-field-helper"><strong>Copy this now</strong> — it will not be shown again. Receivers compute <code>HMAC_SHA256(secret, raw_body)</code> and compare against the <code>X-SN-Signature</code> header.</p>';
		} else {
			echo '<input type="text" readonly value="' . esc_attr( sn_mask_secret( $wh['secret'] ) ) . '" class="sn-mono" disabled>';
			echo '<p class="sn-field-helper">Last 4 chars shown. Tick "Rotate" below + save to generate a new secret (invalidates the current one).</p>';
			echo '<label class="snt-checkbox-row"><input type="checkbox" name="rotate_secret" value="1"> Rotate secret on save</label>';
		}
		echo '</div>';

		echo '<div class="sn-fieldset-actions">';
		echo '<button type="submit" name="sn_action" value="webhook_update" class="button button-primary">Save changes</button>';
		// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm attribute.
		echo ' <button type="submit" name="sn_action" value="webhook_delete" class="button button-link-delete" data-snt-confirm="' . esc_attr__( 'Pending retries will be dropped. This cannot be undone.', 'signal-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Delete this webhook?', 'signal-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Delete', 'signal-noise-tools' ) . '" data-snt-confirm-danger="1">Delete</button>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// Delivery log — inline disclosure under the fieldset.
		$log = sn_webhook_log_read( $wh['id'] );
		if ( ! empty( $log ) ) {
			echo '<details class="sn-prose snt-mb-1">';
			echo '<summary>Recent deliveries (' . count( $log ) . ')</summary>';
			echo '<div class="snt-scroll-table">';
			// data-webhook-id: target for the v4.9.0 Heartbeat live-refresh JS (T5).
			echo '<table class="widefat striped snt-table-log" data-webhook-id="' . esc_attr( $wh['id'] ) . '"><thead><tr>';
			echo '<th scope="col">Fired at</th>';
			echo '<th scope="col">Attempt</th>';
			echo '<th scope="col">HTTP</th>';
			echo '<th scope="col">Status</th>';
			echo '<th scope="col">Response</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_reverse( $log ) as $entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['fired_at'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $entry['attempt'] ) . '</td>';
				echo '<td>' . esc_html( (string) $entry['response_code'] ) . '</td>';
				echo '<td>' . ( ! empty( $entry['success'] ) ? '<span class="sn-pill sn-pill--ok">ok</span>' : '<span class="sn-pill sn-pill--warn">fail</span>' ) . '</td>';
				echo '<td><code>' . esc_html( (string) $entry['response_excerpt'] ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
			echo '</details>';
		}

		echo '</form>';
	}

	// ── ADD NEW ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Add a webhook</h2>';
	echo '<p class="sn-fieldset-intro">A signing secret is generated automatically and shown once after save.</p>';

	echo '<div class="sn-field sn-field-w-md">';
	echo '<label class="sn-field-label" for="wh_new_name">Name</label>';
	echo '<input type="text" id="wh_new_name" name="name" placeholder="My n8n flow">';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="wh_new_url">Endpoint URL</label>';
	echo '<input type="url" id="wh_new_url" name="url" placeholder="https://" class="sn-mono">';
	echo '<p class="sn-field-helper">Must be <code>https://</code>. Receiver should respond with 2xx within 10 seconds.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Events</label>';
	foreach ( sn_webhook_events() as $event_key => $event_label ) {
		// post.published pre-checked; the others opt-in.
		$default_checked = ( 'post.published' === $event_key );
		echo '<label class="snt-checkbox-row"><input type="checkbox" name="events[]" value="' . esc_attr( $event_key ) . '"' . checked( $default_checked, true, false ) . '> <code>' . esc_html( $event_key ) . '</code> — ' . esc_html( $event_label ) . '</label>';
	}
	echo '<p class="sn-field-helper">If none are ticked, <code>post.published</code> is used.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label><input type="checkbox" name="enabled" value="1" checked> Start dispatching immediately</label>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="webhook_add" class="button button-primary">Add webhook</button>';
	echo '</div>';

	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── UPTIME MONITORING (v4.9.0, T4; provider-neutral copy since v8.1.6) ──
	// The `uptime_kuma_*` setting keys and POST field names are historical
	// and deliberately kept through the Better Stack migration — renaming
	// keys is a settings-schema change (SemVer break without a migration).
	$kuma_enabled = (bool) sn_setting( 'monitoring.uptime_kuma_enabled', false );
	$kuma_url     = (string) sn_setting( 'monitoring.uptime_kuma_push_url', '' );

	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Uptime monitoring</h2>';
	echo '<p class="sn-fieldset-intro">Push a heartbeat every 5 minutes to an external heartbeat monitor — a <a href="https://betterstack.com/docs/uptime/cron-and-heartbeat-monitor/" target="_blank" rel="noopener noreferrer">Better Stack heartbeat</a> or an <a href="https://github.com/louislam/uptime-kuma" target="_blank" rel="noopener noreferrer">Uptime Kuma</a> push monitor. If WP-Cron stops firing (or the site goes down), the monitor stops receiving the heartbeat and raises an incident.</p>';

	echo '<div class="sn-field sn-field-w-lg">';
	echo '<label class="sn-field-label" for="kuma_push_url">Heartbeat URL</label>';
	echo '<input type="url" id="kuma_push_url" name="uptime_kuma_push_url" value="' . esc_attr( $kuma_url ) . '" placeholder="https://uptime.betterstack.com/api/v1/heartbeat/&lt;token&gt;" class="sn-mono">';
	echo '<p class="sn-field-helper">The heartbeat URL from your monitoring service (Better Stack heartbeat, or Uptime Kuma <code>Push</code> monitor). <code>status=up</code> is appended automatically — Kuma expects it, Better Stack ignores it. Must be <code>https://</code>.</p>';
	echo '</div>';

	// v8.2.0: Uptime API token — powers the in-admin status panel (the rail
	// on this tab + the S&N Uptime dashboard widget). Saved by the same
	// monitoring_save action; render + masking live in inc/uptime-status.php.
	if ( function_exists( 'sn_uptime_status_token_field_html' ) ) {
		echo sn_uptime_status_token_field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
	}

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Status</label>';
	echo '<label><input type="checkbox" name="uptime_kuma_enabled" value="1"' . checked( $kuma_enabled, true, false ) . '> Enabled — send a heartbeat every 5 minutes</label>';
	echo '</div>';

	// Last-ping status line (read-only).
	$last_ping = get_transient( 'sn_uptime_last_ping' );
	if ( is_array( $last_ping ) && ! empty( $last_ping['ts'] ) ) {
		$pill = ! empty( $last_ping['ok'] ) ? 'sn-pill--ok' : 'sn-pill--warn';
		echo '<p class="sn-field-helper">Last heartbeat: ' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $last_ping['ts'] ) ) . ' <span class="sn-pill ' . esc_attr( $pill ) . '">HTTP ' . esc_html( (string) ( $last_ping['code'] ?? 0 ) ) . '</span></p>';
	}

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="monitoring_save" class="button button-primary">Save monitoring</button>';
	echo '</div>';
	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── RAIL: at-a-glance status + payload reference ──
	sn_admin_shell_rail( 'Status & reference' );

	if ( $total > 0 ) {
		$pill_kind = $enabled_count > 0 ? 'ok' : 'warn';
		echo '<div class="sn-status-box' . ( 'ok' === $pill_kind ? '' : ' sn-status-box--warn' ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">' . esc_html( $total ) . ' webhook' . ( 1 === $total ? '' : 's' ) . ' configured</p>';
		echo '<p class="sn-status-box-body">' . esc_html( $enabled_count ) . ' enabled, ' . esc_html( $total - $enabled_count ) . ' disabled. Deliveries fire on each webhook&rsquo;s subscribed post/page events (published, updated, unpublished, deleted).</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' . esc_html( $enabled_count > 0 ? 'Active' : 'Inactive' ) . '</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">No webhooks configured</p>';
		echo '<p class="sn-status-box-body">Add one in the main column to start receiving signed post-publish notifications at your own endpoint.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	// ── BETTER STACK STATUS (v8.2.0) ── async panel; renders only when a
	// token is configured (unconfigured admins get the field helper above,
	// not a dead box). Data loads via the signal-noise/uptime-status ability
	// (assets/uptime-status.js) — this render costs nothing.
	if ( function_exists( 'sn_uptime_status_configured' ) && sn_uptime_status_configured() ) {
		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">Better Stack status</h2>';
		echo sn_uptime_status_mount_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
		echo '</div>';
	}

	// ── PAYLOAD REFERENCE ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Payload reference</h2>';
	echo '<p class="sn-fieldset-intro">Every delivery is a POST with these headers and a JSON body. The <code>X-SN-Event</code> header and the body&rsquo;s <code>event</code> field are one of <code>post.published</code>, <code>post.updated</code>, <code>post.unpublished</code>, or <code>post.deleted</code>. For unpublished/deleted events the <code>post</code> block is a snapshot captured at trigger time (the post may already be gone).</p>';
	echo '<pre class="sn-mono snt-pre-payload">POST &lt;your URL&gt; HTTP/1.1
Content-Type: application/json
X-SN-Event: post.published
X-SN-Delivery: del_…
X-SN-Attempt: 1
X-SN-Signature: sha256=&lt;HMAC_SHA256(secret, body)&gt;
User-Agent: SignalNoiseTools/' . esc_html( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' webhook

{
  "event": "post.published",
  "delivery_id": "del_…",
  "delivered_at": 1234567890,
  "site": "' . esc_html( home_url( '/' ) ) . '",
  "post": {
    "id": 123,
    "title": "…",
    "slug": "…",
    "url": "…",
    "author_id": 1,
    "published_at": 1234567890,
    "type": "post"
  }
}</pre>';
	echo '<p class="sn-field-helper">Receivers should verify <code>X-SN-Signature</code> before trusting the body. Failures (network or HTTP 5xx) retry up to 3 times with 5-minute backoff; HTTP 4xx is a hard rejection.</p>';
	echo '</div>';

	sn_admin_shell_close();
}
