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
		// v9.54.0: the theme owns its own fetch, so it must volunteer its own
		// reason. Absent listener → '' → the card falls back to the generic
		// "unknown", exactly as before. Same contract shape as the tag filter.
		$reason  = (string) apply_filters( 'sn_gh_latest_theme_tag_error_result', '' );
	} else {
		$current = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
		// sn_gh_latest_plugin_tag is plugin-owned (inc/wp-update-integration.php) —
		// calling it directly is fine; same repo as the caller.
		$latest  = function_exists( 'sn_gh_latest_plugin_tag' ) ? sn_gh_latest_plugin_tag() : null;
		$reason  = function_exists( 'sn_gh_latest_plugin_tag_error' ) ? sn_gh_latest_plugin_tag_error() : '';
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
		// v9.54.0: WHY it's unknown. Empty unless state === 'unknown' AND the
		// fetch layer recorded a cause. A red dot that can't say why is what
		// turned a 30-second wp-config fix into a source-reading exercise.
		'reason'  => 'unknown' === $state ? $reason : '',
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
	$pins  = function_exists( 'sn_dash_pins' ) ? sn_dash_pins( get_current_user_id() ) : array();

	// v11.28.0: state earns space. Attention collapses to a line when nothing is
	// wrong; fleet collapses unless a component was never probed. The cards
	// themselves are unchanged — sn_admin_glance_grid() still renders an
	// expanded zone, so the reading order inside a zone is the v10.48.0 one.
	$attention_labels = array( 'Health', 'Cron', 'Caches', 'Provenance' );
	$attention_cards  = array();
	foreach ( $cards as $card ) {
		if ( in_array( (string) ( $card['label'] ?? '' ), $attention_labels, true ) ) {
			$attention_cards[] = $card;
		}
	}

	$workers = function_exists( 'snt_deploy_workers_status' )
		? snt_deploy_workers_status( array( 'probe_budget' => 1 ) )
		: array();

	// v11.28.0: Recent deploys is FOLDED into the fleet zone, not cut. It answers
	// the same question the zone does — did it ship? — so it belongs inside the
	// zone body rather than as a standalone section competing for the same
	// attention.
	ob_start();
	if ( empty( $runs ) ) {
		echo '<p class="description"><em>No recent runs (or GitHub API unreachable).</em></p>';
	} else {
		echo '<ul class="sn-deploy-list">';
		foreach ( $runs as $run ) {
			snt_dashboard_render_deploy_row( $run );
		}
		echo '</ul>';
	}
	$deploys_html = (string) ob_get_clean();

	$fleet_zone = sn_dash_zone_fleet( snt_dashboard_fleet_components( $theme, $plugin, $workers ), $last_deploy_ago );
	$fleet_zone['body_html'] = $deploys_html;

	echo '<section class="sn-dash-zones" aria-label="Status">';
	sn_dash_render_zone( sn_dash_zone_attention( $attention_cards ), $pins );
	sn_dash_render_zone( $fleet_zone, $pins );
	echo '</section>';

	// ── MEASUREMENT ── never collapses: it has no green/red state, so there is
	// nothing to fold. A figure whose accessor is absent renders unknown.
	if ( function_exists( 'sn_dash_render_measurement_strip' ) ) {
		sn_dash_render_measurement_strip(
			sn_dash_measurement_figures( snt_dashboard_measurement_data() )
		);
	}

	// ── 2. ATTENTION STRIP ── one warning row, only when something is off.
	snt_dashboard_render_attention_strip( $runs, count( $overrides ) );

	// ── 3. EXTERNAL APIs, ONLY WHEN LOW ── v11.28.0. A rate limit is interesting
	// at 4% remaining and noise at 99%, so it earns space only when a host is
	// actually warn or crit. RSS activity is gone from here entirely: the RSS
	// tab already renders the full view, and this was the detail view pasted
	// onto the summary.
	if ( function_exists( 'snt_rate_limit_all_statuses' ) && snt_dashboard_api_summary_is_notable() ) {
		snt_dashboard_render_api_summary();
	}

	// ── 4. LOWER ROW ── v11.28.0: Recent deploys moved into the fleet zone
	// above, so this row is Maintenance alone. The .sn-dash-cols wrapper stays
	// for the existing responsive behaviour and the Diagnostics fold below it.
	echo '<div class="sn-dash-cols">';

	// Maintenance 3-card action grid (unchanged actions).
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
	// `signal-noise/get-deploy-status` ability's force_refresh path (Cmd+K;
	// force-check-updates removed v8.0.0), reachable without depending on
	// the ⌘K palette working.
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
		echo '<summary>' . esc_html( sprintf( '%d database override%s: click to expand', count( $overrides ), count( $overrides ) === 1 ? '' : 's' ) ) . '</summary>';
		echo '<ul>';
		foreach ( $overrides as $tpl ) {
			echo '<li><code>' . esc_html( $tpl->post_type ) . '/' . esc_html( $tpl->post_name ) . '</code></li>';
		}
		echo '</ul>';
		echo '</details>';
	}
	// v9.62.2: the Copilot tool-usage card moved to its own AI → Copilot Usage
	// sub-tab (a diagnostic, off the main Dashboard). Rendered there via the
	// registry leaf 'copilot-usage' (snt_ai_tool_invocations_render).
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
		$from   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$to     = gmdate( 'Y-m-d', time() );
		$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$views = (int) ( $deltas['views']['current'] ?? 0 );
			$cards[] = array(
				'label'     => 'Views 7d',
			'href'      => admin_url( 'index.php?page=sn-analytics' ),
				'value'     => number_format_i18n( $views ),
				'meta_html' => snt_dashboard_delta_badge_html( $deltas['views'] ),
			);
		}
	}

	// ── Cache freshness (client-checked dot; JS fills the result). ──
	if ( function_exists( 'snt_freshness_card' ) ) {
		$cards[] = snt_freshness_card();
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
	$order = array( 'Theme', 'Plugin', 'Analytics', 'Provenance edge', 'Login guard', 'Remote MCP', 'Rights signals', 'Deploys', 'Provenance', 'Health', 'Cron', 'Caches', 'Views 7d', 'AI spend 30d' );
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
		'value' => ( $pkg['current'] ?? '' ) ?: '—',
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
		'current' => '' !== $live ? $live : '—',
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
	echo '<p class="sn-attention-strip__lead"><strong>' . esc_html__( 'Needs attention:', 'signal-and-noise-tools' ) . '</strong> ';
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
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'sn_force_update_check', 'sn_force_update_check_nonce' );

	// v4.1.1 (D-01): delegate to the shared impl in desktop-mode-commands.php.
	// Pre-v4.1.1 this 4-line block was duplicated byte-for-byte across this
	// handler and snt_cmd_impl_force_check(). Single source of truth now.
	if ( function_exists( 'snt_cmd_impl_force_check' ) ) {
		snt_cmd_impl_force_check();
	}

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );

// v11.28.1: the Site Health > Info panel moved to inc/dash-debug-info.php and
// took its add_filter with it. It never rendered on this tab — it lived here
// only by history.

