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
 * The post-lifecycle events a webhook can subscribe to.
 *
 * Ordered assoc: event key → human label. Order is canonical (used for
 * checkbox rendering + sanitizer ordering). `post.published` is the
 * original (and back-compat default) event; the other three were added
 * in v4.10.0.
 *
 * @since 4.10.0
 * @return array<string,string>
 */
function sn_webhook_events() {
	return array(
		'post.published'   => 'Published (first publish)',
		'post.updated'     => 'Updated (re-saved while published)',
		'post.unpublished' => 'Unpublished (publish → draft/pending/private)',
		'post.deleted'     => 'Deleted (trashed or permanently removed)',
	);
}

/**
 * Is a webhook subscribed to a given event?
 *
 * Back-compat: a webhook with no `events` key (every webhook created
 * before v4.10.0) is treated as subscribed to `post.published` only —
 * exactly the pre-v4.10.0 behaviour. A webhook with an explicit `events`
 * array is subscribed to precisely those events.
 *
 * @since 4.10.0
 * @param array  $webhook Webhook config.
 * @param string $event   Event key (see sn_webhook_events()).
 * @return bool
 */
function sn_webhook_event_enabled( $webhook, $event ) {
	if ( ! isset( $webhook['events'] ) ) {
		return 'post.published' === $event;
	}
	return in_array( $event, (array) $webhook['events'], true );
}

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
/**
 * Sanitize a submitted `events[]` list against the registry.
 *
 * Drops any value not in sn_webhook_events(), preserves the registry's
 * canonical order, and falls back to `['post.published']` when the result
 * is empty — so a webhook always subscribes to at least one event.
 *
 * @since 4.10.0
 * @param mixed $input Raw `events` input (expected array of strings).
 * @return string[]
 */
function sn_webhook_sanitize_events( $input ) {
	$valid   = array_keys( sn_webhook_events() );
	$cleaned = array_values( array_intersect( $valid, (array) $input ) );
	if ( empty( $cleaned ) ) {
		$cleaned = array( 'post.published' );
	}
	return $cleaned;
}

function sn_webhook_create( $input ) {
	$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
	$url  = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';

	if ( '' === $name ) {
		return new WP_Error( 'sn_webhook_invalid_name', 'Name is required.' );
	}
	// T4 (Fix C): the error copy promises "https://" but the check accepted any
	// scheme wp_http_validate_url() allows (http included), leaking the signed
	// payload over plaintext. Enforce https to match the contract.
	if ( '' === $url || ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return new WP_Error( 'sn_webhook_invalid_url', 'A valid https:// URL is required.' );
	}

	$entry = array(
		'id'         => sn_webhook_generate_id(),
		'name'       => sanitize_text_field( $name ),
		'url'        => $url,
		'secret'     => sn_webhook_generate_secret(),
		'enabled'    => ! empty( $input['enabled'] ),
		'events'     => sn_webhook_sanitize_events( isset( $input['events'] ) ? $input['events'] : array() ),
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
			// T4 (Fix C): only accept https updates — an http candidate is
			// ignored (the existing URL is preserved), mirroring create's
			// https-only contract.
			if ( '' !== $candidate && wp_http_validate_url( $candidate ) && 'https' === wp_parse_url( $candidate, PHP_URL_SCHEME ) ) {
				$wh['url'] = $candidate;
			}
		}
		if ( array_key_exists( 'enabled', $input ) ) {
			$wh['enabled'] = ! empty( $input['enabled'] );
		}
		if ( array_key_exists( 'events', $input ) ) {
			$wh['events'] = sn_webhook_sanitize_events( $input['events'] );
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
 * Build the JSON payload for any webhook event.
 *
 * Two resolution paths:
 *  - `post.published` / `post.updated`: resolve the live post via
 *    get_post() and REQUIRE it to still be `publish` (a re-save that
 *    bounced out of publish before dispatch shouldn't fire). Returns
 *    null if it can't be resolved as a published post.
 *  - `post.unpublished` / `post.deleted`: the post may be gone (or no
 *    longer published) by dispatch time, so build from the $snapshot
 *    captured at TRIGGER time. Returns null if the snapshot is empty.
 *
 * @since 4.10.0 generalized from sn_webhook_build_post_published_payload().
 * @param string $event        Event key (see sn_webhook_events()).
 * @param int    $post_id      Post ID.
 * @param string $delivery_id  Unique-per-attempt id for receiver dedupe.
 * @param array  $snapshot     Trigger-time snapshot (id/title/slug/url/type/author_id)
 *                             for unpublish/delete events.
 * @return string|null         JSON-encoded body, or null if it can't be built.
 */
function sn_webhook_build_payload( $event, $post_id, $delivery_id, $snapshot = array() ) {
	$snapshot_event = ( 'post.unpublished' === $event || 'post.deleted' === $event );

	if ( $snapshot_event ) {
		if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
			return null;
		}
		$post_block = array(
			'id'           => (int) ( $snapshot['id'] ?? $post_id ),
			'title'        => (string) ( $snapshot['title'] ?? '' ),
			'slug'         => (string) ( $snapshot['slug'] ?? '' ),
			'url'          => (string) ( $snapshot['url'] ?? '' ),
			'author_id'    => (int) ( $snapshot['author_id'] ?? 0 ),
			'published_at' => isset( $snapshot['published_at'] ) ? (int) $snapshot['published_at'] : null,
			'type'         => (string) ( $snapshot['type'] ?? '' ),
		);
	} else {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$post_block = array(
			'id'           => (int) $post->ID,
			'title'        => get_the_title( $post ),
			'slug'         => $post->post_name,
			'url'          => get_permalink( $post ),
			'author_id'    => (int) $post->post_author,
			'published_at' => strtotime( $post->post_date_gmt . ' UTC' ),
			'type'         => $post->post_type,
		);
	}

	$payload = array(
		'event'        => $event,
		'delivery_id'  => $delivery_id,
		'delivered_at' => time(),
		'site'         => home_url( '/' ),
		'post'         => $post_block,
	);
	return wp_json_encode( $payload );
}

/**
 * Back-compat shim: delegate to sn_webhook_build_payload() for the
 * original post-published event. Retained for any external caller +
 * the legacy 4-arg cron path.
 *
 * @param int    $post_id
 * @param string $delivery_id
 * @return string|null
 */
function sn_webhook_build_post_published_payload( $post_id, $delivery_id ) {
	return sn_webhook_build_payload( 'post.published', $post_id, $delivery_id );
}

/**
 * Snapshot a post's identity at TRIGGER time, for events whose post may
 * be gone (or no longer published) by dispatch time.
 *
 * @since 4.10.0
 * @param WP_Post|object $post
 * @return array
 */
function sn_webhook_snapshot_post( $post ) {
	return array(
		'id'           => (int) $post->ID,
		'title'        => get_the_title( $post ),
		'slug'         => isset( $post->post_name ) ? $post->post_name : '',
		'url'          => get_permalink( $post ),
		'author_id'    => isset( $post->post_author ) ? (int) $post->post_author : 0,
		'published_at' => isset( $post->post_date_gmt ) ? strtotime( $post->post_date_gmt . ' UTC' ) : null,
		'type'         => isset( $post->post_type ) ? $post->post_type : '',
	);
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
 * Args (v4.10.0 order): [ webhook_id, event, post_id, snapshot, attempt,
 * delivery_id ]. Attempt is 1-indexed; retries increment it. `$snapshot`
 * carries the trigger-time post identity for unpublish/delete events
 * whose post may be gone by dispatch.
 *
 * @since 4.10.0 widened from ( webhook_id, post_id, attempt, delivery_id ).
 * @param string $webhook_id
 * @param string $event       Event key (see sn_webhook_events()).
 * @param int    $post_id
 * @param array  $snapshot
 * @param int    $attempt
 * @param string $delivery_id
 */
function sn_webhook_enqueue( $webhook_id, $event = 'post.published', $post_id = 0, $snapshot = array(), $attempt = 1, $delivery_id = null ) {
	if ( ! $delivery_id ) {
		$delivery_id = 'del_' . wp_generate_password( 16, false, false );
	}
	// wp_schedule_single_event dedupes on (timestamp, hook, args), so
	// double-firing on the same publish (e.g., quick edit re-publish)
	// won't enqueue a duplicate at the same exact second.
	wp_schedule_single_event(
		time(),
		SN_WEBHOOK_DISPATCH_HOOK,
		array( $webhook_id, (string) $event, (int) $post_id, (array) $snapshot, (int) $attempt, $delivery_id )
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
 * Defensive defaults ($event='post.published', $snapshot=[]) keep
 * in-flight OLD 4-arg cron events from before this deploy alive: an
 * install can't observe its own deploy, so events scheduled by the
 * pre-v4.10.0 enqueue (arg order [webhook_id, post_id, attempt,
 * delivery_id]) still fire here. The legacy shape is detected by a
 * numeric 2nd arg (the old post_id sits where $event now is) and remapped
 * so the right post is delivered, not just "no fatal".
 *
 * @since 4.10.0 widened from ( webhook_id, post_id, attempt, delivery_id ).
 * @param string $webhook_id
 * @param string $event       Event key (see sn_webhook_events()).
 * @param int    $post_id
 * @param array  $snapshot    Trigger-time snapshot for unpublish/delete.
 * @param int    $attempt
 * @param string $delivery_id
 */
function sn_webhook_dispatch( $webhook_id, $event = 'post.published', $post_id = 0, $snapshot = array(), $attempt = 1, $delivery_id = null ) {
	// In-flight back-compat: a pre-v4.10.0 cron event passes
	// [webhook_id, post_id, attempt, delivery_id]. Those land as
	// ($event=post_id, $post_id=attempt, $snapshot=delivery_id). Detect by
	// a non-event 2nd arg and remap to the new positions.
	if ( ! is_string( $event ) || ! array_key_exists( $event, sn_webhook_events() ) ) {
		$legacy_post_id     = (int) $event;
		$legacy_attempt     = is_scalar( $post_id ) ? (int) $post_id : 1;
		$legacy_delivery_id = is_string( $snapshot ) ? $snapshot : null;
		$event       = 'post.published';
		$post_id     = $legacy_post_id;
		$attempt     = $legacy_attempt;
		$delivery_id = $legacy_delivery_id;
		$snapshot    = array();
	}

	$attempt = max( 1, (int) $attempt );
	$webhook = sn_webhook_find( $webhook_id );
	if ( ! $webhook || empty( $webhook['enabled'] ) ) {
		// Webhook was deleted or disabled between enqueue and dispatch — drop silently.
		return;
	}

	$body = sn_webhook_build_payload( $event, (int) $post_id, $delivery_id, (array) $snapshot );
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
		// v4.5.2: do NOT follow redirects. The webhook URL is validated with
		// wp_http_validate_url() at config time, but WP's HTTP layer does not
		// re-validate redirect targets — a receiver returning 30x → an internal
		// host / cloud-metadata endpoint (169.254.169.254) would be followed and
		// its response body recorded in the admin-visible delivery log (SSRF +
		// exfil). Webhook receivers expose a stable endpoint; they don't redirect.
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'      => 'application/json',
			'Accept'            => 'application/json',
			'User-Agent'        => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '?' ) . ' webhook',
			'X-SN-Signature'    => $signature,
			'X-SN-Event'        => $event,
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
			array( $webhook_id, (string) $event, (int) $post_id, (array) $snapshot, $attempt + 1, $delivery_id )
		);
	}
}
add_action( SN_WEBHOOK_DISPATCH_HOOK, 'sn_webhook_dispatch', 10, 6 );

/**
 * The post types eligible for webhook events. Filterable; defaults to
 * post + page. The type gate excludes revisions, attachments, and
 * nav-menu-items (each a distinct post_type). It does NOT exclude
 * auto-drafts — 'auto-draft' is a post STATUS on a post_type='post'/'page'
 * row — those are excluded by the status guard in sn_webhook_on_delete().
 *
 * @since 4.10.0 extracted so every trigger branch shares one gate.
 * @return string[]
 */
function sn_webhook_allowed_post_types() {
	return apply_filters( 'sn_webhook_post_types', array( 'post', 'page' ) );
}

/**
 * Fan a single event out to every enabled webhook subscribed to it.
 *
 * @since 4.10.0
 * @param string $event    Event key (see sn_webhook_events()).
 * @param int    $post_id
 * @param array  $snapshot Trigger-time snapshot for unpublish/delete events.
 */
function sn_webhook_fan_out( $event, $post_id, $snapshot = array() ) {
	foreach ( sn_webhooks_all() as $wh ) {
		if ( empty( $wh['enabled'] ) ) {
			continue;
		}
		if ( ! sn_webhook_event_enabled( $wh, $event ) ) {
			continue;
		}
		sn_webhook_enqueue( $wh['id'], $event, (int) $post_id, $snapshot );
	}
}

/**
 * The trigger: transition_post_status, branched by lifecycle event.
 *
 *  - non-publish → publish (old ≠ publish): post.published (first publish)
 *  - publish → publish:                     post.updated (re-saved live)
 *  - publish → draft/pending/private:       post.unpublished (snapshot)
 *  - publish → trash:                       post.deleted (snapshot) ONLY —
 *    trashing a published post is one event, not unpublished + deleted.
 *
 * `transition_post_status` is the canonical hook here — `publish_post`
 * alone fires too eagerly on already-published meta updates. Permanent
 * deletion does NOT pass through here (no transition); see
 * sn_webhook_on_delete().
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function sn_webhook_on_transition( $new_status, $old_status, $post ) {
	if ( ! in_array( $post->post_type, sn_webhook_allowed_post_types(), true ) ) {
		return;
	}

	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		// First publish.
		sn_webhook_fan_out( 'post.published', (int) $post->ID );
		return;
	}

	if ( 'publish' === $old_status ) {
		if ( 'publish' === $new_status ) {
			// Re-saved while published.
			sn_webhook_fan_out( 'post.updated', (int) $post->ID );
			return;
		}
		if ( 'trash' === $new_status ) {
			// Trashed: treat as deleted ONLY (no double-fire with unpublished).
			$live = get_post( $post->ID );
			sn_webhook_fan_out( 'post.deleted', (int) $post->ID, sn_webhook_snapshot_post( $live ? $live : $post ) );
			return;
		}
		if ( in_array( $new_status, array( 'draft', 'pending', 'private' ), true ) ) {
			// Unpublished: snapshot now — the live post may bounce again before dispatch.
			$live = get_post( $post->ID );
			sn_webhook_fan_out( 'post.unpublished', (int) $post->ID, sn_webhook_snapshot_post( $live ? $live : $post ) );
			return;
		}
	}
}
add_action( 'transition_post_status', 'sn_webhook_on_transition', 10, 3 );

/**
 * Permanent-deletion trigger: before_delete_post fires from wp_delete_post()
 * (not wp_trash_post()), covering force-deletes and EMPTY_TRASH_DAYS-off sites.
 * Build the snapshot NOW — by dispatch the row is gone.
 *
 * Fires post.deleted ONLY for a row that is still 'publish' at deletion time.
 * This deliberately suppresses two non-events: (1) emptying the trash, where the
 * row is already 'trash' and the publish→trash transition already fired
 * post.deleted (re-firing would double-deliver with a different delivery_id, so
 * receivers could not dedupe); (2) never-public rows (draft/pending/private/
 * future, and auto-draft garbage collection via wp_delete_auto_drafts()) that
 * never fired post.published. A direct force-delete of a live published post
 * still fires exactly once. Revisions/autosaves are excluded via
 * wp_is_post_revision(); other post types via the type gate.
 *
 * @since 4.10.0
 * @param int          $post_id
 * @param WP_Post|null $post
 */
function sn_webhook_on_delete( $post_id, $post = null ) {
	if ( ! $post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post ) {
		return;
	}
	if ( wp_is_post_revision( $post ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, sn_webhook_allowed_post_types(), true ) ) {
		return;
	}
	if ( 'publish' !== ( isset( $post->post_status ) ? $post->post_status : '' ) ) {
		return;
	}
	sn_webhook_fan_out( 'post.deleted', (int) $post_id, sn_webhook_snapshot_post( $post ) );
}
add_action( 'before_delete_post', 'sn_webhook_on_delete', 10, 2 );
