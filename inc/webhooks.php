<?php
/**
 * Signal & Noise Tools — Personal automation webhooks.
 *
 * Fires HMAC-SHA256-signed POSTs to user-configured URLs on
 * post-publish events. Async dispatch via WP-Cron so the publish
 * request never blocks on slow webhook receivers. Three retries on
 * failure (network or HTTP 5xx), each delayed by 5 minutes.
 *
 * Storage:
 *   - sn_webhooks (autoload=true): array of webhook configs
 *     { id, name, url, secret, enabled, created_at }
 *   - sn_webhook_log_<webhook_id> (autoload=false): rolling
 *     20-entry deliveries array per webhook
 *     { delivery_id, attempt, fired_at, response_code, response_excerpt, success }
 *
 * Surfaces:
 *   - wp-admin Webhooks tab — CRUD UI
 *   - WP-Cron hook 'sn_webhook_dispatch' — async worker (the only
 *     thing that actually opens an HTTP connection)
 *   - transition_post_status — enqueues a dispatch per enabled webhook
 *     when a post transitions to 'publish' from any non-publish state
 *
 * @package SignalNoiseTools
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_WEBHOOKS_OPTION',     'sn_webhooks' );
define( 'SN_WEBHOOKS_LOG_PREFIX', 'sn_webhook_log_' );
define( 'SN_WEBHOOK_DISPATCH_HOOK', 'sn_webhook_dispatch' );
define( 'SN_WEBHOOK_MAX_RETRIES', 3 );
define( 'SN_WEBHOOK_RETRY_DELAY', 5 * MINUTE_IN_SECONDS );
define( 'SN_WEBHOOK_LOG_CAP',     20 );

/**
 * Read all webhook configs.
 *
 * @return array Array of webhook entries (may be empty).
 */
function sn_webhooks_all() {
	$stored = get_option( SN_WEBHOOKS_OPTION, array() );
	return is_array( $stored ) ? $stored : array();
}

function sn_webhook_find( $id ) {
	foreach ( sn_webhooks_all() as $wh ) {
		if ( isset( $wh['id'] ) && $wh['id'] === $id ) {
			return $wh;
		}
	}
	return null;
}

/**
 * Generate a webhook id + secret. wp_generate_password is the
 * project's go-to for "secure-ish random string we need to print
 * to the admin once and then never see again."
 */
function sn_webhook_generate_id() {
	return 'wh_' . wp_generate_password( 12, false, false );
}

function sn_webhook_generate_secret() {
	// 48 chars of [A-Za-z0-9] — ~288 bits of entropy, plenty for HMAC.
	return wp_generate_password( 48, false, false );
}

/**
 * Create a new webhook. Returns the new entry (including its
 * generated id + secret) so the admin UI can show the secret once.
 *
 * @param array $input Sanitized form input.
 * @return array|WP_Error
 */
function sn_webhook_create( $input ) {
	$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
	$url  = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';

	if ( '' === $name ) {
		return new WP_Error( 'sn_webhook_invalid_name', 'Name is required.' );
	}
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'sn_webhook_invalid_url', 'A valid https:// URL is required.' );
	}

	$entry = array(
		'id'         => sn_webhook_generate_id(),
		'name'       => sanitize_text_field( $name ),
		'url'        => $url,
		'secret'     => sn_webhook_generate_secret(),
		'enabled'    => ! empty( $input['enabled'] ),
		'created_at' => time(),
	);

	$all = sn_webhooks_all();
	$all[] = $entry;
	update_option( SN_WEBHOOKS_OPTION, $all );

	return $entry;
}

/**
 * Update an existing webhook. Secret is regenerated only if
 * $input['rotate_secret'] is truthy.
 */
function sn_webhook_update( $id, $input ) {
	$all = sn_webhooks_all();
	$updated = null;
	foreach ( $all as $idx => $wh ) {
		if ( ! isset( $wh['id'] ) || $wh['id'] !== $id ) {
			continue;
		}
		if ( isset( $input['name'] ) ) {
			$wh['name'] = sanitize_text_field( trim( (string) $input['name'] ) );
		}
		if ( isset( $input['url'] ) ) {
			$candidate = esc_url_raw( trim( (string) $input['url'] ) );
			if ( '' !== $candidate && wp_http_validate_url( $candidate ) ) {
				$wh['url'] = $candidate;
			}
		}
		if ( array_key_exists( 'enabled', $input ) ) {
			$wh['enabled'] = ! empty( $input['enabled'] );
		}
		if ( ! empty( $input['rotate_secret'] ) ) {
			$wh['secret'] = sn_webhook_generate_secret();
		}
		$all[ $idx ] = $wh;
		$updated     = $wh;
		break;
	}
	if ( null === $updated ) {
		return new WP_Error( 'sn_webhook_not_found', 'Webhook not found.' );
	}
	update_option( SN_WEBHOOKS_OPTION, $all );
	return $updated;
}

function sn_webhook_delete( $id ) {
	$all = sn_webhooks_all();
	$kept = array();
	$removed = false;
	foreach ( $all as $wh ) {
		if ( isset( $wh['id'] ) && $wh['id'] === $id ) {
			$removed = true;
			continue;
		}
		$kept[] = $wh;
	}
	if ( ! $removed ) {
		return new WP_Error( 'sn_webhook_not_found', 'Webhook not found.' );
	}
	update_option( SN_WEBHOOKS_OPTION, $kept );
	delete_option( SN_WEBHOOKS_LOG_PREFIX . $id );
	return true;
}

/**
 * Compute the HMAC-SHA256 signature for a payload.
 *
 * Returns the canonical header value: "sha256=<hex>".
 * Pure function — no I/O — so tests can verify against known fixtures.
 */
function sn_webhook_compute_signature( $secret, $body ) {
	return 'sha256=' . hash_hmac( 'sha256', (string) $body, (string) $secret );
}

/**
 * Build the JSON payload for a post-published event.
 *
 * @param int    $post_id
 * @param string $delivery_id  Unique-per-attempt id for receiver dedupe.
 * @return string|null         JSON-encoded body, or null if post can't be resolved.
 */
function sn_webhook_build_post_published_payload( $post_id, $delivery_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return null;
	}
	$payload = array(
		'event'        => 'post.published',
		'delivery_id'  => $delivery_id,
		'delivered_at' => time(),
		'site'         => home_url( '/' ),
		'post'         => array(
			'id'           => (int) $post->ID,
			'title'        => get_the_title( $post ),
			'slug'         => $post->post_name,
			'url'          => get_permalink( $post ),
			'author_id'    => (int) $post->post_author,
			'published_at' => strtotime( $post->post_date_gmt . ' UTC' ),
			'type'         => $post->post_type,
		),
	);
	return wp_json_encode( $payload );
}

/**
 * Append a delivery record to the per-webhook log. Caps at 20 entries.
 */
function sn_webhook_log_record( $webhook_id, $record ) {
	$key = SN_WEBHOOKS_LOG_PREFIX . $webhook_id;
	$log = get_option( $key, array() );
	if ( ! is_array( $log ) ) { $log = array(); }
	$log[] = $record;
	if ( count( $log ) > SN_WEBHOOK_LOG_CAP ) {
		$log = array_slice( $log, -SN_WEBHOOK_LOG_CAP );
	}
	update_option( $key, $log, false );
}

function sn_webhook_log_read( $webhook_id ) {
	$log = get_option( SN_WEBHOOKS_LOG_PREFIX . $webhook_id, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * Enqueue a webhook dispatch as a one-off WP-Cron event.
 *
 * Args: [ webhook_id, post_id, attempt, delivery_id ]. Attempt is
 * 1-indexed; retries increment it.
 */
function sn_webhook_enqueue( $webhook_id, $post_id, $attempt = 1, $delivery_id = null ) {
	if ( ! $delivery_id ) {
		$delivery_id = 'del_' . wp_generate_password( 16, false, false );
	}
	// wp_schedule_single_event dedupes on (timestamp, hook, args), so
	// double-firing on the same publish (e.g., quick edit re-publish)
	// won't enqueue a duplicate at the same exact second.
	wp_schedule_single_event(
		time(),
		SN_WEBHOOK_DISPATCH_HOOK,
		array( $webhook_id, (int) $post_id, (int) $attempt, $delivery_id )
	);
}

/**
 * Cron worker. The only thing in this module that opens an outbound
 * HTTP connection. Reads the webhook config + builds the payload +
 * signs + POSTs + records the delivery in the per-webhook log.
 *
 * On failure (network error OR HTTP 5xx), schedules a retry +5 min
 * with attempt+1, up to SN_WEBHOOK_MAX_RETRIES. 4xx responses are
 * treated as receiver-side rejections (no retry).
 *
 * @param string $webhook_id
 * @param int    $post_id
 * @param int    $attempt
 * @param string $delivery_id
 */
function sn_webhook_dispatch( $webhook_id, $post_id, $attempt, $delivery_id ) {
	$attempt = max( 1, (int) $attempt );
	$webhook = sn_webhook_find( $webhook_id );
	if ( ! $webhook || empty( $webhook['enabled'] ) ) {
		// Webhook was deleted or disabled between enqueue and dispatch — drop silently.
		return;
	}

	$body = sn_webhook_build_post_published_payload( (int) $post_id, $delivery_id );
	if ( null === $body ) {
		sn_webhook_log_record( $webhook_id, array(
			'delivery_id'      => $delivery_id,
			'attempt'          => $attempt,
			'fired_at'         => time(),
			'response_code'    => 0,
			'response_excerpt' => 'Post not published or not found at dispatch time; dropping.',
			'success'          => false,
		) );
		return;
	}

	$signature = sn_webhook_compute_signature( $webhook['secret'], $body );

	$response = wp_remote_post( $webhook['url'], array(
		'method'      => 'POST',
		'timeout'     => 10,
		'redirection' => 3,
		'headers'     => array(
			'Content-Type'      => 'application/json',
			'Accept'            => 'application/json',
			'User-Agent'        => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' webhook',
			'X-SN-Signature'    => $signature,
			'X-SN-Event'        => 'post.published',
			'X-SN-Delivery'     => $delivery_id,
			'X-SN-Attempt'      => (string) $attempt,
		),
		'body'        => $body,
	) );

	$success         = false;
	$response_code   = 0;
	$response_excerpt = '';

	if ( is_wp_error( $response ) ) {
		$response_excerpt = $response->get_error_message();
		$retryable        = true;
	} else {
		$response_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_excerpt = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		$success          = $response_code >= 200 && $response_code < 300;
		// 5xx = retry; 4xx = receiver rejected, don't retry.
		$retryable        = $response_code >= 500 || 0 === $response_code;
	}

	sn_webhook_log_record( $webhook_id, array(
		'delivery_id'      => $delivery_id,
		'attempt'          => $attempt,
		'fired_at'         => time(),
		'response_code'    => $response_code,
		'response_excerpt' => $response_excerpt,
		'success'          => $success,
	) );

	if ( ! $success && $retryable && $attempt < SN_WEBHOOK_MAX_RETRIES ) {
		wp_schedule_single_event(
			time() + SN_WEBHOOK_RETRY_DELAY,
			SN_WEBHOOK_DISPATCH_HOOK,
			array( $webhook_id, (int) $post_id, $attempt + 1, $delivery_id )
		);
	}
}
add_action( SN_WEBHOOK_DISPATCH_HOOK, 'sn_webhook_dispatch', 10, 4 );

/**
 * The trigger: transition_post_status with the (publish-now &&
 * not-already-published) guard. This is the canonical pattern —
 * `publish_post` alone fires too eagerly on already-published meta
 * updates.
 */
function sn_webhook_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	// Skip auto-drafts, revisions, attachments, nav-menu-item, etc.
	$allowed_types = apply_filters( 'sn_webhook_post_types', array( 'post', 'page' ) );
	if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
		return;
	}
	foreach ( sn_webhooks_all() as $wh ) {
		if ( empty( $wh['enabled'] ) ) {
			continue;
		}
		sn_webhook_enqueue( $wh['id'], (int) $post->ID );
	}
}
add_action( 'transition_post_status', 'sn_webhook_on_transition', 10, 3 );
