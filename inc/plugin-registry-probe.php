<?php
/**
 * Runtime probe: catch an empty plugin registry at the moment it is served.
 *
 * The health check in inc/health-check-plugin-registry.php is the durable
 * detector, but it runs on a SCHEDULE. A poisoned object-cache entry is
 * transient — it can be served for a few minutes, be seen by a person, and be
 * gone before the next scan. A scheduled check alone would therefore report a
 * clean site for a fault the owner watched happen, which is the same "the
 * instrument disagrees with the person" failure the check was written to end.
 *
 * So this hooks the response itself. When `GET /wp/v2/plugins` answers with a
 * SUCCESS status and an empty collection while WordPress is actively running
 * plugins, the observation is written down with a timestamp. The health check
 * then reports it for seven days, whether or not the cache has since recovered.
 *
 * Deliberately narrow:
 *
 *   - the route is compared before anything else, so every other REST request
 *     pays one string comparison;
 *   - error responses are ignored — a 401 already tells its own story, and the
 *     fault being caught here is specifically a HEALTHY-LOOKING empty answer;
 *   - it records, it does not repair. Flushing a cache from inside a read
 *     request would hide the very evidence the owner needs.
 *
 * @package Signal_And_Noise_Tools
 * @since 13.96.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the last observation. Not autoloaded — read only by the check. */
const SN_PLUGIN_REGISTRY_ANOMALY_OPTION = 'sn_plugin_registry_anomaly';

/** How long an observation stays reportable. Bounded so the check can reach zero again. */
const SN_PLUGIN_REGISTRY_ANOMALY_TTL = 604800; // 7 days.

/**
 * Note an empty plugins collection served with a success status.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result.
 * @param array                                            $handler  Route handler.
 * @param WP_REST_Request|mixed                            $request  The request.
 * @return mixed The response, untouched.
 */
function snt_plugin_registry_probe( $response, $handler, $request ) {
	// Cheapest possible rejection first: this fires on EVERY REST request.
	if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
		return $response;
	}
	if ( '/wp/v2/plugins' !== $request->get_route() ) {
		return $response;
	}
	if ( is_wp_error( $response ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
		return $response;
	}
	if ( method_exists( $response, 'is_error' ) && $response->is_error() ) {
		// A 401/403 is not this fault. It reports itself honestly.
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) || array() !== $data ) {
		return $response;
	}

	// An empty collection is only wrong if WordPress is running plugins.
	$active = get_option( 'active_plugins' );
	if ( ! is_array( $active ) || array() === $active ) {
		return $response;
	}

	update_option(
		SN_PLUGIN_REGISTRY_ANOMALY_OPTION,
		array(
			'time'   => time(),
			'active' => count( $active ),
		),
		false
	);

	return $response;
}
add_filter( 'rest_request_after_callbacks', 'snt_plugin_registry_probe', 10, 3 );

/**
 * The last recorded anomaly, if it is still inside the reporting window.
 *
 * @return array{time:int,active:int}|null
 */
function snt_plugin_registry_anomaly() {
	$seen = get_option( SN_PLUGIN_REGISTRY_ANOMALY_OPTION );
	if ( ! is_array( $seen ) || empty( $seen['time'] ) ) {
		return null;
	}
	$age = time() - (int) $seen['time'];
	if ( $age < 0 || $age > SN_PLUGIN_REGISTRY_ANOMALY_TTL ) {
		return null;
	}

	return array(
		'time'   => (int) $seen['time'],
		'active' => (int) ( $seen['active'] ?? 0 ),
	);
}
