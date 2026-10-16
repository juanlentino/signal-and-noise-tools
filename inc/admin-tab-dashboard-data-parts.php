<?php
/**
 * Signal & Noise Tools: admin-tab-dashboard.php, the deploy status, the
 * override counts, the tab hook and the tab data.
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
		// Installed newer than the cached newest tag: the cache predates the
		// release. Re-fetch once per five minutes (the same rule as the workers).
		if ( $latest && $current && version_compare( $current, ltrim( (string) $latest, 'v' ), '>' ) && false === get_transient( 'snt_gh_plugin_refetch' ) ) {
			set_transient( 'snt_gh_plugin_refetch', 1, 5 * MINUTE_IN_SECONDS );
			$latest = sn_gh_latest_plugin_tag( true );
		}
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

function snt_dashboard_tab_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return null;
	}

	$theme     = snt_deploy_status_for( 'theme' );
	$plugin    = snt_deploy_status_for( 'plugin' );
	// v4.1.4: merge wp-admin Updates installs with GHA workflow runs. Since
	// v1.10.1 (plugin) / v8.5.1 (theme) tag pushes no longer auto-deploy, so
	// the GHA-only feed froze at the last auto-on-tag-push deploy. The
	// deploy-history module records wp-admin installs via the
	// upgrader_process_complete hook; the merged view shows both sources.
	// v1208: the Ops Wall reads this feed 6-deep ( array_slice( $runs, 0, 6 ) )
	// and the Deploys glance card counts recent runs from it — a limit of 5
	// starved both readings before either ever saw a 6th row.
	$runs      = function_exists( 'snt_deploy_history_merged' )
		? snt_deploy_history_merged( array_values( SNT_DEPLOY_REPOS ), 6 )
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

	// v11.28.0: state earns space. Attention collapses to a line when nothing is
	// wrong; fleet collapses unless a component was never probed. The cards
	// themselves are unchanged — sn_admin_glance_grid() still renders an
	// expanded zone, so the reading order inside a zone is the v10.48.0 one.
	$attention_labels = array( 'Health', 'Cron', 'Provenance' );
	$attention_cards  = array();
	foreach ( $cards as $card ) {
		if ( in_array( (string) ( $card['label'] ?? '' ), $attention_labels, true ) ) {
			$attention_cards[] = $card;
		}
	}

	$workers = function_exists( 'snt_deploy_workers_status' )
		? snt_deploy_workers_status( array( 'probe_budget' => 1 ) )
		: array();

	// v11.32.0: the v11.28.0 "Recent deploys folded into the fleet zone" markup
	// used to be built here. v11.30.0 replaced the zone screen with the console
	// and gave deploys their own ops panel, and this block was left behind —
	// assigned to $fleet_zone['body_html'] and then overwritten wholesale by
	// the second sn_dash_zone_fleet() call a few lines down, so it rendered a
	// list into a buffer and threw it away on every dashboard load. Its only
	// consumer, sn_dash_render_zone(), has since been removed.

	// ── 1. THE CONSOLE ── v11.29.1, direction B with C's band.
	//
	// Replaces the v11.28.0 collapsing zones. Those implemented "state earns
	// space" faithfully and produced a page that was 53% empty on a healthy
	// site, because that rule describes what ALARMS do and never says what the
	// page IS when nothing is wrong — which is nearly always. The console is
	// dense at rest: every check and every component is readable without
	// expanding anything, and the band states the situation above it.
	$needy = 0;
	foreach ( $cards as $card ) {
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		if ( sn_admin_card_wants_attention( $card ) && ( 'err' === $kind || 'warn' === $kind ) ) {
			++$needy;
		}
	}

	$analytics_on = function_exists( 'sn_analytics_config' ) && sn_analytics_config();

	$fleet_zone = sn_dash_zone_fleet(
		snt_dashboard_fleet_components( $theme, $plugin, $workers ),
		$last_deploy_ago
	);

	$measurement = function_exists( 'snt_dashboard_measurement_data' ) ? snt_dashboard_measurement_data() : array();

	$series = ( function_exists( 'sn_analytics_daily_series' ) && function_exists( 'sn_analytics_config' ) && sn_analytics_config() )
		? sn_analytics_daily_series( gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() ), 'human', 'day' )
		: array();

	// ── HOW OLD IS THIS SCREEN? ──────────────────────────────────────────────
	// The headline is present tense; its inputs are not. The readings come from
	// the SHARED collector the index.php widget also calls — deriving them here
	// instead would let the widget stay green while this screen went amber.
	$freshness = function_exists( 'sn_dash_freshness' )
		? sn_dash_freshness( sn_dash_freshness_readings() )
		: array();

	$subline = implode(
		' · ',
		array_filter( array(
			/* translators: %d fleet components */
			sprintf( _n( '%d component', '%d components', count( $fleet_zone['cards'] ), 'signal-and-noise-tools' ), count( $fleet_zone['cards'] ) ),
			'' !== (string) $last_deploy_ago ? sprintf( /* translators: %s time since last deploy */ __( 'last deploy %s', 'signal-and-noise-tools' ), $last_deploy_ago ) : '',
			function_exists( 'sn_dash_freshness_label' ) ? sn_dash_freshness_label( $freshness ) : '',
		) )
	);

	// ── THE OPS WALL ── "everything without bloating" (owner, 2026-08-19).
	// Every source is fetched behind its own guard, and an ABSENT one passes
	// NULL rather than an empty array: the panel still renders, saying it is
	// not measured. A wall that quietly shrinks when a source dies looks
	// complete on the one day it is not.
	// 2026.10.4 audit: the same seven days as the Audience tiles and the
	// "Audience detail, 7 days" eyebrow; a 30-day top page could outnumber the
	// site's 7-day views painted over it.
	list( $from, $to ) = snt_dashboard_week();
	$ops_data = array( 'deploys' => is_array( $runs ) ? array_slice( $runs, 0, 6 ) : null );

	if ( $analytics_on && function_exists( 'sn_analytics_top_paths' ) ) {
		$ops_data['pages'] = (array) sn_analytics_top_paths( $from, $to, 'human', 6 );
	}
	if ( $analytics_on && function_exists( 'sn_analytics_top_sources' ) ) {
		$ops_data['sources'] = (array) sn_analytics_top_sources( $from, $to, 'human', 6 );
	}
	// snt_gsc_data() NULL means never synced — which is not the same fact as a
	// synced window with no queries, so only the second becomes an empty array.
	if ( function_exists( 'snt_gsc_data' ) && function_exists( 'snt_gsc_top_queries' ) && null !== snt_gsc_data() ) {
		$ops_data['queries'] = (array) snt_gsc_top_queries( 6 );
	}
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		$ops_data['api'] = (array) snt_rate_limit_all_statuses();
	}

	// ── THE SIGNALS ── every one carries a comparison. A bare number cannot be
	// judged (Few, context over isolation), and v11.29.x shipped four of five
	// with nothing to judge them against.
	$signals = sn_dash_signals_from_measurement( $measurement );

	// A stale reading is an exception, not a footnote: it flows in as a CARD so
	// the one shared sn_dash_verdict() raises it on both surfaces at once.
	$screen_checks = function_exists( 'sn_dash_freshness_cards' )
		? array_merge( $attention_cards, sn_dash_freshness_cards( $freshness ) )
		: $attention_cards;

	return array(
		'verdict'           => sn_dash_verdict( array_merge( array_values( $screen_checks ), array_values( $fleet_zone['cards'] ) ) ),
		'checks'            => $screen_checks,
		'components'        => $fleet_zone['cards'],
		'signals'           => $signals,
		'subline'           => $subline,
		'series'            => is_array( $series ) ? $series : array(),
		'panels'            => sn_dash_ops_panels( $ops_data ),
		// v14.0.3: core's nonce-free force check — a PWA page held for days cannot carry a nonce.
		'check_updates_url' => admin_url( 'update-core.php?force-check=1' ),
		'attention'         => snt_dashboard_attention_items( $runs, count( $overrides ) ),
		'overrides'         => snt_dashboard_override_names( $overrides ),
		'runs'              => $runs,
		'override_count'    => count( $overrides ),
	);
}

