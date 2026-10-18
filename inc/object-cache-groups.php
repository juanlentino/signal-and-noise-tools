<?php
/**
 * Signal & Noise Tools: object cache groups that must not be prefetched.
 *
 * Object Cache Pro prefetches a request's keys before plugins load. The
 * WordPress AI client caches model objects in `wp_ai_client`; prefetched that
 * early they unserialize as __PHP_Incomplete_Class, so OCP drops them and logs
 * "is incompatible with prefetching" on every request. On 2026-10-17 that
 * notice was about 980 of the app error log's last 1000 lines, so the log
 * held about 4 hours and an earlier fatal was gone before anyone looked.
 *
 * OCP filters a request's stored prefetch list by these groups at shutdown,
 * so registering at load is enough (no wp-config change). Lists stored before
 * this shipped heal as each URL is visited again.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the groups with OCP. A no-op without OCP.
 *
 * @return void
 */
function snt_object_cache_groups() {
	if ( function_exists( 'wp_cache_add_non_prefetchable_groups' ) ) {
		wp_cache_add_non_prefetchable_groups( array( 'wp_ai_client' ) );
	}
}

snt_object_cache_groups();
