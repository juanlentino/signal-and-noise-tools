<?php
/**
 * Signal & Noise: what needs attention, each problem said once, in the same
 * sentence on both twins.
 *
 * The items: health findings, a stale or missing scan, theme items served
 * from the database, cron events with no handler, and a failed deploy. Each
 * carries the system it is about (`label`, the same word as that system's
 * card), so S&N Home lists a problem once (2026.10.4 audit: "Health, 3
 * findings" sat over "3 health findings"), and its `text` is the problem as
 * a sentence, which the classic strip prints too. Split out of
 * admin-tab-dashboard.php (2026.10.4 audit, batch 4).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The attention items, in reading order.
 *
 * @param array $runs           Merged deploy runs, newest first.
 * @param int   $override_count DB template/navigation override count.
 * @return array<int,array{label:string,text:string,href:string}>
 */
function snt_dashboard_attention_items( $runs, $override_count ) {
	$items  = array();
	$health = admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' );

	if ( function_exists( 'sn_health_last_scan' ) ) {
		$scan = sn_health_last_scan();
		if ( is_array( $scan ) ) {
			$findings = sn_health_finding_total( $scan );
			if ( $findings > 0 ) {
				/* translators: %s: health findings. */
				$items[] = array( 'label' => 'Health', 'text' => sprintf( _n( '%s health finding in the last scan.', '%s health findings in the last scan.', $findings, 'signal-and-noise-tools' ), number_format_i18n( $findings ) ), 'href' => $health );
			}
			$ttl = defined( 'SN_HEALTH_CACHE_TTL' ) ? (int) SN_HEALTH_CACHE_TTL : DAY_IN_SECONDS;
			if ( ! empty( $scan['scanned_at'] ) && ( time() - (int) $scan['scanned_at'] ) > $ttl ) {
				/* translators: %s: how long ago the scan ran ("2 days"). */
				$items[] = array( 'label' => 'Health', 'text' => sprintf( __( 'The health scan is stale: it last ran %s ago.', 'signal-and-noise-tools' ), human_time_diff( (int) $scan['scanned_at'], time() ) ), 'href' => $health );
			}
		} else {
			$items[] = array( 'label' => 'Health', 'text' => __( 'No health scan has run yet.', 'signal-and-noise-tools' ), 'href' => $health );
		}
	}

	$n = (int) $override_count;
	if ( $n > 0 ) {
		$items[] = array(
			'label' => __( 'Database overrides', 'signal-and-noise-tools' ),
			/* translators: %s: theme items overridden in the database. */
			'text'  => sprintf( _n( '%s theme item is served from the database, not from the theme files.', '%s theme items are served from the database, not from the theme files.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) ),
			'href'  => admin_url( 'admin.php?page=sn-theme-options&tab=dashboard#sn-dash-diagnostics' ),
		);
	}

	if ( function_exists( 'snt_cron_summary_for_localize' ) ) {
		$orphans = (int) ( snt_cron_summary_for_localize()['orphans'] ?? 0 );
		if ( $orphans > 0 ) {
			$items[] = array(
				'label' => 'Cron',
				/* translators: %s: cron events with no handler. */
				'text'  => sprintf( _n( '%s scheduled cron event has no handler, so nothing runs when it fires.', '%s scheduled cron events have no handler, so nothing runs when they fire.', $orphans, 'signal-and-noise-tools' ), number_format_i18n( $orphans ) ),
				'href'  => admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=cron' ),
			);
		}
	}

	foreach ( snt_dashboard_failed_deploys( $runs ) as $failed ) {
		$what = snt_dashboard_short_repo( $failed['repo'] );
		$what = '' !== $what ? $what . ' ' : '';
		$items[] = array(
			'label' => 'Deploys',
			'text'  => 'failure' === $failed['conclusion']
				/* translators: %s: "plugin " or "theme " (with its space), or nothing. */
				? sprintf( __( 'The newest %sdeploy failed.', 'signal-and-noise-tools' ), $what )
				/* translators: 1: "plugin " or "theme " (with its space), or nothing, 2: how it ended ("timed out"). */
				: sprintf( __( 'The newest %1$sdeploy did not succeed: %2$s.', 'signal-and-noise-tools' ), $what, str_replace( '_', ' ', $failed['conclusion'] ) ),
			'href'  => $failed['href'],
		);
	}

	return $items;
}

/**
 * The classic strip: the same sentences as a list, each a link to the screen
 * that fixes it, only when something is off.
 *
 * @param array $runs           Merged deploy runs.
 * @param int   $override_count Database overrides.
 * @return void
 */
function snt_dashboard_render_attention_strip( $runs, $override_count ) {
	$items = snt_dashboard_attention_items( $runs, $override_count );
	if ( empty( $items ) ) {
		return;
	}
	$lis = '';
	foreach ( $items as $item ) {
		$href    = (string) $item['href'];
		$offsite = 0 === strpos( $href, 'https://github.com/' );
		$lis    .= '<li>' . ( '' === $href ? esc_html( $item['text'] ) : '<a href="' . esc_url( $href ) . '"' . ( $offsite ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $item['text'] ) . '</a>' ) . '</li>';
	}
	// The lead paragraph carries its own class, so the wrap is ours; wp_admin_notice() runs wp_kses_post on the whole.
	wp_admin_notice(
		'<p class="sn-attention-strip__lead"><strong>' . esc_html__( 'Needs attention:', 'signal-and-noise-tools' ) . '</strong></p><ul class="sn-attention-strip__list">' . $lis . '</ul>',
		array( 'type' => 'warning', 'additional_classes' => array( 'inline', 'sn-attention-strip' ), 'paragraph_wrap' => false )
	);
}

/**
 * The classic diagnostics block: the override list, open, under a heading,
 * when any exist. 2026.10.4 audit: it was a fold whose summary said "click to
 * expand" on a box that was already open; the native twin is a plain list.
 *
 * @return void
 */
function snt_dashboard_render_diagnostics() {
	$names = snt_dashboard_override_names(
		get_posts(
			array(
				'post_type'      => snt_dashboard_override_post_types(),
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		)
	);
	$n = count( $names );
	if ( 0 === $n ) {
		return;
	}
	echo '<h2 class="sn-section-h" id="sn-dash-diagnostics">' . esc_html__( 'Database overrides', 'signal-and-noise-tools' ) . '</h2>';
	/* translators: %s: theme items overridden in the database. */
	echo '<p class="sn-prose">' . esc_html( sprintf( _n( '%s theme item is served from the database, not from the theme files.', '%s theme items are served from the database, not from the theme files.', $n, 'signal-and-noise-tools' ), number_format_i18n( $n ) ) ) . '</p>';
	echo '<ul class="sn-override-list">';
	foreach ( $names as $name ) {
		echo '<li><code>' . esc_html( $name ) . '</code></li>';
	}
	echo '</ul>';
}
