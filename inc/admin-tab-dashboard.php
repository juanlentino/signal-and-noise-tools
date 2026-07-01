<?php
/**
 * Signal & Noise Tools — Dashboard tab renderer.
 *
 * Owns the full Dashboard tab content via the `sn_admin_dashboard_extras`
 * action hook (fired in inc/admin-page.php). The legacy Status table +
 * Override details + Actions card grid that previously rendered inline
 * in admin-page.php were absorbed into this file in v1.14.0.
 *
 * Composition (top to bottom), after the Phase 1 "open and wide" redesign:
 *   1. GLANCE GRID       — full-width first-glance hero (theme/plugin versions,
 *                          deploys, health findings, AI spend 30d, cron, login
 *                          blocks, views 7d). Built by snt_dashboard_glance_cards()
 *                          from existing accessors only; absent sources are
 *                          omitted. Rendered via sn_admin_glance_grid().
 *   2. ATTENTION STRIP   — one bg-warning row, shown only when something is off
 *                          (health findings, DB overrides, cron orphans, stale
 *                          scan, failed deploy), linking to the relevant tab.
 *   3. STATUS SUMMARIES  — External-API + RSS single-line summaries.
 *   4. LOWER ROW         — two columns (.sn-dash-cols): Recent deploys (last 5
 *                          merged GHA runs) on the left, Maintenance 3-card
 *                          action grid on the right. Collapses to one column on
 *                          narrow viewports. Forms POST to sn_handle_admin_post()
 *                          via the existing sn_theme_options_nonce.
 *   5. DIAGNOSTICS       — collapsible override-detail list (only renders
 *                          when there ARE overrides)
 *
 * Design principles (per memory: feedback_no_brutalist_in_admin_ui.md):
 *   - WP-admin native (.button, .notice, .widefat, .form-table where it fits)
 *   - .sn-* classes for composition patterns that don't have a WP-native
 *     equivalent (.sn-glance, .sn-deploy-list, .sn-api-summary)
 *   - NO inline styles — all promoted to assets/admin.css
 *   - NO brutalist treatment (uppercase labels OK in muted secondary text,
 *     but no Bebas-feel display type, no display mono)
 *
 * Verified against WP source (per CLAUDE.md framework-source-first rule):
 *   - admin-post.php reads $action from $_REQUEST, fires admin_post_{$action}
 *     for logged-in users; no automatic nonce verification.
 *   - .button .button-primary .button-secondary are WP-canonical classes
 *     (wp-admin/css/common.css).
 *
 * Replaces inc/deploy-widget.php (v1.12.0, removed v1.13.0) and
 * inc/deploy-status.php (v1.13.0, renamed/redesigned this file in v1.14.0).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DEPLOY_REPOS = array(
	'theme'  => 'juanlentino/signal-and-noise',
	'plugin' => 'juanlentino/signal-and-noise-tools',
);

/**
 * Normalized status struct for one package.
 *
 * @param string $package 'theme' | 'plugin'
 * @return array { current, latest, state ('ok'|'available'|'unknown'), repo }
 */
function snt_deploy_status_for( $package ) {
	if ( 'theme' === $package ) {
		$current = (string) wp_get_theme( 'signal-and-noise' )->get( 'Version' );
		// v4.1.1 (X-01): contract — fetch via filter (theme registers a listener),
		// don't call the theme function directly. WORDPRESS-REFERENCE.md §10 mandates
		// "never let plugin code directly call a theme function — even with
		// function_exists guards." The pre-v4.1.1 direct call worked only because
		// the theme happens to be active on this install; the filter pattern is
		// tolerant of theme-absent/inactive by design.
		$latest  = apply_filters( 'sn_gh_latest_theme_tag_result', null );
	} else {
		$current = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
		// sn_gh_latest_plugin_tag is plugin-owned (inc/wp-update-integration.php) —
		// calling it directly is fine; same repo as the caller.
		$latest  = function_exists( 'sn_gh_latest_plugin_tag' ) ? sn_gh_latest_plugin_tag() : null;
	}
	$latest_version = $latest ? ltrim( $latest, 'v' ) : '';
	$state          = 'unknown';
	if ( $current && $latest_version ) {
		$state = version_compare( $latest_version, $current, '>' ) ? 'available' : 'ok';
	}
	return array(
		'current' => $current,
		'latest'  => $latest_version,
		'state'   => $state,
		'repo'    => SNT_DEPLOY_REPOS[ $package ] ?? '',
	);
}

/**
 * Post types treated as DB overrides on the Dashboard. Single source of
 * truth so the override-count helper + the diagnostics list query stay in
 * sync.
 *
 * @since 4.9.0
 * @return array<string>
 */
function snt_dashboard_override_post_types() {
	return array( 'wp_template', 'wp_template_part', 'wp_navigation' );
}

/**
 * Count of DB template/navigation overrides. Delegates to the same
 * post-type set the Dashboard's diagnostics list queries. Used by both the
 * Site Health Info panel (Task 3) and exposable elsewhere.
 *
 * @since 4.9.0
 * @return int
 */
function snt_dashboard_override_count() {
	$ids = get_posts( array(
		'post_type'      => snt_dashboard_override_post_types(),
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	) );
	return is_array( $ids ) ? count( $ids ) : 0;
}

/**
 * Render the full Dashboard tab content. Hooked at priority 10 (default)
 * because we now OWN the entire Dashboard tab; nothing else listens.
 *
 * Phase 1 "open and wide" redesign: the tab now opens with a first-glance
 * GLANCE GRID (sourced only from accessors the plugin already computes), then a
 * CONDITIONAL attention strip (shown only when something is off), then the
 * External-API / RSS summaries, then a two-column lower row (Recent deploys +
 * Maintenance), and finally the deep diagnostics <details>. This is a
 * render-layer reorganization — no data-layer change.
 */
add_action( 'sn_admin_dashboard_extras', 'snt_dashboard_tab_render' );

function snt_dashboard_tab_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$theme     = snt_deploy_status_for( 'theme' );
	$plugin    = snt_deploy_status_for( 'plugin' );
	// v4.1.4: merge wp-admin Updates installs with GHA workflow runs. Since
	// v1.10.1 (plugin) / v8.5.1 (theme) tag pushes no longer auto-deploy, so
	// the GHA-only feed froze at the last auto-on-tag-push deploy. The
	// deploy-history module records wp-admin installs via the
	// upgrader_process_complete hook; the merged view shows both sources.
	$runs      = function_exists( 'snt_deploy_history_merged' )
		? snt_deploy_history_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();
	$overrides = get_posts( array(
		'post_type'      => snt_dashboard_override_post_types(),
		'posts_per_page' => -1,
		'post_status'    => 'any',
	) );
	$last_deploy_ago = snt_dashboard_last_deploy_label( $runs );

	// ── 1. FIRST-GLANCE GRID ── full-width hero (versions, deploys, health,
	// AI spend, cron, login blocks, views), built only from accessors that
	// actually exist on this install.
	$cards = snt_dashboard_glance_cards( $theme, $plugin, $runs, $last_deploy_ago );
	if ( ! empty( $cards ) ) {
		echo '<section aria-label="Site at a glance">';
		sn_admin_glance_grid( $cards );
		echo '</section>';
	}

	// ── 2. ATTENTION STRIP ── one warning row, only when something is off.
	snt_dashboard_render_attention_strip( $runs, count( $overrides ) );

	// ── 3. STATUS SUMMARY ── external-API + RSS health (single-line scannable).
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		snt_dashboard_render_api_summary();
	}
	if ( function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
		snt_dashboard_render_rss_summary();
	}

	// ── 4. LOWER ROW ── Recent deploys (wider) + Maintenance, two columns
	// that collapse to one on narrow viewports (.sn-dash-cols).
	echo '<div class="sn-dash-cols">';

	// Left: Recent deploys.
	echo '<div class="sn-dash-cols__main">';
	echo '<h2 class="sn-section-h">Recent deploys</h2>';
	if ( empty( $runs ) ) {
		echo '<p class="description"><em>No recent runs (or GitHub API unreachable).</em></p>';
	} else {
		echo '<ul class="sn-deploy-list">';
		foreach ( $runs as $run ) {
			snt_dashboard_render_deploy_row( $run );
		}
		echo '</ul>';
	}
	echo '</div>'; // .sn-dash-cols__main

	// Right: Maintenance 3-card action grid (unchanged actions).
	echo '<div class="sn-dash-cols__side">';
	echo '<h2 class="sn-section-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-card-grid sn-card-grid--dash">';

	// v4.1.6 (U-13): button hierarchy matches action gravity.
	//   - Full Reset is the most destructive (overrides + caches in one go) → button-link-delete (red).
	//   - Purge All Caches is the most-common routine action → button-primary.
	//   - Clear Overrides + Check for Updates are reversible/informational → bare button.
	echo '<div class="sn-card">';
	echo '<strong>Full Reset</strong>';
	echo '<p class="sn-helper">Clears all overrides and purges every cache. Use after theme updates.</p>';
	echo '<button type="submit" name="sn_action" value="full_reset" class="button button-link-delete">Run Full Reset</button>';
	echo '</div>';

	echo '<div class="sn-card">';
	echo '<strong>Clear Overrides</strong>';
	echo '<p class="sn-helper">Removes template, template part, and navigation DB entries.</p>';
	echo '<button type="submit" name="sn_action" value="clear_overrides" class="button">Clear Overrides</button>';
	echo '</div>';

	echo '<div class="sn-card">';
	echo '<strong>Purge Caches</strong>';
	echo '<p class="sn-helper">WP object cache, transients, Breeze page/minification, Varnish.</p>';
	echo '<button type="submit" name="sn_action" value="purge_caches" class="button button-primary">Purge All Caches</button>';
	echo '</div>';

	// v2.5.3: visible UI shortcut for the "tagged a new release, where's
	// the Updates UI?" workflow. Replaces the need to run
	// `gh workflow run deploy.yml --ref vX.Y.Z` for every release.
	//
	// Why this exists: WP's `update_plugins` site transient has a ~12h TTL.
	// Our pre_set_site_transient_update_plugins filter only fires when WP
	// is about to RE-SET that transient — i.e., on cache miss, on
	// WP_FORCE_UPDATE_CHECK, or on `?force-check=1`. Without an explicit
	// re-check, a freshly-tagged release can stay invisible to Updates UI
	// for up to 12 hours. This button is one click → both transients
	// cleared → redirect to update-core.php?force-check=1 → WP repolls →
	// our filter injects the new tag → Updates UI shows it.
	//
	// As a bonus, this is just an admin-bar-free version of the
	// `signal-noise/force-check-updates` ability (Cmd+K path), reachable
	// without depending on the ⌘K palette working.
	// v2.5.3: re-use the existing sn_force_update_check admin-post handler
	// (lower in this file) which clears both transients + redirects to
	// update-core.php?force-check=1. Same handler as the API summary's
	// "Refresh now" link — single source of truth for force-check.
	$check_updates_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=sn_force_update_check' ),
		'sn_force_update_check',
		'sn_force_update_check_nonce'
	);
	echo '<div class="sn-card">';
	echo '<strong>Check for Updates</strong>';
	echo '<p class="sn-helper">Clears the theme + plugin update caches and re-polls GitHub. Use after tagging a new release.</p>';
	echo '<a class="button" href="' . esc_url( $check_updates_url ) . '">Check Now</a>';
	echo '</div>';

	echo '</div>'; // .sn-card-grid--dash
	echo '</form>';
	echo '</div>'; // .sn-dash-cols__side
	echo '</div>'; // .sn-dash-cols

	// ── DIAGNOSTICS ── only when there's anything to show (full-width, below
	// the two-column row). The override count surfaces in the attention strip
	// above; this stays for deep inspection.
	if ( ! empty( $overrides ) ) {
		echo '<h2 class="sn-section-h" id="sn-dash-diagnostics">Diagnostics</h2>';
		echo '<details class="sn-override-details" open>';
		echo '<summary>' . esc_html( sprintf( '%d database override%s — click to expand', count( $overrides ), count( $overrides ) === 1 ? '' : 's' ) ) . '</summary>';
		echo '<ul>';
		foreach ( $overrides as $tpl ) {
			echo '<li><code>' . esc_html( $tpl->post_type ) . '/' . esc_html( $tpl->post_name ) . '</code></li>';
		}
		echo '</ul>';
		echo '</details>';
	}
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

	// ── Deploys: last age + count in 24h. ──
	$count_24h = snt_dashboard_count_recent_runs( (array) $runs, DAY_IN_SECONDS );
	$cards[]   = array(
		'label'     => 'Deploys',
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
			$findings = sn_health_finding_total( $scan );
			$age  = ! empty( $scan['scanned_at'] ) ? human_time_diff( (int) $scan['scanned_at'], time() ) . ' ago' : 'age unknown';
			$cards[] = array(
				'label'     => 'Health',
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
			'value'     => sprintf( '%d event%s', $total, 1 === $total ? '' : 's' ),
			'pill'      => $orphans > 0
				? array( 'kind' => 'warn', 'text' => sprintf( '%d orphan%s', $orphans, 1 === $orphans ? '' : 's' ) )
				: array( 'kind' => 'ok', 'text' => 'healthy' ),
		);
	}

	// ── Login blocks (7d): the same source as the login-defense widget. ──
	if ( function_exists( 'sn_login_defense_headline' ) ) {
		$lg = sn_login_defense_headline();
		if ( is_array( $lg ) && ! empty( $lg['configured'] ) ) {
			$blocked = (int) ( $lg['blocked'] ?? 0 );
			$cards[] = array(
				'label'     => 'Login blocks 7d',
				'value'     => number_format_i18n( $blocked ),
				'meta_html' => esc_html( sprintf( '%d%% block rate', (int) ( $lg['block_rate'] ?? 0 ) ) ),
			);
		}
	}

	// ── Views 7d + week-over-week delta (reuse the analytics delta accessor;
	// do NOT recompute). ──
	if ( function_exists( 'sn_analytics_config' ) && sn_analytics_config()
		&& function_exists( 'sn_analytics_period_deltas' ) ) {
		$from   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$to     = gmdate( 'Y-m-d', time() );
		$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$views = (int) ( $deltas['views']['current'] ?? 0 );
			$cards[] = array(
				'label'     => 'Views 7d',
				'value'     => number_format_i18n( $views ),
				'meta_html' => snt_dashboard_delta_badge_html( $deltas['views'] ),
			);
		}
	}

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
	} elseif ( 'available' === ( $pkg['state'] ?? '' ) ) {
		$pill = array( 'kind' => 'warn', 'text' => 'v' . (string) ( $pkg['latest'] ?? '' ) . ' available' );
	}
	return array(
		'label' => $label,
		'value' => ( $pkg['current'] ?? '' ) ?: '—',
		'pill'  => $pill,
	);
}

/**
 * Format a USD spend estimate with a sub-cent floor (mirrors the Insights tab).
 *
 * @param float $cost
 * @return string
 */
function snt_dashboard_fmt_cost( $cost ) {
	$cost = (float) $cost;
	if ( $cost > 0 && $cost < 0.005 ) {
		return '<$0.01';
	}
	return '$' . number_format_i18n( $cost, 2 );
}

/**
 * Build a pre-escaped week-over-week delta badge from an analytics delta row
 * ({dir, pct}). Returns kses-safe markup for the glance card meta line.
 *
 * @param array $delta {dir:string, pct:?int, current:int, previous:int}
 * @return string Escaped badge markup, or empty string when no comparison.
 */
function snt_dashboard_delta_badge_html( $delta ) {
	$dir = (string) ( $delta['dir'] ?? '' );
	if ( '' === $dir ) {
		return '';
	}
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct ) ? 'flat' : ( ( $pct >= 0 ? '+' : '' ) . (int) $pct . '%' );
	return '<span class="sn-glance-delta sn-glance-delta--' . esc_attr( $dir ) . '">'
		. esc_html( $arrow . ' ' . $text . ' WoW' ) . '</span>';
}

/**
 * Conditional attention strip: a single warning row, shown ONLY when something
 * is off (health findings > 0, DB overrides > 0, cron orphans, stale health
 * scan, or a failed recent deploy), with a link to the relevant tab. Replaces
 * burying the override count in the bottom <details>.
 *
 * @since 6.43.0
 * @param array $runs           Merged deploy runs (to detect a failed deploy).
 * @param int   $override_count DB template/navigation override count.
 * @return void
 */
function snt_dashboard_render_attention_strip( $runs, $override_count ) {
	$items = array();

	// Health findings + staleness.
	if ( function_exists( 'sn_health_last_scan' ) ) {
		$scan = sn_health_last_scan();
		if ( is_array( $scan ) ) {
			$findings = sn_health_finding_total( $scan );
			if ( $findings > 0 ) {
				$items[] = array(
					'text' => sprintf( '%d health finding%s', $findings, 1 === $findings ? '' : 's' ),
					'href' => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
				);
			}
			$ttl = defined( 'SN_HEALTH_CACHE_TTL' ) ? (int) SN_HEALTH_CACHE_TTL : DAY_IN_SECONDS;
			if ( ! empty( $scan['scanned_at'] ) && ( time() - (int) $scan['scanned_at'] ) > $ttl ) {
				$items[] = array(
					'text' => 'health scan is stale',
					'href' => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
				);
			}
		} else {
			$items[] = array(
				'text' => 'no health scan has run',
				'href' => admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=health' ),
			);
		}
	}

	// DB overrides.
	if ( (int) $override_count > 0 ) {
		$items[] = array(
			'text' => sprintf( '%d database override%s', (int) $override_count, 1 === (int) $override_count ? '' : 's' ),
			'href' => admin_url( 'admin.php?page=sn-theme-options&tab=dashboard#sn-dash-diagnostics' ),
		);
	}

	// Cron orphans.
	if ( function_exists( 'snt_cron_summary_for_localize' ) ) {
		$cron = snt_cron_summary_for_localize();
		if ( (int) ( $cron['orphans'] ?? 0 ) > 0 ) {
			$items[] = array(
				'text' => sprintf( '%d orphan cron event%s', (int) $cron['orphans'], 1 === (int) $cron['orphans'] ? '' : 's' ),
				'href' => admin_url( 'admin.php?page=sn-theme-options&tab=connections&sub=cron' ),
			);
		}
	}

	// Failed recent deploy (any non-success conclusion on a completed run).
	foreach ( (array) $runs as $run ) {
		$status     = (string) ( $run['status'] ?? '' );
		$conclusion = (string) ( $run['conclusion'] ?? '' );
		if ( 'completed' === $status && '' !== $conclusion
			&& ! in_array( $conclusion, array( 'success', 'cancelled', 'skipped' ), true ) ) {
			$items[] = array(
				'text' => 'a recent deploy failed',
				'href' => admin_url( 'admin.php?page=sn-theme-options&tab=dashboard' ),
			);
			break;
		}
	}

	if ( empty( $items ) ) {
		return;
	}

	echo '<div class="notice notice-warning inline sn-attention-strip">';
	echo '<p class="sn-attention-strip__lead"><strong>' . esc_html__( 'Needs attention:', 'signal-noise-tools' ) . '</strong> ';
	$links = array();
	foreach ( $items as $item ) {
		$links[] = '<a href="' . esc_url( $item['href'] ) . '">' . esc_html( $item['text'] ) . '</a>';
	}
	// $links entries are individually escaped above; the separator is static.
	echo wp_kses_post( implode( ' &middot; ', $links ) );
	echo '</p></div>';
}

/* ════════════════════════════════════════════════════════════════════════
 * RENDER HELPERS
 * ════════════════════════════════════════════════════════════════════════ */

/** "14m ago" / "—" for the Deploys card. */
function snt_dashboard_last_deploy_label( $runs ) {
	foreach ( $runs as $run ) {
		if ( ! empty( $run['created_at'] ) ) {
			$t = strtotime( $run['created_at'] );
			if ( $t ) {
				return human_time_diff( $t, time() ) . ' ago';
			}
		}
	}
	return '—';
}

/** Count runs within $window seconds of now. */
function snt_dashboard_count_recent_runs( $runs, $window ) {
	$cutoff = time() - $window;
	$n      = 0;
	foreach ( $runs as $run ) {
		if ( empty( $run['created_at'] ) ) {
			continue;
		}
		$t = strtotime( $run['created_at'] );
		if ( $t && $t >= $cutoff ) {
			++$n;
		}
	}
	return $n;
}

function snt_dashboard_render_deploy_row( $run ) {
	$status_class = 'sn-deploy-row__status sn-deploy-row__status--';
	$status_icon  = snt_dashboard_run_glyph( $run, $status_class );
	$repo_short   = snt_dashboard_short_repo( $run['repo'] ?? '' );
	$ref          = $run['ref'] ?? '';
	$duration     = snt_dashboard_duration_label( $run['duration_s'] ?? null );
	$when         = snt_dashboard_relative_time( $run['created_at'] ?? '' );
	$href         = $run['html_url'] ?? '';

	echo '<li class="sn-deploy-row">';
	echo $status_icon; /* already escaped via helper */
	echo '<span class="sn-deploy-row__repo">' . esc_html( $repo_short ) . '</span>';
	echo '<span class="sn-deploy-row__ref"><code>' . esc_html( $ref ) . '</code></span>';
	echo '<span class="sn-deploy-row__duration">' . esc_html( $duration ) . '</span>';
	echo '<span class="sn-deploy-row__when">' . esc_html( $when ) . '</span>';
	if ( $href ) {
		echo '<a class="sn-deploy-row__link" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'View on GitHub', 'signal-noise-tools' ) . '">&#x2197;</a>';
	} else {
		echo '<span></span>';
	}
	echo '</li>';
}

function snt_dashboard_run_glyph( $run, $base_class ) {
	$status     = (string) ( $run['status'] ?? '' );
	$conclusion = (string) ( $run['conclusion'] ?? '' );
	if ( 'in_progress' === $status || 'queued' === $status ) {
		return snt_dashboard_run_glyph_html( $base_class . 'warn', '&middot;', __( 'Running', 'signal-noise-tools' ) );
	}
	if ( 'success' === $conclusion ) {
		return snt_dashboard_run_glyph_html( $base_class . 'ok', '&#x2713;', __( 'Success', 'signal-noise-tools' ) );
	}
	if ( 'cancelled' === $conclusion || 'skipped' === $conclusion ) {
		return snt_dashboard_run_glyph_html( $base_class . 'warn', '&#x2298;', ucfirst( $conclusion ) );
	}
	return snt_dashboard_run_glyph_html( $base_class . 'err', '&#x2717;', $conclusion ? $conclusion : 'unknown' );
}

/**
 * Build a deploy-status glyph span. v6.47.0: the glyph is aria-hidden and the
 * status word is carried in a visually-hidden .screen-reader-text label (plus
 * the title for sighted hover), so AT announces the status instead of a bare,
 * inconsistently-read glyph. $glyph is a hardcoded entity (never user data).
 *
 * @param string $class HTML class.
 * @param string $glyph HTML entity for the status glyph.
 * @param string $label Status word.
 * @return string
 */
function snt_dashboard_run_glyph_html( $class, $glyph, $label ) {
	return '<span class="' . esc_attr( $class ) . '" title="' . esc_attr( $label ) . '">'
		. '<span aria-hidden="true">' . $glyph . '</span>'
		. '<span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
}

function snt_dashboard_short_repo( $repo ) {
	if ( str_ends_with( $repo, '-tools' ) ) {
		return 'plugin';
	}
	return $repo ? 'theme' : '';
}

function snt_dashboard_duration_label( $seconds ) {
	if ( ! is_int( $seconds ) ) {
		return '—';
	}
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	return sprintf( '%dm %ds', intdiv( $seconds, 60 ), $seconds % 60 );
}

function snt_dashboard_relative_time( $iso ) {
	if ( ! $iso ) {
		return '';
	}
	$t = strtotime( $iso );
	return $t ? human_time_diff( $t, time() ) . ' ago' : '';
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
		$items[] = sprintf(
			'<span class="%s">%s: <span class="sn-mono">%s/%s</span></span>',
			esc_attr( $state_cls ),
			esc_html( $label ),
			esc_html( number_format_i18n( $snap['remaining'] ) ),
			esc_html( number_format_i18n( $snap['limit'] ) )
		);
	}

	// If any host is critical, surface a notice ABOVE everything (rare event).
	if ( ! empty( $crit ) ) {
		echo '<div class="notice notice-warning inline sn-notice-spacing"><p>';
		printf(
			/* translators: %s: comma-separated host labels */
			esc_html__( 'Rate limit critical: %s. The site may temporarily lose access to these services.', 'signal-noise-tools' ),
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
	echo implode( ' ' . $sep . ' ', $items );
	// Separator before the Refresh link only when at least one host item
	// rendered — avoids a leading "· Refresh" when no host has a snapshot
	// (unreachable in practice since GitHub is polled by the update-checker,
	// but keeps the markup clean if it ever happens). (v4.5.5)
	if ( ! empty( $items ) ) {
		echo ' ' . $sep . ' ';
	}
	echo '<a class="button-link" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh now', 'signal-noise-tools' ) . '</a>';
	echo '</p>';
}

/**
 * RSS activity summary — single line, content-driven (not arithmetic).
 *
 * Mirrors the External APIs summary pattern: most-recent timestamp +
 * three rolling windows (24h/7d/30d) showing total requests + unique
 * subscribers. Click-through to the RSS tab for deeper data.
 *
 * Re-added in v2.0.1 (RSS activity was on the dashboard in v1.13.0 then
 * dropped in v1.14.0's redesign for being "arithmetic, not content-
 * driven." This treatment fixes that critique — it's the same scannable
 * single-line shape as External APIs, with a clear next-action link).
 */
function snt_dashboard_render_rss_summary() {
	$stats = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$sep   = '<span class="sn-api-summary__sep" aria-hidden="true">&middot;</span>';

	$last_request = '';
	if ( ! empty( $stats['most_recent'] ) ) {
		$t = strtotime( $stats['most_recent'] );
		if ( $t ) {
			$last_request = human_time_diff( $t, time() ) . ' ago';
		}
	}

	$windows = $stats['windows'] ?? array();
	$w24h    = $windows[1]  ?? array( 'total' => 0, 'uniques' => 0 );
	$w7d     = $windows[7]  ?? array( 'total' => 0, 'uniques' => 0 );
	$w30d    = $windows[30] ?? array( 'total' => 0, 'uniques' => 0 );

	// v6.30.1: the standalone `page=sn-rss` slug isn't registered (every SN
	// admin surface lives under page=sn-theme-options&tab=…), so the old link
	// hit WP's "not allowed to access this page" guard. Point straight at the
	// canonical Content → RSS sub-section (RSS moved here from Monitoring in
	// v6.18.0), mirroring tab=connections&sub=cron / tab=monitoring&sub=analytics.
	$rss_url = admin_url( 'admin.php?page=sn-theme-options&tab=content&sub=rss' );

	echo '<h2 class="sn-section-h">RSS feed activity</h2>';
	echo '<p class="sn-api-summary">';
	echo '<span class="sn-api-summary__item">Last request: <em>' . esc_html( $last_request ?: 'none yet' ) . '</em></span>';
	echo ' ' . $sep . ' ';
	echo '<span class="sn-api-summary__item">24h: <span class="sn-mono">' . esc_html( number_format_i18n( $w24h['total'] ) ) . '</span> req &middot; <span class="sn-mono">' . esc_html( number_format_i18n( $w24h['uniques'] ) ) . '</span> uniq</span>';
	echo ' ' . $sep . ' ';
	echo '<span class="sn-api-summary__item">7d: <span class="sn-mono">' . esc_html( number_format_i18n( $w7d['total'] ) ) . '</span> req &middot; <span class="sn-mono">' . esc_html( number_format_i18n( $w7d['uniques'] ) ) . '</span> uniq</span>';
	echo ' ' . $sep . ' ';
	echo '<span class="sn-api-summary__item">30d: <span class="sn-mono">' . esc_html( number_format_i18n( $w30d['total'] ) ) . '</span> req &middot; <span class="sn-mono">' . esc_html( number_format_i18n( $w30d['uniques'] ) ) . '</span> uniq</span>';
	echo ' ' . $sep . ' ';
	echo '<a class="button-link" href="' . esc_url( $rss_url ) . '">' . esc_html__( 'Open RSS tab', 'signal-noise-tools' ) . '</a>';
	echo '</p>';
}

/* ════════════════════════════════════════════════════════════════════════
 * FORCE-CHECK HANDLER
 *
 * Stays as a separate admin-post.php handler (rather than folding into
 * sn_handle_admin_post()) because the refresh link in the API summary is
 * a GET (nonce-protected URL), not a POST from a tab form. admin-post.php
 * accepts both POST and GET via $_REQUEST routing.
 * ════════════════════════════════════════════════════════════════════════ */

add_action( 'admin_post_sn_force_update_check', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-noise-tools' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_force_update_check', 'sn_force_update_check_nonce' );

	// v4.1.1 (D-01): delegate to the shared impl in desktop-mode-integration.php.
	// Pre-v4.1.1 this 4-line block was duplicated byte-for-byte across this
	// handler and snt_cmd_impl_force_check(). Single source of truth now.
	if ( function_exists( 'snt_cmd_impl_force_check' ) ) {
		snt_cmd_impl_force_check();
	}

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );

/* ════════════════════════════════════════════════════════════════════════
 * SITE HEALTH > INFO panel (v4.9.0, Task 3)
 *
 * Surfaces SN operational state in Tools → Site Health → Info under a
 * "Signal & Noise Tools" panel. Every field is read from an EXISTING
 * getter — no new computation. Integration-adjacent fields (API rate
 * state, AI availability, cron internals) are marked private => true so
 * they're excluded from the "Copy site info to clipboard" export.
 * ════════════════════════════════════════════════════════════════════════ */

add_filter( 'debug_information', 'snt_dashboard_debug_information' );

/**
 * @since 4.9.0
 * @param array $info Core's accumulated debug-info panels.
 * @return array
 */
function snt_dashboard_debug_information( $info ) {
	$fields = array();

	// Plugin + theme versions (public).
	$fields['plugin_version'] = array(
		'label' => __( 'Plugin version', 'signal-noise-tools' ),
		'value' => defined( 'SNT_VERSION' ) ? SNT_VERSION : '',
	);
	$fields['theme_version'] = array(
		'label' => __( 'Signal & Noise theme version', 'signal-noise-tools' ),
		'value' => (string) wp_get_theme( 'signal-and-noise' )->get( 'Version' ),
	);

	// Plugin update state (public).
	if ( function_exists( 'snt_deploy_status_for' ) ) {
		$plugin = snt_deploy_status_for( 'plugin' );
		$fields['plugin_update_state'] = array(
			'label' => __( 'Plugin update state', 'signal-noise-tools' ),
			'value' => isset( $plugin['state'] ) ? (string) $plugin['state'] : 'unknown',
		);
	}

	// DB override count (public).
	$fields['db_overrides'] = array(
		'label' => __( 'Database template/navigation overrides', 'signal-noise-tools' ),
		'value' => snt_dashboard_override_count(),
	);

	// Cron pipeline summary (private — internal hook names).
	$cron_lines = array();
	$hooks      = function_exists( 'snt_cron_sn_owned_hooks' ) ? snt_cron_sn_owned_hooks() : array();
	foreach ( $hooks as $hook ) {
		$next       = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
		$last_fired = function_exists( 'snt_cron_last_fired_for' ) ? snt_cron_last_fired_for( $hook ) : null;
		$sched      = ( false !== $next && is_numeric( $next ) ) ? __( 'scheduled', 'signal-noise-tools' ) : __( 'NOT scheduled', 'signal-noise-tools' );
		$fired      = ( null !== $last_fired )
			? sprintf( /* translators: %s: human time diff. */ __( 'fired %s ago', 'signal-noise-tools' ), human_time_diff( (int) $last_fired, time() ) )
			: __( 'never', 'signal-noise-tools' );
		$cron_lines[] = $hook . ': ' . $sched . ', ' . $fired;
	}
	$fields['cron_pipeline'] = array(
		'label'   => __( 'Cron pipeline', 'signal-noise-tools' ),
		'value'   => $cron_lines ? implode( ' | ', $cron_lines ) : __( 'no SN-owned hooks', 'signal-noise-tools' ),
		'private' => true,
	);

	// Cron-history table present? (private).
	$fields['cron_history_table'] = array(
		'label'   => __( 'Cron history table installed', 'signal-noise-tools' ),
		'value'   => ( defined( 'SNT_CRON_HISTORY_DB_VERSION_OPT' ) && get_option( SNT_CRON_HISTORY_DB_VERSION_OPT ) )
			? __( 'yes', 'signal-noise-tools' )
			: __( 'no', 'signal-noise-tools' ),
		'private' => true,
	);

	// External-API rate state (private — integration-adjacent).
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		$rate_lines = array();
		foreach ( snt_rate_limit_all_statuses() as $host => $row ) {
			$snapshot  = isset( $row['snapshot'] ) ? $row['snapshot'] : array();
			$state     = function_exists( 'snt_rate_limit_state' ) ? snt_rate_limit_state( $snapshot ) : 'unknown';
			$label     = isset( $row['label'] ) ? (string) $row['label'] : (string) $host;
			$rate_lines[] = $label . ': ' . $state;
		}
		$fields['api_rate_state'] = array(
			'label'   => __( 'External API rate state', 'signal-noise-tools' ),
			'value'   => $rate_lines ? implode( ', ', $rate_lines ) : __( 'none', 'signal-noise-tools' ),
			'private' => true,
		);
	}

	// AI availability (private).
	if ( function_exists( 'snt_ai_is_available' ) ) {
		$fields['ai_available'] = array(
			'label'   => __( 'AI provider available', 'signal-noise-tools' ),
			'value'   => snt_ai_is_available() ? __( 'yes', 'signal-noise-tools' ) : __( 'no', 'signal-noise-tools' ),
			'private' => true,
		);
	}

	// Webhooks count (public — counts only, no URLs/secrets).
	if ( function_exists( 'sn_webhooks_all' ) ) {
		$all     = sn_webhooks_all();
		$total   = is_array( $all ) ? count( $all ) : 0;
		$enabled = 0;
		if ( is_array( $all ) ) {
			foreach ( $all as $wh ) {
				if ( ! empty( $wh['enabled'] ) ) { $enabled++; }
			}
		}
		$fields['webhooks'] = array(
			'label' => __( 'Webhooks (total / enabled)', 'signal-noise-tools' ),
			'value' => sprintf( '%d / %d', $total, $enabled ),
		);
	}

	// Cache state — health-scan presence/age (private).
	$cache_bits = array();
	// v6.47.2: read through the accessor (a durable option since v6.47.2), not a
	// direct get_transient — the scan no longer lives in a transient.
	$health = function_exists( 'sn_health_last_scan' ) ? sn_health_last_scan() : null;
	if ( is_array( $health ) && ! empty( $health['scanned_at'] ) ) {
		$cache_bits[] = 'health-scan: ' . human_time_diff( (int) $health['scanned_at'], time() ) . ' ago';
	} else {
		$cache_bits[] = 'health-scan: none';
	}
	$fields['cache_state'] = array(
		'label'   => __( 'Cache state', 'signal-noise-tools' ),
		'value'   => implode( '; ', $cache_bits ),
		'private' => true,
	);

	$info['signal-noise-tools'] = array(
		'label'       => __( 'Signal & Noise Tools', 'signal-noise-tools' ),
		'description' => __( 'Operational state for the Signal & Noise Tools plugin (versions, cron pipeline, integrations, caches).', 'signal-noise-tools' ),
		'fields'      => $fields,
	);

	return $info;
}
