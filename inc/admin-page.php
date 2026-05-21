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
 * Admin page: Signal & Noise — top-level menu (v1.8.1+).
 *
 * Lives at admin.php?page=sn-theme-options (was previously under
 * Appearance via add_theme_page; URL slug unchanged so all existing
 * ?tab=… deep links remain valid). The hook suffix returned by
 * add_menu_page() is cached so the stylesheet enqueue can guard on
 * it without re-deriving it from the slug.
 *
 * The auto-generated first submenu would otherwise duplicate the
 * parent label ("Signal & Noise / Signal & Noise"); add_submenu_page
 * with the same slug overrides the auto entry's label to "Dashboard".
 */
/**
 * The 8 SN admin pages, each rendered by sn_theme_options_page().
 *
 * Defined once at module scope so registration and dispatch read from
 * a single source of truth. Slug uniqueness is critical — WP's
 * add_submenu_page() has no duplicate detection (gotcha #16 in
 * docs/WORDPRESS-REFERENCE.md), so a typo here would silently produce
 * a phantom sidebar entry.
 *
 * Order in the array = display order in the WP sidebar.
 */
function sn_admin_pages() {
	// Note: the 'dashboard' slug ('sn-theme-options') intentionally matches
	// the parent menu slug to suppress WP's auto-prepended duplicate-parent
	// submenu entry (gotcha #14). Order matters: must be first submenu
	// registered.
	return array(
		array( 'slug' => 'sn-theme-options', 'tab' => 'dashboard',    'label' => 'Dashboard',     'title' => 'Signal & Noise — Dashboard',     'subtitle' => 'Status overview and maintenance actions for the theme + plugin pair.' ),
		array( 'slug' => 'sn-identity',      'tab' => 'identity',     'label' => 'Identity',      'title' => 'Signal & Noise — Identity',      'subtitle' => 'Site name, social profiles, Open Graph cards, and per-route SEO copy.' ),
		array( 'slug' => 'sn-login',         'tab' => 'login',        'label' => 'Login',         'title' => 'Signal & Noise — Login',         'subtitle' => 'Custom login URL and emergency unlock for the WordPress admin.' ),
		array( 'slug' => 'sn-cloudflare',    'tab' => 'cloudflare',   'label' => 'Cloudflare',    'title' => 'Signal & Noise — Cloudflare',    'subtitle' => 'API token and zone config for automatic edge-cache purges.' ),
		array( 'slug' => 'sn-plausible',     'tab' => 'plausible',    'label' => 'Plausible',     'title' => 'Signal & Noise — Plausible',     'subtitle' => 'Stats API token for the dashboard widgets.' ),
		array( 'slug' => 'sn-rss',           'tab' => 'rss',          'label' => 'RSS',           'title' => 'Signal & Noise — RSS',           'subtitle' => 'RSS subscriber tracking (delivered by the rss-plausible-tracker MU plugin).' ),
		array( 'slug' => 'sn-reading-time',  'tab' => 'reading-time', 'label' => 'Reading Time',  'title' => 'Signal & Noise — Reading Time',  'subtitle' => 'Legacy reading-time-string cleanup tool for posts written before the shortcode existed.' ),
		array( 'slug' => 'sn-cron',          'tab' => 'cron',         'label' => 'Cron',          'title' => 'Signal & Noise — Cron',          'subtitle' => 'Scheduled jobs — next run, recurrence, last fired, manual trigger.' ),
		array( 'slug' => 'sn-webhooks',      'tab' => 'webhooks',     'label' => 'Webhooks',      'title' => 'Signal & Noise — Webhooks',      'subtitle' => 'Personal automation — fire HMAC-signed POSTs to your own endpoints when posts publish.' ),
		array( 'slug' => 'sn-insights',      'tab' => 'insights',     'label' => 'Insights',      'title' => 'Signal & Noise — Insights',      'subtitle' => 'AI-synthesized recommendations from your analytics, publish history, and automation patterns.' ),
		array( 'slug' => 'sn-health',        'tab' => 'health',       'label' => 'Health',        'title' => 'Signal & Noise — Content Health','subtitle' => 'Detection scans — missing alt text, orphaned media, broken internal links, stale posts.' ),
		array( 'slug' => 'sn-links',         'tab' => 'links',        'label' => 'Links',         'title' => 'Signal & Noise — Links',         'subtitle' => 'External shortcuts — GitHub repos, release pages, Cloudflare, Cloudways.' ),
	);
}

/**
 * Look up the subtitle for the active tab. Used by the page header.
 */
function sn_admin_page_subtitle_for_tab( $tab ) {
	foreach ( sn_admin_pages() as $page ) {
		if ( $page['tab'] === $tab ) {
			return $page['subtitle'];
		}
	}
	return '';
}

/**
 * Single source of truth: every tab slug registered in sn_admin_pages().
 *
 * Derived (not duplicated) so adding a new tab is a one-line edit in
 * sn_admin_pages(). v3.0.0 shipped a regression where Task 10 added the
 * page entry + dispatch case but missed two inline whitelists 200 lines
 * away (CHANGELOG v3.0.2). Encoding this as a derived helper makes the
 * coordination constraint impossible to violate.
 *
 * @since 3.0.2
 */
function sn_admin_page_valid_tabs() {
	return array_column( sn_admin_pages(), 'tab' );
}

/**
 * Single source of truth: tab → label map, keyed by tab slug.
 *
 * @since 3.0.2
 */
function sn_admin_page_tab_labels() {
	return array_column( sn_admin_pages(), 'label', 'tab' );
}

/**
 * Map an admin-page slug to a tab name. Used by sn_theme_options_page()
 * to dispatch when $_GET['tab'] isn't present (v1.9.0+ deep links).
 */
function sn_admin_page_tab_for_slug( $slug ) {
	foreach ( sn_admin_pages() as $page ) {
		if ( $page['slug'] === $slug ) {
			return $page['tab'];
		}
	}
	return 'dashboard';
}

/**
 * Cache of all registered hook suffixes for the SN admin pages.
 * Used by the enqueue guard to load the stylesheet on any of our
 * pages without re-deriving hook names from slugs.
 *
 * add_menu_page() always returns a string; add_submenu_page() returns
 * false when the user lacks the required capability (gotcha #15), so
 * we filter the array before comparing.
 */
function sn_admin_page_hooks( $set = null ) {
	static $hooks = array();
	if ( is_array( $set ) ) {
		$hooks = array_values( array_filter( $set, 'is_string' ) );
	}
	return $hooks;
}

add_action( 'admin_menu', function() {
	$hooks = array();

	$hooks[] = add_menu_page(
		'Signal & Noise',
		'Signal & Noise',
		'manage_options',
		'sn-theme-options',
		'sn_theme_options_page',
		'dashicons-megaphone',
		81
	);

	foreach ( sn_admin_pages() as $page ) {
		$hooks[] = add_submenu_page(
			'sn-theme-options',
			$page['title'],
			$page['label'],
			'manage_options',
			$page['slug'],
			'sn_theme_options_page'
		);
	}

	sn_admin_page_hooks( $hooks );
} );

/**
 * Enqueue the SN admin stylesheet on any of our 8 pages.
 *
 * Guards via in_array() against the collected hook list so a slug
 * rename in sn_admin_pages() won't silently break the guard. Cache-
 * busted by SNT_VERSION.
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! in_array( $hook, sn_admin_page_hooks(), true ) ) {
		return;
	}
	wp_enqueue_style(
		'sn-admin',
		SNT_URL . 'assets/admin.css',
		array(),
		SNT_VERSION
	);
	wp_enqueue_script(
		'sn-admin',
		SNT_URL . 'assets/admin.js',
		array(),
		SNT_VERSION,
		true // load in footer, after DOM is parsed
	);
} );

/**
 * Handle all SN admin form submissions on admin_init.
 *
 * Runs before any HTML output, so wp_safe_redirect() works cleanly.
 * This implements Post/Redirect/Get for our custom forms — the Plugin
 * Handbook recommends Settings API specifically because it does this
 * for you. Since we bypass Settings API to keep a single nested-array
 * option (sn_settings), we own this responsibility (gotchas #18, #19).
 *
 * Save status survives the redirect via the ?sn_flash query arg,
 * which sn_theme_options_page() reads to render the appropriate
 * success/error notice on the post-redirect GET request.
 */
add_action( 'admin_init', 'sn_handle_admin_post' );

function sn_handle_admin_post() {
	if ( ! isset( $_POST['sn_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only process for our admin pages — guards against the handler
	// firing for an unrelated $_POST that happens to carry sn_action.
	$current_page  = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
	$our_slugs     = array_column( sn_admin_pages(), 'slug' );
	if ( ! in_array( $current_page, $our_slugs, true ) ) {
		return;
	}

	check_admin_referer( 'sn_theme_options_nonce' );

	$action = sanitize_text_field( wp_unslash( $_POST['sn_action'] ) );
	$flash  = '';

	if ( 'clear_overrides' === $action ) {
		$count = (int) apply_filters( 'sn_clear_template_overrides_result', 0 );
		$flash = 'cleared_' . $count;
	} elseif ( 'purge_caches' === $action ) {
		apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => false ) );
		$flash = 'purged';
	} elseif ( 'full_reset' === $action ) {
		$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array() );
		$flash = 'reset_' . $count;
	} elseif ( 'save_identity' === $action ) {
		$saved = sn_settings_save( $_POST );
		$flash = $saved ? 'identity_saved' : 'identity_unchanged';
	} elseif ( 'save_login' === $action ) {
		$slug = isset( $_POST['login_slug'] ) ? sanitize_title( wp_unslash( $_POST['login_slug'] ) ) : '';
		if ( ! $slug ) {
			$flash = 'login_empty';
		} else {
			$settings                  = (array) get_option( 'sn_settings', array() );
			$settings['login']         = is_array( $settings['login'] ?? null ) ? $settings['login'] : array();
			$settings['login']['slug'] = $slug;
			update_option( 'sn_settings', $settings );
			// gotcha #10: update_option returns false on both "no change"
			// and "real failure" — re-read to disambiguate.
			$re_read = (array) get_option( 'sn_settings', array() );
			$flash   = ( $re_read['login']['slug'] ?? '' ) === $slug ? 'login_saved' : 'login_failed';
		}
	} elseif ( 'pl_save' === $action ) {
		// Constant-locked field: short-circuit the save so admin edits
		// can't override wp-config. Matches the locked-field-disabled
		// pattern on the Login tab.
		if ( defined( 'SN_PLAUSIBLE_STATS_TOKEN' ) && SN_PLAUSIBLE_STATS_TOKEN ) {
			$flash = 'pl_locked';
		} else {
			$new_token = isset( $_POST['sn_pl_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_pl_token'] ) ) : '';
			if ( 'clear' === $new_token ) {
				delete_option( SN_PLAUSIBLE_TOKEN_OPT );
				sn_pl_admin_invalidate_caches();
				$flash = 'pl_cleared';
			} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
				update_option( SN_PLAUSIBLE_TOKEN_OPT, $new_token, false ); // not autoloaded
				sn_pl_admin_invalidate_caches();
				$flash = 'pl_saved';
			} else {
				// Empty submission with the obscured placeholder = leave alone.
				$flash = 'pl_unchanged';
			}
		}
	} elseif ( 'pl_test' === $action ) {
		$cfg = sn_plausible_config();
		if ( ! $cfg ) {
			$flash = 'pl_test_unconfigured';
		} else {
			delete_transient( SN_PLAUSIBLE_ERR_KEY ); // force-fresh
			$result = sn_plausible_api( 'aggregate', array( 'period' => '7d', 'metrics' => 'visitors' ), $cfg );
			$flash  = is_array( $result ) ? 'pl_test_ok' : 'pl_test_err';
		}
	} elseif ( 'cf_save' === $action ) {
		$token_const = defined( 'SN_CLOUDFLARE_API_TOKEN' );
		$zone_const  = defined( 'SN_CLOUDFLARE_ZONE_ID' );

		if ( ! $token_const ) {
			$new_token = isset( $_POST['sn_cf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_token'] ) ) : '';
			if ( 'clear' === $new_token ) {
				delete_option( SN_CF_TOKEN_OPT );
			} elseif ( '' !== $new_token && '••••' !== substr( $new_token, 0, 4 ) ) {
				update_option( SN_CF_TOKEN_OPT, $new_token, false ); // not autoloaded
			}
		}
		if ( ! $zone_const ) {
			$new_zone = isset( $_POST['sn_cf_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['sn_cf_zone'] ) ) : '';
			if ( 'clear' === $new_zone ) {
				delete_option( SN_CF_ZONE_OPT );
			} elseif ( '' !== $new_zone ) {
				update_option( SN_CF_ZONE_OPT, $new_zone, true );
			}
		}
		$flash = 'cf_saved';
	} elseif ( 'cf_purge_now' === $action ) {
		$flash = sn_cf_purge_everything() ? 'cf_purged_ok' : 'cf_purged_unconfigured';
	} elseif ( 'apply_reading_time_cleanup' === $action ) {
		$count = (int) sn_apply_legacy_reading_time_cleanup();
		$flash = 'rt_applied_' . $count;
	} elseif ( 'health_scan' === $action ) {
		// v3.5.1: route through the central dispatcher per the established
		// pattern (matches cf_save, pl_save, etc.). The impl module owns
		// the work; this handler just dispatches + sets the flash.
		if ( function_exists( 'sn_health_run_scan' ) ) {
			sn_health_run_scan();
		}
		$flash = 'health_scanned';
	} elseif ( 'webhook_add' === $action ) {
		if ( function_exists( 'sn_webhook_create' ) ) {
			$result = sn_webhook_create( wp_unslash( $_POST ) );
			if ( is_wp_error( $result ) ) {
				$flash = 'wh_invalid';
			} else {
				// Encode new id in the flash so the renderer can show the
				// secret once. Same pattern as 'rt_applied_<count>' etc.
				$flash = 'wh_added_' . $result['id'];
			}
		} else {
			$flash = 'wh_invalid';
		}
	} elseif ( 'webhook_update' === $action ) {
		if ( function_exists( 'sn_webhook_update' ) ) {
			$id     = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
			$rotate = ! empty( $_POST['rotate_secret'] );
			$result = sn_webhook_update( $id, wp_unslash( $_POST ) );
			if ( is_wp_error( $result ) ) {
				$flash = 'wh_not_found';
			} else {
				$flash = $rotate ? ( 'wh_rotated_' . $id ) : 'wh_updated';
			}
		} else {
			$flash = 'wh_not_found';
		}
	} elseif ( 'webhook_delete' === $action ) {
		if ( function_exists( 'sn_webhook_delete' ) ) {
			$id = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
			sn_webhook_delete( $id );
		}
		$flash = 'wh_deleted';
	} elseif ( 'insights_run' === $action ) {
		if ( function_exists( 'snt_insights_run_scan' ) ) {
			$force  = ! empty( $_POST['force'] );
			$result = snt_insights_run_scan( $force );
			$flash  = is_wp_error( $result ) ? 'insights_failed' : 'insights_scanned';
		} else {
			$flash = 'insights_failed';
		}
	} elseif ( 'insights_dismiss' === $action ) {
		if ( function_exists( 'snt_insights_dismiss' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_dismiss( $id );
		}
		$flash = 'insights_dismissed';
	} elseif ( 'insights_snooze' === $action ) {
		if ( function_exists( 'snt_insights_snooze' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_snooze( $id );
		}
		$flash = 'insights_snoozed';
	} elseif ( 'insights_mark_done' === $action ) {
		if ( function_exists( 'snt_insights_mark_done' ) ) {
			$id = isset( $_POST['rec_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_id'] ) ) : '';
			snt_insights_mark_done( $id );
		}
		$flash = 'insights_done';
	} else {
		return;
	}

	$redirect_args = array(
		'page'     => $current_page,
		'sn_flash' => $flash,
	);
	// Preserve v1.8.x-style ?tab=… so legacy bookmarks survive PRG.
	if ( isset( $_REQUEST['tab'] ) ) {
		$redirect_args['tab'] = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
	}
	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
	wp_safe_redirect( $redirect_url );
	exit;
}

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
	$valid_tabs = sn_admin_page_valid_tabs();

	// Dispatch order: (1) explicit ?tab=… in URL (v1.8.x legacy deep links;
	// must keep working); (2) derive from the current ?page=… slug (v1.9.0
	// path — each sidebar submenu has a unique slug). Default to dashboard
	// if neither resolves.
	if ( isset( $_GET['tab'] ) ) {
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
	} else {
		$current_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'sn-theme-options';
		$active_tab   = sn_admin_page_tab_for_slug( $current_slug );
	}

	if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
		$active_tab = 'dashboard';
	}

	// Form processing happens in sn_handle_admin_post() on admin_init —
	// before any output, so wp_safe_redirect() works (gotcha #17, #19).
	// This block just translates ?sn_flash=… into notices for the
	// post-redirect GET request.
	if ( isset( $_GET['sn_flash'] ) ) {
		$flash = sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) );
		if ( 'identity_saved' === $flash ) {
			$notices[] = array( 'success', 'Identity settings saved.' );
		} elseif ( 'identity_unchanged' === $flash ) {
			$notices[] = array( 'info', 'No changes to save.' );
		} elseif ( 'login_saved' === $flash ) {
			$slug_now  = sn_setting( 'login.slug', 'sn-login' );
			$login_url = home_url( '/' . $slug_now );
			$notices[] = array( 'success', 'Login slug saved. New URL: <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>' );
		} elseif ( 'login_empty' === $flash ) {
			$notices[] = array( 'error', 'Login slug cannot be empty.' );
		} elseif ( 'login_failed' === $flash ) {
			$notices[] = array( 'error', 'Login slug save failed.' );
		} elseif ( 'pl_saved' === $flash ) {
			$notices[] = array( 'success', 'Stats API key saved. Caches purged — widgets refresh on next dashboard view.' );
		} elseif ( 'pl_cleared' === $flash ) {
			$notices[] = array( 'success', 'Stats API key cleared. Caches purged.' );
		} elseif ( 'pl_unchanged' === $flash ) {
			$notices[] = array( 'info', 'No changes to save.' );
		} elseif ( 'pl_locked' === $flash ) {
			$notices[] = array( 'error', 'Token is locked by the SN_PLAUSIBLE_STATS_TOKEN constant — remove the constant in wp-config.php to edit here.' );
		} elseif ( 'pl_test_ok' === $flash ) {
			// Read fresh count from the transient sn_plausible_api populates.
			$cached   = get_transient( SN_PLAUSIBLE_BATCH_KEY );
			$visitors = is_array( $cached ) && isset( $cached['data']['visitors']['value'] ) ? (int) $cached['data']['visitors']['value'] : 0;
			$notices[] = array( 'success', '&#10003; API call succeeded — ' . number_format_i18n( $visitors ) . ' visitor(s) in last 7 days.' );
		} elseif ( 'pl_test_err' === $flash ) {
			$err    = sn_plausible_last_error();
			$detail = $err ? 'HTTP ' . (int) $err['code'] . ' &middot; <code>' . esc_html( substr( $err['message'], 0, 200 ) ) . '</code>' : 'no diagnostic recorded';
			$notices[] = array( 'error', '&#10005; API call failed &mdash; ' . $detail );
		} elseif ( 'pl_test_unconfigured' === $flash ) {
			$notices[] = array( 'error', 'Plausible not fully configured (missing domain or token).' );
		} elseif ( 'cf_saved' === $flash ) {
			$notices[] = array( 'success', 'Cloudflare settings saved.' );
		} elseif ( 'cf_purged_ok' === $flash ) {
			$notices[] = array( 'success', 'Cloudflare zone purge dispatched.' );
		} elseif ( 'cf_purged_unconfigured' === $flash ) {
			$notices[] = array( 'warning', 'Cloudflare not configured — set the API token and zone ID first.' );
		} elseif ( 0 === strpos( $flash, 'rt_applied_' ) ) {
			$count     = (int) substr( $flash, strlen( 'rt_applied_' ) );
			$notices[] = array( 'success', sprintf( '%d post(s) cleaned. Reading-time cache rebuilt.', $count ) );
		} elseif ( 'purged' === $flash ) {
			$notices[] = array( 'success', 'All caches purged.' );
		} elseif ( 0 === strpos( $flash, 'cleared_' ) ) {
			$count     = (int) substr( $flash, strlen( 'cleared_' ) );
			$notices[] = array( 'success', $count . ' database override(s) cleared. Site is reading from theme files.' );
		} elseif ( 0 === strpos( $flash, 'reset_' ) ) {
			$count     = (int) substr( $flash, strlen( 'reset_' ) );
			$notices[] = array( 'success', 'Full reset: ' . $count . ' override(s) cleared + all caches purged.' );
		} elseif ( 0 === strpos( $flash, 'wh_added_' ) ) {
			$notices[] = array( 'success', 'Webhook added. Copy the signing secret below — it will not be shown again.' );
		} elseif ( 'wh_updated' === $flash ) {
			$notices[] = array( 'success', 'Webhook updated.' );
		} elseif ( 0 === strpos( $flash, 'wh_rotated_' ) ) {
			$notices[] = array( 'success', 'Webhook updated. <strong>Signing secret was rotated</strong> — copy the new value below before navigating away.' );
		} elseif ( 'wh_deleted' === $flash ) {
			$notices[] = array( 'success', 'Webhook deleted. Pending retries (if any) will drop on next dispatch.' );
		} elseif ( 'wh_invalid' === $flash ) {
			$notices[] = array( 'error', 'Could not add webhook — name and valid URL are required.' );
		} elseif ( 'wh_not_found' === $flash ) {
			$notices[] = array( 'error', 'Webhook not found.' );
		} elseif ( 'insights_scanned' === $flash ) {
			$notices[] = array( 'success', 'Insights scan complete — recommendations below.' );
		} elseif ( 'insights_failed' === $flash ) {
			$notices[] = array( 'error', 'Insights scan failed. Check that an AI provider is configured under Settings → Connectors.' );
		} elseif ( 'insights_dismissed' === $flash ) {
			$notices[] = array( 'success', 'Recommendation dismissed.' );
		} elseif ( 'insights_snoozed' === $flash ) {
			$notices[] = array( 'success', 'Recommendation snoozed for 30 days.' );
		} elseif ( 'insights_done' === $flash ) {
			$notices[] = array( 'success', 'Recommendation marked as done.' );
		} elseif ( 'health_scanned' === $flash ) {
			$notices[] = array( 'success', 'Scan complete — findings below.' );
		}
	}

	// Extract the new/rotated webhook id from the flash so the Webhooks
	// renderer can highlight the affected row + show the secret once.
	if ( ! isset( $_GET['new_id'] ) && isset( $_GET['sn_flash'] ) ) {
		$flash_now = sanitize_text_field( wp_unslash( $_GET['sn_flash'] ) );
		if ( 0 === strpos( $flash_now, 'wh_added_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_added_' ) );
		} elseif ( 0 === strpos( $flash_now, 'wh_rotated_' ) ) {
			$_GET['new_id'] = substr( $flash_now, strlen( 'wh_rotated_' ) );
		}
	}

	$local_sha = (string) get_option( 'sn_github_local_sha', '' );

	$overrides = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part', 'wp_navigation' ), 'posts_per_page' => -1, 'post_status' => 'any' ) );
	$base_url  = admin_url( 'admin.php?page=sn-theme-options' );

	// ── PAGE SHELL ──
	echo '<div class="wrap">';
	echo '<h1 class="sn-page-h1">Signal &amp; Noise</h1>';
	$subtitle = sn_admin_page_subtitle_for_tab( $active_tab );
	if ( $subtitle ) {
		echo '<p class="sn-page-subtitle">' . esc_html( $subtitle ) . '</p>';
	}

	// Notices. Severity is escaped as an attribute; bodies are run
	// through wp_kses_post because some entries deliberately ship
	// inline markup (<a>, <code>) — esc_html would mangle those.
	foreach ( $notices as $n ) {
		echo '<div class="notice notice-' . esc_attr( $n[0] ) . ' is-dismissible"><p>' . wp_kses_post( $n[1] ) . '</p></div>';
	}

	// ── TABS ──
	$tab_labels = sn_admin_page_tab_labels();
	echo '<nav class="nav-tab-wrapper sn-nav-tabs">';
	foreach ( $tab_labels as $slug => $label ) {
		$is_active = ( $slug === $active_tab );
		echo '<a href="' . esc_url( $base_url . '&tab=' . $slug ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';

	// ════════════════════════════════════════
	// TAB: DASHBOARD
	// ════════════════════════════════════════
	if ( 'dashboard' === $active_tab ) {

		/**
		 * As of v1.14.0, the Dashboard tab is rendered entirely by
		 * inc/admin-tab-dashboard.php via the sn_admin_dashboard_extras
		 * hook. The legacy Status table + Override details + Actions
		 * card grid that used to live here were absorbed into the new
		 * file's unified composition (hero state grid + recent deploys
		 * + maintenance cards + API summary + diagnostics).
		 *
		 * The hook name stays for backward compatibility with any
		 * third-party listener (none currently registered) and matches
		 * the module-owned tab pattern used by all other tabs
		 * (sn_admin_cloudflare_tab, sn_admin_plausible_tab, etc.).
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
			echo '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS subscriber tracker not installed.</strong></p>';
			echo '<p>Copy <code>mu-plugins/rss-plausible-tracker.php</code> from the theme repo to <code>wp-content/mu-plugins/</code> on this host. MU plugins activate automatically — no further action needed.</p></div>';
		}

	// ════════════════════════════════════════
	// TAB: READING TIME
	// ════════════════════════════════════════
	} elseif ( 'reading-time' === $active_tab ) {

		/** Module-owned UI: see inc/reading-time.php. */
		do_action( 'sn_admin_reading_time_tab' );

	// ════════════════════════════════════════
	// TAB: CRON
	// ════════════════════════════════════════
	} elseif ( 'cron' === $active_tab ) {

		/** Module-owned UI: see inc/cron-dashboard-admin.php. */
		do_action( 'sn_admin_cron_tab' );

	// ════════════════════════════════════════
	// TAB: WEBHOOKS
	// ════════════════════════════════════════
	} elseif ( 'webhooks' === $active_tab ) {

		/** Module-owned UI: see inc/webhooks-admin.php. */
		do_action( 'sn_admin_webhooks_tab' );

	// ════════════════════════════════════════
	// TAB: INSIGHTS
	// ════════════════════════════════════════
	} elseif ( 'insights' === $active_tab ) {

		/** Module-owned UI: see inc/insights-admin.php. */
		do_action( 'sn_admin_insights_tab' );

	// ════════════════════════════════════════
	// TAB: HEALTH
	// ════════════════════════════════════════
	} elseif ( 'health' === $active_tab ) {

		/** Module-owned UI: see inc/health-checks-admin.php. */
		do_action( 'sn_admin_health_tab' );

	// ════════════════════════════════════════
	// TAB: LINKS
	// ════════════════════════════════════════
	} elseif ( 'links' === $active_tab ) {

		// v1.14.0: upgraded from a 4-row .form-table to a card grid that
		// scans faster. Each card has a category label + a title + the
		// destination host, with the whole card as the click target via
		// an absolutely-positioned link overlay (.sn-link-card__link).
		$link_groups = array(
			array(
				'label' => 'Source code',
				'links' => array(
					array( 'title' => 'Theme repo',  'href' => 'https://github.com/juanlentino/signal-and-noise' ),
					array( 'title' => 'Plugin repo', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools' ),
				),
			),
			array(
				'label' => 'Releases',
				'links' => array(
					array( 'title' => 'Theme releases',  'href' => 'https://github.com/juanlentino/signal-and-noise/releases' ),
					array( 'title' => 'Plugin releases', 'href' => 'https://github.com/juanlentino/signal-and-noise-tools/releases' ),
				),
			),
			array(
				'label' => 'Infrastructure',
				'links' => array(
					array( 'title' => 'Cloudflare dashboard', 'href' => 'https://dash.cloudflare.com' ),
					array( 'title' => 'Cloudways platform',   'href' => 'https://platform.cloudways.com' ),
				),
			),
		);
		echo '<div class="sn-link-grid">';
		foreach ( $link_groups as $group ) {
			foreach ( $group['links'] as $link ) {
				$host = (string) wp_parse_url( $link['href'], PHP_URL_HOST );
				echo '<div class="sn-link-card">';
				echo '<span class="sn-link-card__label">' . esc_html( $group['label'] ) . '</span>';
				echo '<span class="sn-link-card__title">' . esc_html( $link['title'] ) . '</span>';
				echo '<span class="sn-link-card__host">' . esc_html( $host ) . ' &#x2197;</span>';
				echo '<a class="sn-link-card__link" href="' . esc_url( $link['href'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['title'] ) . '</a>';
				echo '</div>';
			}
		}
		echo '</div>';

	// ════════════════════════════════════════
	// TAB: IDENTITY
	// ════════════════════════════════════════
	} elseif ( 'identity' === $active_tab ) {

		echo '<form method="post" class="sn-identity-form">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="save_identity">';

		echo '<p class="sn-prose">Site-identity values used by OG/Twitter meta, JSON-LD schema, and per-route SEO copy. Empty fields fall back to WordPress built-in defaults (site name, tagline).</p>';

		// Section TOC — anchor-jump links into the long form below.
		echo '<nav class="sn-toc" aria-label="Identity sections">';
		echo '<span class="sn-toc-label">Jump to</span>';
		echo '<a href="#sn-sec-identity">Identity</a>';
		echo '<a href="#sn-sec-social">Social</a>';
		echo '<a href="#sn-sec-og">Open Graph</a>';
		echo '<a href="#sn-sec-seo">SEO Copy</a>';
		echo '</nav>';

		// ── IDENTITY ──
		echo '<div class="sn-fieldset" id="sn-sec-identity">';
		echo '<h2 class="sn-fieldset-h">Identity</h2>';
		echo '<p class="sn-fieldset-intro">Site-wide name, description, and locale.</p>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_site_name">Site name</label>';
		echo '<input type="text" id="sn_identity_site_name" name="identity_site_name" value="' . esc_attr( sn_setting( 'identity.site_name', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_identity_site_description">Site description</label>';
		echo '<textarea id="sn_identity_site_description" name="identity_site_description" rows="2">' . esc_textarea( (string) sn_setting( 'identity.site_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_person_name">Person name (schema author)</label>';
		echo '<input type="text" id="sn_identity_person_name" name="identity_person_name" value="' . esc_attr( sn_setting( 'identity.person_name', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-md">';
		echo '<label class="sn-field-label" for="sn_identity_job_title">Job title</label>';
		echo '<input type="text" id="sn_identity_job_title" name="identity_job_title" value="' . esc_attr( sn_setting( 'identity.job_title', 'Music Producer' ) ) . '" placeholder="Music Producer">';
		echo '<p class="sn-field-helper">Emitted as <code>jobTitle</code> on the Person schema. Single short phrase.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_identity_knows_about">Knows about</label>';
		$knows_about_value = (array) sn_setting(
			'identity.knows_about',
			array( 'Music Production', 'Audio Engineering', 'Provenance', 'Music Industry' )
		);
		echo '<textarea id="sn_identity_knows_about" name="identity_knows_about" rows="4">' . esc_textarea( implode( "\n", $knows_about_value ) ) . '</textarea>';
		echo '<p class="sn-field-helper">One topic per line. Emitted as the <code>knowsAbout</code> array on the Person schema — domain expertise areas that signal to search engines what this person is about. Leave a line blank to omit the entry.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_identity_locale">Locale</label>';
		echo '<input type="text" id="sn_identity_locale" name="identity_locale" value="' . esc_attr( sn_setting( 'identity.locale', 'en_US' ) ) . '" placeholder="en_US">';
		echo '<p class="sn-field-helper">WP locale code (e.g. <code>en_US</code>). Used for og:locale and schema inLanguage.</p>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// ── SOCIAL ──
		echo '<div class="sn-fieldset" id="sn-sec-social">';
		echo '<h2 class="sn-fieldset-h">Social</h2>';
		echo '<p class="sn-fieldset-intro">Twitter / X handle and profile URLs (emitted as schema sameAs).</p>';

		echo '<div class="sn-field sn-field-w-sm">';
		echo '<label class="sn-field-label" for="sn_social_twitter_handle">Twitter / X handle</label>';
		echo '<input type="text" id="sn_social_twitter_handle" name="social_twitter_handle" value="' . esc_attr( sn_setting( 'social.twitter_handle', '' ) ) . '" placeholder="@username">';
		echo '<p class="sn-field-helper">Used as twitter:site and twitter:creator. Include the @ prefix.</p>';
		echo '</div>';

		$same_as = (array) sn_setting( 'social.same_as', array() );
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Profile URLs (sameAs)</label>';
		echo '<div class="sn-sameas">';
		foreach ( $same_as as $url ) {
			echo '<input type="url" name="social_same_as[]" value="' . esc_attr( (string) $url ) . '" placeholder="https://...">';
		}
		// "+ Add another" button — JS handler in assets/admin.js inserts a
		// new <input> above the button on click. <noscript> fallback
		// preserves the v1.9.5 single-trailing-input behaviour for users
		// with JavaScript disabled.
		echo '<button type="button" class="sn-add-row-btn" aria-label="Add another profile URL row">Add another profile URL</button>';
		echo '<noscript>';
		echo '<input type="url" name="social_same_as[]" value="" placeholder="https://..." class="sn-sameas-extra">';
		echo '</noscript>';
		echo '</div>'; // .sn-sameas
		echo '<p class="sn-field-helper">Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.</p>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// ── OG ──
		echo '<div class="sn-fieldset" id="sn-sec-og">';
		echo '<h2 class="sn-fieldset-h">Open Graph</h2>';
		echo '<p class="sn-fieldset-intro">Fallback OG image and card dimensions for social shares.</p>';

		echo '<div class="sn-field sn-field-w-lg">';
		echo '<label class="sn-field-label" for="sn_og_default_image_url">Default OG image URL</label>';
		echo '<input type="url" id="sn_og_default_image_url" name="og_default_image_url" value="' . esc_attr( (string) sn_setting( 'og.default_image_url', '' ) ) . '">';
		echo '<p class="sn-field-helper">Fallback image used when no per-post OG card exists.</p>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_og_card_width">Card width (px)</label>';
		echo '<input type="number" min="1" id="sn_og_card_width" name="og_card_width" value="' . esc_attr( (string) sn_setting( 'og.card_width', 1200 ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xs">';
		echo '<label class="sn-field-label" for="sn_og_card_height">Card height (px)</label>';
		echo '<input type="number" min="1" id="sn_og_card_height" name="og_card_height" value="' . esc_attr( (string) sn_setting( 'og.card_height', 630 ) ) . '">';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// ── SEO COPY ──
		echo '<div class="sn-fieldset" id="sn-sec-seo">';
		echo '<h2 class="sn-fieldset-h">SEO Copy</h2>';
		echo '<p class="sn-fieldset-intro">Per-route title + description for the home, /notes, and /provenance pages.</p>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_home_title">Home title</label>';
		echo '<input type="text" id="sn_seo_home_title" name="seo_home_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.home_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_home_description">Home description</label>';
		echo '<textarea id="sn_seo_home_description" name="seo_home_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.home_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_notes_title">/notes title</label>';
		echo '<input type="text" id="sn_seo_notes_title" name="seo_notes_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.notes_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_notes_description">/notes description</label>';
		echo '<textarea id="sn_seo_notes_description" name="seo_notes_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.notes_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_provenance_title">/provenance title</label>';
		echo '<input type="text" id="sn_seo_provenance_title" name="seo_provenance_title" value="' . esc_attr( (string) sn_setting( 'seo_copy.provenance_title', '' ) ) . '">';
		echo '</div>';

		echo '<div class="sn-field sn-field-w-xl">';
		echo '<label class="sn-field-label" for="sn_seo_provenance_description">/provenance description</label>';
		echo '<textarea id="sn_seo_provenance_description" name="seo_provenance_description" rows="2">' . esc_textarea( (string) sn_setting( 'seo_copy.provenance_description', '' ) ) . '</textarea>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset

		// Sticky save bar — same pattern as v1.8.1.
		echo '<div class="sn-savebar">';
		echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
		echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
		echo '</div>';
		echo '</form>';

	// ════════════════════════════════════════
	// TAB: LOGIN
	// ════════════════════════════════════════
	} elseif ( 'login' === $active_tab ) {

		// Detect module state. Three possibilities:
		//   1. ACTIVE: our login-hide.php is firing (no wps-hide-login,
		//      no SN_LOGIN_BYPASS)
		//   2. DORMANT (conflict): wps-hide-login is still active so
		//      our module stood down
		//   3. DORMANT (bypass): SN_LOGIN_BYPASS constant is set
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// v2.1.1: mirror the tightened check from login-hide.php — option
		// entry alone isn't authoritative; the file must also exist on
		// disk. Without this, an orphan slug in active_plugins would
		// have this status display falsely claim "dormant — conflict
		// with wps-hide-login" even though the file is gone and our
		// module is actually active.
		$wps_basename = 'wps-hide-login/wps-hide-login.php';
		$wps_active   = is_plugin_active( $wps_basename ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
		$bypassed     = defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS;
		$slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' );
		$slug_const = defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG;
		$login_url  = home_url( '/' . $slug );

		echo '<p class="sn-prose">Custom login URL module — replaces <code>/wp-login.php</code> with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).</p>';

		// Status box
		if ( $bypassed ) {
			echo '<div class="sn-status-box sn-status-box--warn">';
			echo '<div>';
			echo '<p class="sn-status-box-title">Module bypassed</p>';
			echo '<p class="sn-status-box-body">The <code>SN_LOGIN_BYPASS</code> constant is set in <code>wp-config.php</code>. Default <code>/wp-login.php</code> behavior is restored. Remove the constant to re-enable.</p>';
			echo '</div>';
			echo '<span class="sn-pill sn-pill--warn">Bypassed</span>';
			echo '</div>';
		} elseif ( $wps_active ) {
			echo '<div class="sn-status-box sn-status-box--warn">';
			echo '<div>';
			echo '<p class="sn-status-box-title">Module dormant — conflict with wps-hide-login</p>';
			echo '<p class="sn-status-box-body">The <code>wps-hide-login</code> plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.</p>';
			echo '</div>';
			echo '<span class="sn-pill sn-pill--warn">Dormant</span>';
			echo '</div>';
		} else {
			echo '<div class="sn-status-box">';
			echo '<div>';
			echo '<p class="sn-status-box-title">Module active</p>';
			echo '<p class="sn-status-box-body">Direct visits to <code>/wp-login.php</code> and unauthenticated <code>/wp-admin</code> return 404. Login form reachable at the custom URL below.</p>';
			echo '</div>';
			echo '<span class="sn-pill sn-pill--ok">Active</span>';
			echo '</div>';
		}

		// Slug edit form (in its own fieldset)
		echo '<form method="post">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="save_login">';

		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">Custom login slug</h2>';
		echo '<p class="sn-fieldset-intro">The path segment used in place of <code>wp-login.php</code>.</p>';

		echo '<div class="sn-field sn-field-w-sm">';
		echo '<label class="sn-field-label" for="sn_login_slug">Slug</label>';
		if ( $slug_const ) {
			echo '<input type="text" id="sn_login_slug" value="' . esc_attr( $slug ) . '" disabled>';
			echo '<p class="sn-field-helper"><strong>Locked.</strong> The <code>SN_LOGIN_SLUG</code> constant in <code>wp-config.php</code> is overriding this field. Remove the constant to edit here.</p>';
		} else {
			echo '<input type="text" id="sn_login_slug" name="login_slug" value="' . esc_attr( $slug ) . '" placeholder="sn-login">';
			echo '<p class="sn-field-helper">Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).</p>';
		}
		echo '</div>';

		// Current login URL preview
		echo '<div class="sn-field">';
		echo '<label class="sn-field-label">Current login URL</label>';
		echo '<a class="sn-url-preview" href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>';
		echo '<p class="sn-field-helper">Bookmark this URL. The default <code>/wp-login.php</code> 404s for unauthenticated visitors.</p>';
		echo '</div>';

		// Inline save action — short form, no need for sticky save bar.
		// Hint copy only appears when the constant is locking the field.
		echo '<div class="sn-fieldset-actions">';
		if ( $slug_const ) {
			echo '<p class="sn-fieldset-actions-hint">Slug locked by <code>SN_LOGIN_SLUG</code> constant.</p>';
		}
		echo '<button type="submit" class="button button-primary"' . ( $slug_const ? ' disabled' : '' ) . '>Save</button>';
		echo '</div>';

		echo '</div>'; // .sn-fieldset
		echo '</form>';

		// Emergency unlock docs (out-of-form, no submission)
		echo '<div class="sn-callout">';
		echo '<p class="sn-callout-h">Emergency unlock</p>';
		echo '<p>If you ever lock yourself out (forgot the slug, can\'t reach the login form), add either of these constants to <code>wp-config.php</code> via SSH or your host\'s file manager:</p>';
		echo '<pre>// Option 1 — pin the slug. Reachable at /&lt;slug-here&gt;.
define( \'SN_LOGIN_SLUG\', \'your-fallback-slug\' );

// Option 2 — disable the module entirely. Restores /wp-login.php.
define( \'SN_LOGIN_BYPASS\', true );</pre>';
		echo '<p>The constants take priority over the setting and persist across plugin updates. Remove them once you\'ve regained access.</p>';
		echo '</div>';

	}

	echo '</div>'; // wrap
}
