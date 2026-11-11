<?php
/**
 * Signal & Noise Tools: rss-feed-tracker.php, the window stats, the recent
 * reads, the settings form handler and its hook, the flash, the glance cards
 * and the local time.
 *
 * Split from inc/rss-feed-tracker.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-window aggregation in one query. UTC throughout — rows are
 * inserted with current_time('mysql', true), so the comparison side must
 * also be UTC. NOW() on Cloudways isn't guaranteed UTC and would
 * silently slide the windows.
 *
 * Days values are clamped + cast to int and interpolated rather than
 * prepared because MySQL INTERVAL doesn't accept a placeholder in a way
 * that composes cleanly with conditional aggregation. The clamp makes
 * the interpolation safe.
 *
 * @param int[] $days_list e.g. array(1, 7, 30)
 * @return array{most_recent: ?string, windows: array<int, array{total:int, uniques:int}>}
 */
function sn_rss_tracker_window_stats_multi( array $days_list ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_RSS_TRACKER_TABLE;

	$safe = array();
	foreach ( $days_list as $d ) {
		$safe[] = max( 1, min( 365, (int) $d ) );
	}
	$safe = array_values( array_unique( $safe ) );
	if ( empty( $safe ) ) {
		return array( 'most_recent' => null, 'windows' => array() );
	}
	$max_d   = max( $safe );
	$selects = array( 'MAX(ts) AS most_recent' );
	foreach ( $safe as $d ) {
		$selects[] = "SUM(ts >= UTC_TIMESTAMP() - INTERVAL {$d} DAY) AS total_{$d}";
		$selects[] = "COUNT(DISTINCT CASE WHEN ts >= UTC_TIMESTAMP() - INTERVAL {$d} DAY THEN ua_hash END) AS uniq_{$d}";
	}

	// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $selects is composed solely of hardcoded aggregate expressions with (int)-cast, 1..365-clamped day intervals; $table is $wpdb->prefix + a plugin constant.
	$row = $wpdb->get_row(
		"SELECT " . implode( ', ', $selects )
		. " FROM {$table}"
		. " WHERE ts >= UTC_TIMESTAMP() - INTERVAL {$max_d} DAY",
		ARRAY_A
	);

	$out = array(
		'most_recent' => $row['most_recent'] ?? null,
		'windows'     => array(),
	);
	foreach ( $safe as $d ) {
		$out['windows'][ $d ] = array(
			'total'   => (int) ( $row[ "total_{$d}" ] ?? 0 ),
			'uniques' => (int) ( $row[ "uniq_{$d}" ] ?? 0 ),
		);
	}
	return $out;
}

function sn_rss_tracker_recent( $limit = 20 ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_RSS_TRACKER_TABLE;
	$limit = max( 1, min( 100, (int) $limit ) );
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT ts, ua_hash, feed_url FROM {$table} ORDER BY id DESC LIMIT %d",
		$limit
	), ARRAY_A );
}

/**
 * RSS dashboard widget removed in v1.13.0. The RSS subscriber count
 * surface now lives exclusively on the SN admin → RSS tab. Reason:
 * dashboard widgets clutter the WP dashboard surface where SN-specific
 * info competes with other plugins' widgets. The SN settings pages
 * are the canonical home for operational info.
 * (See memory: feedback_no_dashboard_widgets.md)
 */

/**
 * One RSS write, run by its own admin-post.php hook (registered below):
 * Save settings, Purge now or Reset to Defaults. Core's check_admin_referer()
 * first, against a nonce minted for this action alone, then manage_options,
 * the shape of every other SN admin write since #1614. A stale form gets
 * core's "link expired" page; until 2026-11-10 it returned silently from
 * admin_init, so the click did nothing and said nothing.
 *
 * Off the sn_admin_post_handlers() table on purpose: that dispatcher
 * redirects with ?sn_flash, and this leaf reads its own ?sn_rss_ok on both
 * surfaces (the OpenStation window replays the same hook through the host's
 * admin-post pipeline).
 *
 * @param string $action One of the SN_RSS_TRACKER_ACTION_* values.
 * @return void Redirects and exits.
 */
function sn_rss_tracker_handle_form( $action ) {
	$action = (string) $action;
	// A form submit only: admin-post.php dispatches on $_REQUEST, so a GET link
	// carrying a valid nonce would otherwise purge or reset. The OpenStation
	// window replays with its form values in $_POST, so it still passes.
	if ( empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- refusing before the nonce is read.
		wp_die( esc_html__( 'This action needs a form submit.', 'signal-and-noise-tools' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'sn_rss_' . $action );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}

	$flash = '';

	if ( SN_RSS_TRACKER_ACTION_SAVE === $action ) {
		global $wpdb;
		$defaults = sn_rss_tracker_defaults();
		$current  = sn_rss_tracker_settings();
		$new      = array(
			'enabled'            => ! empty( $_POST['enabled'] ),
			// v10.46.0: collector_url is NO LONGER POSTED BY THIS FORM — the field
			// moved to Measurement → Analytics, where the collector is what the
			// screen is about. The fallback had to change from $defaults to
			// $current at the same time: this branch rebuilds the WHOLE settings
			// array from $_POST, so a defaults fallback would silently reset a
			// customised collector back to home_url('/_sn/px') on EVERY RSS save.
			// Falling back to the stored value keeps the two write surfaces
			// disjoint. Pinned by tests/rss-collector-move.php.
			'collector_url'      => esc_url_raw( wp_unslash( $_POST['collector_url'] ?? ( $current['collector_url'] ?? $defaults['collector_url'] ) ) ),
			'event_name'         => sanitize_text_field( wp_unslash( $_POST['event_name'] ?? $defaults['event_name'] ) ),
			'log_retention_days' => max( 7, min( 365, (int) wp_unslash( $_POST['log_retention_days'] ?? $defaults['log_retention_days'] ) ) ),
		);
		$ok = update_option( SN_RSS_TRACKER_SETTINGS_OPT, $new );
		// update_option returns false on both real-failure and value-
		// unchanged. Distinguish: if false AND wpdb has a non-empty
		// last_error, it's a real failure. Otherwise it's a no-op and
		// the user's "change" was identical to what was already stored.
		if ( false === $ok && ! empty( $wpdb->last_error ) ) {
			error_log( 'sn_rss_tracker: settings save failed: ' . $wpdb->last_error );
			$flash = 'save-error';
		} elseif ( false === $ok ) {
			$flash = 'unchanged';
		} else {
			$flash = 'saved';
		}
	} elseif ( SN_RSS_TRACKER_ACTION_PURGE === $action ) {
		global $wpdb;
		$days    = max( 7, min( 365, (int) wp_unslash( $_POST['purge_days'] ?? 90 ) ) );
		$result  = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . SN_RSS_TRACKER_TABLE . "
			   WHERE ts < ( UTC_TIMESTAMP() - INTERVAL %d DAY )",
			$days
		) );
		// (int)false === 0, so without this branch a SQL error would
		// render "Purged 0 entries" inside notice-success — fake
		// success on a real failure. Distinguish explicitly.
		if ( false === $result ) {
			error_log( 'sn_rss_tracker: manual purge failed: ' . $wpdb->last_error );
			$flash = 'purge-error';
		} else {
			$flash = 'purged-' . (int) $result;
		}
	} elseif ( SN_RSS_TRACKER_ACTION_RESET === $action ) {
		sn_rss_tracker_reset_settings();
		$flash = 'reset';
	}

	// 2026-11-10: straight to Monitoring -> RSS. The legacy ?tab=rss no longer
	// redirects there on the live site (it rendered the Dashboard and dropped
	// the flash), so the canonical tab/sub is named here.
	// #1228: the SN page moved from Appearance (themes.php, add_theme_page)
	// to its own top-level menu (admin.php, add_menu_page) — see
	// inc/admin-menu.php's docblock. themes.php ignores an unrecognized
	// ?page= and just renders the Themes screen, so this redirect silently
	// stranded the owner there instead of back on Connections -> RSS.
	wp_safe_redirect( add_query_arg(
		array(
			'page'      => 'sn-theme-options',
			'tab'       => 'monitoring',
			'sub'       => 'rss',
			'sn_rss_ok' => $flash,
		),
		admin_url( 'admin.php' )
	) );
	exit;
}

// At load, not behind is_admin(): an OpenStation window replays a form inside
// a REST request and asks has_action() before it runs the hook.
foreach ( array( SN_RSS_TRACKER_ACTION_SAVE, SN_RSS_TRACKER_ACTION_PURGE, SN_RSS_TRACKER_ACTION_RESET ) as $sn_rss_action ) {
	add_action(
		'admin_post_sn_rss_' . $sn_rss_action,
		static function () use ( $sn_rss_action ) {
			sn_rss_tracker_handle_form( $sn_rss_action );
		}
	);
}
unset( $sn_rss_action );

function sn_rss_tracker_render_flash( $flash ) {
	if ( 'saved' === $flash ) {
		wp_admin_notice( 'Settings saved.', array( 'type' => 'success', 'dismissible' => true ) );
	} elseif ( 'unchanged' === $flash ) {
		wp_admin_notice( 'Settings unchanged: submitted values matched what was already stored.', array( 'type' => 'info', 'dismissible' => true ) );
	} elseif ( 'save-error' === $flash ) {
		wp_admin_notice( 'Settings could not be saved. Check the PHP error log for the database error.', array( 'type' => 'error', 'dismissible' => true ) );
	} elseif ( 'reset' === $flash ) {
		wp_admin_notice( 'Settings reset to defaults.', array( 'type' => 'success', 'dismissible' => true ) );
	} elseif ( 'purge-error' === $flash ) {
		wp_admin_notice( 'Purge failed: no rows were deleted. Check the PHP error log for the database error.', array( 'type' => 'error', 'dismissible' => true ) );
	} elseif ( 0 === strpos( $flash, 'purged-' ) ) {
		$n = (int) substr( $flash, 7 );
		wp_admin_notice( 'Purged ' . esc_html( number_format_i18n( $n ) ) . ' log entries.', array( 'type' => 'success', 'dismissible' => true ) );
	}
}

/**
 * First-glance activity cards for sn_admin_glance_grid(). Pure — takes the
 * window stats, returns the card array (total → value, uniques → meta). v6.47.0:
 * converged off the bespoke .sn-rss-activity-card vocabulary onto the shared
 * token-driven glance grid, matching Cron / Health / Tags / Audit-log.
 *
 * @param array $stats sn_rss_tracker_window_stats_multi() result.
 * @return array<int,array<string,mixed>>
 */
function snt_rss_glance_cards( $stats ) {
	$cards = array();
	foreach ( array( 1 => '24 hours', 7 => '7 days', 30 => '30 days' ) as $days => $label ) {
		$w       = $stats['windows'][ $days ] ?? array( 'total' => 0, 'uniques' => 0 );
		$cards[] = array(
			'label'     => $label,
			'value'     => number_format_i18n( $w['total'] ),
			'meta_html' => esc_html( number_format_i18n( $w['uniques'] ) . ' unique' ),
		);
	}
	return $cards;
}

/**
 * A stored UTC stamp in the site's timezone and its own date and time format,
 * the same on both surfaces (2026-10-12 audit: this page printed UTC beside a
 * native twin that printed local time); the input unchanged when unreadable.
 *
 * @param string $utc A MySQL UTC stamp (the tracker stores current_time( 'mysql', true )).
 * @return string
 */
function sn_rss_tracker_local_time( $utc ) {
	$ts = strtotime( trim( (string) $utc ) . ' UTC' );
	if ( false === $ts || $ts <= 0 ) {
		return (string) $utc;
	}
	return (string) wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $ts );
}

