<?php
/**
 * Signal & Noise — one-time Plausible-CSV → first-party rollup importer (v6.0.0).
 *
 * When Plausible is retired (this release), its history would be lost — the
 * first-party edge pipeline only began accruing once the worker deployed. This
 * back-fills the durable rollup tables from Plausible's CSV exports so the
 * comprehensive dashboard carries the full timeline.
 *
 * Pure, testable mapping: each CSV type → the EXACT row shape the existing
 * idempotent upserts (sn_analytics_rollup_upsert / sn_analytics_dims_upsert)
 * already validate + write. The importer never writes SQL itself. Historical
 * labels are normalized into the first-party vocabulary (Microsoft Edge → Edge,
 * Mac → macOS, Desktop → desktop) so old + new merge into one bucket.
 *
 * What maps: pages → wp_sn_analytics_daily; sources/locations/devices/browsers/
 * operating_systems → wp_sn_analytics_dims (referrer/country/device/browser/os).
 * What can't (no Plausible source): the hour heatmap + scroll/time distributions
 * (daily + summed, not hourly/bucketed) and network/colo/protocol/tls dims.
 *
 * No-clobber: Plausible's export ends the day before the worker went live, and
 * the rollup cron only UPSERTs days Analytics Engine returns rows for, so an
 * imported pre-worker day is never overwritten.
 *
 * @package SignalNoiseTools
 * @since 6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The CSV types this importer understands → a human label for the upload UI. */
function sn_analytics_import_types() {
	return array(
		'pages'             => 'Pages (top pages, scroll, time)',
		'sources'           => 'Sources (referrers)',
		'locations'         => 'Locations (countries)',
		'devices'           => 'Devices',
		'browsers'          => 'Browsers',
		'operating_systems' => 'Operating systems',
	);
}

/**
 * Parse a CSV string into header-keyed associative rows. Uses fgetcsv (handles
 * quoted commas) via an in-memory stream. Values are returned as strings.
 *
 * @param string $content
 * @return array<int, array<string, string>>
 */
function sn_analytics_import_parse_csv( $content ) {
	$content = (string) $content;
	if ( '' === trim( $content ) ) {
		return array();
	}
	$fh = fopen( 'php://temp', 'r+' );
	fwrite( $fh, $content );
	rewind( $fh );

	// Explicit args incl. $escape='' (RFC 4180; Plausible quote-doubles) so PHP 8.3+
	// doesn't deprecation-warn on the default escape and 8.4's default-change is moot.
	$header = fgetcsv( $fh, null, ',', '"', '' );
	if ( ! is_array( $header ) ) {
		fclose( $fh );
		return array();
	}
	$header = array_map( 'strval', $header );

	$out = array();
	while ( ( $row = fgetcsv( $fh, null, ',', '"', '' ) ) !== false ) {
		if ( ! is_array( $row ) || ( 1 === count( $row ) && ( null === $row[0] || '' === $row[0] ) ) ) {
			continue;
		}
		$assoc = array();
		foreach ( $header as $i => $key ) {
			$assoc[ $key ] = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
		}
		$out[] = $assoc;
	}
	fclose( $fh );
	return $out;
}

/** Plausible device label → first-party device vocab ('' = unknown). */
function sn_analytics_import_norm_device( $v ) {
	$v = strtolower( trim( (string) $v ) );
	if ( 'desktop' === $v ) {
		return 'desktop';
	}
	if ( 'mobile' === $v || 'tablet' === $v ) {
		return 'mobile';
	}
	return '';
}

/** Plausible browser label → first-party browserFrom() vocab (passthrough for matches). */
function sn_analytics_import_norm_browser( $v ) {
	$v   = trim( (string) $v );
	$map = array(
		'Microsoft Edge'  => 'Edge',
		'Samsung Browser' => 'Samsung Internet',
		'Mobile App'      => '',
	);
	return array_key_exists( $v, $map ) ? $map[ $v ] : $v;
}

/** Plausible OS label → first-party osFrom() vocab (passthrough for matches). */
function sn_analytics_import_norm_os( $v ) {
	$v   = trim( (string) $v );
	$map = array(
		'Mac'       => 'macOS',
		'GNU/Linux' => 'Linux',
		'Ubuntu'    => 'Linux',
	);
	return array_key_exists( $v, $map ) ? $map[ $v ] : $v;
}

/** A Plausible referrer (host, or URL/app-uri) → bare lowercase host (matches the worker's hostOf). */
function sn_analytics_import_norm_referrer( $v ) {
	$v = trim( (string) $v );
	if ( '' === $v ) {
		return '';
	}
	$pos = strpos( $v, '://' );
	if ( false !== $pos ) {
		$v = substr( $v, $pos + 3 );
	}
	$v = preg_replace( '#[/?].*$#', '', $v ); // strip path/query → host
	return strtolower( (string) $v );
}

/**
 * Map an `imported_pages` CSV into wp_sn_analytics_daily rows. views=pageviews,
 * visits=visitors (uniques), scroll_avg = total_scroll/scroll_visits,
 * time_avg(ms) = (total_time/time_visits)*1000 (Plausible stores seconds).
 * Admin/login paths are skipped (they never fire the front-end beacon).
 *
 * @param array $rows Parsed CSV rows.
 * @return array Upsert-ready daily rows.
 */
function sn_analytics_import_pages_rows( $rows ) {
	$out = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day  = isset( $r['date'] ) ? trim( (string) $r['date'] ) : '';
		$path = isset( $r['page'] ) ? trim( (string) $r['page'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $path ) {
			continue;
		}
		if ( 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-login.php' ) ) {
			continue;
		}
		$scroll_visits = (float) ( $r['total_scroll_depth_visits'] ?? 0 );
		$time_visits   = (float) ( $r['total_time_on_page_visits'] ?? 0 );
		$out[] = array(
			'day'        => $day,
			'path'       => $path,
			'class'      => 'human',
			'views'      => max( 0, (int) ( $r['pageviews'] ?? 0 ) ),
			'visits'     => max( 0, (int) ( $r['visitors'] ?? 0 ) ),
			'scroll_avg' => $scroll_visits > 0 ? ( (float) ( $r['total_scroll_depth'] ?? 0 ) / $scroll_visits ) : 0.0,
			'time_avg'   => $time_visits > 0 ? ( (float) ( $r['total_time_on_page'] ?? 0 ) / $time_visits ) * 1000.0 : 0.0,
		);
	}
	return $out;
}

/**
 * Map a Plausible dimension CSV into wp_sn_analytics_dims rows for one $dim,
 * taking the value from $col and optionally normalizing it. views=pageviews,
 * visits=visitors.
 *
 * @param array         $rows
 * @param string        $dim   Target first-party dim.
 * @param string        $col   CSV column holding the value.
 * @param callable|null $norm  Optional value normalizer.
 * @return array Upsert-ready dims rows.
 */
function sn_analytics_import_dim_rows( $rows, $dim, $col, $norm = null ) {
	$out = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day = isset( $r['date'] ) ? trim( (string) $r['date'] ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			continue;
		}
		$value = isset( $r[ $col ] ) ? (string) $r[ $col ] : '';
		if ( $norm && is_callable( $norm ) ) {
			$value = (string) call_user_func( $norm, $value );
		}
		$out[] = array(
			'day'    => $day,
			'dim'    => $dim,
			'value'  => $value,
			'class'  => 'human',
			'views'  => max( 0, (int) ( $r['pageviews'] ?? 0 ) ),
			'visits' => max( 0, (int) ( $r['visitors'] ?? 0 ) ),
		);
	}
	return $out;
}

/**
 * Map parsed rows of a known CSV $type to upsert-ready rows.
 *
 * @param string $type
 * @param array  $rows
 * @return array{table:string, dim?:string, rows:array}
 */
function sn_analytics_import_map( $type, $rows ) {
	if ( 'pages' === $type ) {
		return array( 'table' => 'daily', 'rows' => sn_analytics_import_pages_rows( $rows ) );
	}
	// type => [dim, value column, normalizer].
	$cfg = array(
		'sources'           => array( 'referrer', 'referrer', 'sn_analytics_import_norm_referrer' ),
		'locations'         => array( 'country', 'country', null ),
		'devices'           => array( 'device', 'device', 'sn_analytics_import_norm_device' ),
		'browsers'          => array( 'browser', 'browser', 'sn_analytics_import_norm_browser' ),
		'operating_systems' => array( 'os', 'operating_system', 'sn_analytics_import_norm_os' ),
	);
	if ( ! isset( $cfg[ $type ] ) ) {
		return array( 'table' => 'unknown', 'dim' => '', 'rows' => array() );
	}
	list( $dim, $col, $norm ) = $cfg[ $type ];
	return array( 'table' => 'dims', 'dim' => $dim, 'rows' => sn_analytics_import_dim_rows( $rows, $dim, $col, $norm ) );
}

/**
 * Orchestrate: read each provided CSV file ($files = [type => path]), parse,
 * map, and feed the existing idempotent upserts. Returns a per-table/-dim count
 * report; an unreadable or unknown file is recorded, never fatal.
 *
 * @param array<string, string> $files
 * @return array{daily:int, dims:array<string,int>, skipped:array<string,string>}
 */
function sn_analytics_import_run( $files ) {
	$report = array( 'daily' => 0, 'dims' => array(), 'skipped' => array() );
	if ( ! is_array( $files ) ) {
		return $report;
	}
	foreach ( $files as $type => $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			$report['skipped'][ $type ] = 'unreadable';
			continue;
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			$report['skipped'][ $type ] = 'unreadable';
			continue;
		}
		$mapped = sn_analytics_import_map( $type, sn_analytics_import_parse_csv( $content ) );

		if ( 'daily' === $mapped['table'] ) {
			if ( ! empty( $mapped['rows'] ) && function_exists( 'sn_analytics_rollup_upsert' ) ) {
				$report['daily'] += (int) sn_analytics_rollup_upsert( $mapped['rows'] );
			}
		} elseif ( 'dims' === $mapped['table'] ) {
			if ( ! empty( $mapped['rows'] ) && function_exists( 'sn_analytics_dims_upsert' ) ) {
				$dim                    = $mapped['dim'];
				$report['dims'][ $dim ] = ( $report['dims'][ $dim ] ?? 0 ) + (int) sn_analytics_dims_upsert( $mapped['rows'] );
			}
		} else {
			$report['skipped'][ $type ] = 'unknown_type';
		}
	}
	return $report;
}
