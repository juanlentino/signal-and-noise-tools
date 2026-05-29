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
		// v4.1.1 (D-07): pass explicit template_overrides=true rather than an
		// empty args array. The theme-side listener's interpretation of an
		// empty array vs. an explicit truthy flag was previously undefined at
		// the call site. "Full reset" semantically includes template overrides;
		// being explicit prevents drift if the theme tightens its filter contract.
		$count = (int) apply_filters( 'sn_purge_all_caches_result', 0, array( 'template_overrides' => true ) );
		$flash = 'reset_' . $count;
	} elseif ( 'save_identity' === $action ) {
		$saved = sn_settings_save( $_POST );
		$flash = $saved ? 'identity_saved' : 'identity_unchanged';
	} elseif ( 'save_login' === $action ) {
		$slug = isset( $_POST['login_slug'] ) ? sanitize_title( wp_unslash( $_POST['login_slug'] ) ) : '';
		if ( ! $slug ) {
			$flash = 'login_empty';
		} else {
			// v4.2.0 (D-06): write via sn_setting_update() so the
			// per-request static cache is busted — any sn_setting()
			// call later in this request sees the new slug.
			// v4.2.1: removed the delete_option('sn_login_rewrites_flushed')
			// force-flush call — the rewrite-rule routing was removed in
			// v4.2.1 in favor of plugins_loaded request interception
			// (inc/login-hide.php), so there's no sentinel to invalidate.
			$ok    = sn_setting_update( 'login.slug', $slug );
			$flash = $ok ? 'login_saved' : 'login_failed';
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
	} elseif ( 'save_insights_settings' === $action ) {
		// v4.2.0 (D-06): write via sn_setting_update() — busts the
		// per-request cache and provides the standard re-read
		// confirmation. The cron sync below reads back the new value
		// via sn_setting(), which now sees it.
		$enabled = ! empty( $_POST['insights_weekly_cron'] );
		sn_setting_update( 'insights.weekly_cron_enabled', $enabled );

		// Sync the cron schedule with the new setting.
		if ( $enabled ) {
			if ( function_exists( 'snt_insights_maybe_schedule_weekly_cron' ) ) {
				snt_insights_maybe_schedule_weekly_cron();
			}
		} else {
			if ( function_exists( 'snt_insights_unschedule_weekly_cron' ) ) {
				snt_insights_unschedule_weekly_cron();
			}
		}
		$flash = 'insights_settings_saved';
	} elseif ( 'audit_save_retention' === $action ) {
		$raw   = isset( $_POST['audit_retention_days'] ) ? (int) $_POST['audit_retention_days'] : 90;
		$days  = max( 7, min( 365, $raw ) );
		$ok    = sn_setting_update( 'audit.retention_days', $days );
		$flash = $ok ? 'audit_retention_saved' : 'audit_retention_unchanged';
	} elseif ( 'pattern_adoption_scan' === $action ) {
		// v4.3.0: Pattern-adoption opportunity scan — routes through the
		// central dispatcher per the established health_scan pattern. The
		// impl module owns the work; this handler just dispatches + sets
		// the flash.
		if ( function_exists( 'snt_pattern_adoption_run_scan' ) ) {
			snt_pattern_adoption_run_scan();
		}
		$flash = 'pattern_adoption_scanned';
	} elseif ( 'block_migrations_scan' === $action ) {
		// v4.5.0: Block-migration opportunity scan — mirrors the
		// pattern_adoption_scan dispatcher. The impl module owns the work;
		// this handler just dispatches + sets the flash.
		if ( function_exists( 'snt_block_migrations_run_scan' ) ) {
			snt_block_migrations_run_scan();
		}
		$flash = 'block_migrations_scanned';
	} else {
		return;
	}

	$redirect_args = array(
		'page'     => $current_page,
		'sn_flash' => $flash,
	);

	// v3.8.0+: redirect to canonical top-tab + anchor (instead of legacy
	// tab slug). v3.8.1+: also preserves &sub= query arg so flash notices
	// land on the right sub-tab (otherwise saving a form on sub-tab X
	// would redirect to the top-tab's default sub-tab, losing context).
	// The legacy tab slug from the form POST is mapped via
	// sn_admin_legacy_redirect_map() — if it's a known legacy slug, the
	// canonical top-tab + sub-tab + anchor replace it.
	$anchor = '';
	if ( isset( $_REQUEST['tab'] ) ) {
		$requested_tab = sanitize_text_field( wp_unslash( $_REQUEST['tab'] ) );
		$map           = sn_admin_legacy_redirect_map();
		$top_tabs      = array_column( sn_admin_top_tabs(), 'tab' );

		if ( in_array( $requested_tab, $top_tabs, true ) ) {
			// Already a canonical top tab; pass through.
			$redirect_args['tab'] = $requested_tab;
			// v3.8.1+: preserve &sub= from the request (set by sub-tab forms).
			if ( isset( $_REQUEST['sub'] ) ) {
				$redirect_args['sub'] = sanitize_text_field( wp_unslash( $_REQUEST['sub'] ) );
			}
		} elseif ( isset( $map[ $requested_tab ] ) ) {
			// Legacy slug; rewrite to canonical destination.
			$redirect_args['tab'] = $map[ $requested_tab ]['tab'];
			if ( ! empty( $map[ $requested_tab ]['sub'] ) ) {
				$redirect_args['sub'] = $map[ $requested_tab ]['sub'];
			}
			if ( ! empty( $map[ $requested_tab ]['anchor'] ) ) {
				$anchor = '#sn-sec-' . rawurlencode( $map[ $requested_tab ]['anchor'] );
			}
		} else {
			// Unknown slug; fall back to dashboard.
			$redirect_args['tab'] = 'dashboard';
		}
	}

	$redirect_url = add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . $anchor;

	// Raw header() because wp_safe_redirect() strips URL fragments.
	// Destination is admin_url() (same-host, trusted) with sanitized
	// top-tab name from a fixed allowlist — safe.
	// 302 not 301: this is a transient post-save redirect, not a "moved permanently" signal.
	header( 'Location: ' . $redirect_url, true, 302 );
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

	// v3.8.0+: 301-redirect legacy tab/page slugs to canonical destinations.
	// Must run BEFORE any output so headers can still be sent.
	sn_admin_maybe_redirect_legacy();

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
		} elseif ( 'insights_settings_saved' === $flash ) {
			$notices[] = array( 'success', 'Insights settings saved.' );
		} elseif ( 'health_scanned' === $flash ) {
			$notices[] = array( 'success', 'Scan complete — findings below.' );
		} elseif ( 'pattern_adoption_scanned' === $flash ) {
			$notices[] = array( 'success', 'Scan complete.' );
		} elseif ( 'block_migrations_scanned' === $flash ) {
			$notices[] = array( 'success', 'Block migration scan complete.' );
		} elseif ( 'audit_retention_saved' === $flash ) {
			$notices[] = array( 'success', 'Audit retention saved.' );
		} elseif ( 'audit_retention_unchanged' === $flash ) {
			$notices[] = array( 'info', 'Audit retention unchanged.' );
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

	// v4.1.1 (X-03): removed dead `$local_sha = get_option('sn_github_local_sha', '')`.
	// The option was written by the legacy updater (inc/updater.php) retired in
	// theme v8.3.0 — the variable was never read after fetch and the option is
	// always empty string on current installs. Existing leftover DB data is
	// harmless; no migration needed.

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

	// v3.8.1+: resolve the active sub-tab for the current top tab. Used by
	// every dispatch arm below to render only the active sub-tab's content
	// instead of all sub-sections (fixes the v3.8.0 long-scroll-per-tab issue).
	// Returns '' for Dashboard (which has no sub_tabs).
	$active_sub = sn_admin_resolve_active_sub( $active_tab );

	// ════════════════════════════════════════
	// TAB: DASHBOARD (landing — no sub-tabs)
	// ════════════════════════════════════════
	if ( 'dashboard' === $active_tab ) {

		/**
		 * Dashboard renders the hero state grid + recent deploys +
		 * maintenance cards + API summary + diagnostics via the
		 * sn_admin_dashboard_extras hook (see inc/admin-tab-dashboard.php).
		 * This is a landing page with no in-page TOC.
		 */
		do_action( 'sn_admin_dashboard_extras' );

	// ════════════════════════════════════════
	// TAB: SITE (v3.8.1+: sub-tabs)
	// Sub-tabs: identity-and-seo (with inner TOC for 4 form sections), cloudflare
	// ════════════════════════════════════════
	} elseif ( 'site' === $active_tab ) {

		sn_admin_render_sub_tabs( 'site', $active_sub );

	if ( 'cloudflare' === $active_sub ) {
		// Cloudflare sub-tab — module-owned (inc/cloudflare-purge.php), own form.
		sn_admin_render_section( 'cloudflare', function() {
			do_action( 'sn_admin_cloudflare_tab' );
		} );
	} else {
		// Default sub-tab: 'identity-and-seo' (bundle of 4 form sections with one Save).
		sn_admin_render_toc( 'site', 'identity-and-seo' );

		echo '<form method="post" class="sn-identity-form">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="sn_action" value="save_identity">';

		sn_admin_render_section( 'identity', function() {
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
		} );

		sn_admin_render_section( 'social', function() {
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
			// WCAG 4.1.2: each repeating input needs its own accessible name.
			// The visible .sn-field-label applies to the group; aria-label on
			// each row gives screen readers a per-input name. Matches the
			// pattern already in assets/admin.js initAddRowButton() for
			// dynamically-added rows (audit D PA-10).
			foreach ( $same_as as $url ) {
				echo '<input type="url" name="social_same_as[]" value="' . esc_attr( (string) $url ) . '" placeholder="https://..." aria-label="Profile URL">';
			}
			echo '<button type="button" class="sn-add-row-btn" aria-label="Add another profile URL row">Add another profile URL</button>';
			echo '<noscript>';
			echo '<input type="url" name="social_same_as[]" value="" placeholder="https://..." class="sn-sameas-extra" aria-label="Profile URL">';
			echo '</noscript>';
			echo '</div>'; // .sn-sameas
			echo '<p class="sn-field-helper">Emitted as the Person schema sameAs array. Leave a row empty to remove it on save.</p>';
			echo '</div>';
		} );

		sn_admin_render_section( 'open-graph', function() {
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
		} );

		sn_admin_render_section( 'seo-copy', function() {
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
		} );

		// Sticky save bar — saves Identity / Social / OG / SEO Copy (the 4 above).
		// Cloudflare's save is separate (its own form on its own sub-tab now).
		echo '<div class="sn-savebar">';
		echo '<p class="sn-savebar-hint">Changes apply immediately on Save. Live site re-renders on next request.</p>';
		echo '<button type="submit" class="button button-primary">Save Identity Settings</button>';
		echo '</div>';
		echo '</form>';
	}  // close: else (identity-and-seo sub-tab)

	// ════════════════════════════════════════
	// TAB: SECURITY (v3.8.1+: sub-tabs)
	// Sub-tabs: login (audit-log added in future v3.8.x). Sub-tab nav hidden when count < 2.
	// ════════════════════════════════════════
	} elseif ( 'security' === $active_tab ) {

		sn_admin_render_sub_tabs( 'security', $active_sub );

		// v3.8.3+: 2 sub-tabs (Login URL + Audit log) — sub-tab nav now visible.
		if ( 'audit-log' === $active_sub ) {
			sn_admin_render_section( 'audit-log', 'snt_audit_log_render_tab' );
		} elseif ( 'login' === $active_sub || '' === $active_sub ) {
		sn_admin_render_section( 'login', function() {
			// Detect module state. Three possibilities:
			//   1. ACTIVE: our login-hide.php is firing (no wps-hide-login,
			//      no SN_LOGIN_BYPASS)
			//   2. DORMANT (conflict): wps-hide-login is still active so
			//      our module stood down
			//   3. DORMANT (bypass): SN_LOGIN_BYPASS constant is set
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$wps_basename = 'wps-hide-login/wps-hide-login.php';
			$wps_active   = is_plugin_active( $wps_basename ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
			$bypassed     = defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS;
			$slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : sn_setting( 'login.slug', 'sn-login' );
			$slug_const   = defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG;
			$login_url    = home_url( '/' . $slug );

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

			echo '<form method="post">';
			wp_nonce_field( 'sn_theme_options_nonce' );
			echo '<input type="hidden" name="sn_action" value="save_login">';

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

			echo '<div class="sn-field">';
			echo '<label class="sn-field-label">Current login URL</label>';
			echo '<a class="sn-url-preview" href="' . esc_url( $login_url ) . '" target="_blank" rel="noopener">' . esc_html( $login_url ) . '</a>';
			echo '<p class="sn-field-helper">Bookmark this URL. The default <code>/wp-login.php</code> 404s for unauthenticated visitors.</p>';
			echo '</div>';

			echo '<div class="sn-fieldset-actions">';
			if ( $slug_const ) {
				echo '<p class="sn-fieldset-actions-hint">Slug locked by <code>SN_LOGIN_SLUG</code> constant.</p>';
			}
			echo '<button type="submit" class="button button-primary"' . ( $slug_const ? ' disabled' : '' ) . '>Save</button>';
			echo '</div>';

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
		} );
		}  // close: elseif login (default)

	// ════════════════════════════════════════
	// TAB: AUTOMATION (v3.8.1+: sub-tabs)
	// Sub-tabs: webhooks, cron
	// ════════════════════════════════════════
	} elseif ( 'automation' === $active_tab ) {

		sn_admin_render_sub_tabs( 'automation', $active_sub );

		if ( 'cron' === $active_sub ) {
			sn_admin_render_section( 'cron', function() {
				do_action( 'sn_admin_cron_tab' );
			} );
		} else {
			// Default sub-tab: 'webhooks'
			sn_admin_render_section( 'webhooks', function() {
				do_action( 'sn_admin_webhooks_tab' );
			} );
		}

	// ════════════════════════════════════════
	// TAB: MONITORING (v3.8.1+: sub-tabs)
	// Sub-tabs: insights, health, plausible, rss
	// ════════════════════════════════════════
	} elseif ( 'monitoring' === $active_tab ) {

		sn_admin_render_sub_tabs( 'monitoring', $active_sub );

		if ( 'health' === $active_sub ) {
			sn_admin_render_section( 'health', function() {
				do_action( 'sn_admin_health_tab' );
			} );
		} elseif ( 'plausible' === $active_sub ) {
			sn_admin_render_section( 'plausible', function() {
				do_action( 'sn_admin_plausible_tab' );
			} );
		} elseif ( 'rss' === $active_sub ) {
			sn_admin_render_section( 'rss', function() {
				if ( has_action( 'sn_admin_rss_tab' ) ) {
					do_action( 'sn_admin_rss_tab' );
				} else {
					echo '<div class="notice notice-warning inline sn-rss-not-installed"><p><strong>RSS subscriber tracker not installed.</strong></p>';
					echo '<p>Copy <code>mu-plugins/rss-plausible-tracker.php</code> from the theme repo to <code>wp-content/mu-plugins/</code> on this host. MU plugins activate automatically — no further action needed.</p></div>';
				}
			} );
		} else {
			// Default sub-tab: 'insights'
			sn_admin_render_section( 'insights', function() {
				do_action( 'sn_admin_insights_tab' );
			} );
		}

	// ════════════════════════════════════════
	// TAB: TOOLS (v3.8.1+: sub-tabs)
	// Sub-tabs: reading-time, links, block-migrations
	// ════════════════════════════════════════
	} elseif ( 'tools' === $active_tab ) {

		sn_admin_render_sub_tabs( 'tools', $active_sub );

		if ( 'links' === $active_sub ) {
			sn_admin_render_section( 'links', function() {
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
		} );
		} elseif ( 'block-migrations' === $active_sub ) {
			sn_admin_render_section( 'block-migrations', function() {
				do_action( 'sn_admin_block_migrations_tab' );
			} );
		} else {
			// Default sub-tab: 'reading-time'
			sn_admin_render_section( 'reading-time', function() {
				do_action( 'sn_admin_reading_time_tab' );
			} );
		}

	}

	echo '</div>'; // wrap
}
