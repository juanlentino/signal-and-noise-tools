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
	echo '<p class="sn-prose">POST a signed JSON payload to your own endpoints (n8n, Zapier, Pipedream, anything that accepts webhooks) when a post or page is published. Each delivery is HMAC-SHA256 signed; receivers should verify the <code>X-SN-Signature</code> header before acting.</p>';

	// ── STATUS BOX ──
	$enabled_count = 0;
	foreach ( $webhooks as $wh ) {
		if ( ! empty( $wh['enabled'] ) ) {
			$enabled_count++;
		}
	}
	$total = count( $webhooks );

	if ( $total > 0 ) {
		$pill_kind = $enabled_count > 0 ? 'ok' : 'warn';
		echo '<div class="sn-status-box' . ( 'ok' === $pill_kind ? '' : ' sn-status-box--warn' ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">' . esc_html( $total ) . ' webhook' . ( 1 === $total ? '' : 's' ) . ' configured</p>';
		echo '<p class="sn-status-box-body">' . esc_html( $enabled_count ) . ' enabled, ' . esc_html( $total - $enabled_count ) . ' disabled. Deliveries fire on <code>transition_post_status</code> when a post or page transitions to <code>publish</code>.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill_kind ) . '">' . esc_html( $enabled_count > 0 ? 'Active' : 'Inactive' ) . '</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">No webhooks configured</p>';
		echo '<p class="sn-status-box-body">Add one below to start receiving signed post-publish notifications at your own endpoint.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	// ── EXISTING WEBHOOKS — one fieldset per entry ──
	foreach ( $webhooks as $wh ) {
		$is_new = ( $new_id === $wh['id'] );

		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="webhook_id" value="' . esc_attr( $wh['id'] ) . '">';

		echo '<div class="sn-fieldset"' . ( $is_new ? ' style="border-left:3px solid var(--wp--preset--color--blood, #e00404);"' : '' ) . '>';
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
		echo '<label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $wh['enabled'] ), true, false ) . '> Enabled — deliveries fire on publish</label>';
		echo '</div>';

		// Secret
		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label">Signing secret</label>';
		if ( $is_new ) {
			echo '<input type="text" readonly value="' . esc_attr( $wh['secret'] ) . '" class="sn-mono" style="background:#fffbcc;">';
			echo '<p class="sn-field-helper"><strong>Copy this now</strong> — it will not be shown again. Receivers compute <code>HMAC_SHA256(secret, raw_body)</code> and compare against the <code>X-SN-Signature</code> header.</p>';
		} else {
			echo '<input type="text" readonly value="' . esc_attr( '••••' . substr( $wh['secret'], -4 ) ) . '" class="sn-mono" disabled>';
			echo '<p class="sn-field-helper">Last 4 chars shown. Tick "Rotate" below + save to generate a new secret (invalidates the current one).</p>';
			echo '<label style="margin-top:0.25rem;display:block;"><input type="checkbox" name="rotate_secret" value="1"> Rotate secret on save</label>';
		}
		echo '</div>';

		echo '<div class="sn-fieldset-actions">';
		echo '<button type="submit" name="sn_action" value="webhook_update" class="button button-primary">Save changes</button>';
		echo ' <button type="submit" name="sn_action" value="webhook_delete" class="button button-link-delete" onclick="return confirm(\'Delete this webhook? Pending retries will be dropped.\')">Delete</button>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// Delivery log — inline disclosure under the fieldset.
		$log = sn_webhook_log_read( $wh['id'] );
		if ( ! empty( $log ) ) {
			echo '<details class="sn-prose" style="margin-bottom:1rem;">';
			echo '<summary>Recent deliveries (' . count( $log ) . ')</summary>';
			echo '<table class="widefat striped" style="margin-top:0.5rem; font-size:0.85em;"><thead><tr>';
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
			echo '</tbody></table></details>';
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
	echo '<label><input type="checkbox" name="enabled" value="1" checked> Start dispatching on publish immediately</label>';
	echo '</div>';

	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="webhook_add" class="button button-primary">Add webhook</button>';
	echo '</div>';

	echo '</div>'; // .sn-fieldset
	echo '</form>';

	// ── PAYLOAD REFERENCE ──
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Payload reference</h2>';
	echo '<p class="sn-fieldset-intro">Every delivery is a POST with these headers and a JSON body:</p>';
	echo '<pre class="sn-mono" style="background:#f3f3f3;padding:0.75rem;overflow:auto;font-size:0.85em;">POST &lt;your URL&gt; HTTP/1.1
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
}
