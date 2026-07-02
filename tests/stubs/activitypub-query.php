<?php
/**
 * Test stub of the official ActivityPub plugin's Query singleton.
 *
 * Mirrors the REAL surface used by inc/security-headers.php, verified
 * against plugin v9.0.2 source (includes/class-query.php): the class is
 * \Activitypub\Query, fetched via ::get_instance(), and the request check
 * is the instance method is_activitypub_request(). If the real plugin ever
 * renames either, sn_security_is_activitypub_request() would return false
 * in production (class_exists guard) — re-verify against the live plugin,
 * not this stub.
 *
 * Lives in tests/stubs/ so the tests/*.php sweep glob never executes it as
 * a suite.
 */

namespace Activitypub;

class Query {
	public static $is_activitypub_request = false;

	public static function get_instance() {
		return new self();
	}

	public function is_activitypub_request() {
		return (bool) self::$is_activitypub_request;
	}
}
