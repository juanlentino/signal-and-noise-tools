<?php
/**
 * Signal & Noise — Dashboard external-API summary.
 *
 * The rate-limit line, and the test that decides whether it earns its space at
 * all. A rate limit is interesting at 4% remaining and noise at 99%, so this
 * surfaces only when a host is warn or crit.
 *
 * @package SignalNoiseTools
 * @since 11.28.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API summary — single line + inline Refresh link. Promotes to a
 * notice-warning at the top if any host is critical (<10% remaining).
 */
function snt_dashboard_render_api_summary() {
	$statuses = snt_rate_limit_all_statuses();
	$crit     = array();
	$items    = array();
	$sep      = '<span class="sn-api-summary__sep" aria-hidden="true">&middot;</span>';

	foreach ( $statuses as $host => $info ) {
		$snap  = $info['snapshot'];
		$label = $info['label'];
		// v4.5.5: only render a host that has actually reported a rate-limit
		// snapshot. Two of the three tracked hosts never will: Cloudflare uses
		// non-standard `Ratelimit`/`Ratelimit-Policy` response headers (not the
		// `X-RateLimit-*` set inc/api-rate-monitor.php parses), and the Plausible
		// stats API emits no rate-limit headers at all (600/h, documented-only).
		// A permanent "—" implied "tracked, no data yet" — misleading. Omitting
		// the host is self-healing: if it ever reports, it appears automatically.
		// GitHub (polled by the update-checker, returns X-RateLimit-*) still shows.
		if ( ! $snap ) {
			continue;
		}
		$pct       = $snap['remaining'] / max( 1, $snap['limit'] );
		$state_cls = 'sn-api-summary__item';
		if ( $pct < 0.10 ) {
			$state_cls .= ' sn-api-summary__item--crit';
			$crit[]     = $label;
		} elseif ( $pct < 0.25 ) {
			$state_cls .= ' sn-api-summary__item--warn';
		}
		// v9.54.0: ALWAYS print the snapshot's age. This readout is recorded
		// only from responses that CARRY x-ratelimit-* headers — so a 401 (bad
		// credential: GitHub sends no rate headers) and a WP_Error (timeout:
		// never reaches the http_response filter) both leave it frozen at the
		// last success. During the 2026-07-16 incident it showed a confident
		// "4,971/5,000" while every single call was failing, and it was the most
		// misleading thing on the page. A number that can only update on success
		// must show its age, or it is a fossil posing as a live reading.
		$age_html = '';
		if ( ! empty( $snap['fetched_at'] ) ) {
			$age_html = sprintf(
				' <span class="sn-api-summary__age">%s</span>',
				esc_html( sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 mins". */
					__( 'as of %s ago', 'signal-and-noise-tools' ),
					human_time_diff( (int) $snap['fetched_at'], time() )
				) )
			);
		}
		$items[] = sprintf(
			'<span class="%s">%s: <span class="sn-mono">%s/%s</span>%s</span>',
			esc_attr( $state_cls ),
			esc_html( $label ),
			esc_html( number_format_i18n( $snap['remaining'] ) ),
			esc_html( number_format_i18n( $snap['limit'] ) ),
			$age_html // Already escaped above.
		);
	}

	// If any host is critical, surface a notice ABOVE everything (rare event).
	if ( ! empty( $crit ) ) {
		echo '<div class="notice notice-warning inline sn-notice-spacing"><p>';
		printf(
			/* translators: %s: comma-separated host labels */
			esc_html__( 'Rate limit critical: %s. The site may temporarily lose access to these services.', 'signal-and-noise-tools' ),
			esc_html( implode( ', ', $crit ) )
		);
		echo '</p></div>';
	}

	$refresh_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=sn_force_update_check' ),
		'sn_force_update_check',
		'sn_force_update_check_nonce'
	);

	echo '<h2 class="sn-section-h">External APIs</h2>';
	echo '<p class="sn-api-summary">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $items entry is sprintf()-built with esc_attr/esc_html on every field; $sep is static markup.
	echo implode( ' ' . $sep . ' ', $items );
	// Separator before the Refresh link only when at least one host item
	// rendered — avoids a leading "· Refresh" when no host has a snapshot
	// (unreachable in practice since GitHub is polled by the update-checker,
	// but keeps the markup clean if it ever happens). (v4.5.5)
	if ( ! empty( $items ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sep is static, hardcoded markup.
		echo ' ' . $sep . ' ';
	}
	echo '<a class="button-link" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh now', 'signal-and-noise-tools' ) . '</a>';
	echo '</p>';
}

/**
 * Is the external-API rate picture worth the space? (v11.28.0)
 *
 * A rate limit is interesting at 4% remaining and noise at 99%, so the summary
 * surfaces only when a host is actually low. Reuses the module's own bucket
 * classifier rather than inventing a second threshold.
 *
 * `unknown` deliberately does NOT surface. Elsewhere on this Dashboard an
 * unmeasured probe forces its zone to `unknown`, because a claim of health
 * needs evidence. This is the opposite case: the summary makes no claim when
 * it is hidden, and a never-yet-fetched rate snapshot on a fresh install is
 * not news.
 *
 * @since 11.28.0
 * @return bool
 */
function snt_dashboard_api_summary_is_notable() {
	if ( ! function_exists( 'snt_rate_limit_all_statuses' ) || ! function_exists( 'snt_rate_limit_state' ) ) {
		return false;
	}
	foreach ( snt_rate_limit_all_statuses() as $row ) {
		$state = snt_rate_limit_state( $row['snapshot'] ?? null );
		if ( 'warn' === $state || 'crit' === $state ) {
			return true;
		}
	}
	return false;
}
