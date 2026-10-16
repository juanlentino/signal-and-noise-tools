<?php
/**
 * Signal & Noise Tools: admin-tab-dashboard.php, the tab renderer, the
 * glance cards, and the version and worker cards.
 *
 * Split from inc/admin-tab-dashboard.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic tab: the same data, painted as wp-admin markup.
 *
 * @return void
 */
function snt_dashboard_tab_render() {
	$data = snt_dashboard_tab_data();
	if ( null === $data ) {
		return;
	}
	sn_dash_render_screen(
		$data['checks'],
		$data['components'],
		$data['signals'],
		array(
			'subline'           => $data['subline'],
			'series'            => $data['series'],
			'panels'            => $data['panels'],
			'check_updates_url' => $data['check_updates_url'],
		)
	);

	// ── 2. ATTENTION STRIP ── one warning row, only when something is off.
	snt_dashboard_render_attention_strip( $data['runs'], $data['override_count'] );

	// ── DIAGNOSTICS ── the database overrides, when any exist.
	snt_dashboard_render_diagnostics();
}

/* ════════════════════════════════════════════════════════════════════════
 * GLANCE GRID + ATTENTION STRIP (Phase 1 redesign)
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * Build the first-glance card list for sn_admin_glance_grid().
 *
 * Every card is sourced ONLY from data the plugin already computes, and every
 * non-version accessor is function_exists / config guarded: when a source is
 * genuinely absent the card is OMITTED (never fabricated). Theme + Plugin
 * version cards always render (their accessors live in this file). The pill on
 * each card reuses the existing .sn-pill ok/warn/err vocabulary.
 *
 * @since 6.43.0
 * @param array  $theme           snt_deploy_status_for('theme') struct.
 * @param array  $plugin          snt_deploy_status_for('plugin') struct.
 * @param array  $runs            Merged deploy runs (for the Deploys card).
 * @param string $last_deploy_ago Pre-formatted "14m ago" / "—" label.
 * @return array<int,array<string,mixed>> Cards for sn_admin_glance_grid().
 */
function snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago ) {
	$cards = array();

	// ── Theme + Plugin version + update state (always available). ──
	$cards[] = snt_dashboard_version_card( 'Theme', $theme );
	$cards[] = snt_dashboard_version_card( 'Plugin', $plugin );

	// ── Cloudflare workers (Deploy Status): always render a row per worker.
	// probe_budget=1 so a cold page load never fans out five live HTTP calls;
	// warm cache hits are free. Unprobeable / failed probes stay as UNKNOWN.
	if ( function_exists( 'snt_deploy_workers_status' ) ) {
		foreach ( snt_deploy_workers_status( array( 'probe_budget' => 1 ) ) as $worker ) {
			$cards[] = snt_dashboard_worker_card( $worker );
		}
	}

	// ── Deploys: last age + count in 24h. ──
	$count_24h = snt_dashboard_count_recent_runs( (array) $runs, DAY_IN_SECONDS );
	$cards[]   = array(
		'label'     => 'Deploys',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' ),
		'value'     => (string) $last_deploy_ago,
		'meta_html' => esc_html(
			empty( $runs )
				? 'no recent runs'
				: sprintf( '%d in last 24h', (int) $count_24h )
		),
	);

	// ── Health: total findings + scan age. ──
	if ( function_exists( 'sn_health_last_scan' ) ) {
		$scan = sn_health_last_scan();
		if ( is_array( $scan ) ) {
			// Scoped inside sn_health_finding_total() since v11.16.1.
			$findings = sn_health_finding_total( $scan );
			$age  = ! empty( $scan['scanned_at'] ) ? human_time_diff( (int) $scan['scanned_at'], time() ) . ' ago' : 'age unknown';
			$cards[] = array(
				'label'     => 'Health',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
				'value'     => sprintf( '%d finding%s', $findings, 1 === $findings ? '' : 's' ),
				'pill'      => array(
					'kind' => $findings > 0 ? 'warn' : 'ok',
					'text' => $findings > 0 ? 'issues found' : 'all clear',
				),
				'meta_html' => esc_html( 'scanned ' . $age ),
			);
		} else {
			$cards[] = array(
				'label'     => 'Health',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
				'value'     => 'no scan',
				'pill'      => array( 'kind' => 'warn', 'text' => 'run a scan' ),
			);
		}
	}

	// ── AI spend 30d: cost + calls. ──
	if ( function_exists( 'snt_ai_usage_summary' ) ) {
		$s30   = snt_ai_usage_summary( 30 );
		$calls = (int) ( $s30['calls'] ?? 0 );
		$cost  = (float) ( $s30['cost'] ?? 0 );
		$cards[] = array(
			'label'     => 'AI spend 30d',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=ai&sub=models-budget' ),
			'value'     => snt_dashboard_fmt_cost( $cost ),
			'meta_html' => esc_html( sprintf( '%s call%s', number_format_i18n( $calls ), 1 === $calls ? '' : 's' ) ),
		);
	}

	// ── Cron: scheduled event count + orphan flag. ──
	if ( function_exists( 'snt_cron_summary_for_localize' ) ) {
		$cron    = snt_cron_summary_for_localize();
		$total   = (int) ( $cron['total'] ?? 0 );
		$orphans = (int) ( $cron['orphans'] ?? 0 );
		$cards[] = array(
			'label'     => 'Cron',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=cron' ),
			'value'     => sprintf( '%d event%s', $total, 1 === $total ? '' : 's' ),
			'pill'      => $orphans > 0
				? array( 'kind' => 'warn', 'text' => sprintf( '%d orphan%s', $orphans, 1 === $orphans ? '' : 's' ) )
				: array( 'kind' => 'ok', 'text' => 'healthy' ),
		);
	}

	// ── Views 7d + week-over-week delta (reuse the analytics delta accessor;
	// do NOT recompute). ──
	if ( function_exists( 'sn_analytics_config' ) && sn_analytics_config()
		&& function_exists( 'sn_analytics_period_deltas' ) ) {
		list( $from, $to ) = snt_dashboard_week();
		$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$views = (int) ( $deltas['views']['current'] ?? 0 );
			$cards[] = array(
				'label'     => 'Views 7d',
			'href'      => snt_analytics_page_url(),
				'value'     => number_format_i18n( $views ),
				'meta_html' => snt_dashboard_delta_badge_html( $deltas['views'] ),
			);
		}
	}

	// ── Provenance: confirmed count + pending pill. Only when provenance is
	// active AND the Worker is configured — an unconfigured install dispatches
	// nothing, so a "0 confirmed / all anchored" card would imply integrity that
	// isn't there. Omit it instead (this file never fabricates a card).
	//
	// Counts come from snt_prov_anchor_overview() — each Note's LATEST anchor,
	// the SAME source the anchor-status ability serves — never a sum over
	// every historical commit (the old sn_prov_admin_system_status() path
	// counted all chain versions, so the card and the ability disagreed the
	// moment any note carried more than one version). A pending version >= 2
	// is an UPDATE of a published note re-anchoring, named as such on the
	// pill instead of blending into the new-note count. ──
	if ( function_exists( 'sn_prov_active' ) && sn_prov_active()
		&& function_exists( 'sn_prov_worker_url' ) && '' !== sn_prov_worker_url()
		&& function_exists( 'snt_prov_anchor_overview' ) ) {
		$ov        = snt_prov_anchor_overview();
		$confirmed = (int) ( $ov['confirmed'] ?? 0 );
		$rows      = isset( $ov['pending'] ) && is_array( $ov['pending'] ) ? $ov['pending'] : array();
		$updates   = 0;
		foreach ( $rows as $row ) {
			if ( (int) ( $row['version'] ?? 0 ) >= 2 ) {
				++$updates;
			}
		}
		$fresh = count( $rows ) - $updates;
		$parts = array();
		if ( $fresh > 0 ) {
			$parts[] = sprintf( '%s pending', number_format_i18n( $fresh ) );
		}
		if ( $updates > 0 ) {
			$parts[] = sprintf( ( 1 === $updates ? '%s update anchoring' : '%s updates anchoring' ), number_format_i18n( $updates ) );
		}
		$cards[] = array(
			'label' => 'Provenance',
			'href'      => admin_url( 'admin.php?page=sn-theme-options&tab=tools&sub=provenance' ),
			'value' => sprintf( '%s confirmed', number_format_i18n( $confirmed ) ),
			'pill'  => empty( $parts )
				? array( 'kind' => 'ok', 'text' => 'all anchored' )
				: array( 'kind' => 'warn', 'text' => implode( ' · ', $parts ) ),
		);
	}

	// Arrange into the two-row grouping — release/integrity, then runtime/
	// audience — independent of build order. Absent-source cards never appear;
	// present cards follow this relative order. usort is stable on PHP 8+ (the
	// plugin's floor), and every built card is in the map (an unlisted label
	// sorts last, defensively).
	// Worker Deploy Status labels sit under Theme/Plugin; "Provenance edge" is
	// the worker semver card (distinct from the "Provenance" anchor card).
	$order = array( 'Theme', 'Plugin', 'Analytics', 'Provenance edge', 'Login guard', 'Remote door', 'Rights signals', 'Deploys', 'Provenance', 'Health', 'Cron', 'Views 7d', 'AI spend 30d' );
	$rank  = array_flip( $order );
	usort( $cards, function ( $a, $b ) use ( $rank ) {
		$ra = $rank[ is_array( $a ) ? ( $a['label'] ?? '' ) : '' ] ?? 999;
		$rb = $rank[ is_array( $b ) ? ( $b['label'] ?? '' ) : '' ] ?? 999;
		return $ra <=> $rb;
	} );

	return $cards;
}

/**
 * One version glance card (Theme / Plugin), reusing the version-state pill.
 *
 * @param string $label 'Theme' | 'Plugin'.
 * @param array  $pkg   snt_deploy_status_for() struct.
 * @return array A glance card.
 */
function snt_dashboard_version_card( $label, $pkg ) {
	$pill = array( 'kind' => 'err', 'text' => 'unknown' );
	if ( 'ok' === ( $pkg['state'] ?? '' ) ) {
		$pill = array( 'kind' => 'ok', 'text' => 'up to date' );
	} elseif ( 'available' === ( $pkg['state'] ?? '' ) || 'behind' === ( $pkg['state'] ?? '' ) ) {
		// Workers use "behind"; theme/plugin use "available". Same warn pill.
		$pill = array( 'kind' => 'warn', 'text' => 'v' . (string) ( $pkg['latest'] ?? '' ) . ' available' );
	}
	$card = array(
		'label' => $label,
		'value' => ( $pkg['current'] ?? '' ) ?: __( 'not read', 'signal-and-noise-tools' ),
		'pill'  => $pill,
	);
	// v9.54.0: an "unknown" pill that can't say why is a dead end. When the
	// fetch layer recorded a cause, print it under the card — this is the
	// difference between "something's wrong" and "rotate SNT_GITHUB_TOKEN".
	$reason = (string) ( $pkg['reason'] ?? '' );
	if ( '' !== $reason ) {
		$card['meta_html'] = esc_html( $reason );
	}
	return $card;
}

/**
 * Glance card for one Cloudflare worker row (Deploy Status).
 * Maps live/latest/state onto the theme/plugin version-card shape.
 *
 * @param array $worker snt_deploy_worker_status_for() struct.
 * @return array Glance card.
 */
function snt_dashboard_worker_card( $worker ) {
	$live = (string) ( $worker['live'] ?? '' );
	$pkg  = array(
		'current' => '' !== $live ? $live : __( 'not read', 'signal-and-noise-tools' ),
		'latest'  => (string) ( $worker['latest'] ?? '' ),
		'state'   => (string) ( $worker['state'] ?? 'unknown' ),
		'reason'  => (string) ( $worker['reason'] ?? '' ),
	);
	$card = snt_dashboard_version_card( (string) ( $worker['label'] ?? 'Worker' ), $pkg );
	// Cold is not broken: a never-probed row (budget-skipped, warm cron
	// pending) gets an amber "warming…" pill, not the red "unknown" that
	// belongs to probes that actually FAILED. Observed live after the
	// v11.11.4 install: four alarm-red cards whose only sin was a cold cache.
	if ( 'unknown' === $pkg['state'] && 'warming' === $pkg['reason'] ) {
		$card['pill']      = array( 'kind' => 'warn', 'text' => 'warming…' );
		$card['meta_html'] = esc_html( 'first probe scheduled' );
		// v11.16.0: amber, but not urgent. A purge clears every worker transient
		// at once, so without this the next Dashboard load puts four cold caches
		// above a real finding — which is the same sentence this branch already
		// writes ("cold is not broken"), applied to the ORDER as well as the
		// colour.
		$card['attention'] = false;
	}
	return $card;
}

