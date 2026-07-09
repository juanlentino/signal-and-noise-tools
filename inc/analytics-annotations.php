<?php
/**
 * Signal & Noise Tools — Analytics annotation resolvers (Release 1).
 *
 * One pure function per eligible panel: it receives the data the panel already
 * fetched and returns a one-sentence "read" or null. Zero new AE queries, zero
 * AI, fully deterministic (and therefore unit-testable). Thresholds are the
 * SN_ANNOTATION_* constants below, tuned conservative so quiet ranges stay silent.
 *
 * Rendered by snt_an_annotation() (inc/analytics-panels.php).
 *
 * @package SignalNoiseTools
 * @since 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Movers: need at least this many movers to read a direction, and this share of
// them must point one way for the skew to be worth stating.
const SN_ANNOTATION_MOVERS_MIN  = 3;
const SN_ANNOTATION_MOVERS_SKEW = 0.6;

// Anomalies: this many pages of one divergence type before it is worth a line.
const SN_ANNOTATION_ANOMALY_MIN = 2;

// Lifecycle: below this catalogue size a census is not meaningful; at or above
// this refresh-candidate count the read fires.
const SN_ANNOTATION_LIFECYCLE_MIN_TOTAL      = 8;
const SN_ANNOTATION_LIFECYCLE_MIN_CANDIDATES = 3;

// Overview: views must move at least this percent for a volume/engagement
// divergence to be worth calling out.
const SN_ANNOTATION_OVERVIEW_VIEWS_PCT = 15;

// Top pages: fire when one page holds at least this share of the returned rows'
// views (the Content view has no grand range total in scope, so this is a
// share-of-top-pages), and only with at least this many pages in play (a 55%
// share among 2-3 pages is trivial, not a concentration signal).
const SN_ANNOTATION_TOP_PAGE_SHARE    = 0.55;
const SN_ANNOTATION_TOP_PAGE_MIN_ROWS = 4;

// Sources: fire when direct is at least this share of referrer-category visits,
// above a volume floor so a trickle never trips it. Conservative because a
// cookieless site already strips many referrers into the direct bucket.
const SN_ANNOTATION_DIRECT_SHARE       = 0.85;
const SN_ANNOTATION_SOURCES_MIN_VISITS = 30;

// Geography: fire when the two largest markets hold at least this share of visits,
// and only with at least this many markets in play (with 3, top-2 is mathematically
// >= 67%, so the read would be trivial rather than a real concentration signal).
const SN_ANNOTATION_GEO_TOP2_SHARE = 0.65;
const SN_ANNOTATION_GEO_MIN_ROWS   = 4;

/**
 * Movers read: state the direction of movement when it clearly skews one way.
 * Uses only { path, views, delta }, with no post age (age would need a per-path
 * query, breaking the zero-new-query rule). Null on mixed or thin movement.
 *
 * @param array $movers [ { path, views, delta } ] from sn_analytics_movers().
 * @return string|null
 */
function sn_annotation_movers( $movers ) {
	$movers = is_array( $movers ) ? $movers : array();
	$total  = count( $movers );
	if ( $total < SN_ANNOTATION_MOVERS_MIN ) {
		return null;
	}
	$up   = 0;
	$down = 0;
	foreach ( $movers as $m ) {
		$d = (int) ( $m['delta'] ?? 0 );
		if ( $d > 0 ) {
			++$up;
		} elseif ( $d < 0 ) {
			++$down;
		}
	}
	if ( $down >= $up && $down / $total >= SN_ANNOTATION_MOVERS_SKEW ) {
		return sprintf(
			/* translators: 1: count of declining pages, 2: total movers */
			__( 'Movement skews down: %1$d of %2$d movers lost views.', 'signal-and-noise-tools' ),
			$down,
			$total
		);
	}
	if ( $up > $down && $up / $total >= SN_ANNOTATION_MOVERS_SKEW ) {
		return sprintf(
			/* translators: 1: count of rising pages, 2: total movers */
			__( 'Movement skews up: %1$d of %2$d movers gained views.', 'signal-and-noise-tools' ),
			$up,
			$total
		);
	}
	return null;
}

/**
 * Anomalies read: summarize the divergence rows by type (skim / stall). Null
 * below the per-type threshold.
 *
 * @param array $anom { divergence:[ { type } ], outliers:[] } from sn_analytics_engagement_anomalies().
 * @return string|null
 */
function sn_annotation_anomalies( $anom ) {
	$div   = ( is_array( $anom ) && isset( $anom['divergence'] ) && is_array( $anom['divergence'] ) ) ? $anom['divergence'] : array();
	$skim  = 0;
	$stall = 0;
	foreach ( $div as $d ) {
		$t = (string) ( $d['type'] ?? '' );
		if ( 'skim' === $t ) {
			++$skim;
		} elseif ( 'stall' === $t ) {
			++$stall;
		}
	}
	$parts = array();
	if ( $skim >= SN_ANNOTATION_ANOMALY_MIN ) {
		/* translators: %d is the number of skimmed pages */
		$parts[] = sprintf( __( '%d pages skimmed: deep scroll, fast leave.', 'signal-and-noise-tools' ), $skim );
	}
	if ( $stall >= SN_ANNOTATION_ANOMALY_MIN ) {
		/* translators: %d is the number of stalled pages */
		$parts[] = sprintf( __( '%d pages stalled: long dwell, low scroll.', 'signal-and-noise-tools' ), $stall );
	}
	return empty( $parts ) ? null : implode( ' ', $parts );
}

/**
 * Lifecycle read: the catalogue's shape. Fires on a refresh-candidate cluster,
 * else on an evergreen majority. Null on a thin catalogue or no clear shape.
 *
 * @param array $summary { counts:{spike,cooling,evergreen,unknown}, refresh_candidates, total } from sn_analytics_lifecycle_summary().
 * @return string|null
 */
function sn_annotation_lifecycle( $summary ) {
	$summary = is_array( $summary ) ? $summary : array();
	$total   = (int) ( $summary['total'] ?? 0 );
	if ( $total < SN_ANNOTATION_LIFECYCLE_MIN_TOTAL ) {
		return null;
	}
	$counts    = is_array( $summary['counts'] ?? null ) ? $summary['counts'] : array();
	$cooling   = (int) ( $counts['cooling'] ?? 0 );
	$evergreen = (int) ( $counts['evergreen'] ?? 0 );
	$cands     = (int) ( $summary['refresh_candidates'] ?? 0 );

	if ( $cands >= SN_ANNOTATION_LIFECYCLE_MIN_CANDIDATES ) {
		return sprintf(
			/* translators: 1: cooling count, 2: total posts, 3: refresh-candidate count */
			__( '%1$d of %2$d posts are cooling, and %3$d are refresh candidates.', 'signal-and-noise-tools' ),
			$cooling,
			$total,
			$cands
		);
	}
	if ( $evergreen * 2 > $total ) {
		return sprintf(
			/* translators: 1: evergreen count, 2: total posts */
			__( 'Most of your catalogue holds: %1$d of %2$d posts are evergreen.', 'signal-and-noise-tools' ),
			$evergreen,
			$total
		);
	}
	return null;
}

/**
 * Overview read: volume moved but engagement diverged from it, the caveat the
 * headline number hides. Uses the period deltas + engaged-rate delta the header
 * region already fetched. Phrased qualitatively on the engagement side so it
 * needs no assumption about the rate's scale. Null when they agree, when views
 * moved little, or on the 'all' range (no deltas).
 *
 * @param array $deltas  Period deltas ('views' => { pct, dir }) from sn_analytics_period_deltas().
 * @param array $engaged Engaged-rate delta ({ dir }) from sn_analytics_engaged_rate_delta().
 * @return string|null
 */
function sn_annotation_overview( $deltas, $engaged ) {
	$views = ( is_array( $deltas ) && isset( $deltas['views'] ) && is_array( $deltas['views'] ) ) ? $deltas['views'] : array();
	$vdir  = (string) ( $views['dir'] ?? '' );
	$vpct  = (int) round( (float) ( $views['pct'] ?? 0 ) );
	$edir  = is_array( $engaged ) ? (string) ( $engaged['dir'] ?? '' ) : '';

	$is_move  = ( 'up' === $vdir || 'down' === $vdir ) && abs( $vpct ) >= SN_ANNOTATION_OVERVIEW_VIEWS_PCT;
	$diverges = ( 'up' === $vdir && 'down' === $edir ) || ( 'down' === $vdir && 'up' === $edir );
	if ( ! $is_move || ! $diverges ) {
		return null;
	}
	if ( 'up' === $vdir ) {
		return sprintf(
			/* translators: %d is the percent rise in views */
			__( 'Views up %d%%, but engaged rate slipped: more traffic, shallower visits.', 'signal-and-noise-tools' ),
			abs( $vpct )
		);
	}
	return sprintf(
		/* translators: %d is the percent fall in views */
		__( 'Views down %d%%, but engaged rate rose: fewer visits, but stickier.', 'signal-and-noise-tools' ),
		abs( $vpct )
	);
}

/**
 * Top-pages read: one page dominates the view distribution. Share is over the
 * returned rows' views (the Content view holds no grand range total in scope),
 * so the copy says "top pages", not "the range". Null on a spread distribution,
 * a thin page set, or zero views.
 *
 * @param array $paths [ { path, views, ... } ] from sn_analytics_top_paths().
 * @return string|null
 */
function sn_annotation_top_pages( $paths ) {
	$paths = is_array( $paths ) ? array_values( $paths ) : array();
	if ( count( $paths ) < SN_ANNOTATION_TOP_PAGE_MIN_ROWS ) {
		return null;
	}
	$views = array();
	foreach ( $paths as $p ) {
		$views[] = max( 0, (int) ( $p['views'] ?? 0 ) );
	}
	$total = array_sum( $views );
	if ( $total <= 0 ) {
		return null;
	}
	$share = max( $views ) / $total;
	if ( $share < SN_ANNOTATION_TOP_PAGE_SHARE ) {
		return null;
	}
	return sprintf(
		/* translators: %d is the percent of the top pages' views held by the single most-viewed page */
		__( 'One page holds %d%% of your top pages\' views: traffic is concentrated.', 'signal-and-noise-tools' ),
		(int) round( $share * 100 )
	);
}

/**
 * Sources read: traffic is almost entirely direct, with little referral (an
 * owned audience rather than a discovered one). Reads referrer CATEGORIES, which
 * isolate direct cleanly, rather than top_sources, which folds unknown referrers
 * into the direct bucket. Null below the direct-share threshold or the floor.
 *
 * @param array $cats [ { category, views, visits } ] from sn_analytics_referrer_categories().
 * @return string|null
 */
function sn_annotation_sources( $cats ) {
	$cats   = is_array( $cats ) ? $cats : array();
	$total  = 0;
	$direct = 0;
	foreach ( $cats as $c ) {
		$v      = max( 0, (int) ( $c['visits'] ?? 0 ) );
		$total += $v;
		if ( 'direct' === (string) ( $c['category'] ?? '' ) ) {
			$direct += $v;
		}
	}
	if ( $total < SN_ANNOTATION_SOURCES_MIN_VISITS ) {
		return null;
	}
	$share = $direct / $total;
	if ( $share < SN_ANNOTATION_DIRECT_SHARE ) {
		return null;
	}
	return sprintf(
		/* translators: %d is the percent of visits that arrive directly, with no referrer */
		__( '%d%% of visits are direct, with little referral: an owned audience, not discovered.', 'signal-and-noise-tools' ),
		(int) round( $share * 100 )
	);
}

/**
 * Geography read: the audience clusters in a couple of markets, with little
 * discovery beyond them. Uses the 250-row country pull (every country, so the
 * visits sum is a true total, not a share-of-top-N). Markets are ranked by visits.
 * Null on a spread map, a thin market set, or no visits.
 *
 * @param array $geo [ { value, views, visits } ] from sn_analytics_top_dimension('country',…,250).
 * @return string|null
 */
function sn_annotation_geography( $geo ) {
	$geo = is_array( $geo ) ? array_values( $geo ) : array();
	if ( count( $geo ) < SN_ANNOTATION_GEO_MIN_ROWS ) {
		return null;
	}
	$visits = array();
	foreach ( $geo as $g ) {
		$visits[] = max( 0, (int) ( $g['visits'] ?? 0 ) );
	}
	$total = array_sum( $visits );
	if ( $total <= 0 ) {
		return null;
	}
	rsort( $visits );
	$share = ( $visits[0] + ( $visits[1] ?? 0 ) ) / $total;
	if ( $share < SN_ANNOTATION_GEO_TOP2_SHARE ) {
		return null;
	}
	return sprintf(
		/* translators: %d is the percent of visits from the two largest country markets */
		__( 'Two markets are %d%% of visits: little discovery beyond your core geography.', 'signal-and-noise-tools' ),
		(int) round( $share * 100 )
	);
}
