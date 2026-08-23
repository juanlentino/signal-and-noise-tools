<?php
/**
 * Signal & Noise — admin POST handlers: analytics collector, tuning, funnels, exclusions and export.
 *
 * Split out of inc/admin-post-actions.php in v12.22.0, which had grown to
 * 1,682 lines (see docs/REFACTOR-admin-post-actions.md). Nothing about the
 * contract changed: each handler is still fn( array $post ): string returning
 * a ?sn_flash=… code, and sn_admin_post_handlers() in inc/admin-post-handler.php
 * still reaches it BY NAME, which is why the move is invisible to dispatch.
 *
 * Actions served: analytics_collector_save, analytics_save,
 * machine_readers_save, analytics_test, analytics_exclude_save,
 * analytics_tuning_save, analytics_funnels_save, analytics_export
 *
 * @package SignalNoiseTools
 * @since 12.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v10.46.0: save the three AI settings, split out of sn_handle_save_theme()
 * when the AI tab was created.
 *
 * WHY SPLITTING THIS FORM IS SAFE. Splitting one settings form into two is the
 * classic subtree-clobber bug in this codebase: a handler that writes a whole
 * settings subtree at once blanks every sibling key the smaller form no longer
 * posts. That is not the shape here — both handlers write through PER-KEY
 * sn_setting_update() calls, so each touches only the keys it names and neither
 * can erase the other's. Checked against sn_handle_save_theme() before the
 * split; if that handler is ever converted to a subtree write, this pairing has
 * to be revisited.
 *
 * Validation, not sanitization, on the two model ids: an off-list id keeps the
 * currently stored value (then the first allow-listed id), so a tampered POST
 * can never park an unknown model id in settings. Carried over verbatim from
 * the v7.3.0 / v9.26.0 originals.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
/**
 * v10.46.0: save the analytics collector endpoint, moved to Measurement →
 * Analytics from Content → RSS.
 *
 * MERGE, NEVER REPLACE. The value lives inside the RSS tracker's settings option
 * (SN_RSS_TRACKER_SETTINGS_OPT) alongside enabled / event_name /
 * log_retention_days. That option's own save branch in inc/rss-feed-tracker.php
 * rebuilds the whole array from $_POST, which is fine for the form that posts
 * every key — but this handler must NOT do that, or saving the collector would
 * blank the other three. It reads the current settings, replaces one key, and
 * writes back.
 *
 * The key stays in the RSS option rather than moving to `analytics.*`: relocating
 * it needs a settings migration, and inc/worker-version.php reads it from there
 * to derive the /_sn/version probe base. Where a value is EDITED and where it is
 * STORED are separate questions; only the first one moved.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code.
 */
function sn_handle_analytics_collector_save( $post ) {
	if ( ! function_exists( 'sn_rss_tracker_settings' ) ) {
		return 'analytics_collector_failed';
	}
	$url = isset( $post['sn_an_collector_url'] ) ? esc_url_raw( wp_unslash( $post['sn_an_collector_url'] ) ) : '';
	if ( '' === $url ) {
		return 'analytics_collector_invalid';
	}
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
		return 'analytics_collector_invalid';
	}

	$current = (array) sn_rss_tracker_settings();
	if ( ( $current['collector_url'] ?? '' ) === $url ) {
		return 'analytics_collector_unchanged';
	}
	$current['collector_url'] = $url;
	update_option( SN_RSS_TRACKER_SETTINGS_OPT, $current );

	return 'analytics_collector_saved';
}

/**
 * S2 (P2 analytics data layer): save the Cloudflare Analytics Engine credentials
 * from the Analytics settings form.
 *
 * Two fields:
 *   sn_cf_account_id       — plain identifier (not a secret), change-detected.
 *   sn_cf_analytics_token  — secret token; masked field; a '••••…' placeholder
 *                             means "no edit" and is silently skipped so the stored
 *                             value is never clobbered by the placeholder text.
 *
 * Both are constant-lockable: when SN_CF_ANALYTICS_TOKEN AND SN_CF_ACCOUNT_ID are
 * both defined and non-empty in wp-config.php, admin edits are rejected entirely.
 * When only one is locked, that field is skipped and the other may still be saved.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_saved' | 'analytics_unchanged' | 'analytics_locked'.
 */
function sn_handle_analytics_save( $post ) {
	$token_locked = defined( 'SN_CF_ANALYTICS_TOKEN' ) && '' !== (string) SN_CF_ANALYTICS_TOKEN;
	$acct_locked  = defined( 'SN_CF_ACCOUNT_ID' ) && '' !== (string) SN_CF_ACCOUNT_ID;
	if ( $token_locked && $acct_locked ) {
		return 'analytics_locked';
	}

	$changed = false;

	// Account ID — identifier, not a secret: plain text, change-detected.
	if ( ! $acct_locked && isset( $post['sn_cf_account_id'] ) ) {
		$acct = sanitize_text_field( wp_unslash( $post['sn_cf_account_id'] ) );
		if ( 'clear' === $acct ) {
			if ( '' !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
				delete_option( SN_CF_ACCOUNT_ID_OPT );
				$changed = true;
			}
		} elseif ( '' !== $acct && $acct !== (string) get_option( SN_CF_ACCOUNT_ID_OPT, '' ) ) {
			update_option( SN_CF_ACCOUNT_ID_OPT, $acct, false );
			$changed = true;
		}
	}

	// Token — secret: masked field, ignore an un-edited '••••…' placeholder.
	if ( ! $token_locked ) {
		$new_token = isset( $post['sn_cf_analytics_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_cf_analytics_token'] ) ) : '';
		if ( 'clear' === $new_token ) {
			if ( '' !== (string) get_option( SN_CF_ANALYTICS_TOKEN_OPT, '' ) ) {
				delete_option( SN_CF_ANALYTICS_TOKEN_OPT );
				$changed = true;
			}
		} elseif ( '' !== $new_token && 0 !== strpos( $new_token, '••••' ) ) {
			update_option( SN_CF_ANALYTICS_TOKEN_OPT, $new_token, false );
			$changed = true;
		}
	}

	return $changed ? 'analytics_saved' : 'analytics_unchanged';
}

/**
 * v9.85.0 (Session 3): save the Machine Readers sensor settings (worker URL
 * override + write-only read token) under the machine_readers subtree. The
 * pure, subtree-preserving merge lives in snt_mr_settings_save()
 * (inc/machine-readers-admin.php); this wrapper owns unslash/sanitize,
 * persistence, and the sn_setting cache bust, and drops the tab's display
 * transient so new credentials take effect on the next page load.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'machine_readers_saved'.
 */
function sn_handle_machine_readers_save( $post ) {
	$fields = array(
		'worker_url' => isset( $post['sn_mr_worker_url'] ) ? sanitize_text_field( wp_unslash( $post['sn_mr_worker_url'] ) ) : '',
		'read_token' => isset( $post['sn_mr_read_token'] ) ? sanitize_text_field( wp_unslash( $post['sn_mr_read_token'] ) ) : '',
	);
	$stored = get_option( SN_SETTINGS_OPTION, array() );
	update_option( SN_SETTINGS_OPTION, snt_mr_settings_save( $fields, is_array( $stored ) ? $stored : array() ) );
	sn_setting_reset_cache();
	// The tab's display window; other windows age out on their own short TTL.
	delete_transient( 'sn_mr_rows_30' );
	return 'machine_readers_saved';
}

/**
 * S2 (P2 analytics data layer): test the Cloudflare Analytics Engine credentials
 * via a lightweight probe query (admin "Test connection" button).
 *
 * Dispatches through the sn_analytics_config() / sn_analytics_probe() seam so
 * both functions are replaceable in unit tests without network access.
 *
 * @param array $post Raw $_POST (unused; kept for dispatcher contract).
 * @return string Flash code: 'analytics_test_unconfigured' | 'analytics_test_ok' | 'analytics_test_err'.
 */
function sn_handle_analytics_test( $post ) {
	if ( ! sn_analytics_config() ) {
		return 'analytics_test_unconfigured';
	}
	delete_transient( SN_ANALYTICS_ERR_KEY ); // force-fresh: show THIS test's result, not a stale failure
	return sn_analytics_probe() ? 'analytics_test_ok' : 'analytics_test_err';
}

/**
 * v6.23.0: save the "Exclude my own visits" role allow-list (Monitoring →
 * Analytics). Sanitizes the submitted role slugs against the real role list
 * (sn_beacon_sanitize_exclude_roles) and persists them to the analytics subtree.
 * The theme's sn_beacon_enabled filter (inc/beacon-owner-exclusion.php) reads
 * this to suppress the front-end beacon for logged-in users in those roles.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_exclude_saved' | 'analytics_exclude_unchanged'.
 */
function sn_handle_analytics_exclude_save( $post ) {
	$raw = isset( $post['sn_exclude_roles'] ) ? wp_unslash( $post['sn_exclude_roles'] ) : array();
	$new = sn_beacon_sanitize_exclude_roles( $raw );
	sort( $new );

	$prior = (array) sn_setting( 'analytics.exclude_roles', array() );
	sort( $prior );

	if ( $new === $prior ) {
		return 'analytics_exclude_unchanged';
	}
	return sn_setting_update( 'analytics.exclude_roles', $new ) ? 'analytics_exclude_saved' : 'analytics_exclude_unchanged';
}

/**
 * v9.36.0 (settings hub): save the two predictive-engine tuning knobs
 * (Measurement → Analytics → Engine tuning). Baseline is clamped to [14,90]
 * (floor = the engine's SN_ANALYTICS_SIGNAL_FLOOR_DAYS); the sensitivity
 * preset is whitelisted (unknown → 'standard'). Invalid input is corrected,
 * never rejected-with-loss. sn_analytics_signal_opts() reads these on the
 * next dashboard load — no cache to bust.
 *
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_tuning_saved' | 'analytics_tuning_unchanged'.
 */
function sn_handle_analytics_tuning_save( $post ) {
	$baseline = isset( $post['sn_signal_baseline_days'] ) ? (int) $post['sn_signal_baseline_days'] : 30;
	$baseline = max( 14, min( 90, $baseline ) );

	$preset = isset( $post['sn_anomaly_sensitivity'] ) ? sanitize_key( wp_unslash( $post['sn_anomaly_sensitivity'] ) ) : 'standard';
	if ( ! in_array( $preset, array( 'relaxed', 'standard', 'strict' ), true ) ) {
		$preset = 'standard';
	}

	$prior_baseline = (int) sn_setting( 'analytics.signal_baseline_days', 30 );
	$prior_preset   = (string) sn_setting( 'analytics.anomaly_sensitivity', 'standard' );
	if ( $baseline === $prior_baseline && $preset === $prior_preset ) {
		return 'analytics_tuning_unchanged';
	}

	$ok = sn_setting_update( 'analytics.signal_baseline_days', $baseline );
	$ok = sn_setting_update( 'analytics.anomaly_sensitivity', $preset ) && $ok;
	return $ok ? 'analytics_tuning_saved' : 'analytics_tuning_unchanged';
}

/**
 * S2 §3 (v9.42.0 arc): save the owner-defined session funnels (Monitoring →
 * Analytics → Session funnels). No inline nonce check — sn_handle_admin_post()
 * (inc/admin-post-handler.php) already runs check_admin_referer() for every
 * action on this dispatcher before any handler is called, same as every other
 * handler in this file.
 *
 * Atomic: a parse error saves NOTHING (the prior analytics.funnels setting is
 * left exactly as it was) and returns an
 * 'analytics_funnels_invalid[_<line>k<kindIndex>[-<line>k<kindIndex>…]]' flash
 * — reason-surfacing task: $kindIndex is now packed in alongside each bad
 * line, mirroring the existing count/id-prefixed flash-code idiom (cleared_12,
 * wh_added_<id>, …) resolved in inc/admin-flash-messages.php. STILL no extra
 * transient plumbing (that was deliberately declined — see
 * sn_analytics_funnels_error_flash_code() below): the parser's structured
 * errors_detail already names both the line AND the machine-stable kind.
 *
 * STRING-SETTING RULE: WP core slashes all of $_POST (wp_magic_quotes()), so
 * the raw textarea payload is wp_unslash()ed BEFORE it reaches the parser —
 * apostrophes in funnel names are the exact recurring hazard (see
 * tests/settings-save-unslash.php / the v9.36.1 fix in sn_settings_save()).
 *
 * @since S2 (v9.42.0 arc); pair-encoded flash code (reason-surfacing task).
 * @param array $post Raw $_POST.
 * @return string Flash code: 'analytics_funnels_saved' | 'analytics_funnels_invalid[_<line>k<kindIndex>[-…]]' | 'analytics_funnels_failed'.
 */
function sn_handle_analytics_funnels_save( $post ) {
	// is_string guard: a crafted sn_funnels[]= array would warn on the string
	// cast (final review); non-string payloads parse as empty → error flash.
	$raw    = isset( $post['sn_funnels'] ) && is_string( $post['sn_funnels'] ) ? wp_unslash( $post['sn_funnels'] ) : '';
	$parsed = sn_analytics_parse_funnels( (string) $raw );

	if ( ! empty( $parsed['errors'] ) ) {
		return sn_analytics_funnels_error_flash_code( $parsed['errors_detail'] );
	}

	$ok = sn_setting_update( 'analytics.funnels', $parsed['funnels'] );
	return $ok ? 'analytics_funnels_saved' : 'analytics_funnels_failed';
}

/**
 * Encode the parser's structured error detail (reason-surfacing task) into
 * the 'analytics_funnels_invalid[_<line>k<kindIndex>[-<line>k<kindIndex>…]]'
 * flash code inc/admin-flash-messages.php decodes back into per-line reason
 * text.
 *
 * $kindIndex is the entry's position in SN_ANALYTICS_FUNNELS_ERR_KINDS
 * (inc/analytics-sessions.php) — NEVER the reason string itself and NEVER
 * anything derived from the owner's textarea content — so nothing beyond
 * digits (plus the fixed 'k'/'-' separators) can ever reach the redirect URL.
 * A detail entry with an out-of-enum kind or a non-positive line (never
 * produced by the real parser — the enum is closed and lines are always
 * >= 1 — but defensive against any other caller) is silently skipped rather
 * than encoded as-is.
 *
 * SOURCE cap (final review, carried over unchanged from the pre-reason-
 * surfacing code): first FIVE bad lines only — an uncapped code from a huge
 * paste can blow the redirect URL past server limits (414).
 *
 * Worst-case length: 5 pairs of "<line up to 4 digits>k<kind 1 digit>"
 * ("9999k5", 6 chars) joined by 4 '-' separators = 5*6 + 4 = 34 chars —
 * comfortably inside the 40-char cap inc/admin-flash-messages.php enforces on
 * decode (unchanged from the pre-reason-surfacing display-truncation constant).
 *
 * @since (reason-surfacing task)
 * @param array $errors_detail List of array{line:int,kind:string,message:string}.
 * @return string
 */
function sn_analytics_funnels_error_flash_code( array $errors_detail ) {
	$kinds = defined( 'SN_ANALYTICS_FUNNELS_ERR_KINDS' ) ? SN_ANALYTICS_FUNNELS_ERR_KINDS : array();
	$pairs = array();
	foreach ( array_slice( $errors_detail, 0, 5 ) as $error ) {
		$line       = isset( $error['line'] ) ? (int) $error['line'] : 0;
		$kind_index = array_search( (string) ( $error['kind'] ?? '' ), $kinds, true );
		if ( $line < 1 || false === $kind_index ) {
			continue; // never emit a malformed pair — the enum is closed, this should not happen.
		}
		$pairs[] = $line . 'k' . $kind_index;
	}
	return $pairs ? ( 'analytics_funnels_invalid_' . implode( '-', $pairs ) ) : 'analytics_funnels_invalid';
}

/**
 * v6.1.0: stream a CSV or JSON download of the current analytics range/class.
 *
 * This handler intentionally does NOT return a flash code — it streams a file
 * download and calls exit(), so the dispatcher's PRG redirect never runs.
 *
 * Load-order note: inc/analytics-read.php (sn_analytics_top_paths) and
 * inc/analytics-admin.php (snt_analytics_resolve_range / snt_analytics_resolve_class /
 * snt_analytics_range_dates) are both loaded unconditionally via require_once in
 * signal-and-noise-tools.php before any WordPress hook fires, so they are always
 * available at admin_init. inc/analytics-export.php (the formatters) is a new
 * file not yet in the bootstrap — require_once it here on first use.
 *
 * @param array $post Raw $_POST.
 * @return void (exits after streaming the download)
 */
function sn_handle_analytics_export( $post ) {
	if ( ! function_exists( 'sn_analytics_export_csv' ) ) {
		require_once __DIR__ . '/analytics-export.php';
	}

	$range_raw = isset( $post['sn_range'] ) ? sanitize_text_field( wp_unslash( $post['sn_range'] ) ) : '30';
	$from_raw  = isset( $post['sn_from'] ) ? sanitize_text_field( wp_unslash( $post['sn_from'] ) ) : '';
	$to_raw    = isset( $post['sn_to'] ) ? sanitize_text_field( wp_unslash( $post['sn_to'] ) ) : '';
	$class     = isset( $post['sn_class'] ) ? snt_analytics_resolve_class( sanitize_text_field( wp_unslash( $post['sn_class'] ) ) ) : 'human';
	$fmt       = ( isset( $post['format'] ) && 'json' === $post['format'] ) ? 'json' : 'csv';
	list( $range, $from, $to ) = snt_analytics_resolve_window( $range_raw, $from_raw, $to_raw );

	$rows  = sn_analytics_top_paths( $from, $to, $class, 500 );
	$fname = 'sn-analytics-' . $from . '_' . $to . '-' . $class . '.' . $fmt;

	if ( 'json' === $fmt ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_json( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	} else {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
		echo sn_analytics_export_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- file download, not HTML
	}
	exit;
}
