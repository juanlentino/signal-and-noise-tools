<?php
/**
 * Signal & Noise Tools — WP-native update integration.
 *
 * Hooks into WordPress's standard update system so this plugin appears
 * in wp-admin/update-core.php and wp-admin/plugins.php alongside other
 * plugins. Polls the GitHub Tags API every 12h (cached in a site
 * transient) to compare local version against the latest tagged release.
 *
 * When a new tag is available, WP UI shows "Update Available" — the
 * maintainer clicks Update Now and WP downloads + installs from
 * GitHub's auto-generated tag archive (`/archive/refs/tags/<tag>.zip`).
 *
 * The unpacked archive's top-level directory is `signal-and-noise-tools-<version>/`
 * (GitHub strips the leading 'v' from the tag in the dir name); the
 * `upgrader_source_selection` filter renames it to `signal-and-noise-tools/`
 * so WP installs to the slug that matches SN_GH_PLUGIN_BASENAME.
 *
 * Added in v1.4.0 (2026-05-16). Rewritten in v1.10.1 (2026-05-16):
 * the original implementation intercepted "Update Now" with a
 * WP_Error because the legacy auto-deploy-on-tag-push pipeline would
 * .git-checkout the new tag and overwrite the WP installer's work.
 * v1.10.1 moves to WP-UI-driven updates and disables the tag-push
 * auto-deploy (see .github/workflows/deploy.yml). v1.11.1 fixed the
 * 12h cache that was hiding new tags from the WP updater. v1.11.2
 * adds inc/wp-update-git-preservation.php which backs up + restores
 * the .git directory through the install — closing the footgun where
 * WP's clear_destination() would otherwise destroy the .git checkout
 * that the canonical workflow_dispatch deploy depends on. Both install
 * paths (gh workflow run AND wp-admin Update Now) now coexist safely.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_GH_PLUGIN_OWNER          = 'juanlentino';
const SN_GH_PLUGIN_REPO           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_CACHE_KEY      = 'sn_gh_latest_plugin';
const SN_GH_PLUGIN_CACHE_TTL      = HOUR_IN_SECONDS; // v1.11.1: 12h → 1h
const SN_GH_PLUGIN_BASENAME       = 'signal-and-noise-tools/signal-and-noise-tools.php';
const SN_GH_PLUGIN_SLUG           = 'signal-and-noise-tools';
const SN_GH_PLUGIN_LAST_SEEN_OPT  = 'sn_last_seen_plugin_version';
/**
 * Why the last tag fetch failed, in prose, for the Dashboard card. Lives beside
 * the negative cache and shares its lifetime. See sn_gh_record_fetch_failure().
 *
 * @since 9.54.0
 */
const SN_GH_PLUGIN_ERROR_KEY      = 'sn_gh_latest_plugin_error';
/**
 * Failure-cache TTLs, split by whether re-asking could get a different answer.
 * Transient mirrors github-actions-api.php's SNT_GH_RUNS_FAIL_TTL — the value
 * that rode out the 2026-07-16 GitHub incident without ever going dark.
 *
 * @since 9.54.1
 */
const SN_GH_FAIL_TTL_TRANSIENT    = 5 * MINUTE_IN_SECONDS;
const SN_GH_FAIL_TTL_DURABLE      = HOUR_IN_SECONDS;

/**
 * Whether this plugin is installed somewhere other than SN_GH_PLUGIN_BASENAME says.
 *
 * WHY THIS EXISTS (v12.25.0, 2026-08-24)
 *
 * SN_GH_PLUGIN_BASENAME is hardcoded, and everything in this file keys off it:
 * the entry we write into WP's `update_plugins` transient, and the gates on
 * upgrader_source_selection / upgrader_pre_install / upgrader_post_install.
 * There are therefore two identities that must agree — the directory WordPress
 * actually loaded us from, and the basename we CLAIM — and WP core never
 * reconciles them.
 *
 * When they diverge the failure is silent by construction: we announce an
 * update for a plugin WordPress does not have installed, so no update row
 * renders anywhere, and clearing caches cannot help because the transient is
 * rebuilt with the same wrong key. The only route to new code becomes
 * delete-and-reinstall — which is precisely the path that CAUSES the
 * divergence, since GitHub's tag archive unpacks to
 * `signal-and-noise-tools-<version>/` and the rename filter below gates on
 * `$hook_extra['plugin']`, which is unset for a manual Upload Plugin.
 *
 * So: assert it, and say so out loud. Returns '' when correct, otherwise the
 * basename WordPress actually loaded — the wrong value is the useful one.
 *
 * @since 12.25.0
 * @return string '' when correct, else the actual basename.
 */
function sn_plugin_basename_mismatch() {
	if ( ! defined( 'SNT_PATH' ) || ! function_exists( 'plugin_basename' ) ) {
		return '';
	}

	$actual = (string) plugin_basename( SNT_PATH . 'signal-and-noise-tools.php' );
	if ( '' === $actual || SN_GH_PLUGIN_BASENAME === $actual ) {
		return '';
	}

	return $actual;
}

/**
 * The door for the assertion above. An instrument nobody reads is not a check,
 * so a mismatch gets a notice that names both directories and the fix.
 *
 * Scoped to users who could actually act on it — a subscriber seeing this
 * learns nothing and can do nothing about it.
 *
 * @since 12.25.0
 * @return void
 */
function sn_plugin_basename_mismatch_notice() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$actual = sn_plugin_basename_mismatch();
	if ( '' === $actual ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'Signal & Noise Tools: self-updates are disabled.', 'signal-and-noise-tools' )
		. '</strong></p><p>'
		. sprintf(
			/* translators: 1: actual plugin basename, 2: expected plugin basename. */
			esc_html__( 'WordPress loaded this plugin from %1$s, but its updater is hardcoded to %2$s. Because those disagree, no update will ever appear on the Updates screen, and clearing caches will not help.', 'signal-and-noise-tools' ),
			'<code>' . esc_html( $actual ) . '</code>',
			'<code>' . esc_html( SN_GH_PLUGIN_BASENAME ) . '</code>'
		)
		. '</p><p>'
		. esc_html__( 'Fix: reinstall the plugin so its directory is named signal-and-noise-tools. A plain Upload Plugin of a GitHub tag archive keeps the version suffix, which is what causes this.', 'signal-and-noise-tools' )
		. '</p></div>';
}
add_action( 'admin_notices', 'sn_plugin_basename_mismatch_notice' );


/**
 * Turn a failed tags fetch into a short sentence a human can act on.
 *
 * WHY THIS EXISTS (v9.54.0, after a live incident)
 *
 * Both Dashboard cards showed a red "unknown" and nothing, anywhere, said why.
 * A 401 (dead/expired SNT_GITHUB_TOKEN), a 403 (rate limit), a 404 (repo gone)
 * and a network timeout all collapsed into the same `return null`. Diagnosing it
 * meant reading this source, timing the endpoint from a laptop, and probing
 * GitHub's 401 header behaviour — to recover a fact this function had in its
 * hand and dropped.
 *
 * It was worse than uninformative. The Dashboard's "GitHub API: 4,971/5,000"
 * readout still looked healthy, because the rate monitor only records a
 * snapshot from responses that CARRY x-ratelimit-* headers — and GitHub's 401
 * for a bad credential carries none, while a WP_Error never reaches the
 * http_response filter at all. So the one number on screen that looked like
 * evidence was a fossil of the last success. A cache that only updates on
 * success cannot report failure; it poses as healthy exactly when it isn't.
 *
 * Same rule as the v9.47.2 janitor: never silent. The fetch still fails the
 * same way — this only stops the REASON from being thrown away.
 *
 * @since 9.54.0
 * @param array|WP_Error $response The wp_remote_get() return.
 * @return string A short human-readable reason. Never contains a credential.
 */
function sn_gh_fetch_failure_reason( $response ) {
	if ( is_wp_error( $response ) ) {
		// No HTTP response at all — timeout, DNS, TLS. This is the case the
		// frozen rate readout hides best, so carry the real driver message
		// ("cURL error 28: Operation timed out after 5001 ms") rather than a
		// generic "network error": the number in it is the actual diagnosis.
		return sn_gh_redact_secrets( sprintf(
			/* translators: %s: underlying HTTP error message. */
			__( 'could not reach GitHub. %s', 'signal-and-noise-tools' ),
			$response->get_error_message()
		) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	switch ( $code ) {
		case 401:
			// The one failure a site owner can fix in 30 seconds, and the one
			// the old code hid most completely. Name the constant.
			return __( 'GitHub rejected the credential (401). SNT_GITHUB_TOKEN in wp-config.php is invalid, expired, or revoked', 'signal-and-noise-tools' );
		case 403:
			return __( 'GitHub refused the request (403): usually a rate limit; set SNT_GITHUB_TOKEN in wp-config.php to raise 60/h to 5000/h', 'signal-and-noise-tools' );
		case 404:
			return __( 'GitHub returned 404: the repository was renamed, deleted, or made private', 'signal-and-noise-tools' );
		case 200:
			return __( 'GitHub returned 200 but the body was not a readable tag list', 'signal-and-noise-tools' );
		default:
			return sprintf(
				/* translators: %d: HTTP status code. */
				__( 'GitHub returned an unexpected HTTP %d', 'signal-and-noise-tools' ),
				$code
			);
	}
}

/**
 * Strip anything token-shaped out of a message before it can reach a screen.
 *
 * The reason string is rendered in wp-admin and exposed over MCP/REST. An HTTP
 * driver message is not ours and could, in principle, quote a request header
 * back at us. Redact defensively rather than reason about whether cURL ever
 * does: the cost is a regex, and the failure mode is a leaked credential.
 *
 * @since 9.54.0
 * @param string $message
 * @return string
 */
function sn_gh_redact_secrets( $message ) {
	return (string) preg_replace(
		'/\b(gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,}|Bearer\s+\S+)/i',
		'[redacted]',
		(string) $message
	);
}

/**
 * Record why a fetch failed, alongside the empty-sentinel negative cache.
 *
 * @since 9.54.0
 * @param string $reason
 * @return null Always null — callers `return sn_gh_record_fetch_failure(...)`.
 */
function sn_gh_record_fetch_failure( $reason, $code = null ) {
	$ttl = sn_gh_failure_ttl( $code );
	set_site_transient( SN_GH_PLUGIN_CACHE_KEY, '', $ttl );
	set_site_transient( SN_GH_PLUGIN_ERROR_KEY, $reason, $ttl );
	return null;
}

/**
 * Will this failure plausibly have fixed itself in five minutes?
 *
 * WHY THIS EXISTS (v9.54.1, after a live incident)
 *
 * 2026-07-16 22:51 UTC, GitHub declared "Degraded REST API Availability" —
 * ~35% of REST requests failing, "not consistently reaching the application
 * layer". Our tags fetch caught a 503 and both version cards went red four
 * minutes later.
 *
 * The 503 was GitHub's. The SIXTY MINUTES of blindness was ours: any failure
 * cached the empty sentinel for HOUR_IN_SECONDS, so a blip lasting one second
 * cost a full hour — then the next hourly poll had another 35% chance of
 * re-arming it. The dashboard could stay dark for the entire incident and
 * beyond.
 *
 * The tell sat on the same screen the whole time: "Recent deploys" stayed live
 * and correct throughout, because its sibling fetch (github-actions-api.php)
 * caches failures for FIVE MINUTES and self-heals. Same host, same token, same
 * 5s timeout — only the failure TTL differed. (Worth remembering: we first
 * blamed the token, then the response size, then the timeout. The asymmetry was
 * a constant, one file over.)
 *
 * So classify by whether re-asking could plausibly get a different answer:
 *   - 5xx / network / timeout → the far end is unwell. It recovers on its own.
 *   - 401 / 404               → nothing changes in an hour. Don't hammer it.
 *
 * @since 9.54.1
 * @param int|null $code HTTP status, 0 for a WP_Error, null when unknown.
 * @return bool
 */
function sn_gh_failure_is_transient( $code ) {
	$code = (int) $code;
	// 0 = WP_Error: timeout, DNS, TLS, connection reset. The box may just be
	// busy — treat as transient. Same for anything the far end calls a server
	// error (500/502/503/504) and for 429 (explicit "come back shortly").
	return 0 === $code || 429 === $code || $code >= 500;
}

/**
 * How long to hold a failure before asking GitHub again.
 *
 * @since 9.54.1
 * @param int|null $code HTTP status, 0 for a WP_Error, null when unknown.
 * @return int Seconds.
 */
function sn_gh_failure_ttl( $code ) {
	// Transient: match github-actions-api.php's SNT_GH_RUNS_FAIL_TTL, the value
	// that demonstrably rode out the 2026-07-16 incident without ever going
	// dark. Durable: an hour, because a dead credential or a deleted repo will
	// answer identically five minutes from now and the poll is pure noise.
	return sn_gh_failure_is_transient( $code ) ? SN_GH_FAIL_TTL_TRANSIENT : SN_GH_FAIL_TTL_DURABLE;
}

/**
 * Why the last tag fetch failed, or '' if the last one succeeded.
 *
 * @since 9.54.0
 * @return string
 */
function sn_gh_latest_plugin_tag_error() {
	$reason = get_site_transient( SN_GH_PLUGIN_ERROR_KEY );
	return is_string( $reason ) ? $reason : '';
}

/**
 * Fetch the highest semver-formatted tag from GitHub. Returns the tag
 * string (e.g. "v1.4.0") on success, null on error / no matching tags.
 * Cached for SN_GH_PLUGIN_CACHE_TTL; empty sentinel cached 1h on failure.
 *
 * @param bool $force_refresh When true, bypass the cache and re-fetch.
 *                            Used when WP's "Check Again" button is
 *                            clicked (WP_FORCE_UPDATE_CHECK constant
 *                            or `?force-check=1` query arg).
 */
function sn_gh_latest_plugin_tag( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_site_transient( SN_GH_PLUGIN_CACHE_KEY );
		if ( $cached !== false ) {
			return $cached === '' ? null : $cached;
		}
	}

	$url     = 'https://api.github.com/repos/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '/tags?per_page=100';
	$headers = array(
		'Accept'     => 'application/vnd.github+json',
		'User-Agent' => 'WordPress; ' . home_url(),
	);
	// v4.5.6: authenticate the tag-fetch when SNT_GITHUB_TOKEN is defined in
	// wp-config.php — raises the GitHub limit from 60/h (unauthenticated, shared
	// per-server-IP) to 5000/h. Without this, a busy/shared IP can exhaust the
	// 60/h pool, the fetch 403s, sn_gh_latest_plugin_tag() returns null, and the
	// Updates page silently shows "no update available" even when one exists.
	// Mirrors inc/github-actions-api.php. Conditional → unauthenticated fallback
	// is unchanged when the constant is absent.
	if ( defined( 'SNT_GITHUB_TOKEN' ) && SNT_GITHUB_TOKEN ) {
		$headers['Authorization'] = 'Bearer ' . SNT_GITHUB_TOKEN;
	}
	$args = array(
		'timeout'     => 5,
		'headers'     => $headers,
		// v8.8.x: forbid redirects — the SNT_GITHUB_TOKEN bearer must never be
		// re-sent to a 3xx target (outbound-hardening convention, v8.7.1).
		'redirection' => 0,
	);

	$response = wp_remote_get( $url, $args );
	$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

	// v9.54.1: ONE retry, transient failures only. During the 2026-07-16 GitHub
	// incident (~35% of REST requests failing, independently) a single retry
	// recovers ~65% of the polls that would otherwise have blinded the cards.
	// Durable failures (401/404) are never retried — the second answer is the
	// first answer, and hammering a dead credential is pure noise.
	if ( 200 !== $code && sn_gh_failure_is_transient( $code ) ) {
		$response = wp_remote_get( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	if ( 200 !== $code ) {
		return sn_gh_record_fetch_failure( sn_gh_fetch_failure_reason( $response ), $code );
	}

	$tags = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $tags ) ) {
		// A 200 whose body isn't a tag list means we reached SOMETHING that
		// wasn't GitHub's API — an intermediary, a captive portal, an incident
		// error page. Far likelier a blip than a permanent contract change, so
		// classify TRANSIENT (0 = "no usable answer"). The reason string still
		// reports the literal 200 we saw; the code only drives retry policy.
		return sn_gh_record_fetch_failure( sn_gh_fetch_failure_reason( $response ), 0 );
	}

	$highest = '';
	foreach ( $tags as $tag ) {
		$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';
		if ( ! preg_match( '/^v\d+\.\d+\.\d+$/', $name ) ) {
			continue;
		}
		if ( $highest === '' || version_compare( ltrim( $name, 'v' ), ltrim( $highest, 'v' ), '>' ) ) {
			$highest = $name;
		}
	}

	if ( $highest === '' ) {
		// Distinct from "no update available": we reached GitHub and it had
		// nothing shaped like a release. Say so — a silent null here reads
		// identically to a dead token on the card.
		// DURABLE: we reached GitHub, it answered correctly, and the repo simply
		// has no vX.Y.Z tags. Re-asking in five minutes gets the same answer.
		return sn_gh_record_fetch_failure(
			__( 'GitHub returned no tags matching vX.Y.Z: nothing to compare against', 'signal-and-noise-tools' ),
			200
		);
	}

	// Success CLEARS the reason. Without this the fix becomes the next bug: a
	// stale error would sit on the card after the token was rotated, and the
	// owner would rotate a working token again.
	delete_site_transient( SN_GH_PLUGIN_ERROR_KEY );
	set_site_transient( SN_GH_PLUGIN_CACHE_KEY, $highest, SN_GH_PLUGIN_CACHE_TTL );
	return $highest;
}

/**
 * Whether this request is allowed to bypass the cached GitHub tag result.
 *
 * WordPress' constant is trusted internal state. The public query flag is
 * honored only for users who can update plugins, preventing anonymous cache
 * churn while preserving the core Updates screen's Check Again flow.
 */
function sn_plugin_update_force_refresh_requested() {
	if ( defined( 'WP_FORCE_UPDATE_CHECK' ) && WP_FORCE_UPDATE_CHECK ) {
		return true;
	}

	return ! empty( $_GET['force-check'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorization-gated read-only cache bypass.
		&& current_user_can( 'update_plugins' );
}

/**
 * Register the plugin with WP's update transient. WP renders it on
 * wp-admin/update-core.php and Plugins → Installed Plugins from this data.
 *
 * Plugin update transient shape differs from themes: keys are basenames
 * (dir/file.php), entries are stdClass with slug/plugin/new_version/url/package.
 */
add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {
	if ( empty( $transient ) || ! is_object( $transient ) ) {
		$transient = new stdClass();
	}

	// v1.11.1: honor WP's "Check Again" button. WP sets the WP_FORCE_UPDATE_CHECK
	// constant during the wp-admin/update-core.php?force-check=1 flow.
	// Without this, our 12h-cached value persists even when the user
	// explicitly asks for a fresh check.
	$force_refresh = sn_plugin_update_force_refresh_requested();

	$latest_tag = sn_gh_latest_plugin_tag( $force_refresh );
	if ( $latest_tag === null ) {
		return $transient;
	}

	$latest_version  = ltrim( $latest_tag, 'v' );
	$current_version = defined( 'SNT_VERSION' ) ? SNT_VERSION : '0.0.0';

	$plugin_data              = new stdClass();
	$plugin_data->slug        = SN_GH_PLUGIN_SLUG;
	$plugin_data->plugin      = SN_GH_PLUGIN_BASENAME;
	$plugin_data->new_version = $latest_version;
	$plugin_data->url         = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO;
	$plugin_data->package     = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO . '/archive/refs/tags/' . $latest_tag . '.zip';
	// v2.1.2: brand assets — icons + banners served from our own
	// assets/ directory. WP core reads these from the update transient to
	// render the plugin icon on Dashboard → Updates list (verified
	// against wp-admin/update-core.php list_plugin_updates() which
	// checks svg > 2x > 1x > default in that priority order). Without
	// this, the default puzzle-piece dashicon renders.
	//
	// IMPORTANT: `default` key must always be set — class-wp-plugin-
	// install-list-table.php reads `$plugin['icons']['default']`
	// without an `! empty()` guard, so unsetting it would throw a PHP
	// notice on the Plugins → Add New screen. We point every key at the
	// same SVG; browsers render SVG inside <img> tags fine, and the SVG
	// scales to any DPR without a separate retina file.
	$icon_url                 = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$banner_low_url           = plugins_url( 'assets/banner.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$plugin_data->icons       = array(
		'svg'     => $icon_url,
		'2x'      => $icon_url,
		'1x'      => $icon_url,
		'default' => $icon_url,
	);
	// banners only render in the "View Details" modal which goes
	// through the plugins_api filter below — included here for
	// symmetry/scanner-tool compatibility but inert on the update list.
	$plugin_data->banners     = array(
		'low'  => $banner_low_url,
		'high' => $banner_low_url,
	);

	// v2.1.4: compatibility metadata. wp-admin/update-core.php
	// list_plugin_updates() reads $plugin_data->update->tested at
	// line 527 (verified against WP 6.9.4 source). Without it, every
	// upgrade row renders "Compatibility with WordPress N.N.N: Unknown".
	// list_plugin_updates() also reads ->requires_php at line 545 to
	// gate the "this requires PHP X" notice, and ->requires at line ~550
	// for the core-version requirement notice. Setting all three keeps
	// the Updates page green instead of falling through to the
	// uncertainty messaging.
	//
	// `tested` must satisfy version_compare( $tested, $cur_wp_version, '>=' )
	// — bumping it as we test against newer WP. `requires` + `requires_php`
	// mirror the values in the plugin file header for consistency with
	// what the View Details modal renders (plugins_api filter below).
	$plugin_data->tested       = '7.0';
	$plugin_data->requires     = '7.0';
	$plugin_data->requires_php = '8.3';

	if ( version_compare( $latest_version, $current_version, '>' ) ) {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ SN_GH_PLUGIN_BASENAME ] = $plugin_data;
	} else {
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		$transient->no_update[ SN_GH_PLUGIN_BASENAME ] = $plugin_data;
	}

	return $transient;
} );

/**
 * Rename the unpacked source directory so WP installs to the correct
 * plugin slug.
 *
 * GitHub's auto-generated tag archive (`/archive/refs/tags/v1.10.1.zip`)
 * unpacks to `signal-and-noise-tools-1.10.1/` — with the version suffix
 * but without the leading 'v'. WP's installer uses the dir name to
 * decide where to install, which would end up as
 * `wp-content/plugins/signal-and-noise-tools-1.10.1/` (wrong slug,
 * the plugin would deactivate on update because SN_GH_PLUGIN_BASENAME
 * no longer resolves).
 *
 * The filter receives `$source` (path to the unpacked dir) and renames
 * it to drop the version suffix. Standard pattern for GitHub-hosted
 * plugins that ship via auto-generated tag archives.
 */
add_filter( 'upgrader_source_selection', function( $source, $remote_source, $upgrader, $hook_extra ) {
	$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
	if ( $plugin !== SN_GH_PLUGIN_BASENAME ) {
		return $source;
	}

	$source         = trailingslashit( $source );
	$desired_source = trailingslashit( dirname( $source ) ) . SN_GH_PLUGIN_SLUG . '/';

	if ( $source === $desired_source ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) ) ) {
		return new WP_Error(
			'sn_rename_source_failed',
			'Could not rename the unpacked plugin directory from "' . esc_html( basename( $source ) ) . '" to "' . SN_GH_PLUGIN_SLUG . '". Manual install via SFTP may be required.'
		);
	}

	return $desired_source;
}, 10, 4 );

/**
 * On every admin pageview, check whether the on-disk plugin version
 * differs from the last-seen version. If it does, clear the update
 * transient — the cached "latest" was relative to the previous
 * version and is now stale.
 *
 * Handles the upgrade-just-happened case automatically:
 * - WP UI install completes → next pageview clears the cache
 * - workflow_dispatch deploy lands → next pageview clears the cache
 * - `wp plugin install --force` lands → next WP-CLI command clears the cache
 *
 * Costs one get_option() call per request. Negligible — the option is
 * autoloaded, so the read is an array lookup, and the body only does work on
 * the single request that observes the version change.
 *
 * HOOK CHOICE — `init`, NOT `admin_init` (v12.25.0, 2026-08-24)
 *
 * This was registered on `admin_init` from v1.11.1 until v12.25.0, which meant
 * it only ever fired for a logged-in wp-admin pageview. WP-CLI is not an admin
 * request and neither is wp-cron, so the two contexts that most need the
 * invalidation never got it: a maintainer updating from the CLI read whatever
 * the object cache last held (up to SN_GH_PLUGIN_CACHE_TTL for the tag, up to
 * 12h for `update_plugins`), and the cron-driven update poll likewise.
 *
 * That is not academic on a site with a persistent object cache: site
 * transients live in Redis rather than wp_options, so `wp transient delete
 * --all` cannot clear them either — it deletes only DB-backed transients by
 * design. The self-healing existed but was unreachable from the way the plugin
 * is actually operated. Diagnosed 2026-08-24.
 *
 * `init` fires in wp-admin, on the front end, under wp-cron and under WP-CLI.
 * Admin requests fire `init` *before* `admin_init`, so this strictly dominates
 * the old registration — nothing that worked before stops working.
 *
 * Added in v1.11.1 (2026-05-16). Moved to `init` + named in v12.25.0.
 */
function sn_plugin_update_version_watchdog() {
	$last_seen = (string) get_option( SN_GH_PLUGIN_LAST_SEEN_OPT, '' );
	$current   = defined( 'SNT_VERSION' ) ? SNT_VERSION : '';
	if ( $current && $last_seen !== $current ) {
		delete_site_transient( SN_GH_PLUGIN_CACHE_KEY );
		// Also clear WP's own plugin update transient so the next poll
		// re-fetches fresh data (covers the case where WP cached our
		// pre-update version as "latest").
		delete_site_transient( 'update_plugins' );
		// v1.15.1: also clear the parsed-plugins-headers cache so the
		// Plugins screen renders the current plugin header (Name,
		// Description, Author) instead of cached pre-update values.
		// Required because our SSH-checkout deploy path doesn't trigger
		// WP's installer (which would call wp_clean_plugins_cache
		// automatically). Bug surfaced when "Signal & Noise Tools"
		// displayed as the literal text "Signal &amp; Noise Tools" in
		// the plugins list (header was already plain `&`, but the cache
		// retained an old double-escaped value across SSH deploys).
		// wp_clean_plugins_cache() lives in wp-admin/includes/plugin.php, which
		// is not loaded outside wp-admin. Now that this watchdog runs under
		// WP-CLI, wp-cron and the front end too, pull the include in rather
		// than silently skipping the header-cache clear in those contexts —
		// skipping it would be worse than the bug this fixes, because the
		// last-seen option is written either way and the next admin request
		// would see no version change left to act on.
		if ( ! function_exists( 'wp_clean_plugins_cache' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
			// v13.97.1 (#1029): and never leave an EMPTY plugin list behind.
			// This watchdog is the one thing in the plugin that deliberately
			// drops WP's plugin cache, it fires exactly once on the first
			// request after a version change, and since v12.25.0 it runs on
			// `init` - so under WP-CLI, cron, the front end and REST as well as
			// wp-admin. Whichever call rebuilds the cache next does so while
			// the plugin directory may still be settling, and `get_plugins()`
			// caches whatever it scanned, including nothing. A persistent
			// object cache then serves "no plugins installed" as a healthy 200
			// until something evicts it - which is what the OpenStation
			// Plugins window rendered as an empty list after an update.
			//
			// The repair is a no-op unless the registry is empty AND
			// active_plugins is not, which cannot both be legitimately true.
			if ( function_exists( 'snt_plugin_registry_repair' ) ) {
				snt_plugin_registry_repair();
			}
		}
		// v2.1.2: also clear the plugin_information_<slug> transient
		// which caches the plugins_api response (used by the View
		// Details modal). Without this, the modal would keep showing
		// the previous version's metadata even after install.
		delete_site_transient( 'plugin_information_' . SN_GH_PLUGIN_SLUG );
		update_option( SN_GH_PLUGIN_LAST_SEEN_OPT, $current );
	}
}
add_action( 'init', 'sn_plugin_update_version_watchdog' );

/**
 * Filter plugins_api to provide the View Details modal data for our
 * self-hosted plugin.
 *
 * WP core's plugin_install.php fires plugins_api( 'plugin_information', $args )
 * when a user clicks "View details" on any plugin row. For
 * wordpress.org plugins, the WP.org API server populates the response.
 * For self-hosted, that API returns nothing, so the modal shows a
 * "Plugin not found" error — unless we filter and supply the response
 * ourselves.
 *
 * v2.1.2: added so the View Details modal renders correctly + the
 * banner + the inline description. Result cached in the
 * `plugin_information_<slug>` site transient (24h TTL by WP default);
 * the version-change watchdog above clears it on each upgrade.
 *
 * Verified shape against wp-admin/includes/plugin-install.php
 * install_plugin_information() lines ~872-891 (banner rendering) and
 * wp-admin/includes/class-wp-plugin-install-list-table.php display_rows()
 * lines ~445-475 (icon rendering).
 */
add_filter( 'plugins_api', function( $result, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $result;
	}
	if ( ! isset( $args->slug ) || SN_GH_PLUGIN_SLUG !== $args->slug ) {
		return $result;
	}

	$icon_url       = plugins_url( 'assets/icon.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$banner_url     = plugins_url( 'assets/banner.svg', SNT_PATH . 'signal-and-noise-tools.php' );
	$latest_tag     = sn_gh_latest_plugin_tag();
	$latest_version = $latest_tag ? ltrim( $latest_tag, 'v' ) : ( defined( 'SNT_VERSION' ) ? SNT_VERSION : '' );
	$repo_url       = 'https://github.com/' . SN_GH_PLUGIN_OWNER . '/' . SN_GH_PLUGIN_REPO;

	$info                    = new stdClass();
	$info->name              = 'Signal & Noise Tools';
	$info->slug              = SN_GH_PLUGIN_SLUG;
	$info->version           = $latest_version;
	$info->author            = '<a href="https://juanlentino.com">Juan Lentino</a>';
	$info->author_profile    = 'https://juanlentino.com';
	$info->homepage          = $repo_url;
	$info->requires          = '7.0';
	$info->tested            = '7.0';
	$info->requires_php      = '8.3';
	$info->download_link     = $latest_tag ? $repo_url . '/archive/refs/tags/' . $latest_tag . '.zip' : '';
	$info->short_description = 'Operational + content tooling that powers juanlentino.com. SEO emission, cache controls, RSS subscriber tracking, OG card generation, GitHub-Actions deploy status, AI-assisted meta descriptions (WP 7.0+), and a WordPress/desktop-mode integration with on-desktop widgets.';
	$info->sections          = array(
		'description' => '<p>Companion plugin to the <a href="https://github.com/juanlentino/signal-and-noise">Signal &amp; Noise</a> brutalist block theme. Owns everything operational + content-authoring-related so the theme can stay focused on presentation.</p>'
			. '<p><strong>SEO</strong>: meta tags, JSON-LD <code>@graph</code>, sitemap routing, <code>&lt;title&gt;</code> emission, <code>Last-Modified</code> header + <code>If-Modified-Since</code> 304 (post-Phase-13 cutover; The SEO Framework dropped as a dependency).</p>'
			. '<p><strong>Ops tooling</strong>. Cloudflare cache purge, custom login URL, RSS subscriber tracking, API rate-limit monitor, OG card generator, GitHub Actions deploy status.</p>'
			. '<p><strong>WP 7.0 readiness</strong>. AI-assisted meta description (Phase 12 scaffold, dormant on 6.x), Abilities API registration (4 abilities: <code>purge-all-caches</code>, <code>regenerate-og-card</code>, <code>get-deploy-status</code>, <code>clear-template-overrides</code>).</p>'
			. '<p><strong>Desktop-mode integration</strong>: dock entry with 8-tab submenu, three desktop widgets (deploy status, quick actions, RSS subscribers), 13 ⌘K commands.</p>',
		'changelog'   => '<p>See the full <a href="' . esc_url( $repo_url . '/blob/main/CHANGELOG.md' ) . '">CHANGELOG on GitHub</a>.</p>',
	);
	$info->icons             = array(
		'svg'     => $icon_url,
		'2x'      => $icon_url,
		'1x'      => $icon_url,
		'default' => $icon_url,
	);
	$info->banners           = array(
		'low'  => $banner_url,
		'high' => $banner_url,
	);

	return $info;
}, 10, 3 );
