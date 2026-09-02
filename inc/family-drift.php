<?php
/**
 * Signal & Noise Tools — the family-drift check (measurement weave, Phase 5).
 *
 * `snt_mr_valid_families()` is 18 hand-maintained values mirrored across two
 * repos — this plugin and the rights-signals worker's `MACHINE_FAMILIES` —
 * and nothing checked that the two copies still agreed, or that the enum
 * still matched the world. Standing rule: derive lists, never remember them.
 * This one was remembered twice.
 *
 * Two MIT-licensed DATA corpora make it checkable, as a CONTROL, never as a
 * classifier (their axes differ: ours is vendor-first for AI, theirs is
 * function-first and collapses every AI vendor into one tag):
 *
 *   monperrus/crawler-user-agents — 1,500 tagged UA patterns with instances
 *   ai-robots-txt/ai.robots.txt   — 166 AI agents with operator/function/respect
 *
 * Both are PINNED in data/family-drift/pinned.json (vocabulary + respect,
 * with the upstream commit recorded) and DIFFED against a weekly re-fetch.
 * Never fetch-and-trust: a silent vocabulary change upstream surfaces as
 * drift here rather than being absorbed.
 *
 * The worker's enum is read from the DEPLOYED source: `/version` names the
 * `source_commit`, and the check fetches src/machine-readers.mjs at exactly
 * that commit from the public repo. That is the enum actually classifying
 * traffic, not whatever `main` says.
 *
 * FAIL-CLOSED on an unmeasured fetch: any of the three sources failing makes
 * the run `unavailable`, never an empty diff — an empty diff is byte-identical
 * to "no drift" and would report the healthiest possible result at the exact
 * moment the instrument broke. The last good report is kept beside it, with
 * its age, so a reader sees both facts.
 *
 * Rows the report carries (Appendix: docs/proposals/measurement-weave-2026-08-31.md):
 *   mirror_parity     plugin enum vs deployed worker enum — unequal is RED
 *   ours_unmatched    worker families that classify ZERO upstream entries
 *   upstream_unmapped upstream tags no family of ours claims (counts, not hits —
 *                     the ledger has no UA dimension, so "live hits per tag" is
 *                     not derivable; the plan's wording was optimistic)
 *   vendor_gap        ai.robots.txt operators absent from our AI families
 *   respect_flips     agents whose `respect` changed since the pinned copy
 *   vocabulary        tags / agents added or removed since the pinned copy
 *
 * @package SignalNoiseTools
 * @since 13.62.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_FAMILY_DRIFT_HOOK         = 'sn_family_drift_weekly';
const SN_FAMILY_DRIFT_LAST_OPTION  = 'sn_family_drift_last';
const SN_FAMILY_DRIFT_OK_OPTION    = 'sn_family_drift_last_ok';
const SN_FAMILY_DRIFT_STALE_SECS   = 14 * DAY_IN_SECONDS;
const SN_FAMILY_DRIFT_FETCH_MAX    = 2 * 1024 * 1024;
const SN_FAMILY_DRIFT_UA_URL       = 'https://raw.githubusercontent.com/monperrus/crawler-user-agents/master/crawler-user-agents.json';
const SN_FAMILY_DRIFT_AI_URL       = 'https://raw.githubusercontent.com/ai-robots-txt/ai.robots.txt/main/robots.json';
const SN_FAMILY_DRIFT_WORKER_RAW   = 'https://raw.githubusercontent.com/juanlentino/sn-rights-signals-worker/%s/src/machine-readers.mjs';
const SN_FAMILY_DRIFT_UNMAPPED_MIN = 5; // an upstream tag with fewer entries than this is not evidence of a gap.

/**
 * Plugin-only families, by decision. `unclassified-machine` (v10.79.0) is
 * named on a different axis from the worker's classifier: it carries rows the
 * worker would have dropped. It is NOT a mirror defect.
 */
const SN_FAMILY_DRIFT_PLUGIN_ONLY = array( 'unclassified-machine' );

/**
 * Families that CANNOT classify an upstream entry, by construction.
 *
 * `apple-ai` (v13.74.0). Apple ships no AI-training crawler with a user agent
 * of its own: `Applebot` is the SEARCH crawler, and `Applebot-Extended` is a
 * robots.txt token that governs how already-collected data may be used and
 * NEVER FETCHES. So this family can only ever report 0 — or a non-zero count
 * that is spoofed or synthetic, which is the worse reading because it looks
 * measured. `Google-Extended` has the same shape and no family of its own.
 *
 * Exempt from ours_unmatched, not deleted. Deleting it would mean a
 * coordinated change against the CRITICAL mirror-parity row to remove a family
 * that states something TRUE — Apple publishes a training-use control — and
 * would trade a weekly false alarm for a permanent silence.
 *
 * The alarm is what earns the exemption: ours_unmatched reads "either the
 * vendor is gone or its user agents changed", and for this family neither is
 * ever true. A weekly CRITICAL-carrying check that cries wolf on a known
 * constant is a check nobody reads by the time mirror_parity actually breaks.
 *
 * The exemption is REPORTED (see the `unobservable` row), never silent: an
 * exemption you cannot see is indistinguishable from a family that quietly
 * started matching.
 */
const SN_FAMILY_DRIFT_UNOBSERVABLE = array( 'apple-ai' );

/** Worker families that are not AI vendors (for the vendor_gap row). */
const SN_FAMILY_DRIFT_NON_AI = array( 'search', 'seo', 'feed', 'uptime', 'other-bot' );

/**
 * The pinned corpora. null when the file is missing or unreadable — a caller
 * must treat that as UNAVAILABLE, not as an empty pin.
 *
 * @return array|null
 */
function sn_family_drift_pinned() {
	static $pin = false;
	if ( false !== $pin ) {
		return $pin;
	}
	$path = dirname( __DIR__ ) . '/data/family-drift/pinned.json';
	$raw  = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	$dec  = '' !== $raw ? json_decode( $raw, true ) : null;
	$pin  = ( is_array( $dec ) && isset( $dec['ua_patterns'], $dec['ai_agents'], $dec['sources'] ) ) ? $dec : null;
	return $pin;
}

/**
 * Parse the worker's MACHINE_FAMILIES table out of its source. PURE.
 *
 * Returns name => PHP-delimited regex in TABLE ORDER (order is the
 * classifier's first-match rule), or null when the table cannot be found —
 * null, never [], because an empty enum would make every row below read as
 * total drift.
 *
 * @param string $src src/machine-readers.mjs.
 * @return array<string,string>|null
 */
function sn_family_drift_parse_worker_enum( $src ) {
	if ( ! preg_match( '/MACHINE_FAMILIES\s*=\s*\[(.*?)\n\];/s', (string) $src, $m ) ) {
		return null;
	}
	if ( ! preg_match_all( '/\[\s*"([a-z0-9-]+)"\s*,\s*\/((?:\\\\\/|[^\/])+)\/([a-z]*)\s*,?\s*\]/s', $m[1], $rows, PREG_SET_ORDER ) ) {
		return null;
	}
	$out = array();
	foreach ( $rows as $r ) {
		$flags = str_replace( array( 'g', 'y' ), '', $r[3] ); // JS-only flags.
		$out[ $r[1] ] = '~' . str_replace( '~', '\\~', $r[2] ) . '~' . $flags . 'u';
	}
	return array() === $out ? null : $out;
}

/**
 * First worker family whose regex matches any of the strings, in table order.
 *
 * @param array<string,string> $enum    From sn_family_drift_parse_worker_enum().
 * @param string[]             $samples UA instances (and the pattern as a fallback).
 * @return string|null
 */
function sn_family_drift_classify( $enum, $samples ) {
	foreach ( $enum as $family => $re ) {
		foreach ( $samples as $s ) {
			if ( '' !== (string) $s && 1 === @preg_match( $re, (string) $s ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a hostile upstream regex must not fatal the check.
				return $family;
			}
		}
	}
	return null;
}

/**
 * THE DIFF. PURE over the four inputs; every row is derived, none remembered.
 *
 * @param string[]             $plugin_enum snt_mr_valid_families().
 * @param array<string,string> $worker_enum sn_family_drift_parse_worker_enum().
 * @param array                $ua_live     Decoded crawler-user-agents.json.
 * @param array                $ai_live     Decoded robots.json.
 * @param array                $pinned      sn_family_drift_pinned().
 * @return array
 */
function sn_family_drift_compute( $plugin_enum, $worker_enum, $ua_live, $ai_live, $pinned ) {
	$plugin_core = array_values( array_diff( (array) $plugin_enum, SN_FAMILY_DRIFT_PLUGIN_ONLY ) );
	$worker      = array_keys( (array) $worker_enum );

	// mirror_parity — sets AND order (order is the classifier's precedence).
	$mirror = array(
		'ok'          => $plugin_core === $worker,
		'plugin_only' => array_values( array_diff( $plugin_core, $worker ) ),
		'worker_only' => array_values( array_diff( $worker, $plugin_core ) ),
		'order_ok'    => array_values( array_intersect( $plugin_core, $worker ) ) === array_values( array_intersect( $worker, $plugin_core ) ),
		'exempt'      => SN_FAMILY_DRIFT_PLUGIN_ONLY,
	);

	// Classify every upstream UA entry with the worker's own rules.
	$claimed_per_family = array_fill_keys( $worker, 0 );
	$per_tag            = array(); // tag => [entries, claimed]
	$live_tags          = array();
	foreach ( (array) $ua_live as $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		$samples   = array_merge( (array) ( $e['instances'] ?? array() ), array( (string) ( $e['pattern'] ?? '' ) ) );
		$family    = sn_family_drift_classify( (array) $worker_enum, $samples );
		$is_claim  = null !== $family && 'other-bot' !== $family;
		if ( null !== $family ) {
			$claimed_per_family[ $family ]++;
		}
		foreach ( (array) ( $e['tags'] ?? array() ) as $tag ) {
			$tag = (string) $tag;
			$live_tags[ $tag ] = true;
			if ( ! isset( $per_tag[ $tag ] ) ) {
				$per_tag[ $tag ] = array( 'entries' => 0, 'claimed' => 0 );
			}
			$per_tag[ $tag ]['entries']++;
			if ( $is_claim ) {
				$per_tag[ $tag ]['claimed']++;
			}
		}
	}
	$ours_unmatched = array();
	$unobservable   = array();
	foreach ( $claimed_per_family as $family => $n ) {
		if ( 0 !== $n || 'other-bot' === $family ) {
			continue;
		}
		if ( in_array( $family, SN_FAMILY_DRIFT_UNOBSERVABLE, true ) ) {
			$unobservable[] = $family;
			continue;
		}
		$ours_unmatched[] = $family;
	}
	$upstream_unmapped = array();
	foreach ( $per_tag as $tag => $c ) {
		if ( 0 === $c['claimed'] && $c['entries'] >= SN_FAMILY_DRIFT_UNMAPPED_MIN ) {
			$upstream_unmapped[ $tag ] = $c['entries'];
		}
	}
	arsort( $upstream_unmapped );

	// vendor_gap — ai.robots.txt agents no AI family of ours matches, by operator.
	$ai_enum = array_diff_key( (array) $worker_enum, array_fill_keys( SN_FAMILY_DRIFT_NON_AI, 1 ) );
	$vendor_gap = array();
	foreach ( (array) $ai_live as $name => $meta ) {
		if ( null !== sn_family_drift_classify( $ai_enum, array( (string) $name ) ) ) {
			continue;
		}
		$op = is_array( $meta ) ? (string) ( $meta['operator'] ?? '' ) : '';
		$op = '' === $op ? '(unstated)' : $op;
		$vendor_gap[ $op ][] = (string) $name;
	}
	ksort( $vendor_gap );

	// respect_flips + vocabulary — live vs PINNED.
	$pin_ai        = (array) ( $pinned['ai_agents'] ?? array() );
	$respect_flips = array();
	foreach ( (array) $ai_live as $name => $meta ) {
		if ( isset( $pin_ai[ $name ] ) && is_array( $meta ) ) {
			$was = (string) ( $pin_ai[ $name ]['respect'] ?? '' );
			$now = (string) ( $meta['respect'] ?? '' );
			if ( $was !== $now ) {
				$respect_flips[ (string) $name ] = array( 'from' => $was, 'to' => $now );
			}
		}
	}
	$pin_tags = array();
	foreach ( (array) ( $pinned['ua_patterns'] ?? array() ) as $tags ) {
		foreach ( (array) $tags as $t ) {
			$pin_tags[ (string) $t ] = true;
		}
	}
	$vocabulary = array(
		'tags_added'     => array_values( array_diff( array_keys( $live_tags ), array_keys( $pin_tags ) ) ),
		'tags_removed'   => array_values( array_diff( array_keys( $pin_tags ), array_keys( $live_tags ) ) ),
		'agents_added'   => count( array_diff_key( (array) $ai_live, $pin_ai ) ),
		'agents_removed' => count( array_diff_key( $pin_ai, (array) $ai_live ) ),
	);

	return array(
		'mirror_parity'     => $mirror,
		'ours_unmatched'    => $ours_unmatched,
		'unobservable'      => $unobservable,
		'upstream_unmapped' => $upstream_unmapped,
		'vendor_gap'        => $vendor_gap,
		'respect_flips'     => $respect_flips,
		'vocabulary'        => $vocabulary,
		'counts'            => array(
			'plugin_families' => count( (array) $plugin_enum ),
			'worker_families' => count( $worker ),
			'ua_entries'      => count( (array) $ua_live ),
			'ai_agents'       => count( (array) $ai_live ),
		),
	);
}

/**
 * One SSRF-guarded https GET. null on ANY failure (blocked, transport,
 * non-200, oversize, empty) — the caller turns null into UNAVAILABLE.
 *
 * @param string $url
 * @return string|null
 */
function sn_family_drift_fetch( $url ) {
	if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'wp_http_validate_url' ) ) {
		return null;
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) ) ) {
		return null;
	}
	$resp = wp_remote_get( $url, array(
		'timeout'             => 15,
		'redirection'         => 0,
		'sslverify'           => true,
		'limit_response_size' => SN_FAMILY_DRIFT_FETCH_MAX,
		'headers'             => array( 'Accept' => 'application/json, text/plain' ),
	) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}
	$body = (string) wp_remote_retrieve_body( $resp );
	return '' === trim( $body ) ? null : $body;
}

/** The worker's /version URL, derived from the machine-readers endpoint. */
function sn_family_drift_worker_version_url() {
	$endpoint = function_exists( 'snt_mr_config' ) ? (string) ( snt_mr_config()['endpoint'] ?? '' ) : '';
	if ( '' === $endpoint && defined( 'SN_MR_DEFAULT_ENDPOINT' ) ) {
		$endpoint = SN_MR_DEFAULT_ENDPOINT;
	}
	return '' === $endpoint ? '' : preg_replace( '~/machine-readers(\?.*)?$~', '/version', $endpoint );
}

/**
 * Run the check: three fetches, one diff, one write. Fail-closed.
 *
 * @return array The stored attempt record.
 */
function sn_family_drift_run() {
	$now  = time();
	$fail = static function ( $error ) use ( $now ) {
		$rec = array( 'status' => 'unavailable', 'error' => (string) $error, 'computed_at' => $now );
		if ( function_exists( 'update_option' ) ) {
			update_option( SN_FAMILY_DRIFT_LAST_OPTION, $rec, false );
		}
		return $rec;
	};
	$pinned = sn_family_drift_pinned();
	if ( null === $pinned ) {
		return $fail( 'pin_missing' );
	}
	$version_url = sn_family_drift_worker_version_url();
	$version     = '' !== $version_url ? json_decode( (string) sn_family_drift_fetch( $version_url ), true ) : null;
	$commit      = is_array( $version ) ? (string) ( $version['source_commit'] ?? '' ) : '';
	if ( ! preg_match( '/^[0-9a-f]{7,40}$/', $commit ) ) {
		return $fail( 'worker_version' );
	}
	$worker_enum = sn_family_drift_parse_worker_enum( (string) sn_family_drift_fetch( sprintf( SN_FAMILY_DRIFT_WORKER_RAW, $commit ) ) );
	if ( null === $worker_enum ) {
		return $fail( 'worker_source' );
	}
	$ua_live = json_decode( (string) sn_family_drift_fetch( SN_FAMILY_DRIFT_UA_URL ), true );
	if ( ! is_array( $ua_live ) || array() === $ua_live ) {
		return $fail( 'upstream_ua' );
	}
	$ai_live = json_decode( (string) sn_family_drift_fetch( SN_FAMILY_DRIFT_AI_URL ), true );
	if ( ! is_array( $ai_live ) || array() === $ai_live ) {
		return $fail( 'upstream_ai' );
	}
	$plugin_enum = function_exists( 'snt_mr_valid_families' ) ? snt_mr_valid_families() : array();
	$report      = sn_family_drift_compute( $plugin_enum, $worker_enum, $ua_live, $ai_live, $pinned );
	$rec         = array_merge( array(
		'status'      => 'ok',
		'error'       => '',
		'computed_at' => $now,
		'sources'     => array(
			'worker_commit' => $commit,
			'worker_version' => (string) ( $version['version'] ?? '' ),
			'pinned_at'     => (string) ( $pinned['pinned_at'] ?? '' ),
			'pin_commits'   => array(
				'crawler_user_agents' => (string) ( $pinned['sources']['crawler_user_agents']['commit'] ?? '' ),
				'ai_robots_txt'       => (string) ( $pinned['sources']['ai_robots_txt']['commit'] ?? '' ),
			),
		),
	), $report );
	if ( function_exists( 'update_option' ) ) {
		update_option( SN_FAMILY_DRIFT_LAST_OPTION, $rec, false );
		update_option( SN_FAMILY_DRIFT_OK_OPTION, $rec, false );
	}
	return $rec;
}

/**
 * What a reader gets: the last attempt and the last good report, never a fetch.
 *
 * @return array{last:array|null,last_ok:array|null}
 */
function sn_family_drift_report() {
	$last = function_exists( 'get_option' ) ? get_option( SN_FAMILY_DRIFT_LAST_OPTION, null ) : null;
	$ok   = function_exists( 'get_option' ) ? get_option( SN_FAMILY_DRIFT_OK_OPTION, null ) : null;
	return array(
		'last'    => is_array( $last ) ? $last : null,
		'last_ok' => is_array( $ok ) ? $ok : null,
	);
}

/**
 * The verdict. PURE. mirror_parity unequal is CRITICAL (the plan's "a RED,
 * not a note"); an instrument that has not measured in 14 days is
 * `recommended`; a family classifying nothing upstream is `recommended`.
 *
 * @param array $r   sn_family_drift_report().
 * @param int   $now
 * @return array{status:string,summary:string}
 */
function sn_family_drift_health( $r, $now ) {
	$last = is_array( $r['last'] ?? null ) ? $r['last'] : null;
	$ok   = is_array( $r['last_ok'] ?? null ) ? $r['last_ok'] : null;
	if ( null === $ok ) {
		return array(
			'status'  => 'recommended',
			'summary' => null === $last
				? 'The family-drift check has never run. Nothing is measured yet.'
				: sprintf( 'The family-drift check has never completed: last attempt failed on "%s". Until it does, the two family enums are unverified.', (string) ( $last['error'] ?? 'unknown' ) ),
		);
	}
	$age = (int) $now - (int) ( $ok['computed_at'] ?? 0 );
	if ( empty( $ok['mirror_parity']['ok'] ) ) {
		$mp = $ok['mirror_parity'];
		return array(
			'status'  => 'critical',
			'summary' => sprintf( 'MIRROR PARITY FAILED: the plugin and the deployed worker disagree on the family enum (plugin-only: %s; worker-only: %s; order %s). Rows classified under a family the other side does not know are silently dropped or mislabelled. Extend BOTH or neither.', implode( ', ', (array) $mp['plugin_only'] ) ?: 'none', implode( ', ', (array) $mp['worker_only'] ) ?: 'none', ! empty( $mp['order_ok'] ) ? 'matches' : 'DIFFERS' ),
		);
	}
	if ( $age > SN_FAMILY_DRIFT_STALE_SECS ) {
		return array(
			'status'  => 'recommended',
			'summary' => sprintf( 'The last completed family-drift run is %d days old (last attempt: %s). The enums may have drifted since.', (int) floor( $age / DAY_IN_SECONDS ), null !== $last && 'ok' !== ( $last['status'] ?? '' ) ? 'failed on "' . (string) ( $last['error'] ?? '' ) . '"' : 'ok' ),
		);
	}
	// READ-TIME exemption (v13.77.0). v13.74.0 exempted unobservable families at
	// COMPUTE time only, inside sn_family_drift_run(). This function reads a
	// STORED report, so every record written before that release still carries
	// apple-ai in ours_unmatched and still rendered "either the vendor is gone or
	// its user agents changed" — the sentence the exemption exists to stop, for up
	// to a week after the fix shipped, on an installed and correct plugin.
	//
	// A fix applied at write time leaves every already-written record wrong. The
	// verdict must not depend on WHEN its input was computed, so the filter is
	// applied to whatever it is handed and the exempted families are named either
	// way.
	$ok['unobservable']   = array_values( array_unique( array_merge(
		(array) ( $ok['unobservable'] ?? array() ),
		array_intersect( (array) ( $ok['ours_unmatched'] ?? array() ), SN_FAMILY_DRIFT_UNOBSERVABLE )
	) ) );
	$ok['ours_unmatched'] = array_values( array_diff( (array) ( $ok['ours_unmatched'] ?? array() ), SN_FAMILY_DRIFT_UNOBSERVABLE ) );

	if ( ! empty( $ok['ours_unmatched'] ) ) {
		return array(
			'status'  => 'recommended',
			'summary' => sprintf( 'Enums agree, but %d famil%s classif%s nothing in the upstream corpus: %s. Either the vendor is gone or its user agents changed.', count( $ok['ours_unmatched'] ), 1 === count( $ok['ours_unmatched'] ) ? 'y' : 'ies', 1 === count( $ok['ours_unmatched'] ) ? 'ies' : 'y', implode( ', ', (array) $ok['ours_unmatched'] ) ),
		);
	}
	// The unobservable exemption is NAMED here, not swallowed. "Every family
	// still classifies something upstream" would be false the moment a family is
	// exempt, and a verdict that overstates its own coverage is worse than the
	// alarm it replaced.
	$sn_unobs = (array) ( $ok['unobservable'] ?? array() );
	$sn_cover = $sn_unobs
		? sprintf( 'every family classifies something upstream except %d unobservable by construction (%s)', count( $sn_unobs ), implode( ', ', $sn_unobs ) )
		: 'every family still classifies something upstream';
	return array(
		'status'  => 'good',
		'summary' => sprintf( 'Plugin and deployed worker agree on %d families (commit %s); %s. %d upstream tag(s) no family claims, %d operator(s) absent from the AI families, %d respect flip(s) since the pin — read the family_drift section for the rows.', (int) ( $ok['counts']['worker_families'] ?? 0 ), substr( (string) ( $ok['sources']['worker_commit'] ?? '' ), 0, 7 ), $sn_cover, count( (array) ( $ok['upstream_unmapped'] ?? array() ) ), count( (array) ( $ok['vendor_gap'] ?? array() ) ), count( (array) ( $ok['respect_flips'] ?? array() ) ) ),
	);
}

/** Core Site Health registration (direct test). */
function sn_family_drift_register_site_health_test( $tests ) {
	$tests['direct']['sn_family_drift'] = array(
		'label' => __( 'Signal & Noise crawler-family enum drift', 'signal-and-noise-tools' ),
		'test'  => 'sn_family_drift_site_health_result',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'sn_family_drift_register_site_health_test' );

/** The Site Health row. */
function sn_family_drift_site_health_result() {
	$v = sn_family_drift_health( sn_family_drift_report(), time() );
	return array(
		'label'       => __( 'Signal & Noise crawler-family enum drift', 'signal-and-noise-tools' ),
		'status'      => in_array( $v['status'], array( 'good', 'recommended', 'critical' ), true ) ? $v['status'] : 'recommended',
		'badge'       => array( 'label' => __( 'Security', 'signal-and-noise-tools' ), 'color' => 'blue' ),
		'description' => '<p>' . esc_html( $v['summary'] ) . '</p>',
		'test'        => 'sn_family_drift',
	);
}

// Always-on weekly. Not opt-in: an enum mirror that is only checked when
// someone remembers to switch it on is the failure this exists to end.
add_action( 'init', function () {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_FAMILY_DRIFT_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', SN_FAMILY_DRIFT_HOOK );
	}
} );
add_action( SN_FAMILY_DRIFT_HOOK, 'sn_family_drift_run' );
