<?php
/**
 * Signal & Noise — Theme options admin page.
 *
 * Registers the Appearance → Signal & Noise submenu and renders a tabbed
 * interface that covers theme management without overflowing into a
 * single-page-of-everything:
 *
 *   - Dashboard      — status overview + the four maintenance actions
 *                      (full reset, clear overrides, purge caches,
 *                      check for updates).
 *   - Cloudflare     — token + zone configuration, status, manual
 *                      zone purge, last-purge timestamp.
 *   - Reading Time   — legacy reading-time-string cleanup tool
 *                      (preview + apply).
 *   - Links          — external service links.
 *
 * Modules contribute their per-tab content via dedicated action hooks
 * (`sn_admin_cloudflare_tab`, `sn_admin_reading_time_tab`) so each
 * subsystem keeps its UI code colocated with its logic.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: Signal & Noise Theme Options under Appearance menu.
 */
add_action( 'admin_menu', function() {
	add_theme_page(
		'Signal & Noise',
		'Signal & Noise',
		'manage_options',
		'sn-theme-options',
		'sn_theme_options_page'
	);
} );

function sn_theme_options_page() {
	// Defense-in-depth capability check. WordPress's add_theme_page()
	// already gates access to the admin URL itself, but re-checking here
	// matches WPCS convention for any handler that mutates state and
	// keeps this function safe if it's ever invoked from another context
	// (e.g. a future shortcode, AJAX dispatcher, or REST callback).
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'You do not have sufficient permissions to access this page.' ) );
	}

	$theme         = wp_get_theme( 'signal-and-noise' );
	$local_version = $theme->get( 'Version' );
	$notices       = array();
	$valid_tabs    = array( 'dashboard', 'cloudflare', 'plausible', 'rss', 'reading-time', 'links' );
	$active_tab    = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'dashboard';
	}

	// Handle form actions.
	if ( isset( $_POST['sn_action'] ) && check_admin_referer( 'sn_theme_options_nonce' ) ) {
		$action = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );

		if ( 'clear_overrides' === $action ) {
			// Dispatched via sn_clear_template_overrides_result filter
			// contract — theme module template-maintenance.php owns
			// the implementation; returns 0 if not loaded.
			$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
			$notices[] = array( 'success', $count . ' database override(s) cleared. Site is reading from theme files.' );
		}

		if ( 'purge_caches' === $action ) {
			// Single source of truth for "purge everything" — see
			// sn_purge_all_caches() in the theme's template-maintenance.php.
			// Dispatched via sn_purge_all_caches_result filter contract.
			// template_overrides => false matches the button copy ("purge
			// caches", not "also delete admin Site Editor edits").
			$cleared = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
			$notices[] = array( 'success', 'All caches purged.' );
		}

		if ( 'full_reset' === $action ) {
			// Full reset = purge everything including DB template overrides.
			// Purge dispatched via sn_purge_all_caches_result filter
			// (default args include template_overrides).
			$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array() );
			$notices[] = array( 'success', 'Full reset: ' . $count . ' override(s) cleared + all caches purged.' );
		}

	}

	$local_sha = (string) get_option( 'sn_github_local_sha', '' );

	$overrides = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part', 'wp_navigation' ), 'posts_per_page' => -1, 'post_status' => 'any' ) );
	$base_url  = admin_url( 'themes.php?page=sn-theme-options' );

	// ── PAGE SHELL ──
	echo '<div class="wrap">';
	echo '<h1 style="font-size:1.6em;margin-bottom:0.2em;">Signal &amp; Noise</h1>';
	echo '<p style="color:#666;margin-top:0;margin-bottom:1em;">Theme management and maintenance.</p>';

	// Notices. Severity is escaped as an attribute; bodies are run
	// through wp_kses_post because some entries deliberately ship
	// inline markup (<a>, <code>) — esc_html would mangle those.
	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
	}

	// ── TABS ──
	$tab_labels = array(
		'dashboard'    => 'Dashboard',
		'cloudflare'   => 'Cloudflare',
		'plausible'    => 'Plausible',
		'rss'          => 'RSS',
		'reading-time' => 'Reading Time',
		'links'        => 'Links',
	);
	echo '<nav class="nav-tab-wrapper" style="margin-bottom:1.5em;">';
	foreach ( $tab_labels as $slug => $label ) {
		$is_active = ( $slug === $active_tab );
		echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	// ════════════════════════════════════════
	// TAB: DASHBOARD
	// ════════════════════════════════════════
	if ( 'dashboard' === $active_tab ) {

		// ── STATUS ──
		echo '<h2 style="font-size:1.1em;margin-bottom:0.8em;">Status</h2>';
		echo '<table class="form-table" style="max-width:500px;">';
		// Print escaped fragments inline rather than building a pre-escaped
		// string and echoing it. Same visual output; eliminates the future-
		// bug class where a maintainer adds a new dynamic field to the
		// concatenation and forgets to esc_html it. (Audit finding H2.)
		echo '<tr><th style="width:180px;padding:8px 10px 8px 0;">Installed version</th><td style="padding:8px 0;"><code>' . esc_html( $local_version ) . '</code>';
		if ( $local_sha ) {
			echo ' <span style="color:#666;">at <code>' . esc_html( $local_sha ) . '</code></span>';
		}
		echo '</td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">DB overrides</th><td style="padding:8px 0;">' . count( $overrides );
		if ( count( $overrides ) > 0 ) {
			echo ' <span style="color:#dba617;">&#9888; Reading from database, not theme files</span>';
		} else {
			echo ' <span style="color:#00a32a;">&#10003; Clean</span>';
		}
		echo '</td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Self-updater</th><td style="padding:8px 0;">';
		echo defined( 'SN_GITHUB_TOKEN' ) ? '<span style="color:#00a32a;">&#10003; Connected</span>' : '<span style="color:#d63638;">&#10005; SN_GITHUB_TOKEN not set</span>';
		echo '</td></tr>';
		echo '</table>';

		if ( $overrides ) {
			echo '<details style="margin-top:0.5em;"><summary style="cursor:pointer;color:#2271b1;font-size:0.85em;">View override details</summary><ul style="margin:0.5em 0 0 1.5em;">';
			foreach ( $overrides as $tpl ) {
				echo '<li><code>' . esc_html( $tpl->post_type ) . '/' . esc_html( $tpl->post_name ) . '</code></li>';
			}
			echo '</ul></details>';
		}

		echo '<hr style="margin:1.5em 0;">';

		// ── ACTIONS ──
		echo '<h2 style="font-size:1.1em;margin-bottom:0.8em;">Actions</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );

		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;">';

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:260px;">';
		echo '<strong style="display:block;margin-bottom:4px;">Full Reset</strong>';
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Clears all overrides and purges every cache. Use after theme updates.</p>';
		echo '<button type="submit" name="sn_action" value="full_reset" class="button button-primary">Run Full Reset</button>';
		echo '</div>';

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:260px;">';
		echo '<strong style="display:block;margin-bottom:4px;">Clear Overrides</strong>';
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">Removes template, template part, and navigation DB entries.</p>';
		echo '<button type="submit" name="sn_action" value="clear_overrides" class="button">Clear Overrides</button>';
		echo '</div>';

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:260px;">';
		echo '<strong style="display:block;margin-bottom:4px;">Purge Caches</strong>';
		echo '<p style="color:#666;font-size:0.85em;margin:0 0 12px;">WP object cache, transients, Breeze page/minification, Varnish.</p>';
		echo '<button type="submit" name="sn_action" value="purge_caches" class="button">Purge All Caches</button>';
		echo '</div>';

		echo '</div>';
		echo '</form>';

		/**
		 * Legacy hook for backward compatibility. As of v7.0.x, modules
		 * should target their dedicated tab hooks instead:
		 *   - sn_admin_cloudflare_tab    (Cloudflare tab)
		 *   - sn_admin_reading_time_tab  (Reading Time tab)
		 * This action is kept firing on the Dashboard tab so any
		 * third-party additions land somewhere visible during the
		 * transition.
		 */
		do_action( 'sn_admin_dashboard_extras' );

	// ════════════════════════════════════════
	// TAB: CLOUDFLARE
	// ════════════════════════════════════════
	} elseif ( 'cloudflare' === $active_tab ) {

		/** Module-owned UI: see inc/cloudflare-purge.php. */
		do_action( 'sn_admin_cloudflare_tab' );

	// ════════════════════════════════════════
	// TAB: PLAUSIBLE
	// ════════════════════════════════════════
	} elseif ( 'plausible' === $active_tab ) {

		/** Module-owned UI: see inc/plausible-admin.php. */
		do_action( 'sn_admin_plausible_tab' );

	// ════════════════════════════════════════
	// TAB: RSS
	// ════════════════════════════════════════
	} elseif ( 'rss' === $active_tab ) {

		/**
		 * Module-owned UI: see mu-plugins/rss-plausible-tracker.php.
		 *
		 * The tracker is a Must-Use plugin (not part of the theme) so it
		 * survives theme switches and continues collecting subscriber
		 * metrics regardless. When the MU plugin is deployed it hooks
		 * this action; when it isn't, the tab renders an install hint
		 * via the fallback below.
		 */
		if ( has_action( 'sn_admin_rss_tab' ) ) {
			do_action( 'sn_admin_rss_tab' );
		} else {
			echo '<div class="notice notice-warning inline" style="margin:0;padding:12px 16px;"><p><strong>RSS subscriber tracker not installed.</strong></p>';
			echo '<p>Copy <code>mu-plugins/rss-plausible-tracker.php</code> from the theme repo to <code>wp-content/mu-plugins/</code> on this host. MU plugins activate automatically — no further action needed.</p></div>';
		}

	// ════════════════════════════════════════
	// TAB: READING TIME
	// ════════════════════════════════════════
	} elseif ( 'reading-time' === $active_tab ) {

		/** Module-owned UI: see inc/reading-time.php. */
		do_action( 'sn_admin_reading_time_tab' );

	// ════════════════════════════════════════
	// TAB: LINKS
	// ════════════════════════════════════════
	} elseif ( 'links' === $active_tab ) {

		echo '<table class="form-table" style="max-width:500px;">';
		echo '<tr><th style="width:180px;padding:8px 10px 8px 0;">GitHub Repository</th><td style="padding:8px 0;"><a href="https://github.com/juanlentino/signal-and-noise" target="_blank" rel="noopener">juanlentino/signal-and-noise</a></td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Release History</th><td style="padding:8px 0;"><a href="https://github.com/juanlentino/signal-and-noise/releases" target="_blank" rel="noopener">All releases</a></td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Cloudflare</th><td style="padding:8px 0;"><a href="https://dash.cloudflare.com" target="_blank" rel="noopener">Cloudflare Dashboard</a></td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Cloudways</th><td style="padding:8px 0;"><a href="https://platform.cloudways.com" target="_blank" rel="noopener">Cloudways Platform</a></td></tr>';
		echo '</table>';

	}

	echo '</div>'; // wrap
}
