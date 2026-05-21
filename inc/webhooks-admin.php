<?php
/**
 * Signal & Noise Tools — Webhooks admin tab.
 *
 * Renders the Webhooks tab and handles its three form actions:
 *   - add      → sn_webhook_create
 *   - update   → sn_webhook_update
 *   - delete   → sn_webhook_delete
 *
 * Save flow follows the existing sn_handle_admin_post pattern from
 * inc/admin-page.php: process on admin_init, redirect with a
 * ?sn_flash=… query arg, render the notice on the next GET.
 *
 * @package SignalNoiseTools
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_webhooks_tab', 'sn_webhooks_render_admin_tab' );

/**
 * Form handler. Hooks into admin_init like the rest of the SN admin.
 * Idempotently checks sn_action so unrelated POSTs are ignored.
 */
add_action( 'admin_init', 'sn_webhooks_handle_post' );
function sn_webhooks_handle_post() {
	if ( ! isset( $_POST['sn_action'] ) ) { return; }
	$action = (string) $_POST['sn_action'];
	if ( 0 !== strpos( $action, 'webhook_' ) ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	check_admin_referer( 'sn_webhooks' );

	$base_url = admin_url( 'admin.php?page=sn-webhooks' );

	if ( 'webhook_add' === $action ) {
		$result = sn_webhook_create( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'sn_flash', 'wh_invalid', $base_url ) );
			exit;
		}
		// Pass the new id so we can highlight + show the secret once.
		wp_safe_redirect( add_query_arg( array( 'sn_flash' => 'wh_added', 'new_id' => $result['id'] ), $base_url ) );
		exit;
	}

	if ( 'webhook_update' === $action ) {
		$id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
		$result = sn_webhook_update( $id, wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'sn_flash', 'wh_not_found', $base_url ) );
			exit;
		}
		$flash = ! empty( $_POST['rotate_secret'] ) ? 'wh_rotated' : 'wh_updated';
		wp_safe_redirect( add_query_arg( array( 'sn_flash' => $flash, 'new_id' => $id ), $base_url ) );
		exit;
	}

	if ( 'webhook_delete' === $action ) {
		$id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
		sn_webhook_delete( $id );
		wp_safe_redirect( add_query_arg( 'sn_flash', 'wh_deleted', $base_url ) );
		exit;
	}
}

function sn_webhooks_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'signal-noise-tools' ) );
	}

	$webhooks = sn_webhooks_all();
	$new_id   = isset( $_GET['new_id'] ) ? sanitize_text_field( wp_unslash( $_GET['new_id'] ) ) : '';

	echo '<div class="sn-webhooks">';

	// Header copy.
	echo '<p class="sn-field-helper">' . esc_html__( 'POST a signed JSON payload to your own endpoints (n8n, Zapier, Pipedream, anything that accepts webhooks) when a post or page is published. Each delivery is HMAC-SHA256 signed; receivers should verify the X-SN-Signature header before acting.', 'signal-noise-tools' ) . '</p>';

	// ── EXISTING WEBHOOKS LIST ──
	if ( ! empty( $webhooks ) ) {
		echo '<h2>' . esc_html__( 'Configured webhooks', 'signal-noise-tools' ) . '</h2>';
		foreach ( $webhooks as $wh ) {
			$is_new = ( $new_id === $wh['id'] );
			echo '<div class="sn-card" style="margin-bottom: 1rem;' . ( $is_new ? ' border-left: 3px solid var(--wp--preset--color--blood, #e00404);' : '' ) . '">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-webhooks' ) ) . '">';
			wp_nonce_field( 'sn_webhooks' );
			echo '<input type="hidden" name="webhook_id" value="' . esc_attr( $wh['id'] ) . '">';

			echo '<table class="form-table" role="presentation"><tbody>';

			echo '<tr><th scope="row"><label for="wh_name_' . esc_attr( $wh['id'] ) . '">' . esc_html__( 'Name', 'signal-noise-tools' ) . '</label></th>';
			echo '<td><input type="text" id="wh_name_' . esc_attr( $wh['id'] ) . '" name="name" value="' . esc_attr( $wh['name'] ) . '" class="regular-text"></td></tr>';

			echo '<tr><th scope="row"><label for="wh_url_' . esc_attr( $wh['id'] ) . '">' . esc_html__( 'URL', 'signal-noise-tools' ) . '</label></th>';
			echo '<td><input type="url" id="wh_url_' . esc_attr( $wh['id'] ) . '" name="url" value="' . esc_attr( $wh['url'] ) . '" class="regular-text code"></td></tr>';

			echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'signal-noise-tools' ) . '</th>';
			echo '<td><label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $wh['enabled'] ), true, false ) . '> ' . esc_html__( 'Deliveries fire on post publish', 'signal-noise-tools' ) . '</label></td></tr>';

			echo '<tr><th scope="row">' . esc_html__( 'Signing secret', 'signal-noise-tools' ) . '</th>';
			echo '<td>';
			if ( $is_new ) {
				// Show the secret once — only on the redirect after add / rotate.
				echo '<input type="text" readonly value="' . esc_attr( $wh['secret'] ) . '" class="regular-text code" style="background:#fffbcc;">';
				echo '<p class="description"><strong>' . esc_html__( 'Copy this now — it will not be shown again.', 'signal-noise-tools' ) . '</strong> ' . esc_html__( 'Receivers must compute HMAC-SHA256(secret, raw_body) and compare against the X-SN-Signature header.', 'signal-noise-tools' ) . '</p>';
			} else {
				echo '<code>•••••••••••• (' . esc_html( substr( $wh['secret'], 0, 4 ) ) . '…' . esc_html( substr( $wh['secret'], -4 ) ) . ')</code> ';
				echo '<label style="margin-left:1rem;"><input type="checkbox" name="rotate_secret" value="1"> ' . esc_html__( 'Rotate secret on save (invalidates the current one)', 'signal-noise-tools' ) . '</label>';
			}
			echo '</td></tr>';

			echo '</tbody></table>';

			echo '<p>';
			echo '<button type="submit" name="sn_action" value="webhook_update" class="button button-primary">' . esc_html__( 'Save changes', 'signal-noise-tools' ) . '</button> ';
			echo '<button type="submit" name="sn_action" value="webhook_delete" class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this webhook? Pending retries will be dropped.', 'signal-noise-tools' ) ) . '\')">' . esc_html__( 'Delete', 'signal-noise-tools' ) . '</button>';
			echo '</p>';
			echo '</form>';

			// Delivery log.
			$log = sn_webhook_log_read( $wh['id'] );
			if ( ! empty( $log ) ) {
				echo '<details style="margin-top:0.5rem;"><summary>' . esc_html__( 'Recent deliveries', 'signal-noise-tools' ) . ' (' . count( $log ) . ')</summary>';
				echo '<table class="widefat striped" style="margin-top:0.5rem; font-size:0.85em;"><thead><tr>';
				echo '<th scope="col">' . esc_html__( 'Fired at', 'signal-noise-tools' ) . '</th>';
				echo '<th scope="col">' . esc_html__( 'Attempt', 'signal-noise-tools' ) . '</th>';
				echo '<th scope="col">' . esc_html__( 'HTTP', 'signal-noise-tools' ) . '</th>';
				echo '<th scope="col">' . esc_html__( 'Status', 'signal-noise-tools' ) . '</th>';
				echo '<th scope="col">' . esc_html__( 'Response (truncated)', 'signal-noise-tools' ) . '</th>';
				echo '</tr></thead><tbody>';
				// Newest first.
				$log = array_reverse( $log );
				foreach ( $log as $entry ) {
					echo '<tr>';
					echo '<td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['fired_at'] ) ) . '</td>';
					echo '<td>' . esc_html( (string) $entry['attempt'] ) . '</td>';
					echo '<td>' . esc_html( (string) $entry['response_code'] ) . '</td>';
					echo '<td>' . ( ! empty( $entry['success'] ) ? '<span style="color:#46b450;">✓</span>' : '<span style="color:#dc3232;">✕</span>' ) . '</td>';
					echo '<td><code>' . esc_html( (string) $entry['response_excerpt'] ) . '</code></td>';
					echo '</tr>';
				}
				echo '</tbody></table></details>';
			}

			echo '</div>'; // .sn-card
		}
	}

	// ── ADD NEW ──
	echo '<h2>' . esc_html__( 'Add a webhook', 'signal-noise-tools' ) . '</h2>';
	echo '<div class="sn-card">';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-webhooks' ) ) . '">';
	wp_nonce_field( 'sn_webhooks' );
	echo '<table class="form-table" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="wh_new_name">' . esc_html__( 'Name', 'signal-noise-tools' ) . '</label></th>';
	echo '<td><input type="text" id="wh_new_name" name="name" class="regular-text" placeholder="' . esc_attr__( 'My n8n flow', 'signal-noise-tools' ) . '"></td></tr>';
	echo '<tr><th scope="row"><label for="wh_new_url">' . esc_html__( 'URL', 'signal-noise-tools' ) . '</label></th>';
	echo '<td><input type="url" id="wh_new_url" name="url" class="regular-text code" placeholder="https://"></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'signal-noise-tools' ) . '</th>';
	echo '<td><label><input type="checkbox" name="enabled" value="1" checked> ' . esc_html__( 'Start dispatching on publish immediately', 'signal-noise-tools' ) . '</label></td></tr>';
	echo '</tbody></table>';
	echo '<p><button type="submit" name="sn_action" value="webhook_add" class="button button-primary">' . esc_html__( 'Add webhook', 'signal-noise-tools' ) . '</button></p>';
	echo '</form>';
	echo '</div>';

	// ── PAYLOAD REFERENCE ──
	echo '<h2 style="margin-top:2rem;">' . esc_html__( 'Payload reference', 'signal-noise-tools' ) . '</h2>';
	echo '<div class="sn-card">';
	echo '<p>' . esc_html__( 'Every delivery is a POST with these headers + a JSON body:', 'signal-noise-tools' ) . '</p>';
	echo '<pre style="background:#f3f3f3;padding:0.75rem;overflow:auto;">POST &lt;your URL&gt; HTTP/1.1
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
	echo '<p>' . esc_html__( 'Receivers should verify X-SN-Signature before trusting the body. Failures (network or HTTP 5xx) are retried up to 3 times with a 5-minute delay between attempts; HTTP 4xx is treated as a hard rejection.', 'signal-noise-tools' ) . '</p>';
	echo '</div>';

	echo '</div>'; // .sn-webhooks
}
