<?php
/**
 * Signal & Noise — analytics signal engine (predictive tier).
 * Pure statistics over the existing durable rollups. Produces typed Signal[]
 * (spec §5.1); no LLM, no new AE queries. @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SN_ANALYTICS_SIGNAL_BASELINE_DAYS = 30;
const SN_ANALYTICS_SIGNAL_FLOOR_DAYS    = 14;
const SN_ANALYTICS_SIGNAL_ANOMALY_Z     = 3.5;   // MAD-based robust z (~3σ for normal data); SN_ANALYTICS_ANOMALY_Z is taken by analytics-derived.php (2.0)
const SN_ANALYTICS_TRAJ_MIN_POINTS      = 14;
const SN_ANALYTICS_FORECAST_HORIZON      = 7;    // days ahead
const SN_ANALYTICS_FORECAST_MIN_POINTS   = 21;   // min history; below → suppressed (never fake precision)
const SN_ANALYTICS_FORECAST_HISTORY_DAYS = 90;   // trailing history ending $to the backtest and the band learn from (2026-10-12: was 30)
const SN_ANALYTICS_FORECAST_WINDOW       = 14;   // the forecast is the median of these trailing days (2026-10-12: replaced Holt)
const SN_ANALYTICS_FORECAST_BAND_Q       = 0.1;  // the band runs from this quantile of past misses to 1 - it: an 80% band
const SN_ANALYTICS_FORECAST_BAND_MIN     = 20;   // past misses needed before a band is measured out of sample
const SN_ANALYTICS_FORECAST_HOLT_MARGIN  = 0.1;  // Holt is used only when its backtest skill beats the median's by this much
const SN_ANALYTICS_FORECAST_HIGH_TOL     = 0.05; // 'high' confidence: the band held within this of its nominal 80%...
const SN_ANALYTICS_FORECAST_HIGH_CHECKS  = 50;   // ...over at least this many out-of-sample checks
/**
 * Persistence-baseline floor, RELATIVE to the series level, below which skill is
 * undefined rather than negative.
 *
 * v13.75.0 guarded `mae_naive > 0`, which catches a PERFECTLY rigid series and
 * misses a NEARLY rigid one. Measured on live crawler data 2026-09-02: the
 * `uptime` family sits at median 480 with MAD 0 — a handful of days differ just
 * enough that mae_naive is small-but-nonzero, so skill blew up to -6.89 and the
 * forecast was withheld for a series that is trivially forecastable.
 *
 * "Is the denominator exactly zero" is not the question. "Is the denominator
 * large enough for the ratio to mean anything" is. When persistence already
 * tracks a series to within 1% of its own level there is nothing left to beat,
 * and the comparison says nothing about the model.
 */
const SN_ANALYTICS_FORECAST_SKILL_MIN_REL = 0.01;

// The rest of the module lives in 3 parts files (house line cap, 2026-10-16),
// loaded in the order the code always ran.
require_once __DIR__ . '/analytics-signals-anomaly-parts.php';
require_once __DIR__ . '/analytics-signals-trend-parts.php';
require_once __DIR__ . '/analytics-signals-forecast-parts.php';
