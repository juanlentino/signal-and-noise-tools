<?php
/**
 * Signal & Noise Tools — analytics-integrity health check (12th Content-Health check).
 *
 * Closes Phase A's P0.4 for real (v9.65.0). The never-invert guard — rollup
 * side (inc/analytics-rollup.php upsert) and read side (inc/analytics-read.php,
 * sn_analytics_read_integrity_guard) — has written a timestamped payload to the
 * `sn_analytics_integrity_alert` option since v9.63.0 whenever a human range
 * shows `views < pageview_visits` (arithmetically impossible by construction:
 * a genuine rollup/sampling bug, values served un-clamped). Until this check
 * existed NOTHING read that option — the docblocks said "the Health scan reads
 * it" while the alarm rang into a void. This is the reader.
 *
 * Behavior matrix:
 *   - option absent            → check PASSES ("no violations recorded").
 *   - option present (any age) → check FLAGS, surfacing when (age + UTC stamp)
 *                                and what inverted (values + day/path or range).
 *   - a violation record NEVER auto-expires: a stale record still flags, and
 *     the finding says "last violation Xd ago" rather than pretending none
 *     happened. Clearing is the owner's explicit call (delete the option)
 *     after the underlying bug is investigated.
 *
 * Detection-only — the fix is a rollup/worker investigation, not a post
 * mutation, so it is NOT in the AI-suggest set (mirrors the Cloudflare-headers
 * and edge-workers checks). Read-only: never mutates or clears the alert.
 *
 * @package SignalNoiseTools
 * @since 9.65.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the findings array from the stored alert payload. PURE (no I/O, clock
 * injected) so the shape + staleness wording is exhaustively testable.
 *
 * Handles both writer shapes:
 *   rollup guard: { time, day, path, class, views, pageview_visits }
 *   read guard:   { time, scope:'read-range', from, to, class, views, pageview_visits }
 *
 * @since 9.65.0
 * @param mixed $alert The stored sn_analytics_integrity_alert value (any type).
 * @param int   $now   Current unix time.
 * @return array[] Zero or one finding row.
 */
function sn_health_analytics_integrity_findings( $alert, $now ) {
	if ( ! is_array( $alert ) ) {
		// Absent (false) or corrupt — not a recorded violation. The guards only
		// ever write a full array payload.
		return array();
	}

	$views = (int) ( $alert['views'] ?? 0 );
	$gated = (int) ( $alert['pageview_visits'] ?? 0 );
	$class = (string) ( $alert['class'] ?? 'human' );

	// Scope: the read guard stamps scope=read-range with from/to; the rollup
	// guard carries day/path.
	if ( 'read-range' === (string) ( $alert['scope'] ?? '' ) ) {
		$scope = sprintf( 'range %s..%s', (string) ( $alert['from'] ?? '?' ), (string) ( $alert['to'] ?? '?' ) );
	} elseif ( isset( $alert['day'] ) ) {
		$scope = sprintf( 'day %s · %s', (string) $alert['day'], (string) ( $alert['path'] ?? '?' ) );
	} else {
		$scope = 'unknown scope';
	}

	// Staleness, stated honestly: the record never expires, so the age is part
	// of the finding — "last violation Xd ago", "today", or "unknown time" when
	// the payload carries no usable stamp (never a fabricated age).
	$ts = (int) ( $alert['time'] ?? 0 );
	if ( $ts > 0 ) {
		$days = max( 0, (int) floor( ( (int) $now - $ts ) / DAY_IN_SECONDS ) );
		$age  = ( 0 === $days )
			? sprintf( 'last violation today (%s)', gmdate( 'Y-m-d H:i \U\T\C', $ts ) )
			: sprintf( 'last violation %dd ago (%s)', $days, gmdate( 'Y-m-d H:i \U\T\C', $ts ) );
	} else {
		$age = 'recorded at an unknown time';
	}

	return array(
		array(
			'subject_type'  => 'analytics_integrity',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => $scope,
			'edit_url'      => '',
			'note'          => sprintf(
				'The never-invert guard recorded views < pageview_visits (%1$d < %2$d) for %3$s (class %4$s) — %5$s. That inequality is impossible by construction, so this is a genuine rollup/sampling bug; the values were served un-clamped and the record stays until cleared.',
				$views,
				$gated,
				$scope,
				$class,
				$age
			),
		),
	);
}

/**
 * CHECK 12: analytics never-invert integrity.
 *
 * @since 9.65.0
 * @return array pack_check envelope.
 */
function sn_health_check_analytics_integrity() {
	$label = 'Analytics integrity';
	$opt   = defined( 'SN_ANALYTICS_INTEGRITY_ALERT_OPT' ) ? SN_ANALYTICS_INTEGRITY_ALERT_OPT : 'sn_analytics_integrity_alert';
	$alert = get_option( $opt );

	if ( ! is_array( $alert ) ) {
		return sn_health_pack_check(
			$label,
			array(),
			'Analytics integrity: no violations recorded — the never-invert guard (views >= pageview_visits, rollup + read side) has not fired.'
		);
	}

	return sn_health_pack_check(
		$label,
		sn_health_analytics_integrity_findings( $alert, time() ),
		'views < pageview_visits is arithmetically impossible (every gated visit implies at least one view), so a recorded violation is a genuine rollup/sampling bug — investigate the AE rollup queries and the collector worker for the recorded scope. The record never expires on its own: after the investigation, clear it explicitly (`wp option delete sn_analytics_integrity_alert`) to reset this check. Values were served un-clamped throughout — the alarm is the feature.'
	);
}
