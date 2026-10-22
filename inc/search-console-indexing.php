<?php
/**
 * Signal & Noise Tools: the indexing guard, the PURE half.
 *
 * Three lists over data the plugin already stores, so the owner knows which
 * URLs to open in Search Console and click Request indexing (the API cannot,
 * and nothing here uses the Indexing API or automates the click):
 *
 *   request_list  in-scope URLs not indexed 7+ days after publish, ranked:
 *                 "Crawled - currently not indexed" first, then core pages,
 *                 then everything else oldest first. Capped at 10.
 *   stuck         the same question at 30 days, capped at 25.
 *   publish_check notes and core pages published 3+ days ago with fewer
 *                 than two inbound links from indexed pages, out of the
 *                 sitemap, or with a canonical pointing elsewhere. A warning
 *                 list; nothing is blocked.
 *
 * In scope is the index_gap candidate (snt_gsc_index_gap_candidate(): a
 * published note or page, never a tag archive, and indexable by the one
 * predicate in inc/indexable.php, so /verify and /call never appear). The
 * day line is snt_gsc_index_stuck(), the same function the 14-day watch reads.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/search-console-index-watch.php';

const SNT_INDEXING_REQUEST_DAYS = 7;
const SNT_INDEXING_REQUEST_CAP  = 10;
const SNT_INDEXING_STUCK_DAYS   = 30;
const SNT_INDEXING_STUCK_CAP    = 25;
const SNT_INDEXING_CHECK_DAYS   = 3;
const SNT_INDEXING_MIN_INBOUND  = 2;

/**
 * The core pages, by join path: these exact paths, plus everything under the
 * two prefixes (/maturity/* and /provenance/*). Their Spanish twins count
 * through sn_indexing_is_core()'s second argument.
 *
 * @return array{paths:string[],prefixes:string[]}
 */
function sn_indexing_core_pages() {
	return array(
		'paths'    => array( '/about', '/resume', '/workflow', '/services', '/contact', '/now', '/about/uses', '/colophon' ),
		'prefixes' => array( '/maturity', '/provenance' ),
	);
}

/**
 * Is this a core page? PURE.
 *
 * @param string $path    A path or URL.
 * @param string $en_path For a Spanish twin, its English page's path (from _sn_en_counterpart).
 * @return bool
 */
function sn_indexing_is_core( $path, $en_path = '' ) {
	$core = sn_indexing_core_pages();
	foreach ( array_filter( array( (string) $path, (string) $en_path ) ) as $p ) {
		$key = sn_path_join_key( $p );
		if ( in_array( $key, $core['paths'], true ) ) {
			return true;
		}
		foreach ( $core['prefixes'] as $prefix ) {
			if ( $key === $prefix || 0 === strpos( $key, $prefix . '/' ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Inbound links from pages Google has indexed. PURE. null when the link graph
 * holds no node for the path (unknown), never a guessed zero.
 *
 * @param string     $path    Join path.
 * @param array|null $inbound snt_ml_inbound_by_path().
 * @param array      $entries Coverage map entries.
 * @return int|null
 */
function sn_indexing_inbound_indexed( $path, $inbound, $entries ) {
	if ( ! is_array( $inbound ) || ! isset( $inbound[ $path ] ) ) {
		return null;
	}
	$n = 0;
	foreach ( (array) ( $inbound[ $path ]['linked_from_paths'] ?? array() ) as $src ) {
		$n += true === ( $entries[ $src ]['indexed'] ?? null ) ? 1 : 0;
	}
	return $n;
}

/**
 * Stuck rows as report rows, ranked. PURE.
 *
 * @param array      $stuck    snt_gsc_index_stuck() rows.
 * @param array      $entries  Coverage map entries.
 * @param array|null $inbound  snt_ml_inbound_by_path().
 * @param array      $core     post_id => bool.
 * @param string     $property Search Console property.
 * @return array<int,array>
 */
function sn_indexing_rows( $stuck, $entries, $inbound, $core, $property ) {
	$rows = array();
	foreach ( (array) $stuck as $r ) {
		$rows[] = array(
			'url'             => (string) $r['url'],
			'path'            => (string) $r['path'],
			'coverage_state'  => (string) $r['state'],
			'days'            => (int) $r['days'],
			'core'            => ! empty( $core[ (int) ( $r['post_id'] ?? 0 ) ] ),
			'inbound_indexed' => sn_indexing_inbound_indexed( (string) $r['path'], $inbound, $entries ),
			'inspect_url'     => snt_gsc_inspect_link( $property, (string) $r['url'] ),
		);
	}
	// Tier: crawled and declined first (Google saw it and said no), then core
	// pages, then the rest; oldest first inside a tier, path as the tiebreak.
	$tier = static function ( $row ) {
		return 0 === stripos( $row['coverage_state'], 'crawled - currently not indexed' ) ? 0 : ( $row['core'] ? 1 : 2 );
	};
	usort( $rows, static function ( $a, $b ) use ( $tier ) {
		return array( $tier( $a ), $b['days'], $a['path'] ) <=> array( $tier( $b ), $a['days'], $b['path'] );
	} );
	return $rows;
}

/**
 * The publish check over notes and core pages. PURE. A failure lists its
 * reasons: inbound (fewer than two links from indexed pages), sitemap (the
 * indexable predicate refuses it), canonical (it points elsewhere). The
 * inbound leg is not judged before the coverage map exists.
 *
 * @param array      $subjects Rows {post_id, path, url, type, published, indexable, canonical, core}.
 * @param array      $entries  Coverage map entries.
 * @param array|null $inbound  snt_ml_inbound_by_path().
 * @param int        $now
 * @return array{checked:int,inbound_judged:bool,failures:array}
 */
function sn_indexing_publish_check( $subjects, $entries, $inbound, $now ) {
	$judged   = array() !== (array) $entries && is_array( $inbound );
	$checked  = 0;
	$failures = array();
	foreach ( (array) $subjects as $s ) {
		if ( ( 'post' !== $s['type'] && empty( $s['core'] ) ) || ( (int) $now - (int) $s['published'] ) < SNT_INDEXING_CHECK_DAYS * DAY_IN_SECONDS ) {
			continue;
		}
		++$checked;
		$in      = sn_indexing_inbound_indexed( (string) $s['path'], $inbound, (array) $entries );
		$reasons = array();
		if ( $judged && ( null === $in || $in < SNT_INDEXING_MIN_INBOUND ) ) {
			$reasons[] = 'inbound';
		}
		if ( empty( $s['indexable'] ) ) {
			$reasons[] = 'sitemap';
		}
		if ( '' !== trim( (string) $s['canonical'] ) ) {
			$reasons[] = 'canonical';
		}
		if ( $reasons ) {
			$failures[] = array( 'path' => (string) $s['path'], 'url' => (string) $s['url'], 'post_type' => (string) $s['type'], 'inbound_indexed' => $in, 'reasons' => $reasons );
		}
	}
	return array( 'checked' => $checked, 'inbound_judged' => $judged, 'failures' => $failures );
}

/**
 * The whole report from its inputs. PURE.
 *
 * @param array      $entries  Coverage map entries.
 * @param array      $subjects Published notes and pages (see sn_indexing_publish_check()).
 * @param array|null $inbound  snt_ml_inbound_by_path().
 * @param int        $now
 * @param string     $property Search Console property.
 * @return array
 */
function sn_indexing_build( $entries, $subjects, $inbound, $now, $property ) {
	$published = array();
	$core      = array();
	foreach ( (array) $subjects as $s ) {
		$published[ (int) $s['post_id'] ] = (int) $s['published'];
		$core[ (int) $s['post_id'] ]      = ! empty( $s['core'] );
	}
	$request = sn_indexing_rows( snt_gsc_index_stuck( $entries, $published, $now, SNT_INDEXING_REQUEST_DAYS ), $entries, $inbound, $core, $property );
	$stuck   = sn_indexing_rows( snt_gsc_index_stuck( $entries, $published, $now, SNT_INDEXING_STUCK_DAYS ), $entries, $inbound, $core, $property );
	return array(
		'request_list'  => array_slice( $request, 0, SNT_INDEXING_REQUEST_CAP ),
		'request_total' => count( $request ),
		'stuck'         => array_slice( $stuck, 0, SNT_INDEXING_STUCK_CAP ),
		'stuck_total'   => count( $stuck ),
		'publish_check' => sn_indexing_publish_check( $subjects, $entries, $inbound, $now ),
	);
}
