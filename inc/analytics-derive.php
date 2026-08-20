<?php
/**
 * Signal & Noise — pure analytics derive layer (Analytics Integrity Phase A).
 *
 * Computes every spec-§4 derived field from ONE daily-aggregate row (or a
 * range total shaped like one): the honest denominators (`unique_visitor_days`,
 * `pageview_visits`, `viewless_visits`), the transparent ratios, exact
 * per-view / per-visit engagement, and the never-invert `integrity_violation`
 * flag. Input-key spellings match the rollup columns (inc/analytics-rollup.php)
 * — NOT invented names. Today's rollup SELECT emits only views / visits /
 * scroll_avg / time_avg; pageview_visits / scroll_sum / scroll_events /
 * time_sum / time_events are schema-v5 columns that Task 3 will populate.
 * Any key may be ABSENT or NULL.
 *
 * ── PURE MODULE — the load-bearing constraint ────────────────────────────────
 *
 * ZERO WordPress calls, zero globals, zero I/O. Tests require() this real file
 * directly (never a stub), so the asserted behaviour IS the shipped behaviour.
 * Declarations are function_exists-guarded so a test that already loaded the
 * file (or a WP boot that double-requires) never fatals on redeclare.
 *
 * ── Null discipline (each rule is a shipped-bug class from project memory) ───
 *
 *   - absent key ≡ null value ≡ "never measured" → the derived field is null.
 *     Distinguished via array_key_exists(): `??`/isset() are blind to a
 *     present-but-null key and would silently conflate the two.
 *   - A ratio is null when ANY of its inputs is null/absent OR its denominator
 *     is 0. A zero-traffic day therefore yields REAL 0 counts and null ratios.
 *   - Never cast null → 0 (fabricates a measurement) and never 0 → null
 *     (erases one): a measured scroll_sum of 0 over live views is exactly 0.0.
 *   - `integrity_violation` is a strict bool, never null: true only when
 *     `views` and `pageview_visits` are BOTH known and views < pageview_visits
 *     (arithmetically impossible per spec §5 — so true means a genuine
 *     rollup/sampling bug upstream). Values are still reported un-clamped;
 *     the alarm is the feature.
 *
 * ── Scroll-depth unit (v9.64.0 redefinition — owner call 2026-07-18) ────────
 *
 * The beacon fires one CUMULATIVE 'sc' event per milestone reached
 * (25/50/75/100), each at most once per view. `scroll_sum` therefore sums
 * milestone POINTS, not depths: a full-depth view contributes 25+50+75+100 =
 * 250, and the original `scroll_sum / views` ratio read 113% live. Because the
 * milestones are evenly spaced and fire at most once per view,
 * 25 × scroll_events IS the sum of per-view max depths, so
 * `scroll_avg_per_view` = 25 × scroll_events / views is the TRUE mean max
 * scroll depth (0–100) — same identity per visitor-day for
 * `scroll_avg_per_visit`. Since v9.66.0 (daily schema v6) the STORED
 * `scroll_sum` column is re-based to the SAME identity (25 × scroll_events,
 * true depth units; the v5-era raw milestone-point rows were repaired by the
 * one-time v6 migration) — only the AE-transported alias still carries raw
 * milestone points, and it feeds nothing but the legacy weighted scroll_avg
 * in the rollup. This layer keeps deriving from `scroll_events`, so it is
 * correct against BOTH eras and `scroll_sum` still feeds no ratio here.
 * `time_*` fields are untouched (time_sum is a real ms sum).
 *
 * @package SignalNoiseTools
 * @since 9.63.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SN_ANALYTICS_SCROLL_MILESTONE_STEP' ) ) {
	// One 'sc' beacon milestone = 25 scroll-depth points (milestones fire at
	// 25/50/75/100% and at most once each per view) — see the module header.
	define( 'SN_ANALYTICS_SCROLL_MILESTONE_STEP', 25 );
}

if ( ! function_exists( 'sn_analytics_derive_num' ) ) {
	/**
	 * Read one numeric input from a daily row, honouring absent-vs-null.
	 *
	 * @param array  $daily Daily-aggregate row (or range total).
	 * @param string $key   Input key (rollup column spelling).
	 * @return int|float|null Numeric value, or null when absent / null /
	 *                        non-numeric ("never measured" / untrustworthy —
	 *                        never coerced to a fabricated 0).
	 */
	function sn_analytics_derive_num( array $daily, $key ) {
		if ( ! array_key_exists( $key, $daily ) ) {
			return null; // absent — isset()/`??` could not tell this from null.
		}
		$value = $daily[ $key ];
		if ( null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return $value + 0; // numeric-string (wpdb reality) → real int|float.
	}
}

if ( ! function_exists( 'sn_analytics_derive_ratio' ) ) {
	/**
	 * Honest division: null unless both operands are known and the denominator
	 * is non-zero. A known-0 numerator over a live denominator returns 0.0.
	 *
	 * @param int|float|null $numerator   Known value or null.
	 * @param int|float|null $denominator Known value or null.
	 * @return float|null
	 */
	function sn_analytics_derive_ratio( $numerator, $denominator ) {
		if ( null === $numerator || null === $denominator || 0.0 === (float) $denominator ) {
			return null;
		}
		return (float) $numerator / (float) $denominator;
	}
}

if ( ! function_exists( 'sn_analytics_derive_metrics' ) ) {
	/**
	 * Derive every spec-§4 field from one daily row (or range total).
	 *
	 * @param array $daily Row with any of: views, visits (≡ unique visitor-
	 *                     days), pageview_visits, scroll_sum, scroll_events,
	 *                     time_sum, time_events. Any key may be absent or null.
	 * @return array {
	 *     @type int|null   $unique_visitor_days       Honest alias of `visits`.
	 *     @type int|null   $pageview_visits           Gated visitor-days (headline).
	 *     @type int|null   $viewless_visits           unique_visitor_days − pageview_visits.
	 *     @type float|null $view_visit_ratio          views / pageview_visits.
	 *     @type float|null $pageviews_per_visitor_day views / unique_visitor_days.
	 *     @type float|null $scroll_avg_per_view       25 × scroll_events / views (true mean max depth, 0–100).
	 *     @type float|null $time_avg_per_view         time_sum / views (exact). MILLISECONDS.
	 *     @type float|null $scroll_avg_per_visit      25 × scroll_events / unique_visitor_days (diluted by viewless days).
	 *     @type float|null $time_avg_per_visit        time_sum / unique_visitor_days (diluted by viewless days). MILLISECONDS.
	 *     @type bool       $integrity_violation       true iff both known AND views < pageview_visits.
	 * }
	 */
	function sn_analytics_derive_metrics( array $daily ) {
		$views         = sn_analytics_derive_num( $daily, 'views' );
		$scroll_events = sn_analytics_derive_num( $daily, 'scroll_events' );
		$time_sum      = sn_analytics_derive_num( $daily, 'time_sum' );

		// v9.64.0 depth identity (module header): 25 × scroll_events = the sum
		// of per-view max depths, because milestones are evenly spaced and each
		// fires at most once per view. scroll_sum deliberately feeds no ratio
		// here: deriving from scroll_events is correct against BOTH storage
		// eras (v5 rows held raw milestone points — 250 for a full-depth view —
		// until the v6 repair re-based them to this same 25 × events identity).
		$scroll_depth_points = ( null === $scroll_events )
			? null
			: SN_ANALYTICS_SCROLL_MILESTONE_STEP * $scroll_events;

		$visitor_days = sn_analytics_derive_num( $daily, 'visits' );
		$gated        = sn_analytics_derive_num( $daily, 'pageview_visits' );
		$visitor_days = null === $visitor_days ? null : (int) $visitor_days;
		$gated        = null === $gated ? null : (int) $gated;

		return array(
			'unique_visitor_days'       => $visitor_days,
			'pageview_visits'           => $gated,
			'viewless_visits'           => ( null !== $visitor_days && null !== $gated ) ? $visitor_days - $gated : null,
			'view_visit_ratio'          => sn_analytics_derive_ratio( $views, $gated ),
			'pageviews_per_visitor_day' => sn_analytics_derive_ratio( $views, $visitor_days ),
			'scroll_avg_per_view'       => sn_analytics_derive_ratio( $scroll_depth_points, $views ),
			'time_avg_per_view'         => sn_analytics_derive_ratio( $time_sum, $views ),
			'scroll_avg_per_visit'      => sn_analytics_derive_ratio( $scroll_depth_points, $visitor_days ),
			'time_avg_per_visit'        => sn_analytics_derive_ratio( $time_sum, $visitor_days ),
			'integrity_violation'       => null !== $views && null !== $gated && $views < $gated,
		);
	}
}

/**
 * Canonical form of a stored request path.
 *
 * A trailing slash is a SPELLING, not a page. Ingestion never normalises one
 * (inc/analytics-rollup.php stores `path` verbatim under the (day, path, class)
 * key), so `/notes` and `/notes/` are two stored rows for one page and every
 * read that groups by the raw column reports them as two pages — which is what
 * the owner saw on 2026-08-19: two `/notes` entries, 27 views each.
 *
 * TWO EDGES, both deliberate:
 *
 * - The ROOT keeps its slash. `/` trimmed is the empty string, which is not a
 *   path at all; collapsing it would delete the home page from every report.
 * - An EMPTY path stays empty. Ingestion refuses to store one, so encountering
 *   it means something upstream is wrong — inventing a `/` here would hide that
 *   behind a plausible-looking row.
 *
 * sn_analytics_canonical_path_sql() is the SQL twin of this function and MUST
 * agree with it on all four cases; the read suite pins both.
 *
 * @since 11.31.2
 * @param string $path Stored path.
 * @return string
 */
if ( ! function_exists( 'sn_analytics_canonical_path' ) ) {
	function sn_analytics_canonical_path( $path ) {
		$p = (string) $path;
		if ( '' === $p ) {
			return '';
		}
		$trimmed = rtrim( $p, '/' );
		return '' === $trimmed ? '/' : $trimmed;
	}
}

/**
 * The SQL expression that canonicalises a path column, for use in GROUP BY.
 *
 * This belongs in the GROUP BY and NOT in PHP after the query. The reads that
 * use it all end in `ORDER BY views DESC LIMIT n`, so the database ranks and
 * truncates on the grouped figure: with the raw column as the key, one spelling
 * of a page can be cut by the LIMIT before any PHP ever sees it, and a merge
 * done afterwards has nothing left to merge. Same trap as the freshness clock,
 * where post-filtering could not recover a row the WHERE clause had excluded.
 *
 * @since 11.31.2
 * @param string $col Column name — CALLER-CONTROLLED LITERAL ONLY, never input.
 * @return string
 */
if ( ! function_exists( 'sn_analytics_canonical_path_sql' ) ) {
	function sn_analytics_canonical_path_sql( $col = 'path' ) {
		return "CASE WHEN {$col} = '' THEN ''"
			. " WHEN TRIM(TRAILING '/' FROM {$col}) = '' THEN '/'"
			. " ELSE TRIM(TRAILING '/' FROM {$col}) END";
	}
}
