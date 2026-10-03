<?php
/**
 * Signal & Noise Tools: alerts, the pure half. What counts as a spike or a
 * break, and the plain-text email that says so.
 *
 * Owner, 2026-10-03: "email me when something spikes or breaks". Everything
 * here is PURE given its inputs, so tests/alerts.php needs no WordPress; the
 * hourly cron in inc/alerts-cron.php gathers the stored rows and sends.
 *
 * WHY FLOORS, NOT ONLY RATIOS. The site gets about 50 human visits a week, so
 * most paths have a 7-day mean under one view a day: five times nothing is
 * still nothing, and a ratio alone would mail on a path's third view. The
 * absolute floor is what makes a spike mean "someone linked this", and the
 * ratio only takes over on the few paths that already have traffic.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_ALERT_PATH_FLOOR = 15; // A path's human views today must pass this...
const SNT_ALERT_PATH_RATIO = 5;  // ...and this many times its prior 7-day daily mean.
const SNT_ALERT_SITE_FLOOR = 40; // The whole site today: about a normal week's visits in one day.
const SNT_ALERT_SITE_RATIO = 4;  // Lower than the path ratio: the site total is the steadier number.
const SNT_ALERT_BREAK_MIN  = 3;  // Stored 5xx on one front-end page in one day; one or two is a blip.
const SNT_ALERT_BASE_DAYS  = 7;  // The baseline window, absent days counted as zero.
const SNT_ALERT_KEEP_DAYS  = 14; // How long a sent stamp is kept.

/**
 * The thresholds, filterable (`snt_alerts_thresholds`). A filter can change a
 * number, never remove one.
 *
 * @return array<string,float>
 */
function snt_alerts_thresholds() {
	$base = array( 'path_floor' => SNT_ALERT_PATH_FLOOR, 'path_ratio' => SNT_ALERT_PATH_RATIO, 'site_floor' => SNT_ALERT_SITE_FLOOR, 'site_ratio' => SNT_ALERT_SITE_RATIO, 'break_min' => SNT_ALERT_BREAK_MIN );
	$got  = function_exists( 'apply_filters' ) ? (array) apply_filters( 'snt_alerts_thresholds', $base ) : $base;
	return array_map( 'floatval', array_intersect_key( $got, $base ) + $base );
}

/**
 * Text that came from a request (a path, a referrer host), made safe for a
 * plain-text email: printable ASCII only, bounded.
 *
 * @param string $text Untrusted text.
 * @return string
 */
function snt_alerts_clean( $text ) {
	return substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', (string) $text ), 0, 120 );
}

/**
 * Which alerts are due and not yet sent.
 *
 * @param array<string,mixed> $in {
 *     today    string               The site-local day, YYYY-MM-DD.
 *     views    array<string,int>    Today's human views by path.
 *     history  array<string,int>    Human views by path, summed over the prior 7 days.
 *     errors   array<string,array<string,int>> Stored 5xx by UTC day, then path.
 *     sent     array<string,int>    Alert key => when it was mailed.
 *     excluded callable|null        The plugin's excluded-path rule.
 *     cache    array|null           A cache call nothing could retry (sn_cf_purge_failure()).
 *     hn       array                Hacker News stories for this site (sn_hn_refresh()).
 *     hn_since int                  A story first seen at or after this is news.
 *     real     callable|null        Whether a path is a real page (home, published content, a known archive). Without it no break fires.
 * }
 * @param array<string,float> $t Thresholds, from snt_alerts_thresholds().
 * @return array<int,array<string,mixed>> Each: key, kind, path, day, value, baseline, line.
 */
function snt_alerts_evaluate( array $in, array $t ) {
	$today    = (string) ( $in['today'] ?? '' );
	$views    = (array) ( $in['views'] ?? array() );
	$history  = (array) ( $in['history'] ?? array() );
	$sent     = (array) ( $in['sent'] ?? array() );
	$excluded = $in['excluded'] ?? null;
	$junk     = static function ( $path ) use ( $excluded ) {
		return '/wp-json' === $path || ( is_callable( $excluded ) && call_user_func( $excluded, $path ) );
	};
	$real     = $in['real'] ?? null;
	$found    = array();
	$add      = static function ( $kind, $path, $day, $value, $baseline, $line, array $extra = array() ) use ( &$found, $sent ) {
		$key = $kind . '|' . $path . '|' . $day;
		if ( ! isset( $sent[ $key ] ) ) {
			$found[] = compact( 'key', 'kind', 'path', 'day', 'value', 'baseline', 'line' ) + $extra;
		}
	};
	// Hacker News stories for this site (inc/hn-mentions.php), by path, for the
	// spike line below and the two alerts after it.
	$hn_by_path = array();
	foreach ( (array) ( $in['hn'] ?? array() ) as $row ) {
		$hn_by_path[ '/' . trim( (string) ( $row['path'] ?? '' ), '/' ) ] ??= $row; // rows come newest first: the first for a path is the current story.
	}

	$total = 0;
	foreach ( $views as $path => $n ) {
		$path = (string) $path;
		if ( $junk( $path ) ) {
			continue;
		}
		$total += (int) $n;
		$mean   = (int) ( $history[ $path ] ?? 0 ) / SNT_ALERT_BASE_DAYS;
		$line   = max( $t['path_floor'], $t['path_ratio'] * $mean );
		if ( (int) $n > $line ) {
			$story = $hn_by_path[ '/' . trim( $path, '/' ) ] ?? null;
			$add( 'spike', $path, $today, (int) $n, round( $mean, 1 ), $line, $story ? array( 'hn' => $story ) : array() );
		}
	}
	$mean = array_sum( array_map( 'intval', $history ) ) / SNT_ALERT_BASE_DAYS;
	$line = max( $t['site_floor'], $t['site_ratio'] * $mean );
	if ( $total > $line ) {
		$add( 'spike_site', '', $today, $total, round( $mean, 1 ), $line );
	}
	// A break is keyed on the day the errors HAPPENED, not the day they were
	// noticed: the edge rollup stores a day after it ends, so the same rows are
	// read on two calendar days and must mail once. Only a REAL page breaks: the
	// stored 5xx rows are full of scanner probes (/_security/_authenticate,
	// /model/info, /Dockerfile) that no excluded-path rule can list, so the
	// test is positive (the path resolves to content), never a deny list.
	foreach ( (array) ( $in['errors'] ?? array() ) as $day => $paths ) {
		foreach ( (array) $paths as $path => $n ) {
			if ( (int) $n >= $t['break_min'] && ! $junk( (string) $path ) && is_callable( $real ) && call_user_func( $real, (string) $path ) ) {
				$add( 'break', (string) $path, (string) $day, (int) $n, 0, $t['break_min'] );
			}
		}
	}
	// Hacker News: a story first seen in the last day mails once; reaching the
	// front page mails once more. Keyed on the story id, never on a day.
	foreach ( (array) ( $in['hn'] ?? array() ) as $row ) {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id < 1 ) {
			continue;
		}
		// News is a YOUNG story just found. The first run finds every old story
		// at once; those are history, not news.
		$seen = (int) ( $row['first_seen'] ?? 0 );
		if ( $seen >= (int) ( $in['hn_since'] ?? PHP_INT_MAX ) && (int) ( $row['created'] ?? 0 ) >= $seen - 3 * 86400 ) {
			$add( 'hn_new', (string) ( $row['path'] ?? '' ), 'hn' . $id, (int) ( $row['points'] ?? 0 ), (int) ( $row['comments'] ?? 0 ), 0, array( 'hn' => $row ) );
		}
		if ( (int) ( $row['rank'] ?? 0 ) > 0 ) {
			$add( 'hn_front', (string) ( $row['path'] ?? '' ), 'hn' . $id, (int) $row['rank'], 0, 0, array( 'hn' => $row ) );
		}
	}
	// A cache call nothing could retry (inc/cloudflare-purge-send.php). Keyed
	// on the failure's own time, so one failure mails once and the next mails.
	$cache = $in['cache'] ?? null;
	if ( is_array( $cache ) && ! empty( $cache['time'] ) ) {
		$add( 'cache', (string) ( $cache['what'] ?? '' ), gmdate( 'Y-m-d H:i:s', (int) $cache['time'] ), (int) ( $cache['http'] ?? 0 ), (int) ( $cache['attempts'] ?? 1 ), 0 );
	}
	return $found;
}

/**
 * The UTC days whose 5xx were NOT read, so no rows is not "no errors". PURE.
 * A day the edge rollup marked failed is unread. A day it never marked is
 * pending, and overdue once two days old; yesterday may simply not have run
 * yet. An empty map means the bookkeeping never ran (edge not set up): quiet.
 *
 * @param array<string,string> $read The edge rollup's map: day => '' (read) or its refusal.
 * @param int                  $now  Unix time.
 * @return array<string,string> Day => why it is unread.
 */
function snt_alerts_edge_unread( array $read, $now ) {
	$out = array();
	foreach ( array( 1, 2 ) as $back ) {
		$day = gmdate( 'Y-m-d', (int) $now - $back * 86400 );
		if ( '' !== (string) ( $read[ $day ] ?? '' ) ) {
			$out[ $day ] = 'failed: ' . snt_alerts_clean( $read[ $day ] );
		} elseif ( 2 === $back && $read && ! array_key_exists( $day, $read ) ) {
			$out[ $day ] = 'never read (the daily edge rollup is overdue)';
		}
	}
	return $out;
}

/**
 * Sent stamps with the old ones dropped, so the option stays bounded.
 *
 * @param array<string,int> $sent Key => unix time.
 * @param int               $now  Unix time.
 * @return array<string,int>
 */
function snt_alerts_prune( array $sent, $now ) {
	$cut = (int) $now - SNT_ALERT_KEEP_DAYS * 86400;
	return array_filter( array_map( 'intval', $sent ), static fn( $at ) => $at >= $cut );
}

/**
 * The email. Short: what fired, the numbers, the baseline, where to look.
 *
 * @param array<int,array<string,mixed>> $alerts  From snt_alerts_evaluate().
 * @param array<int,array<string,mixed>>|null $sources Today's top sources (value, views); null when the read FAILED.
 * @param string                         $site    Site name.
 * @param string                         $where   The dashboard URL.
 * @return array{0:string,1:string} Subject, body.
 */
function snt_alerts_compose( array $alerts, $sources, $site, $where ) {
	$lines  = array();
	$spikes = 0;
	$caches = 0;
	$hns    = 0;
	foreach ( $alerts as $a ) {
		$path = snt_alerts_clean( $a['path'] );
		if ( 'hn_new' === $a['kind'] || 'hn_front' === $a['kind'] ) {
			++$hns;
			$row     = (array) ( $a['hn'] ?? array() );
			$what    = 'hn_front' === $a['kind'] ? sprintf( 'is on the Hacker News front page at #%d', (int) $a['value'] ) : 'was posted to Hacker News';
			$lines[] = sprintf( 'HACKER NEWS: "%s" (%s) %s: %s. https://news.ycombinator.com/item?id=%d', snt_alerts_clean( $row['title'] ?? '' ), $path, $what, function_exists( 'sn_hn_line' ) ? sn_hn_line( $row ) : '', (int) ( $row['id'] ?? 0 ) );
			continue;
		}
		if ( 'cache' === $a['kind'] ) {
			++$caches;
			$lines[] = sprintf( 'CACHE: Cloudflare did not accept a cache refresh (%s) at %s UTC: HTTP %d after %d %s. Pages may be stale until the next refresh succeeds. Check the Cloudflare token in Connections, Cloudflare.', $path, $a['day'], $a['value'], $a['baseline'], 1 === (int) $a['baseline'] ? 'try' : 'tries' );
			continue;
		}
		if ( 'break' === $a['kind'] ) {
			$lines[] = sprintf( 'BREAK: %s answered a server error %d times on %s (UTC), in the stored edge 5xx rollup. The alert line is %d.', $path, $a['value'], $a['day'], $a['line'] );
			continue;
		}
		++$spikes;
		$lines[] = sprintf( 'SPIKE: %s has %d human views today (%s). The prior 7-day mean is %s a day; the alert line is more than %s.', 'spike_site' === $a['kind'] ? 'the whole site' : $path, $a['value'], $a['day'], $a['baseline'], round( $a['line'], 1 ) )
			. ( ! empty( $a['hn'] ) && function_exists( 'sn_hn_line' ) ? ' On Hacker News: ' . sn_hn_line( (array) $a['hn'] ) . '.' : '' );
	}
	$body = "Signal & Noise alert\n\n" . implode( "\n", $lines ) . "\n";
	if ( $spikes > 0 ) {
		$top = array();
		foreach ( array_slice( (array) $sources, 0, 5 ) as $s ) {
			$top[] = snt_alerts_clean( $s['value'] ?? '' ) . ' ' . (int) ( $s['views'] ?? 0 );
		}
		$none  = null === $sources ? 'Top sources could not be read just now (the stored read failed, which is not the same as none): see the dashboard.' : 'No sources are stored for today yet.';
		$body .= "\n" . ( $top ? 'Top sources today, site-wide (views): ' . implode( ', ', $top ) . '.' : $none );
		$body .= "\nVisits with no referrer (apps, RSS readers, privacy browsers) show as direct.\n";
	}
	$body .= "\nWhere to look: " . $where . "\nEach alert mails once for its day. Switch alerts off in Connections, Cron.\n";
	$breaks = count( $alerts ) - $spikes - $caches - $hns;
	$what   = array_filter( array( $spikes ? $spikes . ( 1 === $spikes ? ' spike' : ' spikes' ) : '', $breaks ? $breaks . ( 1 === $breaks ? ' break' : ' breaks' ) : '', $caches ? 'cache refresh failed' : '', $hns ? 'Hacker News' : '' ) );
	return array( sprintf( '[%s] Alert: %s', $site, implode( ', ', $what ) ), $body );
}
