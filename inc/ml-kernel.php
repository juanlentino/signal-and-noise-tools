<?php
/**
 * Signal & Noise — ML kernel (pure computational core).
 *
 * The primitives a site-wide pipeline registry (inc/ml-pipelines.php)
 * composes: tokenizer, corpus statistics, TF-IDF, cosine, BM25, and the
 * graph-signal / blended relatedness scorers. Everything here is arithmetic
 * over arrays the caller already fetched.
 *
 * ── PURE MODULE — the load-bearing constraint ────────────────────────────────
 *
 * ZERO WordPress calls, zero globals, zero I/O (model: inc/analytics-derive.php).
 * Tests require() this real file directly, so the asserted behaviour IS the
 * shipped behaviour — and tests/ml-kernel.php grep-pins this file's text free
 * of WP function names. Filterability (e.g. relatedness weights) is the
 * INTEGRATION layer's job: pure fns take overrides as plain arguments.
 * Declarations are function_exists-guarded so a double-require never fatals.
 *
 * @package SignalNoiseTools
 * @since 10.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The kernel's functions live in four parts files (house line cap, 2026-10-14),
// loaded in the order they always ran. tests/ml-kernel.php pins all five files' text.
require_once __DIR__ . '/ml-kernel-text-parts.php';
require_once __DIR__ . '/ml-kernel-graph-parts.php';
require_once __DIR__ . '/ml-kernel-cadence-parts.php';
require_once __DIR__ . '/ml-kernel-drift-parts.php';
