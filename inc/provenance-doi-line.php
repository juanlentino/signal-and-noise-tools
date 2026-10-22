<?php
/**
 * The DOI downloads proof line on /provenance (2026-10-21): how many site
 * documents carry a DOI and how often Zenodo has served them, all time and
 * over the last 30 days.
 *
 * Built on the north star's existing Zenodo sync (inc/north-star-return.php):
 * the same daily fetch also stores a site-only total per day, counting only
 * the records whose DOI belongs to a site document (the ORCID also returns
 * duplicate deposits and records that are not the site's). The page renders
 * from stored snapshots alone, never calling Zenodo, and hides rather than
 * show a number it cannot stand behind: no snapshot, a snapshot older than
 * 48 hours, or one from before the site split. Settings live on Connections
 * › Zenodo (both surfaces).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_PROV_DOI_OPT = 'sn_prov_doi_line';

/**
 * Add the site-only figures to a fetch. Without a known site DOI none is
 * stored, so the line hides rather than reading zero. PURE.
 *
 * Matched on the version DOI the deposit stored; the site never re-versions
 * a record, so the version Zenodo's search returns is that one.
 *
 * @param array    $sum       snt_nsm_zenodo_fetch().
 * @param string[] $site_dois Production DOIs of site documents.
 * @return array $sum plus site_downloads and site_records when computable.
 */
function snt_nsm_zenodo_with_site( array $sum, array $site_dois ) {
	if ( array() === $site_dois ) {
		return $sum;
	}
	$mine = array_intersect_key( (array) ( $sum['by_doi'] ?? array() ), array_flip( $site_dois ) );
	return $sum + array( 'site_downloads' => (int) array_sum( $mine ), 'site_records' => count( $mine ) );
}

/**
 * Every published document's production DOI (a sandbox DOI never counts).
 *
 * @return string[]
 */
function snt_nsm_zenodo_site_dois() {
	if ( ! function_exists( 'sn_zenodo_doi_for' ) || ! defined( 'SN_ZENODO_DOI_META' ) ) {
		return array();
	}
	$ids  = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'meta_key' => SN_ZENODO_DOI_META, 'fields' => 'ids', 'numberposts' => -1, 'no_found_rows' => true ) );
	$dois = array();
	foreach ( (array) $ids as $id ) {
		$doi = sn_zenodo_doi_for( (int) $id );
		if ( '' !== $doi ) {
			$dois[] = $doi;
		}
	}
	return array_values( array_unique( $dois ) );
}

/**
 * The owner's settings: on by default, an optional lead label, and a floor
 * under which the 30-day figure is left out.
 *
 * @return array{enabled:bool,label:string,floor:int}
 */
function snt_prov_doi_settings() {
	$s = (array) get_option( SNT_PROV_DOI_OPT, array() );
	return array(
		'enabled' => (bool) ( $s['enabled'] ?? true ),
		'label'   => (string) ( $s['label'] ?? '' ),
		'floor'   => max( 0, (int) ( $s['floor'] ?? 0 ) ),
	);
}

/**
 * The figures the line shows, from stored snapshots. PURE. Null hides the line.
 *
 * @param array  $snaps date => snapshot (snt_nsm_zenodo_snapshot()).
 * @param string $today Y-m-d (UTC).
 * @param array  $cfg   snt_prov_doi_settings().
 * @return array{documents:int,all_time:int,last_30:int|null}|null
 */
function snt_prov_doi_reading( array $snaps, $today, array $cfg ) {
	if ( empty( $cfg['enabled'] ) || array() === $snaps ) {
		return null;
	}
	ksort( $snaps );
	$date   = (string) array_key_last( $snaps );
	$latest = (array) $snaps[ $date ];
	$at     = strtotime( $date . ' 00:00:00 UTC' );
	$age    = false === $at ? -1 : ( strtotime( $today . ' 00:00:00 UTC' ) - $at ) / 86400;
	// Day precision: a snapshot from two days back is at most 48 hours old.
	if ( $age < 0 || $age > 2 || ! isset( $latest['site_downloads'], $latest['site_records'] ) || (int) $latest['site_records'] < 1 ) {
		return null;
	}
	$out  = array( 'documents' => (int) $latest['site_records'], 'all_time' => (int) $latest['site_downloads'], 'last_30' => null );
	$back = gmdate( 'Y-m-d', $at - 30 * 86400 );
	// Same documents on both days, or the difference would count a new
	// document's lifetime downloads as if they happened in the window.
	if ( isset( $snaps[ $back ]['site_downloads'], $snaps[ $back ]['site_records'] ) && (int) $snaps[ $back ]['site_records'] === $out['documents'] ) {
		$diff = $out['all_time'] - (int) $snaps[ $back ]['site_downloads'];
		// A drop is not a count of downloads; below the floor the owner chose, all time alone.
		if ( $diff >= 0 && $diff >= (int) ( $cfg['floor'] ?? 0 ) ) {
			$out['last_30'] = $diff;
		}
	}
	return $out;
}

/**
 * The line's markup. Counts are exact; the source links to Zenodo's search
 * for the author ORCID (there is no Zenodo community).
 *
 * @param array $r   snt_prov_doi_reading().
 * @param array $cfg snt_prov_doi_settings().
 * @return string
 */
function snt_prov_doi_line_html( array $r, array $cfg ) {
	$docs  = (int) $r['documents'];
	$all   = (int) $r['all_time'];
	/* translators: %d: site documents with a DOI. */
	$parts = array( sprintf( _n( '%d document with a DOI', '%d documents with DOIs', $docs, 'signal-and-noise-tools' ), $docs ) );
	/* translators: %d: Zenodo unique downloads, all time. */
	$parts[] = sprintf( _n( '%d download all time', '%d downloads all time', $all, 'signal-and-noise-tools' ), $all );
	if ( null !== $r['last_30'] ) {
		/* translators: %d: Zenodo unique downloads over the last 30 days. */
		$parts[] = sprintf( __( '%d in the last 30 days', 'signal-and-noise-tools' ), (int) $r['last_30'] );
	}
	$url   = 'https://zenodo.org/search?q=' . rawurlencode( 'creators.orcid:"' . SN_ZENODO_ORCID . '"' );
	$label = trim( (string) ( $cfg['label'] ?? '' ) );
	return '<p class="sn-doi-proof">'
		. ( '' !== $label ? '<span class="sn-doi-proof__label">' . esc_html( $label ) . '</span> ' : '' )
		. esc_html( implode( ' · ', $parts ) )
		. ' (<a href="' . esc_url( $url ) . '">Zenodo</a>)</p>';
}

/**
 * The line as it renders now, '' when hidden. Reads options only.
 *
 * @return string
 */
function snt_prov_doi_line() {
	$cfg = snt_prov_doi_settings();
	$r   = snt_prov_doi_reading( defined( 'SNT_NSM_ZENODO_OPT' ) ? (array) get_option( SNT_NSM_ZENODO_OPT, array() ) : array(), gmdate( 'Y-m-d' ), $cfg );
	return null === $r ? '' : snt_prov_doi_line_html( $r, $cfg );
}

/**
 * After /provenance's own content. Priority 22: after the signed-page badge
 * (20, which steps aside when the content already carries an `sn-prov-` class)
 * and the citation (21).
 *
 * @param string $content Post content.
 * @return string
 */
function snt_prov_doi_append( $content ) {
	if ( ! defined( 'SN_PROVENANCE_SLUG' ) || is_feed() || ! is_page( SN_PROVENANCE_SLUG ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	return $content . snt_prov_doi_line();
}
add_filter( 'the_content', 'snt_prov_doi_append', 22 );

/**
 * Purge /provenance at the edge when its line changed, so a cached page
 * neither keeps a stale figure nor ignores the owner switching it off.
 *
 * @param string $before The line before the change.
 */
function snt_prov_doi_purge_if_changed( $before ) {
	if ( $before !== snt_prov_doi_line() && defined( 'SN_PROVENANCE_SLUG' ) && function_exists( 'sn_cf_purge_urls' ) ) {
		sn_cf_purge_urls( array( home_url( '/' . SN_PROVENANCE_SLUG . '/' ) ) );
	}
}
