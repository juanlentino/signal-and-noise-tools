<?php
/**
 * Signal & Noise Tools — Machine Readers: the durable crawler snapshot.
 *
 * R3 gate 3A. The machine-readability row's own prose sets the bar: "once that
 * read can be served from state the site already holds, so a reader's page never
 * waits on a sensor call." snt_mr_fetch() cannot meet it — it is a display
 * transient in front of an outbound wp_remote_get, so a cache MISS blocks the
 * render on a Cloudflare round-trip, and under Breeze/Redis the transient lives
 * in the object cache, where any flush evaporates it. (The health scan learned
 * exactly this in v6.47.2 and moved to a durable option; same move, same reason.)
 *
 * The split this file enforces:
 *
 *   snt_mr_snapshot_refresh()  — cron only. The ONLY fetcher, the ONLY writer.
 *   snt_mr_snapshot()          — read only. Never fetches, under ANY cache state.
 *
 * Three-valued by design, because the two failure modes are not the same answer:
 *   captured_at === null  → never measured. total() is NULL. Render "not measured
 *                           yet", never "0". A sensor that never answered is not
 *                           a site that nobody crawled.
 *   captured_at is int, total 0 → a MEASURED zero. That 0 is real; say it.
 *   captured_at is old    → stale. The record states its own age rather than
 *                           silently passing off a three-day-old count as now.
 *
 * A failed refresh never destroys a good capture: last_attempt_at and last_error
 * sit BESIDE the measurement, so "when was this true" and "when did we last try"
 * are separate questions with separate answers.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The durable option. autoload=no — it is read by the machine-readability
 * surfaces, not on every request, and mirrors SN_HEALTH_CACHE_KEY's shape.
 */
const SN_MR_SNAPSHOT_KEY = 'sn_mr_snapshot';

/** The recurring refresh event (add to snt_cron_sn_owned_hooks()). */
const SN_MR_SNAPSHOT_HOOK = 'snt_mr_snapshot_refresh';

/** The window the snapshot captures, in days. Clamped by snt_mr_fetch(). */
const SN_MR_SNAPSHOT_DAYS = 30;

/**
 * DISPLAY threshold only — the option never auto-expires (that is the whole
 * point of not being a transient). At an hourly refresh this is six consecutive
 * missed firings, which is a real outage rather than one slow cron tick.
 */
const SN_MR_SNAPSHOT_STALE_AFTER = 6 * HOUR_IN_SECONDS;

/**
 * Schedule the hourly refresh. Idempotent via wp_next_scheduled(), on init
 * rather than activation so an already-installed site self-heals a lost event.
 */
function snt_mr_snapshot_schedule() {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}
	if ( ! wp_next_scheduled( SN_MR_SNAPSHOT_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SN_MR_SNAPSHOT_HOOK );
	}
}
add_action( 'init', 'snt_mr_snapshot_schedule' );
add_action( SN_MR_SNAPSHOT_HOOK, 'snt_mr_snapshot_refresh' );

/**
 * Fold sensor rows into the stored aggregate.
 *
 * Families and surfaces with no rows are ABSENT from the maps rather than
 * present as 0 — the same absent-vs-zero rule the record itself follows one
 * level up. A caller wanting "openai crawled us zero times" must say so from a
 * known family list, not read it out of a map that never claimed completeness.
 *
 * @param array $rows snt_mr_fetch() normalized rows.
 * @return array{total:int,by_family:array<string,int>,by_surface:array<string,int>}
 */
function snt_mr_snapshot_aggregate( $rows ) {
	$total      = 0;
	$by_family  = array();
	$by_surface = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$hits    = isset( $row['hits'] ) ? max( 0, (int) $row['hits'] ) : 0;
		$family  = isset( $row['family'] ) ? (string) $row['family'] : '';
		$surface = isset( $row['surface'] ) ? (string) $row['surface'] : '';
		$total  += $hits;
		if ( '' !== $family ) {
			$by_family[ $family ] = ( $by_family[ $family ] ?? 0 ) + $hits;
		}
		if ( '' !== $surface ) {
			$by_surface[ $surface ] = ( $by_surface[ $surface ] ?? 0 ) + $hits;
		}
	}
	return array(
		'total'      => $total,
		'by_family'  => $by_family,
		'by_surface' => $by_surface,
	);
}

/**
 * Capture the sensor read into the durable option. Cron's job, nobody else's.
 *
 * On failure the previous measurement is preserved verbatim and only the
 * attempt metadata moves — a broken sensor must not be able to blank a count
 * that was true an hour ago.
 *
 * @return bool True when a measurement was captured this run.
 */
function snt_mr_snapshot_refresh() {
	$result   = snt_mr_fetch( SN_MR_SNAPSHOT_DAYS );
	$existing = snt_mr_snapshot();
	$record   = is_array( $existing ) ? $existing : array( 'captured_at' => null );

	$record['last_attempt_at'] = time();

	if ( true !== ( $result['ok'] ?? false ) ) {
		// Carried verbatim from the fetch layer: 'not_configured' and 'http_503'
		// are different operator problems and the surface should be able to say
		// which. Collapsing both to "unavailable" hides the fixable one.
		$record['last_error'] = (string) ( $result['error'] ?? 'unknown' );
		update_option( SN_MR_SNAPSHOT_KEY, $record, false );
		return false;
	}

	$agg    = snt_mr_snapshot_aggregate( $result['rows'] ?? array() );
	$record = array_merge( $record, $agg, array(
		'captured_at' => time(),
		'days'        => SN_MR_SNAPSHOT_DAYS,
		'last_error'  => null,
	) );
	update_option( SN_MR_SNAPSHOT_KEY, $record, false );
	return true;
}

/**
 * Read the stored snapshot. Makes NO outbound call under any state — not on a
 * miss, not when stale, not when the last refresh failed. That is the gate this
 * whole module exists to hold, and the test suite asserts it with a tripwire
 * rather than a canned failure.
 *
 * @return array|null The record, or null when nothing has ever been stored.
 */
function snt_mr_snapshot() {
	$stored = get_option( SN_MR_SNAPSHOT_KEY, null );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Whether this record carries a real measurement, as opposed to only the
 * record of an attempt. Gate every count behind this.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return bool
 */
function snt_mr_snapshot_has_measurement( $snap ) {
	return is_array( $snap ) && is_int( $snap['captured_at'] ?? null );
}

/**
 * Total machine reads in the captured window.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return int|null Null when never measured — NEVER 0. A measured zero is 0.
 */
function snt_mr_snapshot_total( $snap ) {
	if ( ! snt_mr_snapshot_has_measurement( $snap ) ) {
		return null;
	}
	return max( 0, (int) ( $snap['total'] ?? 0 ) );
}

/**
 * Age of the measurement in seconds.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return int|null Null when never measured (not 0 — "just now" is a claim).
 */
function snt_mr_snapshot_age( $snap ) {
	if ( ! snt_mr_snapshot_has_measurement( $snap ) ) {
		return null;
	}
	return max( 0, time() - (int) $snap['captured_at'] );
}

/**
 * Whether the measurement is old enough to warn about.
 *
 * @param array|null $snap A snt_mr_snapshot() record.
 * @return bool|null Null when there is no measurement — absent is not stale,
 *                   it is a different answer, and a caller that treats "not
 *                   stale" as reassurance must not be handed one here.
 */
function snt_mr_snapshot_is_stale( $snap ) {
	$age = snt_mr_snapshot_age( $snap );
	if ( null === $age ) {
		return null;
	}
	return $age > SN_MR_SNAPSHOT_STALE_AFTER;
}
