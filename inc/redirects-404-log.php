<?php
/**
 * Signal & Noise Tools — front-end 404 capture log (B2, v8.10.0).
 *
 * A capped, aggregating option (`sn_404_log`) of paths that hit a real front-end
 * 404, so the owner can spot broken inbound links and turn the worst offenders
 * into redirects (B1). Pure data layer — the template_redirect capture hook that
 * feeds it lives in inc/redirects-handler.php.
 *
 * Aggregating (path-keyed with a hit count), not append-only: a link a bot
 * hammers 10,000 times is ONE row with count 10000, not 10,000 rows. That bounds
 * both the option size and the signal. A junk filter keeps the exec/probe noise
 * (wp-login.php, /.env, vendor/phpunit RCE probes) out entirely.
 *
 *   '/broken-path' => array( 'count' => int, 'first_seen' => ts, 'last_seen' => ts, 'referer' => str )
 *
 * @package SignalNoiseTools
 * @since 8.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_404_LOG_OPT' ) ) {
	define( 'SN_404_LOG_OPT', 'sn_404_log' );
}
if ( ! defined( 'SN_404_LOG_MAX' ) ) {
	define( 'SN_404_LOG_MAX', 200 );
}

/**
 * Should this 404 path be logged? Filters out the site root and the executable /
 * config-file probes automated scanners fire in bulk (they aren't broken links
 * an owner can fix, and they'd swamp the log). Everything content-shaped passes.
 *
 * @param string $path Request path (any form; normalized here).
 * @return bool True to capture, false to ignore.
 */
function sn_404_should_capture( $path ) {
	$path = sn_redirects_normalize_path( $path );
	if ( '/' === $path ) {
		return false;
	}
	$lower = strtolower( $path );
	// Executable / config / dump extensions — never a legit content 404.
	if ( preg_match( '#\.(php|phtml|asp|aspx|jsp|cgi|env|git|sql|bak|old|ini|sh|py|yml|yaml|json|lock)$#', $lower ) ) {
		return false;
	}
	// Substrings that mark an infra path or a known probe campaign.
	$probes = array( 'wp-login', 'wp-admin', 'xmlrpc', '/wp-json', '/.git', '/.env', '/.svn', '.htaccess', '.ds_store', '/vendor/', '/wp-includes/', '/wp-content/', 'phpunit', 'eval-stdin', '/cgi-bin/', '/.well-known/acme' );
	foreach ( $probes as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The full 404 log, path-keyed.
 *
 * @return array<string,array{count:int,first_seen:int,last_seen:int,referer:string}>
 */
function sn_404_log_all() {
	$log = get_option( SN_404_LOG_OPT, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * Record a 404 hit. Aggregates onto an existing path (bump count + last_seen +
 * latest referer) or inserts a new one; enforces the FIFO cap on distinct paths.
 * Junk paths (sn_404_should_capture) are silently ignored.
 *
 * @param string $uri     Request URI (may include a query string).
 * @param string $referer Referring URL, if any.
 * @return bool True if the hit was recorded, false if filtered out.
 */
function sn_404_log_record( $uri, $referer = '' ) {
	if ( ! sn_404_should_capture( $uri ) ) {
		return false;
	}
	$path    = sn_redirects_normalize_path( $uri );
	$referer = trim( (string) $referer );
	$now     = time();
	$log     = sn_404_log_all();

	if ( isset( $log[ $path ] ) ) {
		$log[ $path ]['count']     = (int) $log[ $path ]['count'] + 1;
		$log[ $path ]['last_seen'] = $now;
		if ( '' !== $referer ) {
			$log[ $path ]['referer'] = $referer;
		}
	} else {
		$log[ $path ] = array(
			'count'      => 1,
			'first_seen' => $now,
			'last_seen'  => $now,
			'referer'    => $referer,
		);
	}
	if ( count( $log ) > SN_404_LOG_MAX ) {
		$log = array_slice( $log, -SN_404_LOG_MAX, null, true );
	}
	// Non-autoloaded: this log is read only in wp-admin and can hold 200 entries —
	// it has no business in the autoload bundle loaded on every front-end request.
	update_option( SN_404_LOG_OPT, $log, false );
	return true;
}

/**
 * Delete a single logged path (e.g. once the owner has added a redirect for it).
 *
 * @param string $path Path (any form; normalized here).
 * @return bool True if a matching entry existed and was removed.
 */
function sn_404_log_delete( $path ) {
	$path = sn_redirects_normalize_path( $path );
	$log  = sn_404_log_all();
	if ( ! isset( $log[ $path ] ) ) {
		return false;
	}
	unset( $log[ $path ] );
	update_option( SN_404_LOG_OPT, $log );
	return true;
}

/**
 * Clear the entire 404 log.
 *
 * @return bool
 */
function sn_404_log_clear() {
	return delete_option( SN_404_LOG_OPT );
}
