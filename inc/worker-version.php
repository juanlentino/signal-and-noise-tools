<?php
/**
 * Signal & Noise Tools — analytics edge-Worker version readout.
 *
 * Surfaces the DEPLOYED version of the first-party analytics collector Worker
 * (signal-and-noise-analytics-worker) inside Measurement → Analytics, so the
 * full version story — theme + plugin + edge Worker — is visible in wp-admin
 * without curling the edge by hand.
 *
 * Data source: the Worker's read-only `GET /_sn/version` endpoint (worker
 * v1.4.0+), which returns JSON:
 *   { worker, version (package.json semver, injected at deploy via
 *     `--var SN_VERSION`), cf_version_id (== the `wrangler deployments list`
 *     UUID), cf_version_tag, deployed_at } — every field degrades to null when
 *   its binding/var is absent, and nothing secret is exposed (the endpoint
 *   takes no token).
 *
 * Why DERIVE the URL instead of adding a setting or hardcoding it:
 *   - NEVER hardcode the version — always show what's actually live (this is the
 *     point of the feature, and it sidesteps the `*_VERSION` constant-drift class
 *     entirely; see feedback_version_constants_must_derive_from_docblock).
 *   - The version endpoint is a SIBLING of the collector beacon (`/_sn/px`) on
 *     the same Worker origin, so we rebuild it from the SAME admin-configured
 *     collector base the RSS tracker / front-end beacon already use
 *     (inc/rss-feed-tracker.php `collector_url`). If the origin can't hairpin to
 *     the edge and the collector is pointed at the Worker's `*.workers.dev` URL,
 *     the version probe follows automatically — no second URL to keep in sync.
 *
 * Security: the derived URL is influenced by an admin-set option, so it goes
 * through the SAME outbound gate as every other probe in this plugin — https
 * only + wp_http_validate_url() + the shared sn_ssrf_host_blocked()
 * (resolve-then-range-check, which catches the encoded-IP metadata bypasses a
 * literal string match misses) + redirection=0. Read-only GET, admin-only
 * render, SWR-cached in a transient so a settings-page load never blocks on a
 * cold/slow edge for more than one short request.
 *
 * @package SignalNoiseTools
 * @since 6.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_WORKER_VERSION_TRANSIENT = 'sn_worker_version_probe';
const SN_WORKER_VERSION_LASTGOOD  = 'sn_worker_version_last_good';
const SN_WORKER_VERSION_TTL_OK    = 600; // 10 min — fresh cache after a good probe.
const SN_WORKER_VERSION_TTL_FAIL  = 120; // 2 min  — retry sooner after a failure.
const SN_WORKER_VERSION_TIMEOUT   = 4;   // seconds — keep the admin page responsive.

/**
 * The admin-configured collector base — the same URL the RSS tracker + the
 * front-end beacon post to (`/_sn/px`). Falls back to this site's own endpoint
 * (mirroring the tracker's own default) when the tracker module isn't loaded.
 *
 * @since 6.21.0
 * @return string Collector URL, e.g. https://example.com/_sn/px
 */
function sn_worker_version_collector_base() {
	if ( function_exists( 'sn_rss_tracker_settings' ) ) {
		$settings  = sn_rss_tracker_settings();
		$collector = is_array( $settings ) && isset( $settings['collector_url'] ) ? (string) $settings['collector_url'] : '';
		if ( '' !== $collector ) {
			return $collector;
		}
	}
	return home_url( '/_sn/px' );
}

/**
 * Rebuild the Worker's `/_sn/version` URL from the collector base's ORIGIN
 * (scheme + host + optional port). The version endpoint is a sibling path of the
 * beacon, so the base's own path is ignored entirely — only its origin matters.
 * Returns '' when the base has no usable scheme/host (caller fails closed).
 *
 * @since 6.21.0
 * @return string e.g. https://example.com/_sn/version  (or '' if underivable)
 */
function sn_worker_version_endpoint_url() {
	$parts = wp_parse_url( sn_worker_version_collector_base() );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$origin = $parts['scheme'] . '://' . $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$origin .= ':' . (int) $parts['port'];
	}
	return $origin . '/_sn/version';
}

/**
 * Parse a `/_sn/version` HTTP response into a normalized result. Pure — no I/O —
 * so it's exhaustively testable. Whitelists + sanitizes only the fields we
 * render; every one degrades to '' when absent (the Worker nulls them when a
 * binding/var is missing). A 2xx body that isn't the expected JSON shape (e.g.
 * an HTML error page from a proxy in front of the Worker) is treated as a
 * failure, not a fake success.
 *
 * @since 6.21.0
 * @param int    $code HTTP status.
 * @param string $body Response body.
 * @return array{ok:bool,data:array,error:string}
 */
function sn_worker_version_parse_response( $code, $body ) {
	if ( 200 !== (int) $code ) {
		return array(
			'ok'    => false,
			'data'  => array(),
			'error' => 'http-' . (int) $code,
		);
	}
	$json = json_decode( (string) $body, true );
	if ( ! is_array( $json ) || empty( $json['worker'] ) ) {
		return array(
			'ok'    => false,
			'data'  => array(),
			'error' => 'bad-response',
		);
	}
	$clean = static function ( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	};
	// config (worker v1.9.0+): presence-only booleans whether each binding/secret is
	// wired. Pass it through as a strict bool map so the edge-worker Health check can
	// alert on a data-loss misconfiguration (e.g. px_token_set=false silently rejects
	// every beacon). Absent on older workers → empty array, treated as "unknown".
	$config = array();
	if ( isset( $json['config'] ) && is_array( $json['config'] ) ) {
		foreach ( $json['config'] as $key => $value ) {
			$config[ sanitize_key( (string) $key ) ] = (bool) $value;
		}
	}
	return array(
		'ok'    => true,
		'data'  => array(
			'worker'         => $clean( $json['worker'] ?? '' ),
			'version'        => $clean( $json['version'] ?? '' ),
			'cf_version_id'  => $clean( $json['cf_version_id'] ?? '' ),
			'cf_version_tag' => $clean( $json['cf_version_tag'] ?? '' ),
			'deployed_at'    => $clean( $json['deployed_at'] ?? '' ),
			'config'         => $config,
		),
		'error' => '',
	);
}

/**
 * Probe the Worker's /_sn/version endpoint NOW (no cache). Applies the shared
 * outbound gate, then a short-timeout GET, then parses. The endpoint + a
 * fetched_at stamp are attached to every result (success or failure) so the
 * caller can render "checked N ago" / "couldn't reach <url>".
 *
 * @since 6.21.0
 * @return array{ok:bool,data:array,error:string,url:string,fetched_at:int}
 */
function sn_worker_version_probe() {
	$url   = sn_worker_version_endpoint_url();
	$stamp = array(
		'url'        => $url,
		'fetched_at' => time(),
	);

	if ( '' === $url ) {
		return array_merge(
			array(
				'ok'    => false,
				'data'  => array(),
				'error' => 'no-endpoint',
			),
			$stamp
		);
	}

	// Same outbound gate as every other probe (webhooks / uptime / link-rot):
	// https-only + core URL validation + the shared resolve-then-range-check
	// guard, which catches the encoded-IP metadata bypasses a literal string
	// match misses. redirection=0 stops a validated host redirecting to an
	// internal one (the host filter only ever sees the first hop).
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return array_merge(
			array(
				'ok'    => false,
				'data'  => array(),
				'error' => 'blocked',
			),
			$stamp
		);
	}

	$resp = wp_remote_get(
		$url,
		array(
			'timeout'     => SN_WORKER_VERSION_TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'SignalNoiseTools/' . ( defined( 'SNT_VERSION' ) ? SNT_VERSION : 'dev' ) . ' worker-version',
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return array_merge(
			array(
				'ok'    => false,
				'data'  => array(),
				'error' => 'network',
			),
			$stamp
		);
	}

	$parsed = sn_worker_version_parse_response(
		(int) wp_remote_retrieve_response_code( $resp ),
		(string) wp_remote_retrieve_body( $resp )
	);
	return array_merge( $parsed, $stamp );
}

/**
 * SWR-cached probe result. Serves the transient when warm; on a miss, probes
 * live, caches it (a SHORT TTL on failure so we retry sooner), and records the
 * last GOOD result in a separate option so a transient edge blip still shows the
 * last-known version rather than blanking. The last-good option is never
 * overwritten by a failure. $force bypasses the transient and probes live — a
 * cache-control seam for tests (and any future explicit re-check); the rendered
 * card itself is read-only and always uses the cached path.
 *
 * @since 6.21.0
 * @param bool $force Bypass the transient and probe live.
 * @return array
 */
function sn_worker_version_get( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( SN_WORKER_VERSION_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$result = sn_worker_version_probe();
	set_transient(
		SN_WORKER_VERSION_TRANSIENT,
		$result,
		! empty( $result['ok'] ) ? SN_WORKER_VERSION_TTL_OK : SN_WORKER_VERSION_TTL_FAIL
	);
	if ( ! empty( $result['ok'] ) ) {
		update_option( SN_WORKER_VERSION_LASTGOOD, $result, false );
	}
	return $result;
}

/**
 * Format an ISO-8601 deploy timestamp for display: "2026-06-17 12:00 UTC
 * (2 days ago)" when parseable, else the raw string. '' when empty.
 *
 * @since 6.21.0
 * @param string $iso Timestamp string from CF version metadata.
 * @return string
 */
function sn_worker_version_format_deployed( $iso ) {
	$iso = (string) $iso;
	if ( '' === $iso ) {
		return '';
	}
	$ts = strtotime( $iso );
	if ( false === $ts ) {
		return $iso;
	}
	return gmdate( 'Y-m-d H:i', $ts ) . ' UTC (' . human_time_diff( $ts, time() ) . ' ago)';
}

/**
 * Render a successful (or last-known) version result as a native readout. $stale
 * marks a last-known value shown because the live probe just failed. No new CSS:
 * reuses the `.notice notice-*-alt inline` + `.sn-an-empty` pattern the
 * analytics empty/error states already use.
 *
 * @since 6.21.0
 * @param array $result A probe result with ok=true (live or cached last-good).
 * @param bool  $stale  True when shown as a fallback after a live probe failed.
 */
function sn_worker_version_render_data( $result, $stale ) {
	$data     = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	$worker   = (string) ( $data['worker'] ?? 'sn-analytics' );
	$version  = (string) ( $data['version'] ?? '' );
	$cf_id    = (string) ( $data['cf_version_id'] ?? '' );
	$cf_tag   = (string) ( $data['cf_version_tag'] ?? '' );
	$deployed = sn_worker_version_format_deployed( $data['deployed_at'] ?? '' );
	$level    = $stale ? 'notice-warning' : 'notice-info';

	echo '<div class="notice ' . esc_attr( $level ) . ' notice-alt inline">';

	echo '<p><strong>Worker</strong> <code>' . esc_html( '' !== $worker ? $worker : 'sn-analytics' ) . '</code> ';
	if ( '' !== $version ) {
		echo '<code>v' . esc_html( $version ) . '</code>';
	} else {
		echo '<span class="sn-an-empty">(semver unreported: deploy with <code>npm run deploy</code>)</span>';
	}
	echo '</p>';

	if ( '' !== $cf_id ) {
		echo '<p><strong>Cloudflare version:</strong> <code class="sn-mono">' . esc_html( $cf_id ) . '</code>';
		if ( '' !== $cf_tag ) {
			echo ' &middot; tag <code>' . esc_html( $cf_tag ) . '</code>';
		}
		echo '</p>';
	}

	if ( '' !== $deployed ) {
		echo '<p><strong>Deployed:</strong> ' . esc_html( $deployed ) . '</p>';
	}

	$fetched_at = isset( $result['fetched_at'] ) ? (int) $result['fetched_at'] : 0;
	if ( $stale ) {
		echo '<p class="sn-an-empty">Live check failed just now: showing the last value reached';
		if ( $fetched_at > 0 ) {
			echo ' ' . esc_html( human_time_diff( $fetched_at, time() ) ) . ' ago';
		}
		echo '.</p>';
	} elseif ( $fetched_at > 0 ) {
		echo '<p class="sn-an-empty">Checked ' . esc_html( human_time_diff( $fetched_at, time() ) ) . ' ago.</p>';
	}

	if ( ! empty( $result['url'] ) ) {
		echo '<p class="sn-an-empty">Source: <code class="sn-mono">' . esc_html( (string) $result['url'] ) . '</code></p>';
	}

	echo '</div>';
}

/**
 * Whether the admin requested an explicit "Re-check now" — a nonce-verified
 * cache-bypass so the card probes the Worker live. Needed because Worker deploys
 * are out-of-band from wp-admin, so the 10-min SWR cache would otherwise show a
 * stale version until it expires. Read-only side effect (re-probe + cache
 * refresh); the caller enforces manage_options.
 *
 * @since 6.22.1
 * @return bool
 */
function sn_worker_version_recheck_requested() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence flag only; the nonce is verified on the very next line before any effect.
	if ( empty( $_GET['sn_worker_recheck'] ) ) {
		return false;
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	return (bool) wp_verify_nonce( $nonce, 'sn_worker_recheck' );
}

/**
 * The nonce-protected "Re-check now" URL — the same Measurement → Analytics screen
 * with the cache-bypass trigger appended.
 *
 * @since 6.22.1
 * @return string
 */
function sn_worker_version_recheck_url() {
	return wp_nonce_url(
		add_query_arg(
			array(
				'page'              => 'sn-theme-options',
				'tab'               => 'monitoring',
				'sn_worker_recheck' => '1',
			),
			admin_url( 'admin.php' )
		),
		'sn_worker_recheck'
	);
}

/**
 * Render the edge-Worker version status card into the Measurement → Analytics
 * settings section. Native wp-admin chrome (no theme vocabulary). Three states:
 * live (info), last-known after a transient failure (warning), and
 * never-reached (warning), plus a nonce-protected "Re-check now" link that
 * bypasses the SWR cache (Worker deploys are out-of-band from wp-admin, so the
 * card would otherwise lag a deploy by up to its 10-min TTL). Admin-only.
 *
 * @since 6.21.0
 */
function sn_worker_version_render_card() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result    = sn_worker_version_get( sn_worker_version_recheck_requested() );
	$last_good = get_option( SN_WORKER_VERSION_LASTGOOD, array() );

	echo '<h3 class="sn-fieldset-h">Edge worker</h3>';
	echo '<p class="sn-an-settings-help">The deployed version of the analytics collector Worker, read live from its <code>/_sn/version</code> endpoint (derived from the configured collector base, so it follows a <code>*.workers.dev</code> override automatically).</p>';

	if ( ! empty( $result['ok'] ) ) {
		sn_worker_version_render_data( $result, false );
	} elseif ( is_array( $last_good ) && ! empty( $last_good['ok'] ) ) {
		sn_worker_version_render_data( $last_good, true );
	} else {
		echo '<div class="notice notice-warning notice-alt inline"><p>';
		echo '<strong>Worker version unknown.</strong> Couldn\'t reach the <code>/_sn/version</code> endpoint';
		if ( ! empty( $result['url'] ) ) {
			echo ' at <code class="sn-mono">' . esc_html( (string) $result['url'] ) . '</code>';
		}
		echo '. The Worker may not be deployed yet (it needs worker <strong>v1.4.0+</strong>), or this host can\'t reach it &mdash; point the <em>Collector endpoint</em> (Content &rarr; RSS) at the Worker\'s <code>*.workers.dev</code> URL if the origin doesn\'t hairpin to the edge.';
		echo '</p></div>';
	}

	// Explicit re-check — Worker deploys happen outside wp-admin, so the 10-min
	// SWR cache can show a stale version until it expires. This link probes live.
	echo '<p><a href="' . esc_url( sn_worker_version_recheck_url() ) . '" class="button button-secondary">Re-check now</a></p>';
}
