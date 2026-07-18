<?php
/**
 * Trailing-90d analytics backfill re-roll — Phase A Task 6
 * (plan: docs/analytics-integrity-plan.md; spec §8 in docs/analytics-integrity-design.md).
 *
 * OWNER-RUN, DEV-ONLY. Never bundled (tools/ is export-ignored), never
 * registered by the plugin loader, excluded from the CI test sweep (which
 * globs tests/*.php only) and from PHPCS (tools/ exclude-pattern).
 *
 * Run ON LIVE **from the WordPress root (public_html)** — Cloudways WP-CLI
 * requires the WP root as the current working directory:
 *
 *   cd ~/applications/<app>/public_html
 *   wp eval-file wp-content/plugins/signal-and-noise-tools/tools/reroll-analytics-90d.php
 *
 * What it does
 * ------------
 * Re-rolls each of the trailing 90 days from Analytics Engine into
 * wp_sn_analytics_daily so the five v5 columns (scroll_sum / scroll_events /
 * time_sum / time_events / pageview_visits) exist on every day AE still
 * retains. Each day runs through the SAME production pipeline the cron uses —
 * sn_analytics_rollup_sql() + sn_analytics_rollup_gated_sql() →
 * sn_analytics_rollup_merge_gated() → sn_analytics_rollup_upsert() — so the
 * write path (null discipline, %s FLOAT binds, 100-row chunking, the
 * never-invert guard) is byte-identical to the cron's.
 *
 * Day boundary / timezone: matches the rollup EXACTLY — the SITE-LOCAL day via
 * sn_analytics_site_tz_name() (v9.26.4; zoned formatDateTime/toStartOfInterval),
 * falling back to the UTC path only when the site zone is a manual offset. The
 * plan's Task 6 note said "UTC"; the real rollup is site-local and the rollup
 * wins — a re-roll bucketed differently from the durable history would write
 * adjacent-day rows beside it instead of overwriting. For the same reason this
 * tool deliberately has NO mid-run UTC fallback (unlike the cron): if the zoned
 * query fails, the day FAILS LOUDLY rather than re-keying the table.
 *
 * Why per-day windows (not one trailing-90d query): AE responses are row-capped
 * and the rollup orders day DESC, so a single 90-day query would silently
 * truncate the OLDEST days — exactly the ones only this backfill can reach.
 * Each bounded [start-of-day, start-of-next-day) window keeps every write
 * inside a small observable result set. Offsets 89..2 run as bounded windows;
 * yesterday+today ride the unmodified production trailing-1-day query (today is
 * partial by definition and the cron keeps refreshing it — idempotent).
 *
 * Every day echoes a result line — NEVER silent (a success:true that wrote
 * nothing is a known failure shape here; an empty day is an ANSWER and says so).
 * A 0-AE-row day is AMBIGUOUS, though: genuinely quiet vs aged out of AE's
 * ~90d retention (the offset-89 boundary). It counts as fully OK only when the
 * durable table also has no INCOMPLETE rows (scroll_sum OR pageview_visits
 * NULL) for that day — otherwise (this site's daily RSS srv:1 beacon rows
 * guarantee real days have rows) it is excluded from the streak, so
 * exact_metrics_since never claims coverage over a day whose range still reads
 * null exact fields.
 * On completion it sets the option `sn_analytics_exact_metrics_since` (Y-m-d,
 * read by inc/analytics-read.php) to the earliest day of the UNBROKEN fully-OK
 * streak ending today — on full success that is the earliest re-rolled day
 * (today − 89d). A day only counts as fully OK when BOTH queries succeeded, the
 * upsert wrote, AND the post-write row-level completeness check passes: the
 * durable table can hold MORE rows for a day than AE still returns — "stale
 * sibling" rows the original nightly cron wrote when the day's events were
 * fresh, whose (day, path, class) keys AE has since consolidated away
 * (production diagnostic 2026-07-18: 36 such rows since 2026-06-13). Those
 * siblings keep NULL scroll_sum/pageview_visits forever (their AE source is
 * gone; they hold real legacy views/visits — never delete, never fabricate
 * 0s), so day-level success ≠ row-level completeness and the streak must be
 * earned by the TABLE, not the run. A gated-query failure likewise leaves
 * pageview_visits NULL ("never measured", matching the cron's
 * degrade-not-corrupt rule) and excludes the day from the streak. If today
 * itself failed, the option is NOT set — fix and re-run; the whole tool is
 * idempotent.
 *
 * Do not start a run within ~30 minutes of local midnight: the day labels are
 * computed once at start while each AE window is relative to now() at query
 * time, so a midnight rollover mid-run would shift the remaining windows.
 *
 * The pure helpers below are unit-tested by tests/reroll-analytics-90d.php
 * (which defines SN_REROLL_TEST to skip the execution section). Every SQL
 * transform fails LOUDLY (returns null) if the rollup builder's shape drifted
 * from the needles — never a silently-unchanged query, which would re-roll the
 * wrong window and clobber complete neighbouring days.
 *
 * @package SignalNoiseTools
 */

// SECURITY: Prevent web access. Dev tool, not a runtime module. Allow only
// CLI / WP-CLI invocations (mirrors tests/contracts-smoke.php).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

// ── Pure helpers (no WP calls — unit-testable) ───────────────────────────────

/**
 * The bounded-window day offsets, in ascending-day order (largest offset
 * first): 89..2 for a 90-day span. Offsets 1 and 0 (yesterday + today) are
 * deliberately absent — they ride the unmodified production trailing-1-day
 * query, which needs no upper bound (today is partial by definition).
 *
 * @param int $span Total days to re-roll (>= 3 for any bounded windows).
 * @return int[] Offsets, descending from $span-1 to 2; empty when $span < 3.
 */
function sn_reroll_bounded_offsets( $span ) {
	$span = (int) $span;
	if ( $span < 3 ) {
		return array();
	}
	return range( $span - 1, 2 );
}

/**
 * The Y-m-d label for the day $offset civil days before $today, in $today's
 * timezone. Civil-day arithmetic (DateTimeImmutable::modify) is DST-safe —
 * crossing a spring-forward boundary never slips an hour into the wrong date.
 *
 * @param int               $offset Days back from today (floored at 0).
 * @param DateTimeImmutable $today  "Today" in the site zone (computed once per run).
 * @return string Y-m-d.
 */
function sn_reroll_day_label( $offset, DateTimeImmutable $today ) {
	$offset = max( 0, (int) $offset );
	return $today->modify( "-{$offset} days" )->format( 'Y-m-d' );
}

/**
 * Bound the main rollup query to ONE day: [$lower, $upper).
 *
 * $lower/$upper are the REAL sn_analytics_rollup_window_exprs() lower bounds
 * for offsets k and k-1 — the same expression family the production rollup
 * floors its window with, so the bounded day starts exactly where the durable
 * table's day buckets start. The upper bound is strict `<`: the next day's
 * start instant belongs to the next day (formatDateTime buckets it there).
 *
 * @param string $base  Output of sn_analytics_rollup_sql( k, $tz ).
 * @param string $lower Window lower bound (exprs for offset k).
 * @param string $upper Window upper bound (exprs for offset k-1).
 * @return string|null Transformed SQL, or null when the base shape drifted or
 *                     the bounds are degenerate.
 */
function sn_reroll_day_sql( $base, $lower, $upper ) {
	$base  = (string) $base;
	$lower = (string) $lower;
	$upper = (string) $upper;
	if ( '' === $lower || '' === $upper || $lower === $upper ) {
		return null;
	}
	$needle = "WHERE timestamp >= {$lower} GROUP BY";
	if ( 1 !== substr_count( $base, $needle ) ) {
		return null;
	}
	return str_replace( $needle, "WHERE timestamp >= {$lower} AND timestamp < {$upper} GROUP BY", $base );
}

/**
 * Bound the gated pageview_visits query to the SAME one-day window.
 *
 * The needle includes the pv gate, so this transform can never be applied to
 * the main query (and vice versa) — a wrong-query cross-feed returns null.
 * The upper bound sits BETWEEN the lower bound and the pv gate, keeping both
 * queries' window predicates byte-identical (the PHP merge joins on
 * (day, path, class); a bound drift would silently mis-key the merge).
 *
 * @param string $base  Output of sn_analytics_rollup_gated_sql( k, $tz ).
 * @param string $lower Window lower bound (exprs for offset k).
 * @param string $upper Window upper bound (exprs for offset k-1).
 * @return string|null Transformed SQL, or null when the base shape drifted or
 *                     the bounds are degenerate.
 */
function sn_reroll_gated_day_sql( $base, $lower, $upper ) {
	$base  = (string) $base;
	$lower = (string) $lower;
	$upper = (string) $upper;
	if ( '' === $lower || '' === $upper || $lower === $upper ) {
		return null;
	}
	$needle = "WHERE timestamp >= {$lower} AND blob1 = 'pv' GROUP BY";
	if ( 1 !== substr_count( $base, $needle ) ) {
		return null;
	}
	return str_replace( $needle, "WHERE timestamp >= {$lower} AND timestamp < {$upper} AND blob1 = 'pv' GROUP BY", $base );
}

/**
 * Durable-table row-level completeness COUNT for one day: how many rows still
 * lack an exact column (scroll_sum IS NULL OR pageview_visits IS NULL)?
 *
 * Day-level success ≠ row-level completeness (production diagnostic
 * 2026-07-18: 36 such rows since 2026-06-13). The durable table can hold MORE
 * rows for a day than AE still returns — "stale sibling" rows the original
 * nightly cron wrote when the day's events were fresh, whose (day, path,
 * class) keys AE has since consolidated away. The re-roll upserts everything
 * AE returns and the day looks OK, yet the unmatched siblings keep NULL
 * scroll_sum/pageview_visits forever (their AE source is gone; they hold real
 * legacy views/visits — never delete, never fabricate 0s), and the read layer
 * correctly nulls exact fields for any range touching the day. So the streak
 * must be earned by the TABLE, not the run. The OR arm also catches a
 * scroll-written/gated-NULL row: run N's main query succeeded (scroll_sum
 * written), its gated query failed, and the key vanished from AE before run
 * N+1 — pageview_visits stays NULL with no source left to measure it.
 *
 * @param object $db    wpdb (injected, so this helper stays unit-testable).
 * @param string $table Durable daily table name (prefix included).
 * @param string $label Day label (Y-m-d).
 * @return int|null Incomplete-row count, or null when the COUNT read failed —
 *                  an unknown is NOT a clean answer (callers must never treat
 *                  null as 0; fail toward honesty, matching the
 *                  missing-'ok'-key rule in sn_reroll_since_day()).
 */
function sn_reroll_incomplete_rows( $db, $table, $label ) {
	$count = $db->get_var( $db->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE day = %s AND (scroll_sum IS NULL OR pageview_visits IS NULL)",
		(string) $label
	) );
	return is_numeric( $count ) ? (int) $count : null;
}

/**
 * Is a 0-AE-row day safe to count toward the exact_metrics_since streak?
 *
 * 0 AE rows is AMBIGUOUS: a genuinely quiet day looks identical to a day that
 * aged out of AE's ~90d retention (the offset-89 boundary). On this site every
 * real day has durable rows (the daily RSS srv:1 beacon class), so an aged-out
 * day still carries LEGACY rows (both exact columns NULL — pre-v5, never
 * backfilled) in wp_sn_analytics_daily. Counting such a day streak-OK would
 * let exact_metrics_since claim coverage over a day whose range reads null
 * exact fields (the read layer's mixed-range rule) — so it is streak-OK ONLY
 * when the durable table provably has NO incomplete rows for it; otherwise the
 * day is not-ok and `since` lands after it. Rides the SAME unified predicate
 * as the post-write completeness check (sn_reroll_incomplete_rows) — two
 * diverging predicates would reopen the stale-sibling gap. Null (failed COUNT)
 * !== 0, so unknown stays NOT ok.
 *
 * @param object $db    wpdb (injected, so this helper stays unit-testable).
 * @param string $table Durable daily table name (prefix included).
 * @param string $label Day label (Y-m-d).
 * @return bool True only when provably zero incomplete rows exist for the day.
 */
function sn_reroll_empty_day_ok( $db, $table, $label ) {
	return 0 === sn_reroll_incomplete_rows( $db, $table, $label );
}

/**
 * The exact_metrics_since value: the earliest day of the unbroken fully-OK
 * streak ending at the LAST (most recent) result. Null when the last day
 * failed (a "since" date over a broken tail would be a lie) or the list is
 * empty. A missing/falsy 'ok' key counts as NOT ok — fail toward honesty.
 *
 * @param array $results Ascending-day list of array{day:string, ok:bool}.
 * @return string|null Y-m-d, or null.
 */
function sn_reroll_since_day( array $results ) {
	$since = null;
	for ( $i = count( $results ) - 1; $i >= 0; $i-- ) {
		$r = $results[ $i ];
		if ( ! is_array( $r ) || empty( $r['ok'] ) || ! isset( $r['day'] ) ) {
			break;
		}
		$since = (string) $r['day'];
	}
	return $since;
}

// Unit tests load only the pure helpers above.
if ( defined( 'SN_REROLL_TEST' ) ) {
	return;
}

// ── WP bootstrap (mirrors tests/contracts-smoke.php) ─────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( file_exists( $wp_load ) ) {
		require_once $wp_load;
	} else {
		die( "ABSPATH not set and wp-load.php not findable 4 dirs up. Run from public_html via `wp eval-file wp-content/plugins/signal-and-noise-tools/tools/reroll-analytics-90d.php`.\n" );
	}
}

foreach ( array(
	'sn_analytics_config',
	'sn_analytics_query',
	'sn_analytics_last_error',
	'sn_analytics_site_tz_name',
	'sn_analytics_rollup_sql',
	'sn_analytics_rollup_gated_sql',
	'sn_analytics_rollup_window_exprs',
	'sn_analytics_rollup_gated_query',
	'sn_analytics_rollup_merge_gated',
	'sn_analytics_rollup_upsert',
) as $sn_reroll_fn ) {
	if ( ! function_exists( $sn_reroll_fn ) ) {
		die( "FATAL: {$sn_reroll_fn}() not defined — is the signal-and-noise-tools plugin active?\n" );
	}
}

if ( ! sn_analytics_config() ) {
	die( "FATAL: AE not configured (sn_analytics_config() is null) — set SN_CF_ANALYTICS_TOKEN / SN_CF_ACCOUNT_ID.\n" );
}

// ── Run helpers (WP-coupled; not unit-tested, every branch echoes) ───────────

/**
 * One AE failure line, with the client's captured error verbatim.
 *
 * @param string $label Day label.
 * @param string $what  Which query failed.
 * @return void
 */
function sn_reroll_echo_fail( $label, $what ) {
	$err    = sn_analytics_last_error();
	$detail = is_array( $err ) ? sprintf( ' — HTTP %d: %s', $err['code'], $err['message'] ) : ' — (no error captured)';
	echo "{$label}  FAIL  {$what} failed{$detail}\n";
}

/**
 * Sum a (possibly numeric-string — AE returns UInt64 as JSON strings) column
 * over AE rows, for the echo lines only.
 *
 * @param array  $rows AE rows.
 * @param string $key  Column key.
 * @return int
 */
function sn_reroll_sum_col( $rows, $key ) {
	$sum = 0;
	foreach ( $rows as $r ) {
		if ( is_array( $r ) && isset( $r[ $key ] ) && is_numeric( $r[ $key ] ) ) {
			$sum += (int) round( (float) $r[ $key ] );
		}
	}
	return $sum;
}

// ── Execute ───────────────────────────────────────────────────────────────────

$sn_reroll_tz    = sn_analytics_site_tz_name();
$sn_reroll_today = new DateTimeImmutable( 'now', new DateTimeZone( '' !== $sn_reroll_tz ? $sn_reroll_tz : 'UTC' ) );

echo "Trailing-90d analytics backfill re-roll — Phase A Task 6\n";
echo 'Site: ' . home_url() . "\n";
echo 'Zone: ' . ( '' !== $sn_reroll_tz ? $sn_reroll_tz : '(UTC path — site zone is a manual offset)' ) . " — matching the rollup's day boundary; NO mid-run UTC fallback\n";
echo 'Run started: ' . $sn_reroll_today->format( 'Y-m-d H:i:s T' ) . "\n\n";

// Pre-flight drift check: if the builder shape drifted from the needles, every
// window would fail identically — die loudly once instead of 88 times.
list( , $sn_reroll_pf_lower ) = sn_analytics_rollup_window_exprs( 2, $sn_reroll_tz );
list( , $sn_reroll_pf_upper ) = sn_analytics_rollup_window_exprs( 1, $sn_reroll_tz );
if ( null === sn_reroll_day_sql( sn_analytics_rollup_sql( 2, $sn_reroll_tz ), $sn_reroll_pf_lower, $sn_reroll_pf_upper )
	|| null === sn_reroll_gated_day_sql( sn_analytics_rollup_gated_sql( 2, $sn_reroll_tz ), $sn_reroll_pf_lower, $sn_reroll_pf_upper ) ) {
	die( "FATAL: SQL transform returned null — sn_analytics_rollup_sql()/sn_analytics_rollup_gated_sql() shape drifted from this tool's needles. Update tools/reroll-analytics-90d.php (and tests/reroll-analytics-90d.php); nothing was written.\n" );
}

$sn_reroll_results = array(); // ascending-day: [ [ 'day' => Y-m-d, 'ok' => bool ], ... ]

foreach ( sn_reroll_bounded_offsets( 90 ) as $sn_reroll_k ) {
	$sn_reroll_label = sn_reroll_day_label( $sn_reroll_k, $sn_reroll_today );
	list( , $sn_reroll_lower ) = sn_analytics_rollup_window_exprs( $sn_reroll_k, $sn_reroll_tz );
	list( , $sn_reroll_upper ) = sn_analytics_rollup_window_exprs( $sn_reroll_k - 1, $sn_reroll_tz );

	$sn_reroll_main_sql  = sn_reroll_day_sql( sn_analytics_rollup_sql( $sn_reroll_k, $sn_reroll_tz ), $sn_reroll_lower, $sn_reroll_upper );
	$sn_reroll_gated_sql = sn_reroll_gated_day_sql( sn_analytics_rollup_gated_sql( $sn_reroll_k, $sn_reroll_tz ), $sn_reroll_lower, $sn_reroll_upper );
	if ( null === $sn_reroll_main_sql || null === $sn_reroll_gated_sql ) {
		echo "{$sn_reroll_label}  FAIL  SQL transform returned null (builder shape drift) — day skipped\n";
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}

	$sn_reroll_rows = sn_analytics_query( $sn_reroll_main_sql );
	if ( ! is_array( $sn_reroll_rows ) ) {
		sn_reroll_echo_fail( $sn_reroll_label, 'main query' );
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}
	if ( array() === $sn_reroll_rows ) {
		// 0 AE rows is ambiguous — quiet day vs aged-out-of-retention. Streak-OK
		// only when the durable table has no incomplete rows (the unified
		// scroll_sum-or-pageview_visits-NULL predicate) for the day; otherwise
		// `since` must land after it (mixed-range honesty).
		if ( sn_reroll_empty_day_ok( $GLOBALS['wpdb'], $GLOBALS['wpdb']->prefix . SN_ANALYTICS_DAILY_TABLE, $sn_reroll_label ) ) {
			echo "{$sn_reroll_label}  OK    0 AE rows, no durable incomplete rows — genuinely quiet day; nothing to write (empty is an ANSWER)\n";
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => true );
		} else {
			echo "{$sn_reroll_label}  WARN  0 AE rows but durable INCOMPLETE rows exist (scroll_sum or pageview_visits NULL) — aged out of AE retention (or the completeness check failed); day EXCLUDED from exact_metrics_since\n";
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		}
		continue;
	}

	// Day-key integrity: every row in a bounded window must bucket to this
	// day's label. A mismatch means the PHP label and AE's bucketing disagree
	// (window/label drift) — writing would clobber a NEIGHBOURING complete day
	// with a partial slice, so refuse the write and fail loudly.
	$sn_reroll_mismatch = 0;
	foreach ( $sn_reroll_rows as $sn_reroll_r ) {
		if ( ! is_array( $sn_reroll_r ) || trim( (string) ( $sn_reroll_r['day'] ?? '' ) ) !== $sn_reroll_label ) {
			++$sn_reroll_mismatch;
		}
	}
	if ( $sn_reroll_mismatch > 0 ) {
		echo "{$sn_reroll_label}  FAIL  {$sn_reroll_mismatch} of " . count( $sn_reroll_rows ) . " rows bucketed OUTSIDE this day — window/label drift; NOTHING written for this day (investigate before re-running)\n";
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}

	// The wrapper refuses a row-cap-TRUNCATED gated set (treated as failed —
	// pageview_visits stays NULL), so the merge's missing-key-is-0 rule only
	// ever runs over a provably complete result.
	$sn_reroll_gated    = sn_analytics_rollup_gated_query( $sn_reroll_gated_sql );
	$sn_reroll_gated_ok = is_array( $sn_reroll_gated );
	$sn_reroll_written  = sn_analytics_rollup_upsert( sn_analytics_rollup_merge_gated( $sn_reroll_rows, $sn_reroll_gated_ok ? $sn_reroll_gated : null ) );

	if ( ! $sn_reroll_gated_ok ) {
		sn_reroll_echo_fail( $sn_reroll_label, 'gated query' );
		echo "{$sn_reroll_label}  WARN  main rows written ({$sn_reroll_written} of " . count( $sn_reroll_rows ) . ") but pageview_visits stays NULL (never measured) — day EXCLUDED from exact_metrics_since\n";
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}
	if ( 0 === $sn_reroll_written ) {
		echo "{$sn_reroll_label}  FAIL  upsert wrote 0 of " . count( $sn_reroll_rows ) . " rows — DB write failure (or every row excluded/malformed)\n";
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}

	// Post-write row-level completeness: the upsert can only touch keys AE still
	// returns — stale cron-era siblings (keys AE consolidated away) survive it
	// with permanent-NULL exact columns, so the day's streak claim must be
	// earned by the durable TABLE, not by this run. A failed COUNT (null) is
	// not a clean answer — excluded, same as a positive count.
	$sn_reroll_stale = sn_reroll_incomplete_rows( $GLOBALS['wpdb'], $GLOBALS['wpdb']->prefix . SN_ANALYTICS_DAILY_TABLE, $sn_reroll_label );
	if ( 0 !== $sn_reroll_stale ) {
		echo "{$sn_reroll_label}  WARN  " . ( null === $sn_reroll_stale ? 'row-level completeness COUNT failed (unknown is not a clean answer)' : "{$sn_reroll_stale} stale cron-era rows remain (keys AE no longer returns; engagement/gated unmeasurable)" ) . " — day EXCLUDED from exact_metrics_since (AE rows=" . count( $sn_reroll_rows ) . " written={$sn_reroll_written})\n";
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		continue;
	}

	echo "{$sn_reroll_label}  OK    rows=" . count( $sn_reroll_rows ) . " written={$sn_reroll_written} views=" . sn_reroll_sum_col( $sn_reroll_rows, 'views' ) . ' pageview_visits=' . sn_reroll_sum_col( $sn_reroll_gated, 'pageview_visits' ) . "\n";
	$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => true );
}

// ── Yesterday + today: the unmodified production trailing-1-day window ───────
// (No upper bound needed — today is partial by definition and the cron keeps
// refreshing it; yesterday is re-upserted identically. Idempotent.)

$sn_reroll_tail_labels = array( sn_reroll_day_label( 1, $sn_reroll_today ), sn_reroll_day_label( 0, $sn_reroll_today ) );
$sn_reroll_rows        = sn_analytics_query( sn_analytics_rollup_sql( 1, $sn_reroll_tz ) );

if ( ! is_array( $sn_reroll_rows ) ) {
	foreach ( $sn_reroll_tail_labels as $sn_reroll_label ) {
		sn_reroll_echo_fail( $sn_reroll_label, 'main query (trailing-1 production window)' );
		$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
	}
} elseif ( array() === $sn_reroll_rows ) {
	foreach ( $sn_reroll_tail_labels as $sn_reroll_label ) {
		// Same 0-AE-row ambiguity rule as the bounded loop (yesterday/today can't
		// age out, but an incomplete row here would still make the claim a lie).
		if ( sn_reroll_empty_day_ok( $GLOBALS['wpdb'], $GLOBALS['wpdb']->prefix . SN_ANALYTICS_DAILY_TABLE, $sn_reroll_label ) ) {
			echo "{$sn_reroll_label}  OK    0 AE rows, no durable incomplete rows — quiet day; nothing to write (empty is an ANSWER)\n";
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => true );
		} else {
			echo "{$sn_reroll_label}  WARN  0 AE rows but durable INCOMPLETE rows exist (scroll_sum or pageview_visits NULL) — day EXCLUDED from exact_metrics_since\n";
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		}
	}
} else {
	$sn_reroll_mismatch = 0;
	foreach ( $sn_reroll_rows as $sn_reroll_r ) {
		if ( ! is_array( $sn_reroll_r ) || ! in_array( trim( (string) ( $sn_reroll_r['day'] ?? '' ) ), $sn_reroll_tail_labels, true ) ) {
			++$sn_reroll_mismatch;
		}
	}
	if ( $sn_reroll_mismatch > 0 ) {
		echo implode( '/', $sn_reroll_tail_labels ) . "  FAIL  {$sn_reroll_mismatch} of " . count( $sn_reroll_rows ) . " rows bucketed outside yesterday/today — window/label drift; NOTHING written (investigate before re-running)\n";
		foreach ( $sn_reroll_tail_labels as $sn_reroll_label ) {
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => false );
		}
	} else {
		$sn_reroll_gated    = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( 1, $sn_reroll_tz ) );
		$sn_reroll_gated_ok = is_array( $sn_reroll_gated );
		$sn_reroll_written  = sn_analytics_rollup_upsert( sn_analytics_rollup_merge_gated( $sn_reroll_rows, $sn_reroll_gated_ok ? $sn_reroll_gated : null ) );
		$sn_reroll_tail_ok  = $sn_reroll_gated_ok && $sn_reroll_written > 0;

		if ( ! $sn_reroll_gated_ok ) {
			sn_reroll_echo_fail( implode( '/', $sn_reroll_tail_labels ), 'gated query (trailing-1 production window)' );
		}
		foreach ( $sn_reroll_tail_labels as $sn_reroll_label ) {
			$sn_reroll_day_rows = array_values( array_filter( $sn_reroll_rows, function ( $r ) use ( $sn_reroll_label ) {
				return is_array( $r ) && trim( (string) ( $r['day'] ?? '' ) ) === $sn_reroll_label;
			} ) );
			$sn_reroll_gated_day_rows = ! $sn_reroll_gated_ok ? array() : array_values( array_filter( $sn_reroll_gated, function ( $r ) use ( $sn_reroll_label ) {
				return is_array( $r ) && trim( (string) ( $r['day'] ?? '' ) ) === $sn_reroll_label;
			} ) );
			$sn_reroll_day_ok = $sn_reroll_tail_ok;
			if ( $sn_reroll_tail_ok ) {
				// Same post-write row-level completeness gate as the bounded loop —
				// stale cron-era siblings survive the trailing-1 upsert identically.
				$sn_reroll_stale = sn_reroll_incomplete_rows( $GLOBALS['wpdb'], $GLOBALS['wpdb']->prefix . SN_ANALYTICS_DAILY_TABLE, $sn_reroll_label );
				if ( 0 !== $sn_reroll_stale ) {
					echo "{$sn_reroll_label}  WARN  " . ( null === $sn_reroll_stale ? 'row-level completeness COUNT failed (unknown is not a clean answer)' : "{$sn_reroll_stale} stale cron-era rows remain (keys AE no longer returns; engagement/gated unmeasurable)" ) . ' — day EXCLUDED from exact_metrics_since (AE rows=' . count( $sn_reroll_day_rows ) . ", trailing-1 production window)\n";
					$sn_reroll_day_ok = false;
				} else {
					echo "{$sn_reroll_label}  OK    rows=" . count( $sn_reroll_day_rows ) . ' views=' . sn_reroll_sum_col( $sn_reroll_day_rows, 'views' ) . ' pageview_visits=' . sn_reroll_sum_col( $sn_reroll_gated_day_rows, 'pageview_visits' ) . " (trailing-1 production window, written={$sn_reroll_written} combined)\n";
				}
			} else {
				echo "{$sn_reroll_label}  " . ( $sn_reroll_gated_ok ? 'FAIL  upsert wrote 0 rows — DB write failure' : 'WARN  written without pageview_visits (NULL, never measured) — day EXCLUDED from exact_metrics_since' ) . "\n";
			}
			$sn_reroll_results[] = array( 'day' => $sn_reroll_label, 'ok' => $sn_reroll_day_ok );
		}
	}
}

// ── Completion: set the discontinuity option + summarize ─────────────────────

$sn_reroll_ok_days = count( array_filter( $sn_reroll_results, function ( $r ) {
	return ! empty( $r['ok'] );
} ) );
$sn_reroll_all_ok  = count( $sn_reroll_results ) === $sn_reroll_ok_days;

echo "\n=== SUMMARY ===\n";
echo "{$sn_reroll_ok_days}/" . count( $sn_reroll_results ) . " days fully OK\n";

$sn_reroll_since = sn_reroll_since_day( $sn_reroll_results );
$sn_reroll_opt   = defined( 'SN_ANALYTICS_EXACT_SINCE_OPT' ) ? SN_ANALYTICS_EXACT_SINCE_OPT : 'sn_analytics_exact_metrics_since';
if ( null !== $sn_reroll_since ) {
	update_option( $sn_reroll_opt, $sn_reroll_since, false );
	echo "sn_analytics_exact_metrics_since = {$sn_reroll_since}";
	echo $sn_reroll_all_ok
		? " (earliest re-rolled day — full success)\n"
		: " (start of the unbroken OK streak ending today; earlier FAILed/WARNed days above stay pre-discontinuity)\n";
} else {
	echo "sn_analytics_exact_metrics_since NOT set — today's re-roll did not fully succeed. Fix the failure above and re-run; the tool is idempotent.\n";
}

exit( $sn_reroll_all_ok && null !== $sn_reroll_since ? 0 : 1 );
