<?php
/**
 * Signal & Noise — the Posts tab's DATA LAYER: one row per published note,
 * built from the dense per-note signals the site already syncs. (v14.6.0)
 *
 * WHY THIS REPLACES THE LIFECYCLE VIEW. The tab used to classify every note
 * as sustained / cooling / spike from human pageviews, the thinnest signal
 * the site collects: measured 2026-09-13, the highest lifetime count across
 * 41 notes was 14, and notes were being classified on samples of 6 and 7.
 * Meanwhile Search Console metrics, URL Inspection coverage, the internal
 * link graph, the provenance chain and the ML kernel were all stored per
 * note and surfaced nowhere on this tab. This file reads those; pageviews
 * ride as a raw number.
 *
 * THREE RULES, enforced here and nowhere else:
 *  1. A MISSING SIGNAL IS NULL, WITH A REASON. Never zero, never a fallback
 *     that reads as a pass. Each row field is {value, why} where why is ''
 *     when the value is real and a short reason when it is null.
 *  2. THE THREE FLAGS ARE DERIVED ONCE, in sn_analytics_posts_flags(). True
 *     binaries, no sample size: not_indexed, stale_crawl, orphaned.
 *  3. MACHINE READS NEVER GO PER NOTE. The crawler sensor keeps no document
 *     paths by its privacy contract; the site-wide figure rides the header
 *     strip only (sn_analytics_posts_strip()).
 *
 * The note dossier reads the same functions for one note (snt_gsc_*,
 * sn_note_dossier_anchored_commit, snt_ml_inbound_by_path, ...), so the tab
 * and the dossier cannot disagree about the same row.
 *
 * @package signal-and-noise-tools
 * @since 14.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Inbound links at or below this = orphaned. One threshold, one place. */
const SN_POSTS_ORPHAN_MAX_INBOUND = 1;

/**
 * A field: a real value with no reason, or null with the reason it is null.
 *
 * @param mixed  $value
 * @param string $why   Only when $value is null.
 * @return array{value:mixed,why:string}
 */
function sn_posts_field( $value, $why = '' ) {
	return array( 'value' => $value, 'why' => null === $value ? (string) $why : '' );
}

/**
 * The published notes this tab covers: posts in the note category, oldest
 * first (the table sorts client-side; a stable server order keeps parity pins
 * honest).
 *
 * @return WP_Post[]
 */
function sn_analytics_posts_catalogue() {
	if ( ! function_exists( 'get_posts' ) ) {
		return array();
	}
	$posts = get_posts( array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => SN_POSTS_LIFECYCLE_MAX,
		'orderby'             => 'date',
		'order'               => 'ASC',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	) );
	$out = array();
	foreach ( (array) $posts as $p ) {
		if ( is_object( $p ) && ( ! function_exists( 'sn_prov_is_note' ) || sn_prov_is_note( (int) $p->ID ) ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

/**
 * The site-wide context every row shares: the Search Console window, the
 * coverage run, the inbound graph, the kernel build, the machine-read
 * snapshot. Read ONCE per tab paint, never per row.
 *
 * @return array<string,mixed>
 */
function sn_analytics_posts_context() {
	$gsc = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	$cov = function_exists( 'snt_gsc_coverage_data' ) ? snt_gsc_coverage_data() : null;
	$tot = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
	return array(
		'now'          => function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time(),
		'gsc'          => is_array( $gsc ) ? $gsc : null,
		'gsc_capped'   => is_array( $tot ) && ! empty( $tot['capped'] ),
		'coverage'     => is_array( $cov ) ? $cov : null,
		'inbound'      => function_exists( 'snt_ml_inbound_by_path' ) ? snt_ml_inbound_by_path() : null,
		'kernel_built' => function_exists( 'snt_ml_related_for_post' ) && is_array( get_option( defined( 'SNT_ML_CORPUS_META_OPT' ) ? SNT_ML_CORPUS_META_OPT : 'snt_ml_corpus_meta', false ) ),
		'followed_key' => function_exists( 'sn_prov_key_id' ) ? (string) sn_prov_key_id() : '',
	);
}

/**
 * One note's row. Every field is sn_posts_field(); the flags come from
 * sn_analytics_posts_flags() and only from there.
 *
 * @param WP_Post             $post
 * @param array<string,mixed> $ctx  sn_analytics_posts_context().
 * @return array<string,mixed>
 */
function sn_analytics_posts_row( $post, array $ctx ) {
	$id        = (int) $post->ID;
	$permalink = (string) get_permalink( $post );
	$key       = function_exists( 'sn_path_join_key' ) ? (string) sn_path_join_key( $permalink ) : '';
	$publish   = (int) get_post_time( 'U', true, $post );
	$modified  = (int) get_post_modified_time( 'U', true, $post );
	$now       = (int) $ctx['now'];

	// ── Editorial ────────────────────────────────────────────────────────
	$words = function_exists( 'snt_corpus_word_count' ) ? sn_posts_field( (int) snt_corpus_word_count( (string) $post->post_content ) ) : sn_posts_field( null, 'counter not loaded' );
	$age   = sn_posts_field( $publish > 0 ? max( 0, (int) floor( ( $now - $publish ) / DAY_IN_SECONDS ) ) : null, 'no publish date' );

	// ── Search Console metrics: the sync's own window ────────────────────
	if ( null === $ctx['gsc'] ) {
		$impr = sn_posts_field( null, 'never synced' );
		$clicks = $impr;
		$pos    = $impr;
	} else {
		$m = ( '' !== $key && function_exists( 'snt_gsc_metrics_for_path' ) ) ? snt_gsc_metrics_for_path( $key ) : null;
		if ( ! is_array( $m ) ) {
			$why    = $ctx['gsc_capped'] ? 'not among the rows the sync keeps' : 'not shown by Google in this window';
			$impr   = sn_posts_field( null, $why );
			$clicks = sn_posts_field( null, $why );
			$pos    = sn_posts_field( null, $why );
		} else {
			$impr   = sn_posts_field( (int) ( $m['impressions'] ?? 0 ) );
			$clicks = sn_posts_field( (int) ( $m['clicks'] ?? 0 ) );
			$pos    = sn_posts_field( round( (float) ( $m['position'] ?? 0 ), 1 ) );
		}
	}

	// ── Coverage: the weekly URL Inspection run ──────────────────────────
	// `indexed` is the store's own tri-state: true / false / null (Google gave
	// no coverage state). Null stays null: it is not a guess either way.
	$e = null;
	if ( null === $ctx['coverage'] ) {
		$index     = sn_posts_field( null, 'coverage inspection has never run' );
		$crawl     = sn_posts_field( null, 'coverage inspection has never run' );
		$cov_state = '';
	} else {
		$e = ( '' !== $key && isset( $ctx['coverage']['entries'][ $key ] ) && is_array( $ctx['coverage']['entries'][ $key ] ) ) ? $ctx['coverage']['entries'][ $key ] : null;
		if ( null === $e ) {
			$why       = empty( $ctx['coverage']['complete'] ) ? 'not yet inspected: a run is in progress' : 'not inspected in the last run';
			$index     = sn_posts_field( null, $why );
			$crawl     = sn_posts_field( null, $why );
			$cov_state = '';
		} elseif ( isset( $e['error'] ) ) {
			$index     = sn_posts_field( null, 'inspection failed' );
			$crawl     = sn_posts_field( null, 'inspection failed' );
			$cov_state = '';
		} else {
			$cov_state = (string) ( $e['coverage_state'] ?? '' );
			$index     = sn_posts_field( array_key_exists( 'indexed', $e ) ? $e['indexed'] : null, 'Google gave no coverage state' );
			$ts        = '' !== (string) ( $e['last_crawl_time'] ?? '' ) ? strtotime( (string) $e['last_crawl_time'] ) : false;
			$crawl     = sn_posts_field( false !== $ts ? (int) $ts : null, 'never crawled' );
		}
	}

	// ── Inbound links: the live link graph ───────────────────────────────
	$inbound = ( is_array( $ctx['inbound'] ) && '' !== $key )
		? sn_posts_field( (int) ( $ctx['inbound'][ $key ]['inbound'] ?? 0 ) )
		: sn_posts_field( null, 'link graph not built' );

	// ── Provenance: the local chain ──────────────────────────────────────
	$chain    = function_exists( 'sn_prov_get_chain' ) ? (array) sn_prov_get_chain( $id ) : array();
	$anchored = function_exists( 'sn_note_dossier_anchored_commit' ) ? sn_note_dossier_anchored_commit( $chain ) : null;
	if ( array() === $chain ) {
		$anchor = sn_posts_field( null, 'unsigned' );
	} elseif ( null === $anchored ) {
		$anchor = sn_posts_field( null, 'signed, no confirmed anchor yet' );
	} else {
		$signer = (string) ( $anchored['pubkey_id'] ?? '' );
		$anchor = sn_posts_field( array(
			'version'      => (int) ( $anchored['version'] ?? 0 ),
			'block'        => (int) ( $anchored['bitcoin_block'] ?? 0 ),
			'followed_key' => '' !== $signer && '' !== $ctx['followed_key'] ? $signer === $ctx['followed_key'] : null,
		) );
	}

	// ── ML kernel: related notes ─────────────────────────────────────────
	if ( ! function_exists( 'snt_ml_related_for_post' ) || ! $ctx['kernel_built'] ) {
		$related = sn_posts_field( null, 'kernel not built' );
	} else {
		$rel = snt_ml_related_for_post( $id, defined( 'SNT_ML_TOP_N' ) ? SNT_ML_TOP_N : 10 );
		if ( null === $rel ) {
			$related = sn_posts_field( null, 'kernel not built' );
		} elseif ( ! is_array( get_post_meta( $id, defined( 'SNT_ML_RELATED_META' ) ? SNT_ML_RELATED_META : '_snt_ml_related', true ) ) ) {
			$related = sn_posts_field( null, 'not in the kernel yet: built before this note' );
		} else {
			$top = 0.0;
			foreach ( $rel as $r ) {
				$top = max( $top, (float) ( $r['score'] ?? 0 ) );
			}
			$related = sn_posts_field( array( 'count' => count( $rel ), 'top' => round( $top, 2 ) ) );
		}
	}

	// ── Views: the durable daily table, raw ──────────────────────────────
	$path  = function_exists( 'sn_analytics_post_path' ) ? (string) sn_analytics_post_path( $id ) : '';
	$views = ( '' !== $path && function_exists( 'sn_analytics_path_lifetime' ) ) ? sn_posts_field( (int) sn_analytics_path_lifetime( $path ) ) : sn_posts_field( null, 'analytics table not read' );

	$row = array(
		'id'             => $id,
		'title'          => (string) get_the_title( $post ),
		'permalink'      => $permalink,
		'publish_ts'     => $publish,
		'modified_ts'    => $modified,
		'age'            => $age,
		'words'          => $words,
		'index'          => $index,
		'coverage_state' => $cov_state,
		'last_crawl'     => $crawl,
		'impressions'    => $impr,
		'clicks'         => $clicks,
		'position'       => $pos,
		'inbound'        => $inbound,
		'anchor'         => $anchor,
		'related'        => $related,
		'views'          => $views,
	);
	$row['flags'] = sn_analytics_posts_flags( $row );
	return $row;
}

/**
 * THE THREE FLAGS. Derived here and only here; each a true binary that needs
 * no sample size, each false (never "unknown") when its input is a gap,
 * because a gap is not evidence of the fault either.
 *
 *   not_indexed  coverage says Google is not indexing it (indexed === false).
 *   stale_crawl  last crawl predates the last edit.
 *   orphaned     inbound internal links <= SN_POSTS_ORPHAN_MAX_INBOUND.
 *
 * @param array<string,mixed> $row
 * @return array{not_indexed:bool,stale_crawl:bool,orphaned:bool}
 */
function sn_analytics_posts_flags( array $row ) {
	$indexed = $row['index']['value'] ?? null;
	$crawl   = $row['last_crawl']['value'] ?? null;
	$inbound = $row['inbound']['value'] ?? null;
	return array(
		'not_indexed' => false === $indexed,
		'stale_crawl' => null !== $crawl && (int) $row['modified_ts'] > 0 && (int) $crawl < (int) $row['modified_ts'],
		'orphaned'    => null !== $inbound && (int) $inbound <= SN_POSTS_ORPHAN_MAX_INBOUND,
	);
}

/**
 * Severity order for the queue: a note Google is not indexing outranks a
 * stale crawl, which outranks an orphan. More flags rank higher.
 *
 * @param array<string,bool> $flags
 * @return int Higher = more urgent.
 */
function sn_analytics_posts_severity( array $flags ) {
	return ( ! empty( $flags['not_indexed'] ) ? 4 : 0 ) + ( ! empty( $flags['stale_crawl'] ) ? 2 : 0 ) + ( ! empty( $flags['orphaned'] ) ? 1 : 0 );
}

/**
 * Every row, plus the summary counts and the header strip.
 *
 * @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>,strip:array<string,mixed>}
 */
function sn_analytics_posts_signals() {
	$ctx  = sn_analytics_posts_context();
	$rows = array();
	foreach ( sn_analytics_posts_catalogue() as $post ) {
		$rows[] = sn_analytics_posts_row( $post, $ctx );
	}
	return array(
		'rows'   => $rows,
		'counts' => sn_analytics_posts_counts( $rows ),
		'strip'  => sn_analytics_posts_strip( $ctx ),
	);
}

/**
 * Counts that mean something: each a count of a binary over the rows.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{total:int,indexed:int,with_impressions:int,zero_inbound:int,stale_crawl:int,not_indexed:int,not_inspected:int}
 */
function sn_analytics_posts_counts( array $rows ) {
	$c = array( 'total' => count( $rows ), 'indexed' => 0, 'with_impressions' => 0, 'zero_inbound' => 0, 'stale_crawl' => 0, 'not_indexed' => 0, 'not_inspected' => 0 );
	foreach ( $rows as $r ) {
		if ( true === ( $r['index']['value'] ?? null ) ) {
			++$c['indexed'];
		}
		if ( null === ( $r['index']['value'] ?? null ) ) {
			++$c['not_inspected'];
		}
		if ( (int) ( $r['impressions']['value'] ?? 0 ) > 0 ) {
			++$c['with_impressions'];
		}
		if ( 0 === ( $r['inbound']['value'] ?? null ) ) {
			++$c['zero_inbound'];
		}
		if ( ! empty( $r['flags']['stale_crawl'] ) ) {
			++$c['stale_crawl'];
		}
		if ( ! empty( $r['flags']['not_indexed'] ) ) {
			++$c['not_indexed'];
		}
	}
	return $c;
}

/**
 * The header strip: the site-wide facts every row is read against.
 *
 * Machine reads live HERE and nowhere else on the tab.
 *
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function sn_analytics_posts_strip( array $ctx ) {
	$snap  = function_exists( 'snt_mr_snapshot' ) ? snt_mr_snapshot() : null;
	$total = function_exists( 'snt_mr_snapshot_total' ) ? snt_mr_snapshot_total( $snap ) : null;
	$gw    = is_array( $ctx['gsc'] ) && is_array( $ctx['gsc']['window'] ?? null ) ? $ctx['gsc']['window'] : null;
	$cs    = is_array( $ctx['coverage'] ) && is_array( $ctx['coverage']['status'] ?? null ) ? $ctx['coverage']['status'] : null;
	return array(
		'machine_reads'      => sn_posts_field( null === $total ? null : (int) $total, 'no site-wide measurement yet' ),
		'machine_reads_days' => is_array( $snap ) && ! empty( $snap['days'] ) ? (int) $snap['days'] : 30,
		'machine_reads_stale' => function_exists( 'snt_mr_snapshot_is_stale' ) ? (bool) snt_mr_snapshot_is_stale( $snap ) : false,
		'gsc_window'         => $gw ? array( 'start' => (string) ( $gw['start'] ?? '' ), 'end' => (string) ( $gw['end'] ?? '' ) ) : null,
		'coverage_run'       => $cs ? array( 'finished_at' => (int) ( $cs['finished_at'] ?? 0 ), 'inspected' => (int) ( $cs['inspected'] ?? 0 ), 'errors' => (int) ( $cs['errors'] ?? 0 ) ) : null,
	);
}
