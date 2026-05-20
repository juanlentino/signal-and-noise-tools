<?php
/**
 * Signal & Noise Tools — Dashboard tab renderer.
 *
 * Owns the full Dashboard tab content via the `sn_admin_dashboard_extras`
 * action hook (fired in inc/admin-page.php). The legacy Status table +
 * Override details + Actions card grid that previously rendered inline
 * in admin-page.php were absorbed into this file in v1.14.0.
 *
 * Composition (top to bottom):
 *   1. SITE STATE        — 4-card hero grid (theme, plugin, deploys, health)
 *   2. RECENT DEPLOYS    — clean list of last 5 GHA workflow runs (merged)
 *   3. MAINTENANCE       — 3-card action grid (Full Reset / Clear Overrides /
 *                          Purge Caches). Forms POST to sn_handle_admin_post()
 *                          via the existing sn_theme_options_nonce.
 *   4. EXTERNAL APIs     — single-line summary + inline "Refresh now" link
 *   5. DIAGNOSTICS       — collapsible override-detail list (only renders
 *                          when there ARE overrides)
 *
 * Design principles (per memory: feedback_no_brutalist_in_admin_ui.md):
 *   - WP-admin native (.button, .notice, .widefat, .form-table where it fits)
 *   - .sn-* classes for composition patterns that don't have a WP-native
 *     equivalent (.sn-state-grid, .sn-deploy-list, .sn-api-summary)
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
		$latest  = function_exists( 'sn_gh_latest_theme_tag' ) ? sn_gh_latest_theme_tag() : null;
	} else {
		$current = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
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
 * Render the full Dashboard tab content. Hooked at priority 10 (default)
 * because we now OWN the entire Dashboard tab; nothing else listens.
 */
add_action( 'sn_admin_dashboard_extras', 'snt_dashboard_tab_render' );

function snt_dashboard_tab_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$theme     = snt_deploy_status_for( 'theme' );
	$plugin    = snt_deploy_status_for( 'plugin' );
	$runs      = function_exists( 'snt_gh_recent_runs_merged' )
		? snt_gh_recent_runs_merged( array_values( SNT_DEPLOY_REPOS ), 5 )
		: array();
	$overrides = get_posts( array(
		'post_type'      => array( 'wp_template', 'wp_template_part', 'wp_navigation' ),
		'posts_per_page' => -1,
		'post_status'    => 'any',
	) );
	$last_deploy_ago = snt_dashboard_last_deploy_label( $runs );

	// ── 1. SITE STATE ── hero grid (4 cards)
	echo '<section class="sn-state-grid" aria-label="Site state">';
	snt_dashboard_render_state_card(
		'Theme',
		$theme['current'] ?: '—',
		snt_dashboard_state_meta( $theme )
	);
	snt_dashboard_render_state_card(
		'Plugin',
		$plugin['current'] ?: '—',
		snt_dashboard_state_meta( $plugin )
	);
	snt_dashboard_render_state_card(
		'Deploys',
		$last_deploy_ago,
		count( $runs ) > 0
			? sprintf( '%d in last 24h', snt_dashboard_count_recent_runs( $runs, DAY_IN_SECONDS ) )
			: 'no recent runs'
	);
	snt_dashboard_render_state_card(
		'Health',
		count( $overrides ) > 0
			? sprintf( '%d override%s', count( $overrides ), count( $overrides ) === 1 ? '' : 's' )
			: 'clean',
		count( $overrides ) > 0 ? 'reading from database' : 'no alerts'
	);
	echo '</section>';

	// ── 2. RECENT DEPLOYS ── clean list
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

	// ── 3. MAINTENANCE ── 3-card action grid (unchanged from pre-v1.13.0)
	echo '<h2 class="sn-section-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-card-grid">';

	echo '<div class="sn-card">';
	echo '<strong>Full Reset</strong>';
	echo '<p class="sn-helper">Clears all overrides and purges every cache. Use after theme updates.</p>';
	echo '<button type="submit" name="sn_action" value="full_reset" class="button button-primary">Run Full Reset</button>';
	echo '</div>';

	echo '<div class="sn-card">';
	echo '<strong>Clear Overrides</strong>';
	echo '<p class="sn-helper">Removes template, template part, and navigation DB entries.</p>';
	echo '<button type="submit" name="sn_action" value="clear_overrides" class="button">Clear Overrides</button>';
	echo '</div>';

	echo '<div class="sn-card">';
	echo '<strong>Purge Caches</strong>';
	echo '<p class="sn-helper">WP object cache, transients, Breeze page/minification, Varnish.</p>';
	echo '<button type="submit" name="sn_action" value="purge_caches" class="button">Purge All Caches</button>';
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

	echo '</div>';
	echo '</form>';

	// ── 4. EXTERNAL APIs ── single-line summary + inline Refresh link
	if ( function_exists( 'snt_rate_limit_all_statuses' ) ) {
		snt_dashboard_render_api_summary();
	}

	// ── 5. RSS ACTIVITY ── single-line summary + link to RSS tab
	if ( function_exists( 'sn_rss_tracker_window_stats_multi' ) ) {
		snt_dashboard_render_rss_summary();
	}

	// ── 6. DIAGNOSTICS ── only when there's anything to show
	if ( ! empty( $overrides ) ) {
		echo '<h2 class="sn-section-h">Diagnostics</h2>';
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
 * RENDER HELPERS
 * ════════════════════════════════════════════════════════════════════════ */

function snt_dashboard_render_state_card( $label, $value, $meta ) {
	echo '<div class="sn-state-card">';
	echo '<p class="sn-state-card__label">' . esc_html( $label ) . '</p>';
	echo '<p class="sn-state-card__value">' . esc_html( $value ) . '</p>';
	echo '<p class="sn-state-card__meta">' . wp_kses_post( $meta ) . '</p>';
	echo '</div>';
}

/** State card "meta" text — small label below the value. Includes a pill for version states. */
function snt_dashboard_state_meta( $pkg ) {
	switch ( $pkg['state'] ) {
		case 'ok':
			return '<span class="sn-pill sn-pill--ok">up to date</span>';
		case 'available':
			return '<span class="sn-pill sn-pill--warn">v' . esc_html( $pkg['latest'] ) . ' available</span>';
		default:
			return '<span class="sn-pill sn-pill--err">unknown</span>';
	}
}

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
		return '<span class="' . esc_attr( $base_class . 'warn' ) . '" title="' . esc_attr__( 'Running', 'signal-noise-tools' ) . '">&middot;</span>';
	}
	if ( 'success' === $conclusion ) {
		return '<span class="' . esc_attr( $base_class . 'ok' ) . '" title="' . esc_attr__( 'Success', 'signal-noise-tools' ) . '">&#x2713;</span>';
	}
	if ( 'cancelled' === $conclusion || 'skipped' === $conclusion ) {
		return '<span class="' . esc_attr( $base_class . 'warn' ) . '" title="' . esc_attr( ucfirst( $conclusion ) ) . '">&#x2298;</span>';
	}
	return '<span class="' . esc_attr( $base_class . 'err' ) . '" title="' . esc_attr( $conclusion ?: 'unknown' ) . '">&#x2717;</span>';
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
		if ( ! $snap ) {
			$items[] = '<span class="sn-api-summary__item">' . esc_html( $label ) . ': <em>—</em></span>';
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
	echo ' ' . $sep . ' ';
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

	$rss_url = admin_url( 'admin.php?page=sn-rss' );

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

	// v1.15.2: only clear the "is there a new version?" caches. The GHA
	// runs cache (deploy history) is a separate concern — clearing it
	// would force a 60/h GitHub API request without answering the
	// question the user actually asked (force-check is about updates,
	// not deploy timeline). ETag-based conditional requests in
	// snt_gh_recent_runs() handle the runs cache freshness automatically
	// without quota cost.
	delete_site_transient( 'sn_gh_latest_theme' );
	delete_site_transient( 'sn_gh_latest_plugin' );
	delete_site_transient( 'update_themes' );
	delete_site_transient( 'update_plugins' );

	wp_safe_redirect( admin_url( 'update-core.php?force-check=1' ) );
	exit;
} );
